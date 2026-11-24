<?php

namespace App\Modules\TRB;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consentimiento de datos del trabajador (M-TRB SCR-TRB-03 «consentimiento de datos»; Ley 29733) en worker_consents.
 *  GET  /trb/consent-text                 texto vigente de la cláusula (parámetro legal worker_data_consent_text) y su versión
 *  GET  /trb/workers/{id}/consents        consentimientos registrados del trabajador
 *  POST /trb/workers/{id}/consents        registrar {consent_type?, granted, method: in_person_assisted|paper_scanned, file_id?}
 *
 * El texto legal no está aprobado: el parámetro es global «a confirmar» con un texto neutro hasta que el abogado lo apruebe
 * (la empresa puede fijar el suyo). Cada registro guarda la versión del texto (clave@vigencia), su huella SHA-256, la fecha,
 * el canal (method) y quién lo registró (created_by).
 */
class WorkerConsentController extends Controller
{
    public const TEXT_KEY = 'worker_data_consent_text';

    private const METHODS = ['in_person_assisted', 'paper_scanned'];

    public function text(): JsonResponse
    {
        $this->can('trb.workers.read');

        return response()->json(['data' => self::current()]);
    }

    public function index(string $id): JsonResponse
    {
        $this->can('trb.worker_consents.read');
        $this->worker($id);
        $rows = DB::select(
            "select c.id, c.consent_type, c.text_version, c.granted, c.granted_at, c.method, c.file_id, c.revoked_at, c.revocation_reason, c.created_at,
                    u.full_name as registered_by
               from worker_consents c left join users u on u.id = c.created_by
              where c.worker_id = ? and c.deleted_at is null order by c.granted_at desc", [$id]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $this->can('trb.worker_consents.create');
        $d = $request->validate([
            'consent_type' => ['nullable', 'in:data_processing'],
            'granted' => ['required', 'boolean'],
            'method' => ['required', 'in:'.implode(',', self::METHODS)],
            'file_id' => ['nullable', 'uuid', 'required_if:method,paper_scanned'],
        ], [
            'granted.required' => 'Indica si el trabajador dio su consentimiento.',
            'method.required' => 'Indica cómo se registró el consentimiento.',
            'method.in' => 'Elige cómo se registró el consentimiento.',
            'file_id.required_if' => 'Adjunta el consentimiento firmado en papel.',
        ]);
        $this->worker($id);
        if (! empty($d['file_id']) && ! DB::selectOne('select 1 as ok from files where id = ? and deleted_at is null', [$d['file_id']])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El archivo adjunto no existe.', ['errors' => ['file_id' => ['El archivo adjunto no existe.']]]);
        }
        $t = self::current();
        $uid = (string) auth()->id();
        $company = app(CompanyContext::class)->companyId;
        $row = DB::selectOne(
            "insert into worker_consents (company_id, worker_id, consent_type, text_version, text_sha256, granted, granted_at, method, file_id, ip, created_by, updated_by)
             values (?, ?, ?, ?, decode(?, 'hex'), ?, now(), ?, ?, ?::inet, ?, ?) returning id, granted_at",
            [$company, $id, $d['consent_type'] ?? 'data_processing', $t['version'], $t['sha256'], $d['granted'] ? 'true' : 'false', $d['method'], $d['file_id'] ?? null,
                $request->ip(), $uid, $uid]);
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
                    values (?, ?, ?, 'user', 'worker_consent_recorded', 'TRB', 'workers', ?, ?::jsonb, ?::inet, ?)",
            [$company, $uid, app(CompanyContext::class)->roleCode, $id,
                json_encode(['consent' => $row->id, 'granted' => (bool) $d['granted'], 'method' => $d['method'], 'text_version' => $t['version']], JSON_UNESCAPED_UNICODE),
                $request->ip(), mb_substr((string) $request->userAgent(), 0, 300)]);

        return response()->json(['data' => ['id' => $row->id, 'granted_at' => $row->granted_at, 'text_version' => $t['version'], 'to_confirm' => $t['to_confirm']]], 201);
    }

    /** Estado del consentimiento de datos de un trabajador: el último registro sin revocar. */
    public static function status(string $workerId): ?object
    {
        return DB::selectOne("select granted, granted_at, method, text_version from worker_consents
                               where worker_id = ? and consent_type = 'data_processing' and deleted_at is null and revoked_at is null
                               order by granted_at desc limit 1", [$workerId]);
    }

    /** @return array{text: string, version: string, sha256: string, to_confirm: bool} */
    public static function current(): array
    {
        $company = app()->bound(CompanyContext::class) ? app(CompanyContext::class)->companyId : null;
        $p = DB::selectOne(
            'select value, valid_from, to_confirm, company_id from legal_parameters where key = ? and (company_id is null or company_id = ?) and valid_from <= current_date
                and (valid_to is null or valid_to >= current_date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1',
            [self::TEXT_KEY, $company]);
        $text = $p && trim((string) $p->value) !== '' ? trim((string) $p->value) : 'Texto de consentimiento pendiente de aprobación';

        return ['text' => $text, 'version' => self::TEXT_KEY.'@'.($p ? ($p->company_id ? 'empresa:' : '').$p->valid_from : 'sin-parametro'),
            'sha256' => hash('sha256', $text), 'to_confirm' => ! $p || (bool) $p->to_confirm];
    }

    private function worker(string $id): void
    {
        if (! Str::isUuid($id) || ! DB::selectOne('select 1 as ok from workers where id = ? and deleted_at is null', [$id])) {
            throw ApiProblem::notFound('El trabajador no existe o es de otra empresa.');
        }
        $ctx = app(CompanyContext::class);
        if ($ctx->roleCode === 'worker' && DB::selectOne('select worker_id from memberships where id = ?', [$ctx->membershipId])?->worker_id !== $id) {
            throw ApiProblem::notFound('El trabajador no existe o es de otra empresa.');
        }
        \App\Core\Auth\Scope::assertRecord('workers', $id);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede ver o registrar consentimientos.');
        }
    }
}
