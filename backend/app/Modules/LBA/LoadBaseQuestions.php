<?php

namespace App\Modules\LBA;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * POST /lba/assessments/{id}/load — copia al diagnóstico las preguntas base de EscudoSST (las del autodiagnóstico
 * del sitio, por sección). Es una lista inicial: la empresa la completa con los ítems de su lista de verificación
 * (R.M. 050-2013-TR, Anexo 3). No duplica ítems ya cargados.
 */
class LoadBaseQuestions extends Controller
{
    public function __invoke(string $assessment): JsonResponse
    {
        $a = DB::selectOne('select id, company_id, status from baseline_assessments where id = ? and deleted_at is null', [$assessment]);
        if (! $a) {
            throw ApiProblem::notFound('El diagnóstico no existe o es de otra empresa.');
        }
        if (in_array($a->status, ['approved', 'archived'], true)) {
            throw new ApiProblem(409, 'conflict', 'Diagnóstico aprobado', 'El diagnóstico está aprobado: no se le agregan ítems.');
        }
        $questions = DB::connection('platform')->select(
            "select q.section, q.question_text, q.legal_reference, q.sort_order from assessment_questions q
               join assessment_questionnaires n on n.id = q.questionnaire_id
              where q.deleted_at is null and n.deleted_at is null
                and n.id = (select id from assessment_questionnaires where deleted_at is null order by (status = 'published') desc, version desc limit 1)
              order by q.sort_order"
        );
        $added = 0;
        $sections = array_values(array_unique(array_column($questions, 'section')));
        foreach ($questions as $k => $q) {
            $lineament = sprintf('L%02d', array_search($q->section, $sections, true) + 1);
            $code = sprintf('BASE-%02d', $k + 1);
            if (DB::selectOne('select 1 as ok from baseline_items where assessment_id = ? and item_code = ? and deleted_at is null', [$assessment, $code])) {
                continue;
            }
            DB::insert(
                "insert into baseline_items (company_id, assessment_id, lineament_code, lineament_name, item_code, question, legal_basis, result, sort_order, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)",
                [$a->company_id, $assessment, $lineament, $q->section, $code, $q->question_text, $q->legal_reference, (int) $q->sort_order, (string) auth()->id(), (string) auth()->id()]
            );
            $added++;
        }

        return response()->json(['data' => ['added' => $added]]);
    }
}
