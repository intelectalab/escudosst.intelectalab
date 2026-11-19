<?php

namespace App\Modules\CAP;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Modules\REC\Registry;
use App\Modules\REC\RecordsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M-PCA flujo 1: al crear el programa del año, el sistema propone los temas mínimos (inducción, IPERC del puesto, uso de EPP,
 * respuesta a emergencias, RISST) y los temas del sector si la empresa instaló un paquete sectorial (PSE). Se agregan como temas
 * del programa en borrador, obligatorios y sin puesto: el Jefe SST les asigna después los puestos objetivo, el mes y la modalidad
 * (flujo 2); hasta entonces el programa no se puede enviar (RN-PCA-02).
 */
final class ProgramTopicsController
{
    /** Temas mínimos de la ficha (flujo 1), con su clave estable. */
    private const MINIMUM = [
        'min:induccion' => ['Inducción en seguridad y salud en el trabajo', 'induction'],
        'min:iperc' => ['IPERC del puesto', 'specific'],
        'min:epp' => ['Uso de EPP', 'specific'],
        'min:emergencias' => ['Respuesta a emergencias', 'specific'],
        'min:risst' => ['Reglamento Interno de SST (RISST)', 'specific'],
    ];

    public function index(string $id): JsonResponse
    {
        $p = $this->program($id, 'pca.training_programs.read');

        return response()->json(['data' => ['editable' => $p->status === 'draft', 'topics' => $this->suggestions($p->id)]]);
    }

    public function store(Request $request, string $id, RecordsService $records): JsonResponse
    {
        $p = $this->program($id, 'pca.training_program_items.create');
        if ($p->status !== 'draft') {
            throw new ApiProblem(409, 'conflict', 'No corresponde', 'Los temas propuestos se agregan al programa en borrador; si ya está aprobado, crea una nueva versión (RN-PCA-06).');
        }
        $d = $request->validate(['keys' => ['required', 'array', 'min:1', 'max:60'], 'keys.*' => ['string', 'max:120']], ['keys.required' => 'Elige al menos un tema.']);
        $by = collect($this->suggestions($p->id))->keyBy('key');
        $res = Registry::resource('PCA', 'training_program_items');
        $added = 0;
        DB::transaction(function () use ($d, $by, $records, $res, $p, &$added) {
            foreach (array_unique($d['keys']) as $key) {
                $t = $by->get($key);
                if (! $t || $t['present']) {
                    continue;
                }
                $records->create($res, ['training_program_id' => $p->id, 'topic' => $t['topic'], 'training_type' => $t['training_type'], 'is_mandatory_legal' => true]);
                $added++;
            }
        });

        return response()->json(['data' => ['added' => $added, 'topics' => $this->suggestions($p->id)]], 201);
    }

    /** Temas mínimos y del sector, marcando los que el programa ya tiene (por nombre, sin distinguir mayúsculas). */
    private function suggestions(string $programId): array
    {
        $have = array_map(fn ($r) => mb_strtolower(trim($r->topic)), DB::select('select topic from training_program_items where training_program_id = ? and deleted_at is null and status <> \'cancelled\'', [$programId]));
        $out = [];
        foreach (self::MINIMUM as $key => [$topic, $type]) {
            $out[] = ['key' => $key, 'topic' => $topic, 'training_type' => $type, 'source' => 'Tema mínimo', 'present' => in_array(mb_strtolower($topic), $have, true)];
        }
        $sector = DB::select(
            "select i.item_key, i.payload, p.name from company_sector_packs c join sector_packs p on p.id = c.sector_pack_id
               join sector_pack_items i on i.sector_pack_id = p.id and i.item_type = 'training_topic' and i.deleted_at is null
              where c.deleted_at is null and c.uninstalled_at is null and c.status not in ('uninstalled', 'removed') order by p.name, i.item_key");
        foreach ($sector as $s) {
            $pl = json_decode((string) $s->payload, true) ?: [];
            $topic = trim((string) ($pl['topic'] ?? ''));
            if ($topic === '') {
                continue;
            }
            $type = in_array($pl['training_type'] ?? null, ['induction', 'specific', 'refresher', 'drill_training', 'awareness', 'legal_mandatory', 'other'], true) ? $pl['training_type'] : 'specific';
            $out[] = ['key' => 'pse:'.$s->item_key, 'topic' => $topic, 'training_type' => $type, 'source' => 'Paquete '.$s->name, 'present' => in_array(mb_strtolower($topic), $have, true)];
        }

        return collect($out)->unique('key')->values()->all();
    }

    private function program(string $id, string $perm): object
    {
        if (! in_array($perm, Permissions::effective(), true)) {
            throw ApiProblem::forbidden();
        }
        $p = Str::isUuid($id) ? DB::selectOne('select id, status from training_programs where id = ? and deleted_at is null', [$id]) : null;

        return $p ?? throw ApiProblem::notFound('El programa no existe o es de otra empresa.');
    }
}
