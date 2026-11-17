<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /psi/reba — M-PSI SCR-PSI-02: formulario del método REBA (Rapid Entire Body Assessment, Hignett y McAtamney, 2000) con el
 * cálculo del puntaje: tablas A (tronco, cuello, piernas), B (brazo, antebrazo, muñeca) y C, más carga/fuerza, agarre y actividad.
 * Niveles del método: 1 inapreciable, 2–3 bajo, 4–7 medio, 8–10 alto, 11–15 muy alto (se guardan en los niveles de la tabla:
 * 1–3 bajo, 4–7 medio, 8–10 alto, 11–15 muy alto). Con «save» registra la evaluación con el servicio de registros: un nivel alto
 * genera la acción correctiva y marca el IPERC del puesto (RN-PSI-03, reglas del registro).
 */
class RebaController extends Controller
{
    /** Tabla A [cuello 1-3][tronco 1-5][piernas 1-4]. */
    private const A = [
        1 => [1 => [1, 2, 3, 4], [2, 3, 4, 5], [2, 4, 5, 6], [3, 5, 6, 7], [4, 6, 7, 8]],
        2 => [1 => [1, 2, 3, 4], [3, 4, 5, 6], [4, 5, 6, 7], [5, 6, 7, 8], [6, 7, 8, 9]],
        3 => [1 => [3, 3, 5, 6], [4, 5, 6, 7], [5, 6, 7, 8], [6, 7, 8, 9], [7, 8, 9, 9]],
    ];

    /** Tabla B [antebrazo 1-2][brazo 1-6][muñeca 1-3]. */
    private const B = [
        1 => [1 => [1, 2, 2], [1, 2, 3], [3, 4, 5], [4, 5, 5], [6, 7, 8], [7, 8, 8]],
        2 => [1 => [1, 2, 3], [2, 3, 4], [4, 5, 5], [5, 6, 7], [7, 8, 8], [8, 9, 9]],
    ];

    /** Tabla C [puntaje A 1-12][puntaje B 1-12]. */
    private const C = [
        1 => [1, 1, 1, 2, 3, 3, 4, 5, 6, 7, 7, 7],
        2 => [1, 2, 2, 3, 4, 4, 5, 6, 6, 7, 7, 8],
        3 => [2, 3, 3, 3, 4, 5, 6, 7, 7, 8, 8, 8],
        4 => [3, 4, 4, 4, 5, 6, 7, 8, 8, 9, 9, 9],
        5 => [4, 4, 4, 5, 6, 7, 8, 8, 9, 9, 9, 9],
        6 => [6, 6, 6, 7, 8, 8, 9, 9, 10, 10, 10, 10],
        7 => [7, 7, 7, 8, 9, 9, 9, 10, 10, 11, 11, 11],
        8 => [8, 8, 8, 9, 10, 10, 10, 10, 10, 11, 11, 11],
        9 => [9, 9, 9, 10, 10, 10, 11, 11, 11, 12, 12, 12],
        10 => [10, 10, 10, 11, 11, 11, 11, 12, 12, 12, 12, 12],
        11 => [11, 11, 11, 11, 12, 12, 12, 12, 12, 12, 12, 12],
        12 => [12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12],
    ];

    public function __invoke(Request $request, RecordsService $records): JsonResponse
    {
        $save = $request->boolean('save');
        $this->can($save ? 'psi.ergonomic_assessments.create' : 'psi.ergonomic_assessments.read');
        $d = $request->validate([
            'trunk' => ['required', 'integer', 'between:1,5'], 'neck' => ['required', 'integer', 'between:1,3'], 'legs' => ['required', 'integer', 'between:1,4'],
            'load' => ['required', 'integer', 'between:0,3'],
            'upper_arm' => ['required', 'integer', 'between:1,6'], 'lower_arm' => ['required', 'integer', 'between:1,2'], 'wrist' => ['required', 'integer', 'between:1,3'],
            'coupling' => ['required', 'integer', 'between:0,3'],
            'activity' => ['required', 'integer', 'between:0,3'],
            'position_id' => ['nullable', 'uuid', 'required_if:save,true,1'], 'area_id' => ['nullable', 'uuid'], 'worker_id' => ['nullable', 'uuid'],
            'assessed_on' => ['nullable', 'date_format:Y-m-d', 'required_if:save,true,1'],
            'task' => ['nullable', 'string', 'max:300'], 'recommendations' => ['nullable', 'string', 'max:3000'],
        ], ['position_id.required_if' => 'Elige el puesto evaluado.', 'assessed_on.required_if' => 'Indica la fecha de la evaluación.']);
        $a = self::A[$d['neck']][$d['trunk']][$d['legs'] - 1];
        $b = self::B[$d['lower_arm']][$d['upper_arm']][$d['wrist'] - 1];
        $scoreA = min(12, $a + $d['load']);
        $scoreB = min(12, $b + $d['coupling']);
        $c = self::C[$scoreA][$scoreB - 1];
        $final = $c + $d['activity'];
        $level = $final >= 11 ? 'very_high' : ($final >= 8 ? 'high' : ($final >= 4 ? 'medium' : 'low'));
        $action = $final === 1 ? 'Riesgo inapreciable: no es necesaria actuación.' : ($final <= 3 ? 'Riesgo bajo: puede ser necesaria la actuación.'
            : ($final <= 7 ? 'Riesgo medio: es necesaria la actuación.' : ($final <= 10 ? 'Riesgo alto: es necesaria la actuación cuanto antes.' : 'Riesgo muy alto: es necesaria la actuación de inmediato.')));
        $result = ['table_a' => $a, 'score_a' => $scoreA, 'table_b' => $b, 'score_b' => $scoreB, 'table_c' => $c, 'score' => $final, 'risk_level' => $level, 'action' => $action];
        if ($save) {
            $findings = "Método REBA. Tronco {$d['trunk']}, cuello {$d['neck']}, piernas {$d['legs']}, carga/fuerza {$d['load']} → A {$scoreA}. "
                ."Brazo {$d['upper_arm']}, antebrazo {$d['lower_arm']}, muñeca {$d['wrist']}, agarre {$d['coupling']} → B {$scoreB}. Tabla C {$c} + actividad {$d['activity']} = {$final}. {$action}"
                .(! empty($d['task']) ? " Tarea: {$d['task']}." : '');
            $row = $records->create(Registry::resource('PSI', 'ergonomic_assessments'), array_filter([
                'position_id' => $d['position_id'], 'area_id' => $d['area_id'] ?? null, 'worker_id' => $d['worker_id'] ?? null, 'method' => 'REBA', 'assessed_on' => $d['assessed_on'],
                'assessor_user_id' => (string) auth()->id(), 'score' => $final, 'risk_level' => $level, 'findings' => $findings, 'recommendations' => $d['recommendations'] ?? null, 'status' => 'completed',
            ], fn ($x) => $x !== null));
            $result['id'] = $row['id'];
        }

        return response()->json(['data' => $result], $save ? 201 : 200);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer evaluaciones ergonómicas.');
        }
    }
}
