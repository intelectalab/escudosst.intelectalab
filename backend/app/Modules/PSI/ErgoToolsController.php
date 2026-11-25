<?php

namespace App\Modules\PSI;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ergonomía (M-PSI SCR-PSI-01/02 y §11):
 *  POST /psi/rula              método RULA (McAtamney y Corlett, 1993): tablas A (brazo, antebrazo, muñeca, giro), B (cuello, tronco,
 *                              piernas) y C, más uso muscular y fuerza/carga. Niveles de actuación del método: 1–2 aceptable, 3–4
 *                              investigar, 5–6 investigar y cambiar pronto, 7 cambiar de inmediato (en la tabla: bajo, medio, alto,
 *                              muy alto). Con «save» registra la evaluación (un nivel alto genera la acción, RN-PSI-03)
 *  GET  /psi/rm375             factores de la lista de verificación de la R.M. 375-2008-TR (parámetro psi_rm375_checklist)
 *  POST /psi/rm375             registra la lista respondida: factores presentes y nivel de riesgo que indica quien evalúa
 *  GET  /psi/ergonomics/report informe ergonómico por puesto (última evaluación, método, puntaje, nivel, acción) e indicadores
 */
class ErgoToolsController extends Controller
{
    /** Tabla A [brazo 1-6][antebrazo 1-3][muñeca 1-4] => [sin giro, con giro]. */
    private const A = [
        1 => [1 => [[1, 2], [2, 2], [2, 3], [3, 3]], [[2, 2], [2, 2], [3, 3], [3, 3]], [[2, 3], [3, 3], [3, 3], [4, 4]]],
        2 => [1 => [[2, 3], [3, 3], [3, 3], [4, 4]], [[3, 3], [3, 3], [3, 3], [4, 4]], [[3, 4], [4, 4], [4, 4], [5, 5]]],
        3 => [1 => [[3, 3], [4, 4], [4, 4], [5, 5]], [[3, 4], [4, 4], [4, 4], [5, 5]], [[4, 4], [4, 4], [4, 5], [5, 5]]],
        4 => [1 => [[4, 4], [4, 4], [4, 5], [5, 5]], [[4, 4], [4, 4], [4, 5], [5, 5]], [[4, 4], [4, 5], [5, 5], [6, 6]]],
        5 => [1 => [[5, 5], [5, 5], [5, 6], [6, 7]], [[5, 6], [6, 6], [6, 7], [7, 7]], [[6, 6], [6, 7], [7, 7], [7, 8]]],
        6 => [1 => [[7, 7], [7, 7], [7, 8], [8, 9]], [[8, 8], [8, 8], [8, 9], [9, 9]], [[9, 9], [9, 9], [9, 9], [9, 9]]],
    ];

    /** Tabla B [cuello 1-6][tronco 1-6] => [piernas apoyadas, sin apoyo]. */
    private const B = [
        1 => [1 => [1, 3], [2, 3], [3, 4], [5, 5], [6, 6], [7, 7]],
        2 => [1 => [2, 3], [2, 3], [4, 5], [5, 5], [6, 7], [7, 7]],
        3 => [1 => [3, 3], [3, 4], [4, 5], [5, 6], [6, 7], [7, 7]],
        4 => [1 => [5, 5], [5, 6], [6, 7], [7, 7], [7, 7], [8, 8]],
        5 => [1 => [7, 7], [7, 7], [7, 8], [8, 8], [8, 8], [8, 8]],
        6 => [1 => [8, 8], [8, 8], [8, 8], [8, 9], [9, 9], [9, 9]],
    ];

    /** Tabla C [puntaje C 1-8+][puntaje D 1-7+]. */
    private const C = [
        1 => [1, 2, 3, 3, 4, 5, 5], 2 => [2, 2, 3, 4, 4, 5, 5], 3 => [3, 3, 3, 4, 4, 5, 6], 4 => [3, 3, 3, 4, 5, 6, 6],
        5 => [4, 4, 4, 5, 6, 7, 7], 6 => [4, 4, 5, 6, 6, 7, 7], 7 => [5, 5, 6, 6, 7, 7, 7], 8 => [5, 5, 6, 7, 7, 7, 7],
    ];

    public function rula(Request $request, RecordsService $records): JsonResponse
    {
        $save = $request->boolean('save');
        $this->can($save ? 'psi.ergonomic_assessments.create' : 'psi.ergonomic_assessments.read');
        $d = $request->validate([
            'upper_arm' => ['required', 'integer', 'between:1,6'], 'lower_arm' => ['required', 'integer', 'between:1,3'], 'wrist' => ['required', 'integer', 'between:1,4'],
            'wrist_twist' => ['required', 'integer', 'between:1,2'], 'arm_muscle' => ['required', 'integer', 'between:0,1'], 'arm_force' => ['required', 'integer', 'between:0,3'],
            'neck' => ['required', 'integer', 'between:1,6'], 'trunk' => ['required', 'integer', 'between:1,6'], 'legs' => ['required', 'integer', 'between:1,2'],
            'body_muscle' => ['required', 'integer', 'between:0,1'], 'body_force' => ['required', 'integer', 'between:0,3'],
            'position_id' => ['nullable', 'uuid', 'required_if:save,true,1'], 'area_id' => ['nullable', 'uuid'], 'worker_id' => ['nullable', 'uuid'],
            'assessed_on' => ['nullable', 'date_format:Y-m-d', 'required_if:save,true,1'],
            'task' => ['nullable', 'string', 'max:300'], 'recommendations' => ['nullable', 'string', 'max:3000'],
        ], ['position_id.required_if' => 'Elige el puesto evaluado.', 'assessed_on.required_if' => 'Indica la fecha de la evaluación.']);
        $a = self::A[$d['upper_arm']][$d['lower_arm']][$d['wrist'] - 1][$d['wrist_twist'] - 1];
        $b = self::B[$d['neck']][$d['trunk']][$d['legs'] - 1];
        $c = $a + $d['arm_muscle'] + $d['arm_force'];
        $dd = $b + $d['body_muscle'] + $d['body_force'];
        $final = self::C[min(8, $c)][min(7, $dd) - 1];
        $level = $final >= 7 ? 'very_high' : ($final >= 5 ? 'high' : ($final >= 3 ? 'medium' : 'low'));
        $action = $final <= 2 ? 'Nivel 1: postura aceptable.' : ($final <= 4 ? 'Nivel 2: se requiere investigar; pueden necesitarse cambios.'
            : ($final <= 6 ? 'Nivel 3: investigar y cambiar pronto.' : 'Nivel 4: investigar y cambiar de inmediato.'));
        $result = ['table_a' => $a, 'score_c' => $c, 'table_b' => $b, 'score_d' => $dd, 'score' => $final, 'risk_level' => $level, 'action' => $action];
        if ($save) {
            $findings = "Método RULA. Brazo {$d['upper_arm']}, antebrazo {$d['lower_arm']}, muñeca {$d['wrist']}, giro {$d['wrist_twist']} → A {$a}; uso muscular {$d['arm_muscle']}, fuerza {$d['arm_force']} → C {$c}. "
                ."Cuello {$d['neck']}, tronco {$d['trunk']}, piernas {$d['legs']} → B {$b}; uso muscular {$d['body_muscle']}, fuerza {$d['body_force']} → D {$dd}. Puntaje final {$final}. {$action}"
                .(! empty($d['task']) ? " Tarea: {$d['task']}." : '');
            $row = $records->create(Registry::resource('PSI', 'ergonomic_assessments'), array_filter([
                'position_id' => $d['position_id'], 'area_id' => $d['area_id'] ?? null, 'worker_id' => $d['worker_id'] ?? null, 'method' => 'RULA', 'assessed_on' => $d['assessed_on'],
                'assessor_user_id' => (string) auth()->id(), 'score' => $final, 'risk_level' => $level, 'findings' => $findings, 'recommendations' => $d['recommendations'] ?? null, 'status' => 'completed',
            ], fn ($x) => $x !== null));
            $result['id'] = $row['id'];
        }

        return response()->json(['data' => $result], $save ? 201 : 200);
    }

    public function rm375Items(): JsonResponse
    {
        $this->can('psi.ergonomic_assessments.read');

        return response()->json(['data' => ['items' => LegalParams::list('psi_rm375_checklist')]]);
    }

    public function rm375(Request $request, RecordsService $records): JsonResponse
    {
        $this->can('psi.ergonomic_assessments.create');
        $items = LegalParams::list('psi_rm375_checklist');
        $d = $request->validate([
            'answers' => ['required', 'array'], 'answers.*.present' => ['required', 'boolean'], 'answers.*.notes' => ['nullable', 'string', 'max:500'],
            'risk_level' => ['required', 'in:low,medium,high,very_high'],
            'position_id' => ['required', 'uuid'], 'area_id' => ['nullable', 'uuid'], 'worker_id' => ['nullable', 'uuid'], 'assessed_on' => ['required', 'date_format:Y-m-d'],
            'recommendations' => ['nullable', 'string', 'max:3000'],
        ], ['risk_level.required' => 'Indica el nivel de riesgo que resulta de la evaluación.', 'position_id.required' => 'Elige el puesto evaluado.']);
        $missing = array_values(array_diff($items, array_keys($d['answers'])));
        if ($missing) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Responde cada factor de la lista: falta «'.$missing[0].'».', ['errors' => ['answers' => ['Responde cada factor de la lista.']]]);
        }
        $checklist = array_map(fn ($i) => ['item' => $i, 'present' => (bool) $d['answers'][$i]['present'], 'notes' => $d['answers'][$i]['notes'] ?? null], $items);
        $present = array_column(array_filter($checklist, fn ($x) => $x['present']), 'item');
        $row = $records->create(Registry::resource('PSI', 'ergonomic_assessments'), array_filter([
            'position_id' => $d['position_id'], 'area_id' => $d['area_id'] ?? null, 'worker_id' => $d['worker_id'] ?? null, 'method' => 'checklist_rm375', 'assessed_on' => $d['assessed_on'],
            'assessor_user_id' => (string) auth()->id(), 'score' => count($present), 'risk_level' => $d['risk_level'],
            'findings' => 'Lista de verificación R.M. 375-2008-TR: '.count($present).' de '.count($items).' factores presentes'.($present ? ' ('.implode(', ', $present).')' : '').'.',
            'recommendations' => $d['recommendations'] ?? null, 'status' => 'completed',
        ], fn ($x) => $x !== null));
        DB::update('update ergonomic_assessments set checklist = ?::jsonb where id = ?', [json_encode($checklist, JSON_UNESCAPED_UNICODE), $row['id']]);

        return response()->json(['data' => ['id' => $row['id'], 'present' => count($present), 'total' => count($items)]], 201);
    }

    public function report(): JsonResponse
    {
        $this->can('psi.ergonomic_assessments.read');
        $rows = DB::select(
            "select p.id as position_id, p.name as position,
                    (select count(*) from workers w where w.current_position_id = p.id and w.status = 'active' and w.deleted_at is null".\App\Core\Auth\Scope::andTable('workers', 'w').") as workers,
                    e.id, e.method, e.assessed_on, e.score, e.risk_level, e.recommendations, u.full_name as assessor, c.code as action_code, c.status as action_status
               from positions p
               left join lateral (select * from ergonomic_assessments x where x.position_id = p.id and x.deleted_at is null and x.status <> 'draft'".\App\Core\Auth\Scope::andTable('ergonomic_assessments', 'x')." order by x.assessed_on desc, x.created_at desc limit 1) e on true
               left join users u on u.id = e.assessor_user_id left join corrective_actions c on c.id = e.corrective_action_id
              where p.deleted_at is null and p.status = 'active'".\App\Core\Auth\Scope::andTable('positions', 'p')." order by (e.risk_level = 'very_high') desc nulls last, (e.risk_level = 'high') desc nulls last, p.name");
        $evaluated = array_filter($rows, fn ($r) => $r->id !== null);
        $high = array_filter($evaluated, fn ($r) => in_array($r->risk_level, ['high', 'very_high'], true));
        $closed = array_filter($high, fn ($r) => in_array($r->action_status, ['verified', 'closed'], true));
        $c = DB::selectOne('select legal_name, trade_name, ruc from companies where id = ?', [app(\App\Core\Tenancy\CompanyContext::class)->companyId]);

        return response()->json(['data' => [
            'company' => ['name' => $c->trade_name ?: $c->legal_name, 'legal_name' => $c->legal_name, 'ruc' => trim((string) $c->ruc)],
            'indicators' => [
                'positions' => count($rows), 'evaluated' => count($evaluated), 'evaluated_pct' => $rows ? (int) round(count($evaluated) * 100 / count($rows)) : null,
                'high' => count($high), 'high_actions_closed' => count($closed),
                'by_level' => array_count_values(array_map(fn ($r) => $r->risk_level, $evaluated)),
            ],
            'positions' => $rows,
        ]]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer evaluaciones ergonómicas.');
        }
    }
}
