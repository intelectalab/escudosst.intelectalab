<?php

namespace App\Modules\LBA;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Línea base (M-LBA):
 *  GET /lba/compare?a=&b=           SCR-LBA-05: dos diagnósticos lado a lado con la diferencia por lineamiento
 *  GET /lba/assessments/{id}.xlsx   lista de verificación del Anexo 3 con respuestas, notas y puntaje, en Excel
 */
class BaselineToolsController extends Controller
{
    public function compare(Request $request): JsonResponse
    {
        $this->can();
        $d = $request->validate(['a' => ['required', 'uuid'], 'b' => ['required', 'uuid', 'different:a']]);
        $info = fn (string $id) => DB::selectOne('select id, title, assessment_on, score_pct, status from baseline_assessments where id = ? and deleted_at is null', [$id])
            ?? throw ApiProblem::notFound('El diagnóstico no existe o es de otra empresa.');
        $a = $info($d['a']);
        $b = $info($d['b']);
        $by = fn (string $id) => collect(DB::select(
            "select lineament_code, max(lineament_name) as lineament_name, round(100.0 * sum(coalesce(score, 0)) / nullif(count(*) filter (where result <> 'not_applicable'), 0), 1) as pct
               from baseline_items where assessment_id = ? and deleted_at is null group by lineament_code order by lineament_code", [$id]))->keyBy('lineament_code');
        $la = $by($a->id);
        $lb = $by($b->id);
        $rows = [];
        foreach ($la->keys()->merge($lb->keys())->unique()->sort()->values() as $code) {
            $x = $la[$code]->pct ?? null;
            $y = $lb[$code]->pct ?? null;
            $rows[] = ['code' => $code, 'name' => $la[$code]->lineament_name ?? $lb[$code]->lineament_name ?? $code, 'a' => $x === null ? null : (float) $x, 'b' => $y === null ? null : (float) $y,
                'diff' => $x === null || $y === null ? null : round((float) $y - (float) $x, 1)];
        }

        return response()->json(['data' => ['a' => $a, 'b' => $b, 'lineaments' => $rows,
            'diff' => $a->score_pct === null || $b->score_pct === null ? null : round((float) $b->score_pct - (float) $a->score_pct, 1)]]);
    }

    public function xlsx(string $id): Response
    {
        $this->can();
        $a = Str::isUuid($id) ? DB::selectOne('select * from baseline_assessments where id = ? and deleted_at is null', [$id]) : null;
        if (! $a) {
            throw ApiProblem::notFound('El diagnóstico no existe o es de otra empresa.');
        }
        $res = ['compliant' => 'Cumple', 'partial' => 'Cumple parcialmente', 'non_compliant' => 'No cumple', 'not_applicable' => 'No aplica', 'pending' => 'Pendiente'];
        $out = [['Lista de verificación de lineamientos del SG-SST (R.M. 050-2013-TR, Anexo 3)'], [$a->title.' · '.$a->assessment_on.' · cumplimiento '.($a->score_pct ?? '—').' %'], [],
            ['Lineamiento', 'N.°', 'Indicador', 'Resultado', 'Puntaje', 'Observaciones y evidencia']];
        foreach (DB::select('select * from baseline_items where assessment_id = ? and deleted_at is null order by lineament_code, sort_order, item_code', [$id]) as $i) {
            $out[] = [trim(($i->lineament_code ?? '').' '.($i->lineament_name ?? '')), (string) ($i->item_code ?? ''), (string) ($i->question ?? ''),
                $res[$i->result] ?? $i->result, $i->score === null ? '' : (float) $i->score, (string) ($i->evidence_notes ?? '')];
        }

        return response(Xlsx::build('Anexo 3', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="linea-base-anexo3-'.$a->assessment_on.'.xlsx"',
        ]);
    }

    private function can(): void
    {
        if (! in_array('lba.baseline_assessments.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve la línea base.');
        }
    }
}
