<?php

namespace App\Modules\AMB;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Support\Xlsx;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Medio ambiente (M-AMB):
 *  GET/PUT /amb/criteria       RN-AMB-01: criterios de significancia de la empresa (escalas de severidad y probabilidad y umbral);
 *                              al guardarlos se recalculan los aspectos vigentes (criterio de aceptación 3).
 *  GET /amb/reports.xlsx?kind=aspects|waste&year=   matriz de aspectos o residuos por tipo y mes
 */
class AmbToolsController extends Controller
{
    public static function criteria(): array
    {
        return [
            'severity_max' => (int) LegalParams::number('amb_severity_max', 5),
            'probability_max' => (int) LegalParams::number('amb_probability_max', 5),
            'threshold' => (int) LegalParams::number('amb_significance_threshold', 9),
        ];
    }

    public function criteriaEndpoint(Request $request): JsonResponse
    {
        $this->can($request->isMethod('put') ? 'amb.environmental_aspects.update' : 'amb.environmental_aspects.read');
        $recalculated = null;
        if ($request->isMethod('put')) {
            $d = $request->validate([
                'severity_max' => ['required', 'integer', 'min:2', 'max:10'],
                'probability_max' => ['required', 'integer', 'min:2', 'max:10'],
                'threshold' => ['required', 'integer', 'min:1'],
            ]);
            if ($d['threshold'] > $d['severity_max'] * $d['probability_max']) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El umbral no puede superar el puntaje máximo.', ['errors' => ['threshold' => ['El umbral no puede superar el puntaje máximo.']]]);
            }
            foreach (['amb_severity_max' => 'severity_max', 'amb_probability_max' => 'probability_max', 'amb_significance_threshold' => 'threshold'] as $key => $f) {
                LegalParams::setForCompany($key, (string) $d[$f]);
            }
            $recalculated = DB::transaction(fn () => DB::update(
                "update environmental_aspects set is_significant = coalesce(severity_value > 0 and probability_value > 0 and severity_value * probability_value >= ?, false), updated_at = now()
                  where deleted_at is null and status = 'active'", [$d['threshold']]));
        }

        return response()->json(['data' => self::criteria() + ['recalculated' => $recalculated]]);
    }

    public function report(Request $request): Response
    {
        $this->can('amb.environmental_aspects.read');
        $kind = $request->query('kind') === 'waste' ? 'waste' : 'aspects';
        $year = (int) ($request->query('year') ?: date('Y'));
        if ($kind === 'aspects') {
            $cond = ['normal' => 'Normal', 'abnormal' => 'Anormal', 'emergency' => 'Emergencia'];
            $out = [['Matriz de aspectos e impactos ambientales'], ['Umbral de significancia: '.self::criteria()['threshold'].'.'], [],
                ['Sede', 'Área', 'Actividad', 'Aspecto', 'Impacto', 'Condición', 'Severidad', 'Probabilidad', 'Puntaje', 'Significativo', 'Controles']];
            foreach (DB::select("select s.name as site, a.name as area, e.* from environmental_aspects e left join sites s on s.id = e.site_id left join areas a on a.id = e.area_id
                                  where e.deleted_at is null and e.status = 'active' order by s.name, e.activity") as $r) {
                $out[] = [(string) $r->site, (string) $r->area, $r->activity, $r->aspect, $r->impact, $cond[$r->condition] ?? $r->condition, (int) $r->severity_value, (int) $r->probability_value,
                    (int) $r->significance_score, $r->is_significant ? 'Sí' : 'No', (string) $r->controls];
            }
        } else {
            $types = ['municipal' => 'Municipal', 'non_hazardous' => 'No peligroso', 'hazardous' => 'Peligroso', 'recyclable' => 'Reaprovechable', 'electronic' => 'RAEE', 'biocontaminated' => 'Biocontaminado', 'other' => 'Otro'];
            $out = [["Residuos por tipo y mes · {$year}"], ['Cantidades en la unidad registrada.'], [], ['Tipo', 'Unidad', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Set', 'Oct', 'Nov', 'Dic', 'Total']];
            $grid = [];
            foreach (DB::select('select waste_type, unit, extract(month from generated_on)::int as m, sum(quantity) as q from waste_records
                                  where deleted_at is null and extract(year from generated_on) = ? group by 1, 2, 3', [$year]) as $r) {
                $grid[$r->waste_type.'|'.$r->unit][$r->m] = (float) $r->q;
            }
            ksort($grid);
            foreach ($grid as $k => $months) {
                [$t, $u] = explode('|', $k);
                $row = [$types[$t] ?? $t, $u];
                for ($m = 1; $m <= 12; $m++) {
                    $row[] = $months[$m] ?? 0;
                }
                $row[] = array_sum($months);
                $out[] = $row;
            }
        }

        return response(Xlsx::build($kind === 'waste' ? 'Residuos' : 'Aspectos', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.($kind === 'waste' ? "residuos-{$year}" : 'matriz-aspectos').'.xlsx"',
        ]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve la gestión ambiental.');
        }
    }
}
