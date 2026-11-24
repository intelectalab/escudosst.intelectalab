<?php

namespace App\Modules\REC;

use App\Core\Auth\Scope;
use App\Core\Support\Xlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reportes de listas (§11 de las fichas):
 *  GET /reports/workers.xlsx?site_id=&area_id=&position_id=  M-TRB: padrón de trabajadores activos por sede, área y puesto
 *  GET /reports/users.xlsx                                   M-USR: matriz de usuarios × roles × alcances
 *  GET /reports/documents.xlsx                               M-DOC: lista maestra (código, versión vigente, vigencia, responsable)
 *  GET /reports/corrective_actions[.xlsx]                    M-ACR: acciones por origen, estado, sede y responsable; % de cierre en plazo; antigüedad de abiertas
 *  GET /reports/investigation_causes                         M-INV: causas por categoría (Pareto)
 *  GET /reports/signature_requests.xlsx                      M-FIR: firmas pendientes por módulo y responsable
 */
class ListReportsController extends FieldReportsController
{
    public function workers(Request $request): Response
    {
        $this->can('trb.workers.export');
        $where = ["w.deleted_at is null", "w.status = 'active'"];
        $args = [];
        foreach (['site_id' => 'w.current_site_id', 'area_id' => 'w.current_area_id', 'position_id' => 'w.current_position_id'] as $k => $col) {
            if (($v = $request->query($k)) && Str::isUuid((string) $v)) {
                $where[] = "{$col} = ?";
                $args[] = $v;
            }
        }
        $rows = DB::select(
            "select s.name as site, a.name as area, p.name as position, w.full_name, w.document_type, '****' || w.document_last4 as document, w.employee_code,
                    w.hire_on, w.employment_type, w.contract_type
               from workers w left join sites s on s.id = w.current_site_id left join areas a on a.id = w.current_area_id left join positions p on p.id = w.current_position_id
              where ".implode(' and ', $where).Scope::andSql('w.current_site_id', 'w.current_area_id').' order by s.name nulls last, a.name nulls last, p.name nulls last, w.full_name', $args);

        return $this->xlsx('padron-trabajadores', 'Padrón de trabajadores activos',
            ['Sede', 'Área', 'Puesto', 'Trabajador', 'Tipo de documento', 'Documento', 'Código', 'Fecha de ingreso', 'Tipo de empleo', 'Tipo de contrato'],
            array_map(fn ($r) => [$r->site, $r->area, $r->position, $r->full_name, $r->document_type, $r->document, $r->employee_code, $this->d($r->hire_on),
                $this->label('workers', 'employment_type', $r->employment_type), $this->label('workers', 'contract_type', $r->contract_type)], $rows));
    }

    public function users(): Response
    {
        $this->can('usr.memberships.read');
        $rows = DB::select(
            "select u.full_name, u.email, r.name as role, m.status, m.valid_from, m.expires_at, m.last_access_at,
                    (select string_agg(case sc.scope_type when 'site' then 'Sede: ' || s.name when 'area' then 'Área: ' || a.name when 'module' then 'Módulo: ' || sc.module_code
                              when 'contractor' then 'Contratista: ' || c.legal_name end, '; ' order by sc.scope_type)
                       from membership_scopes sc left join sites s on s.id = sc.site_id left join areas a on a.id = sc.area_id left join contractors c on c.id = sc.contractor_id
                      where sc.membership_id = m.id and sc.deleted_at is null) as scopes
               from memberships m join users u on u.id = m.user_id join roles r on r.id = m.role_id
              where m.deleted_at is null and not m.is_support_session order by u.full_name");

        return $this->xlsx('usuarios-roles-alcances', 'Matriz de usuarios, roles y alcances',
            ['Usuario', 'Correo', 'Rol', 'Alcance', 'Estado', 'Vigente desde', 'Vence', 'Último acceso'],
            array_map(fn ($r) => [$r->full_name, $r->email, $r->role, $r->scopes ?: 'Toda la empresa', $this->label('memberships', 'status', $r->status), $this->d($r->valid_from),
                $this->d($r->expires_at), $this->dt($r->last_access_at)], $rows));
    }

    public function documents(): Response
    {
        $this->can('doc.documents.read');
        $rows = DB::select(
            "select d.code, d.title, c.name as category, v.version_label, v.version_number, v.effective_on, v.effective_until, d.next_review_on, u.full_name as owner, d.status
               from documents d left join document_categories c on c.id = d.category_id left join document_versions v on v.id = d.current_version_id
               left join users u on u.id = d.owner_user_id
              where d.deleted_at is null".Scope::andSql('d.site_id', 'd.area_id').' order by d.code');

        return $this->xlsx('lista-maestra-documentos', 'Lista maestra de documentos',
            ['Código', 'Título', 'Categoría', 'Versión vigente', 'Vigente desde', 'Vigente hasta', 'Próxima revisión', 'Responsable', 'Estado'],
            array_map(fn ($r) => [$r->code, $r->title, $r->category, $r->version_label ?? ($r->version_number ? 'v'.$r->version_number : null), $this->d($r->effective_on),
                $this->d($r->effective_until), $this->d($r->next_review_on), $r->owner, $this->label('documents', 'status', $r->status)], $rows));
    }

    /**
     * M-ACR §11. «Cierre en plazo»: acción terminada (completed_at) hasta su fecha límite vigente (due_on). «Antigüedad»: días
     * desde su registro de las acciones que siguen abiertas (ni verificadas ni anuladas).
     */
    public function actions(Request $request): JsonResponse|Response
    {
        $this->can('acr.corrective_actions.read');
        $rows = DB::select(
            "select ca.code, ca.title, ca.source_type, ca.status, s.name as site, coalesce(u.full_name, w.full_name, c.legal_name) as responsible, ca.due_on, ca.completed_at,
                    ca.created_at, (ca.completed_at is not null and (ca.completed_at at time zone 'America/Lima')::date <= ca.due_on) as on_time,
                    case when ca.status not in ('verified', 'cancelled') then (current_date - (ca.created_at at time zone 'America/Lima')::date) end as age_days
               from corrective_actions ca left join sites s on s.id = ca.site_id left join users u on u.id = ca.responsible_user_id
               left join workers w on w.id = ca.responsible_worker_id left join contractors c on c.id = ca.contractor_id
              where ca.deleted_at is null".Scope::andSql('ca.site_id', 'ca.area_id').' order by ca.created_at');
        if (str_ends_with($request->path(), '.xlsx')) {
            $this->can('acr.corrective_actions.export');

            return $this->xlsx('acciones-correctivas', 'Acciones correctivas y preventivas',
                ['Código', 'Acción', 'Origen', 'Estado', 'Sede', 'Responsable', 'Fecha límite', 'Terminada', 'Cerrada en plazo', 'Días abierta'],
                array_map(fn ($r) => [$r->code, $r->title, $this->label('corrective_actions', 'source_type', $r->source_type), $this->label('corrective_actions', 'status', $r->status),
                    $r->site, $r->responsible, $this->d($r->due_on), $this->d($r->completed_at), $r->completed_at ? ($r->on_time ? 'Sí' : 'No') : '',
                    $r->age_days === null ? null : (int) $r->age_days], $rows));
        }
        $active = array_filter($rows, fn ($r) => $r->status !== 'cancelled');
        $done = array_filter($active, fn ($r) => $r->completed_at !== null);
        $open = array_filter($active, fn ($r) => $r->age_days !== null);
        $group = function (string $key, ?callable $label = null) use ($active) {
            $out = [];
            foreach ($active as $r) {
                $k = $r->{$key} ?? ($key === 'site' ? 'Sin sede' : '—');
                $out[$k] ??= ['key' => $label ? $label($k) : $k, 'total' => 0, 'open' => 0];
                $out[$k]['total']++;
                $out[$k]['open'] += $r->age_days !== null ? 1 : 0;
            }
            usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);

            return $out;
        };
        usort($open, fn ($a, $b) => (int) $b->age_days <=> (int) $a->age_days);
        $ages = array_map(fn ($r) => (int) $r->age_days, $open);

        return response()->json(['data' => [
            'total' => count($active),
            'done' => count($done),
            'on_time' => count(array_filter($done, fn ($r) => $r->on_time)),
            'on_time_pct' => $done ? (int) round(count(array_filter($done, fn ($r) => $r->on_time)) * 100 / count($done)) : null,
            'open' => count($open),
            'open_avg_days' => $ages ? (int) round(array_sum($ages) / count($ages)) : null,
            'open_max_days' => $ages ? max($ages) : null,
            'oldest' => array_values(array_map(fn ($r) => ['code' => $r->code, 'title' => $r->title, 'responsible' => $r->responsible, 'age_days' => (int) $r->age_days, 'due_on' => $r->due_on],
                array_slice($open, 0, 10))),
            'by_source' => $group('source_type', fn ($k) => $this->label('corrective_actions', 'source_type', $k) ?: $k),
            'by_status' => $group('status', fn ($k) => $this->label('corrective_actions', 'status', $k) ?: $k),
            'by_site' => $group('site'),
            'by_responsible' => $group('responsible'),
        ]]);
    }

    public function causes(Request $request): JsonResponse
    {
        $this->can('inv.investigation_causes.read');
        $year = (int) $request->query('year', 0);
        $rows = DB::select(
            "select c.cause_category, count(*) as n from investigation_causes c join investigations i on i.id = c.investigation_id and i.deleted_at is null
              where c.deleted_at is null and c.cause_category is not null".($year ? ' and extract(year from coalesce(i.started_on, i.created_at::date)) = ?' : '').'
              group by c.cause_category order by n desc', $year ? [$year] : []);
        $total = array_sum(array_map(fn ($r) => (int) $r->n, $rows));
        $acc = 0;

        return response()->json(['data' => ['total' => $total, 'categories' => array_map(function ($r) use ($total, &$acc) {
            $acc += (int) $r->n;

            return ['category' => $r->cause_category, 'label' => $this->label('investigation_causes', 'cause_category', $r->cause_category) ?: $r->cause_category,
                'count' => (int) $r->n, 'pct' => $total ? round($r->n * 100 / $total, 1) : 0, 'cumulative_pct' => $total ? round($acc * 100 / $total, 1) : 0];
        }, $rows)]]);
    }

    public function pendingSignatures(): Response
    {
        $this->can('fir.signature_requests.read');
        $rows = DB::select(
            "select r.subject_module, r.subject_type, coalesce(u.full_name, w.full_name) as signer, r.role_in_document, r.created_at, r.expires_at, ru.full_name as requested_by,
                    r.reminder_count
               from signature_requests r left join users u on u.id = r.signer_user_id left join workers w on w.id = r.signer_worker_id left join users ru on ru.id = r.requested_by
              where r.deleted_at is null and r.status = 'pending' order by r.subject_module, signer, r.created_at");
        $res = Registry::data()['resources'];

        return $this->xlsx('firmas-pendientes', 'Firmas pendientes por módulo y responsable',
            ['Módulo', 'Registro', 'Firmante', 'En calidad de', 'Pedida el', 'Vence', 'Pedida por', 'Recordatorios'],
            array_map(fn ($r) => [$r->subject_module, $res[$r->subject_type]['title'] ?? $r->subject_type, $r->signer, $this->label('signature_requests', 'role_in_document', $r->role_in_document),
                $this->dt($r->created_at), $this->d($r->expires_at), $r->requested_by, (int) $r->reminder_count], $rows));
    }

    private function xlsx(string $file, string $title, array $header, array $rows): Response
    {
        $c = $this->company();
        $bytes = Xlsx::build(mb_substr($title, 0, 31), [
            [mb_strtoupper($title)],
            ["{$c['legal_name']} · RUC {$c['ruc']}"],
            ['Generado: '.now('America/Lima')->format('d/m/Y H:i').' · '.count($rows).' fila(s)'],
            [],
            $header,
            ...$rows,
        ], 5);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$file}-".date('Y-m-d').'.xlsx"',
        ]);
    }

    private function d(mixed $v): string
    {
        return $v ? date('d/m/Y', strtotime((string) $v)) : '';
    }

    private function dt(mixed $v): string
    {
        return $v ? (new \DateTimeImmutable((string) $v))->setTimezone(new \DateTimeZone('America/Lima'))->format('d/m/Y H:i') : '';
    }

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
}
