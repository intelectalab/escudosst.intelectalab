<?php

namespace App\Modules\INS\Http;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inspecciones (M-INS):
 *  POST /ins/templates/{id}/new-version   RN-INS-04: nueva versión en borrador de la plantilla con sus ítems; al activarla, la anterior
 *                                         se retira (las inspecciones pasadas conservan la versión que usaron)
 *  GET  /ins/dashboard?year=              tablero: inspecciones del año por área con % ejecutadas, puntaje promedio y críticas
 */
class InsToolsController extends Controller
{
    public function newVersion(string $id): JsonResponse
    {
        if (! in_array('ins.inspection_templates.create', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no crea plantillas de inspección.');
        }
        $t = Str::isUuid($id) ? DB::selectOne('select * from inspection_templates where id = ? and deleted_at is null', [$id]) : null;
        if (! $t) {
            throw ApiProblem::notFound('La plantilla no existe o es de otra empresa.');
        }
        if (DB::selectOne("select 1 as ok from inspection_templates where previous_template_id = ? and status = 'draft' and deleted_at is null", [$t->id])) {
            throw new ApiProblem(409, 'conflict', 'Ya hay una versión en borrador', 'Ya existe una versión nueva en borrador de esta plantilla.');
        }
        $uid = (string) auth()->id();

        return DB::transaction(function () use ($t, $uid) {
            $n = (int) DB::selectOne('select coalesce(max(version_number), 0) + 1 as n from inspection_templates where code = ?', [$t->code])->n;
            $new = DB::selectOne(
                "insert into inspection_templates (company_id, code, name, inspection_type, sector, sector_pack_id, version_number, description, scoring_method, requires_signature, status, previous_template_id, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?) returning id",
                [app(\App\Core\Tenancy\CompanyContext::class)->companyId, $t->code, $t->name, $t->inspection_type, $t->sector, $t->sector_pack_id, $n, $t->description, $t->scoring_method, $t->requires_signature, $t->id, $uid, $uid])->id;
            DB::insert(
                'insert into inspection_template_items (company_id, template_id, section, item_code, question, answer_type, weight, is_critical, requires_photo_on_fail, legal_reference, sort_order, created_by, updated_by)
                 select ?, ?, section, item_code, question, answer_type, weight, is_critical, requires_photo_on_fail, legal_reference, sort_order, ?, ?
                   from inspection_template_items where template_id = ? and deleted_at is null', [app(\App\Core\Tenancy\CompanyContext::class)->companyId, $new, $uid, $uid, $t->id]);

            return response()->json(['data' => ['id' => $new, 'version_number' => $n]], 201);
        });
    }

    public function dashboard(Request $request): JsonResponse
    {
        if (! in_array('ins.inspections.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve las inspecciones.');
        }
        $year = (int) ($request->query('year') ?: now('America/Lima')->year);
        $today = now('America/Lima')->toDateString();
        $rows = DB::select(
            "select coalesce(a.name, 'Sin área') as area, s.name as site,
                    count(*) filter (where i.status <> 'cancelled' and (i.scheduled_on is null or i.scheduled_on <= ?::date)) as due,
                    count(*) filter (where i.status in ('completed', 'signed', 'closed')) as done,
                    count(*) filter (where i.status = 'overdue' or (i.status in ('scheduled', 'in_progress') and i.scheduled_on < ?::date)) as overdue,
                    round(avg(i.score_pct) filter (where i.status in ('completed', 'signed', 'closed')), 1) as avg_score,
                    count(*) filter (where exists (select 1 from inspection_answers x where x.inspection_id = i.id and x.deleted_at is null and x.is_critical and x.is_compliant = false)) as critical
               from inspections i left join areas a on a.id = i.area_id left join sites s on s.id = i.site_id
              where i.deleted_at is null and extract(year from coalesce(i.scheduled_on, i.created_at::date)) = ?
              group by a.name, s.name order by s.name nulls last, a.name nulls last", [$today, $today, $year]);
        foreach ($rows as $r) {
            $r->due = (int) $r->due;
            $r->done = (int) $r->done;
            $r->pct = $r->due ? (int) round(min($r->done, $r->due) * 100 / $r->due) : null;
            $r->avg_score = $r->avg_score === null ? null : (float) $r->avg_score;
        }
        $due = array_sum(array_column($rows, 'due'));
        $done = array_sum(array_column($rows, 'done'));
        $scores = array_filter(array_column($rows, 'avg_score'), fn ($x) => $x !== null);

        return response()->json(['data' => ['year' => $year, 'areas' => $rows, 'total' => ['due' => $due, 'done' => $done, 'pct' => $due ? (int) round(min($done, $due) * 100 / $due) : null,
            'avg_score' => $scores ? round(array_sum($scores) / count($scores), 1) : null, 'critical' => array_sum(array_map('intval', array_column($rows, 'critical')))]]]);
    }
}
