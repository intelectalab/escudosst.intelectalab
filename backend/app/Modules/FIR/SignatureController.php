<?php

namespace App\Modules\FIR;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\Registry;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Firma electrónica de registros (M-FIR, Ley 27269 y su reglamento: firma electrónica simple con evidencia).
 *  GET  /fir/subject/{table}/{id}          firmas pedidas y hechas de un registro
 *  POST /fir/requests                      pedir una firma {subject_table, subject_id, signer_user_id | signer_worker_id, role_in_document, expires_days?}
 *  POST /fir/requests/{id}/sign            firmar: el usuario confirma con su contraseña; a un trabajador sin usuario lo firma «asistido en persona»
 *                                          quien tiene permiso, con su presencia declarada {password} | {assisted: true}
 *  POST /fir/requests/{id}/decline         rechazar {reason}
 *
 * Al pedir la firma se guarda la huella (SHA-256) del registro; al firmar se vuelve a calcular: si el registro cambió,
 * la firma no procede. Cada firma deja su evidencia (quién, cómo, cuándo, IP, navegador, huellas) en «signatures».
 */
class SignatureController extends Controller
{
    private const ROLES = ['attendee', 'responsible', 'approver', 'reviewer', 'worker', 'witness', 'committee_member', 'doctor'];

    public function subject(string $table, string $id): JsonResponse
    {
        $this->subjectRow($table, $id);

        return response()->json(['data' => DB::select(
            "select r.id, r.role_in_document, r.status, r.signer_user_id, r.signer_worker_id, r.expires_at, r.signed_at, r.declined_reason,
                    coalesce(u.full_name, w.full_name) as signer_name, s.method, s.signer_name as signed_by_name, s.id as signature_id,
                    exists (select 1 from signature_strokes k where k.signature_id = s.id) as has_image
               from signature_requests r left join users u on u.id = r.signer_user_id left join workers w on w.id = r.signer_worker_id
               left join signatures s on s.signature_request_id = r.id
              where r.subject_type = ? and r.subject_id = ? and r.deleted_at is null order by r.sequence_order, r.created_at",
            [$table, $id]
        )]);
    }

    public function request(Request $request, AccountsService $accounts): JsonResponse
    {
        $this->can('fir.signature_requests.create');
        $d = $request->validate([
            'subject_table' => ['required', 'string', 'max:60'],
            'subject_id' => ['required', 'uuid'],
            'signer_user_id' => ['nullable', 'uuid', 'required_without:signer_worker_id'],
            'signer_worker_id' => ['nullable', 'uuid', 'required_without:signer_user_id'],
            'role_in_document' => ['required', 'in:'.implode(',', self::ROLES)],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:60'],
        ], ['*.required_without' => 'Elige a quién se le pide la firma.', 'role_in_document.in' => 'Elige en calidad de qué firma.']);
        [$res, $row] = $this->subjectRow($d['subject_table'], $d['subject_id']);
        $company = app(CompanyContext::class)->companyId;
        if (! empty($d['signer_user_id']) && ! DB::selectOne("select 1 as ok from memberships where user_id = ? and company_id = ? and status = 'active' and deleted_at is null", [$d['signer_user_id'], $company])) {
            $this->fail('signer_user_id', 'Esa persona no es usuaria de la empresa.');
        }
        if (! empty($d['signer_worker_id']) && ! DB::selectOne("select 1 as ok from workers where id = ? and deleted_at is null and status <> 'terminated'", [$d['signer_worker_id']])) {
            $this->fail('signer_worker_id', 'El trabajador no existe o ya no labora en la empresa.');
        }
        $order = (int) DB::selectOne('select coalesce(max(sequence_order), 0) + 1 as n from signature_requests where subject_type = ? and subject_id = ? and deleted_at is null',
            [$d['subject_table'], $d['subject_id']])->n;
        $id = DB::selectOne(
            "insert into signature_requests (company_id, subject_type, subject_id, subject_module, document_hash, signer_user_id, signer_worker_id, role_in_document,
                sequence_order, status, expires_at, requested_by, created_by, updated_by)
             values (?, ?, ?, ?, decode(?, 'hex'), ?, ?, ?, ?, 'pending', case when ?::int is null then null else now() + make_interval(days => ?::int) end, ?, ?, ?) returning id",
            [$company, $d['subject_table'], $d['subject_id'], $res['module'], SignatureService::hash($d['subject_table'], $row), $d['signer_user_id'] ?? null, $d['signer_worker_id'] ?? null,
                $d['role_in_document'], $order, $d['expires_days'] ?? null, $d['expires_days'] ?? null, (string) auth()->id(), (string) auth()->id(), (string) auth()->id()]
        )->id;
        $accounts->audit((string) auth()->id(), 'signature_requested', $d['subject_table'], $d['subject_id'], ['request' => $id]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function sign(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $r = $this->pending($id);
        $uid = (string) auth()->id();
        if ($r->signer_user_id) {
            if ((string) $r->signer_user_id !== $uid) {
                throw ApiProblem::forbidden('Esta firma se pidió a otra persona.');
            }
            $password = (string) $request->input('password');
            if ($password === '' || ! Hash::check($password, (string) $request->user()->getAuthPassword())) {
                $this->fail('password', 'Confirma tu contraseña para firmar.');
            }
            $method = 'pin';
            $name = DB::selectOne('select full_name from users where id = ?', [$uid])->full_name;
            $doc = null;
        } else {
            // Trabajador sin usuario: firma asistida en persona por quien registra (con permiso), que declara su presencia.
            $this->can('fir.signature_requests.create');
            if (! $request->boolean('assisted')) {
                $this->fail('assisted', 'Confirma que el trabajador está presente y está de acuerdo con firmar.');
            }
            $method = 'assisted_in_person';
            $w = DB::selectOne('select full_name, document_last4 from workers where id = ?', [$r->signer_worker_id]);
            $name = $w->full_name;
            $doc = $w->document_last4 ? '****'.$w->document_last4 : null;
        }
        [, $row] = $this->subjectRow($r->subject_type, $r->subject_id);
        $current = SignatureService::hash($r->subject_type, $row);
        if (! hash_equals(bin2hex(self::bytes($r->document_hash)), $current)) {
            throw new ApiProblem(409, 'conflict', 'El registro cambió', 'El registro cambió después de pedir la firma: vuelve a pedirla para firmar la versión actual.');
        }
        $evidence = ['request_id' => $r->id, 'method' => $method, 'signed_by_user_id' => $uid, 'signer' => $name, 'subject' => "{$r->subject_type}/{$r->subject_id}",
            'document_hash' => $current, 'hash_scope' => SignatureService::scope($r->subject_type), 'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300), 'at' => now()->toIso8601String()];
        $sigId = DB::selectOne(
            "insert into signatures (company_id, signature_request_id, subject_type, subject_id, signer_user_id, signer_worker_id, signer_name, signer_document_masked, method,
                document_hash, evidence_hash, evidence, ip, user_agent, signed_at, created_by)
             values (?, ?, ?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), decode(?, 'hex'), ?::jsonb, ?::inet, ?, now(), ?) returning id",
            [$r->company_id, $r->id, $r->subject_type, $r->subject_id, $r->signer_user_id, $r->signer_worker_id, $name, $doc, $method, $current,
                hash('sha256', json_encode($evidence)), json_encode($evidence, JSON_UNESCAPED_UNICODE), $request->ip(), mb_substr((string) $request->userAgent(), 0, 300), $uid]
        )->id;
        // Imagen de la firma en el documento: la guardada del usuario, o la que el trabajador dibujó en la pantalla.
        SignatureImages::stamp($sigId, $r->company_id, $r->signer_user_id ? $uid : null, $request->input('image') ?: null);
        DB::update("update signature_requests set status = 'signed', signed_at = now(), updated_by = ? where id = ?", [$uid, $r->id]);
        $accounts->audit($uid, 'signature_signed', $r->subject_type, $r->subject_id, ['request' => $r->id, 'method' => $method]);

        return response()->json(['data' => ['status' => 'signed', 'method' => $method]]);
    }

    public function decline(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $r = $this->pending($id);
        if ((string) $r->signer_user_id !== (string) auth()->id()) {
            throw ApiProblem::forbidden('Solo la persona a quien se pidió la firma puede rechazarla.');
        }
        $reason = trim((string) $request->input('reason'));
        if ($reason === '') {
            $this->fail('reason', 'Indica por qué rechazas la firma.');
        }
        DB::update("update signature_requests set status = 'declined', declined_reason = ?, updated_by = ? where id = ?", [$reason, (string) auth()->id(), $r->id]);
        $accounts->audit((string) auth()->id(), 'signature_declined', $r->subject_type, $r->subject_id, ['request' => $r->id]);

        return response()->json(['data' => ['status' => 'declined']]);
    }

    /** Huella del registro: sus columnas (sin fechas de actualización ni versión), ordenadas. */
    public static function hashRow(object $row): string
    {
        $data = (array) $row;
        unset($data['updated_at'], $data['updated_by'], $data['version']);
        ksort($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function pending(string $id): object
    {
        $r = Str::isUuid($id) ? DB::selectOne('select * from signature_requests where id = ? and deleted_at is null', [$id]) : null;
        if (! $r) {
            throw ApiProblem::notFound('La solicitud de firma no existe o es de otra empresa.');
        }
        if ($r->status !== 'pending') {
            throw new ApiProblem(409, 'conflict', 'Firma cerrada', 'La solicitud de firma ya no está pendiente.');
        }
        if ($r->expires_at && strtotime($r->expires_at) < time()) {
            DB::update("update signature_requests set status = 'expired' where id = ?", [$r->id]);
            throw new ApiProblem(409, 'conflict', 'Firma vencida', 'La solicitud de firma venció: pídela de nuevo.');
        }

        return $r;
    }

    /** @return array{0: array, 1: object} recurso del catálogo y fila del registro (se exige poder verlo). */
    private function subjectRow(string $table, string $id): array
    {
        $res = Registry::data()['resources'][$table] ?? null;
        if (! $res || ! Str::isUuid($id)) {
            throw ApiProblem::notFound('Ese registro no admite firmas.');
        }
        if (! in_array(strtolower($res['module']).".{$table}.read", Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede ver ese registro.');
        }
        $row = DB::selectOne("select * from \"{$table}\" where id = ?".(Registry::softDelete($table) ? ' and deleted_at is null' : ''), [$id]);
        if (! $row) {
            throw ApiProblem::notFound('El registro no existe o es de otra empresa.');
        }

        return [$res + ['table' => $table], $row];
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto con las firmas.');
        }
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }

    /** El driver de Postgres puede devolver bytea como recurso o como texto «\x…». */
    private static function bytes(mixed $v): string
    {
        if (is_resource($v)) {
            $v = stream_get_contents($v);
        }
        $v = (string) $v;

        return str_starts_with($v, '\\x') ? (string) hex2bin(substr($v, 2)) : $v;
    }
}
