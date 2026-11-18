<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use App\Modules\USR\Http\Controllers\CompanyUsersController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Auditorías (M-AUI):
 *  GET  /aui/calendar?year=                 SCR-AUI-01/02: auditorías del año por mes con su estado y la preparación: brechas del último
 *                                           autodiagnóstico SUNAFIL (M-SUN: puntaje y brechas críticas) para revisar antes de la auditoría
 *  POST /aui/audits/{id}/checklist          RN-AUI-07: precarga los ítems del checklist desde la plantilla de la norma (Ley 29783: las
 *                                           preguntas base de EscudoSST, las mismas de la línea base); no duplica ítems
 *  POST /aui/audits/{id}/auditor-access     flujo 1 y RN-AUI-02: invita al auditor externo con el rol «auditor externo» y acceso que vence
 *                                           al terminar la auditoría + auditor_access_grace_days; queda ligado a la auditoría
 */
class AuditToolsController extends Controller
{
    public function calendar(Request $request): JsonResponse
    {
        $this->can('aui.audits.read');
        $year = (int) $request->query('year', now('America/Lima')->format('Y'));
        $audits = DB::select(
            "select a.id, a.code, a.audit_type, a.standard, a.starts_on, a.ends_on, a.status, a.lead_auditor_name, s.name as site,
                    (select count(*) from audit_findings f where f.audit_id = a.id and f.deleted_at is null and f.finding_type in ('major_nc', 'minor_nc')) as ncs
               from audits a left join sites s on s.id = a.site_id
              where a.deleted_at is null and extract(year from a.starts_on) = ? order by a.starts_on", [$year]);
        $diag = DB::selectOne("select id, title, diagnostic_on, score_pct, critical_gaps from self_diagnostics where deleted_at is null and status = 'completed' order by diagnostic_on desc limit 1");

        return response()->json(['data' => ['year' => $year, 'audits' => $audits,
            'preparation' => $diag ? ['id' => $diag->id, 'title' => $diag->title, 'diagnostic_on' => $diag->diagnostic_on, 'score_pct' => $diag->score_pct === null ? null : (float) $diag->score_pct,
                'critical_gaps' => json_decode((string) $diag->critical_gaps, true)] : null]]);
    }

    public function loadChecklist(string $id): JsonResponse
    {
        $this->can('aui.audit_checklist_items.create');
        $a = $this->audit($id);
        if (in_array($a->status, ['completed', 'closed', 'cancelled'], true)) {
            throw new ApiProblem(409, 'conflict', 'No se modifica', 'La auditoría ya está completada o cerrada.');
        }
        if (! in_array($a->standard, ['ley_29783', 'integrated'], true)) {
            throw new ApiProblem(422, 'validation_failed', 'Sin plantilla', 'Solo hay plantilla para la Ley 29783; para otras normas agrega los ítems a mano.',
                ['errors' => ['standard' => ['Solo hay plantilla para la Ley 29783; para otras normas agrega los ítems a mano.']]]);
        }
        $questions = DB::connection('platform')->select(
            "select q.section, q.question_text, q.legal_reference, q.sort_order from assessment_questions q join assessment_questionnaires n on n.id = q.questionnaire_id
              where q.deleted_at is null and n.deleted_at is null
                and n.id = (select id from assessment_questionnaires where deleted_at is null order by (status = 'published') desc, version desc limit 1)
              order by q.sort_order");
        $added = 0;
        $sections = array_values(array_unique(array_column($questions, 'section')));
        foreach ($questions as $q) {
            $clause = sprintf('L%02d', array_search($q->section, $sections, true) + 1);
            $req = $q->question_text.($q->legal_reference ? " ({$q->legal_reference})" : '');
            if (DB::selectOne('select 1 as ok from audit_checklist_items where audit_id = ? and requirement = ? and deleted_at is null', [$a->id, $req])) {
                continue;
            }
            DB::insert('insert into audit_checklist_items (company_id, audit_id, clause_code, requirement, sort_order, created_by, updated_by) values (?, ?, ?, ?, ?, ?, ?)',
                [$a->company_id, $a->id, $clause, $req, (int) $q->sort_order, (string) auth()->id(), (string) auth()->id()]);
            $added++;
        }

        return response()->json(['data' => ['added' => $added]]);
    }

    public function auditorAccess(Request $request, string $id, CompanyUsersController $users): JsonResponse
    {
        $this->can('aui.audits.update');
        $a = $this->audit($id);
        if (! $a->ends_on) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Indica la fecha de fin de la auditoría: de ella depende cuándo vence el acceso.', ['errors' => ['ends_on' => ['Indica la fecha de fin de la auditoría.']]]);
        }
        $grace = (int) LegalParams::number('auditor_access_grace_days', 0);
        $expires = date('Y-m-d', strtotime($a->ends_on.' +'.$grace.' days'));
        $sub = Request::create('/', 'POST', ['email' => $request->input('email'), 'full_name' => $request->input('full_name'), 'role_code' => 'external_auditor', 'expires_on' => $expires]);
        $sub->setUserResolver(fn () => $request->user());
        $res = $users->store($sub);
        $membership = $res->getData(true)['data']['id'];
        DB::update('update audits set external_auditor_membership_id = ?, updated_by = ?, updated_at = now() where id = ?', [$membership, (string) auth()->id(), $a->id]);

        return response()->json(['data' => ['membership_id' => $membership, 'expires_on' => $expires]], 201);
    }

    /** GET /aui/findings.xlsx?year= — M-AUI §11: hallazgos del año por cláusula y tipo, con cuántos siguen abiertos. */
    public function findingsByClause(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->can('aui.audit_findings.read');
        $year = (int) $request->query('year', now('America/Lima')->format('Y'));
        $rows = DB::select(
            "select coalesce(nullif(trim(f.clause_code), ''), nullif(trim(i.clause_code), ''), 'Sin cláusula') as clause,
                    count(*) filter (where f.finding_type = 'major_nc') as major, count(*) filter (where f.finding_type = 'minor_nc') as minor,
                    count(*) filter (where f.finding_type = 'observation') as obs, count(*) filter (where f.finding_type = 'opportunity') as opp,
                    count(*) filter (where f.status in ('open', 'action_assigned')) as open, count(*) as total
               from audit_findings f join audits a on a.id = f.audit_id and a.deleted_at is null left join audit_checklist_items i on i.id = f.checklist_item_id
              where f.deleted_at is null and extract(year from a.starts_on) = ? group by 1 order by 1", [$year]);
        $out = [['Hallazgos de auditoría por cláusula'], ["Auditorías del {$year}."], [],
            ['Cláusula', 'No conformidad mayor', 'No conformidad menor', 'Observación', 'Oportunidad de mejora', 'Abiertos', 'Total']];
        foreach ($rows as $r) {
            $out[] = [$r->clause, (int) $r->major, (int) $r->minor, (int) $r->obs, (int) $r->opp, (int) $r->open, (int) $r->total];
        }
        if ($rows === []) {
            $out[] = ['Sin hallazgos en el año'];
        }

        return response(\App\Core\Support\Xlsx::build('Hallazgos', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"hallazgos-por-clausula-{$year}.xlsx\"",
        ]);
    }

    private function audit(string $id): object
    {
        return (Str::isUuid($id) ? DB::selectOne('select * from audits where id = ? and deleted_at is null', [$id]) : null) ?? throw ApiProblem::notFound('La auditoría no existe.');
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto con las auditorías.');
        }
    }
}
