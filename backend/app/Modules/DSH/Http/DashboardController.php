<?php

namespace App\Modules\DSH\Http;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\DSH\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tablero principal de la empresa (SCR-DSH-01):
 *
 *  GET /dsh/overview?year=2026&site_id=   indicadores con semáforo, alertas para actuar y conteos base
 */
class DashboardController extends Controller
{
    public function __construct(private KpiService $kpis) {}

    public function overview(Request $request): JsonResponse
    {
        $current = (int) DB::selectOne("select extract(year from now() at time zone 'America/Lima')::int as y")->y;
        $year = (int) ($request->query('year') ?: $current);
        if ($year < 2000 || $year > $current) {
            throw ApiProblem::badRequest('Elige un año entre 2000 y el actual.');
        }
        $site = $request->query('site_id');
        if ($site !== null && $site !== '') {
            if (! Str::isUuid($site) || ! DB::selectOne('select 1 as ok from sites where id = ? and deleted_at is null', [$site])) {
                throw ApiProblem::notFound('La sede no existe.');
            }
        } else {
            $site = null;
        }

        $kpis = $this->kpis->overview($year, $site);

        return response()->json(['data' => [
            'year' => $year,
            'site_id' => $site,
            'kpis' => $kpis,
            'alerts' => $year === $current ? $this->kpis->alerts() : [],
            'counts' => $this->kpis->counts(),
            'sites' => DB::select("select id, name from sites where deleted_at is null and status = 'active' order by is_main desc, name"),
        ]]);
    }
}
