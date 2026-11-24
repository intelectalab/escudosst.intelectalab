<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PETAR en campo y medición de gases (M-PTR SCR-PTR-03, SCR-PTR-05, RN-PTR-03, RN-PTR-06):
 *  GET  /ptr/gas-limits                  rangos permitidos de O₂, LEL, CO y H₂S (parámetros legales vigentes, a confirmar)
 *  GET  /ptr/work-permits/{id}/context   si el tipo exige gases, sus rangos y si la persona puede suspender el permiso
 *  POST /ptr/work-permits/{id}/suspend   {reason} suspende un permiso activo: quien edita permisos o un participante con
 *                                        «ptr.work_permits.suspend» (solicitante, líder ejecutante o integrante de la cuadrilla, p. ej. el vigía)
 * La suspensión sigue el mismo flujo del registro (reglas, aviso inmediato y reporte de condición en ACT).
 */
class PermitFieldController extends Controller
{
    public function limits(): JsonResponse
    {
        $this->canRead();

        return response()->json(['data' => self::gasLimits()]);
    }

    public function context(string $id): JsonResponse
    {
        $this->canRead();
        $p = $this->permit($id);

        return response()->json(['data' => [
            'requires_gas_test' => (bool) DB::selectOne('select requires_gas_test from permit_types where id = ?', [$p->permit_type_id])?->requires_gas_test,
            'gas_limits' => self::gasLimits(),
            'can_suspend' => $p->status === 'active' && $this->maySuspend($p),
        ]]);
    }

    public function suspend(Request $request, string $id, RecordsService $records): JsonResponse
    {
        $this->canRead();
        $p = $this->permit($id);
        if (! $this->maySuspend($p)) {
            throw ApiProblem::forbidden('Suspende el permiso quien participa en él (solicitante, ejecutante o vigía) o la supervisión SST.');
        }
        if ($p->status !== 'active') {
            throw new ApiProblem(409, 'conflict', 'No se puede suspender', 'Solo se suspende un permiso activo (trabajo en curso).');
        }
        $reason = trim((string) $request->input('reason'));
        if ($reason === '') {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Indica por qué se suspende el trabajo.', ['errors' => ['reason' => ['Indica por qué se suspende el trabajo.']]]);
        }
        $row = DB::transaction(fn () => $records->update(Registry::resource('PTR', 'work_permits'), $p->id, ['status' => 'suspended', 'suspended_reason' => mb_substr($reason, 0, 1000)]));

        return response()->json(['data' => $row]);
    }

    /** Rangos de gases que aplica la regla del permiso (mismos parámetros y valores de respaldo). */
    public static function gasLimits(): array
    {
        return [
            ['code' => 'o2', 'label' => 'O₂', 'unit' => '%', 'min' => LegalParams::number('gas_o2_min_pct', 19.5), 'max' => LegalParams::number('gas_o2_max_pct', 22.5), 'max_exclusive' => false],
            ['code' => 'lel', 'label' => 'LEL', 'unit' => '%', 'min' => null, 'max' => LegalParams::number('gas_lel_max_pct', 10), 'max_exclusive' => true],
            ['code' => 'co', 'label' => 'CO', 'unit' => 'ppm', 'min' => null, 'max' => LegalParams::number('gas_co_max_ppm', 25), 'max_exclusive' => true],
            ['code' => 'h2s', 'label' => 'H₂S', 'unit' => 'ppm', 'min' => null, 'max' => LegalParams::number('gas_h2s_max_ppm', 10), 'max_exclusive' => true],
        ];
    }

    private function canRead(): void
    {
        if (! in_array('ptr.work_permits.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve los permisos de trabajo.');
        }
    }

    private function permit(string $id): object
    {
        $p = Str::isUuid($id) ? DB::selectOne('select id, status, permit_type_id, requested_by, executor_leader_worker_id, crew from work_permits where id = ? and deleted_at is null', [$id]) : null;
        if (! $p) {
            throw ApiProblem::notFound('El permiso no existe o es de otra empresa.');
        }

        return $p;
    }

    /** RN-PTR-06: cualquier participante puede suspender; quien edita permisos también. */
    private function maySuspend(object $p): bool
    {
        $mine = Permissions::effective();
        if (in_array('ptr.work_permits.update', $mine, true)) {
            return true;
        }
        if (! in_array('ptr.work_permits.suspend', $mine, true)) {
            return false;
        }
        $me = (string) auth()->id();
        if ((string) $p->requested_by === $me) {
            return true;
        }
        $crew = json_decode((string) ($p->crew ?? '[]'), true) ?: [];
        $ids = array_values(array_filter(array_merge([$p->executor_leader_worker_id], array_column(is_array($crew) ? $crew : [], 'worker_id')),
            fn ($i) => is_string($i) && Str::isUuid($i)));

        return $ids !== [] && DB::selectOne('select 1 as ok from workers where user_id = ? and deleted_at is null and id in ('.implode(',', array_fill(0, count($ids), '?')).')', [$me, ...$ids]) !== null;
    }
}
