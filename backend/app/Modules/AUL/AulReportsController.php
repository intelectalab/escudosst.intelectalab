<?php

namespace App\Modules\AUL;

use App\Core\Auth\Scope;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reportes del aula (M-AUL §11):
 *  GET /aul/admin/reports/progress?by=area|position&course_id=      avance y aprobados vs desaprobados por curso y área o cargo
 *  GET /aul/admin/reports/progress.xlsx?by=area|position&course_id= lo mismo en Excel
 *  GET /aul/admin/reports/conversion.xlsx                           embudo de conversión de recomendaciones por fuente, regla, curso,
 *                                                                   canal, área y empresa compradora, con tasa de matrícula o compra y descartes
 * El área y el cargo son los actuales del trabajador; los participantes externos y de contratistas van en su propio grupo.
 * El capacitador solo ve sus cursos; el alcance por sede o área de la membresía se aplica sobre el trabajador.
 */
class AulReportsController extends Controller
{
    private const LEARNERS = ['worker', 'external_learner'];

    public function progress(Request $request): JsonResponse
    {
        [$by, $rows] = $this->progressRows($request);

        return response()->json(['data' => ['by' => $by, 'rows' => $rows]]);
    }

    public function progressXlsx(Request $request): Response
    {
        [$by, $rows] = $this->progressRows($request);
        $group = $by === 'area' ? 'Área' : 'Cargo';
        $out = [["Avance y aprobados del aula por curso y {$group}"], ['Área y cargo actuales del trabajador; participantes externos y de contratistas en su propio grupo.'], [],
            ['Curso', $group, 'Matriculados', 'Sin iniciar', 'En curso', 'Aprobados', 'Desaprobados', 'Vencidos', 'Avance promedio (%)', 'Aprobados (%)']];
        foreach ($rows as $r) {
            $out[] = [$r['course'], $r['group'], $r['enrolled'], $r['not_started'], $r['in_progress'], $r['passed'], $r['failed'], $r['expired'], $r['avg_progress_pct'], $r['passed_pct'] ?? ''];
        }
        if ($rows === []) {
            $out[] = ['Sin matrículas'];
        }

        return response(Xlsx::build('Avance', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="aula-avance-por-'.($by === 'area' ? 'area' : 'cargo').'.xlsx"',
        ]);
    }

    public function conversionXlsx(): Response
    {
        $this->staff();
        $titles = ['source' => 'Por fuente', 'rule' => 'Por regla', 'course' => 'Por curso', 'channel' => 'Por canal', 'area' => 'Por área', 'buyer_company' => 'Por empresa compradora'];
        $out = [['Conversión de recomendaciones de cursos'], ['Tasa = convertidas (matrícula o compra) / enviadas.'], []];
        foreach (AulAdminController::funnelBreakdown() as $dim => $rows) {
            $out[] = [$titles[$dim]];
            $out[] = ['Grupo', 'Total', 'Por aprobar', 'Enviadas', 'Vistas', 'Con clic', 'Convertidas', 'Por matrícula', 'Por compra', 'Descartadas', 'Tasa (%)'];
            foreach ($rows as $r) {
                $out[] = [$r['label'], $r['total'], $r['suggested'], $r['sent'], $r['viewed'], $r['clicked'], $r['converted'], $r['enrolled'], $r['purchased'], $r['dismissed'], $r['conversion_pct'] ?? ''];
            }
            if ($rows === []) {
                $out[] = ['Sin recomendaciones'];
            }
            $out[] = [];
        }

        return response(Xlsx::build('Conversión', $out, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="aula-conversion-recomendaciones.xlsx"',
        ]);
    }

    /** @return array{0: string, 1: list<array<string, mixed>>} */
    private function progressRows(Request $request): array
    {
        $this->staff();
        $d = $request->validate(['by' => ['nullable', 'in:area,position'], 'course_id' => ['nullable', 'uuid']], ['by.in' => 'Agrupa por área o por cargo.']);
        $by = $d['by'] ?? 'area';
        [$gid, $gname] = $by === 'area' ? ['w.current_area_id', 'a.name'] : ['w.current_position_id', 'p.name'];
        $where = 'e.deleted_at is null and c.deleted_at is null and e.status <> \'cancelled\'';
        $bind = [];
        if (! empty($d['course_id'])) {
            $where .= ' and e.course_id = ?';
            $bind[] = $d['course_id'];
        }
        if (app(CompanyContext::class)->roleCode === 'trainer') {
            $where .= ' and exists (select 1 from trainer_permissions tp where tp.course_id = c.id and tp.trainer_user_id = ? and tp.deleted_at is null)';
            $bind[] = (string) auth()->id();
        }
        if ($scope = Scope::sql('w.current_site_id', 'w.current_area_id')) {
            $where .= ' and ('.$scope[0].')';
            array_push($bind, ...$scope[1]);
        }
        $rows = DB::select(
            "select c.id as course_id, concat_ws(' · ', c.code, c.title) as course, {$gid}::text as group_id,
                    case when e.worker_id is null then case when e.contractor_worker_id is not null then 'Trabajadores de contratistas' else 'Participantes externos' end
                         else coalesce({$gname}, '".($by === 'area' ? 'Sin área' : 'Sin cargo')."') end as grp,
                    count(*) as enrolled, count(*) filter (where e.status = 'enrolled') as not_started, count(*) filter (where e.status = 'in_progress') as in_progress,
                    count(*) filter (where e.status = 'completed') as passed, count(*) filter (where e.status = 'failed') as failed,
                    count(*) filter (where e.status = 'expired') as expired, round(avg(coalesce(e.progress_pct, 0)), 1) as avg_progress
               from course_enrollments e join courses c on c.id = e.course_id
               left join workers w on w.id = e.worker_id left join areas a on a.id = w.current_area_id left join positions p on p.id = w.current_position_id
              where {$where}
              group by c.id, c.code, c.title, 3, 4 order by c.code, 4", $bind);

        return [$by, array_map(function ($r) {
            $closed = (int) $r->passed + (int) $r->failed;

            return ['course_id' => $r->course_id, 'course' => $r->course, 'group_id' => $r->group_id, 'group' => $r->grp, 'enrolled' => (int) $r->enrolled,
                'not_started' => (int) $r->not_started, 'in_progress' => (int) $r->in_progress, 'passed' => (int) $r->passed, 'failed' => (int) $r->failed,
                'expired' => (int) $r->expired, 'avg_progress_pct' => (float) $r->avg_progress, 'passed_pct' => $closed > 0 ? round((int) $r->passed * 100 / $closed, 1) : null];
        }, $rows)];
    }

    private function staff(): void
    {
        if (in_array(app(CompanyContext::class)->roleCode, self::LEARNERS, true)) {
            throw ApiProblem::forbidden('Tus cursos están en «Mis cursos».');
        }
    }
}
