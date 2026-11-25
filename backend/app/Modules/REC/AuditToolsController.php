<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Auth\Scope;
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
 *                                           SCR-AUI-02: cada requisito del autodiagnóstico con su módulo, su resultado, enlace a la lista de
 *                                           registros del módulo y los últimos registros que lo respaldan (solo si el rol los puede leer)
 *  GET  /aui/record-types                   RN-AUI-07: tipos de registro del sistema que se pueden vincular como evidencia (por módulo)
 *  GET  /aui/audits/{id}/checklist.xlsx     checklist de la auditoría con resultado, evidencia y registros vinculados
 *  POST /aui/audits/{id}/checklist          RN-AUI-07: precarga los ítems del checklist desde la plantilla de la norma (Ley 29783: las
 *                                           preguntas base de EscudoSST, las mismas de la línea base); no duplica ítems
 *  POST /aui/audits/{id}/auditor-access     flujo 1 y RN-AUI-02: invita al auditor externo con el rol «auditor externo» y acceso que vence
 *                                           al terminar la auditoría + auditor_access_grace_days; queda ligado a la auditoría.
 *                                           {modules?: ['AUI','DOC',…]} RN-AUI-08: módulos que verá (memberships.allowed_modules;
 *                                           vacío = todos los de su rol); el periodo de la auditoría queda en audit_period_from/to
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
                'critical_gaps' => json_decode((string) $diag->critical_gaps, true), 'requirements' => $this->requirements($diag->id)] : null]]);
    }

    /**
     * SCR-AUI-02: requisitos del autodiagnóstico SUNAFIL guardado (cada obligación con su módulo y tabla) enlazados a los registros
     * del sistema: la lista del módulo y los 5 registros más recientes de esa tabla, si el rol puede leerla.
     * @return list<array<string, mixed>>
     */
    private function requirements(string $diagnosticId): array
    {
        $items = json_decode((string) DB::selectOne('select items from self_diagnostics where id = ?', [$diagnosticId])?->items, true) ?: [];
        $perms = Permissions::effective();
        $res = Registry::data()['resources'];
        $out = [];
        foreach ($items['areas'] ?? [] as $a) {
            $table = $a['table'] ?? null;
            $module = (string) ($a['module'] ?? '');
            // Los datos de salud quedan solo como porcentaje agregado (M-SUN): sus registros no se listan aquí.
            $readable = $table && isset($res[$table]) && $res[$table]['module'] === $module && ! in_array($module, Permissions::MEDICAL_MODULES, true)
                && in_array(strtolower($module).".{$table}.read", $perms, true) && Scope::moduleAllowed($module);
            $records = [];
            $total = null;
            if ($readable) {
                $soft = Registry::softDelete($table) ? 'r.deleted_at is null' : 'true';
                $scope = Scope::andTable($table, 'r');
                $total = (int) DB::selectOne("select count(*) as n from \"{$table}\" r where {$soft}{$scope}")->n;
                foreach (DB::select('select r.id, ('.LinkedRecords::labelExpr($table, 'r').")::text as label from \"{$table}\" r where {$soft}{$scope} order by r.created_at desc limit 5") as $r) {
                    $records[] = ['id' => $r->id, 'label' => $r->label ?? $r->id, 'link' => LinkedRecords::link(['module' => $module, 'table' => $table, 'id' => $r->id])];
                }
            }
            $out[] = ['code' => $a['code'] ?? null, 'name' => $a['name'] ?? '', 'module' => $module, 'table' => $table, 'pct' => $a['pct'] ?? null, 'result' => $a['result'] ?? null,
                'severity_label' => $a['severity_label'] ?? null, 'detail' => $a['detail'] ?? null, 'readable' => $readable,
                'link' => $readable ? '/m/'.strtolower($module).'?t='.$table : null, 'records_total' => $total, 'records' => $records];
        }

        return $out;
    }

    public function recordTypes(): JsonResponse
    {
        $this->can('aui.audit_checklist_items.read');

        return response()->json(['data' => LinkedRecords::types()]);
    }

    /** Checklist de la auditoría con su evidencia: texto, archivo y registros del sistema vinculados (RN-AUI-07). */
    public function checklistXlsx(string $id): \Symfony\Component\HttpFoundation\Response
    {
        $this->can('aui.audit_checklist_items.read');
        $a = $this->audit($id);
        $options = collect(Registry::data()['resources']['audit_checklist_items']['fields'])->firstWhere('name', 'result')['options'] ?? [];
        $result = collect($options)->pluck('label', 'value')->all();
        $rows = DB::select('select i.clause_code, i.requirement, i.result, i.evidence, i.file_id, i.linked_records
                              from audit_checklist_items i where i.audit_id = ? and i.deleted_at is null order by i.sort_order, i.clause_code', [$a->id]);
        $titles = collect(Registry::data()['resources'])->map(fn ($r) => $r['title'] ?? '')->all();
        $out = [["Checklist de la auditoría {$a->code}"], ['Del '.($a->starts_on ? date('d/m/Y', strtotime($a->starts_on)) : '—').' al '.($a->ends_on ? date('d/m/Y', strtotime($a->ends_on)) : '—').'.'], [],
            ['Cláusula', 'Requisito', 'Resultado', 'Evidencia', 'Archivo adjunto', 'Registros vinculados']];
        foreach ($rows as $r) {
            $links = array_map(fn ($l) => trim(($titles[$l['table'] ?? ''] ?? '').': '.($l['label'] ?? '')), json_decode((string) $r->linked_records, true) ?: []);
            $out[] = [$r->clause_code ?? '', $r->requirement, $result[$r->result] ?? $r->result, $r->evidence ?? '', $r->file_id ? 'Sí' : '', implode(' | ', $links)];
        }
        if ($rows === []) {
            $out[] = ['Sin ítems en el checklist'];
        }

        return response(\App\Core\Support\Xlsx::build('Checklist', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"checklist-{$a->code}.xlsx\"",
        ]);
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
        // RN-AUI-08: módulos que verá el auditor (de los que existen; sin médicos, que su rol nunca ve).
        $modules = array_values(array_unique(array_map(fn ($m) => strtoupper(trim((string) $m)), array_filter((array) ($request->input('modules') ?? []), 'is_scalar'))));
        if ($modules !== []) {
            $known = array_column(DB::select('select trim(code) as code from modules where deleted_at is null and code = any(?::text[])', ['{'.implode(',', array_map(fn ($m) => preg_replace('/[^A-Z0-9]/', '', $m), $modules)).'}']), 'code');
            if (count($known) !== count($modules) || array_intersect($modules, Permissions::MEDICAL_MODULES) !== []) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Uno de los módulos no existe o no se puede dar al auditor externo.', ['errors' => ['modules' => ['Elige módulos válidos (sin datos médicos).']]]);
            }
        }
        $grace = (int) LegalParams::number('auditor_access_grace_days', 0);
        $expires = date('Y-m-d', strtotime($a->ends_on.' +'.$grace.' days'));
        $sub = Request::create('/', 'POST', ['email' => $request->input('email'), 'full_name' => $request->input('full_name'), 'role_code' => 'external_auditor', 'expires_on' => $expires]);
        $sub->setUserResolver(fn () => $request->user());
        $res = $users->store($sub);
        $membership = $res->getData(true)['data']['id'];
        DB::update('update audits set external_auditor_membership_id = ?, updated_by = ?, updated_at = now() where id = ?', [$membership, (string) auth()->id(), $a->id]);
        DB::update('update memberships set allowed_modules = ?::text[], audit_period_from = ?, audit_period_to = ?, updated_by = ?, updated_at = now() where id = ?',
            [$modules ? '{'.implode(',', $modules).'}' : null, $a->starts_on, $a->ends_on, (string) auth()->id(), $membership]);
        Permissions::flush();

        return response()->json(['data' => ['membership_id' => $membership, 'expires_on' => $expires, 'modules' => $modules ?: null]], 201);
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
