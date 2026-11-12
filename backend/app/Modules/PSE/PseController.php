<?php

namespace App\Modules\PSE;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Paquetes sectoriales (M-PSE):
 *  GET  /pse/packs                     catálogo con estado de instalación (SCR-PSE-01)
 *  GET  /pse/packs/{id}/preview        qué hará cada ítem al instalar (SCR-PSE-02)
 *  POST /pse/packs/{id}/install        instala los ítems elegidos {items?: [id]} (idempotente, RN-PSE-02/08)
 *  GET  /pse/packs/{id}/updates        diferencias entre la versión instalada y la nueva (SCR-PSE-03)
 *  POST /pse/packs/{id}/updates        aplica la actualización a los ítems elegidos {items: [id], confirm?: [id]} (RN-PSE-03)
 *  POST /pse/packs/{id}/uninstall      desinstala sin borrar el contenido usado (RN-PSE-06)
 *  GET  /pse/inventory                 contenido instalado y su versión (§11)
 */
class PseController extends Controller
{
    public function __construct(private readonly SectorPacks $packs) {}

    public function index(): JsonResponse
    {
        $this->can('pse.company_sector_packs.read');

        return response()->json(['data' => DB::select(
            "select p.id, p.code, p.name, p.sector, p.description, p.version_number,
                    (select count(*) from sector_pack_items i where i.sector_pack_id = p.id and i.deleted_at is null) as items,
                    c.status as install_status, c.applied_at, c.installed_version, c.update_available
               from sector_packs p
               left join lateral (select * from company_sector_packs c where c.sector_pack_id = p.id and c.status not in ('uninstalled', 'removed') and c.deleted_at is null limit 1) c on true
              where p.deleted_at is null and p.status = 'published' order by p.name"
        )]);
    }

    public function preview(string $id): JsonResponse
    {
        $this->can('pse.company_sector_packs.read');
        $p = $this->packs->pack($id);

        return response()->json(['data' => ['pack' => ['id' => $p->id, 'name' => $p->name, 'version' => (int) $p->version_number, 'description' => $p->description],
            'items' => $this->packs->preview($id)]]);
    }

    public function install(Request $request, string $id): JsonResponse
    {
        $this->can('pse.company_sector_packs.create');
        $items = $request->has('items') ? array_values(array_filter((array) $request->input('items'), 'is_string')) : null;

        return response()->json(['data' => DB::transaction(fn () => $this->packs->installPack($id, $items))], 201);
    }

    public function updates(string $id): JsonResponse
    {
        $this->can('pse.company_sector_packs.read');

        return response()->json(['data' => $this->packs->updates($id)]);
    }

    public function applyUpdates(Request $request, string $id): JsonResponse
    {
        $this->can('pse.company_sector_packs.update');
        $items = array_values(array_filter((array) $request->input('items', []), 'is_string'));
        if ($items === []) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Elige al menos un ítem para actualizar.', ['errors' => ['items' => ['Elige al menos un ítem.']]]);
        }

        return response()->json(['data' => DB::transaction(fn () => $this->packs->applyUpdates($id, $items, array_values(array_filter((array) $request->input('confirm', []), 'is_string'))))]);
    }

    public function uninstall(string $id): JsonResponse
    {
        $this->can('pse.company_sector_packs.update');
        DB::transaction(fn () => $this->packs->uninstall($id));

        return response()->json(['data' => ['uninstalled' => true]]);
    }

    public function inventory(): JsonResponse
    {
        $this->can('pse.company_sector_packs.read');

        return response()->json(['data' => $this->packs->inventory()]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto.');
        }
    }
}
