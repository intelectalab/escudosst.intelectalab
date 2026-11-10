<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * API · Clientes de la API y webhooks (docs/modulos/M-API.md §8).
 * - Cliente: la clave pública se genera sola; el secreto se genera al crearlo, se guarda solo su hash y se muestra
 *   una única vez (queda 10 minutos para leerlo con «Ver secreto»). Revocar es definitivo.
 * - Webhook: la URL debe ser https; su secreto de firma (HMAC) se genera solo.
 */
class ApiRules extends RecordRules
{
    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        if ($res['table'] === 'api_clients') {
            if ($op === 'create') {
                $secret = 'sk_'.Str::random(40);
                $key = 'esc_'.Str::lower(Str::random(24));
                $extra['client_key'] = ['?', [$key]];
                $extra['secret_hash'] = ['?', [hash('sha256', $secret)]];
                $values['scopes'] ??= ['read'];
                $values['status'] = 'active';
                Cache::put('api-secret:'.$key, $secret, now()->addMinutes(10));
            }
            if ($current && $current->status === 'revoked' && $values !== []) {
                $this->conflict('El cliente está revocado: crea uno nuevo.');
            }

            return;
        }
        if ($res['table'] === 'webhooks') {
            $url = (string) $this->value($values, $current, 'target_url');
            if ($url !== '' && (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($url), 'https://'))) {
                $this->fail('target_url', 'La dirección debe empezar con https://');
            }
            if ($op === 'create') {
                $extra['secret'] = ['?', ['whsec_'.Str::random(32)]];
                $values['event_codes'] ??= [];
            }
        }
    }

    /** Secreto recién creado (una sola lectura, mientras no venza). */
    public static function pullSecret(string $clientId): ?string
    {
        $key = DB::selectOne('select client_key from api_clients where id = ?', [$clientId])?->client_key;

        return $key ? Cache::pull('api-secret:'.$key) : null;
    }
}
