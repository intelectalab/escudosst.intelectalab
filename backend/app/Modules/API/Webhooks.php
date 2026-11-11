<?php

namespace App\Modules\API;

use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Webhooks (complemento API): cuando ocurre algo, se encola un envío para cada webhook activo suscrito a ese evento;
 * «php artisan escudo:webhooks» (cada minuto) los envía firmados con HMAC-SHA256 (cabecera X-EscudoSST-Signature)
 * y reintenta con espera creciente; tras 6 intentos fallidos lo abandona y, con 20 fallos seguidos, pausa el webhook.
 * Eventos: event.created, action.created, action.verified, inspection.completed, report.received.
 */
final class Webhooks
{
    public const TEST_EVENT = 'webhook.test';

    public const EVENTS = [
        'sst_events' => ['create' => 'event.created'],
        'corrective_actions' => ['create' => 'action.created', 'verified' => 'action.verified'],
        'inspections' => ['completed' => 'inspection.completed'],
        'substandard_reports' => ['create' => 'report.received'],
    ];

    /** Encola los envíos de un cambio (lo llama el motor de registros). */
    public static function queue(string $table, string $op, string $id, array $values): void
    {
        $map = self::EVENTS[$table] ?? null;
        if (! $map) {
            return;
        }
        $code = $op === 'create' ? ($map['create'] ?? null) : ($map[$values['status'] ?? ''] ?? null);
        if (! $code) {
            return;
        }
        try {
            $hooks = DB::select('select id, company_id from webhooks where is_active and deleted_at is null and ? = any(event_codes)', [$code]);
        } catch (\Throwable) {
            return;   // sin módulo o sin permiso: no se interrumpe el registro
        }
        foreach ($hooks as $h) {
            $body = ['event' => $code, 'id' => $id, 'table' => $table, 'occurred_at' => now()->toIso8601String()];
            DB::insert("insert into webhook_deliveries (company_id, webhook_id, event_code, event_id, request_body, payload_sha256, status, next_retry_at)
                        values (?, ?, ?, ?, ?::jsonb, decode(?, 'hex'), 'pending', now())",
                [$h->company_id, $h->id, $code, $id, json_encode($body), hash('sha256', json_encode($body))]);
        }
    }

    /** Envía lo pendiente de todas las empresas. @return int envíos hechos */
    public static function dispatch(AccountsService $accounts): int
    {
        $companies = DB::connection('platform')->select("select distinct company_id from feature_flags where module_code = 'API' and mode = 'enabled' and deleted_at is null");
        $sent = 0;
        foreach ($companies as $c) {
            $sent += $accounts->withCompany($c->company_id, function (ConnectionInterface $db) {
                $n = 0;
                foreach ($db->select("select d.*, w.target_url, w.secret, w.failure_count from webhook_deliveries d join webhooks w on w.id = d.webhook_id
                                       where d.status = 'pending' and d.deleted_at is null and d.next_retry_at <= now() and w.is_active order by d.created_at limit 50") as $d) {
                    $n++;
                    self::send($db, $d);
                }

                return $n;
            });
        }

        return $sent;
    }

    /**
     * Envía una entrega (fila de webhook_deliveries unida a su webhook: target_url y secret) y guarda el resultado.
     *
     * @return bool si el destino respondió 2xx
     */
    public static function send(ConnectionInterface $db, object $d): bool
    {
        $secret = self::secret($d->secret);
        $body = is_string($d->request_body) ? $d->request_body : json_encode($d->request_body);
        $started = microtime(true);
        try {
            $r = Http::timeout(10)->withHeaders([
                'Content-Type' => 'application/json', 'X-EscudoSST-Event' => $d->event_code, 'X-EscudoSST-Delivery' => $d->id,
                'X-EscudoSST-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ])->withBody($body, 'application/json')->post($d->target_url);
            $ok = $r->successful();
            $status = $r->status();
            $err = $ok ? null : mb_substr($r->body(), 0, 300);
        } catch (\Throwable $e) {
            [$ok, $status, $err] = [false, null, mb_substr($e->getMessage(), 0, 300)];
        }
        $ms = (int) ((microtime(true) - $started) * 1000);
        if ($ok) {
            $db->update("update webhook_deliveries set status = 'delivered', delivered_at = now(), response_status = ?, response_ms = ? where id = ?", [$status, $ms, $d->id]);
            $db->update('update webhooks set failure_count = 0 where id = ?', [$d->webhook_id]);
        } elseif ($d->event_code === self::TEST_EVENT) {
            // La prueba no se reintenta ni cuenta para pausar el webhook.
            $db->update("update webhook_deliveries set status = 'failed', response_status = ?, response_ms = ?, error = ?, next_retry_at = null where id = ?", [$status, $ms, $err, $d->id]);
        } else {
            $next = (int) $d->attempt + 1;
            $db->update("update webhook_deliveries set attempt = ?, response_status = ?, response_ms = ?, error = ?,
                           status = case when ? > 6 then 'abandoned' else 'pending' end, next_retry_at = now() + make_interval(mins => ?::int) where id = ?",
                [$next, $status, $ms, $err, $next, 2 ** min($next, 8), $d->id]);
            $db->update('update webhooks set failure_count = failure_count + 1, is_active = case when failure_count + 1 >= 20 then false else is_active end,
                           disabled_at = case when failure_count + 1 >= 20 then now() else disabled_at end where id = ?', [$d->webhook_id]);
        }

        return $ok;
    }

    /**
     * «Enviar prueba» (SCR-API-02): registra una entrega con el evento webhook.test y la envía en el momento.
     *
     * @return array{id: string, status: string, response_status: ?int, error: ?string}
     */
    public static function test(object $webhook): array
    {
        $eventId = (string) Str::uuid7();
        $body = json_encode(['event' => self::TEST_EVENT, 'id' => $eventId, 'table' => 'webhooks', 'occurred_at' => now()->toIso8601String()]);
        $id = DB::selectOne("insert into webhook_deliveries (company_id, webhook_id, event_code, event_id, request_body, payload_sha256, status, next_retry_at)
                             values (?, ?, ?, ?, ?::jsonb, decode(?, 'hex'), 'pending', null) returning id",
            [$webhook->company_id, $webhook->id, self::TEST_EVENT, $eventId, $body, hash('sha256', $body)])->id;
        $d = DB::selectOne('select d.*, w.target_url, w.secret from webhook_deliveries d join webhooks w on w.id = d.webhook_id where d.id = ?', [$id]);
        self::send(DB::connection(), $d);
        $r = DB::selectOne('select id, status, response_status, error from webhook_deliveries where id = ?', [$id]);

        return ['id' => $r->id, 'status' => $r->status, 'response_status' => $r->response_status, 'error' => $r->error];
    }

    /** El secreto de firma se guarda cifrado; los creados antes de este cambio, en claro. */
    public static function secret(string $stored): string
    {
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return $stored;
        }
    }
}
