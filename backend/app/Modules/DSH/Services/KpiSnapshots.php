<?php

namespace App\Modules\DSH\Services;

use App\Core\Tenancy\DbContext;
use Illuminate\Support\Facades\DB;

/**
 * M-DSH: guarda cada día el valor de los indicadores de cada empresa (kpi_snapshots) para ver su historia por mes y sede.
 * Cada fila es el valor acumulado del año al cierre del mes (o a hoy en el mes en curso), igual que el tablero; una fila por
 * indicador, mes y sede (sede vacía = toda la empresa). Al correr por primera vez completa los meses ya pasados del año.
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
        return DB::transaction(function () use ($company) {
            DbContext::setCompany($company);
            if (! DB::selectOne("select 1 as ok from feature_flags where module_code = 'DSH' and mode in ('enabled', 'read_only') and deleted_at is null")) {
                return 0;
            }
            $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
            $year = (int) substr($today, 0, 4);
            $sites = array_merge([null], array_column(DB::select("select id from sites where deleted_at is null and status = 'active'"), 'id'));
            $defs = collect(DB::select('select id, code from kpi_definitions where deleted_at is null and is_active
                and (company_id is not null or not exists (select 1 from kpi_definitions o where o.company_id is not null and o.code = kpi_definitions.code and o.deleted_at is null))'))->keyBy('code');
            $months = [];
            for ($m = 1; $m <= (int) substr($today, 5, 2); $m++) {
                $end = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $year, $m)));
                $done = DB::selectOne('select 1 as ok from kpi_snapshots where period_start = ? and site_id is null limit 1', [sprintf('%d-%02d-01', $year, $m)]);
                if ($end >= $today || ! $done) {
                    $months[] = [$m, min($end, $today)];
                }
            }
            $n = 0;
            foreach ($months as [$m, $asOf]) {
                $start = sprintf('%d-%02d-01', $year, $m);
                foreach ($sites as $site) {
                    foreach ($this->kpis->overview($year, $site, $asOf) as $k) {
                        if (! empty($k['locked']) || ! isset($defs[$k['code']])) {
                            continue;
                        }
                        $vals = [$asOf, $k['value'], $k['numerator'] ?? null, $k['denominator'] ?? null, $k['color'] ?? null,
                            json_encode(['label' => $k['label'] ?? null, 'basis' => $k['basis'] ?? null], JSON_UNESCAPED_UNICODE)];
                        $upd = DB::update(
                            'update kpi_snapshots set period_end = ?, value = ?, numerator = ?, denominator = ?, status_color = ?, details = ?::jsonb, computed_at = now(), updated_at = now()
                              where kpi_definition_id = ? and period_start = ? and site_id is not distinct from ?::uuid and deleted_at is null',
                            [...$vals, $defs[$k['code']]->id, $start, $site]);
                        if (! $upd) {
                            DB::insert(
                                "insert into kpi_snapshots (company_id, kpi_definition_id, period_start, site_id, period_end, value, numerator, denominator, status_color, details, computed_at)
                                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, now())",
                                [$company, $defs[$k['code']]->id, $start, $site, ...$vals]);
                        }
                        $n++;
                    }
                }
            }

            return $n;
        });
    }
}
