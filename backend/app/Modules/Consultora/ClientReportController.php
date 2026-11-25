<?php

namespace App\Modules\Consultora;

use App\Core\Http\ApiProblem;
use App\Core\Support\Sectors;
use App\Core\Support\Xlsx;
use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\DbContext;
use App\Http\Controllers\Controller;
use App\Modules\DSH\Services\KpiService;
use App\Modules\SUN\DiagnosisController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Exportación por cliente para el informe mensual de la consultora (M-CON §11):
 *  GET /con/clients/{company}/report?month=AAAA-MM        datos del informe (el PDF se imprime en /imprimir/informe-cliente/{id})
 *  GET /con/clients/{company}/report.xlsx?month=AAAA-MM   el mismo informe en Excel
 *
 * De una empresa cliente con asignación vigente (RN-CON-01): datos de la empresa, cumplimiento frente a una inspección
 * (autodiagnóstico SUNAFIL por obligación), indicadores del año al cierre del mes, actividad del mes (inspecciones, charlas,
 * capacitaciones, reuniones del comité, eventos, reportes y acciones), alertas abiertas y vencimientos de los próximos 30 días.
 * Solo conteos e indicadores: nunca datos personales ni de salud (RN-CON-07). Se calcula con el contexto RLS de esa empresa.
 * La descarga queda en la bitácora de exportaciones (AuditExports: …/report y .xlsx).
 */
class ClientReportController extends Controller
{
    public function show(Request $request, string $company): JsonResponse
    {
        return response()->json(['data' => $this->build($request, $company)]);
    }

    public function xlsx(Request $request, string $company): Response
    {
        $d = $this->build($request, $company);
        $rows = [["Informe mensual de {$d['company']['name']} · {$d['month_label']}"], ["{$d['consultancy']} · RUC {$d['company']['ruc']} · generado el ".date('d/m/Y')], [],
            ['Cumplimiento frente a una inspección SUNAFIL', $d['compliance']['score'] === null ? 'Sin datos' : $d['compliance']['score'].' de 100'], [],
            ['Sección', 'Concepto', 'Valor', 'Detalle']];
        foreach ($d['compliance']['areas'] as $a) {
            $rows[] = ['Obligaciones SUNAFIL', $a['name'], $a['pct'] === null ? 'No aplica' : $a['pct'].' %', $a['result_label'].($a['detail'] ? ' · '.$a['detail'] : '')];
        }
        foreach ($d['activity'] as $a) {
            $rows[] = ['Actividad del mes', $a['label'], $a['value'], ''];
        }
        foreach ($d['indicators'] as $k) {
            $rows[] = ['Indicadores del año', $k['name'], $k['value'] === null ? '—' : $k['value'], $k['unit'] ?? ''];
        }
        foreach ($d['alerts'] as $a) {
            $rows[] = ['Alertas abiertas', $a['text'], $a['count'], $a['tone'] === 'bad' ? 'Crítica' : 'Por atender'];
        }
        foreach ($d['agenda'] as $a) {
            $rows[] = ['Próximos 30 días', $a['kind'].': '.$a['text'], $a['date'], ''];
        }
        $name = 'informe-'.Str::slug($d['company']['name']).'-'.$d['month'].'.xlsx';

        return response(Xlsx::build('Informe', $rows, 6), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }

    private function build(Request $request, string $company): array
    {
        $p = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']], ['month.regex' => 'El mes va como AAAA-MM.']);
        $con = Consultancy::resolve();
        $m = Str::isUuid($company) ? collect($con->clients)->firstWhere('company_id', $company) : null;
        if (! $m) {
            throw ApiProblem::notFound('La empresa no es cliente de tu consultora.');
        }
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $month = $p['month'] ?? substr($today, 0, 7);
        $from = $month.'-01';
        $last = date('Y-m-t', strtotime($from));
        if ($from > $today) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el mes', 'El informe es de un mes ya iniciado.', ['errors' => ['month' => ['Elige el mes actual o uno anterior.']]]);
        }
        $to = min($last, $today);
        $active = app(CompanyContext::class)->companyId;
        try {
            DbContext::setCompany($m->company_id);
            // RN-CON-01: un consultor solo ve las empresas con asignación vigente.
            if ($m->role_code === 'consultant' && ! DB::selectOne("select 1 as ok from consultant_assignments where status = 'active' and deleted_at is null and consultant_user_id = ?
                    and starts_on <= current_date and (ends_on is null or ends_on >= current_date)", [(string) auth()->id()])) {
                throw ApiProblem::forbidden('No tienes una asignación vigente en esta empresa.');
            }
            $svc = new KpiService;
            $indicators = [];
            foreach ($svc->overview((int) substr($month, 0, 4), null, $to) as $k) {
                if (! ($k['locked'] ?? false)) {
                    $indicators[] = ['code' => $k['code'], 'name' => $k['name'], 'unit' => $k['unit'] ?? null,
                        'value' => isset($k['value']) && is_numeric($k['value']) ? round((float) $k['value'], 2) : null, 'status' => $k['status'] ?? null];
                }
            }
            $alerts = array_map(fn ($a) => ['text' => $a['text'], 'count' => (int) $a['count'], 'tone' => $a['tone'], 'module' => $a['module']], $svc->alerts());
            try {
                $diag = app(DiagnosisController::class)->evaluate();
            } catch (Throwable) {
                $diag = ['score' => null, 'areas' => []];
            }
            $result = ['complies' => 'Cumple', 'partial' => 'Parcial', 'fails' => 'No cumple', 'na' => 'No aplica'];
            $areas = array_map(fn ($a) => ['name' => $a['name'], 'pct' => $a['pct'], 'result' => $a['result'], 'result_label' => $result[$a['result']] ?? $a['result'],
                'severity' => $a['severity_label'] ?? null, 'detail' => $a['no_module'] ?? false ? 'Sin módulo: evaluada con documentos' : null], $diag['areas'] ?? []);
            $activity = $this->activity($from, $to);
            $until = date('Y-m-d', strtotime($today.' +30 days'));
            $agenda = app(PanelController::class)->agenda($today, $until);
            $workers = $svc->counts()['workers'];
        } finally {
            DbContext::setCompany($active);
        }
        $info = DB::connection('platform')->selectOne('select economic_sector, sst_model from companies where id = ?', [$m->company_id]);
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];

        return [
            'consultancy' => $con->name,
            'company' => ['id' => $m->company_id, 'name' => $m->company_name, 'ruc' => $m->ruc, 'sector' => Sectors::label($info?->economic_sector),
                'sst_model' => $info?->sst_model, 'workers' => $workers],
            'month' => $month, 'month_label' => $months[(int) substr($month, 5, 2) - 1].' de '.substr($month, 0, 4), 'from' => $from, 'to' => $to,
            'compliance' => ['score' => $diag['score'] ?? null, 'risk_weighted_score' => $diag['risk_weighted_score'] ?? null, 'areas' => $areas],
            'indicators' => $indicators,
            'activity' => $activity,
            'alerts' => $alerts,
            'agenda' => $agenda,
        ];
    }

    /** Actividad del mes en números (sin datos de personas). */
    private function activity(string $from, string $to): array
    {
        $d = "(%s at time zone 'America/Lima')::date between ?::date and ?::date";
        $q = [
            ['inspections_done', 'Inspecciones realizadas', 'select count(*) as n from inspections where deleted_at is null and status in (\'completed\', \'closed\') and '.sprintf($d, 'completed_at')],
            ['talks_held', 'Charlas de 5 minutos', "select count(*) as n from safety_talks where deleted_at is null and status = 'held' and talk_on between ?::date and ?::date"],
            ['trainings_held', 'Capacitaciones dictadas', "select count(*) as n from training_sessions where deleted_at is null and status = 'held' and ".sprintf($d, 'starts_at')],
            ['committee_meetings', 'Reuniones del comité realizadas', "select count(*) as n from committee_meetings where deleted_at is null and status in ('held', 'minutes_draft', 'minutes_signed') and ".sprintf($d, 'held_at')],
            ['accidents', 'Accidentes', "select count(*) as n from sst_events where deleted_at is null and status <> 'discarded' and event_type in ('fatal_accident', 'disabling_accident', 'minor_accident') and ".sprintf($d, 'occurred_at')],
            ['incidents', 'Incidentes', "select count(*) as n from sst_events where deleted_at is null and status <> 'discarded' and event_type in ('dangerous_incident', 'incident') and ".sprintf($d, 'occurred_at')],
            ['occupational_diseases', 'Enfermedades ocupacionales', "select count(*) as n from sst_events where deleted_at is null and status <> 'discarded' and event_type = 'occupational_disease' and ".sprintf($d, 'occurred_at')],
            ['substandard_reports', 'Reportes de actos y condiciones', 'select count(*) as n from substandard_reports where deleted_at is null and '.sprintf($d, 'reported_at')],
            ['actions_opened', 'Acciones correctivas abiertas en el mes', 'select count(*) as n from corrective_actions where deleted_at is null and '.sprintf($d, 'created_at')],
            ['actions_verified', 'Acciones correctivas verificadas', "select count(*) as n from corrective_actions where deleted_at is null and status = 'verified' and ".sprintf($d, 'verified_at')],
            ['actions_overdue', 'Acciones vencidas al cierre', "select count(*) as n from corrective_actions where deleted_at is null and status not in ('verified', 'cancelled') and due_on < ?::date and ?::date is not null"],
        ];
        $out = [];
        foreach ($q as [$code, $label, $sql]) {
            try {
                $bind = $code === 'actions_overdue' ? [$to, $to] : [$from, $to];
                // Con punto de guardado: si una consulta falla no deja la transacción de la petición abortada.
                $out[] = ['code' => $code, 'label' => $label, 'value' => (int) DB::transaction(fn () => DB::selectOne($sql, $bind))->n];
            } catch (Throwable) {
                // tabla de un módulo que la empresa no tiene: no se informa
            }
        }

        return $out;
    }
}
