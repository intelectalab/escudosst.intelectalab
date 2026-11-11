<?php

namespace App\Modules\REC;

use App\Core\Http\Middleware\FeatureGate;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Registros de los módulos SST con empresa activa (RLS por app.company_id):
 *
 *  GET    /records/{module}                         pestañas del módulo: tablas, campos y lo que el rol puede hacer
 *  GET    /records/{module}/{table}                 lista (q, f[campo]=valor, page, per_page)
 *  GET    /records/{module}/{table}/{id}            detalle (con datos cifrados solo si puede editar)
 *  POST   /records/{module}/{table}                 alta
 *  PATCH  /records/{module}/{table}/{id}            edición
 *  DELETE /records/{module}/{table}/{id}            archivo (borrado lógico)
 *  GET    /records/{module}/{table}/options/{field} opciones de una referencia (q, id)
 *  GET    /records/{module}/{table}/{id}/trace      trazabilidad: de dónde viene y qué generó
 *  GET    /records/{module}/{table}/{id}/history    historial del registro (bitácora): quién cambió qué y cuándo
 *
 * Plan: el módulo debe estar habilitado (en solo lectura, solo se lee). Permisos: {módulo}.{tabla}.{acción}.
 */
class RecordsController extends Controller
{
    public function __construct(private RecordsService $records) {}

    public function schema(Request $request, string $module): JsonResponse
    {
        $this->gate($request, $module);
        $tables = Registry::tables($module);
        $out = [];
        foreach ($tables as $table) {
            $res = Registry::resource($module, $table);
            $can = $this->records->abilities($res);
            if (! $can['read']) {
                continue;
            }
            $out[] = [
                'table' => $table,
                'title' => $res['title'],
                'tab' => $res['tab'],
                'hybrid' => $res['hybrid'],
                'immutable' => $res['immutable'],
                'label' => $res['label'],
                'columns' => $res['columns'] ?? [],
                'fields' => $res['fields'],
                'can' => $can,
            ];
        }
        $mode = DB::selectOne('select mode from feature_flags where module_code = ? and deleted_at is null', [strtoupper($module)])?->mode;

        return response()->json(['module' => strtoupper($module), 'mode' => $mode, 'resources' => $out]);
    }

    public function index(Request $request, string $module, string $table): JsonResponse
    {
        $res = $this->resource($request, $module, $table, 'read');

        return response()->json($this->records->list($res, $request->query()));
    }

    public function show(Request $request, string $module, string $table, string $id): JsonResponse
    {
        $res = $this->resource($request, $module, $table, 'read');

        return response()->json(['data' => $this->records->find($res, $id, decrypt: $this->records->abilities($res)['update'])]);
    }

    public function store(Request $request, string $module, string $table): JsonResponse
    {
        $res = $this->resource($request, $module, $table, 'create');

        return response()->json(['data' => $this->records->create($res, $request->all())], 201);
    }

    public function update(Request $request, string $module, string $table, string $id): JsonResponse
    {
        $res = $this->resource($request, $module, $table, 'update');

        return response()->json(['data' => $this->records->update($res, $id, $request->all())]);
    }

    public function destroy(Request $request, string $module, string $table, string $id): Response
    {
        $res = $this->resource($request, $module, $table, 'delete');
        $this->records->delete($res, $id);

        return response()->noContent();
    }

    public function trace(Request $request, string $module, string $table, string $id): JsonResponse
    {
        $res = $this->resource($request, $module, $table, 'read');
        $this->records->find($res, $id);

        return response()->json(['data' => app(TraceService::class)->trace($res, $id)]);
    }

    /**
     * Historial del registro (docs/07 §3.2, pestaña «Historial»): línea de tiempo desde la bitácora. Lo ve quien puede
     * ver el registro. Los valores de campos cifrados no se muestran (solo que cambiaron).
     */
    public function history(Request $request, string $module, string $table, string $id): JsonResponse
    {
        $res = $this->resource($request, $module, $table, 'read');
        $this->records->find($res, $id);
        $hidden = array_column(array_filter($res['fields'], fn ($f) => ! empty($f['encrypted'])), 'name');
        $fields = array_column($res['fields'], null, 'name');
        $show = function (string $k, mixed $v) use ($fields): mixed {
            if ($v === null || ! is_scalar($v)) {
                return $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            foreach ($fields[$k]['options'] ?? [] as $o) {
                if ((string) $o['value'] === (string) $v) {
                    return $o['label'];
                }
            }

            return is_bool($v) ? ($v ? 'Sí' : 'No') : $v;
        };
        $rows = DB::select("select a.occurred_at, a.action, a.changes, a.actor_role, a.is_support_session, u.full_name as actor_name
            from audit_logs a left join users u on u.id = a.actor_user_id
            where a.entity_type = ? and a.entity_id = ? order by a.occurred_at desc, a.chain_seq desc limit 100", [$table, $id]);

        return response()->json(['data' => array_map(function ($r) use ($hidden, $fields, $show) {
            $out = [];
            foreach ((array) (json_decode((string) $r->changes, true) ?: []) as $k => $v) {
                if (in_array($k, ['updated_at', 'updated_by', 'created_at', 'created_by', 'company_id', 'id', 'row_version'], true) || ! isset($fields[$k])) {
                    continue;
                }
                $secret = in_array($k, $hidden, true) || ($fields[$k]['type'] ?? '') === 'ref';
                $pair = is_array($v) && array_key_exists('to', $v) ? $v : ['from' => null, 'to' => $v];
                $out[] = ['field' => $fields[$k]['label'] ?? $k, 'from' => $secret ? null : $show($k, $pair['from']), 'to' => $secret ? null : $show($k, $pair['to'])];
            }

            return ['occurred_at' => $r->occurred_at, 'action' => $r->action, 'actor' => $r->actor_name ?? 'Sistema', 'actor_role' => $r->actor_role, 'support' => (bool) $r->is_support_session, 'changes' => array_slice($out, 0, 12)];
        }, $rows)]);
    }

    public function options(Request $request, string $module, string $table, string $field): JsonResponse
    {
        $res = $this->resource($request, $module, $table, 'read');

        return response()->json(['data' => $this->records->options($res, $field, $request->query('q'), $request->query('id'))]);
    }

    private function resource(Request $request, string $module, string $table, string $action): array
    {
        $res = Registry::resource($module, $table);
        $this->gate($request, $module);
        $this->records->authorize($res, $action);

        return $res;
    }

    /** Mismo control de plan que el middleware module:XXX (habilitado / solo lectura / bloqueado). */
    private function gate(Request $request, string $module): void
    {
        if (Registry::tables($module) === []) {
            throw \App\Core\Http\ApiProblem::notFound('Ese módulo no tiene registros.');
        }
        app(FeatureGate::class)->handle($request, fn () => new Response, strtoupper($module));
    }
}
