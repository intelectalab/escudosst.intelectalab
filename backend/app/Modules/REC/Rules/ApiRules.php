<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * API · Clientes de la API y webhooks (docs/modulos/M-API.md §8, RN-API-03).
 * - Cliente: la clave pública se genera sola; el secreto se genera al crearlo, se guarda solo su hash y se muestra
 *   una única vez en la respuesta de creación. Después solo se puede rotar (nuevo secreto, el anterior deja de servir)
 *   o revocar, que es definitivo.
 * - Webhook: la URL debe ser https; su secreto de firma (HMAC) se genera solo, se guarda cifrado (hace falta para
 *   firmar cada envío) y se muestra una única vez al crearlo o al rotarlo.
 */
class ApiRules extends RecordRules
{
    /** Secreto recién generado en esta solicitud (se entrega una vez con la respuesta de creación). */
    private static ?string $fresh = null;

    /** «fitness.read» (solo la aptitud, RN-API-01) además necesita la aprobación de la empresa: POST /api-clients/{id}/fitness-approval. */
    public const SCOPES = ['read', '*', 'workers.read', 'events.read', 'actions.read', 'inspections.read', 'trainings.read', 'credentials.read', 'access_events.read', 'write', 'workers.write',
        'fitness.read'];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        if ($res['table'] === 'api_clients') {
            if ($op === 'create') {
                $secret = self::newClientSecret();
                $extra['client_key'] = ['?', ['esc_'.Str::lower(Str::random(24))]];
                $extra['secret_hash'] = ['?', [self::hashClientSecret($secret)]];
                $values['scopes'] ??= ['read'];
                $values['status'] = 'active';
                self::$fresh = $secret;
            }
            if ($current && $current->status === 'revoked' && $values !== []) {
                $this->invalidTransition('El cliente está revocado: crea uno nuevo.');
            }
            // Permisos por recurso (M-API SCR-API-01): «read», «*» o «recurso.read»; ningún permiso abre datos de salud (RN-API-01).
            foreach ((array) ($values['scopes'] ?? []) as $sc) {
                if (! in_array($sc, self::SCOPES, true)) {
                    $this->fail('scopes', "Permiso «{$sc}» no válido. Usa: ".implode(', ', self::SCOPES).'.');
                }
            }
            // RN-API-01: la aprobación de la aptitud vale para el cliente tal como se aprobó; si se quita «fitness.read», se pierde
            // (y si se vuelve a agregar, hay que aprobarla de nuevo).
            if ($current && array_key_exists('scopes', $values) && ! in_array('fitness.read', (array) $values['scopes'], true) && $current->fitness_approved_at) {
                $extra['fitness_approved_by'] = ['null', []];
                $extra['fitness_approved_at'] = ['null', []];
            }

            return;
        }
        if ($res['table'] === 'webhooks') {
            $url = (string) $this->value($values, $current, 'target_url');
            if ($url !== '' && (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($url), 'https://'))) {
                $this->fail('target_url', 'La dirección debe empezar con https://');
            }
            if ($op === 'create') {
                $secret = self::newWebhookSecret();
                $extra['secret'] = ['?', [Crypt::encryptString($secret)]];
                $values['event_codes'] ??= [];
                self::$fresh = $secret;
            }
        }
    }

    public function reveal(array $res, string $id): array
    {
        $secret = self::$fresh;
        self::$fresh = null;

        return $secret === null ? [] : ['secret' => $secret];
    }

    public static function newClientSecret(): string
    {
        return 'sk_'.Str::random(40);
    }

    public static function hashClientSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function newWebhookSecret(): string
    {
        return 'whsec_'.Str::random(32);
    }
}
