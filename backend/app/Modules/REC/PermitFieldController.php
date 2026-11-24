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
 *  GET  /ptr/board                       SCR-PTR-01: permisos en curso por sede (estado, tipo, vencimiento) con coordenadas para el mapa
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

    /**
     * SCR-PTR-01 «Permisos activos»: permisos en curso (por aprobar, aprobados, activos y suspendidos) agrupados por sede, con su
     * estado, tipo y hora de vencimiento, y las coordenadas de la sede (o del permiso) para el mapa. Respeta el alcance por sede/área.
     */
    public function board(): JsonResponse
    {
        $this->canRead();
        $rows = DB::select(
            "select w.id, w.code, w.status, w.valid_from, w.valid_until, w.location_text, w.work_description, w.latitude, w.longitude, w.suspended_reason,
                    t.name as type, t.category, w.site_id, s.name as site, s.latitude as site_lat, s.longitude as site_lng, a.name as area,
                    (w.status = 'active' and w.valid_until < now()) as overdue
               from work_permits w left join permit_types t on t.id = w.permit_type_id left join sites s on s.id = w.site_id left join areas a on a.id = w.area_id
              where w.deleted_at is null and w.status in ('pending_approval', 'approved', 'active', 'suspended')".\App\Core\Auth\Scope::andSql('w.site_id', 'w.area_id').'
              order by s.name nulls last, w.valid_until nulls last');
        $sites = [];
        $num = fn ($v) => $v === null ? null : (float) $v;
        foreach ($rows as $r) {
            $k = $r->site_id ?? '-';
            $sites[$k] ??= ['site_id' => $r->site_id, 'site' => $r->site ?? 'Sin sede', 'lat' => $num($r->site_lat), 'lng' => $num($r->site_lng), 'by_status' => [], 'by_type' => [], 'permits' => []];
            $sites[$k]['by_status'][$r->status] = ($sites[$k]['by_status'][$r->status] ?? 0) + 1;
            $sites[$k]['by_type'][$r->type ?? 'Sin tipo'] = ($sites[$k]['by_type'][$r->type ?? 'Sin tipo'] ?? 0) + 1;
            $sites[$k]['permits'][] = ['id' => $r->id, 'code' => $r->code, 'status' => $r->status, 'type' => $r->type, 'area' => $r->area, 'location_text' => $r->location_text,
                'work_description' => $r->work_description, 'valid_from' => $r->valid_from, 'valid_until' => $r->valid_until, 'overdue' => (bool) $r->overdue,
                'suspended_reason' => $r->suspended_reason, 'lat' => $num($r->latitude), 'lng' => $num($r->longitude)];
        }
        foreach ($sites as &$g) {
            // Sin coordenadas de la sede, el mapa se centra en el primer permiso con coordenadas.
            if ($g['lat'] === null) {
                foreach ($g['permits'] as $p) {
                    if ($p['lat'] !== null && $p['lng'] !== null) {
                        [$g['lat'], $g['lng']] = [$p['lat'], $p['lng']];
                        break;
                    }
                }
            }
        }
        unset($g);
        $totals = [];
        foreach ($rows as $r) {
            $totals[$r->status] = ($totals[$r->status] ?? 0) + 1;
        }

        return response()->json(['data' => ['totals' => $totals, 'sites' => array_values($sites)]]);
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
