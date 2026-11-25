<?php

namespace App\Modules\FOT;

use App\Core\Auth\Permissions;
use App\Core\Auth\Scope;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\Rules\PeopleRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fotocheck (M-FOT):
 *  GET  /fot/matrix?credential_type_id=         SCR-FOT-01: requisitos por puesto (los generales del tipo y los propios de cada puesto)
 *  POST /fot/credentials/{id}/verifications     SCR-FOT-07: «Registrar verificación» en campo (con sesión): resultado calculado en el momento
 *  GET  /fot/credentials/{id}/verifications     SCR-FOT-03: historial de verificaciones (QR público, campo, garita)
 *  GET  /fot/credentials/batch?credential_type_id=&site_id=&area_id=   SCR-FOT-04: fotochecks activos para imprimir en lote
 */
class FotToolsController extends Controller
{
    private const TYPES = ['training' => 'Capacitación', 'induction' => 'Inducción', 'emo_fitness' => 'Examen médico ocupacional', 'sctr' => 'SCTR', 'ppe_delivery' => 'EPP obligatorio',
        'document' => 'Documento', 'accreditation' => 'Acreditación del contratista'];

    public function matrix(Request $request): JsonResponse
    {
        $this->can('fot.credentials.read');
        $types = DB::select('select id, name from credential_types where deleted_at is null and coalesce(is_active, true) order by name');
        $type = (string) $request->query('credential_type_id', $types[0]->id ?? '');
        if (! Str::isUuid($type)) {
            return response()->json(['data' => ['types' => $types, 'type_id' => null, 'general' => [], 'positions' => []]]);
        }
        $reqs = DB::select(
            'select r.id, r.requirement_type, r.position_id, r.is_blocking, r.grace_days, coalesce(r.description, t.title) as description, t.title as training
               from credential_requirements r left join trainings t on t.id = r.training_id where r.credential_type_id = ? and r.deleted_at is null order by r.requirement_type, t.title', [$type]);
        $label = fn ($r) => ['type' => self::TYPES[$r->requirement_type] ?? $r->requirement_type, 'description' => $r->description, 'blocking' => (bool) $r->is_blocking, 'grace_days' => $r->grace_days];
        $general = array_map($label, array_values(array_filter($reqs, fn ($r) => ! $r->position_id)));
        $positions = [];
        // Alcance por sede/área (docs/03 §3): solo los cargos y trabajadores que la membresía ve.
        foreach (DB::select("select p.id, p.name, (select count(*) from workers w where w.current_position_id = p.id and w.status = 'active' and w.deleted_at is null".Scope::andTable('workers', 'w').") as workers
                              from positions p where p.deleted_at is null and p.status = 'active'".Scope::andTable('positions', 'p').' order by p.name') as $p) {
            $own = array_map($label, array_values(array_filter($reqs, fn ($r) => $r->position_id === $p->id)));
            $positions[] = ['id' => $p->id, 'name' => $p->name, 'workers' => (int) $p->workers, 'requirements' => $own, 'total' => count($own) + count($general)];
        }

        return response()->json(['data' => ['types' => $types, 'type_id' => $type, 'general' => $general, 'positions' => $positions]]);
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        $this->can('fot.credentials.read');
        $c = $this->credential($id);
        $d = $request->validate(['latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180']]);
        $today = now('America/Lima')->toDateString();
        $ev = PeopleRules::credentialEvaluation($c->credential_type_id, $c->worker_id, $c->contractor_worker_id, $c->expires_on);
        $result = match (true) {
            $c->status === 'revoked' => 'revoked',
            $c->expires_on && $c->expires_on < $today => 'expired',
            in_array($c->status, ['active', 'issued'], true) && $ev['reasons'] === [] => 'enabled',
            default => 'not_enabled',
        };
        // Lo que ve el supervisor: requisitos que faltan, sin términos médicos (RN-FOT-04).
        $missing = array_map(fn ($r) => $r['type'] === 'emo_fitness' ? 'Examen médico ocupacional pendiente' : $r['text'], $ev['reasons']);
        DB::insert("insert into credential_verifications (company_id, credential_id, channel, verifier_user_id, result, response_snapshot, latitude, longitude)
                    values (?, ?, 'app_scan', ?, ?, ?::jsonb, ?, ?)",
            [app(CompanyContext::class)->companyId, $c->id, (string) auth()->id(), $result, json_encode(['missing' => $missing], JSON_UNESCAPED_UNICODE), $d['latitude'] ?? null, $d['longitude'] ?? null]);

        return response()->json(['data' => ['result' => $result, 'missing' => $missing]], 201);
    }

    public function history(string $id): JsonResponse
    {
        $this->can('fot.credentials.read');
        $c = $this->credential($id);

        return response()->json(['data' => DB::select(
            'select v.verified_at, v.channel, v.result, u.full_name as verifier from credential_verifications v left join users u on u.id = v.verifier_user_id
              where v.credential_id = ? order by v.verified_at desc limit 200', [$c->id])]);
    }

    public function batch(Request $request): JsonResponse
    {
        $this->can('fot.credentials.read');
        $f = array_filter(['credential_type_id' => $request->query('credential_type_id'), 'site_id' => $request->query('site_id'), 'area_id' => $request->query('area_id')], fn ($v) => Str::isUuid((string) $v));
        Scope::assertSite($f['site_id'] ?? null);
        $sql = "select c.id from credentials c left join workers w on w.id = c.worker_id where c.deleted_at is null and c.status in ('active', 'issued')".Scope::andWorker('c.worker_id');
        $bind = [];
        if (isset($f['credential_type_id'])) {
            $sql .= ' and c.credential_type_id = ?';
            $bind[] = $f['credential_type_id'];
        }
        if (isset($f['site_id'])) {
            $sql .= ' and w.current_site_id = ?';
            $bind[] = $f['site_id'];
        }
        if (isset($f['area_id'])) {
            $sql .= ' and w.current_area_id = ?';
            $bind[] = $f['area_id'];
        }

        return response()->json(['data' => array_column(DB::select($sql.' order by w.full_name nulls last limit 300', $bind), 'id')]);
    }

    private function credential(string $id): object
    {
        $c = Str::isUuid($id) ? DB::selectOne('select * from credentials where id = ? and deleted_at is null', [$id]) : null;

        return $c ?? throw ApiProblem::notFound('El fotocheck no existe o es de otra empresa.');
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden();
        }
    }
}
