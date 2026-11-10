<?php

namespace App\Modules\VMD;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
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
 *  POST  /vmd/reauth                     confirma la contraseña → clave de acceso médico por unos minutos (RN-VMD-02)
 *  GET   /vmd/programs                   programas con sus conteos (sin datos de personas)
 *  POST  /vmd/programs                   crear programa · PATCH /vmd/programs/{id} cambiar estado o datos
 *  GET   /vmd/cases?program_id=&status=&overdue=1&worker_id=   casos (requiere clave médica; queda registrado)
 *  POST  /vmd/cases                      abrir caso · PATCH /vmd/cases/{id} seguimiento / cierre
 *  GET   /vmd/cases/{id}                 caso con sus notas clínicas (registrado)
 *  POST  /vmd/cases/{id}/notes           nota clínica firmada por su autor; no se edita: se agrega una adenda (RN-VMD-03)
 *
 * Cada lectura o escritura de datos de personas se registra en medical_access_logs con la clave de re-autenticación.
 */
class VmdController extends Controller
{
    private const REAUTH_MINUTES = 15;

    public function reauth(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']], [], ['password' => 'contraseña']);
        $user = $request->user();
        if (! Hash::check($data['password'], (string) $user->getAuthPassword())) {
            throw new ApiProblem(422, 'validation_failed', 'Contraseña incorrecta', 'La contraseña no es correcta.', ['errors' => ['password' => ['La contraseña no es correcta.']]]);
        }
        $id = (string) Str::uuid();
        $expires = now()->addMinutes(self::REAUTH_MINUTES);
        Cache::put("vmd-reauth:{$id}", ['user' => (string) $user->id, 'company' => $this->company()], $expires);

        return response()->json(['data' => ['reauth_id' => $id, 'expires_at' => $expires->toIso8601String()]]);
    }

    public function programs(): JsonResponse
    {
        $this->can('vmd.surveillance_programs.read');
        $reauth = $this->reauthId(request());

        return response()->json(['data' => $this->med($reauth, fn ($db) => $db->select(
            "select p.id, p.code, p.name, p.target_risk, p.description, p.protocol, p.starts_on, p.ends_on, p.status,
                    count(c.id) filter (where c.status <> 'closed' and c.deleted_at is null) as open_cases,
                    count(c.id) filter (where c.status <> 'closed' and c.deleted_at is null and c.next_control_on < current_date) as overdue
               from surveillance_programs p left join surveillance_cases c on c.program_id = p.id
              where p.deleted_at is null group by p.id order by p.status, p.name"))]);
    }

    public function createProgram(Request $request): JsonResponse
    {
        $this->can('vmd.surveillance_programs.create');
        $reauth = $this->reauthId($request);
        $d = $request->validate([
            'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:200'],
            'target_risk' => ['required', 'in:noise,respiratory,musculoskeletal,psychosocial,chemical,biological,radiation,cardiovascular,visual,other'],
            'description' => ['nullable', 'string', 'max:2000'], 'protocol' => ['nullable', 'string', 'max:5000'], 'starts_on' => ['nullable', 'date'],
        ], [], ['code' => 'código', 'name' => 'nombre', 'target_risk' => 'riesgo vigilado']);
        $id = $this->med($reauth, function ($db) use ($d) {
            if ($db->selectOne('select 1 as ok from surveillance_programs where code = ? and deleted_at is null', [$d['code']])) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Ya hay un programa con ese código.', ['errors' => ['code' => ['Ya hay un programa con ese código.']]]);
            }

            return $db->selectOne("insert into surveillance_programs (company_id, code, name, target_risk, description, protocol, starts_on, responsible_user_id, status, created_by)
                                   values (?, ?, ?, ?, ?, ?, coalesce(?::date, current_date), ?, 'active', ?) returning id",
                [$this->company(), $d['code'], $d['name'], $d['target_risk'], $d['description'] ?? null, $d['protocol'] ?? null, $d['starts_on'] ?? null, $this->uid(), $this->uid()])->id;
        });

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function updateProgram(Request $request, string $id): JsonResponse
    {
        $this->can('vmd.surveillance_programs.update');
        $reauth = $this->reauthId($request);
        $d = $request->validate(['status' => ['nullable', 'in:active,closed'], 'name' => ['nullable', 'string', 'max:200'], 'protocol' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:2000']]);
        $this->med($reauth, function ($db) use ($id, $d) {
            $p = $this->find($db, 'surveillance_programs', $id, 'El programa no existe.');
            if (($d['status'] ?? null) === 'closed' && $db->selectOne("select 1 as ok from surveillance_cases where program_id = ? and status <> 'closed' and deleted_at is null", [$id])) {
                throw new ApiProblem(409, 'conflict', 'No se puede cerrar', 'El programa tiene casos abiertos: ciérralos primero.');
            }
            $db->update('update surveillance_programs set status = coalesce(?, status), name = coalesce(?, name), protocol = coalesce(?, protocol),
                         description = coalesce(?, description), ends_on = case when ? = \'closed\' then current_date else ends_on end, updated_by = ? where id = ?',
                [$d['status'] ?? null, $d['name'] ?? null, $d['protocol'] ?? null, $d['description'] ?? null, $d['status'] ?? null, $this->uid(), $p->id]);
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

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function updateCase(Request $request, string $id): JsonResponse
    {
        $this->can('vmd.surveillance_cases.update');
        $reauth = $this->reauthId($request);
        $d = $request->validate([
            'status' => ['nullable', 'in:open,in_follow_up,closed'], 'next_control_on' => ['nullable', 'date'], 'risk_classification' => ['nullable', 'in:low,medium,high'],
            'closure_reason' => ['nullable', 'string', 'max:2000'],
        ], [], ['closure_reason' => 'motivo de cierre', 'next_control_on' => 'próximo control']);
        $this->med($reauth, function ($db) use ($id, $d) {
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
        });

        return response()->json(['data' => ['id' => $id]]);
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
                $notes = $db->select('select id, note_type, noted_at, author_user_id, subjective, objective, assessment, plan, amended_note_id
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
            'amended_note_id' => ['nullable', 'uuid'],
        ], [], ['note_type' => 'tipo de nota']);
        if (! array_filter([$d['subjective'] ?? null, $d['objective'] ?? null, $d['assessment'] ?? null, $d['plan'] ?? null])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Escribe al menos una parte de la nota.', ['errors' => ['subjective' => ['Escribe al menos una parte de la nota.']]]);
        }
        $noteId = $this->med($reauth, function ($db) use ($id, $d) {
            $c = $this->find($db, 'surveillance_cases', $id, 'El caso no existe.');
            if (! empty($d['amended_note_id']) && ! $db->selectOne('select 1 as ok from clinical_notes where id = ? and surveillance_case_id = ?', [$d['amended_note_id'], $id])) {
                throw ApiProblem::notFound('La nota que quieres corregir no existe en este caso.');
            }
            $nid = $db->selectOne('insert into clinical_notes (company_id, surveillance_case_id, worker_id, note_type, noted_at, author_user_id, subjective, objective, assessment, plan, amended_note_id, created_by)
                                   values (?, ?, ?, ?, now(), ?, ?, ?, ?, ?, ?, ?) returning id',
                [$this->company(), $id, $c->worker_id, $d['note_type'], $this->uid(), $d['subjective'] ?? null, $d['objective'] ?? null, $d['assessment'] ?? null,
                    $d['plan'] ?? null, $d['amended_note_id'] ?? null, $this->uid()])->id;
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
        $id = (string) $request->header('X-Medical-Reauth');
        $r = Str::isUuid($id) ? Cache::get("vmd-reauth:{$id}") : null;
        if (! $r || $r['user'] !== $this->uid() || $r['company'] !== $this->company()) {
            throw new ApiProblem(403, 'reauth_required', 'Confirma tu contraseña', 'Para ver o registrar datos médicos confirma tu contraseña (dura '.self::REAUTH_MINUTES.' minutos).');
        }

        return $id;
    }

    /** Registro inalterable del acceso (función med_log_access de la base médica, con la sesión de re-autenticación fijada). */
    private function log($db, string $resource, ?string $resourceId, ?string $workerId, string $action, ?string $purpose): void
    {
        $db->select('select med_log_access(?, ?::uuid, ?, ?::uuid, ?, ?, ?::inet)',
            [$resource, $resourceId, $action, $workerId, app(CompanyContext::class)->roleCode, $purpose, request()->ip()]);
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
