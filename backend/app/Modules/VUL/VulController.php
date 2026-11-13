<?php

namespace App\Modules\VUL;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Modules\EMO\MedicalCipher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grupos vulnerables (M-VUL SCR-VUL-01/02/03).
 *
 *  GET  /vul/adjustments                 adecuaciones con su compatibilidad con el IPERC del puesto. La columna «Condición»
 *                                        llega como reservada; solo el médico con clave médica vigente (X-Medical-Reauth)
 *                                        recibe la condición real, leída de la base médica y registrada (RN-VUL-01/05)
 *  POST /vul/compatibility               {worker_id, families[], new_position_id?} peligros del IPERC del puesto que la adecuación restringe
 *  POST /vul/adjustments                 {worker_id, adjustment_category, description, restricted_hazard_families[], starts_on, ends_on?,
 *                                         new_position_id?, compatibility_justification?}  exige reubicación o justificación si hay choque (RN-VUL-02)
 *  PUT  /vul/adjustments/{id}            mismos campos + status
 *  POST /vul/reauth                      {password} clave médica (solo médico ocupacional)
 *  GET  /vul/records                     condiciones registradas (base médica, clave médica)
 *  POST /vul/records                     {worker_id, vulnerability_type, starts_on, expected_end_on?, details?, conadis_registration?,
 *                                         risk_assessment?, recommended_measures?, consent_on?}  con medidas propone una adecuación
 *  PUT  /vul/records/{id}                corrige o da por terminada la condición (ends_on, status)
 */
class VulController extends Controller
{
    public const TYPES = ['pregnancy' => 'Gestante', 'lactation' => 'Lactante', 'minor' => 'Menor de edad', 'disability' => 'Persona con discapacidad',
        'chronic_condition' => 'Condición crónica', 'elderly' => 'Adulto mayor', 'other' => 'Otra condición'];

    public const FAMILIES = ['physical' => 'Físico', 'chemical' => 'Químico', 'biological' => 'Biológico', 'ergonomic' => 'Ergonómico', 'psychosocial' => 'Psicosocial',
        'mechanical' => 'Mecánico', 'electrical' => 'Eléctrico', 'locative' => 'Locativo', 'fire_explosion' => 'Incendio o explosión', 'natural' => 'Fenómeno natural',
        'public_safety' => 'Seguridad pública', 'vehicular' => 'Vehicular', 'other' => 'Otro'];

    /** Palabras para reconocer la familia de filas IPERC escritas sin el catálogo de peligros. */
    private const KEYWORDS = [
        'chemical' => ['químic', 'quimic', 'solvente', 'vapor', 'gases', 'humos', 'polvo', 'sustancia', 'ácido', 'acido', 'pintura', 'barniz', 'plaguicida', 'pesticida'],
        'physical' => ['ruido', 'vibraci', 'radiaci', 'temperatura', 'calor', 'frío', 'frio', 'iluminaci'],
        'biological' => ['biológ', 'biolog', 'virus', 'bacteria', 'hongo', 'fluidos', 'sangre'],
        'ergonomic' => ['ergonóm', 'ergonom', 'postura', 'carga manual', 'levantamiento', 'repetitiv', 'bipedestaci'],
        'psychosocial' => ['psicosocial', 'estrés', 'estres', 'turno', 'jornada'],
        'electrical' => ['eléctric', 'electric', 'tensión', 'tension'],
        'mechanical' => ['atrapamiento', 'corte', 'golpe', 'máquina', 'maquina', 'proyecci'],
    ];

    private const NO_CONDITION = 'Solo el médico ocupacional ve y registra la condición; tu rol ve la adecuación del puesto.';

    public function reauth(Request $request): JsonResponse
    {
        return response()->json(['data' => MedicalAccess::reauth($request, ['vul.vulnerability_records.read_sensitive'])]);
    }

    public function adjustments(Request $request): JsonResponse
    {
        MedicalAccess::can('vul.work_adjustments.read', 'Tu rol no ve las adecuaciones de puesto.');
        $rows = DB::select(
            "select a.id, a.worker_id, w.full_name as worker, w.document_last4, w.current_position_id, p.name as position, a.adjustment_category, a.description,
                    a.new_position_id, np.name as new_position, a.starts_on, a.ends_on, a.status, a.vulnerability_ref, a.restricted_hazard_families,
                    a.compatibility_justification, a.created_at
               from work_adjustments a join workers w on w.id = a.worker_id
               left join positions p on p.id = w.current_position_id left join positions np on np.id = a.new_position_id
              where a.deleted_at is null
              order by (a.status in ('active', 'proposed')) desc, a.starts_on desc limit 300");

        // Condición real: solo el médico con la clave médica vigente; si no, «Reservado».
        $conditions = [];
        $visible = false;
        $reauth = MedicalAccess::has('vul.vulnerability_records.read_sensitive') ? MedicalAccess::validReauth($request) : null;
        if ($reauth && $rows) {
            $visible = true;
            MedicalAccess::audit('vulnerability_record', null, null, 'read', $reauth, 'Lista de adecuaciones');
            $conditions = MedicalAccess::med($reauth, function ($db) use ($rows) {
                MedicalAccess::log($db, 'vulnerability_record', null, null, 'read', 'Lista de adecuaciones');
                $out = [];
                foreach ($db->select("select id, worker_id, vulnerability_type, work_adjustment_ref, status from vulnerability_records
                                       where deleted_at is null and worker_id = any(?::uuid[]) order by starts_on desc",
                    ['{'.implode(',', array_unique(array_column($rows, 'worker_id'))).'}']) as $v) {
                    $out['adj:'.$v->work_adjustment_ref] ??= $v;
                    $out['w:'.$v->worker_id] ??= $v;
                }

                return $out;
            });
        }

        $today = date('Y-m-d');
        $data = array_map(function ($a) use ($conditions, $visible, $today) {
            $families = $this->pgArray($a->restricted_hazard_families);
            $position = $a->new_position_id ?: $a->current_position_id;
            $conflicts = $families ? $this->conflicts($position, $families) : [];
            $cond = $visible ? ($conditions['adj:'.$a->id] ?? $conditions['w:'.$a->worker_id] ?? null) : null;

            return [
                'id' => $a->id,
                'worker_id' => $a->worker_id,
                'worker' => $a->worker,
                'document_masked' => $a->document_last4 ? '****'.$a->document_last4 : null,
                'position' => $a->position,
                'new_position_id' => $a->new_position_id,
                'new_position' => $a->new_position,
                'adjustment_category' => $a->adjustment_category,
                'description' => $a->description,
                'starts_on' => $a->starts_on,
                'ends_on' => $a->ends_on,
                'status' => $a->status,
                'expired' => $a->ends_on !== null && $a->ends_on < $today && in_array($a->status, ['proposed', 'active'], true),
                'restricted_hazard_families' => $families,
                'compatibility_justification' => $a->compatibility_justification,
                'conflicts' => $conflicts,
                'compatible' => $conflicts === [] || (bool) $a->compatibility_justification,
                'condition' => $visible ? ($cond ? ['type' => $cond->vulnerability_type, 'label' => self::TYPES[$cond->vulnerability_type] ?? $cond->vulnerability_type] : null) : null,
                'condition_reserved' => ! $visible,
            ];
        }, $rows);

        return response()->json([
            'data' => $data,
            'meta' => [
                'condition_visible' => $visible,
                'is_medical' => MedicalAccess::has('vul.vulnerability_records.read_sensitive'),
                'can_create' => MedicalAccess::has('vul.work_adjustments.create'),
                'can_update' => MedicalAccess::has('vul.work_adjustments.update'),
                'can_record' => MedicalAccess::has('vul.vulnerability_records.create'),
                'families' => self::FAMILIES,
                'kpis' => [
                    'active' => count(array_filter($data, fn ($a) => $a['status'] === 'active')),
                    'proposed' => count(array_filter($data, fn ($a) => $a['status'] === 'proposed')),
                    'incompatible' => count(array_filter($data, fn ($a) => in_array($a['status'], ['proposed', 'active'], true) && ! $a['compatible'])),
                    'expiring' => count(array_filter($data, fn ($a) => $a['status'] === 'active' && $a['ends_on'] && $a['ends_on'] <= date('Y-m-d', strtotime('+15 days')))),
                ],
            ],
        ]);
    }

    public function compatibility(Request $request): JsonResponse
    {
        MedicalAccess::can('vul.work_adjustments.read', 'Tu rol no ve las adecuaciones de puesto.');
        $d = $request->validate([
            'worker_id' => ['required', 'uuid'],
            'families' => ['nullable', 'array'], 'families.*' => ['string', 'in:'.implode(',', array_keys(self::FAMILIES))],
            'new_position_id' => ['nullable', 'uuid'],
        ]);
        $w = $this->worker($d['worker_id']);

        return response()->json(['data' => $this->compat($w, $d['families'] ?? [], $d['new_position_id'] ?? null)]);
    }

    public function store(Request $request): JsonResponse
    {
        MedicalAccess::can('vul.work_adjustments.create', 'Tu rol no registra adecuaciones de puesto.');
        $d = $this->validated($request);
        $w = $this->worker($d['worker_id']);
        MinorGuard::assertAllowed($w->birth_on, $d['new_position_id'] ?? null, 'new_position_id');
        $compat = $this->compat($w, $d['restricted_hazard_families'] ?? [], $d['new_position_id'] ?? null);
        $this->requireJustification($compat, $d);

        $id = DB::selectOne(
            "insert into work_adjustments (company_id, worker_id, adjustment_category, description, new_position_id, starts_on, ends_on, status,
                                           restricted_hazard_families, compatibility_justification, approved_by, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?, ?, ?, ?::text[], ?, ?, ?, ?) returning id",
            [MedicalAccess::company(), $w->id, $d['adjustment_category'], $d['description'], $d['new_position_id'] ?? null, $d['starts_on'], $d['ends_on'] ?? null,
                $d['status'] ?? 'proposed', $this->toPg($d['restricted_hazard_families'] ?? []), $d['compatibility_justification'] ?? null,
                ($d['status'] ?? 'proposed') === 'active' ? MedicalAccess::uid() : null, MedicalAccess::uid(), MedicalAccess::uid()])->id;
        $this->notifyIncompatible($id, $w, $compat);

        return response()->json(['data' => ['id' => $id, 'compatibility' => $compat]], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        MedicalAccess::can('vul.work_adjustments.update', 'Tu rol no modifica adecuaciones de puesto.');
        $a = Str::isUuid($id) ? DB::selectOne('select * from work_adjustments where id = ? and deleted_at is null', [$id]) : null;
        if (! $a) {
            throw ApiProblem::notFound('La adecuación no existe.');
        }
        $d = $this->validated($request, ['worker_id' => $a->worker_id]);
        $w = $this->worker($a->worker_id);
        $compat = $this->compat($w, $d['restricted_hazard_families'] ?? [], $d['new_position_id'] ?? null);
        $status = $d['status'] ?? $a->status;
        if (in_array($status, ['proposed', 'active'], true)) {
            MinorGuard::assertAllowed($w->birth_on, $d['new_position_id'] ?? null, 'new_position_id');
        }
        if (in_array($status, ['proposed', 'active'], true)) {
            $this->requireJustification($compat, $d);
        }
        DB::update(
            "update work_adjustments set adjustment_category = ?, description = ?, new_position_id = ?, starts_on = ?, ends_on = ?, status = ?,
                    restricted_hazard_families = ?::text[], compatibility_justification = ?,
                    approved_by = case when ? = 'active' and approved_by is null then ?::uuid else approved_by end, updated_by = ?
              where id = ?",
            [$d['adjustment_category'], $d['description'], $d['new_position_id'] ?? null, $d['starts_on'], $d['ends_on'] ?? null, $status,
                $this->toPg($d['restricted_hazard_families'] ?? []), $d['compatibility_justification'] ?? null, $status, MedicalAccess::uid(), MedicalAccess::uid(), $a->id]);
        $this->notifyIncompatible($a->id, $w, $compat);

        return response()->json(['data' => ['id' => $a->id, 'compatibility' => $compat]]);
    }

    // ── Condición (base médica) ──

    public function records(Request $request): JsonResponse
    {
        MedicalAccess::can('vul.vulnerability_records.read_sensitive', self::NO_CONDITION);
        $reauth = MedicalAccess::reauthId($request);
        MedicalAccess::audit('vulnerability_record', null, null, 'read', $reauth, $request->input('purpose') ?: 'Registros de condición');
        $rows = MedicalAccess::med($reauth, function ($db) use ($request) {
            MedicalAccess::log($db, 'vulnerability_record', null, null, 'read', $request->input('purpose') ?: 'Registros de condición');

            return $db->select('select * from vulnerability_records where deleted_at is null order by status, starts_on desc limit 300');
        });
        $names = $rows ? collect(DB::select('select id, full_name, document_last4 from workers where id = any(?::uuid[])',
            ['{'.implode(',', array_unique(array_column($rows, 'worker_id'))).'}']))->keyBy('id') : collect();

        return response()->json(['data' => array_map(fn ($v) => $this->recordOut($v, $names[$v->worker_id] ?? null), $rows), 'meta' => ['types' => self::TYPES]]);
    }

    public function storeRecord(Request $request): JsonResponse
    {
        MedicalAccess::can('vul.vulnerability_records.create', self::NO_CONDITION);
        $d = $this->recordValidated($request);
        $w = $this->worker($d['worker_id']);
        $reauth = MedicalAccess::reauthId($request);
        $id = (string) Str::uuid7();
        MedicalAccess::audit('vulnerability_record', $id, $w->id, 'create', $reauth);
        MedicalAccess::med($reauth, function ($db) use ($id, $w, $d) {
            $db->insert('insert into vulnerability_records (id, company_id, worker_id, vulnerability_type, details, conadis_registration, starts_on, expected_end_on,
                                risk_assessment, recommended_measures, status, created_by, updated_by)
                         values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, MedicalAccess::company(), $w->id, $d['vulnerability_type'], $this->details($d), MedicalCipher::encrypt($d['conadis_registration'] ?? null),
                    $d['starts_on'], $d['expected_end_on'] ?? null, MedicalCipher::encrypt($d['risk_assessment'] ?? null), $d['recommended_measures'] ?? null,
                    'active', MedicalAccess::uid(), MedicalAccess::uid()]);
            MedicalAccess::log($db, 'vulnerability_record', $id, $w->id, 'create');
        });

        // Flujo 1: con medidas recomendadas se propone una adecuación en la base principal, descrita sin la condición.
        $adjustment = null;
        if (! empty($d['recommended_measures']) && MedicalAccess::has('vul.work_adjustments.create')) {
            $adjustment = DB::selectOne("insert into work_adjustments (company_id, worker_id, vulnerability_ref, adjustment_category, description, starts_on, ends_on, status, created_by, updated_by)
                                         values (?, ?, ?, ?, ?, ?, ?, 'proposed', ?, ?) returning id",
                [MedicalAccess::company(), $w->id, $id, $d['adjustment_category'] ?? 'other', $d['recommended_measures'], $d['starts_on'], $d['expected_end_on'] ?? null,
                    MedicalAccess::uid(), MedicalAccess::uid()])->id;
            MedicalAccess::med($reauth, fn ($db) => $db->update('update vulnerability_records set work_adjustment_ref = ? where id = ?', [$adjustment, $id]));
            $team = array_column(DB::select("select m.user_id from memberships m join roles r on r.id = m.role_id
                                              where m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = 'sst_manager'"), 'user_id');
            $this->notify($team, 'vul.adjustment_proposed', $adjustment, 'Adecuación de puesto propuesta',
                "Se propuso una adecuación para {$w->full_name}. Revísala con el IPERC de su puesto.", 'normal');
        }

        return response()->json(['data' => ['id' => $id, 'work_adjustment_id' => $adjustment]], 201);
    }

    public function updateRecord(Request $request, string $id): JsonResponse
    {
        MedicalAccess::can('vul.vulnerability_records.update', self::NO_CONDITION);
        $reauth = MedicalAccess::reauthId($request);
        $current = Str::isUuid($id) ? MedicalAccess::med($reauth, fn ($db) => $db->selectOne('select id, worker_id from vulnerability_records where id = ? and deleted_at is null', [$id])) : null;
        if (! $current) {
            throw ApiProblem::notFound('El registro no existe.');
        }
        $d = $this->recordValidated($request, $current->worker_id);
        $ended = ! empty($d['ends_on']);
        MedicalAccess::audit('vulnerability_record', $id, $current->worker_id, 'update', $reauth);
        MedicalAccess::med($reauth, function ($db) use ($id, $d, $ended, $current) {
            $db->update('update vulnerability_records set vulnerability_type = ?, details = ?, conadis_registration = ?, starts_on = ?, expected_end_on = ?, ends_on = ?,
                                risk_assessment = ?, recommended_measures = ?, status = ?, updated_by = ? where id = ?',
                [$d['vulnerability_type'], $this->details($d), MedicalCipher::encrypt($d['conadis_registration'] ?? null), $d['starts_on'], $d['expected_end_on'] ?? null,
                    $d['ends_on'] ?? null, MedicalCipher::encrypt($d['risk_assessment'] ?? null), $d['recommended_measures'] ?? null, $ended ? 'ended' : 'active', MedicalAccess::uid(), $id]);
            MedicalAccess::log($db, 'vulnerability_record', $id, $current->worker_id, 'update');
        });

        return response()->json(['data' => ['id' => $id, 'status' => $ended ? 'ended' : 'active']]);
    }

    // ── apoyo ──

    /** @return array{position: ?array, families: list<string>, conflicts: list<array>, compatible: bool, relocated: bool, minor: bool, alerts: list<string>} */
    private function compat(object $w, array $families, ?string $newPosition): array
    {
        $position = $newPosition ?: $w->current_position_id;
        $p = $position ? DB::selectOne('select id, name from positions where id = ? and deleted_at is null', [$position]) : null;
        if ($newPosition && ! $p) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El nuevo puesto no existe.', ['errors' => ['new_position_id' => ['El nuevo puesto no existe.']]]);
        }
        $conflicts = $families ? $this->conflicts($position, $families) : [];
        $alerts = [];
        if ($conflicts) {
            $alerts[] = 'El IPERC del puesto «'.($p->name ?? '—').'» tiene '.count($conflicts).' peligro(s) de las familias que la adecuación restringe: reubica al trabajador o justifica por qué se mantiene.';
        }
        if ($families && ! $position) {
            $alerts[] = 'El trabajador no tiene puesto asignado: no se pudo cruzar con el IPERC.';
        }
        // RN-VUL-03: menor de edad (por su fecha de nacimiento en TRB).
        $minor = MinorGuard::isMinor($w->birth_on);
        $minorConflicts = $minor ? MinorGuard::conflicts($position) : [];
        if ($minorConflicts) {
            $alerts[] = 'El trabajador es menor de edad y el IPERC del puesto tiene '.count($minorConflicts).' peligro(s) prohibido(s) para menores: no puede asignarse a ese puesto.';
        } elseif ($minor) {
            $alerts[] = 'El trabajador es menor de edad: verifica que el puesto no tenga tareas prohibidas para menores.';
        }

        return [
            'position' => $p ? ['id' => $p->id, 'name' => $p->name] : null,
            'families' => array_values($families),
            'conflicts' => $conflicts,
            'compatible' => $conflicts === [],
            'relocated' => (bool) $newPosition && $newPosition !== $w->current_position_id,
            'minor' => $minor,
            'minor_conflicts' => $minorConflicts,
            'alerts' => $alerts,
        ];
    }

    /** Filas vigentes del IPERC del puesto cuyo peligro es de una familia restringida. */
    public function conflicts(?string $position, array $families): array
    {
        if (! $position) {
            return [];
        }
        static $memo = [];
        $key = $position.'|'.implode(',', $families);
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $rows = DB::select(
            "select r.id, m.code as matrix, r.activity, r.task, r.hazard, r.risk, r.risk_level, h.hazard_family
               from iperc_rows r join iperc_versions v on v.id = r.iperc_version_id and v.deleted_at is null
               join iperc_matrices m on m.id = v.iperc_matrix_id and m.deleted_at is null and m.status in ('active', 'review_required', 'review_overdue')
               left join hazard_catalog h on h.id = r.hazard_catalog_id
              where r.deleted_at is null and (m.current_version_id = v.id or v.status = 'current')
                and (r.position_id = ? or (r.position_id is null and m.position_id = ?))
              order by m.code, r.row_number", [$position, $position]);
        $out = [];
        foreach ($rows as $r) {
            $family = $r->hazard_family ?? $this->guessFamily($r->hazard.' '.$r->risk);
            if ($family && in_array($family, $families, true)) {
                $out[] = ['row_id' => $r->id, 'matrix' => $r->matrix, 'activity' => $r->activity, 'task' => $r->task, 'hazard' => $r->hazard, 'risk' => $r->risk,
                    'risk_level' => $r->risk_level, 'family' => $family, 'family_label' => self::FAMILIES[$family] ?? $family];
            }
        }

        return $memo[$key] = $out;
    }

    private function guessFamily(string $text): ?string
    {
        $t = mb_strtolower($text);
        foreach (self::KEYWORDS as $family => $words) {
            foreach ($words as $word) {
                if (str_contains($t, $word)) {
                    return $family;
                }
            }
        }

        return null;
    }

    private function requireJustification(array $compat, array $d): void
    {
        if ($compat['conflicts'] && ! $compat['relocated'] && trim((string) ($d['compatibility_justification'] ?? '')) === '') {
            throw new ApiProblem(422, 'validation_failed', 'Adecuación incompatible con el puesto', $compat['alerts'][0],
                ['errors' => ['compatibility_justification' => ['El puesto tiene peligros que la adecuación restringe: elige un nuevo puesto o justifica por qué se mantiene.']], 'compatibility' => $compat]);
        }
    }

    private function notifyIncompatible(string $id, object $w, array $compat): void
    {
        if (! $compat['conflicts']) {
            return;
        }
        $team = array_column(DB::select("select m.user_id from memberships m join roles r on r.id = m.role_id
                                          where m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = 'sst_manager'"), 'user_id');
        $this->notify($team, 'vul.incompatible', $id, 'Adecuación incompatible con el puesto',
            "El puesto de {$w->full_name} tiene ".count($compat['conflicts']).' peligro(s) que su adecuación restringe.', 'high');
    }

    private function notify(array $users, string $event, string $subjectId, string $title, string $body, string $priority): void
    {
        $company = app(CompanyContext::class)->companyId;
        foreach (array_unique($users) as $u) {
            DB::insert('insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                        values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$company, $event, 'VUL', $subjectId, $u, $title, mb_substr($body, 0, 500), '/m/vul?t=work_adjustments&open='.$subjectId, $priority, $event.':'.$subjectId.':'.$u.':'.date('Y-m-d')]);
        }
    }

    private function validated(Request $request, array $merge = []): array
    {
        $request->merge($merge);

        return $request->validate([
            'worker_id' => ['required', 'uuid'],
            'adjustment_category' => ['required', 'in:workload,schedule,position_change,ergonomic,environment,equipment,other'],
            'description' => ['required', 'string', 'min:5', 'max:2000'],
            'restricted_hazard_families' => ['nullable', 'array'], 'restricted_hazard_families.*' => ['string', 'in:'.implode(',', array_keys(self::FAMILIES))],
            'new_position_id' => ['nullable', 'uuid'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['nullable', 'in:proposed,active,ended,cancelled'],
            'compatibility_justification' => ['nullable', 'string', 'max:2000'],
        ], [], ['description' => 'descripción', 'starts_on' => 'fecha de inicio', 'ends_on' => 'fecha de fin', 'adjustment_category' => 'tipo de adecuación']);
    }

    private function recordValidated(Request $request, ?string $worker = null): array
    {
        if ($worker) {
            $request->merge(['worker_id' => $worker]);
        }

        return $request->validate([
            'worker_id' => ['required', 'uuid'],
            'vulnerability_type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            'starts_on' => ['required', 'date'],
            'expected_end_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'details' => ['nullable', 'string', 'max:5000'],
            'conadis_registration' => ['nullable', 'string', 'max:40'],
            'risk_assessment' => ['nullable', 'string', 'max:5000'],
            'recommended_measures' => ['nullable', 'string', 'max:2000'],
            'adjustment_category' => ['nullable', 'in:workload,schedule,position_change,ergonomic,environment,equipment,other'],
            'consent_on' => ['nullable', 'date', 'before_or_equal:today'],
        ], [], ['vulnerability_type' => 'condición', 'starts_on' => 'fecha de inicio', 'expected_end_on' => 'fin previsto', 'consent_on' => 'fecha del consentimiento']);
    }

    /** Detalle y consentimiento se guardan juntos y cifrados con la llave médica. */
    private function details(array $d): ?string
    {
        if (empty($d['details']) && empty($d['consent_on'])) {
            return null;
        }

        return MedicalCipher::encrypt(json_encode(['notes' => $d['details'] ?? null, 'consent_on' => $d['consent_on'] ?? null], JSON_UNESCAPED_UNICODE));
    }

    private function recordOut(object $v, ?object $w): array
    {
        $raw = MedicalCipher::decrypt($v->details);
        $det = $raw !== null ? (json_decode($raw, true) ?: ['notes' => $raw]) : [];

        return [
            'id' => $v->id, 'worker_id' => $v->worker_id, 'worker' => $w->full_name ?? '—', 'document_masked' => ($w->document_last4 ?? null) ? '****'.$w->document_last4 : null,
            'vulnerability_type' => $v->vulnerability_type, 'type_label' => self::TYPES[$v->vulnerability_type] ?? $v->vulnerability_type,
            'details' => $det['notes'] ?? null, 'consent_on' => $det['consent_on'] ?? null,
            'conadis_registration' => MedicalCipher::decrypt($v->conadis_registration), 'risk_assessment' => MedicalCipher::decrypt($v->risk_assessment),
            'recommended_measures' => $v->recommended_measures, 'starts_on' => $v->starts_on, 'expected_end_on' => $v->expected_end_on, 'ends_on' => $v->ends_on,
            'status' => $v->status, 'work_adjustment_id' => $v->work_adjustment_ref,
        ];
    }

    private function worker(string $id): object
    {
        $w = Str::isUuid($id) ? DB::selectOne('select id, full_name, birth_on, current_position_id from workers where id = ? and deleted_at is null', [$id]) : null;
        if (! $w) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Elige un trabajador válido.', ['errors' => ['worker_id' => ['Elige un trabajador válido.']]]);
        }

        return $w;
    }

    private function pgArray(?string $v): array
    {
        if ($v === null || $v === '{}' || $v === '') {
            return [];
        }

        return array_values(array_filter(array_map(fn ($x) => trim($x, '" '), explode(',', trim($v, '{}')))));
    }

    private function toPg(array $list): string
    {
        return '{'.implode(',', array_values(array_unique(array_intersect($list, array_keys(self::FAMILIES))))).'}';
    }
}
