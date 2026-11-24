<?php

namespace App\Modules\SUN;

use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use App\Modules\REG\RegistersController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /sun/diagnosis — autodiagnóstico frente a una inspección (M-SUN, SCR-SUN-01/02), calculado en el momento con
 * los registros reales de los módulos: un porcentaje por área con su semáforo, el motivo en palabras y el módulo
 * donde se resuelve. El puntaje general es el promedio de las áreas que se pueden medir.
 * Los datos de salud se dan solo como porcentaje agregado y no se muestran con grupos menores a min_group_size.
 */
class DiagnosisController extends Controller
{
    /** Código de cada obligación del catálogo (en el orden en que se evalúan). */
    public const CODES = ['committee', 'iperc', 'pets', 'drills', 'registers', 'training', 'emo', 'policy', 'ppe', 'legal'];

    public const CHECKLIST_VERSION = 'SUN-2026.1';

    public const SEVERITY_LABEL = ['minor' => 'Leve', 'serious' => 'Grave', 'very_serious' => 'Muy grave'];

    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => $this->evaluate()]);
    }

    /**
     * RN-SUN-01/02: evalúa el catálogo de obligaciones con los datos de los módulos; cada obligación lleva la gravedad de su
     * infracción (parámetro por confirmar) y el puntaje ponderado da más peso a las graves y muy graves.
     */
    public function evaluate(): array
    {
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $year = (int) substr($today, 0, 4);
        $enabled = array_column(DB::select("select module_code from feature_flags where mode in ('enabled', 'read_only') and deleted_at is null"), 'module_code');
        $workers = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active','on_leave')")->n;

        $areas = [
            $this->committee($today),
            $this->iperc($today),
            $this->pets(),
            $this->drills($year),
            $this->registers($year),
            $this->training($year),
            $this->emo($today),
            $this->policy($workers),
            $this->ppe($today),
            $this->legal($today),
        ];
        $severity = json_decode(LegalParams::text('sunafil_obligation_severity', '{}'), true) ?: [];
        $weights = ['minor' => LegalParams::number('sunafil_weight_minor', 1), 'serious' => LegalParams::number('sunafil_weight_serious', 2),
            'very_serious' => LegalParams::number('sunafil_weight_very_serious', 3)];
        $gapBelow = LegalParams::number('sunafil_gap_threshold_pct', 70);
        foreach ($areas as $i => &$a) {
            $a['code'] = self::CODES[$i];
            $a['enabled'] = in_array($a['module'], $enabled, true);
            $a['tone'] = $a['pct'] === null ? 'na' : ($a['pct'] >= 90 ? 'ok' : ($a['pct'] >= $gapBelow ? 'warn' : 'bad'));
            $a['result'] = $a['pct'] === null ? 'na' : ($a['pct'] >= 100 ? 'complies' : ($a['pct'] >= $gapBelow ? 'partial' : 'fails'));
            $a['severity'] = isset(self::SEVERITY_LABEL[$severity[$a['code']] ?? '']) ? $severity[$a['code']] : 'serious';
            $a['severity_label'] = self::SEVERITY_LABEL[$a['severity']];
        }
        unset($a);
        $measured = array_filter($areas, fn ($a) => $a['pct'] !== null);
        $score = $measured ? (int) round(array_sum(array_column($measured, 'pct')) / count($measured)) : null;
        $wsum = array_sum(array_map(fn ($a) => $weights[$a['severity']], $measured));
        $weighted = $measured && $wsum > 0 ? (int) round(array_sum(array_map(fn ($a) => $a['pct'] * $weights[$a['severity']], $measured)) / $wsum) : null;

        return [
            'score' => $score,
            'risk_weighted_score' => $weighted,
            'very_serious_gaps' => count(array_filter($measured, fn ($a) => $a['severity'] === 'very_serious' && $a['result'] === 'fails')),
            'checklist_version' => self::CHECKLIST_VERSION,
            'evaluated_at' => now('America/Lima')->toIso8601String(),
            'areas' => $areas,
        ];
    }

    /** @param list<string> $workers trabajadores que faltan en la obligación (criterio 1 de M-SUN: se listan por nombre) */
    private function area(string $name, string $module, ?string $table, ?float $pct, string $detail, array $workers = []): array
    {
        return ['name' => $name, 'module' => $module, 'table' => $table, 'pct' => $pct === null ? null : (int) round(max(0, min(100, $pct))), 'detail' => $detail,
            'workers' => array_slice($workers, 0, 100), 'workers_total' => count($workers)];
    }

    /** Comité (o supervisor) activo y sesiones mensuales de los últimos 3 meses. */
    private function committee(string $today): array
    {
        $c = DB::selectOne("select id, committee_type from committees where status = 'active' and deleted_at is null order by created_at desc limit 1");
        if (! $c) {
            return $this->area('Comité SST', 'COM', 'committees', 0, 'No hay comité ni supervisor de SST activo.');
        }
        if ($c->committee_type === 'supervisor') {
            return $this->area('Comité SST', 'COM', 'committees', 100, 'Supervisor de SST designado y en funciones.');
        }
        $held = (int) DB::selectOne(
            "select count(distinct date_trunc('month', held_at at time zone 'America/Lima')) as n from committee_meetings
              where committee_id = ? and deleted_at is null and status in ('held','minutes_draft','minutes_signed')
                and (held_at at time zone 'America/Lima')::date > (?::date - interval '3 months')", [$c->id, $today])->n;
        $unsigned = (int) DB::selectOne("select count(*) as n from committee_meetings where committee_id = ? and deleted_at is null and status in ('held','minutes_draft')", [$c->id])->n;
        $pct = 50 + min(3, $held) * 50 / 3 - min(10, $unsigned * 5);
        $detail = $held >= 3 ? 'Comité activo con sesiones mensuales al día' : "Comité activo; {$held} de 3 sesiones mensuales en los últimos 3 meses";

        return $this->area('Comité SST', 'COM', 'committee_meetings', $pct, $detail.($unsigned > 0 ? " · {$unsigned} acta(s) sin firmar." : '.'));
    }

    /** Matrices IPERC activas con versión aprobada y revisión al día. */
    private function iperc(string $today): array
    {
        $r = DB::selectOne(
            "select count(*) as n, count(*) filter (where current_version_id is not null and (next_review_on is null or next_review_on >= ?)) as ok,
                    count(*) filter (where next_review_on < ?) as overdue, count(*) filter (where current_version_id is null) as draft
               from iperc_matrices where status in ('active', 'review_required', 'review_overdue') and deleted_at is null", [$today, $today]);
        if ((int) $r->n === 0) {
            return $this->area('IPERC', 'IPC', 'iperc_matrices', 0, 'No hay matrices IPERC activas.');
        }
        $parts = array_filter([$r->overdue > 0 ? "{$r->overdue} con revisión vencida" : null, $r->draft > 0 ? "{$r->draft} sin versión aprobada" : null]);

        return $this->area('IPERC', 'IPC', 'iperc_matrices', $r->ok * 100 / $r->n,
            $parts ? "{$r->ok} de {$r->n} matrices al día · ".implode(' · ', $parts).'.' : "Las {$r->n} matrices están aprobadas y con revisión al día.");
    }

    /** M-PET: cada puesto crítico tiene su PETS vigente; el que no lo tiene es una brecha (como el aviso pet.critical_without_pets). */
    private function pets(): array
    {
        $rows = DB::select(
            "select p.name, exists (select 1 from procedures r join procedure_versions v on v.id = r.current_version_id and v.status in ('approved', 'current')
                                     where r.position_id = p.id and r.deleted_at is null and r.status = 'active') as ok
               from positions p where p.deleted_at is null and p.status = 'active' and p.is_critical order by p.name");
        if (! $rows) {
            return $this->area('PETS de tareas críticas', 'PET', 'procedures', null, 'No hay puestos marcados como críticos.');
        }
        $missing = array_column(array_filter($rows, fn ($r) => ! $r->ok), 'name');
        $ok = count($rows) - count($missing);

        return $this->area('PETS de tareas críticas', 'PET', 'procedures', $ok * 100 / count($rows),
            $missing ? "{$ok} de ".count($rows).' puestos críticos con PETS vigente · sin PETS: '.implode(', ', array_slice($missing, 0, 5)).(count($missing) > 5 ? '…' : '').'.'
                : 'Todos los puestos críticos tienen su PETS vigente.');
    }

    /** M-SIM RN-SIM-02: cada sede con trabajadores tiene al menos drills_per_year simulacros ejecutados en el año. */
    private function drills(int $year): array
    {
        $min = (int) LegalParams::number('drills_per_year', 1);
        $rows = DB::select(
            "select s.name, (select count(*) from drills d where d.site_id = s.id and d.deleted_at is null and d.status in ('executed', 'evaluated')
                                and extract(year from coalesce(d.executed_at, d.scheduled_at)) = ?) as n
               from sites s where s.deleted_at is null and exists (select 1 from workers w where w.current_site_id = s.id and w.status = 'active' and w.deleted_at is null)
              order by s.name", [$year]);
        if (! $rows) {
            return $this->area('Simulacros', 'SIM', 'drills', null, 'No hay sedes con trabajadores activos.');
        }
        $late = array_values(array_filter($rows, fn ($r) => (int) $r->n < $min));
        $ok = count($rows) - count($late);

        return $this->area('Simulacros', 'SIM', 'drills', $ok * 100 / count($rows),
            $late ? "{$ok} de ".count($rows)." sedes con {$min} o más simulacros en {$year} · faltan: ".implode(', ', array_map(fn ($r) => "{$r->name} ({$r->n})", array_slice($late, 0, 5))).'.'
                : "Todas las sedes tienen {$min} o más simulacros ejecutados en {$year}.");
    }

    /** Registros obligatorios (R.M. 050-2013-TR) generados en el año. */
    private function registers(int $year): array
    {
        $total = count(RegistersController::TYPES);
        $done = (int) DB::selectOne("select count(distinct register_type) as n from register_exports where deleted_at is null and status = 'ready'
                                       and extract(year from coalesce(generated_at, created_at) at time zone 'America/Lima') = ?", [$year])->n;
        $missing = $total - $done;

        return $this->area('Registros obligatorios', 'REG', 'register_exports', $done * 100 / $total,
            $missing === 0 ? "Los {$total} registros se generaron este año." : "{$done} de {$total} registros generados este año · faltan {$missing}.");
    }

    /** Capacitaciones del programa anual realizadas; sin programa, la matriz por cargo. */
    private function training(int $year): array
    {
        $r = DB::selectOne(
            "select count(*) filter (where i.status = 'done') as num, count(*) as den from training_program_items i join training_programs p on p.id = i.training_program_id
              where i.deleted_at is null and p.deleted_at is null and i.status <> 'cancelled' and p.year = ?", [$year]);
        if ((int) $r->den > 0) {
            return $this->area('Capacitación', 'CAP', 'trainings', $r->num * 100 / $r->den, "{$r->num} de {$r->den} capacitaciones del programa {$year} realizadas.");
        }
        $m = DB::selectOne(
            "select count(*) filter (where exists (select 1 from training_certificates c where c.worker_id = w.id and c.training_id = q.training_id and c.deleted_at is null
                      and c.status = 'validated' and (c.expires_on is null or c.expires_on >= current_date))) as num, count(*) as den
               from workers w join position_training_requirements q on q.position_id = w.current_position_id and q.deleted_at is null
                and coalesce(q.is_active, true) and q.requirement_level = 'mandatory' and q.training_id is not null
              where w.deleted_at is null and w.status = 'active'");
        if ((int) $m->den === 0) {
            return $this->area('Capacitación', 'CAP', 'trainings', 0, "No hay programa de capacitación {$year} ni matriz por cargo.");
        }

        return $this->area('Capacitación', 'CAP', 'position_training_requirements', $m->num * 100 / $m->den, "{$m->num} de {$m->den} capacitaciones obligatorias por cargo vigentes.");
    }

    /** Trabajadores activos con aptitud médica vigente (solo el agregado). */
    private function emo(string $today): array
    {
        $r = DB::selectOne(
            "select count(*) filter (where f.status in ('fit','fit_with_restrictions') and (f.valid_until is null or f.valid_until >= ?)) as num,
                    count(*) filter (where f.id is null) as none, count(*) as den
               from workers w left join fitness_status f on f.worker_id = w.id and f.is_current and f.deleted_at is null
              where w.deleted_at is null and w.status = 'active'", [$today]);
        $min = (int) LegalParams::number('min_group_size', 5);
        if ((int) $r->den === 0) {
            return $this->area('EMO', 'EMO', 'fitness_status', 0, 'No hay trabajadores activos registrados.');
        }
        if ((int) $r->den < $min) {
            return $this->area('EMO', 'EMO', 'fitness_status', null, "Grupo menor a {$min} personas: no se muestra para proteger datos de salud.");
        }
        $pending = $r->den - $r->num;

        return $this->area('EMO', 'EMO', 'fitness_status', $r->num * 100 / $r->den,
            $pending === 0 ? 'Todos los trabajadores activos tienen aptitud vigente.' : "{$pending} trabajador(es) sin aptitud vigente".($r->none > 0 ? " ({$r->none} sin EMO registrado)" : '').'.');
    }

    /** Política vigente y RISST vigente entregado (obligatorio desde el umbral de trabajadores). */
    private function policy(int $workers): array
    {
        $p = DB::selectOne("select id from sst_policies where status = 'current' and deleted_at is null limit 1");
        $min = (int) LegalParams::number('risst_threshold_workers', 20);
        if ($workers < $min) {
            return $this->area('Política y RISST', 'POL', 'sst_policies', $p ? 100 : 0,
                ($p ? 'Política vigente.' : 'No hay una política de SST vigente.')." El RISST es obligatorio desde {$min} trabajadores.");
        }
        $r = DB::selectOne("select id from risst_versions where status = 'current' and deleted_at is null limit 1");
        // RN-RIS-06: solo cuenta la entrega hecha (entregada o firmada); quien ingresó hace menos de risst_delivery_grace_days aún está en plazo
        // y no se cuenta como brecha. Las pendientes fuera de plazo sí.
        $grace = (int) LegalParams::number('risst_delivery_grace_days', 30);
        $d = $r ? DB::selectOne(
            "select count(*) filter (where x.done) as done, count(*) filter (where not x.done and (w.hire_on is null or w.hire_on + ?::int < current_date)) as late
               from workers w
               left join lateral (select bool_or(d.status in ('delivered', 'signed')) as done from risst_deliveries d
                                   where d.risst_version_id = ? and d.worker_id = w.id and d.deleted_at is null) x0 on true
               cross join lateral (select coalesce(x0.done, false) as done) x
              where w.deleted_at is null and w.status = 'active'", [$grace, $r->id]) : null;
        $deliv = (int) ($d->done ?? 0);
        $late = (int) ($d->late ?? 0);
        // Quiénes no tienen el cargo de entrega del RISST pasado el plazo.
        $lateNames = $r && $late ? array_column(DB::select(
            "select w.full_name from workers w
              where w.deleted_at is null and w.status = 'active' and (w.hire_on is null or w.hire_on + ?::int < current_date)
                and not exists (select 1 from risst_deliveries d where d.risst_version_id = ? and d.worker_id = w.id and d.deleted_at is null and d.status in ('delivered', 'signed'))
              order by w.full_name", [$grace, $r->id]), 'full_name') : [];
        $due = $deliv + $late;
        $pct = ($p ? 50 : 0) + ($r ? 50 * ($due ? $deliv / $due : 1) : 0);
        $parts = [$p ? 'Política vigente' : 'Falta la política vigente',
            $r ? "RISST entregado a {$deliv} de {$workers} trabajadores".($late ? " ({$late} sin cargo pasado el plazo de {$grace} días)" : '') : 'Falta el RISST vigente'];

        return $this->area('Política y RISST', $p ? 'RIS' : 'POL', $p ? 'risst_deliveries' : 'sst_policies', $pct, implode(' · ', $parts).'.', $lateNames);
    }

    /** Trabajadores con todo el EPP obligatorio de su puesto entregado y vigente. */
    private function ppe(string $today): array
    {
        $r = DB::selectOne(
            "with req as (select w.id as worker_id, r.ppe_item_id from workers w join ppe_position_requirements r on r.position_id = w.current_position_id and r.deleted_at is null and r.is_mandatory
                           where w.deleted_at is null and w.status = 'active')
             select count(*) filter (where ok) as num, count(*) as den from (
               select q.worker_id, bool_and(exists (select 1 from ppe_deliveries d where d.worker_id = q.worker_id and d.ppe_item_id = q.ppe_item_id
                        and d.deleted_at is null and d.status = 'delivered' and (d.expires_on is null or d.expires_on >= ?))) as ok
                 from req q group by q.worker_id) t", [$today]);
        if ((int) $r->den === 0) {
            return $this->area('EPP', 'EPP', 'ppe_position_requirements', 0, 'No hay EPP obligatorio configurado por puesto.');
        }
        $pending = $r->den - $r->num;
        $names = $pending > 0 ? array_column(DB::select(
            "select distinct w.full_name from workers w join ppe_position_requirements q on q.position_id = w.current_position_id and q.deleted_at is null and q.is_mandatory
              where w.deleted_at is null and w.status = 'active'
                and not exists (select 1 from ppe_deliveries d where d.worker_id = w.id and d.ppe_item_id = q.ppe_item_id and d.deleted_at is null and d.status = 'delivered'
                                 and (d.expires_on is null or d.expires_on >= ?))
              order by w.full_name", [$today]), 'full_name') : [];

        return $this->area('EPP', 'EPP', 'ppe_deliveries', $r->num * 100 / $r->den,
            $pending === 0 ? 'Todos tienen el EPP obligatorio de su puesto vigente.' : "{$pending} trabajador(es) sin todo el EPP obligatorio de su puesto vigente.", $names);
    }

    /**
     * M-MRL §12 (insumo del autodiagnóstico): RN-MRL-06, requisitos aplicables con última evaluación «cumple» / requisitos
     * aplicables evaluados; los que «requieren revisión» por cambio normativo no cuentan como cumplidos (RN-MRL-05).
     * El detalle nombra los incumplidos, los pendientes de evaluar y las evaluaciones vencidas.
     */
    private function legal(string $today): array
    {
        $r = DB::selectOne(
            "select count(*) as total,
                    count(*) filter (where e.result is not null) as evaluated,
                    count(*) filter (where e.result = 'compliant' and not r.needs_review) as compliant,
                    count(*) filter (where e.result in ('partial', 'non_compliant')) as failing,
                    count(*) filter (where e.result is null) as pending,
                    count(*) filter (where r.needs_review) as review,
                    count(*) filter (where r.next_evaluation_on < ?) as overdue
               from legal_requirements r
               left join lateral (select result from requirement_evaluations v where v.legal_requirement_id = r.id and v.deleted_at is null
                                   order by v.evaluated_on desc, v.created_at desc limit 1) e on true
              where r.deleted_at is null and r.status = 'active' and r.applies and coalesce(e.result, '') <> 'not_applicable'", [$today]);
        if ((int) $r->total === 0) {
            return $this->area('Requisitos legales', 'MRL', 'legal_requirements', 0, 'No hay requisitos legales aplicables en la matriz.');
        }
        if ((int) $r->evaluated === 0) {
            return $this->area('Requisitos legales', 'MRL', 'requirement_evaluations', 0, "Ninguno de los {$r->total} requisitos legales aplicables tiene evaluación de cumplimiento.");
        }
        $parts = array_filter([
            $r->failing > 0 ? "{$r->failing} con resultado parcial o no cumple" : null,
            $r->review > 0 ? "{$r->review} requieren revisión por cambio normativo" : null,
            $r->pending > 0 ? "{$r->pending} sin evaluar" : null,
            $r->overdue > 0 ? "{$r->overdue} con evaluación vencida" : null,
        ]);

        return $this->area('Requisitos legales', 'MRL', 'requirement_evaluations', $r->compliant * 100 / $r->evaluated,
            "{$r->compliant} de {$r->evaluated} requisitos evaluados cumplen".($parts ? ' · '.implode(' · ', $parts) : '').'.');
    }
}
