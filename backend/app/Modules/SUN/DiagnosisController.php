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
    public function __invoke(): JsonResponse
    {
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $year = (int) substr($today, 0, 4);
        $enabled = array_column(DB::select("select module_code from feature_flags where mode in ('enabled', 'read_only') and deleted_at is null"), 'module_code');
        $workers = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active','on_leave')")->n;

        $areas = [
            $this->committee($today),
            $this->iperc($today),
            $this->registers($year),
            $this->training($year),
            $this->emo($today),
            $this->policy($workers),
            $this->ppe($today),
        ];
        foreach ($areas as &$a) {
            $a['enabled'] = in_array($a['module'], $enabled, true);
            $a['tone'] = $a['pct'] === null ? 'na' : ($a['pct'] >= 90 ? 'ok' : ($a['pct'] >= 70 ? 'warn' : 'bad'));
        }
        unset($a);
        $measured = array_filter($areas, fn ($a) => $a['pct'] !== null);
        $score = $measured ? (int) round(array_sum(array_column($measured, 'pct')) / count($measured)) : null;

        return response()->json(['data' => [
            'score' => $score,
            'evaluated_at' => now('America/Lima')->toIso8601String(),
            'areas' => $areas,
        ]]);
    }

    private function area(string $name, string $module, ?string $table, ?float $pct, string $detail): array
    {
        return ['name' => $name, 'module' => $module, 'table' => $table, 'pct' => $pct === null ? null : (int) round(max(0, min(100, $pct))), 'detail' => $detail];
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
               from iperc_matrices where status = 'active' and deleted_at is null", [$today, $today]);
        if ((int) $r->n === 0) {
            return $this->area('IPERC', 'IPC', 'iperc_matrices', 0, 'No hay matrices IPERC activas.');
        }
        $parts = array_filter([$r->overdue > 0 ? "{$r->overdue} con revisión vencida" : null, $r->draft > 0 ? "{$r->draft} sin versión aprobada" : null]);

        return $this->area('IPERC', 'IPC', 'iperc_matrices', $r->ok * 100 / $r->n,
            $parts ? "{$r->ok} de {$r->n} matrices al día · ".implode(' · ', $parts).'.' : "Las {$r->n} matrices están aprobadas y con revisión al día.");
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
        $deliv = $r ? (int) DB::selectOne('select count(distinct worker_id) as n from risst_deliveries where risst_version_id = ? and deleted_at is null', [$r->id])->n : 0;
        $pct = ($p ? 50 : 0) + ($r ? 50 * min(1, $workers ? $deliv / $workers : 1) : 0);
        $parts = [$p ? 'Política vigente' : 'Falta la política vigente', $r ? "RISST entregado a {$deliv} de {$workers} trabajadores" : 'Falta el RISST vigente'];

        return $this->area('Política y RISST', $p ? 'RIS' : 'POL', $p ? 'risst_deliveries' : 'sst_policies', $pct, implode(' · ', $parts).'.');
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

        return $this->area('EPP', 'EPP', 'ppe_deliveries', $r->num * 100 / $r->den,
            $pending === 0 ? 'Todos tienen el EPP obligatorio de su puesto vigente.' : "{$pending} trabajador(es) sin todo el EPP obligatorio de su puesto vigente.");
    }
}
