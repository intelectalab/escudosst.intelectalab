<?php

namespace App\Modules\INS\Services;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ejecución de inspecciones (docs/modulos/M-INS.md §7 y §8):
 * checklist de la plantilla → respuestas → puntaje (RN-INS-01) → hallazgos por cada «no cumple» (RN-INS-02)
 * → acción correctiva desde cada hallazgo (ACR). La inspección finalizada no se edita (RN-INS-06).
 */
final class InspectionService
{
    /** Respuestas posibles por tipo de ítem y si cumplen (null = no aplica / no se evalúa). */
    private const ANSWERS = [
        'yes_no_na' => ['yes' => true, 'no' => false, 'na' => null],
        'compliant_noncompliant' => ['compliant' => true, 'noncompliant' => false, 'na' => null],
    ];

    public function checklist(string $id): array
    {
        $i = $this->inspection($id);
        $items = $i->template_id ? DB::select(
            'select t.id, t.section, t.item_code, t.question, t.answer_type, t.weight, t.is_critical, t.requires_photo_on_fail, t.legal_reference,
                    a.answer, a.numeric_value, a.comment, a.is_compliant
               from inspection_template_items t
               left join inspection_answers a on a.template_item_id = t.id and a.inspection_id = ? and a.deleted_at is null
              where t.template_id = ? and t.deleted_at is null order by t.sort_order nulls last, t.item_code',
            [$id, $i->template_id]
        ) : [];
        $findings = DB::select(
            'select f.id, f.description, f.severity, f.status, f.corrective_action_id, c.code as action_code, c.status as action_status
               from findings f left join corrective_actions c on c.id = f.corrective_action_id
              where f.inspection_id = ? and f.deleted_at is null order by f.created_at', [$id]);

        return [
            'inspection' => $i,
            'items' => array_map(fn ($r) => [...(array) $r, 'weight' => (float) ($r->weight ?? 1), 'is_critical' => (bool) $r->is_critical,
                'requires_photo_on_fail' => (bool) $r->requires_photo_on_fail], $items),
            'findings' => $findings,
            'editable' => in_array($i->status, ['scheduled', 'in_progress', 'overdue'], true),
        ];
    }

    /** Guarda respuestas (parciales): la inspección pasa a «en curso». */
    public function answer(string $id, array $answers): array
    {
        $i = $this->inspection($id);
        $this->editable($i);
        $items = collect(DB::select('select id, question, answer_type, is_critical from inspection_template_items where template_id = ? and deleted_at is null', [$i->template_id]))->keyBy('id');
        foreach ($answers as $a) {
            $item = $items[$a['item_id'] ?? ''] ?? null;
            if (! $item) {
                throw $this->invalid('answers', 'Hay una respuesta de un ítem que no es de esta plantilla.');
            }
            [$answer, $numeric, $compliant] = $this->evaluate($item, $a['answer'] ?? null);
            $comment = isset($a['comment']) ? mb_substr(trim((string) $a['comment']), 0, 2000) : null;
            $existing = DB::selectOne('select id from inspection_answers where inspection_id = ? and template_item_id = ? and deleted_at is null', [$id, $item->id]);
            if ($existing) {
                DB::update('update inspection_answers set answer = ?, numeric_value = ?, is_compliant = ?, comment = ?, updated_by = ? where id = ?',
                    [$answer, $numeric, $this->bool($compliant), $comment, (string) auth()->id(), $existing->id]);
            } else {
                DB::insert(
                    'insert into inspection_answers (company_id, inspection_id, template_item_id, question_snapshot, answer, numeric_value, is_compliant, is_critical, comment, created_by, updated_by)
                     values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$i->company_id, $id, $item->id, $item->question, $answer, $numeric, $this->bool($compliant), $item->is_critical ? 'true' : 'false', $comment,
                        (string) auth()->id(), (string) auth()->id()]
                );
            }
        }
        if ($i->status !== 'in_progress') {
            DB::update("update inspections set status = 'in_progress', started_at = coalesce(started_at, now()) where id = ?", [$id]);
        }

        return $this->checklist($id);
    }

    /** Finaliza: todos los ítems respondidos, puntaje ponderado y un hallazgo por cada «no cumple». */
    public function finish(string $id, ?string $summary): array
    {
        $i = $this->inspection($id);
        $this->editable($i);
        $data = $this->checklist($id);
        if ($data['items'] === []) {
            throw new ApiProblem(409, 'conflict', 'Sin checklist', 'La inspección no tiene plantilla con ítems: márcala como completada desde su ficha.');
        }
        $missing = array_filter($data['items'], fn ($it) => $it['answer'] === null);
        if ($missing !== []) {
            throw new ApiProblem(422, 'validation_failed', 'Faltan respuestas', 'Responde todos los ítems antes de finalizar ('.count($missing).' pendiente(s)).',
                ['missing' => array_values(array_column($missing, 'id'))]);
        }

        $total = 0.0;
        $ok = 0.0;
        $critical = false;
        $created = 0;
        foreach ($data['items'] as $it) {
            if ($it['is_compliant'] === null) {
                continue;
            }
            $total += $it['weight'];
            if ($it['is_compliant']) {
                $ok += $it['weight'];

                continue;
            }
            $critical = $critical || $it['is_critical'];
            $answerId = DB::selectOne('select id from inspection_answers where inspection_id = ? and template_item_id = ? and deleted_at is null', [$id, $it['id']])->id;
            if (! DB::selectOne('select 1 as ok from findings where inspection_answer_id = ? and deleted_at is null', [$answerId])) {
                DB::insert(
                    "insert into findings (company_id, source_type, inspection_id, inspection_answer_id, finding_type, description, severity, site_id, area_id, status, created_by, updated_by)
                     values (?, 'inspection', ?, ?, 'unsafe_condition', ?, ?, ?, ?, 'open', ?, ?)",
                    [$i->company_id, $id, $answerId, mb_substr('No cumple: '.$it['question'].($it['comment'] ? " — {$it['comment']}" : ''), 0, 2000),
                        $it['is_critical'] ? 'critical' : 'medium', $i->site_id, $i->area_id, (string) auth()->id(), (string) auth()->id()]
                );
                $created++;
            }
        }
        $score = $total > 0 ? round($ok * 100 / $total, 2) : null;
        DB::update("update inspections set status = 'completed', score_pct = ?, completed_at = now(), started_at = coalesce(started_at, now()),
                    summary = coalesce(?, summary), updated_by = ? where id = ?", [$score, $summary ? mb_substr($summary, 0, 4000) : null, (string) auth()->id(), $id]);
        $this->audit('finish', $id, ['score_pct' => $score, 'findings' => $created, 'critical' => $critical]);

        return ['score_pct' => $score, 'findings_created' => $created, 'critical' => $critical];
    }

    /** Acción correctiva desde un hallazgo: el hallazgo queda «con acción» y la acción lo tiene como origen. */
    public function actionFromFinding(string $findingId, array $input): array
    {
        if (! Str::isUuid($findingId)) {
            throw ApiProblem::notFound('El hallazgo no existe.');
        }
        $f = DB::selectOne('select * from findings where id = ? and deleted_at is null', [$findingId]);
        if (! $f) {
            throw ApiProblem::notFound('El hallazgo no existe o es de otra empresa.');
        }
        if ($f->corrective_action_id) {
            throw new ApiProblem(409, 'conflict', 'Ya tiene acción', 'Este hallazgo ya tiene su acción correctiva.');
        }
        $title = trim((string) ($input['title'] ?? ''));
        $user = (string) ($input['responsible_user_id'] ?? '');
        $due = (string) ($input['due_on'] ?? '');
        $errors = [];
        if ($title === '') {
            $errors['title'][] = 'Describe la acción a realizar.';
        }
        if (! Str::isUuid($user) || ! DB::selectOne('select 1 as ok from memberships where user_id = ? and company_id = ? and deleted_at is null', [$user, app(CompanyContext::class)->companyId])) {
            $errors['responsible_user_id'][] = 'Elige el responsable.';
        }
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || $due < $today) {
            $errors['due_on'][] = 'La fecha meta debe ser hoy o posterior.';
        }
        if ($errors) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Hay campos por corregir.', ['errors' => $errors]);
        }
        $n = (int) DB::selectOne('select count(*) + 1 as n from corrective_actions where extract(year from created_at) = extract(year from now())')->n;
        $code = sprintf('AC-%s-%04d', substr($today, 0, 4), $n);
        while (DB::selectOne('select 1 as ok from corrective_actions where code = ? and deleted_at is null', [$code])) {
            $code = sprintf('AC-%s-%04d', substr($today, 0, 4), ++$n);
        }
        $id = DB::selectOne(
            "insert into corrective_actions (company_id, code, source_type, source_id, action_type, title, description, site_id, area_id, responsible_user_id,
                due_on, priority, status, created_by, updated_by)
             values (?, ?, 'finding', ?, 'corrective', ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?) returning id",
            [$f->company_id, $code, $findingId, mb_substr($title, 0, 500), $f->description, $f->site_id, $f->area_id, $user, $due,
                in_array($f->severity, ['high', 'critical'], true) ? 'high' : 'medium', (string) auth()->id(), (string) auth()->id()]
        )->id;
        DB::update("update findings set corrective_action_id = ?, status = 'action_assigned' where id = ?", [$id, $findingId]);

        return ['id' => $id, 'code' => $code];
    }

    private function evaluate(object $item, mixed $answer): array
    {
        $a = is_scalar($answer) ? trim((string) $answer) : '';
        if ($a === '') {
            throw $this->invalid('answers', "Falta la respuesta de «{$item->question}».");
        }
        if (isset(self::ANSWERS[$item->answer_type])) {
            if (! array_key_exists($a, self::ANSWERS[$item->answer_type])) {
                throw $this->invalid('answers', "Respuesta no válida en «{$item->question}».");
            }

            return [$a, null, self::ANSWERS[$item->answer_type][$a]];
        }
        if ($item->answer_type === 'scale_1_5') {
            if (! in_array($a, ['1', '2', '3', '4', '5'], true)) {
                throw $this->invalid('answers', "Elige de 1 a 5 en «{$item->question}».");
            }

            return [$a, (int) $a, (int) $a >= 3];
        }
        if ($item->answer_type === 'numeric') {
            if (! is_numeric($a)) {
                throw $this->invalid('answers', "«{$item->question}» pide un número.");
            }

            return [$a, (float) $a, null];
        }

        return [mb_substr($a, 0, 2000), null, null];
    }

    private function inspection(string $id): object
    {
        if (! Str::isUuid($id)) {
            throw ApiProblem::notFound('La inspección no existe.');
        }
        $i = DB::selectOne('select i.*, t.name as template_name, s.name as site_name, a.name as area_name from inspections i
                              left join inspection_templates t on t.id = i.template_id left join sites s on s.id = i.site_id
                              left join areas a on a.id = i.area_id where i.id = ? and i.deleted_at is null', [$id]);
        if (! $i) {
            throw ApiProblem::notFound('La inspección no existe o es de otra empresa.');
        }

        return $i;
    }

    private function editable(object $i): void
    {
        if (! in_array($i->status, ['scheduled', 'in_progress', 'overdue'], true)) {
            throw new ApiProblem(409, 'conflict', 'Inspección finalizada', 'La inspección ya fue finalizada: no se cambian sus respuestas.');
        }
    }

    private function bool(?bool $v): ?string
    {
        return $v === null ? null : ($v ? 'true' : 'false');
    }

    private function invalid(string $field, string $msg): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }

    private function audit(string $action, string $id, array $changes): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes)
             values (?, ?, ?, 'user', ?, 'INS', 'inspections', ?, ?::jsonb)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $action, $id, json_encode($changes, JSON_UNESCAPED_UNICODE)]
        );
    }
}
