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

    /** RN-PET-03: al aprobarse, las capacitaciones requeridas quedan exigidas para el puesto del PETS (pendientes en la matriz). */
    public static function requireTrainings(string $versionId): void
    {
        $v = DB::selectOne('select v.company_id, v.required_training_ids, p.position_id from procedure_versions v join procedures p on p.id = v.procedure_id where v.id = ?', [$versionId]);
        if (! $v?->position_id || ! $v->required_training_ids) {
            return;
        }
        foreach (str_getcsv(trim((string) $v->required_training_ids, '{}')) as $t) {
            if (Str::isUuid($t)) {
                DB::insert("insert into position_training_requirements (company_id, position_id, training_id, requirement_level, is_active)
                            select ?, ?, ?, 'mandatory', true where not exists (select 1 from position_training_requirements where position_id = ? and training_id = ? and deleted_at is null)",
                    [$v->company_id, $v->position_id, $t, $v->position_id, $t]);
            }
        }
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
