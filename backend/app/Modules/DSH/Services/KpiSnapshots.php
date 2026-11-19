<?php

namespace App\Modules\DSH\Services;

use App\Core\Tenancy\DbContext;
use Illuminate\Support\Facades\DB;

/**
 * M-DSH: guarda cada día el valor de los indicadores de cada empresa (kpi_snapshots) para ver su historia por mes y sede.
 * Cada fila es el valor acumulado del año al cierre del mes (o a hoy en el mes en curso), igual que el tablero; una fila por
 * indicador, mes y ámbito: toda la empresa, cada sede y cada área (con sus subáreas). Al correr por primera vez completa los meses ya pasados del año.
 */
final class KpiSnapshots
{
    public function __construct(private KpiService $kpis) {}

    public function run(): int
    {
        $companies = DB::connection('platform')->select(
            "select c.id from companies c join subscriptions s on s.company_id = c.id
              where c.deleted_at is null and c.status = 'active' and s.deleted_at is null and s.status in ('trialing','active','past_due','grace')");
        $n = 0;
        foreach ($companies as $c) {
            $n += $this->forCompany($c->id);
        }

        return $n;
    }

    public function forCompany(string $company): int
    {
        $n = $this->snapshots($company);
        try {
            DB::transaction(function () use ($company) {
                DbContext::setCompany($company);
                \App\Modules\REC\Rules\DocsFieldRules::refreshKpiTargets();
            });
        } catch (\Throwable $e) {
            report($e);
        }

        return $n;
    }

    private function snapshots(string $company): int
    {
        return DB::transaction(function () use ($company) {
            DbContext::setCompany($company);
            if (! DB::selectOne("select 1 as ok from feature_flags where module_code = 'DSH' and mode in ('enabled', 'read_only') and deleted_at is null")) {
                return 0;
            }
            $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
            $year = (int) substr($today, 0, 4);
            // Ámbitos: toda la empresa (sede vacía), cada sede y cada área activa (con sus subáreas; M-DSH §5 kpi_snapshots.area_id).
            $scopes = array_map(fn ($s) => [$s, null], array_merge([null], array_column(DB::select("select id from sites where deleted_at is null and status = 'active'"), 'id')));
            foreach (DB::select("select id, site_id from areas where deleted_at is null and coalesce(status, 'active') = 'active'") as $a) {
                $scopes[] = [$a->site_id, $a->id];
            }
            $defs = collect(DB::select('select id, code from kpi_definitions where deleted_at is null and is_active
                and (company_id is not null or not exists (select 1 from kpi_definitions o where o.company_id is not null and o.code = kpi_definitions.code and o.deleted_at is null))'))->keyBy('code');
            $months = [];
            for ($m = 1; $m <= (int) substr($today, 5, 2); $m++) {
                $end = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $year, $m)));
                $months[] = [$m, min($end, $today), $end >= $today];
            }
            $n = 0;
            foreach ($months as [$m, $asOf, $open]) {
                $start = sprintf('%d-%02d-01', $year, $m);
                foreach ($scopes as [$site, $area]) {
                    // RN-DSH-06: un mes cerrado que ya tiene su valor no se recalcula; el mes en curso se actualiza cada día.
                    if (! $open && DB::selectOne('select 1 as ok from kpi_snapshots where period_start = ? and site_id is not distinct from ?::uuid and area_id is not distinct from ?::uuid and deleted_at is null limit 1',
                        [$start, $area ? $site : $site, $area])) {
                        continue;
                    }
                    $calc = clone $this->kpis;
                    if ($area) {
                        $calc->forAreas(array_column(DB::select(
                            'with recursive t as (select id from areas where id = ? union all select a.id from areas a join t on a.parent_area_id = t.id where a.deleted_at is null) select id from t', [$area]), 'id'));
                    }
                    foreach ($calc->overview($year, $area ? null : $site, $asOf) as $k) {
                        if (! empty($k['locked']) || ! isset($defs[$k['code']]) || ($area && ($k['value'] ?? null) === null)) {
                            continue;
                        }
                        $vals = [$asOf, $k['value'], $k['numerator'] ?? null, $k['denominator'] ?? null, $k['color'] ?? null,
                            json_encode(['label' => $k['label'] ?? null, 'basis' => $k['basis'] ?? null], JSON_UNESCAPED_UNICODE)];
                        $upd = DB::update(
                            'update kpi_snapshots set period_end = ?, value = ?, numerator = ?, denominator = ?, status_color = ?, details = ?::jsonb, computed_at = now(), updated_at = now()
                              where kpi_definition_id = ? and period_start = ? and site_id is not distinct from ?::uuid and area_id is not distinct from ?::uuid and deleted_at is null',
                            [...$vals, $defs[$k['code']]->id, $start, $site, $area]);
                        if (! $upd) {
                            DB::insert(
                                "insert into kpi_snapshots (company_id, kpi_definition_id, period_start, site_id, area_id, period_end, value, numerator, denominator, status_color, details, computed_at)
                                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, now())",
                                [$company, $defs[$k['code']]->id, $start, $site, $area, ...$vals]);
                        }
                        $n++;
                    }
                }
            }

            return $n;
        });
    }
}
