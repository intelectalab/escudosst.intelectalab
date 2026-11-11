<?php

namespace App\Modules\USR\Http\Controllers;

use App\Core\Auth\Permissions;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\Rules\WorkerRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Búsqueda global de la cabecera (docs/07-pantallas-y-ux.md §2): módulos que el usuario puede usar, trabajadores
 * (por nombre, código o DNI exacto) y acciones correctivas (por código o título). Cada grupo solo aparece con el
 * permiso de lectura de su tabla; el rol trabajador solo se encuentra a sí mismo.
 */
class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            return response()->json(['data' => ['modules' => [], 'workers' => [], 'corrective_actions' => []]]);
        }
        $effective = Permissions::effective();
        $like = '%'.addcslashes($q, '%_\\').'%';

        return response()->json(['data' => [
            'modules' => $this->modules($q),
            'workers' => in_array('trb.workers.read', $effective, true) ? $this->workers($q, $like) : [],
            'corrective_actions' => in_array('acr.corrective_actions.read', $effective, true) ? $this->actions($like) : [],
        ]]);
    }

    private function modules(string $q): array
    {
        $needle = Str::lower(Str::ascii($q));
        $out = [];
        foreach (MeController::moduleRows() as $m) {
            if (! $m->role_can_use || ! in_array($m->mode, ['enabled', 'read_only'], true)) {
                continue;
            }
            if (str_contains(Str::lower(Str::ascii($m->name.' '.$m->solves.' '.$m->code)), $needle)) {
                $out[] = ['code' => $m->code, 'name' => $m->name, 'solves' => $m->solves];
            }
            if (count($out) === 6) {
                break;
            }
        }

        return $out;
    }

    private function workers(string $q, string $like): array
    {
        $dni = preg_replace('/[\s.\-]/', '', $q);
        $bind = [$like, $like];
        $byDoc = '';
        // Número de documento: solo coincidencia exacta (se guarda cifrado, con su HMAC para buscar).
        if (preg_match('/^[A-Za-z0-9]{5,20}$/', (string) $dni) && preg_match('/\d/', (string) $dni)) {
            $types = ['DNI', 'CE', 'PTP', 'PASSPORT', 'OTHER'];
            $byDoc = ' or w.document_number_hash in ('.implode(', ', array_fill(0, count($types), "decode(?, 'hex')")).')';
            foreach ($types as $t) {
                $bind[] = WorkerRules::documentHash($t, (string) $dni);
            }
        }
        $self = '';
        $ctx = app(CompanyContext::class);
        if ($ctx->roleCode === 'worker') {
            $self = ' and w.id = (select worker_id from memberships where id = ?)';
            $bind[] = $ctx->membershipId;
        }

        return DB::select(
            "select w.id, w.full_name, w.document_last4, p.name as position_name
               from workers w left join positions p on p.id = w.current_position_id
              where w.deleted_at is null and (w.full_name ilike ? or w.employee_code ilike ?{$byDoc}){$self}
              order by w.full_name limit 5",
            $bind
        );
    }

    private function actions(string $like): array
    {
        return DB::select(
            'select id, code, title, status from corrective_actions
              where deleted_at is null and (code ilike ? or title ilike ?)
              order by updated_at desc limit 4',
            [$like, $like]
        );
    }
}
