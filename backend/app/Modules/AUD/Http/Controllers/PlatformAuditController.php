<?php

namespace App\Modules\AUD\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel del dueño · Bitácora de auditoría de la plataforma (SCR-AUD-01, docs/modulos/M-AUD.md).
 * Eventos de plataforma (company_id NULL): planes, complementos, cuentas, suscripciones, pagos, avisos automáticos.
 * La bitácora es solo INSERT y encadenada por hash (RN-AUD-01); aquí solo se consulta y se verifica la cadena.
 *
 *  GET /internal/audit?from=&to=&action=&actor=&q=&page=   eventos, filtros disponibles y resumen
 *  GET /internal/audit/verify                              verificación de la cadena de hash
 */
class PlatformAuditController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'action' => ['nullable', 'string', 'max:60'],
            'actor' => ['nullable', 'string', 'max:40'],   // id de usuario o «system»
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ], ['to.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.']);

        $db = DB::connection('platform');
        $where = ['l.company_id is null'];
        $bindings = [];
        // Fechas en hora de Lima (RN-AUD-08).
        if (! empty($data['from'])) {
            $where[] = "l.occurred_at >= (?::date)::timestamp at time zone 'America/Lima'";
            $bindings[] = $data['from'];
        }
        if (! empty($data['to'])) {
            $where[] = "l.occurred_at < ((?::date) + 1)::timestamp at time zone 'America/Lima'";
            $bindings[] = $data['to'];
        }
        if (! empty($data['action'])) {
            $where[] = 'l.action = ?';
            $bindings[] = $data['action'];
        }
        if (! empty($data['actor'])) {
            if ($data['actor'] === 'system') {
                $where[] = 'l.actor_user_id is null';
            } else {
                $where[] = 'l.actor_user_id::text = ?';
                $bindings[] = $data['actor'];
            }
        }
        if (! empty($data['q'])) {
            $where[] = "(l.changes::text ilike ? or coalesce(l.reason, '') ilike ? or coalesce(u.full_name, '') ilike ?)";
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $data['q']).'%';
            array_push($bindings, $like, $like, $like);
        }
        $sqlWhere = implode(' and ', $where);
        $page = (int) ($data['page'] ?? 1);

        $total = (int) $db->selectOne("select count(*) as n from audit_logs l left join users u on u.id = l.actor_user_id where {$sqlWhere}", $bindings)->n;
        $events = array_map(fn ($r) => [
            'id' => $r->id,
            'chain_seq' => (int) $r->chain_seq,
            'occurred_at' => $r->occurred_at,
            'action' => $r->action,
            'module_code' => $r->module_code,
            'entity_type' => $r->entity_type,
            'entity_id' => $r->entity_id,
            'actor' => $r->actor_name,
            'actor_type' => $r->actor_type,
            'reason' => $r->reason,
            'changes' => json_decode($r->changes ?? 'null', true),
        ], $db->select(
            "select l.id, l.chain_seq, l.occurred_at, l.action, l.module_code, l.entity_type, l.entity_id, l.actor_type, l.reason, l.changes,
                    u.full_name as actor_name
               from audit_logs l left join users u on u.id = l.actor_user_id
              where {$sqlWhere}
              order by l.occurred_at desc, l.chain_seq desc
              limit ".self::PER_PAGE.' offset '.(($page - 1) * self::PER_PAGE),
            $bindings
        ));

        // Filtros disponibles y resumen (sobre toda la bitácora de plataforma, sin filtrar).
        $actions = $db->select('select action, count(*) as n from audit_logs where company_id is null group by action order by n desc');
        $actors = $db->select(
            "select coalesce(l.actor_user_id::text, 'system') as id, coalesce(u.full_name, 'Sistema (automático)') as name, count(*) as n
               from audit_logs l left join users u on u.id = l.actor_user_id
              where l.company_id is null group by 1, 2 order by n desc"
        );
        $stats = $db->selectOne(
            "select count(*) as total,
                    count(*) filter (where occurred_at >= date_trunc('day', now() at time zone 'America/Lima') at time zone 'America/Lima') as today,
                    count(*) filter (where occurred_at >= now() - interval '7 days') as week,
                    count(*) filter (where actor_user_id is null) as system
               from audit_logs where company_id is null"
        );

        return response()->json([
            'data' => $events,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE, 'pages' => max(1, (int) ceil($total / self::PER_PAGE))],
            'facets' => [
                'actions' => array_map(fn ($r) => ['action' => $r->action, 'count' => (int) $r->n], $actions),
                'actors' => array_map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'count' => (int) $r->n], $actors),
            ],
            'stats' => ['total' => (int) $stats->total, 'today' => (int) $stats->today, 'week' => (int) $stats->week, 'system' => (int) $stats->system],
        ]);
    }

    /** RN-AUD-01: comprueba que ningún evento de plataforma fue alterado, borrado ni reordenado. */
    public function verify(): JsonResponse
    {
        $db = DB::connection('platform');
        $problems = $db->select('select chain_seq, problem from audit_logs_verify_chain(null)');
        $checked = (int) $db->selectOne('select count(*) as n from audit_logs where company_id is null')->n;

        return response()->json(['data' => [
            'ok' => count($problems) === 0,
            'checked' => $checked,
            'problems' => array_map(fn ($p) => ['chain_seq' => (int) $p->chain_seq, 'problem' => $p->problem], $problems),
            'verified_at' => now()->toIso8601String(),
        ]]);
    }
}
