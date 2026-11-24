<?php

namespace App\Modules\VMD;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Modules\VUL\MedicalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Vigilancia médica ocupacional (M-VMD). Todo dato vive en la base médica separada (RN-VMD-01).
 *
 *  POST  /vmd/reauth                     contraseña + código de la app (o de recuperación) + motivo → clave médica por unos minutos (RN-VMD-02)
 *  GET   /vmd/programs                   programas con sus conteos (sin datos de personas)
 *  POST  /vmd/programs                   crear programa · PATCH /vmd/programs/{id} cambiar estado o datos; con agent_type_code (agente del
 *                                        catálogo MAG) o target_positions el programa es «por exposición» y cuenta sus expuestos vigentes
 *  GET   /vmd/exposures                  agentes con exposición vigente medida en MAG (trabajadores y puestos), para definir programas
 *  GET   /vmd/programs/{id}/exposed      expuestos vigentes del programa y si ya tienen caso abierto (requiere clave médica; registrado)
 *  GET   /vmd/cases?program_id=&status=&overdue=1&worker_id=   casos (requiere clave médica; queda registrado)
 *  POST  /vmd/cases                      abrir caso · PATCH /vmd/cases/{id} seguimiento / cierre
 *  GET   /vmd/cases/{id}                 caso con sus notas clínicas (registrado)
 *  POST  /vmd/cases/{id}/notes           nota clínica firmada por su autor; no se edita: se agrega una adenda (RN-VMD-03); con
 *                                        «share_with_worker» queda visible para el trabajador en su app (SCR-VMD-03)
 *  GET   /vmd/indicators                 SCR-VMD-04 / RN-VMD-01: casos por programa y por área, agregados y sin nombres; un grupo con menos de
 *                                        min_group_size casos no se muestra (para SST y gerencia, sin datos de personas)
 *  PATCH /vmd/cases/{id} {work_restrictions} RN-VMD-05: la restricción funcional (sin diagnóstico) pasa a la aptitud vigente del trabajador
 *
 * Cada lectura o escritura de datos de personas se registra en medical_access_logs con la clave de re-autenticación.
 */
class VmdController extends Controller
{
    /** Contraseña + segundo factor + motivo (M-EMO §7, docs/10 §5): la misma clave médica que EMO, VUL y DES. */
    public function reauth(Request $request): JsonResponse
    {
        return response()->json(['data' => MedicalAccess::reauth($request, null)]);
    }

    public function programs(): JsonResponse
    {
        $this->can('vmd.surveillance_programs.read');
        $reauth = $this->reauthId(request());

        $rows = $this->med($reauth, function ($db) {
            $rows = $db->select(
                "select p.id, p.code, p.name, p.target_risk, p.agent_type_code, p.target_positions, p.description, p.protocol, p.starts_on, p.ends_on, p.status,
                        count(c.id) filter (where c.status <> 'closed' and c.deleted_at is null) as open_cases,
                        count(c.id) filter (where c.status <> 'closed' and c.deleted_at is null and c.next_control_on < current_date) as overdue
                   from surveillance_programs p left join surveillance_cases c on c.program_id = p.id
                  where p.deleted_at is null group by p.id order by p.status, p.name");
            // M-MAG §12 / RN-MAG-06: expuestos vigentes que corresponden al programa (por agente o puesto) y cuántos aún no tienen caso abierto.
            $all = \App\Modules\REC\WorkerExposures::active();
            foreach ($rows as $r) {
                $r->target_positions = $this->uuids($r->target_positions);
                $ids = $this->programWorkers($r, $all);
                $r->exposed_workers = count($ids);
                $r->exposed_without_case = count(array_diff($ids, $this->openCaseWorkers($db, $r->id, $ids)));
            }

            return $rows;
        });

        return response()->json(['data' => $rows]);
    }

    /**
     * «El médico define programas a partir de exposiciones (MAG/IPERC)» (M-VMD §7): agentes con marca de exposición vigente
     * (worker_exposures, base principal, sin datos médicos) y cuántos trabajadores y puestos abarcan, para armar el programa.
     */
    public function exposures(): JsonResponse
    {
        $this->can('vmd.surveillance_programs.read');
        $by = [];
        foreach (\App\Modules\REC\WorkerExposures::active() as $e) {
            $k = $e->agent_code;
            $by[$k] ??= ['agent_code' => $e->agent_code, 'agent' => $e->agent, 'agent_family' => $e->agent_family, 'workers' => [], 'positions' => []];
            $by[$k]['workers'][$e->worker_id] = true;
            if ($e->current_position_id) {
                $by[$k]['positions'][$e->current_position_id] = $e->position;
            }
        }
        $out = array_map(fn ($a) => ['agent_code' => $a['agent_code'], 'agent' => $a['agent'], 'agent_family' => $a['agent_family'], 'workers' => count($a['workers']),
            'positions' => array_map(fn ($id, $n) => ['id' => $id, 'name' => $n], array_keys($a['positions']), array_values($a['positions']))], array_values($by));
        usort($out, fn ($x, $y) => $y['workers'] <=> $x['workers']);

        return response()->json(['data' => $out]);
    }

    /** Expuestos vigentes del programa con su exposición y si ya tienen caso abierto en él (requiere clave médica; queda registrado). */
    public function programExposed(Request $request, string $id): JsonResponse
    {
        $this->can('vmd.surveillance_cases.read');
        $reauth = $this->reauthId($request);
        $rows = $this->med($reauth, function ($db) use ($id) {
            $p = $this->find($db, 'surveillance_programs', $id, 'El programa no existe.');
            $p->target_positions = $this->uuids($p->target_positions);
            $all = \App\Modules\REC\WorkerExposures::active();
            $ids = $this->programWorkers($p, $all);
            $open = $this->openCaseWorkers($db, $p->id, $ids);
            $this->log($db, 'surveillance_program', $p->id, null, 'read', 'Expuestos del programa');
            $out = [];
            foreach ($all as $e) {
                if (in_array($e->worker_id, $ids, true)) {
                    $out[] = ['worker_id' => $e->worker_id, 'worker' => $e->full_name, 'position' => $e->position, 'area' => $e->area, 'agent' => $e->agent, 'level' => $e->level,
                        'ratio_to_limit' => $e->ratio_to_limit === null ? null : (float) $e->ratio_to_limit, 'measured_at' => $e->measured_at, 'valid_until' => $e->valid_until,
                        'has_open_case' => in_array($e->worker_id, $open, true)];
                }
            }

            return $out;
        });

        return response()->json(['data' => $rows]);
    }

    /** Trabajadores con exposición vigente que corresponden al programa: por el código del agente o por sus puestos objetivo. */
    private function programWorkers(object $p, array $all): array
    {
        $ids = [];
        foreach ($all as $e) {
            if (($p->agent_type_code && strcasecmp((string) $e->agent_code, (string) $p->agent_type_code) === 0)
                || ($p->target_positions && $e->current_position_id && in_array($e->current_position_id, $p->target_positions, true))) {
                $ids[$e->worker_id] = true;
            }
        }

        return array_keys($ids);
    }

    private function openCaseWorkers($db, string $programId, array $ids): array
    {
        return $ids ? array_column($db->select("select distinct worker_id from surveillance_cases where program_id = ? and status <> 'closed' and deleted_at is null
                                                and worker_id = any(?::uuid[])", [$programId, '{'.implode(',', $ids).'}']), 'worker_id') : [];
    }

    /** uuid[] de Postgres («{a,b}») a lista. */
    private function uuids(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }

        return array_values(array_filter(explode(',', trim((string) $v, '{}'))));
    }

    /** Valida el agente (catálogo MAG) y los puestos objetivo del programa (base principal). */
    private function exposureTarget(array $d): array
    {
        if (! empty($d['agent_type_code']) && ! DB::selectOne('select 1 as ok from agent_types where lower(code) = lower(?) and deleted_at is null', [$d['agent_type_code']])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Ese agente no está en el catálogo de monitoreo.', ['errors' => ['agent_type_code' => ['Ese agente no está en el catálogo de monitoreo.']]]);
        }
        $pos = array_values(array_unique($d['target_positions'] ?? []));
        if ($pos && (int) DB::selectOne('select count(*) as n from positions where id = any(?::uuid[]) and deleted_at is null', ['{'.implode(',', $pos).'}'])->n !== count($pos)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Algún puesto no existe.', ['errors' => ['target_positions' => ['Algún puesto no existe.']]]);
        }

        return $pos;
    }

    public function createProgram(Request $request): JsonResponse
    {
        $this->can('vmd.surveillance_programs.create');
        $reauth = $this->reauthId($request);
        $d = $request->validate([
            'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:200'],
            'target_risk' => ['required', 'in:noise,respiratory,musculoskeletal,psychosocial,chemical,biological,radiation,cardiovascular,visual,other'],
            'description' => ['nullable', 'string', 'max:2000'], 'protocol' => ['nullable', 'string', 'max:5000'], 'starts_on' => ['nullable', 'date'],
            'agent_type_code' => ['nullable', 'string', 'max:40'], 'target_positions' => ['nullable', 'array', 'max:100'], 'target_positions.*' => ['uuid'],
        ], [], ['code' => 'código', 'name' => 'nombre', 'target_risk' => 'riesgo vigilado', 'agent_type_code' => 'agente', 'target_positions' => 'puestos']);
        $pos = $this->exposureTarget($d);
        $id = $this->med($reauth, function ($db) use ($d, $pos) {
            if ($db->selectOne('select 1 as ok from surveillance_programs where code = ? and deleted_at is null', [$d['code']])) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Ya hay un programa con ese código.', ['errors' => ['code' => ['Ya hay un programa con ese código.']]]);
            }

            return $db->selectOne("insert into surveillance_programs (company_id, code, name, target_risk, agent_type_code, target_positions, description, protocol, starts_on,
                                          responsible_user_id, status, created_by)
                                   values (?, ?, ?, ?, ?, ?::uuid[], ?, ?, coalesce(?::date, current_date), ?, 'active', ?) returning id",
                [$this->company(), $d['code'], $d['name'], $d['target_risk'], ($d['agent_type_code'] ?? null) ?: null, $pos ? '{'.implode(',', $pos).'}' : null,
                    $d['description'] ?? null, $d['protocol'] ?? null, $d['starts_on'] ?? null, $this->uid(), $this->uid()])->id;
        });

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function updateProgram(Request $request, string $id): JsonResponse
    {
        $this->can('vmd.surveillance_programs.update');
        $reauth = $this->reauthId($request);
        $d = $request->validate(['status' => ['nullable', 'in:active,closed'], 'name' => ['nullable', 'string', 'max:200'], 'protocol' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:2000'], 'agent_type_code' => ['nullable', 'string', 'max:40'], 'target_positions' => ['nullable', 'array', 'max:100'],
            'target_positions.*' => ['uuid']]);
        $pos = $this->exposureTarget($d);
        $this->med($reauth, function ($db) use ($id, $d, $pos) {
            $p = $this->find($db, 'surveillance_programs', $id, 'El programa no existe.');
            if (($d['status'] ?? null) === 'closed' && $db->selectOne("select 1 as ok from surveillance_cases where program_id = ? and status <> 'closed' and deleted_at is null", [$id])) {
                throw new ApiProblem(409, 'conflict', 'No se puede cerrar', 'El programa tiene casos abiertos: ciérralos primero.');
            }
            $db->update('update surveillance_programs set status = coalesce(?, status), name = coalesce(?, name), protocol = coalesce(?, protocol),
                         description = coalesce(?, description), ends_on = case when ? = \'closed\' then current_date else ends_on end, updated_by = ? where id = ?',
                [$d['status'] ?? null, $d['name'] ?? null, $d['protocol'] ?? null, $d['description'] ?? null, $d['status'] ?? null, $this->uid(), $p->id]);
            if (array_key_exists('agent_type_code', $d)) {
                $db->update('update surveillance_programs set agent_type_code = ? where id = ?', [$d['agent_type_code'] ?: null, $p->id]);
            }
            if (array_key_exists('target_positions', $d)) {
                $db->update('update surveillance_programs set target_positions = ?::uuid[] where id = ?', [$pos ? '{'.implode(',', $pos).'}' : null, $p->id]);
            }
        });

        return response()->json(['data' => ['id' => $id]]);
    }

    public function cases(Request $request): JsonResponse
    {
        $this->can('vmd.surveillance_cases.read');
        $reauth = $this->reauthId($request);
        $where = ['c.deleted_at is null'];
        $bind = [];
        foreach (['program_id', 'worker_id'] as $f) {
            if ($v = $request->query($f)) {
                if (! Str::isUuid($v)) {
                    throw ApiProblem::badRequest("{$f} no es válido.");
                }
                $where[] = "c.{$f} = ?";
                $bind[] = $v;
            }
        }
        if ($s = $request->query('status')) {
            $where[] = 'c.status = any(?::text[])';
            $bind[] = '{'.implode(',', array_intersect(explode(',', $s), ['open', 'in_follow_up', 'closed'])).'}';
        }
        if ($request->query('overdue')) {
            $where[] = "c.status <> 'closed' and c.next_control_on < current_date";
        }
        $rows = $this->med($reauth, function ($db) use ($where, $bind) {
            $this->log($db, 'surveillance_case', null, null, 'read', 'Lista de casos');

            return $db->select('select c.id, c.program_id, p.name as program, c.worker_id, c.opened_on, c.reason, c.risk_classification, c.next_control_on,
                                       c.closed_on, c.status, (c.status <> \'closed\' and c.next_control_on < current_date) as overdue
                                  from surveillance_cases c join surveillance_programs p on p.id = c.program_id
                                 where '.implode(' and ', $where).' order by (c.status = \'closed\'), c.next_control_on nulls last limit 500', $bind);
        });

        return response()->json(['data' => $this->withWorkers($rows)]);
    }

    public function createCase(Request $request): JsonResponse
    {
        $this->can('vmd.surveillance_cases.create');
        $reauth = $this->reauthId($request);
        $d = $request->validate([
            'program_id' => ['required', 'uuid'], 'worker_id' => ['required', 'uuid'], 'opened_on' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'], 'risk_classification' => ['nullable', 'in:low,medium,high'], 'next_control_on' => ['nullable', 'date'],
        ], [], ['program_id' => 'programa', 'worker_id' => 'trabajador', 'next_control_on' => 'próximo control']);
        if (! DB::selectOne("select 1 as ok from workers where id = ? and deleted_at is null", [$d['worker_id']])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El trabajador no existe.', ['errors' => ['worker_id' => ['El trabajador no existe.']]]);
        }
        $id = $this->med($reauth, function ($db) use ($d) {
            $p = $this->find($db, 'surveillance_programs', $d['program_id'], 'El programa no existe.');
            if ($p->status !== 'active') {
                throw new ApiProblem(409, 'conflict', 'Programa cerrado', 'Solo se abren casos en programas activos.');
            }
            if ($db->selectOne("select 1 as ok from surveillance_cases where program_id = ? and worker_id = ? and status <> 'closed' and deleted_at is null", [$p->id, $d['worker_id']])) {
                throw new ApiProblem(409, 'conflict', 'Caso ya abierto', 'Este trabajador ya tiene un caso abierto en este programa.');
            }
            $id = $db->selectOne("insert into surveillance_cases (company_id, program_id, worker_id, opened_on, reason, risk_classification, next_control_on, assigned_doctor_user_id, status, created_by)
                                  values (?, ?, ?, coalesce(?::date, current_date), ?, ?, ?, ?, 'open', ?) returning id",
                [$this->company(), $p->id, $d['worker_id'], $d['opened_on'] ?? null, $d['reason'] ?? null, $d['risk_classification'] ?? null, $d['next_control_on'] ?? null, $this->uid(), $this->uid()])->id;
            $this->log($db, 'surveillance_case', $id, $d['worker_id'], 'create', null);

            return $id;
        });
        $this->scheduleFollowup($id, $d['worker_id'], $this->uid(), $d['next_control_on'] ?? null);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function updateCase(Request $request, string $id): JsonResponse
    {
        $this->can('vmd.surveillance_cases.update');
        $reauth = $this->reauthId($request);
        $d = $request->validate([
            'status' => ['nullable', 'in:open,in_follow_up,closed'], 'next_control_on' => ['nullable', 'date'], 'risk_classification' => ['nullable', 'in:low,medium,high'],
            'closure_reason' => ['nullable', 'string', 'max:2000'], 'work_restrictions' => ['nullable', 'string', 'max:1000'],
        ], [], ['closure_reason' => 'motivo de cierre', 'next_control_on' => 'próximo control', 'work_restrictions' => 'restricción funcional']);
        $after = $this->med($reauth, function ($db) use ($id, $d) {
            $c = $this->find($db, 'surveillance_cases', $id, 'El caso no existe.');
            if ($c->status === 'closed') {
                throw new ApiProblem(409, 'conflict', 'Caso cerrado', 'El caso está cerrado; abre uno nuevo si hace falta.');
            }
            if (($d['status'] ?? null) === 'closed') {
                if (! in_array('vmd.surveillance_cases.close', Permissions::effective(), true)) {
                    throw ApiProblem::forbidden('Tu rol no puede cerrar casos.');
                }
                if (empty($d['closure_reason'])) {
                    throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Escribe el motivo de cierre.', ['errors' => ['closure_reason' => ['Escribe el motivo de cierre.']]]);
                }
            }
            $db->update("update surveillance_cases set status = coalesce(?, status), next_control_on = coalesce(?::date, next_control_on),
                            risk_classification = coalesce(?, risk_classification), closure_reason = coalesce(?, closure_reason),
                            closed_on = case when ? = 'closed' then current_date else closed_on end, updated_by = ? where id = ?",
                [$d['status'] ?? null, $d['next_control_on'] ?? null, $d['risk_classification'] ?? null, $d['closure_reason'] ?? null, $d['status'] ?? null, $this->uid(), $id]);
            $this->log($db, 'surveillance_case', $id, $c->worker_id, 'update', null);

            return $db->selectOne('select worker_id, assigned_doctor_user_id, next_control_on, status from surveillance_cases where id = ?', [$id]);
        });
        $this->scheduleFollowup($id, $after->worker_id, $after->assigned_doctor_user_id, $after->status === 'closed' ? null : $after->next_control_on);
        // RN-VMD-05: la restricción derivada del caso se publica en la aptitud como restricción funcional (sin diagnóstico).
        if (array_key_exists('work_restrictions', $d) && trim((string) $d['work_restrictions']) !== '') {
            $f = DB::selectOne('select id, status from fitness_status where worker_id = ? and is_current and deleted_at is null limit 1', [$after->worker_id]);
            if (! $f) {
                throw new ApiProblem(409, 'conflict', 'Sin aptitud vigente', 'El trabajador no tiene una aptitud registrada: registra primero su EMO para publicar la restricción.');
            }
            DB::update("update fitness_status set work_restrictions = ?, status = case when status = 'fit' then 'fit_with_restrictions' else status end, updated_at = now(), updated_by = ? where id = ?",
                [trim($d['work_restrictions']), $this->uid(), $f->id]);
            DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes) values (?, ?, ?, 'user', 'update', 'VMD', 'fitness_status', ?, ?::jsonb)",
                [$this->company(), $this->uid(), app(CompanyContext::class)->roleCode, $f->id, json_encode(['work_restrictions' => 'actualizada desde vigilancia médica'])]);
        }

        return response()->json(['data' => ['id' => $id]]);
    }

    public function indicators(): JsonResponse
    {
        $role = DB::selectOne('select coalesce(base_role_code, code) as c from roles where code = ? limit 1', [app(CompanyContext::class)->roleCode])?->c;
        if (! in_array('vmd.surveillance_programs.read', Permissions::effective(), true) && ! in_array($role, ['sst_manager', 'management'], true)) {
            throw ApiProblem::forbidden('Tu rol no ve los indicadores de vigilancia médica.');
        }
        $min = (int) \App\Core\Support\LegalParams::number('min_group_size', 5);
        $db = DB::connection('medical');
        // Solo conteos: la sesión de lectura es propia de esta consulta y queda registrada como acceso agregado.
        $rows = $db->transaction(function () use ($db) {
            $db->select("select set_config('app.company_id', ?, true), set_config('app.user_id', ?, true), set_config('app.reauth_session_id', ?, true)",
                [$this->company(), $this->uid(), (string) Str::uuid()]);
            $db->select('select med_log_access(?, null, ?, null, ?, ?, ?::inet)', ['surveillance_program', 'read', app(CompanyContext::class)->roleCode, 'Indicadores agregados', request()->ip()]);

            return $db->select("select p.name as program, c.worker_id, c.status, c.risk_classification, (c.next_control_on < current_date) as overdue
                                  from surveillance_cases c join surveillance_programs p on p.id = c.program_id where c.deleted_at is null and p.deleted_at is null");
        });
        $where = collect(DB::select("select w.id, coalesce(a.name, 'Sin área') as area, coalesce(s.name, 'Sin sede') as site
                                       from workers w left join areas a on a.id = w.current_area_id left join sites s on s.id = coalesce(a.site_id, w.current_site_id)
                                      where w.id = any(?::uuid[])", ['{'.implode(',', array_unique(array_column($rows, 'worker_id'))).'}']))->keyBy('id');
        // Criterio 3: un área con menos de min_group_size casos no se muestra sola; sus casos se suman a nivel de su sede.
        $perArea = [];
        foreach ($rows as $r) {
            $k = ($where[$r->worker_id]->site ?? 'Sin sede').'|'.($where[$r->worker_id]->area ?? 'Sin área');
            $perArea[$k] = ($perArea[$k] ?? 0) + 1;
        }
        $areas = [];
        foreach ($rows as $r) {
            $site = $where[$r->worker_id]->site ?? 'Sin sede';
            $area = $where[$r->worker_id]->area ?? 'Sin área';
            $areas[$r->worker_id] = $perArea[$site.'|'.$area] >= $min ? $area : "Sede {$site} (áreas con pocos casos)";
        }
        $group = function (callable $key) use ($rows, $min) {
            $g = [];
            foreach ($rows as $r) {
                $k = $key($r);
                $g[$k] ??= ['name' => $k, 'cases' => 0, 'open' => 0, 'overdue' => 0, 'high' => 0];
                $g[$k]['cases']++;
                $g[$k]['open'] += $r->status !== 'closed' ? 1 : 0;
                $g[$k]['overdue'] += $r->status !== 'closed' && $r->overdue ? 1 : 0;
                $g[$k]['high'] += $r->risk_classification === 'high' ? 1 : 0;
            }
            $shown = array_values(array_filter($g, fn ($x) => $x['cases'] >= $min));

            return ['groups' => $shown, 'hidden' => count($g) - count($shown)];
        };
        $total = count($rows);

        return response()->json(['data' => ['min_group' => $min, 'total' => $total >= $min ? $total : null,
            'by_program' => $group(fn ($r) => $r->program), 'by_area' => $group(fn ($r) => $areas[$r->worker_id] ?? 'Sin área')]]);
    }

    public function showCase(Request $request, string $id): JsonResponse
    {
        $this->can('vmd.surveillance_cases.read');
        $reauth = $this->reauthId($request);
        $canNotes = in_array('vmd.clinical_notes.read', Permissions::effective(), true);
        [$case, $notes] = $this->med($reauth, function ($db) use ($id, $canNotes) {
            $c = $db->selectOne('select c.*, p.name as program from surveillance_cases c join surveillance_programs p on p.id = c.program_id where c.id = ? and c.deleted_at is null', [$id]);
            if (! $c) {
                throw ApiProblem::notFound('El caso no existe.');
            }
            $this->log($db, 'surveillance_case', $id, $c->worker_id, 'read', null);
            $notes = [];
            if ($canNotes) {
                $notes = $db->select('select id, note_type, noted_at, author_user_id, subjective, objective, assessment, plan, amended_note_id, not is_confidential as shared
                                        from clinical_notes where surveillance_case_id = ? and deleted_at is null order by noted_at', [$id]);
                if ($notes) {
                    $this->log($db, 'clinical_note', null, $c->worker_id, 'read', 'Notas del caso '.$id);
                }
            }

            return [$c, $notes];
        });
        $authors = collect(DB::select('select id, full_name from users where id = any(?::uuid[])', ['{'.implode(',', array_unique(array_column($notes, 'author_user_id'))).'}']))->pluck('full_name', 'id');
        foreach ($notes as $n) {
            $n->author = $authors[$n->author_user_id] ?? 'Médico';
            $n->is_mine = $n->author_user_id === $this->uid();
        }

        return response()->json(['data' => ['case' => $this->withWorkers([$case])[0], 'notes' => $notes, 'can_notes' => $canNotes]]);
    }

    public function addNote(Request $request, string $id): JsonResponse
    {
        $this->can('vmd.clinical_notes.create');
        $reauth = $this->reauthId($request);
        $d = $request->validate([
            'note_type' => ['required', 'in:consultation,follow_up,evaluation,referral,other'], 'subjective' => ['nullable', 'string', 'max:5000'],
            'objective' => ['nullable', 'string', 'max:5000'], 'assessment' => ['nullable', 'string', 'max:5000'], 'plan' => ['nullable', 'string', 'max:5000'],
            'amended_note_id' => ['nullable', 'uuid'], 'share_with_worker' => ['boolean'],
        ], [], ['note_type' => 'tipo de nota']);
        if (! array_filter([$d['subjective'] ?? null, $d['objective'] ?? null, $d['assessment'] ?? null, $d['plan'] ?? null])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Escribe al menos una parte de la nota.', ['errors' => ['subjective' => ['Escribe al menos una parte de la nota.']]]);
        }
        $noteId = $this->med($reauth, function ($db) use ($id, $d) {
            $c = $this->find($db, 'surveillance_cases', $id, 'El caso no existe.');
            if (! empty($d['amended_note_id']) && ! $db->selectOne('select 1 as ok from clinical_notes where id = ? and surveillance_case_id = ?', [$d['amended_note_id'], $id])) {
                throw ApiProblem::notFound('La nota que quieres corregir no existe en este caso.');
            }
            $nid = $db->selectOne('insert into clinical_notes (company_id, surveillance_case_id, worker_id, note_type, noted_at, author_user_id, subjective, objective, assessment, plan, amended_note_id, is_confidential, created_by)
                                   values (?, ?, ?, ?, now(), ?, ?, ?, ?, ?, ?, ?, ?) returning id',
                [$this->company(), $id, $c->worker_id, $d['note_type'], $this->uid(), $d['subjective'] ?? null, $d['objective'] ?? null, $d['assessment'] ?? null,
                    $d['plan'] ?? null, $d['amended_note_id'] ?? null, ! empty($d['share_with_worker']) ? 'false' : 'true', $this->uid()])->id;
            if ($c->status === 'open') {
                $db->update("update surveillance_cases set status = 'in_follow_up', updated_by = ? where id = ?", [$this->uid(), $id]);
            }
            $this->log($db, 'clinical_note', $nid, $c->worker_id, 'create', null);

            return $nid;
        });

        return response()->json(['data' => ['id' => $noteId]], 201);
    }

    // ── apoyo ──

    /** Ejecuta en la base médica dentro de una transacción con el contexto RLS de la empresa y el usuario. */
    /**
     * M-VMD §10: con cada fecha de control se programan en la campana (scheduled_for) dos avisos sin datos clínicos
     * (RN-NOT-01): «Cita de control» al trabajador followup_notice_days antes y «Control de caso vencido» al médico al
     * día siguiente de la fecha. Si la fecha cambia o el caso se cierra, los avisos que aún no salieron se retiran.
     */
    private function scheduleFollowup(string $caseId, string $workerId, ?string $doctor, ?string $next): void
    {
        try {
            DB::update("update notifications set deleted_at = now() where subject_id = ? and event_code in ('vmd.followup_reminder', 'vmd.control_overdue')
                         and scheduled_for > now() and deleted_at is null", [$caseId]);
            if (! $next) {
                return;
            }
            $days = (int) \App\Core\Support\LegalParams::number('followup_notice_days', 2);
            $w = DB::selectOne('select user_id from workers where id = ?', [$workerId]);
            $company = $this->company();
            $key = date('Ymd', strtotime($next));
            DB::insert(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, scheduled_for, due_at, dedupe_key)
                 values (?, 'vmd.followup_reminder', 'VMD', ?, ?, ?, 'Cita de control', ?, '/mi/salud', 'normal',
                         greatest(now(), (?::date - make_interval(days => ?::int))::timestamp at time zone 'America/Lima' + interval '8 hours'), ?::date, ?) on conflict do nothing",
                [$company, $caseId, $w?->user_id, $workerId, 'Tienes una cita de control médico ocupacional el '.date('d/m/Y', strtotime($next)).'.', $next, $days, $next, "vmd.followup_reminder:{$caseId}:{$key}"]
            );
            if ($doctor) {
                DB::insert(
                    "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, scheduled_for, due_at, dedupe_key)
                     values (?, 'vmd.control_overdue', 'VMD', ?, ?, 'Control de caso vencido', 'Un caso de vigilancia médica pasó su fecha de control sin actualizarse.', '/m/vmd', 'normal',
                             ((?::date + 1)::timestamp at time zone 'America/Lima' + interval '8 hours'), ?::date, ?) on conflict do nothing",
                    [$company, $caseId, $doctor, $next, $next, "vmd.control_overdue:{$caseId}:{$key}"]
                );
            }
        } catch (\Throwable) {
            // El aviso nunca impide registrar el caso.
        }
    }

    private function med(string $reauth, callable $fn): mixed
    {
        $db = DB::connection('medical');

        return $db->transaction(function () use ($db, $fn, $reauth) {
            $db->select("select set_config('app.company_id', ?, true), set_config('app.user_id', ?, true), set_config('app.reauth_session_id', ?, true)",
                [$this->company(), $this->uid(), $reauth]);

            return $fn($db);
        });
    }

    private function reauthId(Request $request): string
    {
        return MedicalAccess::reauthId($request);
    }

    /** Registro inalterable del acceso (función med_log_access de la base médica, con la sesión de re-autenticación fijada). */
    private function log($db, string $resource, ?string $resourceId, ?string $workerId, string $action, ?string $purpose): void
    {
        $db->select('select med_log_access(?, ?::uuid, ?, ?::uuid, ?, ?, ?::inet)',
            [$resource, $resourceId, $action, $workerId, app(CompanyContext::class)->roleCode, MedicalAccess::purpose($purpose), request()->ip()]);
    }

    private function find($db, string $table, string $id, string $msg): object
    {
        $r = Str::isUuid($id) ? $db->selectOne("select * from {$table} where id = ? and deleted_at is null", [$id]) : null;
        if (! $r) {
            throw ApiProblem::notFound($msg);
        }

        return $r;
    }

    /** Nombres de los trabajadores (base principal), sin documento completo. */
    private function withWorkers(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($r) => $r->worker_id, $rows))));
        $w = $ids ? collect(DB::select('select id, full_name, document_last4 from workers where id = any(?::uuid[])', ['{'.implode(',', $ids).'}']))->keyBy('id') : collect();
        foreach ($rows as $r) {
            $r->worker = $w[$r->worker_id]->full_name ?? 'Trabajador';
            $r->document_masked = isset($w[$r->worker_id]) && $w[$r->worker_id]->document_last4 ? '****'.$w[$r->worker_id]->document_last4 : null;
        }

        return $rows;
    }

    private function company(): string
    {
        return app(CompanyContext::class)->companyId;
    }

    private function uid(): string
    {
        return (string) auth()->id();
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden();
        }
    }
}
