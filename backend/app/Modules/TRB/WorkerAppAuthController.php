<?php

namespace App\Modules\TRB;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Providers;
use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\DbContext;
use App\Http\Controllers\Controller;
use App\Modules\NOT\AlertMail;
use App\Modules\NOT\WhatsAppCloud;
use App\Modules\USR\Services\SessionIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Acceso a la app del trabajador (docs/07 §5.2; reglas en WorkerAppAccess).
 *
 * Con sesión (responsable SST o RR. HH., permiso trb.workers.update):
 *  GET  /trb/workers/{id}/app-access       estado: activada, equipos ligados, activación pendiente
 *  POST /trb/workers/{id}/app-access       genera el código y el enlace; los envía por correo (si tiene) y WhatsApp (si está
 *                                          en vivo y el trabajador lo aceptó); siempre se devuelven para imprimirlos
 * Sin sesión:
 *  GET  /auth/worker/activation?t=         a quién corresponde el enlace (primer nombre y empresa)
 *  POST /auth/worker/activate              {t | dni + code, pin, pin_confirmation, device_uid} → sesión + credencial del equipo
 *  POST /auth/worker/pin                   {company_id, device_id, device_secret, dni, pin} → sesión
 */
class WorkerAppAuthController extends Controller
{
    public function __construct(private readonly SessionIssuer $sessions) {}

    // ── Lado de la empresa ──

    public function status(string $id): JsonResponse
    {
        $w = $this->worker($id);
        $devices = (int) DB::selectOne("select count(*) as n from devices where worker_id = ? and pin_bound and status = 'active' and deleted_at is null", [$w->id])->n;
        $user = DB::selectOne("select m.status, m.last_access_at from memberships m where m.worker_id = ? and m.deleted_at is null order by (m.status = 'active') desc limit 1", [$w->id]);

        return response()->json(['data' => [
            'activated_at' => $w->activated_at, 'pending_activation' => $w->activation_code_hash !== null, 'devices' => $devices,
            'membership_status' => $user?->status, 'last_access_at' => $user?->last_access_at,
            'has_email' => (bool) WorkerAppAccess::decrypt($w->personal_email), 'whatsapp' => $this->whatsappPhone($w) !== null,
            'can_issue' => in_array('trb.workers.update', Permissions::effective(), true),
        ]]);
    }

    public function issue(string $id): JsonResponse
    {
        $this->can('trb.workers.update');
        $w = $this->worker($id);
        if (! in_array($w->status, ['active', 'on_leave'], true)) {
            throw new ApiProblem(409, 'conflict', 'Trabajador inactivo', 'Solo se activa la app a trabajadores activos o de licencia.');
        }
        $ctx = app(CompanyContext::class);
        $a = WorkerAppAccess::issue($ctx->companyId, $w, (string) auth()->id());
        $first = Str::before(trim((string) $w->first_name), ' ') ?: $w->first_name;
        $hours = (int) config('escudo.worker_app.activation_hours', 72);
        $sent = ['email' => false, 'whatsapp' => false];

        // Correo personal (si lo tiene): el código y el enlace. No lleva datos sensibles.
        $email = WorkerAppAccess::decrypt($w->personal_email);
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                Mail::to($email)->send(new AlertMail($first, $ctx->companyName, 'Activa tu app de EscudoSST',
                    "Tu código de activación es {$a['code']}. Abre el enlace, escribe tu DNI y crea tu PIN. Vence en {$hours} horas. No lo compartas.", $a['link'], 'high'));
                $sent['email'] = true;
            } catch (Throwable) {
                $sent['email'] = false;
            }
        }
        // WhatsApp: solo con la API en vivo y si el trabajador aceptó el canal (plantilla de aviso general).
        $wa = new WhatsAppCloud(Providers::get('whatsapp'));
        $phone = $this->whatsappPhone($w);
        if ($phone && $wa->ready() && ($template = $wa->template('alert'))) {
            try {
                $sent['whatsapp'] = (bool) ($wa->sendTemplate($phone, $template, ['Activa tu app de EscudoSST', "Tu código es {$a['code']}. Vence en {$hours} h.", $a['link']])['ok'] ?? false);
            } catch (Throwable) {
                $sent['whatsapp'] = false;
            }
        }
        WorkerAppAccess::audit($ctx->companyId, (string) auth()->id(), $ctx->roleCode, 'worker_app_activation_issued', $w->id, ['sent' => $sent, 'expires_at' => $a['expires_at']]);

        return response()->json(['data' => [
            'worker' => ['id' => $w->id, 'full_name' => $w->full_name, 'document_masked' => $w->document_last4 ? '****'.$w->document_last4 : null],
            'company' => $ctx->companyName, 'code' => $a['code'], 'link' => $a['link'], 'expires_at' => $a['expires_at'], 'sent' => $sent,
        ]], 201);
    }

    // ── Sin sesión ──

    public function preview(Request $request): JsonResponse
    {
        $p = WorkerAppAccess::find((string) $request->query('t', ''), null, null) ?? throw WorkerAppAccess::invalidActivation();

        return DB::transaction(function () use ($p) {
            DbContext::setCompany($p['company']);
            $w = DB::selectOne('select first_name, activation_code_hash from workers where id = ? and deleted_at is null', [$p['worker']]);
            if (! $w || $w->activation_code_hash !== hash('sha256', $p['nonce'])) {
                throw WorkerAppAccess::invalidActivation();
            }
            $c = DB::selectOne('select coalesce(trade_name, legal_name) as name from companies where id = ?', [$p['company']]);

            return response()->json(['data' => ['first_name' => Str::before(trim((string) $w->first_name), ' '), 'company' => $c?->name]]);
        });
    }

    public function activate(Request $request): JsonResponse
    {
        $uid = trim((string) $request->input('device_uid'));
        if ($uid === '' || mb_strlen($uid) > 100) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Falta el identificador del equipo.', ['errors' => ['device_uid' => ['Falta el identificador del equipo.']]]);
        }
        $token = (string) $request->input('t', '');
        $dni = (string) $request->input('dni', '');
        $p = WorkerAppAccess::find($token, $dni, (string) $request->input('code', '')) ?? throw WorkerAppAccess::invalidActivation();
        $pin = WorkerAppAccess::validatePin($request->input('pin'), $request->input('pin_confirmation'));

        $out = DB::transaction(function () use ($p, $pin, $uid, $request, $token, $dni) {
            DbContext::setCompany($p['company']);
            $w = DB::selectOne("select * from workers where id = ? and deleted_at is null and status in ('active', 'on_leave')", [$p['worker']]);
            if (! $w || $w->activation_code_hash !== hash('sha256', $p['nonce'])) {
                throw WorkerAppAccess::invalidActivation();
            }
            // Con el enlace, si escribe su DNI debe ser el suyo.
            $docHex = bin2hex(WorkerAppAccess::bytes($w->document_number_hash));
            if ($token !== '' && trim($dni) !== '' && ! in_array($docHex, WorkerAppAccess::documentHashes($dni), true)) {
                throw WorkerAppAccess::invalidActivation();
            }
            $userId = WorkerAppAccess::ensureUser($p['company'], $w, $p['by']);
            WorkerAppAccess::assertPinAllowed($userId);
            DbContext::setCompany($p['company']);
            DB::update('update workers set pin_hash = ?, activation_code_hash = null, activated_at = now() where id = ?', [Hash::make($pin), $w->id]);
            $device = WorkerAppAccess::bindDevice($p['company'], $userId, $w->id, $uid, mb_substr((string) $request->input('model', $request->userAgent()), 0, 120) ?: null);
            WorkerAppAccess::audit($p['company'], $userId, 'worker', 'worker_app_activated', $w->id, ['device_id' => $device['id'], 'by_link' => $token !== '']);
            $c = DB::selectOne('select coalesce(trade_name, legal_name) as name from companies where id = ?', [$p['company']]);

            return ['user' => $userId, 'device' => $device, 'company' => ['id' => $p['company'], 'name' => $c?->name],
                'first_name' => Str::before(trim((string) $w->first_name), ' ')];
        });
        WorkerAppAccess::consume($p);

        return response()->json($this->sessions->issue($out['user'], $request->userAgent()) + [
            'company_id' => $out['company']['id'], 'company_name' => $out['company']['name'], 'first_name' => $out['first_name'], 'device' => $out['device'],
        ], 201);
    }

    public function pin(Request $request): JsonResponse
    {
        $company = (string) $request->input('company_id');
        $deviceId = (string) $request->input('device_id');
        $secret = (string) $request->input('device_secret');
        $dni = trim((string) $request->input('dni'));
        $pin = (string) $request->input('pin');
        if (! Str::isUuid($company) || ! Str::isUuid($deviceId) || strlen($secret) !== 48) {
            throw $this->unknownDevice();
        }
        if ($dni === '' || $pin === '') {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Escribe tu DNI y tu PIN.', ['errors' => ['pin' => ['Escribe tu DNI y tu PIN.']]]);
        }
        // El resultado se decide dentro de la transacción y el error se lanza fuera, para que el intento fallido cuente.
        [$result, $userId] = DB::transaction(function () use ($company, $deviceId, $secret, $dni, $pin) {
            DbContext::setCompany($company);
            $d = DB::selectOne('select id, user_id, worker_id, status, credential_hash from devices where id = ? and deleted_at is null', [$deviceId]);
            if (! $d || $d->status !== 'active' || ! $d->credential_hash || ! $d->worker_id
                || ! hash_equals(bin2hex(WorkerAppAccess::bytes($d->credential_hash)), hash('sha256', $secret))) {
                return ['device', null];
            }
            $w = DB::selectOne('select id, document_number_hash, pin_hash, status from workers where id = ? and deleted_at is null', [$d->worker_id]);
            if (! $w || ! in_array($w->status, ['active', 'on_leave'], true)) {
                return ['device', null];
            }
            WorkerAppAccess::assertNotLocked($w->id);
            $dniOk = in_array(bin2hex(WorkerAppAccess::bytes($w->document_number_hash)), WorkerAppAccess::documentHashes($dni), true);
            if (! WorkerAppAccess::checkPin($w->id, $dniOk ? $w->pin_hash : null, $dniOk ? $pin : '')) {
                return ['bad', $w->id];
            }
            $m = DB::selectOne("select user_id from memberships where worker_id = ? and user_id = ? and status = 'active' and deleted_at is null", [$w->id, $d->user_id]);
            if (! $m) {
                return ['device', null];
            }
            DB::update('update devices set last_login_at = now() where id = ?', [$d->id]);

            return ['ok', $m->user_id];
        });
        if ($result === 'device') {
            throw $this->unknownDevice();
        }
        if ($result === 'bad') {
            WorkerAppAccess::assertNotLocked($userId);
            throw new ApiProblem(401, 'invalid_credentials', 'No pudimos ingresar', 'El DNI o el PIN no son correctos.');
        }
        $u = DB::connection('platform')->selectOne('select status from users where id = ? and deleted_at is null', [$userId]);
        if (! $u || $u->status !== 'active') {
            throw $this->unknownDevice();
        }
        WorkerAppAccess::assertPinAllowed($userId);

        return response()->json($this->sessions->issue($userId, $request->userAgent()) + ['company_id' => $company]);
    }

    // ── Apoyo ──

    private function worker(string $id): object
    {
        $this->can('trb.workers.read');
        $w = Str::isUuid($id) ? DB::selectOne('select * from workers where id = ? and deleted_at is null', [$id]) : null;
        if (! $w) {
            throw ApiProblem::notFound('El trabajador no existe en esta empresa.');
        }

        return $w;
    }

    private function whatsappPhone(object $w): ?string
    {
        return $w->whatsapp_opt_in ? WhatsAppCloud::normalize(WorkerAppAccess::decrypt($w->phone_e164)) : null;
    }

    private function unknownDevice(): ApiProblem
    {
        return new ApiProblem(401, 'device_unknown', 'Equipo no activado',
            'Este equipo no está activado para tu app o se dio de baja. Pide un código nuevo al área de SST o de Recursos Humanos.');
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede activar la app de los trabajadores.');
        }
    }
}
