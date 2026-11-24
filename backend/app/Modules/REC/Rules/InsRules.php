<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\DB;

/**
 * INS · Inspecciones y hallazgos (docs/modulos/M-INS.md §8).
 * - Una inspección con checklist se finaliza desde «Ejecutar checklist» (calcula puntaje y hallazgos).
 * - RN-INS-06: finalizada no se edita (salvo el resumen).
 * - Código automático INS-AAAA-NNNN si no se indica.
 * - Un hallazgo descartado necesita motivo.
 */
class InsRules extends RecordRules
{
    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        // RN-INS-04: una plantilla ya usada en inspecciones no cambia sus ítems; se crea una nueva versión.
        if ($res['table'] === 'inspection_template_items') {
            $tpl = $this->value($values, $current, 'template_id');
            if ($tpl && DB::selectOne('select 1 as ok from inspections where template_id = ? and deleted_at is null limit 1', [$tpl])) {
                $this->conflict('Esta plantilla ya se usó en inspecciones: para cambiar sus preguntas crea una nueva versión (las inspecciones pasadas conservan la suya).');
            }

            return;
        }
        if ($res['table'] === 'inspection_templates') {
            // Al activar una versión nueva, la anterior se retira.
            if ($current && ($values['status'] ?? null) === 'active' && $current->status !== 'active' && $current->previous_template_id) {
                DB::update("update inspection_templates set status = 'retired', updated_at = now() where id = ? and status = 'active'", [$current->previous_template_id]);
            }

            return;
        }
        if ($res['table'] === 'findings') {
            if (($this->value($values, $current, 'status')) === 'dismissed' && ! $this->value($values, $current, 'dismissed_reason')) {
                $this->fail('dismissed_reason', 'Para descartar el hallazgo indica el motivo.');
            }
            if ($op === 'create' && ! isset($values['source_type'])) {
                $values['source_type'] = $this->value($values, $current, 'inspection_id') ? 'inspection' : 'other';
            }

            return;
        }
        if ($op === 'create' && ! isset($values['code'])) {
            $year = date('Y');
            $n = (int) DB::selectOne('select count(*) + 1 as n from inspections where extract(year from created_at) = extract(year from now())')->n;
            do {
                $values['code'] = sprintf('INS-%s-%04d', $year, $n++);
            } while (DB::selectOne('select 1 as ok from inspections where code = ? and deleted_at is null', [$values['code']]));
        }
        if ($current && in_array($current->status, ['completed', 'signed', 'closed'], true) && (array_diff(array_keys($values), ['summary', 'status']) !== []
            || (isset($values['status']) && $values['status'] !== $current->status && ! in_array($values['status'], ['signed', 'closed'], true)))) {
            $this->conflict('La inspección ya fue finalizada: no se edita. Sus hallazgos siguen en Acciones correctivas.');
        }
        $to = $values['status'] ?? null;
        $template = $this->value($values, $current, 'template_id');
        if (in_array($to, ['completed', 'closed'], true) && $template
            && DB::selectOne('select 1 as ok from inspection_template_items where template_id = ? and deleted_at is null', [$template])) {
            $this->fail('status', 'Esta inspección tiene checklist: finalízala con «Ejecutar checklist» para calcular el puntaje y los hallazgos.');
        }
        if ($to === 'completed' && ! $template) {
            $extra['completed_at'] = ['now()', []];
        }
        // M-INS §9: «firmada» cuando la inspección finalizada tiene sus firmas (lo marca la firma; a mano solo si ya hay firmas).
        if ($to === 'signed' && $current?->status !== 'signed') {
            if ($current?->status !== 'completed' || ! DB::selectOne("select 1 as ok from signatures where subject_type = 'inspections' and subject_id = ? and ".\App\Modules\FIR\SignatureService::valid('signatures')."", [$current->id])) {
                $this->fail('status', 'La inspección pasa a «firmada» cuando está finalizada y tiene sus firmas.');
            }
            $extra['signed_at'] = ['now()', []];
        }
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] === 'inspections' && ($values['status'] ?? null) === 'completed') {
            DocsFieldRules::programExecutionFor('inspections', $id, 'INS');
        }
    }
}
