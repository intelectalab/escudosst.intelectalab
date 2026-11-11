<?php

namespace App\Modules\AUL;

use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reglas automáticas de recomendación de cursos (M-AUL «Recomendación de cursos», RN-AUL-24..30), job diario:
 *  - certificate_expiring: certificado del aula que vence dentro de `parameters.lead_days` (30 por defecto) o venció
 *    hace poco → recapacitación del mismo curso (excepción de RN-AUL-25).
 *  - matrix_gap: el puesto del trabajador exige un curso (position_training_requirements.course_id) que no tiene
 *    aprobado ni en curso.
 *  - complementary / learning_path_next: aprobó un curso de `parameters.after_course_ids` → los cursos de la regla.
 * Nunca se recomienda un curso ya matriculado o aprobado con certificado vigente (salvo recapacitación); `dedupe_key`
 * evita repetir la misma recomendación mientras esté activa o descartada dentro de
 * `recommendation_dismiss_cooldown_days` (RN-AUL-26). Los envíos por correo o WhatsApp respetan el consentimiento de
 * publicidad del participante externo (RN-AUL-29) y el límite `max_sends_per_person` por `send_period_days`
 * (RN-AUL-28); fuera de eso la recomendación solo se publica en el portal. Con `parameters.requires_approval` nace
 * «sugerida» y espera la bandeja; si no, se publica («enviada») y avisa en la campana. Ningún filtro usa datos médicos
 * (RN-AUL-27). Tipos sin datos de origen todavía (hallazgos, accidentes, perfil de compra) se omiten.
 */
final class RecommendationEngine
{
    public const SUPPORTED = ['certificate_expiring', 'matrix_gap', 'complementary', 'learning_path_next'];

    private const ACTIVE = ['suggested', 'sent', 'viewed', 'clicked'];

    public function __construct(private AccountsService $accounts) {}

    /** Todas las empresas activas con el aula habilitada. @return int recomendaciones creadas */
    public function run(): int
    {
        $companies = DB::connection('platform')->select(
            "select c.id from companies c join subscriptions s on s.company_id = c.id
              where c.deleted_at is null and c.status = 'active' and s.deleted_at is null and s.status in ('trialing','active','past_due','grace')"
        );
        $total = 0;
        foreach ($companies as $c) {
            try {
                $total += $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $this->forCompany($db, $c->id));
            } catch (\Throwable $e) {
                report($e);   // una empresa con error no detiene a las demás
            }
        }

        return $total;
    }

    public function forCompany(ConnectionInterface $db, string $company): int
    {
        if (! $db->selectOne("select 1 as ok from feature_flags where module_code = 'AUL' and mode = 'enabled' and deleted_at is null")) {
            return 0;
        }
        // Las que pasaron su fecha de vigencia quedan vencidas.
        $db->update("update course_recommendations set status = 'expired', updated_at = now() where deleted_at is null and expires_at < now() and status in ('suggested', 'sent', 'viewed', 'clicked')");
        $n = 0;
        foreach ($db->select('select * from recommendation_rules where deleted_at is null and is_active order by priority, created_at') as $rule) {
            $n += $this->runRule($db, $company, $rule, false)['created'];
        }

        return $n;
    }

    /** @return array{supported: bool, reached: int, created: int, skipped: int, sent_external: int, portal_only: int, preview: array} */
    public function runRule(ConnectionInterface $db, string $company, object $rule, bool $dry): array
    {
        $out = ['supported' => in_array($rule->rule_type, self::SUPPORTED, true), 'reached' => 0, 'created' => 0, 'skipped' => 0, 'sent_external' => 0, 'portal_only' => 0, 'preview' => []];
        if (! $out['supported']) {
            if (! $dry) {
                $db->update("update recommendation_rules set last_run_at = now(), last_run_status = 'partial', last_run_count = 0 where id = ?", [$rule->id]);
            }

            return $out;
        }
        $params = json_decode($rule->parameters ?? '{}', true) ?: [];
        $courses = $this->uuids($rule->course_ids);
        $cooldown = $this->param($db, $company, 'recommendation_dismiss_cooldown_days', 90);
        $channels = $this->uuids($rule->channels, false);
        $preferred = in_array('email', $channels, true) ? 'email' : (in_array('whatsapp', $channels, true) ? 'whatsapp' : 'in_app');
        $approval = ! empty($params['requires_approval']);
        $published = array_column($db->select("select id from courses where deleted_at is null and status = 'published' and company_id is not null"), 'id');

        foreach ($this->candidates($db, $rule, $params, $courses) as $c) {
            if (! in_array($c['course_id'], $published, true)) {
                continue;
            }
            $out['reached']++;
            $col = $c['worker_id'] ? 'worker_id' : 'external_learner_id';
            $person = $c['worker_id'] ?? $c['external_learner_id'];
            // RN-AUL-25: no si ya está matriculado (o aprobó con certificado vigente, salvo recapacitación).
            $busy = $db->selectOne("select 1 as ok from course_enrollments where course_id = ? and {$col} = ? and deleted_at is null and status in ('enrolled', 'in_progress'".($c['recert'] ? '' : ", 'completed'").')', [$c['course_id'], $person]);
            $valid = ! $c['recert'] && $db->selectOne("select 1 as ok from training_certificates where course_id = ? and {$col} = ? and deleted_at is null and status = 'validated' and (expires_on is null or expires_on > current_date)", [$c['course_id'], $person]);
            $key = "rule:{$rule->id}:{$col}:{$person}:course:{$c['course_id']}:{$c['reason_code']}".($c['key_extra'] ?? '');
            $dup = $db->selectOne("select 1 as ok from course_recommendations where dedupe_key = ? and deleted_at is null
                                    and (status in ('suggested', 'sent', 'viewed', 'clicked', 'converted') or (status = 'dismissed' and dismissed_at > now() - make_interval(days => ?::int)))", [$key, $cooldown]);
            if ($busy || $valid || $dup) {
                $out['skipped']++;

                continue;
            }
            $channel = $preferred;
            if ($channel !== 'in_app') {
                $consent = $c['worker_id'] ? true : (bool) $db->selectOne('select 1 as ok from external_learners where id = ? and marketing_consent and deleted_at is null', [$person]);
                $sent = (int) $db->selectOne("select count(*) as n from course_recommendations where {$col} = ? and deleted_at is null and channel in ('email', 'whatsapp')
                                               and sent_at > now() - make_interval(days => ?::int)", [$person, (int) $rule->send_period_days])->n;
                if (($rule->requires_consent && ! $consent) || $sent >= (int) $rule->max_sends_per_person) {
                    $channel = 'in_app';   // RN-AUL-28/29: solo en el portal
                }
            }
            $channel === 'in_app' ? $out['portal_only']++ : $out['sent_external']++;
            if ($dry) {
                if (count($out['preview']) < 50) {
                    $out['preview'][] = ['name' => $c['name'], 'course' => $c['course_title'], 'reason' => $c['message'], 'channel' => $channel];
                }
                $out['created']++;

                continue;
            }
            $status = $approval ? 'suggested' : 'sent';
            $rec = $db->selectOne(
                "insert into course_recommendations (company_id, course_id, worker_id, external_learner_id, rule_id, source, target_scope, reason_code, message, channel, status, sent_at, expires_at, dedupe_key)
                 values (?, ?, ?, ?, ?, 'rule', 'person', ?, ?, ?, ?, case when ? then now() else null end, now() + interval '60 days', ?) returning id",
                [$company, $c['course_id'], $c['worker_id'], $c['external_learner_id'], $rule->id, $c['reason_code'], mb_substr($c['message'], 0, 500), $channel, $status, $status === 'sent' ? 'true' : 'false', $key]
            );
            if ($status === 'sent' && $c['worker_id']) {
                $db->insert('insert into notifications (company_id, event_code, subject_type, subject_id, recipient_worker_id, title, body, link, priority, dedupe_key)
                             values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$company, 'aul.course_recommended', 'AUL', $rec->id, $c['worker_id'], 'Curso recomendado: '.$c['course_title'], mb_substr($c['message'], 0, 500), '/m/aul', 'normal', 'aul-rec:'.$rec->id]);
            }
            $out['created']++;
        }
        if (! $dry) {
            $db->update("update recommendation_rules set last_run_at = now(), last_run_status = 'ok', last_run_count = ? where id = ?", [$out['created'], $rule->id]);
        }

        return $out;
    }

    /** @return array<int, array{worker_id: ?string, external_learner_id: ?string, name: string, course_id: string, course_title: string, reason_code: string, message: string, recert: bool, key_extra?: string}> */
    private function candidates(ConnectionInterface $db, object $rule, array $params, array $courses): array
    {
        $workers = $rule->audience === 'workers';
        $externals = $rule->audience === 'external_learners';
        if (! $workers && ! $externals) {
            return [];   // empresas compradoras: requiere VTA y el representante; fuera de este job
        }
        $inList = $courses ? ' in ('.implode(',', array_fill(0, count($courses), '?')).')' : '';
        $hcol = $this->col($workers);
        $out = [];
        switch ($rule->rule_type) {
            case 'certificate_expiring':
                $lead = max(1, min(365, (int) ($params['lead_days'] ?? 30)));
                $holder = $workers ? 'tc.worker_id' : 'tc.external_learner_id';
                $rows = $db->select(
                    "select tc.worker_id, tc.external_learner_id, tc.course_id, c.title as course_title, tc.expires_on, tc.holder_name as name
                       from training_certificates tc join courses c on c.id = tc.course_id
                      where tc.deleted_at is null and tc.status = 'validated' and {$holder} is not null and tc.expires_on is not null
                        and tc.expires_on <= current_date + ?::int and tc.expires_on >= current_date - 30 ".($inList ? ' and tc.course_id'.$inList : '')."
                        and not exists (select 1 from training_certificates n where n.course_id = tc.course_id and n.{$hcol} = {$holder} and n.deleted_at is null
                                         and n.status = 'validated' and (n.expires_on is null or n.expires_on > tc.expires_on))",
                    [$lead, ...$courses]);
                foreach ($rows as $r) {
                    $when = date('d/m/Y', strtotime($r->expires_on));
                    $out[] = ['worker_id' => $workers ? $r->worker_id : null, 'external_learner_id' => $workers ? null : $r->external_learner_id, 'name' => (string) $r->name,
                        'course_id' => $r->course_id, 'course_title' => $r->course_title, 'reason_code' => 'certificate_expiring', 'recert' => true, 'key_extra' => ':'.$r->expires_on,
                        'message' => strtotime($r->expires_on) < strtotime('today') ? "Tu certificado de «{$r->course_title}» venció el {$when}: renuévalo con este curso."
                            : "Tu certificado de «{$r->course_title}» vence el {$when}: renuévalo a tiempo."];
                }
                break;
            case 'matrix_gap':
                if (! $workers) {
                    break;
                }
                $positions = $this->uuids($params['position_ids'] ?? []);
                $rows = $db->select(
                    "select w.id as worker_id, w.full_name as name, r.course_id, c.title as course_title, p.name as position
                       from position_training_requirements r join workers w on w.current_position_id = r.position_id and w.deleted_at is null and w.status = 'active'
                       join courses c on c.id = r.course_id left join positions p on p.id = r.position_id
                      where r.deleted_at is null and r.is_active and r.course_id is not null ".($inList ? ' and r.course_id'.$inList : '').""
                        .($positions ? ' and r.position_id in ('.implode(',', array_fill(0, count($positions), '?')).')' : ''),
                    [...$courses, ...$positions]);
                foreach ($rows as $r) {
                    $out[] = ['worker_id' => $r->worker_id, 'external_learner_id' => null, 'name' => (string) $r->name, 'course_id' => $r->course_id, 'course_title' => $r->course_title,
                        'reason_code' => 'matrix_gap', 'recert' => false, 'message' => 'Tu puesto'.($r->position ? " «{$r->position}»" : '')." requiere el curso «{$r->course_title}»."];
                }
                break;
            case 'complementary':
            case 'learning_path_next':
                $after = $this->uuids($params['after_course_ids'] ?? []);
                if (! $after || ! $courses) {
                    break;
                }
                $col = $this->col($workers);
                $rows = $db->select(
                    "select distinct e.{$col} as person, c.title as done_title,
                            coalesce(w.full_name, xl.first_name || ' ' || xl.last_name, 'Participante') as name
                       from course_enrollments e join courses c on c.id = e.course_id left join workers w on w.id = e.worker_id left join external_learners xl on xl.id = e.external_learner_id
                      where e.deleted_at is null and e.status = 'completed' and e.{$col} is not null and e.course_id in (".implode(',', array_fill(0, count($after), '?')).')', $after);
                $titles = collect($db->select('select id, title from courses where id in ('.implode(',', array_fill(0, count($courses), '?')).')', $courses))->pluck('title', 'id');
                foreach ($rows as $r) {
                    foreach ($courses as $cid) {
                        if (in_array($cid, $after, true) || ! isset($titles[$cid])) {
                            continue;
                        }
                        $out[] = ['worker_id' => $workers ? $r->person : null, 'external_learner_id' => $workers ? null : $r->person, 'name' => (string) $r->name, 'course_id' => $cid,
                            'course_title' => $titles[$cid], 'reason_code' => $rule->rule_type, 'recert' => false,
                            'message' => $rule->rule_type === 'learning_path_next' ? "Siguiente curso de tu ruta después de «{$r->done_title}»." : "Complementa el curso «{$r->done_title}» que aprobaste."];
                    }
                }
                break;
        }

        return $out;
    }

    private function col(bool $workers): string
    {
        return $workers ? 'worker_id' : 'external_learner_id';
    }

    /** Arreglo de Postgres ({a,b}) o de PHP → lista (solo UUID si $uuid). */
    private function uuids(mixed $v, bool $uuid = true): array
    {
        $list = is_array($v) ? $v : (is_string($v) ? array_filter(explode(',', trim($v, '{}'))) : []);
        $list = array_values(array_unique(array_map(fn ($x) => trim((string) $x, '" '), $list)));

        return $uuid ? array_values(array_filter($list, fn ($x) => preg_match('/^[0-9a-f-]{36}$/i', $x))) : $list;
    }

    private function param(ConnectionInterface $db, string $company, string $key, int $default): int
    {
        $v = $db->selectOne(
            'select value from legal_parameters where key = ? and (company_id is null or company_id = ?) and valid_from <= current_date
                and (valid_to is null or valid_to >= current_date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1',
            [$key, $company]
        );

        return is_numeric($v->value ?? null) ? max(1, (int) $v->value) : $default;
    }
}
