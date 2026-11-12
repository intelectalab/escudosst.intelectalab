<?php

namespace App\Modules\NOT;

use App\Core\Support\Providers;
use Illuminate\Support\Facades\Http;

/**
 * Envío por WhatsApp con la API Cloud de Meta (Graph API), configurada en Panel › Proveedores y claves › WhatsApp.
 * Solo plantillas aprobadas (fuera de la ventana de 24 h es lo único permitido) y sin datos sensibles en las variables.
 *  POST https://graph.facebook.com/{versión}/{id-del-número}/messages   {messaging_product: whatsapp, type: template, ...}
 * El token nunca se registra ni se devuelve; los errores se guardan sin él.
 */
final class WhatsAppCloud
{
    public const GRAPH = 'https://graph.facebook.com/v21.0';

    private array $p;

    public function __construct(?array $provider = null)
    {
        $this->p = $provider ?? Providers::get('whatsapp');
    }

    /** ¿Está en vivo con la API Cloud de Meta? (otro BSP aún no está integrado: se queda en la app). */
    public function ready(): bool
    {
        return $this->p['live'] && $this->p['provider'] === 'meta';
    }

    public function template(string $purpose): ?string
    {
        $t = trim((string) ($this->p['settings']['template_'.$purpose] ?? ''));

        return $t !== '' ? $t : null;
    }

    public function language(): string
    {
        return trim((string) ($this->p['settings']['template_lang'] ?? '')) ?: 'es';
    }

    /** Número en el formato que pide Meta (solo dígitos, con código de país; 9 dígitos que empiezan en 9 = celular de Perú). */
    public static function normalize(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($d) === 9 && $d[0] === '9') {
            $d = '51'.$d;
        }

        return strlen($d) >= 8 && strlen($d) <= 15 ? $d : null;
    }

    /**
     * Respuesta de texto dentro de una conversación que inició la persona (ventana de 24 horas de Meta: ahí no hace falta
     * plantilla). Lo usa la conversación de reportes (ACT/WhatsAppIntake).
     *
     * @return array{ok: bool, id: ?string, error: ?string, code: ?string}
     */
    public function sendText(string $to, string $text): array
    {
        $number = self::normalize($to);
        if (! $number) {
            return ['ok' => false, 'id' => null, 'error' => 'Número de WhatsApp no válido.', 'code' => 'invalid_number'];
        }

        return $this->post(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $number, 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4000)]]);
    }

    /**
     * Envía una plantilla.
     *
     * @param  list<string>  $params  variables del cuerpo {{1}}, {{2}}…
     * @return array{ok: bool, id: ?string, error: ?string, code: ?string}
     */
    public function sendTemplate(string $to, string $template, array $params = [], ?string $language = null): array
    {
        $number = self::normalize($to);
        if (! $number) {
            return ['ok' => false, 'id' => null, 'error' => 'Número de WhatsApp no válido.', 'code' => 'invalid_number'];
        }
        $tpl = ['name' => $template, 'language' => ['code' => $language ?? $this->language()]];
        if ($params) {
            $tpl['components'] = [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => self::clean((string) $v)], array_values($params))]];
        }

        return $this->post(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $number, 'type' => 'template', 'template' => $tpl]);
    }

    /**
     * Prueba sin enviar: lee el número configurado con el token (verifica ID y token).
     *
     * @return array{0: bool, 1: string}
     */
    public function check(): array
    {
        [$phoneId, $token] = [trim((string) ($this->p['settings']['phone_id'] ?? '')), (string) ($this->p['secrets']['token'] ?? '')];
        if ($phoneId === '' || $token === '') {
            return [false, 'Faltan el ID del número o el token de acceso.'];
        }
        try {
            $r = Http::timeout(20)->withToken($token)->get(self::GRAPH.'/'.rawurlencode($phoneId), ['fields' => 'display_phone_number,verified_name,quality_rating']);
        } catch (\Throwable $e) {
            return [false, 'No se pudo conectar con Meta: '.$this->redact($e->getMessage())];
        }
        if (! $r->successful()) {
            return [false, 'Meta respondió '.$r->status().': '.$this->redact((string) ($r->json('error.message') ?? 'error'))];
        }

        return [true, 'Conexión correcta: '.trim(($r->json('verified_name') ?? '').' '.($r->json('display_phone_number') ?? '')).'.'];
    }

    /** @return array{ok: bool, id: ?string, error: ?string, code: ?string} */
    private function post(array $payload): array
    {
        [$phoneId, $token] = [trim((string) ($this->p['settings']['phone_id'] ?? '')), (string) ($this->p['secrets']['token'] ?? '')];
        if ($phoneId === '' || $token === '') {
            return ['ok' => false, 'id' => null, 'error' => 'WhatsApp no está configurado.', 'code' => 'not_configured'];
        }
        try {
            $r = Http::timeout(20)->withToken($token)->acceptJson()->post(self::GRAPH.'/'.rawurlencode($phoneId).'/messages', $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => 'No se pudo conectar con Meta: '.$this->redact($e->getMessage()), 'code' => 'network'];
        }
        if ($r->successful() && $r->json('messages.0.id')) {
            return ['ok' => true, 'id' => (string) $r->json('messages.0.id'), 'error' => null, 'code' => null];
        }

        return ['ok' => false, 'id' => null, 'error' => 'Meta respondió '.$r->status().': '.$this->redact((string) ($r->json('error.error_data.details') ?? $r->json('error.message') ?? 'error')),
            'code' => (string) ($r->json('error.code') ?? $r->status())];
    }

    /** Meta no acepta saltos de línea, tabulaciones ni más de 4 espacios seguidos en las variables. */
    private static function clean(string $v): string
    {
        $v = preg_replace('/\s+/u', ' ', trim($v)) ?? '';

        return mb_substr($v !== '' ? $v : '-', 0, 900);
    }

    private function redact(string $msg): string
    {
        $token = (string) ($this->p['secrets']['token'] ?? '');

        return mb_substr($token !== '' ? str_replace($token, '***', $msg) : $msg, 0, 300);
    }
}
