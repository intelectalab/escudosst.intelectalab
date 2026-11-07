<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Core\Support\Ruc;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Programa de partners y referidos (SCR-WEB-35 pública /partners, SCR-WEB-46 panel; RN-WEB-46 a 48).
 *  Público: POST /public/partners                     solicitud (crea el interesado «partner» y la solicitud pendiente)
 *           GET  /public/partners/ref/{code}          ¿el código existe y está activo? (para el enlace ?ref=)
 *  Panel:   GET  /internal/site/partners              solicitudes, códigos y referidos
 *           POST /internal/site/partners/{id}/review  aprobar (genera el código) o rechazar con motivo (solo Dueño)
 *           PATCH /internal/site/partners/codes/{id}  activar/desactivar y regla de recompensa (solo Dueño)
 *           PATCH /internal/site/partners/referrals/{id}  estado del referido (solo Dueño)
 *
 * La recompensa no tiene monto por defecto: mientras la regla esté vacía se muestra «por definir». El sistema no paga
 * nada: solo registra el derecho cuando el referido pasa a cliente.
 */
class PartnersController extends Controller
{
    public const TYPES = ['consultancy' => 'Consultora SST', 'independent' => 'Profesional independiente', 'institute' => 'Instituto o centro de formación'];

    public const REWARDS = ['discount' => 'Descuento para el referido', 'commission' => 'Comisión para el partner', 'free_months' => 'Meses sin costo', 'other' => 'Otra'];

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'email' => 'Escribe un correo válido.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'accepted' => 'Para enviar, acepta la política de privacidad.',
        'url' => 'Escribe la dirección completa, con https://',
        'regex' => 'Formato no válido.',
    ];

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    private function site(): string
    {
        return rtrim((string) config('escudo.frontend_url'), '/');
    }

    // ---------------------------------------------------------------- Público

    public function apply(Request $request): JsonResponse
    {
        if (filled($request->input('website_hp'))) {   // trampa para robots
            return response()->json(['data' => ['ok' => true]], 201);
        }
        $d = $request->validate([
            'partner_type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:20'],
            'company_name' => ['required', 'string', 'min:2', 'max:160'],
            'ruc' => ['nullable', 'string'],
            'website' => ['nullable', 'url:http,https', 'max:300'],
            'message' => ['nullable', 'string', 'max:2000'],
            'privacy_consent' => ['accepted'],
            'marketing_consent' => ['boolean'],
            'key' => ['nullable', 'uuid'],
        ], self::MESSAGES);
        $ruc = preg_replace('/\D/', '', (string) ($d['ruc'] ?? ''));
        if ($ruc !== '' && ! Ruc::isValid($ruc)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El RUC no es válido.', ['errors' => ['ruc' => ['El RUC no es válido: revisa los 11 dígitos.']]]);
        }
        $data = [
            'full_name' => trim($d['full_name']), 'email' => $d['email'], 'phone' => $d['phone'] ?? null,
            'company_name' => trim($d['company_name']), 'ruc' => $ruc ?: null, 'partner_type' => $d['partner_type'],
            'website' => $d['website'] ?? null, 'message' => isset($d['message']) ? strip_tags($d['message']) : null,
            'privacy_consent' => true, 'marketing_consent' => (bool) ($d['marketing_consent'] ?? false),
        ];
        $r = json_decode($this->db()->selectOne(
            "select public_apply_partner(?::jsonb, decode(?, 'hex'), ?) as r",
            [json_encode($data, JSON_UNESCAPED_UNICODE), hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), $d['key'] ?? Str::uuid7()->toString()]
        )->r, true);

        if (! ($r['replay'] ?? false)) {
            $this->mail($d['email'], new SiteRequestMail(
                explode(' ', $data['full_name'])[0], 'Recibimos tu solicitud de partner',
                'Gracias por tu interés en el programa de partners de EscudoSST. Revisaremos tus datos y te escribiremos con la respuesta.',
                [['Tipo', self::TYPES[$d['partner_type']]], ['Empresa', $data['company_name']]],
                'Si te aprobamos, recibirás tu código de referido y tu enlace personal.'
            ));
        }

        return response()->json(['data' => ['ok' => true]], 201);
    }

    /** Para el enlace ?ref=: solo dice si el código sirve (no revela al partner). */
    public function checkCode(string $code): JsonResponse
    {
        $ok = (bool) $this->db()->selectOne('select 1 from referral_codes where code = upper(?) and is_active and deleted_at is null', [$code]);

        return response()->json(['data' => ['valid' => $ok]]);
    }

    /**
     * Atribución al registrarse (RN-WEB-48): el primer código válido; no si el RUC de la cuenta es el del partner.
     * La llama el registro autoservicio; nunca rompe el registro.
     */
    public static function attribute(?string $code, string $accountId, string $accountRuc, ?string $email): void
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || ! preg_match('/^[A-Z0-9-]{4,32}$/', $code)) {
            return;
        }
        try {
            $db = DB::connection('platform');
            $partner = $db->selectOne(
                'select a.ruc from referral_codes c join partner_applications a on a.id = c.partner_application_id
                  where c.code = ? and c.is_active and c.deleted_at is null', [$code]
            );
            if (! $partner || ($partner->ruc && trim($partner->ruc) === $accountRuc)) {
                return;
            }
            $lead = $email ? $db->selectOne('select id from marketing_leads where email = lower(trim(?)) and deleted_at is null', [$email]) : null;
            $db->selectOne('select web_register_referral(?, ?, ?) as r', [$code, $lead?->id, $accountId]);
            $db->update("update referrals set status = 'trial', updated_at = now() where account_id = ? and status = 'registered'", [$accountId]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar el referido', ['error' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        $apps = $this->db()->select(
            "select a.id, a.partner_type, a.company_name, a.ruc, a.website, a.message, a.status, a.created_at, a.reviewed_at,
                    a.rejection_reason, a.notes, l.full_name, l.email, l.phone, l.marketing_consent, u.full_name as reviewed_by_name,
                    c.id as code_id, c.code, c.is_active as code_active, c.reward_rule_json,
                    (select count(*) from referrals r where r.referral_code_id = c.id and r.deleted_at is null) as referrals,
                    (select count(*) from referrals r where r.referral_code_id = c.id and r.status = 'customer' and r.deleted_at is null) as customers
               from partner_applications a
               join marketing_leads l on l.id = a.lead_id
               left join users u on u.id = a.reviewed_by
               left join referral_codes c on c.partner_application_id = a.id and c.deleted_at is null
              where a.deleted_at is null
              order by (a.status = 'pending') desc, a.created_at desc"
        );
        foreach ($apps as $a) {
            $a->reward_rule_json = $a->reward_rule_json ? json_decode($a->reward_rule_json, true) : null;
        }
        $refs = $this->db()->select(
            "select r.id, r.referral_code_id, r.status, r.created_at, r.converted_at, r.cancelled_reason,
                    coalesce(acc.legal_name, l.company_name, l.full_name) as name
               from referrals r
               left join accounts acc on acc.id = r.account_id
               left join marketing_leads l on l.id = r.lead_id
              where r.deleted_at is null order by r.created_at desc"
        );

        return response()->json(['data' => ['applications' => $apps, 'referrals' => $refs], 'types' => self::TYPES, 'rewards' => self::REWARDS]);
    }

    public function review(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate([
            'approve' => ['required', 'boolean'],
            'code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]+(-[A-Za-z0-9]+)*$/', 'min:4', 'max:32'],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:approve,false'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], self::MESSAGES + ['reason.required_if' => 'Escribe el motivo: se le envía a la persona.', 'code.regex' => 'Solo letras, números y guiones.']);
        $app = $this->db()->selectOne(
            'select a.*, l.full_name, l.email from partner_applications a join marketing_leads l on l.id = a.lead_id where a.id = ? and a.deleted_at is null', [$id]
        ) ?? throw ApiProblem::notFound('La solicitud no existe.');
        if ($app->status !== 'pending') {
            $this->fail('approve', 'Esta solicitud ya fue revisada.');
        }
        if ($d['approve'] && filled($d['code'] ?? null)
            && $this->db()->selectOne('select 1 from referral_codes where code = upper(?)', [$d['code']])) {
            $this->fail('code', 'Ese código ya existe. Elige otro o déjalo vacío para generarlo solo.');
        }
        $db = $this->db();
        $r = $db->transaction(function () use ($db, $id, $d, $request) {
            $db->select("select set_config('app.user_id', ?, true)", [$request->user()->id]);
            $r = json_decode($db->selectOne('select web_review_partner(?, ?, ?, ?) as r', [$id, (bool) $d['approve'], $d['code'] ?? null, $d['reason'] ?? null])->r, true);
            if (filled($d['notes'] ?? null)) {
                $db->update('update partner_applications set notes = ? where id = ?', [strip_tags($d['notes']), $id]);
            }

            return $r;
        });
        $accounts->audit($request->user()->id, $d['approve'] ? 'partner_approved' : 'partner_rejected', 'partner_applications', $id, ['code' => $r['referral_code'] ?? null]);

        $first = explode(' ', trim((string) $app->full_name))[0] ?: 'Hola';
        if ($d['approve']) {
            $link = $this->site().'/prueba-gratis?ref='.$r['referral_code'];
            $this->mail($app->email, new SiteRequestMail(
                $first, '¡Bienvenido al programa de partners!',
                'Aprobamos tu solicitud. Comparte tu enlace: cada empresa que se registre con él queda como tu referido.',
                [['Tu código', $r['referral_code']], ['Tu enlace', $link], ['Recompensa', 'Por definir: te la confirmaremos por este medio']],
                'También pueden escribir tu código al registrarse.', 'Ver la página del programa', $this->site().'/partners'
            ));
        } else {
            $this->mail($app->email, new SiteRequestMail(
                $first, 'Sobre tu solicitud de partner',
                'Gracias por tu interés. Por ahora no podemos aprobar tu solicitud al programa de partners.',
                [['Motivo', $d['reason']]], 'Puedes volver a postular más adelante.'
            ));
        }

        return response()->json(['data' => $r]);
    }

    public function updateCode(Request $request, string $id, AccountsService $accounts): Response
    {
        $d = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reward_type' => ['nullable', 'in:'.implode(',', array_keys(self::REWARDS))],
            'reward_value' => ['nullable', 'string', 'max:60', 'required_with:reward_type'],
            'reward_condition' => ['nullable', 'string', 'max:300'],
        ], self::MESSAGES + ['reward_value.required_with' => 'Escribe el valor (ej. 10 % o S/ 100).']);
        $this->db()->selectOne('select 1 from referral_codes where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('El código no existe.');
        $rule = filled($d['reward_type'] ?? null)
            ? ['type' => $d['reward_type'], 'value' => trim($d['reward_value']), 'condition' => filled($d['reward_condition'] ?? null) ? trim($d['reward_condition']) : null]
            : [];
        $this->db()->update(
            'update referral_codes set is_active = ?, deactivated_at = case when ? then null else coalesce(deactivated_at, now()) end, reward_rule_json = ?::jsonb, updated_by = ?, updated_at = now() where id = ?',
            [(bool) $d['is_active'], (bool) $d['is_active'], json_encode((object) $rule, JSON_UNESCAPED_UNICODE), $request->user()->id, $id]
        );
        $accounts->audit($request->user()->id, 'referral_code_updated', 'referral_codes', $id, ['is_active' => (bool) $d['is_active'], 'reward' => $rule]);

        return response()->noContent();
    }

    public function updateReferral(Request $request, string $id, AccountsService $accounts): Response
    {
        $d = $request->validate([
            'status' => ['required', 'in:registered,trial,customer,cancelled'],
            'reason' => ['nullable', 'string', 'max:300'],
        ], self::MESSAGES);
        $ref = $this->db()->selectOne('select * from referrals where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('El referido no existe.');
        if ($d['status'] === 'customer' && ! $ref->account_id) {
            $this->fail('status', 'Solo un referido con cuenta creada puede pasar a cliente.');
        }
        $this->db()->update(
            'update referrals set status = ?, converted_at = case when ? then coalesce(converted_at, now()) else converted_at end, cancelled_reason = ?, updated_by = ?, updated_at = now() where id = ?',
            [$d['status'], $d['status'] === 'customer', $d['status'] === 'cancelled' ? ($d['reason'] ?? null) : null, $request->user()->id, $id]
        );
        $accounts->audit($request->user()->id, 'referral_status', 'referrals', $id, ['from' => $ref->status, 'to' => $d['status']]);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Apoyo

    private function mail(string $to, SiteRequestMail $m): void
    {
        try {
            Mail::to($to)->send($m);
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo del programa de partners', ['error' => $e->getMessage()]);
        }
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
