<?php

namespace App\Modules\PET;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Editor de PETS (M-PET SCR-PET-02): tabla paso–riesgo–control, capacitaciones requeridas y filas del IPERC vinculadas.
 *  GET /pet/versions/{id}/steps   la tabla, lo elegido y las opciones (capacitaciones y filas de los IPERC vigentes)
 *  PUT /pet/versions/{id}/steps   {steps: [{step, risk, control, significant}], required_training_ids: [], iperc_row_ids: []}
 * Solo se edita una versión en borrador (RN-PET-02: la aprobada es inmutable). RN-PET-04 se valida al enviarla a revisión.
 */
class PetStepsController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $this->can('pet.procedure_versions.read');
        $v = $this->version($id);
        $rows = DB::select(
            "select r.id, r.row_number, coalesce(r.task, r.activity, '') as task, r.hazard, r.risk, r.is_significant, m.code as matrix
               from iperc_rows r join iperc_versions v on v.id = r.iperc_version_id and v.status = 'current' and v.deleted_at is null
               join iperc_matrices m on m.id = v.iperc_matrix_id and m.deleted_at is null
              where r.deleted_at is null order by m.code, r.row_number limit 500");

        return response()->json(['data' => [
            'status' => $v->status,
            'steps' => json_decode((string) $v->steps, true) ?: [],
            'required_training_ids' => $this->ids($v->required_training_ids),
            'iperc_row_ids' => $this->ids($v->iperc_row_ids),
            'trainings' => DB::select("select id, title from trainings where deleted_at is null and status <> 'cancelled' order by title limit 300"),
            'iperc_rows' => $rows,
        ]]);
    }

    /** M-PET §11: PDF de una versión del PETS (pasos, riesgos y controles, EPP, capacitaciones, firmas). */
    public function report(string $id): JsonResponse
    {
        $this->can('pet.procedure_versions.read');
        $this->version($id);

        return response()->json(['data' => $this->payload($id)]);
    }

    /** SCR-PET-04: el QR del PETS abre siempre la versión vigente del procedimiento (vista para el celular en campo). */
    public function current(string $procedureId): JsonResponse
    {
        $this->can('pet.procedure_versions.read');
        $p = \Illuminate\Support\Str::isUuid($procedureId) ? DB::selectOne('select current_version_id from procedures where id = ? and deleted_at is null', [$procedureId]) : null;
        if (! $p) {
            throw ApiProblem::notFound('El procedimiento no existe o es de otra empresa.');
        }
        if (! $p->current_version_id) {
            throw new ApiProblem(409, 'conflict', 'Sin versión vigente', 'Este procedimiento aún no tiene una versión vigente.');
        }

        return response()->json(['data' => $this->payload($p->current_version_id)]);
    }

    private function payload(string $versionId): array
    {
        $v = DB::selectOne(
            "select v.*, p.id as procedure_id, p.code, p.title, p.procedure_type, p.is_critical_task, p.next_review_on, p.current_version_id, s.name as site, a.name as area, ps.name as position,
                    up.full_name as prepared_by_name, ur.full_name as reviewed_by_name, ua.full_name as approved_by_name
               from procedure_versions v join procedures p on p.id = v.procedure_id left join sites s on s.id = p.site_id left join areas a on a.id = p.area_id left join positions ps on ps.id = p.position_id
               left join users up on up.id = v.prepared_by left join users ur on ur.id = v.reviewed_by left join users ua on ua.id = v.approved_by
              where v.id = ?", [$versionId]);
        $c = DB::selectOne('select legal_name, trade_name, ruc from companies where id = ?', [$v->company_id]);
        $trainings = $this->ids($v->required_training_ids);

        return [
            'company' => ['name' => $c->trade_name ?: $c->legal_name, 'legal_name' => $c->legal_name, 'ruc' => trim((string) $c->ruc)],
            'procedure' => ['id' => $v->procedure_id, 'code' => $v->code, 'title' => $v->title, 'type' => $v->procedure_type, 'critical' => (bool) $v->is_critical_task,
                'site' => $v->site, 'area' => $v->area, 'position' => $v->position, 'next_review_on' => $v->next_review_on],
            'version' => ['id' => $v->id, 'number' => (int) $v->version_number, 'status' => $v->status, 'is_current' => $v->current_version_id === $v->id, 'effective_on' => $v->effective_on,
                'approved_at' => $v->approved_at, 'prepared_by' => $v->prepared_by_name, 'reviewed_by' => $v->reviewed_by_name, 'approved_by' => $v->approved_by_name,
                'content_html' => $v->content_html, 'required_ppe' => json_decode((string) $v->required_ppe, true) ?: [], 'change_summary' => $v->change_summary],
            'steps' => json_decode((string) $v->steps, true) ?: [],
            'trainings' => $trainings ? array_column(DB::select('select title from trainings where id = any(?::uuid[]) order by title', ['{'.implode(',', $trainings).'}']), 'title') : [],
            'signatures' => DB::select("select signer_name, signed_at, method from signatures where subject_type = 'procedure_versions' and subject_id = ? and ".\App\Modules\FIR\SignatureService::valid('signatures')." order by signed_at", [$v->id]),
        ];
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->can('pet.procedure_versions.update');
        $v = $this->version($id);
        if ($v->status !== 'draft') {
            throw new ApiProblem(409, 'conflict', 'Versión cerrada', 'Solo se editan los pasos de una versión en borrador. Para cambiarla crea una nueva versión.');
        }
        $d = $request->validate([
            'steps' => ['present', 'array', 'max:80'],
            'steps.*.step' => ['required', 'string', 'max:500'],
            'steps.*.risk' => ['nullable', 'string', 'max:500'],
            'steps.*.control' => ['nullable', 'string', 'max:800'],
            'steps.*.significant' => ['boolean'],
            'required_training_ids' => ['array', 'max:30'], 'required_training_ids.*' => ['uuid'],
            'iperc_row_ids' => ['array', 'max:60'], 'iperc_row_ids.*' => ['uuid'],
        ], ['steps.*.step.required' => 'Cada paso necesita su descripción.'], ['steps' => 'pasos']);
        $steps = array_values(array_map(fn ($s) => ['step' => trim($s['step']), 'risk' => trim((string) ($s['risk'] ?? '')), 'control' => trim((string) ($s['control'] ?? '')),
            'significant' => (bool) ($s['significant'] ?? false)], $d['steps']));
        DB::update('update procedure_versions set steps = ?::jsonb, required_training_ids = ?::uuid[], iperc_row_ids = ?::uuid[], updated_by = ? where id = ?', [
            json_encode($steps, JSON_UNESCAPED_UNICODE), '{'.implode(',', $d['required_training_ids'] ?? []).'}', '{'.implode(',', $d['iperc_row_ids'] ?? []).'}',
            (string) auth()->id(), $id,
        ]);

        return $this->show($id);
    }

    /** RN-PET-04: cada paso con riesgo significativo tiene su control. Devuelve el mensaje del primero que falte. */
    public static function missingControl(string $versionId): ?string
    {
        $steps = json_decode((string) DB::selectOne('select steps from procedure_versions where id = ?', [$versionId])?->steps, true) ?: [];
        foreach ($steps as $i => $s) {
            if (! empty($s['significant']) && trim((string) ($s['control'] ?? '')) === '') {
                return 'El paso '.($i + 1).' tiene un riesgo significativo sin control: agrégalo antes de enviar el PETS.';
            }
        }

        return null;
    }

    /**
     * Puestos vinculados al PETS (M-PET §5 y SCR-PET-02): el puesto del procedimiento y los puestos de las filas del IPERC
     * vinculadas a la versión.
     * @return list<string>
     */
    public static function linkedPositions(string $versionId): array
    {
        return array_column(DB::select(
            'select p.position_id as id from procedure_versions v join procedures p on p.id = v.procedure_id where v.id = ? and p.position_id is not null
             union
             select r.position_id from procedure_versions v join iperc_rows r on r.id = any(v.iperc_row_ids) and r.deleted_at is null
              where v.id = ? and r.position_id is not null', [$versionId, $versionId]), 'id');
    }

    /**
     * RN-PET-03 / criterio 2: al aprobarse una versión, sus trabajadores tienen capacitación pendiente en CAP.
     *  - La versión tiene su capacitación de difusión (se crea una vez, «Difusión del PETS …», y se reutiliza si se vuelve a aprobar).
     *  - Esa capacitación y las capacitaciones requeridas del editor quedan obligatorias para cada puesto vinculado (sin duplicar).
     *  - La difusión de versiones anteriores deja de exigirse (fin de vigencia ayer): con la versión nueva hay que volver a capacitar.
     */
    public static function requireTrainings(string $versionId): void
    {
        $v = DB::selectOne('select v.id, v.company_id, v.procedure_id, v.version_number, v.required_training_ids, v.training_id, p.code, p.title
                              from procedure_versions v join procedures p on p.id = v.procedure_id where v.id = ?', [$versionId]);
        $positions = $v ? self::linkedPositions($versionId) : [];
        if (! $positions) {
            return;
        }
        $own = $v->training_id;
        if (! $own) {
            $own = DB::selectOne("insert into trainings (company_id, code, title, training_type, topic, description, modality, status, created_by, updated_by)
                                  values (?, ?, ?, 'specific', 'PETS', ?, 'in_person', 'draft', ?, ?) returning id", [
                $v->company_id, mb_substr("{$v->code}-V{$v->version_number}", 0, 60), "Difusión del PETS {$v->code} · {$v->title} (versión {$v->version_number})",
                "Capacitación en el procedimiento {$v->code} (versión {$v->version_number}) para los puestos vinculados, creada al aprobarse la versión.",
                auth()->id(), auth()->id(),
            ])->id;
            DB::update('update procedure_versions set training_id = ? where id = ?', [$own, $versionId]);
        }
        $trainings = array_values(array_unique([$own, ...array_filter(str_getcsv(trim((string) $v->required_training_ids, '{}')), fn ($t) => Str::isUuid($t))]));
        foreach ($positions as $pos) {
            foreach ($trainings as $t) {
                DB::insert("insert into position_training_requirements (company_id, position_id, training_id, requirement_level, is_active, effective_from, notes)
                            select ?, ?, ?, 'mandatory', true, (now() at time zone 'America/Lima')::date, ?
                             where not exists (select 1 from position_training_requirements where position_id = ? and training_id = ? and deleted_at is null)",
                    [$v->company_id, $pos, $t, "Exigida por el PETS {$v->code} (versión {$v->version_number}).", $pos, $t]);
            }
        }
        DB::update("update position_training_requirements set effective_to = (now() at time zone 'America/Lima')::date - 1, deleted_at = now(), updated_at = now()
                     where deleted_at is null and training_id in (select training_id from procedure_versions where procedure_id = ? and id <> ? and training_id is not null)",
            [$v->procedure_id, $versionId]);
    }

    private function version(string $id): object
    {
        $v = Str::isUuid($id) ? DB::selectOne('select * from procedure_versions where id = ? and deleted_at is null', [$id]) : null;

        return $v ?? throw ApiProblem::notFound('La versión del PETS no existe o es de otra empresa.');
    }

    private function ids(?string $arr): array
    {
        return $arr ? array_values(array_filter(str_getcsv(trim($arr, '{}')))) : [];
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto con el PETS.');
        }
    }
}
