<?php

namespace App\Modules\AUD\Http\Controllers;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bitácora de la empresa activa (M-AUD SCR-AUD-01 y SCR-AUD-03).
 *  GET /company/audit            quién cambió qué y cuándo. Filtros: module, entity_id, user_id, action, from, to, page.
 *                                Cada fila trae el módulo y la tabla para abrir el registro, desde dónde (app o web, IP
 *                                abreviada) y el antes/después de cada campo cambiado (los cifrados nunca muestran valor).
 *  GET /company/audit/filters    usuarios y módulos con movimientos (para los selectores) y qué pestañas puede ver el rol
 *  GET /company/audit/sensitive  accesos a datos sensibles (sensitive_access_logs): fecha y hora, usuario, dato consultado,
 *                                motivo, re-autenticación y resultado. Nunca el contenido médico. Filtros: user_id, from, to, page.
 * La hora se guarda en UTC y se filtra por días de America/Lima (RN-AUD-08).
 */
class CompanyAuditController extends Controller
{
    private const RESOURCES = [
        'medical_exam' => 'EMO', 'medical_file' => 'Archivo médico', 'clinical_note' => 'Nota clínica', 'leave_detail' => 'Detalle de descanso médico',
        'vulnerability_record' => 'Registro de vulnerabilidad', 'surveillance_case' => 'Caso de vigilancia médica', 'medical_export' => 'Exportación médica',
        'worker_sensitive_data' => 'Datos sensibles',
    ];

    private const ACTIONS = ['read' => 'Consultó', 'download' => 'Descargó', 'export' => 'Exportó', 'create' => 'Registró', 'update' => 'Modificó', 'print' => 'Imprimió'];

    public function __invoke(Request $request): JsonResponse
    {
        $this->can('aud.audit_logs.read', 'Tu rol no ve la bitácora.');
        $where = ['a.company_id = (select nullif(current_setting(\'app.company_id\', true), \'\')::uuid)'];
        $bind = [];
        if ($m = $request->query('module')) {
            $where[] = 'a.module_code = ?';
            $bind[] = strtoupper(substr((string) $m, 0, 3));
        }
        if (($act = $request->query('action')) && preg_match('/^[a-z_]{2,40}$/', (string) $act)) {
            $where[] = 'a.action = ?';
            $bind[] = $act;
        }
        foreach (['entity_id', 'user_id'] as $k) {
            if (($v = $request->query($k)) && Str::isUuid((string) $v)) {
                $where[] = ($k === 'user_id' ? 'a.actor_user_id' : 'a.entity_id').' = ?';
                $bind[] = $v;
            }
        }
        $this->dates($request, 'a.occurred_at', $where, $bind);
        $page = max(1, (int) $request->query('page', 1));
        $sql = 'from audit_logs a left join users u on u.id = a.actor_user_id where '.implode(' and ', $where);
        $total = (int) DB::selectOne("select count(*) as n {$sql}", $bind)->n;
        $rows = DB::select("select a.id, a.occurred_at, a.action, a.module_code, a.entity_type, a.entity_id, a.changes, a.actor_role, a.is_support_session,
                                   host(a.ip) as ip, a.user_agent, u.full_name as actor_name
                              {$sql} order by a.occurred_at desc, a.chain_seq desc limit 50 offset ".(($page - 1) * 50), $bind);
        $resources = Registry::data()['resources'];

        return response()->json([
            'data' => array_map(function ($r) use ($resources) {
                $res = $resources[$r->entity_type] ?? null;
                $changes = json_decode((string) $r->changes, true);

                return [
                    ...(array) $r,
                    'ip' => self::maskIp($r->ip),
                    'user_agent' => null,
                    'channel' => self::channel($r->user_agent),
                    'changes' => $changes,
                    'diff' => $this->diff($res, $r->action, is_array($changes) ? $changes : []),
                    'tab' => $res['tab'] ?? $r->entity_type,
                    'module_of_entity' => $res['module'] ?? null,
                ];
            }, $rows),
            'meta' => ['page' => $page, 'total' => $total, 'pages' => (int) max(1, ceil($total / 50))],
        ]);
    }

    public function filters(): JsonResponse
    {
        $general = $this->has('aud.audit_logs.read');
        $sensitive = $this->has('aud.sensitive_access_logs.read');
        if (! $general && ! $sensitive) {
            throw ApiProblem::forbidden('Tu rol no ve la bitácora.');
        }
        $users = $general ? DB::select(
            "select distinct u.id, u.full_name from audit_logs a join users u on u.id = a.actor_user_id
              where a.company_id = (select nullif(current_setting('app.company_id', true), '')::uuid) order by u.full_name limit 300"
        ) : [];
        $modules = $general ? DB::select(
            "select distinct a.module_code as code, m.name from audit_logs a left join modules m on m.code = a.module_code and m.deleted_at is null
              where a.company_id = (select nullif(current_setting('app.company_id', true), '')::uuid) and a.module_code is not null order by 1"
        ) : [];
        $sensitiveUsers = $sensitive ? DB::select('select distinct u.id, u.full_name from sensitive_access_logs s join users u on u.id = s.user_id order by u.full_name limit 300') : [];

        return response()->json(['data' => [
            'can' => ['general' => $general, 'sensitive' => $sensitive],
            'users' => $users,
            'modules' => array_map(fn ($m) => ['code' => trim((string) $m->code), 'name' => $m->name ?? trim((string) $m->code)], $modules),
            'sensitive_users' => $sensitiveUsers,
        ]]);
    }

    public function sensitive(Request $request): JsonResponse
    {
        $this->can('aud.sensitive_access_logs.read', 'Tu rol no ve los accesos a datos sensibles.');
        [$sql, $bind] = $this->sensitiveQuery($request);
        $page = max(1, (int) $request->query('page', 1));
        $total = (int) DB::selectOne("select count(*) as n {$sql}", $bind)->n;
        $rows = DB::select(self::SENSITIVE_COLS." {$sql} order by s.occurred_at desc limit 50 offset ".(($page - 1) * 50), $bind);

        return response()->json([
            'data' => array_map(fn ($r) => $this->sensitiveRow($r), $rows),
            'meta' => ['page' => $page, 'total' => $total, 'pages' => (int) max(1, ceil($total / 50))],
        ]);
    }

    /**
     * GET /company/audit/sensitive/export  «Exportar» (CSV) de los accesos a datos sensibles con los mismos filtros
     * (máximo 5000 filas). Nunca incluye contenido médico; la exportación misma queda en la bitácora.
     */
    public function sensitiveExport(Request $request): StreamedResponse
    {
        $this->can('aud.sensitive_access_logs.read', 'Tu rol no ve los accesos a datos sensibles.');
        [$sql, $bind] = $this->sensitiveQuery($request);
        $rows = array_map(fn ($r) => $this->sensitiveRow($r), DB::select(self::SENSITIVE_COLS." {$sql} order by s.occurred_at desc limit 5000", $bind));
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, changes, ip, user_agent)
             values (?, ?, ?, 'user', 'export', 'AUD', 'sensitive_access_logs', ?::jsonb, ?::inet, ?)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, json_encode(['rows' => count($rows), 'filters' => array_filter($request->only(['user_id', 'result', 'from', 'to']))]),
                $request->ip(), mb_substr((string) $request->userAgent(), 0, 300)]
        );

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "ï»¿");
            fputcsv($out, ['Fecha y hora (Lima)', 'Usuario', 'Rol', 'Dato consultado', 'Acción', 'Motivo', 'Resultado', 'Motivo del rechazo', 'Re-autenticación', 'IP'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    date_create((string) $r['occurred_at'])->setTimezone(new \DateTimeZone('America/Lima'))->format('d/m/Y H:i:s'),
                    $r['user_name'], $r['role_name'], $r['data_label'], $r['action'], $r['purpose'],
                    $r['result'] === 'granted' ? 'Aceptado' : 'Rechazado', $r['deny_reason'], $r['reauth'] ? 'Sí' : 'No', $r['ip'],
                ], ';');
            }
            fclose($out);
        }, 'accesos-datos-sensibles-'.now('America/Lima')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private const SENSITIVE_COLS = 'select s.id, s.occurred_at, s.resource, s.action, s.purpose, s.result, s.deny_reason, s.reauth_session_id is not null as reauth,
                                   host(s.ip) as ip, s.user_id, u.full_name as user_name, coalesce(r.name, s.role_code) as role_name, w.full_name as worker_name,
                                   s.user_id = (select user_id from workers where id = s.worker_id) as own_data';

    /** @return array{0: string, 1: array} from/where de los accesos sensibles con los filtros user_id, result, from, to. */
    private function sensitiveQuery(Request $request): array
    {
        $where = ['true'];
        $bind = [];
        if (($v = $request->query('user_id')) && Str::isUuid((string) $v)) {
            $where[] = 's.user_id = ?';
            $bind[] = $v;
        }
        if (in_array($res = $request->query('result'), ['granted', 'denied'], true)) {
            $where[] = 's.result = ?';
            $bind[] = $res;
        }
        $this->dates($request, 's.occurred_at', $where, $bind);

        return ['from sensitive_access_logs s left join users u on u.id = s.user_id left join workers w on w.id = s.worker_id
                left join roles r on r.code = s.role_code and r.account_id is null where '.implode(' and ', $where), $bind];
    }

    private function sensitiveRow(object $r): array
    {
        return [
            'id' => $r->id,
            'occurred_at' => $r->occurred_at,
            'user_name' => $r->user_name ?? 'Usuario',
            'role_name' => $r->role_name,
            // Qué dato se consultó y de quién; nunca su contenido (RN-AUD-02, RN-AUD-07).
            'data_label' => (self::RESOURCES[$r->resource] ?? $r->resource).($r->own_data ? ' propios' : ($r->worker_name ? " de {$r->worker_name}" : '')),
            'action' => self::ACTIONS[$r->action] ?? $r->action,
            'purpose' => $r->purpose,
            'reauth' => (bool) $r->reauth,
            'result' => $r->result,
            'deny_reason' => $r->deny_reason,
            'ip' => self::maskIp($r->ip),
        ];
    }

    /** Antes y después por campo, con su etiqueta; los cifrados y referencias no muestran valor (solo que cambiaron). */
    private function diff(?array $res, string $action, array $changes): array
    {
        $fields = $res ? array_column($res['fields'], null, 'name') : [];
        $show = function (string $k, mixed $v) use ($fields): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            if (! is_scalar($v)) {
                return mb_substr(json_encode($v, JSON_UNESCAPED_UNICODE), 0, 300);
            }
            foreach ($fields[$k]['options'] ?? [] as $o) {
                if ((string) $o['value'] === (string) $v) {
                    return $o['label'];
                }
            }

            return is_bool($v) ? ($v ? 'Sí' : 'No') : mb_substr((string) $v, 0, 300);
        };
        $out = [];
        foreach ($changes as $k => $v) {
            if (in_array($k, ['updated_at', 'updated_by', 'created_at', 'created_by', 'company_id', 'id', 'row_version'], true)) {
                continue;
            }
            $f = $fields[$k] ?? null;
            $hidden = ! empty($f['encrypted']) || $v === '[cifrado]' || (is_array($v) && (($v['to'] ?? null) === '[cifrado]'));
            $pair = is_array($v) && (array_key_exists('to', $v) || array_key_exists('from', $v)) ? $v : ['from' => null, 'to' => $v];
            if ($action === 'delete' && $k === 'label') {
                $out[] = ['field' => 'Registro', 'from' => $show($k, $pair['to']), 'to' => null, 'hidden' => false];

                continue;
            }
            $isRef = ($f['type'] ?? '') === 'ref' || ($f['type'] ?? '') === 'file';
            $out[] = [
                'field' => $f['label'] ?? str_replace('_', ' ', (string) $k),
                'from' => $hidden ? null : ($isRef && $pair['from'] ? 'Otro registro' : $show($k, $pair['from'] ?? null)),
                'to' => $hidden ? null : ($isRef && $pair['to'] ? 'Registro vinculado' : $show($k, $pair['to'] ?? null)),
                'hidden' => $hidden,
            ];
        }

        return array_slice($out, 0, 40);
    }

    private function dates(Request $request, string $col, array &$where, array &$bind): void
    {
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            if (($v = $request->query($k)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) {
                $where[] = "{$col} {$op} (?::date".($k === 'to' ? ' + 1' : '').")::timestamp at time zone 'America/Lima'";
                $bind[] = $v;
            }
        }
    }

    /** «App» si vino de la app móvil o sin conexión; «Web» desde el navegador; null si no se registró. */
    private static function channel(?string $ua): ?string
    {
        if (! $ua) {
            return null;
        }

        return preg_match('/EscudoSST-App|Android|iPhone|iPad|Mobile/i', $ua) ? 'App' : 'Web';
    }

    private static function maskIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }
        if (str_contains($ip, '.')) {
            $p = explode('.', $ip);

            return "{$p[0]}.{$p[1]}.xx.xx";
        }

        return implode(':', array_slice(explode(':', $ip), 0, 3)).':…';
    }

    private function has(string $p): bool
    {
        return in_array($p, Permissions::effective(), true);
    }

    private function can(string $p, string $msg): void
    {
        if (! $this->has($p)) {
            throw ApiProblem::forbidden($msg);
        }
    }
}
