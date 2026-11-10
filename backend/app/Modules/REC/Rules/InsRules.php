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
        if ($current && in_array($current->status, ['completed', 'closed'], true) && array_diff(array_keys($values), ['summary']) !== []) {
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
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] === 'inspections' && ($values['status'] ?? null) === 'completed') {
            DocsFieldRules::programExecutionFor('inspections', $id, 'INS');
        }
    }
}
