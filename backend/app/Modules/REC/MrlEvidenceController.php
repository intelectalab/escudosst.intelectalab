<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\REC\Rules\DiagnosticRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /mrl/evidence-options[?type=]  M-MRL SCR-MRL-04 y RN-MRL-08: tipos de registro del sistema que sirven de evidencia y, con
 * ?type=, los 50 registros más recientes de ese tipo en la empresa (id y texto para elegir).
 */
class MrlEvidenceController extends Controller
{
    private const LABEL = [
        'trainings' => "concat_ws(' · ', code, title)",
        'inspections' => "concat_ws(' · ', code, title)",
        'drills' => "concat_ws(' · ', code, title, to_char(scheduled_at, 'DD/MM/YYYY'))",
        'committee_meetings' => "'Sesión N.º '||coalesce(number::text, '—')||' · '||coalesce(to_char(coalesce(held_at, scheduled_at), 'DD/MM/YYYY'), '')",
        'safety_talks' => "concat_ws(' · ', topic, to_char(talk_on, 'DD/MM/YYYY'))",
        'measurements' => "'Medición del '||to_char(measured_at, 'DD/MM/YYYY')||' · '||coalesce(value::text, '')||' '||coalesce(unit, '')",
        'management_reviews' => "concat_ws(' · ', title, to_char(review_on, 'DD/MM/YYYY'))",
        'documents' => "concat_ws(' · ', code, title)",
        'iperc_matrices' => "concat_ws(' · ', code, title)",
        'ppe_deliveries' => "'Entrega de EPP del '||to_char(delivered_at, 'DD/MM/YYYY')||coalesce(' · '||(select full_name from workers w where w.id = worker_id), '')",
        'audits' => "concat_ws(' · ', code, standard, to_char(starts_on, 'DD/MM/YYYY'))",
        'risst_versions' => "concat_ws(' · ', title, 'versión '||version_number)",
        'sst_policies' => "concat_ws(' · ', title, 'versión '||version_number)",
    ];

    public function __invoke(Request $request): JsonResponse
    {
        if (! in_array('mrl.requirement_evaluations.create', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no evalúa requisitos legales.');
        }
        $type = (string) $request->query('type', '');
        if ($type === '') {
            return response()->json(['data' => array_map(fn ($t, $v) => ['type' => $t, 'module' => $v[0], 'label' => $v[1]], array_keys(DiagnosticRules::EVIDENCE_TABLES), DiagnosticRules::EVIDENCE_TABLES)]);
        }
        if (! isset(self::LABEL[$type], DiagnosticRules::EVIDENCE_TABLES[$type])) {
            throw ApiProblem::notFound('Ese tipo de registro no sirve como evidencia.');
        }

        return response()->json(['data' => DB::select('select id, '.self::LABEL[$type]." as label from \"{$type}\" where deleted_at is null order by created_at desc limit 50")]);
    }
}
