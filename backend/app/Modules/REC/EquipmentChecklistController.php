<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M-EQE SCR-EQE-02: checklist de la inspección por QR según el tipo de equipo (parámetro «equipment_checklists»: {tipo: [ítems]},
 * «*» para los tipos sin lista propia). La ficha solo da «presión, precinto, señalización, acceso libre», que es la lista por defecto.
 *  GET /eqe/checklists          — checklist de cada tipo (ya resuelto con el «*»).
 *  PUT /eqe/checklists {checklists: {tipo: [ítems]}} — la empresa fija sus listas (eqe.emergency_equipment.update).
 */
class EquipmentChecklistController extends Controller
{
    public const TYPES = ['fire_extinguisher', 'emergency_light', 'alarm', 'hose_cabinet', 'eyewash', 'shower', 'stretcher', 'aed', 'signage', 'other'];

    public const DEFAULT = ['Presión', 'Precinto', 'Señalización', 'Acceso libre'];

    /** Ítems del checklist de un tipo de equipo. @return string[] */
    public static function forType(string $type): array
    {
        $map = self::map();

        return $map[$type] ?? $map['*'] ?? self::DEFAULT;
    }

    /** @return array<string, string[]> */
    private static function map(): array
    {
        $raw = json_decode(LegalParams::text('equipment_checklists', '{}'), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $k => $items) {
            $list = array_values(array_filter(array_map(fn ($i) => is_string($i) ? trim($i) : '', (array) $items), fn ($i) => $i !== ''));
            if ($list && ($k === '*' || in_array($k, self::TYPES, true))) {
                $out[$k] = $list;
            }
        }

        return $out;
    }

    public function show(): JsonResponse
    {
        $perms = Permissions::effective();
        if (! array_intersect(['eqe.emergency_equipment.read', 'eqe.equipment_checks.create'], $perms)) {
            throw ApiProblem::forbidden('Tu rol no ve los equipos de emergencia.');
        }
        $map = self::map();

        return response()->json(['data' => [
            'default' => $map['*'] ?? self::DEFAULT,
            'types' => array_combine(self::TYPES, array_map(fn ($t) => self::forType($t), self::TYPES)),
            'own' => array_values(array_intersect(self::TYPES, array_keys($map))),
            'can_edit' => in_array('eqe.emergency_equipment.update', $perms, true),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        if (! in_array('eqe.emergency_equipment.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no configura los equipos de emergencia.');
        }
        $in = $request->input('checklists');
        if (! is_array($in)) {
            throw $this->invalid('Envía el checklist de cada tipo de equipo.');
        }
        $out = [];
        foreach ($in as $type => $items) {
            if ($type !== '*' && ! in_array($type, self::TYPES, true)) {
                throw $this->invalid("El tipo de equipo «{$type}» no existe.");
            }
            if (! is_array($items)) {
                throw $this->invalid('Cada tipo lleva una lista de ítems.');
            }
            $list = array_values(array_unique(array_filter(array_map(fn ($i) => is_string($i) ? mb_substr(trim($i), 0, 120) : '', $items), fn ($i) => $i !== '')));
            if (count($list) > 30) {
                throw $this->invalid('Un checklist tiene como máximo 30 ítems.');
            }
            if ($list) {
                $out[$type] = $list;
            }
        }
        if (! isset($out['*'])) {
            $out['*'] = self::map()['*'] ?? self::DEFAULT;
        }
        LegalParams::setForCompany('equipment_checklists', json_encode($out, JSON_UNESCAPED_UNICODE));

        return $this->show();
    }

    private function invalid(string $msg): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => ['checklists' => [$msg]]]);
    }
}
