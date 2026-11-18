<?php

namespace App\Modules\LBA;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * POST /lba/assessments/{id}/load — copia al diagnóstico las preguntas base de EscudoSST (las del autodiagnóstico
 * del sitio, por sección). Es una lista inicial: la empresa la completa con los ítems de su lista de verificación
 * (R.M. 050-2013-TR, Anexo 3). No duplica ítems ya cargados. Si hay un diagnóstico anterior aprobado, sus respuestas quedan
 * como sugerencia en cada ítem pendiente y se copian los ítems que la empresa le había agregado.
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

        // §7: el diagnóstico anterior aprobado se vincula y sus respuestas quedan como sugerencia (el resultado sigue pendiente
        // hasta evaluarlo de nuevo); los ítems que la empresa había agregado se copian.
        $suggested = 0;
        $prev = DB::selectOne("select id, assessment_on from baseline_assessments where id = coalesce((select previous_assessment_id from baseline_assessments where id = ?),
                                 (select id from baseline_assessments where deleted_at is null and status = 'approved' and id <> ? order by assessment_on desc nulls last, approved_at desc limit 1))",
            [$assessment, $assessment]);
        if ($prev) {
            DB::update('update baseline_assessments set previous_assessment_id = coalesce(previous_assessment_id, ?) where id = ?', [$prev->id, $assessment]);
            $label = ['compliant' => 'Cumple', 'partial' => 'Parcial', 'non_compliant' => 'No cumple', 'not_applicable' => 'No aplica'];
            $on = $prev->assessment_on ? date('d/m/Y', strtotime($prev->assessment_on)) : 'anterior';
            foreach (DB::select('select * from baseline_items where assessment_id = ? and deleted_at is null order by sort_order', [$prev->id]) as $old) {
                $cur = DB::selectOne('select id, result, evidence_notes from baseline_items where assessment_id = ? and item_code = ? and deleted_at is null', [$assessment, $old->item_code]);
                if (! $cur) {
                    DB::insert("insert into baseline_items (company_id, assessment_id, lineament_code, lineament_name, item_code, question, legal_basis, result, sort_order, created_by, updated_by)
                                values (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)",
                        [$a->company_id, $assessment, $old->lineament_code, $old->lineament_name, $old->item_code, $old->question, $old->legal_basis, (int) $old->sort_order,
                            (string) auth()->id(), (string) auth()->id()]);
                    $added++;
                    $cur = DB::selectOne('select id, result, evidence_notes from baseline_items where assessment_id = ? and item_code = ? and deleted_at is null', [$assessment, $old->item_code]);
                }
                if ($cur->result === 'pending' && trim((string) $cur->evidence_notes) === '' && isset($label[$old->result])) {
                    $note = "Respuesta del diagnóstico del {$on}: {$label[$old->result]}.".($old->evidence_notes ? ' '.$old->evidence_notes : '');
                    DB::update('update baseline_items set evidence_notes = ?, updated_by = ? where id = ?', [mb_substr($note, 0, 2000), (string) auth()->id(), $cur->id]);
                    $suggested++;
                }
            }
        }

        return response()->json(['data' => ['added' => $added, 'suggested' => $suggested, 'previous_assessment_id' => $prev?->id]]);
    }
}
