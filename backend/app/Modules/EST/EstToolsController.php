<?php

namespace App\Modules\EST;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\TRB\SheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Estadísticas SST (M-EST):
 *  GET  /est/suggest?year=&month=          SCR-EST-01: horas-hombre sugeridas por sede = trabajadores activos × horas del turno × días del mes
 *                                          según los días del turno de la sede (Lun-Vie o Lun-Sáb); sin turno no hay sugerencia
 *  POST /est/man-hours/import {file, apply?}  importación desde Excel/CSV (Código de sede, Año, Mes, Trabajadores, Horas trabajadas,
 *                                          Trabajadores contratistas, Horas contratistas); revisa y, con «apply», registra o actualiza
 *  POST /est/man-hours/{id}/validate       quién validó las horas-hombre (RN-EST-03)
 *  GET  /est/params                        SCR-EST-03: fórmulas y parámetros vigentes con su marca «a confirmar» y si la empresa los cambió
 *  POST /est/params {key, value}           cambia un parámetro para la empresa (desde hoy; los meses cerrados no cambian, RN-EST-02)
 *  GET  /est/months/{year}/{month}/reconcile  SCR-EST-04: eventos del mes para conciliar antes de cerrar y comparación entre sedes
 *  POST /est/months/{id}/adjust {lost_days, reason}  RN-EST-08: ajuste de un mes cerrado con motivo y usuario; recalcula sus índices
 */
class EstToolsController extends Controller
{
    private const PARAMS = ['frequency_index_factor', 'severity_index_factor', 'accident_rate_divisor', 'include_contractors_in_stats', 'lost_days_allocation'];

    public function suggest(Request $request): JsonResponse
    {
        $this->can('est.man_hours.read');
        [$year, $month] = $this->period($request);
        $first = sprintf('%d-%02d-01', $year, $month);
        $last = date('Y-m-t', strtotime($first));
        $out = [];
        foreach (DB::select("select id, name from sites t where deleted_at is null and status = 'active'".\App\Core\Auth\Scope::andTable('sites').' order by name') as $s) {
            $shift = DB::selectOne("select name, hours_per_day, days_pattern from work_shifts where site_id = ? and deleted_at is null and coalesce(status, 'active') = 'active' order by created_at limit 1", [$s->id]);
            $workers = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active', 'on_leave') and current_site_id = ?", [$s->id])->n;
            $days = null;
            if ($shift) {
                $pat = mb_strtolower((string) $shift->days_pattern);
                $max = str_contains($pat, 'dom') ? 7 : (str_contains($pat, 'sáb') || str_contains($pat, 'sab') ? 6 : (str_contains($pat, 'vie') ? 5 : null));
                if ($max) {
                    $days = 0;
                    for ($d = strtotime($first); $d <= strtotime($last); $d += 86400) {
                        $days += (int) date('N', $d) <= $max ? 1 : 0;
                    }
                }
            }
            $out[] = ['site_id' => $s->id, 'site' => $s->name, 'workers' => $workers, 'shift' => $shift?->name, 'hours_per_day' => $shift ? (float) $shift->hours_per_day : null,
                'days' => $days, 'suggested' => $shift && $days !== null ? round($workers * (float) $shift->hours_per_day * $days, 2) : null,
                'note' => ! $shift ? 'La sede no tiene turno registrado.' : ($days === null ? "No se pudo leer los días del turno («{$shift->days_pattern}»)." : null)];
        }

        return response()->json(['data' => ['year' => $year, 'month' => $month, 'sites' => $out]]);
    }

    public function import(Request $request): JsonResponse
    {
        $this->can('est.man_hours.create');
        $request->validate(['file' => ['required', 'file', 'max:5120']], ['file.required' => 'Elige el archivo de Excel.']);
        $file = $request->file('file');
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'csv'], true)) {
            $this->fail('file', 'Sube el archivo en Excel (.xlsx) o CSV.');
        }
        try {
            $sheet = SheetReader::read($file->getRealPath(), $file->getClientOriginalName());
        } catch (\Throwable) {
            $this->fail('file', 'No pudimos leer el archivo.');
        }
        $cols = ['site' => 'código de sede', 'year' => 'año', 'month' => 'mes', 'workers' => 'trabajadores', 'hours' => 'horas trabajadas',
            'cworkers' => 'trabajadores contratistas', 'chours' => 'horas contratistas'];
        $map = null;
        $header = 0;
        foreach ($sheet as $n => $cells) {
            $c = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $cells);
            if (in_array('código de sede', $c, true)) {
                $map = [];
                foreach ($cols as $k => $t) {
                    if (($i = array_search($t, $c, true)) !== false) {
                        $map[$k] = $i;
                    }
                }
                $header = $n;
                break;
            }
        }
        if (! $map || ! isset($map['site'], $map['year'], $map['month'], $map['hours'])) {
            $this->fail('file', 'No encontramos la fila de títulos (Código de sede, Año, Mes, Trabajadores, Horas trabajadas…).');
        }
        $sites = collect(DB::select('select id, lower(code) as code from sites where deleted_at is null'))->keyBy('code');
        $apply = $request->boolean('apply');
        $uid = (string) auth()->id();
        $company = app(CompanyContext::class)->companyId;
        $rows = [];
        foreach ($sheet as $n => $cells) {
            if ($n <= $header) {
                continue;
            }
            $g = fn ($k) => isset($map[$k]) ? trim((string) ($cells[$map[$k]] ?? '')) : '';
            if ($g('site') === '' && $g('hours') === '') {
                continue;
            }
            $err = [];
            $site = $sites->get(mb_strtolower($g('site')));
            if (! $site) {
                $err[] = 'La sede «'.$g('site').'» no existe.';
            }
            $y = (int) $g('year');
            $m = (int) $g('month');
            if ($y < 2000 || $m < 1 || $m > 12) {
                $err[] = 'Año o mes no válido.';
            }
            if (! is_numeric(str_replace(',', '.', $g('hours')))) {
                $err[] = 'Las horas no son un número.';
            }
            $closed = ! $err && DB::selectOne('select 1 as ok from monthly_statistics where period_year = ? and period_month = ? and is_closed and deleted_at is null limit 1', [$y, $m]);
            if ($closed) {
                $err[] = 'Ese mes ya está cerrado.';
            }
            $row = ['row' => $n, 'site' => $g('site'), 'year' => $y, 'month' => $m, 'hours' => $g('hours'), 'errors' => $err];
            if (! $err && $apply) {
                $vals = [(int) $g('workers'), (float) str_replace(',', '.', $g('hours')), (int) ($g('cworkers') ?: 0), (float) str_replace(',', '.', $g('chours') ?: '0')];
                $upd = DB::update("update man_hours set worker_count = ?, hours_worked = ?, contractor_worker_count = ?, contractor_hours = ?, source = 'import', validated_by = null, validated_at = null,
                                     updated_by = ?, updated_at = now() where period_year = ? and period_month = ? and site_id = ? and deleted_at is null", [...$vals, $uid, $y, $m, $site->id]);
                if (! $upd) {
                    DB::insert("insert into man_hours (company_id, period_year, period_month, site_id, worker_count, hours_worked, contractor_worker_count, contractor_hours, source, created_by, updated_by)
                                values (?, ?, ?, ?, ?, ?, ?, ?, 'import', ?, ?)", [$company, $y, $m, $site->id, ...$vals, $uid, $uid]);
                }
            }
            $rows[] = $row;
        }
        if (! $rows) {
            $this->fail('file', 'El archivo no tiene filas.');
        }

        return response()->json(['data' => ['applied' => $apply, 'total' => count($rows), 'valid' => count(array_filter($rows, fn ($r) => ! $r['errors'])), 'rows' => $rows]]);
    }

    public function validateHours(string $id): JsonResponse
    {
        $this->can('est.man_hours.update');
        if (! Str::isUuid($id) || ! DB::update('update man_hours set validated_by = ?, validated_at = now(), updated_at = now() where id = ? and deleted_at is null', [(string) auth()->id(), $id])) {
            throw ApiProblem::notFound('El registro de horas-hombre no existe.');
        }

        return response()->json(['data' => ['id' => $id, 'validated' => true]]);
    }

    public function params(): JsonResponse
    {
        $this->can('est.monthly_statistics.read');
        $company = app(CompanyContext::class)->companyId;
        $out = [];
        foreach (self::PARAMS as $k) {
            $g = DB::selectOne('select value, description, legal_reference, to_confirm from legal_parameters where key = ? and company_id is null and deleted_at is null order by valid_from desc limit 1', [$k]);
            $own = DB::selectOne('select value, valid_from from legal_parameters where key = ? and company_id = ? and deleted_at is null and valid_from <= current_date order by valid_from desc limit 1', [$k, $company]);
            $out[] = ['key' => $k, 'description' => $g?->description, 'reference' => $g?->legal_reference, 'default' => $g?->value, 'value' => $own?->value ?? $g?->value,
                'own' => (bool) $own, 'own_since' => $own?->valid_from, 'to_confirm' => (bool) ($g?->to_confirm ?? true)];
        }

        return response()->json(['data' => $out]);
    }

    public function saveParam(Request $request): JsonResponse
    {
        $this->can('est.monthly_statistics.update');
        $d = $request->validate(['key' => ['required', 'in:'.implode(',', self::PARAMS)], 'value' => ['required', 'string', 'max:40']]);
        $v = trim($d['value']);
        $ok = match ($d['key']) {
            'include_contractors_in_stats' => in_array($v, ['true', 'false'], true),
            'lost_days_allocation' => in_array($v, ['event_month', 'loss_month'], true),
            default => is_numeric($v) && (float) $v > 0,
        };
        if (! $ok) {
            $this->fail('value', 'El valor no es válido para ese parámetro.');
        }
        LegalParams::setForCompany($d['key'], $v);

        return response()->json(['data' => ['key' => $d['key'], 'value' => $v]]);
    }

    public function reconcile(int $year, int $month): JsonResponse
    {
        $this->can('est.monthly_statistics.read');
        $events = DB::select(
            "select e.id, e.code, e.event_type, e.status, e.lost_days, e.occurred_at, s.name as site,
                    (select i.status from investigations i where i.event_id = e.id and i.deleted_at is null limit 1) as investigation
               from sst_events e left join sites s on s.id = e.site_id
              where e.deleted_at is null and e.status <> 'discarded' and extract(year from e.occurred_at at time zone 'America/Lima') = ?
                and extract(month from e.occurred_at at time zone 'America/Lima') = ?".\App\Core\Auth\Scope::andTable('sst_events', 'e').' order by e.occurred_at', [$year, $month]);
        foreach ($events as $e) {
            $e->pending = in_array($e->status, ['reported', 'pending_verification', 'draft'], true) || (str_contains((string) $e->event_type, 'accident') && ! $e->investigation);
        }
        $sites = DB::select(
            "select m.id, coalesce(s.name, 'Toda la empresa') as site, m.hours_worked, m.accidents_fatal + m.accidents_disabling as lost_time, m.lost_days, m.frequency_index, m.severity_index,
                    m.accident_rate_index, m.is_closed
               from monthly_statistics m left join sites s on s.id = m.site_id where m.period_year = ? and m.period_month = ? and m.deleted_at is null"
              .\App\Core\Auth\Scope::andTable('monthly_statistics', 'm')."
              order by m.site_id nulls last, s.name", [$year, $month]);
        $hours = DB::select(
            "select h.id, coalesce(s.name, 'Toda la empresa') as site, h.hours_worked, h.contractor_hours, h.source, h.validated_at, u.full_name as validated_by
               from man_hours h left join sites s on s.id = h.site_id left join users u on u.id = h.validated_by
              where h.period_year = ? and h.period_month = ? and h.deleted_at is null".\App\Core\Auth\Scope::andTable('man_hours', 'h').' order by s.name', [$year, $month]);

        return response()->json(['data' => ['events' => $events, 'sites' => $sites, 'man_hours' => $hours]]);
    }

    public function adjust(Request $request, string $id): JsonResponse
    {
        $this->can('est.monthly_statistics.close');
        $m = (Str::isUuid($id) ? DB::selectOne('select * from monthly_statistics where id = ? and deleted_at is null', [$id]) : null) ?? throw ApiProblem::notFound('El mes no existe.');
        if (! $m->is_closed) {
            throw new ApiProblem(409, 'conflict', 'Mes abierto', 'El mes no está cerrado: recalcúlalo con «Calcular mes».');
        }
        $d = $request->validate(['lost_days' => ['required', 'integer', 'min:0', 'max:100000'], 'reason' => ['required', 'string', 'min:5', 'max:500']],
            ['reason.required' => 'Escribe el motivo del ajuste.', 'reason.min' => 'Escribe el motivo del ajuste.']);
        $h = (float) $m->hours_worked;
        $lost = (int) $m->accidents_fatal + (int) $m->accidents_disabling;
        $si = $h > 0 ? round($d['lost_days'] * (float) $m->severity_factor / $h, 2) : null;
        $fi = $m->frequency_index === null ? null : (float) $m->frequency_index;
        $ai = $h > 0 && $fi !== null ? round($fi * $si / (float) $m->accident_rate_divisor, 2) : null;
        $adj = json_decode((string) $m->adjustments, true) ?: [];
        $adj[] = ['at' => now()->toIso8601String(), 'user_id' => (string) auth()->id(), 'user' => DB::selectOne('select full_name from users where id = ?', [(string) auth()->id()])?->full_name,
            'field' => 'lost_days', 'from' => (int) $m->lost_days, 'to' => $d['lost_days'], 'reason' => $d['reason']];
        DB::update('update monthly_statistics set lost_days = ?, severity_index = ?, accident_rate_index = ?, adjustments = ?::jsonb, updated_by = ?, updated_at = now() where id = ?',
            [$d['lost_days'], $si, $ai, json_encode($adj, JSON_UNESCAPED_UNICODE), (string) auth()->id(), $id]);

        return response()->json(['data' => ['id' => $id, 'lost_days' => $d['lost_days'], 'severity_index' => $si, 'accident_rate_index' => $ai, 'adjustments' => $adj, 'lost_time' => $lost]]);
    }

    /** @return array{0: int, 1: int} */
    private function period(Request $request): array
    {
        $y = (int) $request->query('year', date('Y'));
        $m = (int) $request->query('month', date('n'));
        if ($y < 2000 || $m < 1 || $m > 12) {
            throw ApiProblem::badRequest('Elige un mes válido.');
        }

        return [$y, $m];
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto con las estadísticas.');
        }
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
