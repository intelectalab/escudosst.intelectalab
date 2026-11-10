<?php

namespace App\Modules\ACR;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /acr/from/{table}/{id} — crea la acción correctiva de un hallazgo, causa de investigación, acuerdo del comité,
 * brecha de línea base, reporte de acto/condición o evaluación de requisito legal. La acción queda con ese origen
 * (trazabilidad, RN-ACR-01) y el registro queda vinculado a la acción. {title, responsible_user_id, due_on}
 */
class ActionFromRecord extends Controller
{
    /** tabla => [source_type, columna que identifica el origen, estado que toma el registro al asignarle la acción] */
    private const SOURCES = [
        'findings' => ['finding', 'id', 'action_assigned'],
        'investigation_causes' => ['investigation', 'investigation_id', null],
        'meeting_agreements' => ['committee_agreement', 'id', 'in_progress'],
        'baseline_gaps' => ['baseline', 'id', null],
        'substandard_reports' => ['report', 'id', 'action_assigned'],
        'requirement_evaluations' => ['legal_requirement', 'legal_requirement_id', null],
        'audit_findings' => ['audit', 'id', 'action_assigned'],
        'management_review_outputs' => ['management_review', 'review_id', 'in_progress'],
        'measurements' => ['monitoring', 'id', null],
        'behavior_observations' => ['observation', 'id', null],
        'behavior_observation_items' => ['observation', 'observation_id', null],
        'change_requests' => ['change', 'id', null],
    ];

    public function __invoke(Request $request, string $table, string $id): JsonResponse
    {
        $src = self::SOURCES[$table] ?? null;
        $res = Registry::data()['resources'][$table] ?? null;
        if (! $src || ! $res || ! Str::isUuid($id)) {
            throw ApiProblem::notFound('Ese registro no admite acción correctiva.');
        }
        $perms = Permissions::effective();
        if (! in_array('acr.corrective_actions.create', $perms, true) || ! in_array(strtolower($res['module']).".{$table}.read", $perms, true)) {
            throw ApiProblem::forbidden('Tu rol no puede crear acciones correctivas desde este registro.');
        }
        $row = DB::selectOne("select * from \"{$table}\" where id = ? and deleted_at is null", [$id]);
        if (! $row) {
            throw ApiProblem::notFound('El registro no existe o es de otra empresa.');
        }
        // Tablas sin columna de vínculo (observaciones, solicitudes de cambio): la acción se reconoce por su origen.
        $linkable = property_exists($row, 'corrective_action_id');
        $existing = $linkable
            ? ($row->corrective_action_id ? DB::selectOne("select status from corrective_actions where id = ? and deleted_at is null", [$row->corrective_action_id]) : null)
            : DB::selectOne("select status from corrective_actions where source_type = ? and source_id = ? and deleted_at is null and status <> 'cancelled' limit 1", [$src[0], $id]);
        if ($existing && $existing->status !== 'cancelled') {
            throw new ApiProblem(409, 'conflict', 'Ya tiene acción', 'Este registro ya tiene su acción correctiva.');
        }

        $title = trim((string) $request->input('title'));
        $user = (string) $request->input('responsible_user_id');
        $due = (string) $request->input('due_on');
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $errors = [];
        if ($title === '') {
            $errors['title'][] = 'Describe la acción a realizar.';
        }
        if (! Str::isUuid($user) || ! DB::selectOne('select 1 as ok from memberships where user_id = ? and company_id = ? and deleted_at is null', [$user, app(CompanyContext::class)->companyId])) {
            $errors['responsible_user_id'][] = 'Elige el responsable.';
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || $due < $today) {
            $errors['due_on'][] = 'La fecha meta debe ser hoy o posterior.';
        }
        if ($errors) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Hay campos por corregir.', ['errors' => $errors]);
        }

        [$type, $sourceCol, $newStatus] = $src;
        $n = (int) DB::selectOne('select count(*) + 1 as n from corrective_actions where extract(year from created_at) = extract(year from now())')->n;
        do {
            $code = sprintf('AC-%s-%04d', substr($today, 0, 4), $n++);
        } while (DB::selectOne('select 1 as ok from corrective_actions where code = ? and deleted_at is null', [$code]));
        $severity = $row->severity ?? $row->risk_level ?? $row->priority ?? null;
        $actionId = DB::selectOne(
            "insert into corrective_actions (company_id, code, source_type, source_id, action_type, title, description, site_id, area_id, responsible_user_id,
                due_on, priority, status, created_by, updated_by)
             values (?, ?, ?, ?, 'corrective', ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?) returning id",
            [$row->company_id, $code, $type, $row->{$sourceCol}, mb_substr($title, 0, 500), $row->description ?? null, $row->site_id ?? null, $row->area_id ?? null,
                $user, $due, in_array($severity, ['high', 'critical'], true) ? 'high' : 'medium', (string) auth()->id(), (string) auth()->id()]
        )->id;
        if ($linkable) {
            DB::update("update \"{$table}\" set corrective_action_id = ?".($newStatus ? ', status = ?' : '').' where id = ?',
                $newStatus ? [$actionId, $newStatus, $id] : [$actionId, $id]);
        }

        return response()->json(['data' => ['id' => $actionId, 'code' => $code]], 201);
    }
}
