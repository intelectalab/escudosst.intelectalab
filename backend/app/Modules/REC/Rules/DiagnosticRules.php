<?php

namespace App\Modules\REC\Rules;

use App\Core\Support\LegalParams;
use Illuminate\Support\Facades\DB;

/**
 * LBA · Diagnóstico de línea base y MRL · Matriz de requisitos legales (docs/modulos/M-LBA.md y M-MRL.md §8).
 * LBA:
 * - RN-LBA-01/02: puntaje por ítem (cumple 1, parcial 0,5, no cumple 0); «no aplica» fuera del denominador; % global.
 * - RN-LBA-03: «no aplica» exige comentario.
 * - RN-LBA-05: aprobado es inmutable.
 * - RN-LBA-06: al aprobar, cada «parcial» o «no cumple» genera una brecha (una sola vez).
 * - RN-LBA-07: la brecha se cierra cuando su acción correctiva se verifica (ver AcrRules).
 * MRL:
 * - RN-MRL-02: «no aplicable» exige el motivo.
 * - RN-MRL-03: la próxima evaluación = fecha de evaluación + legal_evaluation_months.
 * - RN-MRL-07: las evaluaciones no se editan; una corrección es otra evaluación.
 */
class DiagnosticRules extends RecordRules
{
    private const SCORE = ['compliant' => 1.0, 'partial' => 0.5, 'non_compliant' => 0.0];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'baseline_assessments' => $this->assessment($op, $values, $current, $extra),
            'baseline_items' => $this->item($values, $current, $extra),
            'legal_requirements' => $this->requirement($values, $current),
            'requirement_evaluations' => $this->evaluation($op, $values),
            'monthly_statistics' => $this->conflict('Las estadísticas del mes se calculan con «Calcular mes»; un mes cerrado no se modifica.'),
            default => null,
        };
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] === 'baseline_items') {
            $this->recalc(DB::selectOne('select assessment_id from baseline_items where id = ?', [$id])->assessment_id);
        }
        if ($res['table'] === 'baseline_assessments' && ($values['status'] ?? null) === 'approved') {
            $this->gaps($id);
        }
        if ($res['table'] === 'requirement_evaluations') {
            $e = DB::selectOne('select * from requirement_evaluations where id = ?', [$id]);
            DB::update('update legal_requirements set next_evaluation_on = ? where id = ?', [$e->next_evaluation_on, $e->legal_requirement_id]);
            // RN-MRL-04: «parcial» o «no cumple» sin acción vinculada crea su acción correctiva (responsable: quien evaluó).
            if (in_array($e->result, ['partial', 'non_compliant'], true) && ! $e->corrective_action_id) {
                $this->actionForEvaluation($e);
            }
        }
    }

    public function prepareDelete(array $res, object $current): void
    {
        if ($res['table'] === 'baseline_items') {
            $this->editable($current->assessment_id);
        }
        if ($res['table'] === 'requirement_evaluations') {
            $this->conflict('Las evaluaciones son históricas: no se borran. Registra una nueva evaluación.');
        }
    }

    private function assessment(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            $values['status'] ??= 'draft';
            $values['assessment_on'] ??= date('Y-m-d');

            return;
        }
        if (in_array($current->status, ['approved', 'archived'], true) && array_diff(array_keys($values), ['status']) !== []) {
            $this->conflict('El diagnóstico está aprobado: no se modifica. Para actualizarlo, crea un nuevo diagnóstico.');
        }
        $to = $values['status'] ?? null;
        $flow = ['draft' => ['in_progress', 'completed', 'approved'], 'in_progress' => ['completed', 'approved', 'draft'], 'completed' => ['approved', 'in_progress'], 'approved' => ['archived'], 'archived' => []];
        if ($to && $to !== $current->status && ! in_array($to, $flow[$current->status] ?? [], true)) {
            $this->fail('status', $current->status === 'approved' ? 'El diagnóstico aprobado no se reabre: crea un nuevo diagnóstico para actualizarlo.' : 'Ese cambio de estado no está permitido.');
        }
        if (in_array($to, ['completed', 'approved'], true)) {
            $pending = (int) DB::selectOne("select count(*) as n from baseline_items where assessment_id = ? and deleted_at is null and result = 'pending'", [$current->id])->n;
            $total = (int) DB::selectOne('select count(*) as n from baseline_items where assessment_id = ? and deleted_at is null', [$current->id])->n;
            if ($total === 0) {
                $this->fail('status', 'El diagnóstico no tiene ítems. Usa «Cargar preguntas base» o agrega los ítems de la lista de verificación.');
            }
            if ($pending > 0) {
                $this->fail('status', "Faltan {$pending} ítem(s) por evaluar.");
            }
        }
        if ($to === 'approved') {
            $extra['approved_by'] = ['?', [(string) auth()->id()]];
            $extra['approved_at'] = ['now()', []];
        }
    }

    private function item(array &$values, ?object $current, array &$extra): void
    {
        $this->editable($this->value($values, $current, 'assessment_id'));
        $result = $this->value($values, $current, 'result') ?? 'pending';
        if ($result === 'not_applicable' && ! $this->value($values, $current, 'evidence_notes')) {
            $this->fail('evidence_notes', 'Si el ítem no aplica, explica por qué en las notas.');
        }
        // RN-LBA-04: con «require_evidence_on_compliance» activo, un «cumple» lleva evidencia (archivo o nota que remita al registro).
        if ($result === 'compliant' && LegalParams::number('require_evidence_on_compliance', 0) > 0
            && ! $this->value($values, $current, 'evidence_file_id') && ! trim((string) $this->value($values, $current, 'evidence_notes'))) {
            $this->fail('evidence_file_id', 'Para marcar «cumple» adjunta la evidencia o indica en las notas el registro que lo demuestra.');
        }
        $extra['score'] = array_key_exists($result, self::SCORE) ? ['?', [self::SCORE[$result]]] : ['null', []];
    }

    private function editable(?string $assessment): void
    {
        $st = $assessment ? DB::selectOne('select status from baseline_assessments where id = ?', [$assessment])?->status : null;
        if (in_array($st, ['approved', 'archived'], true)) {
            $this->conflict('El diagnóstico está aprobado: sus ítems no cambian.');
        }
    }

    private function recalc(string $assessment): void
    {
        DB::update(
            "update baseline_assessments a set total_items = x.applicable, compliant_items = x.compliant,
                    score_pct = case when x.applicable > 0 then round(x.points * 100.0 / x.applicable, 2) else null end,
                    status = case when a.status = 'draft' and x.answered > 0 then 'in_progress' else a.status end
               from (select count(*) filter (where result not in ('not_applicable','pending')) as applicable,
                            count(*) filter (where result = 'compliant') as compliant,
                            count(*) filter (where result <> 'pending') as answered,
                            coalesce(sum(score) filter (where result not in ('not_applicable','pending')), 0) as points
                       from baseline_items where assessment_id = ? and deleted_at is null) x
              where a.id = ?",
            [$assessment, $assessment]
        );
    }

    private function gaps(string $assessment): void
    {
        $items = DB::select(
            "select i.* from baseline_items i where i.assessment_id = ? and i.deleted_at is null and i.result in ('partial','non_compliant')
                and not exists (select 1 from baseline_gaps g where g.baseline_item_id = i.id and g.deleted_at is null)",
            [$assessment]
        );
        foreach ($items as $i) {
            DB::insert(
                "insert into baseline_gaps (company_id, assessment_id, baseline_item_id, description, priority, status, created_by, updated_by)
                 values (?, ?, ?, ?, ?, 'open', ?, ?)",
                [$i->company_id, $assessment, $i->id, mb_substr(($i->result === 'partial' ? 'Cumple parcialmente: ' : 'No cumple: ').$i->question, 0, 2000),
                    $i->result === 'non_compliant' ? 'high' : 'medium', (string) auth()->id(), (string) auth()->id()]
            );
        }
    }

    private function requirement(array &$values, ?object $current): void
    {
        $applies = $this->value($values, $current, 'applies');
        $status = $this->value($values, $current, 'status');
        if (($applies === false || $status === 'not_applicable') && ! $this->value($values, $current, 'applicability')) {
            $this->fail('applicability', 'Si el requisito no aplica, explica el motivo.');
        }
        if ($status === 'not_applicable') {
            $values['applies'] = false;
        }
    }

    private function actionForEvaluation(object $e): void
    {
        $req = DB::selectOne("select r.article, r.requirement, r.responsible_user_id, concat_ws(' ', n.norm_type, n.number) as norm
                                from legal_requirements r left join legal_norms n on n.id = r.legal_norm_id where r.id = ?", [$e->legal_requirement_id]);
        $action = \App\Modules\ACR\AutoAction::create($e->company_id, 'legal_requirement', $e->legal_requirement_id,
            'Cumplir el requisito legal: '.trim(implode(' ', array_filter([$req->norm ?? null, $req->article ?? null]))).' — '.$req->requirement,
            $e->evidence_notes, $req->responsible_user_id ?? $e->evaluator_user_id, null, $e->result === 'non_compliant' ? 'high' : 'medium');
        DB::update('update requirement_evaluations set corrective_action_id = ? where id = ?', [$action, $e->id]);
    }

    private function evaluation(string $op, array &$values): void
    {
        if ($op === 'update') {
            $this->conflict('Las evaluaciones no se editan: registra una nueva evaluación.');
        }
        $values['evaluated_on'] ??= date('Y-m-d');
        $values['evaluator_user_id'] ??= (string) auth()->id();
        if (! isset($values['next_evaluation_on'])) {
            $months = (int) LegalParams::number('legal_evaluation_months', 12);
            $values['next_evaluation_on'] = date('Y-m-d', strtotime($values['evaluated_on']." +{$months} months"));
        }
    }
}
