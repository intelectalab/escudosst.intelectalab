<?php

namespace App\Modules\HOM;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Homologación de proveedores (complemento HOM):
 *  PUT /hom/templates/{id}/criteria    criterios de la plantilla [{name, weight}] (los pesos suman 100)
 *  PUT /hom/evaluations/{id}/scores    puntaje de cada criterio (0 a 5) → puntaje total (0 a 100, ponderado) y resultado
 * Resultado: aprobado si el total alcanza el mínimo de la plantilla (por defecto 70).
 */
class HomController extends Controller
{
    public function criteria(Request $request, string $id): JsonResponse
    {
        $this->can('hom.vendor_evaluation_templates.update');
        $t = $this->find('vendor_evaluation_templates', $id, 'La plantilla no existe o es de otra empresa.');
        if ($t->company_id === null) {
            throw ApiProblem::forbidden('Es una plantilla general de EscudoSST: crea una propia para cambiar sus criterios.');
        }
        $d = $request->validate([
            'criteria' => ['required', 'array', 'min:1', 'max:20'],
            'criteria.*.name' => ['required', 'string', 'min:2', 'max:120', 'distinct'],
            'criteria.*.weight' => ['required', 'numeric', 'min:1', 'max:100'],
        ], ['criteria.required' => 'Agrega al menos un criterio.', 'criteria.*.name.distinct' => 'Hay criterios repetidos.', 'criteria.*.name.required' => 'Cada criterio necesita un nombre.']);
        $sum = array_sum(array_column($d['criteria'], 'weight'));
        if (abs($sum - 100) > 0.01) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los pesos', "Los pesos deben sumar 100 (hoy suman {$sum}).", ['errors' => ['criteria' => ["Los pesos deben sumar 100 (hoy suman {$sum})."]]]);
        }
        $criteria = array_map(fn ($c) => ['name' => trim($c['name']), 'weight' => (float) $c['weight']], $d['criteria']);
        DB::update('update vendor_evaluation_templates set criteria = ?::jsonb, updated_by = ? where id = ?', [json_encode($criteria, JSON_UNESCAPED_UNICODE), (string) auth()->id(), $id]);

        return response()->json(['data' => ['criteria' => $criteria]]);
    }

    public function scores(Request $request, string $id): JsonResponse
    {
        $this->can('hom.vendor_evaluations.update');
        $e = $this->find('vendor_evaluations', $id, 'La evaluación no existe o es de otra empresa.');
        if (in_array($e->status, ['approved', 'rejected', 'expired'], true) && $e->rating) {
            throw new ApiProblem(409, 'conflict', 'Evaluación cerrada', 'La evaluación ya tiene resultado: crea una nueva para volver a evaluar.');
        }
        $t = DB::selectOne('select criteria, passing_score from vendor_evaluation_templates where id = ?', [$e->template_id]);
        $criteria = json_decode($t->criteria ?? '[]', true) ?: [];
        if (! $criteria) {
            throw new ApiProblem(409, 'conflict', 'Plantilla sin criterios', 'La plantilla no tiene criterios: agrégalos primero.');
        }
        $d = $request->validate(['scores' => ['required', 'array'], 'scores.*' => ['nullable', 'numeric', 'min:0', 'max:5']]);
        $total = 0.0;
        $missing = [];
        foreach ($criteria as $c) {
            $v = $d['scores'][$c['name']] ?? null;
            if ($v === null) {
                $missing[] = $c['name'];

                continue;
            }
            $total += ((float) $v / 5) * (float) $c['weight'];
        }
        if ($missing) {
            throw new ApiProblem(422, 'validation_failed', 'Faltan puntajes', 'Califica todos los criterios: '.implode(', ', $missing).'.', ['errors' => ['scores' => ['Califica todos los criterios.']]]);
        }
        $total = round($total, 2);
        $passing = (float) ($t->passing_score ?? 70);
        $rating = $total >= $passing ? 'approved' : 'rejected';
        DB::update('update vendor_evaluations set scores = ?::jsonb, total_score = ?, rating = ?, evaluator_user_id = coalesce(evaluator_user_id, ?), updated_by = ? where id = ?',
            [json_encode(array_intersect_key($d['scores'], array_flip(array_column($criteria, 'name'))), JSON_UNESCAPED_UNICODE), $total, $rating, (string) auth()->id(), (string) auth()->id(), $id]);

        return response()->json(['data' => ['total_score' => $total, 'rating' => $rating, 'passing_score' => $passing]]);
    }

    private function find(string $table, string $id, string $msg): object
    {
        $r = Str::isUuid($id) ? DB::selectOne("select * from {$table} where id = ? and deleted_at is null", [$id]) : null;

        return $r ?? throw ApiProblem::notFound($msg);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer este cambio.');
        }
    }
}
