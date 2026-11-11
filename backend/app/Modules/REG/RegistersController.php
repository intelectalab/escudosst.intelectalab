<?php

namespace App\Modules\REG;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\Registry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * GET /reg/{type}.xlsx?from=AAAA-MM-DD&to=AAAA-MM-DD — registros obligatorios del SGSST (R.M. 050-2013-TR) armados con
 * los datos de los módulos, en Excel. Cada descarga queda en register_exports (quién, qué periodo, cuántas filas).
 * Los registros de salud solo muestran la aptitud (nunca diagnósticos).
 */
class RegistersController extends Controller
{
    public const TYPES = [
        'accidents' => 'Registro de accidentes de trabajo',
        'occupational_diseases' => 'Registro de enfermedades ocupacionales',
        'incidents' => 'Registro de incidentes peligrosos e incidentes',
        'emo' => 'Registro de exámenes médicos ocupacionales (aptitud)',
        'monitoring' => 'Registro de monitoreo de agentes',
        'inspections' => 'Registro de inspecciones internas de SST',
        'statistics' => 'Registro de estadísticas de SST',
        'emergency_equipment' => 'Registro de equipos de seguridad o emergencia',
        'training' => 'Registro de inducción, capacitación, entrenamiento y simulacros',
        'audits' => 'Registro de auditorías',
    ];

    public function index(): \Illuminate\Http\JsonResponse
    {
        $this->can('reg.register_exports.read');

        return response()->json(['data' => array_map(fn ($k, $v) => ['type' => $k, 'name' => $v], array_keys(self::TYPES), self::TYPES)]);
    }

    /**
     * GET /reg/{type}/preview?from=&to= — vista previa del registro (SCR-REG-02): cabecera de la empresa, columnas y las
     * primeras filas del mismo armado que el Excel. No deja constancia en el historial (solo la descarga la deja).
     */
    public function preview(Request $request, string $type): \Illuminate\Http\JsonResponse
    {
        $this->can('reg.register_exports.read');
        if (! isset(self::TYPES[$type])) {
            throw ApiProblem::notFound('Ese registro no existe.');
        }
        $from = (string) $request->query('from', date('Y').'-01-01');
        $to = (string) $request->query('to', date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            throw ApiProblem::badRequest('Elige un periodo válido.');
        }
        $limit = max(1, min(500, (int) $request->query('limit', 10)));
        [$header, $rows] = $this->data($type, $from, $to);
        $company = DB::selectOne('select legal_name, ruc, fiscal_address, economic_sector from companies where id = ?', [app(CompanyContext::class)->companyId]);
        $workers = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active','on_leave')")->n;

        return response()->json(['data' => [
            'type' => $type,
            'title' => self::TYPES[$type],
            'company' => ['legal_name' => $company->legal_name, 'ruc' => trim((string) $company->ruc), 'address' => $company->fiscal_address,
                'activity' => $company->economic_sector, 'workers' => $workers],
            'period' => ['from' => $from, 'to' => $to],
            'header' => $header,
            'rows' => array_slice($rows, 0, $limit),
            'total' => count($rows),
        ]]);
    }

    public function export(Request $request, string $type): Response
    {
        $this->can('reg.register_exports.export');
        if (! isset(self::TYPES[$type])) {
            throw ApiProblem::notFound('Ese registro no existe.');
        }
        $from = (string) $request->query('from', date('Y').'-01-01');
        $to = (string) $request->query('to', date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            throw ApiProblem::badRequest('Elige un periodo válido.');
        }
        [$header, $rows] = $this->data($type, $from, $to);
        $company = DB::selectOne('select legal_name, ruc from companies where id = ?', [app(CompanyContext::class)->companyId]);
        $sheet = [
            [mb_strtoupper(self::TYPES[$type])],
            ["{$company->legal_name} · RUC ".trim($company->ruc)],
            ['Periodo: '.date('d/m/Y', strtotime($from)).' al '.date('d/m/Y', strtotime($to)).' · Generado: '.now('America/Lima')->format('d/m/Y H:i')],
            [],
            $header,
            ...$rows,
        ];
        $bytes = Xlsx::build('Registro', $sheet, 5);
        DB::insert(
            "insert into register_exports (company_id, register_type, period_from, period_to, format, template_version, status, sha256, rows_count,
                requested_by, requested_at, generated_at, created_by, updated_by)
             values (?, ?, ?, ?, 'xlsx', 'rm050-v1', 'ready', ?, ?, ?, now(), now(), ?, ?)",
            [app(CompanyContext::class)->companyId, $type, $from, $to, hash('sha256', $bytes), count($rows), (string) auth()->id(), (string) auth()->id(), (string) auth()->id()]
        );

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"registro-{$type}-{$from}-{$to}.xlsx\"",
        ]);
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private function data(string $type, string $from, string $to): array
    {
        $d = fn ($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '';
        $dt = fn ($v) => $v ? (new \DateTimeImmutable((string) $v))->setTimezone(new \DateTimeZone('America/Lima'))->format('d/m/Y H:i') : '';
        $range = [$from, $to];
        switch ($type) {
            case 'accidents':
            case 'incidents':
            case 'occupational_diseases':
                $types = ['accidents' => ['fatal_accident', 'disabling_accident', 'minor_accident'], 'incidents' => ['dangerous_incident', 'incident'],
                    'occupational_diseases' => ['occupational_disease']][$type];
                $rows = DB::select(
                    "select e.code, e.event_type, e.occurred_at, s.name as site, a.name as area, e.description, e.lost_days, e.injured_count,
                            e.notification_deadline_at, e.status,
                            (select string_agg(coalesce(w.full_name, p.external_person_name, '') || ' (' || p.person_role || coalesce(', ' || p.body_part, '') || ')', '; ')
                               from event_people p left join workers w on w.id = p.worker_id where p.event_id = e.id and p.deleted_at is null) as people,
                            (select string_agg(n.notification_number || ' ' || to_char(n.notified_at at time zone 'America/Lima', 'DD/MM/YYYY'), '; ')
                               from event_notifications n where n.event_id = e.id and n.status in ('sent','acknowledged') and n.deleted_at is null) as notified,
                            (select i.code from investigations i where i.event_id = e.id and i.deleted_at is null limit 1) as investigation,
                            (select string_agg(concat_ws(' · ', w.full_name, '****' || w.document_last4, ps.name, p.disability_type), '; ')
                               from event_people p join workers w on w.id = p.worker_id left join positions ps on ps.id = w.current_position_id
                              where p.event_id = e.id and p.deleted_at is null and p.person_role in ('injured','affected','ill')) as workers,
                            (select string_agg(c.cause_level || ': ' || c.description, '; ' order by c.cause_level)
                               from investigation_causes c join investigations i on i.id = c.investigation_id
                              where i.event_id = e.id and i.deleted_at is null and c.deleted_at is null) as causes,
                            (select string_agg(concat_ws(' · ', ca.code, ca.title, coalesce(u.full_name, wr.full_name), to_char(ca.due_on, 'DD/MM/YYYY'), ca.status), '; ')
                               from corrective_actions ca left join users u on u.id = ca.responsible_user_id left join workers wr on wr.id = ca.responsible_worker_id
                              where ca.deleted_at is null and ca.status <> 'cancelled' and (ca.source_id = e.id or ca.source_id in (select i.id from investigations i where i.event_id = e.id))) as actions
                       from sst_events e left join sites s on s.id = e.site_id left join areas a on a.id = e.area_id
                      where e.deleted_at is null and e.status <> 'discarded' and e.event_type = any(?::text[])
                        and (e.occurred_at at time zone 'America/Lima')::date between ? and ? order by e.occurred_at",
                    ['{'.implode(',', $types).'}', ...$range]
                );

                $causeLevels = fn (?string $t) => $t ? strtr($t, ['immediate:' => 'Inmediata:', 'basic:' => 'Básica:', 'root:' => 'Raíz:', 'lack_of_control:' => 'Falta de control:']) : '';
                $disability = fn (?string $t) => $t ? strtr($t, ['total_temporary' => 'Total temporal', 'partial_temporary' => 'Parcial temporal', 'partial_permanent' => 'Parcial permanente',
                    'total_permanent' => 'Total permanente', ' · none' => '']) : '';
                $status = ['pending_verification' => 'por verificar', 'in_progress' => 'en curso', 'verified' => 'verificada', 'open' => 'abierta', 'overdue' => 'vencida'];

                return [['Código', 'Tipo', 'Fecha y hora', 'Sede', 'Área', 'Descripción', 'Trabajador(es) afectado(s) (DNI, puesto, incapacidad)', 'Personas involucradas',
                    'Días perdidos', 'Lesionados', 'Plazo de notificación', 'Notificado (n.° y fecha)', 'Investigación', 'Causas', 'Medidas correctivas (responsable, fecha, estado)', 'Estado'],
                    array_map(fn ($r) => [$r->code, $this->label('sst_events', 'event_type', $r->event_type).($r->event_type === 'fatal_accident' ? ' (MORTAL)' : ''), $dt($r->occurred_at),
                        $r->site, $r->area, $r->description, $disability($r->workers), $this->roles($r->people), (int) $r->lost_days, (int) $r->injured_count,
                        $dt($r->notification_deadline_at), $r->notified, $r->investigation, $causeLevels($r->causes), $r->actions ? strtr($r->actions, $status) : '',
                        $this->label('sst_events', 'status', $r->status)], $rows)];
            case 'emo':
                $rows = DB::select(
                    "select w.full_name, w.document_last4, p.name as position, f.status, f.valid_from, f.valid_until, f.issued_at
                       from fitness_status f join workers w on w.id = f.worker_id left join positions p on p.id = w.current_position_id
                      where f.deleted_at is null and coalesce(f.valid_from, f.issued_at::date) between ? and ? order by w.full_name", $range);

                return [['Trabajador', 'Documento (últimos 4)', 'Puesto', 'Aptitud', 'Vigente desde', 'Vigente hasta', 'Emitido'],
                    array_map(fn ($r) => [$r->full_name, $r->document_last4, $r->position, $this->label('fitness_status', 'status', $r->status), $d($r->valid_from), $d($r->valid_until), $dt($r->issued_at)], $rows)];
            case 'monitoring':
                $rows = DB::select(
                    "select m.measured_at, t.name as agent, pt.name as point, m.value, m.unit, l.limit_value, l.unit as limit_unit, m.result, m.equipment, m.equipment_calibration_on
                       from measurements m left join agent_types t on t.id = m.agent_type_id left join monitoring_points pt on pt.id = m.monitoring_point_id
                       left join exposure_limits l on l.id = m.exposure_limit_id
                      where m.deleted_at is null and (m.measured_at at time zone 'America/Lima')::date between ? and ? order by m.measured_at", $range);

                return [['Fecha', 'Agente', 'Punto', 'Valor', 'Unidad', 'Límite', 'Unidad del límite', 'Resultado', 'Equipo', 'Calibración'],
                    array_map(fn ($r) => [$dt($r->measured_at), $r->agent, $r->point, $r->value === null ? null : (float) $r->value, $r->unit,
                        $r->limit_value === null ? null : (float) $r->limit_value, $r->limit_unit, $this->label('measurements', 'result', $r->result), $r->equipment, $d($r->equipment_calibration_on)], $rows)];
            case 'inspections':
                $rows = DB::select(
                    "select i.code, i.title, i.inspection_type, s.name as site, i.scheduled_on, i.completed_at, i.score_pct, i.status,
                            (select count(*) from findings f where f.inspection_id = i.id and f.deleted_at is null) as findings, i.summary
                       from inspections i left join sites s on s.id = i.site_id
                      where i.deleted_at is null and i.scheduled_on between ? and ? order by i.scheduled_on", $range);

                return [['Código', 'Título', 'Tipo', 'Sede', 'Programada', 'Realizada', 'Puntaje (%)', 'Hallazgos', 'Estado', 'Resumen'],
                    array_map(fn ($r) => [$r->code, $r->title, $this->label('inspections', 'inspection_type', $r->inspection_type), $r->site, $d($r->scheduled_on), $dt($r->completed_at),
                        $r->score_pct === null ? null : (float) $r->score_pct, (int) $r->findings, $this->label('inspections', 'status', $r->status), $r->summary], $rows)];
            case 'statistics':
                $rows = DB::select(
                    "select m.period_year, m.period_month, coalesce(s.name, 'Toda la empresa') as site, m.worker_count, m.hours_worked, m.accidents_fatal, m.accidents_disabling,
                            m.accidents_minor, m.dangerous_incidents, m.incidents, m.occupational_diseases, m.lost_days, m.frequency_index, m.severity_index, m.accident_rate_index, m.is_closed
                       from monthly_statistics m left join sites s on s.id = m.site_id
                      where m.deleted_at is null and make_date(m.period_year, m.period_month, 1) between date_trunc('month', ?::date) and ?::date
                      order by m.period_year, m.period_month, s.name nulls last", $range);

                return [['Año', 'Mes', 'Sede', 'Trabajadores', 'Horas-hombre', 'Mortales', 'Incapacitantes', 'Leves', 'Incidentes peligrosos', 'Incidentes', 'Enf. ocupacionales',
                    'Días perdidos', 'Índice de frecuencia', 'Índice de gravedad', 'Índice de accidentabilidad', 'Mes cerrado'],
                    array_map(fn ($r) => [(int) $r->period_year, (int) $r->period_month, $r->site, (int) $r->worker_count, (float) $r->hours_worked, (int) $r->accidents_fatal,
                        (int) $r->accidents_disabling, (int) $r->accidents_minor, (int) $r->dangerous_incidents, (int) $r->incidents, (int) $r->occupational_diseases, (int) $r->lost_days,
                        $r->frequency_index === null ? null : (float) $r->frequency_index, $r->severity_index === null ? null : (float) $r->severity_index,
                        $r->accident_rate_index === null ? null : (float) $r->accident_rate_index, $r->is_closed ? 'Sí' : 'No'], $rows)];
            case 'emergency_equipment':
                $rows = DB::select(
                    "select e.code, e.equipment_type, s.name as site, e.location_text, c.checked_at, c.check_type, c.result, c.next_due_on, c.provider_name, c.comments
                       from equipment_checks c join emergency_equipment e on e.id = c.equipment_id left join sites s on s.id = e.site_id
                      where c.deleted_at is null and (c.checked_at at time zone 'America/Lima')::date between ? and ? order by c.checked_at", $range);

                return [['Equipo', 'Tipo', 'Sede', 'Ubicación', 'Fecha de revisión', 'Revisión', 'Resultado', 'Próxima', 'Proveedor', 'Comentarios'],
                    array_map(fn ($r) => [$r->code, $this->label('emergency_equipment', 'equipment_type', $r->equipment_type), $r->site, $r->location_text, $dt($r->checked_at),
                        $this->label('equipment_checks', 'check_type', $r->check_type), $this->label('equipment_checks', 'result', $r->result), $d($r->next_due_on),
                        $r->provider_name, $r->comments], $rows)];
            case 'training':
                $rows = DB::select(
                    "select t.code, t.title, t.training_type, s.starts_at, coalesce(t.instructor_name, s.instructor_name) as instructor, w.full_name, w.document_last4, a.hours, a.attended
                       from training_attendances a join training_sessions s on s.id = a.session_id join trainings t on t.id = s.training_id left join workers w on w.id = a.worker_id
                      where a.deleted_at is null and (s.starts_at at time zone 'America/Lima')::date between ? and ?
                     union all
                     select d.code, d.title, 'drill', d.executed_at, d.external_entity, null, null, null, true from drills d
                      where d.deleted_at is null and d.status in ('executed','evaluated') and (d.executed_at at time zone 'America/Lima')::date between ? and ?
                     order by 4", [...$range, ...$range]);

                return [['Código', 'Tema', 'Tipo', 'Fecha', 'Capacitador / entidad', 'Participante', 'Documento (últimos 4)', 'Horas', 'Asistió'],
                    array_map(fn ($r) => [$r->code, $r->title, $r->training_type === 'drill' ? 'Simulacro' : $this->label('trainings', 'training_type', $r->training_type), $dt($r->starts_at), $r->instructor, $r->full_name, $r->document_last4,
                        $r->hours === null ? null : (float) $r->hours, $r->attended ? 'Sí' : 'No'], $rows)];
            case 'audits':
                $rows = DB::select(
                    "select a.code, a.audit_type, a.standard, a.starts_on, a.ends_on, a.lead_auditor_name, a.auditor_registration, a.status,
                            count(f.*) filter (where f.finding_type in ('major_nc','minor_nc')) as ncs, count(f.*) as findings, a.conclusions,
                            string_agg(case when f.finding_type in ('major_nc','minor_nc') then
                              (case f.finding_type when 'major_nc' then 'NC mayor: ' else 'NC menor: ' end) || f.description
                              || coalesce(' → ' || (select ca.code || ' ' || ca.title || ' (' || to_char(ca.due_on, 'DD/MM/YYYY') || ')' from corrective_actions ca where ca.id = f.corrective_action_id), '')
                            end, '; ') as nc_detail
                       from audits a left join audit_findings f on f.audit_id = a.id and f.deleted_at is null
                      where a.deleted_at is null and a.starts_on between ? and ? group by a.id order by a.starts_on", $range);

                return [['Código', 'Tipo', 'Norma', 'Inicio', 'Fin', 'Auditor líder', 'Registro del auditor', 'Estado', 'No conformidades', 'Hallazgos',
                    'No conformidades y medidas correctivas', 'Conclusiones'],
                    array_map(fn ($r) => [$r->code, $this->label('audits', 'audit_type', $r->audit_type), $this->label('audits', 'standard', $r->standard), $d($r->starts_on), $d($r->ends_on),
                        $r->lead_auditor_name, $r->auditor_registration, $this->label('audits', 'status', $r->status), (int) $r->ncs, (int) $r->findings, $r->nc_detail, $r->conclusions], $rows)];
        }

        return [[], []];
    }

    /** Texto en español de una opción (p. ej. disabling_accident → «Accidente incapacitante»). */
    private function label(string $table, string $field, ?string $value): string
    {
        static $maps = [];
        if ($value === null || $value === '') {
            return '';
        }
        $key = "{$table}.{$field}";
        if (! isset($maps[$key])) {
            $f = Registry::has($table) ? Registry::field(Registry::data()['resources'][$table], $field) : null;
            $maps[$key] = array_column($f['options'] ?? [], 'label', 'value');
        }

        return $maps[$key][$value] ?? $value;
    }

    /** Reemplaza códigos de rol de persona dentro de un texto armado en SQL («… (injured, Mano)»). */
    private function roles(?string $text): string
    {
        return $text ? preg_replace_callback('/\((injured|affected|ill|witness|involved)/', fn ($m) => '('.$this->label('event_people', 'person_role', $m[1]), $text) : '';
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no descarga los registros obligatorios.');
        }
    }
}
