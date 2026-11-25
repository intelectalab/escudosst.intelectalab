<?php

namespace App\Modules\ACT;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Actos y condiciones (M-ACT):
 *  POST /act/report-points/{id}/rotate   cambia el QR del punto (el anterior deja de funcionar) y devuelve el enlace nuevo
 *  POST /act/reports/{id}/escalate       RN-ACT-06: escala el reporte a un evento de ACC {event_type, occurred_at?, site_id?}; el
 *                                        reporte queda «escalado» y ligado al evento, donde corren los plazos de notificación
 */
class ActToolsController extends Controller
{
    public function rotate(string $id): JsonResponse
    {
        if (! in_array('act.report_points.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no cambia los códigos QR.');
        }
        $p = Str::isUuid($id) ? DB::selectOne('select id, token_rotation from report_points where id = ? and deleted_at is null', [$id]) : null;
        if (! $p) {
            throw ApiProblem::notFound('El punto de reporte no existe o es de otra empresa.');
        }
        $company = app(CompanyContext::class)->companyId;
        $n = (int) $p->token_rotation + 1;
        $token = ReportPointController::token($company, $p->id, $n);
        DB::update("update report_points set token_rotation = ?, token_hash = decode(?, 'hex'), token_last4 = ?, updated_at = now(), updated_by = ? where id = ?",
            [$n, hash('sha256', $token), substr($token, -4), (string) auth()->id(), $p->id]);
        $ctx = app(CompanyContext::class);
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes) values (?, ?, ?, 'user', 'update', 'ACT', 'report_points', ?, ?::jsonb)",
            [$company, (string) auth()->id(), $ctx->roleCode, $p->id, json_encode(['token_rotation' => ['from' => $n - 1, 'to' => $n]])]);

        return response()->json(['data' => ['url' => ReportPointController::url($company, $p->id, $n), 'rotations' => $n]]);
    }

    public function escalate(Request $request, string $id, RecordsService $records): JsonResponse
    {
        if (! in_array('act.substandard_reports.update', Permissions::effective(), true) || ! in_array('acc.sst_events.create', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no escala reportes a eventos.');
        }
        $r = Str::isUuid($id) ? DB::selectOne('select * from substandard_reports where id = ? and deleted_at is null', [$id]) : null;
        if (! $r) {
            throw ApiProblem::notFound('El reporte no existe o es de otra empresa.');
        }
        if ($r->escalated_event_id || in_array($r->status, ['rejected', 'closed'], true)) {
            throw new ApiProblem(409, 'conflict', 'No se puede escalar', $r->escalated_event_id ? 'El reporte ya fue escalado a un evento.' : 'El reporte está descartado o cerrado.');
        }
        $d = $request->validate([
            'event_type' => ['required', 'in:fatal_accident,disabling_accident,minor_accident,dangerous_incident,incident,occupational_disease'],
            'occurred_at' => ['nullable', 'date'], 'site_id' => ['nullable', 'uuid'],
            // M-ACC: el plazo de la enfermedad ocupacional corre desde el diagnóstico.
            'diagnosed_on' => ['nullable', 'date_format:Y-m-d', 'required_if:event_type,occupational_disease'],
        ], ['event_type.required' => 'Elige qué tipo de evento es.', 'diagnosed_on.required_if' => 'Indica la fecha del diagnóstico de la enfermedad ocupacional.']);
        $site = $d['site_id'] ?? $r->site_id;
        if (! $site) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Indica la sede donde ocurrió.', ['errors' => ['site_id' => ['Indica la sede donde ocurrió.']]]);
        }
        $event = $records->create(Registry::resource('ACC', 'sst_events'), array_filter([
            'event_type' => $d['event_type'], 'origin' => $r->origin === 'qr' ? 'qr' : ($r->origin === 'whatsapp' ? 'whatsapp' : 'web'),
            'occurred_at' => $d['occurred_at'] ?? $r->occurred_at ?? $r->reported_at, 'site_id' => $site, 'area_id' => $r->area_id,
            'exact_location' => $r->location_text, 'description' => "Escalado desde el reporte {$r->code}: {$r->description}",
            'is_contractor_event' => $r->contractor_id ? true : null, 'contractor_id' => $r->contractor_id,
            'diagnosed_on' => $d['event_type'] === 'occupational_disease' ? $d['diagnosed_on'] : null,
        ], fn ($x) => $x !== null));
        DB::update("update substandard_reports set status = 'escalated', escalated_event_id = ?, classification = coalesce(classification, ?), triaged_by = coalesce(triaged_by, ?),
                     triaged_at = coalesce(triaged_at, now()), updated_at = now(), updated_by = ? where id = ?",
            [$event['id'], $d['event_type'] === 'dangerous_incident' ? 'dangerous_incident' : null, (string) auth()->id(), (string) auth()->id(), $r->id]);

        return response()->json(['data' => ['event_id' => $event['id'], 'event_code' => $event['code'] ?? null]], 201);
    }
}
