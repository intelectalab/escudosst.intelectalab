<?php

namespace App\Core\Auth;

use App\Modules\REC\Registry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alcance por sede/área en los endpoints propios de los módulos que reciben el id de un registro en la ruta
 * (docs/03 §3, docs/05 §2, M-USR criterio 4): antes de llegar al controlador se comprueba que el registro esté en el
 * alcance de la membresía (Scope::assertRecord → 404 si no). Las tablas hijas (causas, filas, asistencias…) heredan el
 * alcance de su registro padre (Scope::parent). Se llama desde SetTenantContext, con la empresa ya fijada.
 *
 * Cada entrada: prefijo de la ruta (sin «api/v1/») => [parámetro => tabla]. Valores especiales:
 *  - «@table»: la tabla viene en el parámetro {table} de la misma ruta (firmas, acción desde registro).
 *  - «subject:signature_requests» / «subject:signatures»: se revisa el registro firmado (subject_type, subject_id).
 *  - «worker:<tabla>»: tabla fuera del registro con worker_id: se revisa el trabajador.
 */
final class ScopeRoutes
{
    private const MAP = [
        'acr/from/{table}/{id}' => ['id' => '@table'],
        'act/report-points/{id}' => ['id' => 'report_points'],
        'act/reports/{id}' => ['id' => 'substandard_reports'],
        'ats/forms/{id}' => ['id' => 'ats_forms'],
        'aui/audits/{id}' => ['id' => 'audits'],
        'cap/attendances/{id}' => ['id' => 'training_attendances'],
        'cap/sessions/{id}' => ['id' => 'training_sessions'],
        'ch5/attendances/{id}' => ['id' => 'talk_attendances'],
        'ch5/talks/{id}' => ['id' => 'safety_talks'],
        'com/elections/{id}' => ['id' => 'elections'],
        'com/meetings/{id}' => ['id' => 'committee_meetings'],
        'ctr/accreditations/{id}' => ['id' => 'contractor_accreditations'],
        'des/absences/{id}' => ['id' => 'absences'],
        'doc/documents/{id}' => ['id' => 'documents'],
        'doc/versions/{id}' => ['id' => 'document_versions'],
        'emo/orders/{order}' => ['order' => 'medical_exam_orders'],
        'emo/workers/{worker}' => ['worker' => 'workers'],
        'emo/positions/{id}' => ['id' => 'positions'],
        'epp/replacement-requests/{id}' => ['id' => 'worker:ppe_replacement_requests'],
        'est/man-hours/{id}' => ['id' => 'man_hours'],
        'est/months/{id}' => ['id' => 'monthly_statistics'],
        'fir/requests/{id}/remind' => ['id' => 'subject:signature_requests'],
        'fir/signatures/{id}' => ['id' => 'subject:signatures'],
        'fir/subject/{table}/{id}' => ['id' => '@table'],
        'fir/verify/{table}/{id}' => ['id' => '@table'],
        'inv/investigations/{id}' => ['id' => 'investigations', 'cause' => 'investigation_causes'],
        'lba/assessments/{assessment}' => ['assessment' => 'baseline_assessments'],
        'lba/assessments/{id}' => ['id' => 'baseline_assessments'],
        'map/maps/{id}' => ['id' => 'risk_maps'],
        'mrl/requirements/{id}' => ['id' => 'legal_requirements'],
        'pas/activities/{id}' => ['id' => 'program_activities'],
        'pas/executions/{id}' => ['id' => 'activity_executions'],
        'pet/procedures/{id}' => ['id' => 'procedures'],
        'pet/versions/{id}' => ['id' => 'procedure_versions'],
        'ptr/work-permits/{id}' => ['id' => 'work_permits'],
        'reg/exports/{id}' => ['id' => 'register_exports'],
        'reports/ats_forms/{id}' => ['id' => 'ats_forms'],
        'reports/baseline_assessments/{id}' => ['id' => 'baseline_assessments'],
        'reports/committees/{id}' => ['id' => 'committees'],
        'reports/documents/{id}' => ['id' => 'documents'],
        'reports/elections/{id}' => ['id' => 'elections'],
        'reports/inspections/{id}' => ['id' => 'inspections'],
        'reports/ppe_deliveries/worker/{id}' => ['id' => 'workers'],
        'reports/signatures/{id}' => ['id' => 'subject:signatures'],
        'reports/sst_events/{id}' => ['id' => 'sst_events'],
        'reports/work_permits/{id}' => ['id' => 'work_permits'],
        'sim/drills/{id}' => ['id' => 'drills'],
        'sun/dossier-items/{id}' => ['id' => 'dossier_items'],
        'sun/dossiers/{dossier}' => ['dossier' => 'inspection_dossiers', 'id' => 'dossier_items'],
        'sun/share-links/{id}' => ['id' => 'dossier_share_links'],
        'trb/workers/{id}' => ['id' => 'workers'],
        'vul/adjustments/{id}' => ['id' => 'work_adjustments'],
    ];

    public static function check(Request $request): void
    {
        $route = $request->route();
        if (! is_object($route) || ! Scope::filtersRecords()) {
            return;
        }
        $uri = preg_replace('#^api/v1/#', '', $route->uri());
        // Lo de toda la empresa (sin sede ni área) se lee pero no se modifica con alcance; votar, postular o firmar lo propio no es modificar.
        $write = ! in_array($request->method(), ['GET', 'HEAD'], true) && ! preg_match('#/(vote|apply|sign|otp|decline|remind)$#', $uri);
        foreach (self::MAP as $prefix => $params) {
            if ($uri !== $prefix && ! str_starts_with($uri, $prefix.'/') && ! str_starts_with($uri, $prefix.'.')) {
                continue;
            }
            foreach ($params as $param => $table) {
                $id = $route->parameter($param);
                if (! is_string($id) || ! Str::isUuid($id)) {
                    continue;
                }
                self::assert($table, $id, $route->parameter('table'), $write);
            }
        }
    }

    private static function assert(string $table, string $id, mixed $routeTable, bool $write): void
    {
        if ($table === '@table') {
            if (is_string($routeTable) && Registry::has($routeTable)) {
                Scope::assertRecord($routeTable, $id, $write);
            }

            return;
        }
        if (str_starts_with($table, 'subject:')) {
            $t = substr($table, 8);
            $s = DB::selectOne("select subject_type, subject_id from \"{$t}\" where id = ?", [$id]);
            if ($s && is_string($s->subject_type) && Registry::has($s->subject_type) && $s->subject_id) {
                Scope::assertRecord($s->subject_type, (string) $s->subject_id, $write);
            }

            return;
        }
        if (str_starts_with($table, 'worker:')) {
            $t = substr($table, 7);
            $w = DB::selectOne("select worker_id from \"{$t}\" where id = ?", [$id])?->worker_id;
            if (! $w) {
                return;
            }
            Scope::assertRecord('workers', (string) $w, $write);

            return;
        }
        Scope::assertRecord($table, $id, $write);
    }

    /** Para los endpoints que reciben el trabajador en el cuerpo o la consulta (entrega rápida de EPP, etc.). */
    public static function assertWorker(?string $workerId): void
    {
        if ($workerId !== null && Str::isUuid($workerId)) {
            Scope::assertRecord('workers', $workerId);
        }
    }
}
