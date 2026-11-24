<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Support\Xlsx;
use App\Http\Controllers\Controller;
use App\Modules\TRB\SheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monitoreo de agentes (M-MAG):
 *  GET  /mag/import/template            plantilla Excel de mediciones del laboratorio (SCR-MAG-03)
 *  POST /mag/import {campaign_id, file, apply?}  revisa la carga masiva y, con «apply», registra las filas válidas con el servicio de
 *                                       registros (límite vigente, cociente y nivel de acción por RN-MAG-01/02/04; acción correctiva si
 *                                       supera el límite, RN-MAG-03)
 *  GET  /mag/trend?point_id=&agent_type_id=  SCR-MAG-05: mediciones del punto en el tiempo con el límite y el nivel de acción
 *  GET  /mag/exposed                     RN-MAG-06: trabajadores expuestos sobre el límite para la vigilancia médica: marcas vigentes de
 *                                        worker_exposures (la medición personal o los trabajadores activos del puesto, o del área, del punto)
 *  GET  /mag/gaps                        RN-MAG-07: puntos activos nunca medidos o sin medición aprobada en monitoring_frequency_months
 */
class MeasurementsController extends Controller
{
    private const COLUMNS = ['point' => 'Código del punto', 'agent' => 'Código del agente', 'measured_at' => 'Fecha y hora', 'value' => 'Valor', 'unit' => 'Unidad',
        'duration' => 'Duración (min)', 'equipment' => 'Equipo', 'calibration' => 'Fecha de calibración', 'notes' => 'Notas'];

    public function template(): Response
    {
        $this->can('mag.measurements.create');
        $points = DB::select('select p.code, p.name, a.code as agent from monitoring_points p left join agent_types a on a.id = p.agent_type_id where p.deleted_at is null order by p.code limit 200');
        $rows = [['MEDICIONES DE MONITOREO DE AGENTES'], ['Una fila por medición. Fecha y hora como AAAA-MM-DD HH:MM. Unidad igual a la del límite del agente.'], [], array_values(self::COLUMNS)];
        foreach ($points as $p) {
            $rows[] = [$p->code, $p->agent, '', '', '', '', '', '', $p->name];
        }

        return response(Xlsx::build('Mediciones', $rows, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla-mediciones.xlsx"',
        ]);
    }

    public function import(Request $request, RecordsService $records): JsonResponse
    {
        $this->can('mag.measurements.create');
        $d = $request->validate(['campaign_id' => ['required', 'uuid'], 'file' => ['required', 'file', 'max:5120']],
            ['campaign_id.required' => 'Elige la campaña.', 'file.required' => 'Elige el archivo de Excel.']);
        if (! DB::selectOne("select 1 as ok from monitoring_campaigns where id = ? and deleted_at is null and status <> 'closed'", [$d['campaign_id']])) {
            $this->fail('campaign_id', 'La campaña no existe o ya está cerrada.');
        }
        $file = $request->file('file');
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'csv'], true)) {
            $this->fail('file', 'Sube el archivo en Excel (.xlsx) o CSV.');
        }
        try {
            $sheet = SheetReader::read($file->getRealPath(), $file->getClientOriginalName());
        } catch (\Throwable) {
            $this->fail('file', 'No pudimos leer el archivo. Descarga la plantilla y vuelve a intentarlo.');
        }
        $norm = fn ($s) => mb_strtolower(trim((string) $s));
        $map = null;
        $header = 0;
        foreach ($sheet as $n => $cells) {
            $c = array_map($norm, $cells);
            if (in_array($norm(self::COLUMNS['point']), $c, true)) {
                $map = [];
                foreach (self::COLUMNS as $k => $t) {
                    if (($i = array_search($norm($t), $c, true)) !== false) {
                        $map[$k] = $i;
                    }
                }
                $header = $n;
                break;
            }
        }
        if (! $map || ! isset($map['point'], $map['measured_at'], $map['value'])) {
            $this->fail('file', 'No encontramos la fila de títulos (Código del punto, Fecha y hora, Valor…). Usa la plantilla.');
        }
        $points = collect(DB::select('select id, lower(code) as code, agent_type_id from monitoring_points where deleted_at is null'))->keyBy('code');
        $agents = collect(DB::select('select id, lower(code) as code from agent_types where deleted_at is null'))->keyBy('code');
        $apply = $request->boolean('apply');
        $res = Registry::resource('MAG', 'measurements');
        $rows = [];
        foreach ($sheet as $n => $cells) {
            if ($n <= $header) {
                continue;
            }
            $get = fn ($k) => isset($map[$k]) ? trim((string) ($cells[$map[$k]] ?? '')) : '';
            if ($get('value') === '' && $get('measured_at') === '') {
                continue;
            }
            $errors = [];
            $point = $points->get($norm($get('point')));
            if (! $point) {
                $errors[] = 'El punto «'.$get('point').'» no existe.';
            }
            $agentId = $get('agent') !== '' ? $agents->get($norm($get('agent')))?->id : $point?->agent_type_id;
            if (! $agentId) {
                $errors[] = $get('agent') !== '' ? 'El agente «'.$get('agent').'» no existe.' : 'Indica el código del agente.';
            }
            $value = str_replace(',', '.', $get('value'));
            if (! is_numeric($value)) {
                $errors[] = 'El valor no es un número.';
            }
            $at = strtotime($get('measured_at'));
            if (! $at) {
                $errors[] = 'La fecha y hora no es válida.';
            }
            $row = ['row' => $n, 'point' => $get('point'), 'measured_at' => $at ? date('Y-m-d H:i', $at) : $get('measured_at'), 'value' => $get('value'), 'unit' => $get('unit'),
                'errors' => $errors, 'result' => null];
            if (! $errors && $apply) {
                try {
                    $m = $records->create($res, array_filter(['campaign_id' => $d['campaign_id'], 'monitoring_point_id' => $point->id, 'agent_type_id' => $agentId,
                        'measured_at' => date(DATE_ATOM, $at), 'value' => (float) $value, 'unit' => $get('unit') ?: null,
                        'duration_minutes' => is_numeric($get('duration')) ? (int) $get('duration') : null, 'equipment' => $get('equipment') ?: null,
                        'equipment_calibration_on' => ($c = strtotime($get('calibration'))) ? date('Y-m-d', $c) : null, 'notes' => $get('notes') ?: null], fn ($x) => $x !== null));
                    $row['result'] = $m['result'] ?? null;
                } catch (ApiProblem $e) {
                    $row['errors'][] = ! empty($e->extra['errors']) ? implode(' ', array_merge(...array_values($e->extra['errors']))) : ($e->detail ?? $e->title);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $row['errors'][] = implode(' ', array_merge(...array_values($e->errors())));
                }
            }
            $rows[] = $row;
        }
        if (! $rows) {
            $this->fail('file', 'El archivo no tiene mediciones.');
        }

        return response()->json(['data' => ['applied' => $apply, 'total' => count($rows), 'valid' => count(array_filter($rows, fn ($r) => ! $r['errors'])), 'rows' => $rows]]);
    }

    public function trend(Request $request): JsonResponse
    {
        $this->can('mag.measurements.read');
        $point = (string) $request->query('point_id');
        if (! Str::isUuid($point)) {
            throw ApiProblem::badRequest('Elige el punto de monitoreo.');
        }
        $agent = $request->query('agent_type_id');
        $rows = DB::select(
            "select m.measured_at, m.value, m.unit, m.ratio_to_limit, m.result, a.name as agent, l.limit_value, l.unit as limit_unit
               from measurements m left join agent_types a on a.id = m.agent_type_id left join exposure_limits l on l.id = m.exposure_limit_id
              where m.monitoring_point_id = ? and m.deleted_at is null and m.status <> 'voided'".(Str::isUuid((string) $agent) ? ' and m.agent_type_id = ?' : '').' order by m.measured_at',
            Str::isUuid((string) $agent) ? [$point, $agent] : [$point]);

        return response()->json(['data' => [
            'action_level_ratio' => (float) LegalParams::number('action_level_ratio', 0.5),
            'points' => array_map(fn ($r) => ['measured_at' => $r->measured_at, 'value' => (float) $r->value, 'unit' => $r->unit, 'agent' => $r->agent,
                'limit' => $r->limit_value === null ? null : (float) $r->limit_value, 'ratio' => $r->ratio_to_limit === null ? null : (float) $r->ratio_to_limit, 'result' => $r->result], $rows),
        ]]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto con las mediciones.');
        }
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }

    public function exposed(): \Illuminate\Http\JsonResponse
    {
        if (! in_array('mag.measurements.read', \App\Core\Auth\Permissions::effective(), true)) {
            throw \App\Core\Http\ApiProblem::forbidden('Tu rol no ve el monitoreo de agentes.');
        }
        $months = (int) LegalParams::number('monitoring_frequency_months', 12);
        // Marcas vigentes de exposición (worker_exposures): las mismas que usan EMO y VMD.
        $rows = WorkerExposures::active();
        usort($rows, fn ($x, $y) => strcmp((string) $x->full_name, (string) $y->full_name));

        return response()->json(['data' => ['months' => $months, 'workers' => $rows]]);
    }

    public function gaps(): \Illuminate\Http\JsonResponse
    {
        if (! in_array('mag.measurements.read', \App\Core\Auth\Permissions::effective(), true)) {
            throw \App\Core\Http\ApiProblem::forbidden('Tu rol no ve el monitoreo de agentes.');
        }
        $months = (int) LegalParams::number('monitoring_frequency_months', 12);
        $rows = DB::select(
            "select p.id, p.code, p.name, s.name as site, a.name as agent, x.last_at
               from monitoring_points p left join sites s on s.id = p.site_id left join agent_types a on a.id = p.agent_type_id
               left join lateral (select max(m.measured_at) as last_at from measurements m where m.monitoring_point_id = p.id and m.deleted_at is null and m.status = 'approved') x on true
              where p.deleted_at is null and p.status = 'active' and (x.last_at is null or x.last_at < now() - make_interval(months => ?::int))
              order by x.last_at nulls first, p.name", [$months]);

        return response()->json(['data' => ['months' => $months, 'never' => array_values(array_filter($rows, fn ($r) => $r->last_at === null)),
            'overdue' => array_values(array_filter($rows, fn ($r) => $r->last_at !== null))]]);
    }
}
