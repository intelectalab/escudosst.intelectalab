<?php

namespace App\Modules\DSH\Services;

use App\Core\Support\LegalParams;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores SST (docs/modulos/M-DSH.md). Cada KPI es un dato (kpi_definitions) con su formula_key;
 * aquí está el cálculo de cada fórmula sobre los registros reales de los módulos.
 *
 * - RN-DSH-02: si el módulo del KPI no está en el plan, se devuelve bloqueado (sin valor).
 * - RN-DSH-03: todo valor trae numerador y denominador, y el módulo donde están sus registros.
 * - RN-DSH-05: los KPI de salud no se muestran con grupos menores a min_group_size.
 * - RN-DSH-08: denominador cero → «No aplica» (sin color), nunca 0 % ni 100 %.
 */
final class KpiService
{
    private const PLAN_NAMES = ['E' => 'Esencial', 'P' => 'Profesional', 'C' => 'Corporativo', 'K' => 'Consultora', '+' => 'complemento'];

    /** Umbrales por defecto cuando el KPI no trae los suyos: % a favor (verde ≥ 90, ámbar ≥ 70). */
    private const DEFAULT_PCT = [90, 70];

    private string $from;
    private string $to;
    private string $today;
    private ?string $site;

    public function overview(int $year, ?string $site): array
    {
        $this->today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $this->from = "{$year}-01-01";
        $this->to = min("{$year}-12-31", $this->today);
        $this->site = $site;

        $defs = DB::select(
            "select k.code, k.name, k.description, k.module_code, k.formula_key, k.unit, k.direction, k.green_threshold, k.amber_threshold,
                    coalesce(k.required_feature, k.module_code) as feature, f.mode, m.plan_code, m.name as module_name
               from kpi_definitions k
               left join modules m on m.code = coalesce(k.required_feature, k.module_code)
               left join feature_flags f on f.module_code = m.code and f.deleted_at is null
              where k.deleted_at is null and k.is_active
                and (k.company_id is not null or not exists (select 1 from kpi_definitions o where o.company_id is not null and o.code = k.code and o.deleted_at is null))
              order by array_position(array['passt_progress','training_compliance','inspection_compliance','corrective_actions_overdue','corrective_actions_closed',
                'legal_compliance','baseline_score','ppe_compliance','emo_valid','events_notified_on_time','frequency_index','severity_index','accident_rate',
                'substandard_reports','credentials_enabled'], k.code), k.name"
        );

        $out = [];
        foreach ($defs as $d) {
            $kpi = [
                'code' => $d->code, 'name' => $d->name, 'description' => $d->description, 'module' => $d->feature, 'module_name' => $d->module_name,
                'unit' => $d->unit, 'direction' => $d->direction,
            ];
            if (! in_array($d->mode, ['enabled', 'read_only'], true)) {
                $out[] = $kpi + ['locked' => true, 'required_plan' => self::PLAN_NAMES[$d->plan_code] ?? $d->plan_code];

                continue;
            }
            $r = $this->compute($d->formula_key);
            $out[] = $kpi + ['locked' => false] + $r + ['color' => $this->color($d, $r)];
        }

        return $out;
    }

    /** Alertas para actuar hoy, con el módulo donde se resuelven. Solo de módulos habilitados. */
    public function alerts(): array
    {
        $enabled = array_column(DB::select("select module_code from feature_flags where mode in ('enabled', 'read_only') and deleted_at is null"), 'module_code');
        $soon = DB::selectOne("select (?::date + 30) as d", [$this->today])->d;
        $site = fn (string $col) => $this->site ? " and {$col} = '".addslashes($this->site)."'" : '';
        $checks = [
            ['ACR', 'bad', 'acciones correctivas vencidas', "select count(*) as n from corrective_actions where deleted_at is null and status in ('open','in_progress','overdue') and due_on < ?".$site('site_id'), [$this->today]],
            ['IPC', 'bad', 'matrices IPERC con revisión vencida', 'select count(*) as n from iperc_matrices where deleted_at is null and status = \'active\' and next_review_on < ?'.$site('site_id'), [$this->today]],
            ['IPC', 'amber', 'matrices IPERC sin versión aprobada', "select count(*) as n from iperc_matrices where deleted_at is null and status = 'active' and current_version_id is null".$site('site_id'), []],
            ['ACC', 'bad', 'notificaciones de accidentes fuera de plazo', "select count(*) as n from event_notifications n join sst_events e on e.id = n.event_id where n.deleted_at is null and n.status = 'pending' and n.deadline_at < now()".$site('e.site_id'), []],
            ['INS', 'amber', 'inspecciones programadas sin ejecutar', "select count(*) as n from inspections where deleted_at is null and status = 'scheduled' and scheduled_on < ?".$site('site_id'), [$this->today]],
            ['EMO', 'amber', 'aptitudes médicas que vencen en 30 días', "select count(*) as n from fitness_status f join workers w on w.id = f.worker_id where f.deleted_at is null and f.is_current and f.valid_until between ? and ? and w.status = 'active'".$site('w.current_site_id'), [$this->today, $soon]],
            ['EPP', 'amber', 'EPP entregados que vencen en 30 días', "select count(*) as n from ppe_deliveries where deleted_at is null and status = 'delivered' and expires_on between ? and ?".$site('site_id'), [$this->today, $soon]],
            ['FOT', 'amber', 'fotochecks que vencen en 30 días', "select count(*) as n from credentials where deleted_at is null and status = 'active' and expires_on between ? and ?", [$this->today, $soon]],
            ['ACT', 'amber', 'reportes de actos o condiciones sin revisar', "select count(*) as n from substandard_reports where deleted_at is null and status = 'received'".$site('site_id'), []],
            ['SCT', 'bad', 'pólizas SCTR vencidas', "select count(*) as n from sctr_policies where deleted_at is null and status = 'active' and ends_on < ?", [$this->today]],
        ];
        $out = [];
        foreach ($checks as [$module, $tone, $text, $sql, $bind]) {
            if (! in_array($module, $enabled, true)) {
                continue;
            }
            $n = (int) DB::selectOne($sql, $bind)->n;
            if ($n > 0) {
                $out[] = ['module' => $module, 'tone' => $tone, 'count' => $n, 'text' => $text];
            }
        }

        return $out;
    }

    /** Conteos de base de la empresa (para la cabecera del inicio). */
    public function counts(): array
    {
        $s = $this->site;

        return [
            'workers' => (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active','on_leave')".($s ? ' and current_site_id = ?' : ''), $s ? [$s] : [])->n,
            'sites' => (int) DB::selectOne("select count(*) as n from sites where deleted_at is null and status = 'active'")->n,
            'positions' => (int) DB::selectOne("select count(*) as n from positions where deleted_at is null and status = 'active'")->n,
        ];
    }

    // ── Fórmulas ──────────────────────────────────────────────────────────────

    private function compute(string $key): array
    {
        return match ($key) {
            'passt_progress_pct' => $this->ratio(
                "select count(*) filter (where a.status = 'done') as num, count(*) as den from program_activities a join annual_programs p on p.id = a.program_id
                  where a.deleted_at is null and p.deleted_at is null and a.status <> 'cancelled' and p.year = ?", [(int) substr($this->from, 0, 4)],
                'actividades del programa anual ejecutadas'),
            'training_compliance_pct' => $this->ratio(
                "select count(*) filter (where i.status = 'done') as num, count(*) as den from training_program_items i join training_programs p on p.id = i.training_program_id
                  where i.deleted_at is null and p.deleted_at is null and i.status <> 'cancelled' and p.year = ?", [(int) substr($this->from, 0, 4)],
                'capacitaciones del programa realizadas'),
            'inspection_compliance_pct' => $this->ratio(
                "select count(*) filter (where status in ('completed','closed')) as num, count(*) as den from inspections
                  where deleted_at is null and status <> 'cancelled' and scheduled_on between ? and ?".$this->siteSql('site_id'), [$this->from, $this->to],
                'inspecciones ejecutadas de las programadas a la fecha'),
            'corrective_actions_overdue_count' => $this->count(
                "select count(*) filter (where due_on < ?) as num, count(*) as den from corrective_actions
                  where deleted_at is null and status in ('open','in_progress','overdue')".$this->siteSql('site_id'), [$this->today],
                'acciones abiertas con la fecha límite pasada'),
            'corrective_actions_on_time_pct' => $this->ratio(
                "select count(*) filter (where status in ('pending_verification','verified') and completed_at::date <= due_on) as num, count(*) as den
                   from corrective_actions where deleted_at is null and status <> 'cancelled' and due_on between ? and ?".$this->siteSql('site_id'), [$this->from, $this->to],
                'acciones cerradas dentro de su plazo'),
            'legal_compliance_pct' => $this->ratio(
                "select count(*) filter (where e.result = 'compliant') as num, count(*) as den from legal_requirements r
                   left join lateral (select result from requirement_evaluations v where v.legal_requirement_id = r.id and v.deleted_at is null
                                       order by v.evaluated_on desc limit 1) e on true
                  where r.deleted_at is null and r.status = 'active' and r.applies and coalesce(e.result, '') <> 'not_applicable'".$this->siteSql('r.site_id', true), [],
                'requisitos legales cumplidos en su última evaluación'),
            'baseline_score_pct' => $this->ratio(
                "select coalesce(compliant_items, 0) as num, coalesce(total_items, 0) as den from baseline_assessments
                  where deleted_at is null and status in ('completed','approved')".$this->siteSql('site_id', true).' order by assessment_on desc limit 1', [],
                'ítems cumplidos en la última línea base'),
            'ppe_compliance_pct' => $this->ratio(
                "with req as (select w.id as worker_id, r.ppe_item_id from workers w join ppe_position_requirements r on r.position_id = w.current_position_id and r.deleted_at is null and r.is_mandatory
                               where w.deleted_at is null and w.status = 'active'".$this->siteSql('w.current_site_id').")
                 select count(*) filter (where ok) as num, count(*) as den from (
                   select q.worker_id, bool_and(exists (select 1 from ppe_deliveries d where d.worker_id = q.worker_id and d.ppe_item_id = q.ppe_item_id
                            and d.deleted_at is null and d.status = 'delivered' and (d.expires_on is null or d.expires_on >= ?))) as ok
                     from req q group by q.worker_id) t", [$this->today],
                'trabajadores con todo el EPP obligatorio de su puesto vigente'),
            'emo_valid_pct' => $this->health($this->ratio(
                "select count(*) filter (where f.status in ('fit','fit_with_restrictions') and (f.valid_until is null or f.valid_until >= ?)) as num, count(*) as den
                   from workers w left join fitness_status f on f.worker_id = w.id and f.is_current and f.deleted_at is null
                  where w.deleted_at is null and w.status = 'active'".$this->siteSql('w.current_site_id'), [$this->today],
                'trabajadores activos con aptitud médica vigente')),
            'events_notified_on_time_pct' => $this->ratio(
                "select count(*) filter (where n.status in ('sent','acknowledged') and n.notified_at <= n.deadline_at) as num, count(*) as den
                   from event_notifications n join sst_events e on e.id = n.event_id
                  where n.deleted_at is null and n.status <> 'not_required' and n.deadline_at::date between ? and ?".$this->siteSql('e.site_id'), [$this->from, $this->to],
                'notificaciones obligatorias hechas dentro del plazo'),
            'frequency_index', 'severity_index', 'accident_rate' => $this->index($key),
            'substandard_reports_count' => $this->count(
                "select count(*) as num, null as den from substandard_reports where deleted_at is null and reported_at::date between ? and ?".$this->siteSql('site_id'), [$this->from, $this->to],
                'reportes de actos y condiciones subestándar en el año'),
            'credentials_enabled_pct' => $this->ratio(
                "select count(*) filter (where status = 'active' and (expires_on is null or expires_on >= ?)) as num, count(*) as den from credentials
                  where deleted_at is null and status in ('issued','active','blocked','expired')", [$this->today],
                'fotochecks habilitados para ingresar'),
            default => ['value' => null, 'numerator' => null, 'denominator' => null, 'label' => 'No aplica', 'basis' => 'Fórmula aún no disponible.'],
        };
    }

    /** Índices de frecuencia, gravedad y accidentabilidad del año con los factores de legal_parameters. */
    private function index(string $key): array
    {
        $ev = DB::selectOne(
            "select count(*) filter (where event_type in ('disabling_accident','fatal_accident')) as accidents, coalesce(sum(lost_days), 0) as days
               from sst_events where deleted_at is null and status <> 'discarded' and occurred_at::date between ? and ?".$this->siteSql('site_id'),
            [$this->from, $this->to]
        );
        $hours = (float) DB::selectOne(
            'select coalesce(sum(hours_worked), 0) as h from man_hours where deleted_at is null and make_date(period_year, period_month, 1) between ? and ?'.$this->siteSql('site_id'),
            [substr($this->from, 0, 8).'01', $this->to]
        )->h;
        $ff = LegalParams::number('frequency_index_factor', 1000000);
        $sf = LegalParams::number('severity_index_factor', 1000000);
        $div = LegalParams::number('accident_rate_divisor', 1000);
        if ($hours <= 0) {
            return ['value' => null, 'numerator' => (int) $ev->accidents, 'denominator' => 0, 'label' => 'No aplica',
                'basis' => 'Registra las horas-hombre trabajadas del año (Estadísticas) para calcular el índice.'];
        }
        $fi = $ev->accidents * $ff / $hours;
        $si = $ev->days * $sf / $hours;
        [$value, $num, $basis] = match ($key) {
            'frequency_index' => [$fi, (int) $ev->accidents, "accidentes incapacitantes × {$this->fmt($ff)} / horas-hombre"],
            'severity_index' => [$si, (int) $ev->days, "días perdidos × {$this->fmt($sf)} / horas-hombre"],
            default => [$fi * $si / $div, null, "IF × IG / {$this->fmt($div)}"],
        };

        return ['value' => round($value, 2), 'numerator' => $num, 'denominator' => round($hours), 'label' => $this->fmt(round($value, 2)), 'basis' => $basis];
    }

    private function ratio(string $sql, array $bind, string $basis): array
    {
        $r = DB::selectOne($sql, $bind);
        $num = (int) ($r->num ?? 0);
        $den = (int) ($r->den ?? 0);
        if ($den === 0) {
            return ['value' => null, 'numerator' => 0, 'denominator' => 0, 'label' => 'No aplica', 'basis' => "Aún no hay registros para medir: {$basis}."];
        }
        $pct = round($num * 100 / $den, 1);

        return ['value' => $pct, 'numerator' => $num, 'denominator' => $den, 'label' => $this->fmt($pct).' %', 'basis' => "{$num} de {$den} {$basis}."];
    }

    private function count(string $sql, array $bind, string $basis): array
    {
        $r = DB::selectOne($sql, $bind);
        $num = (int) $r->num;

        return ['value' => $num, 'numerator' => $num, 'denominator' => $r->den === null ? null : (int) $r->den, 'label' => (string) $num,
            'basis' => $r->den === null ? "{$num} {$basis}." : "{$num} de {$r->den} {$basis}."];
    }

    /** RN-DSH-05: los KPI de salud no se muestran con grupos menores a min_group_size (evita reidentificar). */
    private function health(array $r): array
    {
        $min = (int) LegalParams::number('min_group_size', 5);
        if ($r['denominator'] > 0 && $r['denominator'] < $min) {
            return ['value' => null, 'numerator' => null, 'denominator' => null, 'label' => 'No aplica',
                'basis' => "Grupo menor a {$min} personas: no se muestra para proteger datos de salud."];
        }

        return $r;
    }

    private function color(object $d, array $r): ?string
    {
        if ($r['value'] === null || $d->direction === 'target') {
            return null;
        }
        $green = $d->green_threshold !== null ? (float) $d->green_threshold : null;
        $amber = $d->amber_threshold !== null ? (float) $d->amber_threshold : null;
        if ($green === null && $d->unit === '%' && $d->direction === 'higher_better') {
            [$green, $amber] = self::DEFAULT_PCT;
        }
        if ($green === null && $d->code === 'corrective_actions_overdue') {
            [$green, $amber] = [0, 3];
        }
        if ($green === null) {
            return null;
        }
        $v = (float) $r['value'];

        return $d->direction === 'higher_better'
            ? ($v >= $green ? 'green' : ($v >= $amber ? 'amber' : 'red'))
            : ($v <= $green ? 'green' : ($v <= $amber ? 'amber' : 'red'));
    }

    private function siteSql(string $col, bool $orNull = false): string
    {
        if (! $this->site) {
            return '';
        }
        $s = "'".str_replace("'", "''", $this->site)."'::uuid";

        return $orNull ? " and ({$col} = {$s} or {$col} is null)" : " and {$col} = {$s}";
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }
}
