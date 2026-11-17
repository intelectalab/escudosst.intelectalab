<?php

namespace App\Modules\SUN;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\NOT\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Autodiagnóstico SUNAFIL guardado (M-SUN SCR-SUN-02, §10, §11):
 *  POST /sun/self-diagnostics            ejecuta el autodiagnóstico y lo guarda; si hay brecha muy grave avisa al Jefe SST y a gerencia
 *  GET  /sun/self-diagnostics/monthly    posición por mes (último autodiagnóstico de cada mes, 12 meses)
 *  GET  /sun/self-diagnostics/{id}       detalle de uno guardado (informe PDF)
 */
class SelfDiagnosisController extends Controller
{
    public function store(): JsonResponse
    {
        if (! in_array('sun.self_diagnostics.create', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ejecuta autodiagnósticos.');
        }
        $ev = app(DiagnosisController::class)->evaluate();
        $uid = (string) auth()->id();
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $company = app(\App\Core\Tenancy\CompanyContext::class)->companyId;
        $id = DB::selectOne("insert into self_diagnostics (company_id, title, diagnostic_on, checklist_version, items, score_pct, critical_gaps, performed_by, status, created_by, updated_by)
                             values (?, ?, ?, ?, ?::jsonb, ?, ?, ?, 'completed', ?, ?) returning id",
            [$company, 'Autodiagnóstico SUNAFIL '.date('d/m/Y', strtotime($today)), $today, $ev['checklist_version'], json_encode($ev, JSON_UNESCAPED_UNICODE), $ev['score'],
                $ev['very_serious_gaps'], $uid, $uid, $uid])->id;

        // M-SUN §10: brecha muy grave detectada → Jefe SST y gerencia (app y correo), al ejecutar.
        if ($ev['very_serious_gaps'] > 0) {
            $names = implode(', ', array_column(array_filter($ev['areas'], fn ($a) => $a['severity'] === 'very_serious' && $a['result'] === 'fails'), 'name'));
            Notify::users(Notify::roles(['sst_manager', 'management']), 'sun.very_serious_gap', 'SUN', $id, 'Brecha muy grave en el autodiagnóstico',
                "El autodiagnóstico SUNAFIL encontró incumplimientos de infracción muy grave: {$names}.", '/m/sun', 'high', 'sun.very_serious_gap:'.$id, null, true);
        }

        return response()->json(['data' => ['id' => $id] + $ev], 201);
    }

    public function monthly(): JsonResponse
    {
        $rows = DB::select("select distinct on (date_trunc('month', diagnostic_on)) to_char(diagnostic_on, 'YYYY-MM') as month, id, diagnostic_on, score_pct, critical_gaps,
                                   (items->>'risk_weighted_score')::numeric as weighted
                              from self_diagnostics where deleted_at is null and status = 'completed' and diagnostic_on >= (current_date - interval '11 months')::date
                             order by date_trunc('month', diagnostic_on), diagnostic_on desc, created_at desc");

        return response()->json(['data' => array_map(fn ($r) => ['month' => $r->month, 'id' => $r->id, 'date' => $r->diagnostic_on,
            'score' => $r->score_pct === null ? null : (int) round((float) $r->score_pct), 'weighted' => $r->weighted === null ? null : (int) round((float) $r->weighted),
            'very_serious_gaps' => (int) $r->critical_gaps], $rows)]);
    }

    public function show(string $id): JsonResponse
    {
        $d = Str::isUuid($id) ? DB::selectOne('select s.*, u.full_name as performed_by_name from self_diagnostics s left join users u on u.id = s.performed_by where s.id = ? and s.deleted_at is null', [$id]) : null;
        if (! $d) {
            throw ApiProblem::notFound('El autodiagnóstico no existe.');
        }
        $c = DB::selectOne('select legal_name, ruc from companies where id = ?', [$d->company_id]);

        return response()->json(['data' => ['id' => $d->id, 'title' => $d->title, 'date' => $d->diagnostic_on, 'checklist_version' => $d->checklist_version,
            'performed_by' => $d->performed_by_name, 'company' => ['name' => $c->legal_name, 'ruc' => trim((string) $c->ruc)]] + (json_decode($d->items, true) ?: [])]);
    }
}
