<?php

namespace App\Modules\IAA;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;

/**
 * Conexión opcional a un proveedor de IA (M-IAA RN-IAA-07: detrás de esta interfaz se cambia de proveedor). APAGADA por
 * defecto: solo funciona si en el servidor se configuran AI_PROVIDER=anthropic y AI_API_KEY. Mientras tanto el asistente
 * responde con la biblioteca y las reglas de EscudoSST.
 *  - Recibe solo texto ya redactado (sin nombres, DNI, teléfonos ni correos: RN-IAA-03) y nunca datos de salud.
 *  - La respuesta debe cumplir el esquema JSON del caso de uso; si no lo cumple se descarta (RN-IAA-04).
 *  - Se usa solo cuando la biblioteca no encontró el tema; el límite mensual de la empresa se controla antes (RN-IAA-06).
 */
final class AiProvider
{
    private const FEATURE_PROMPT = [
        'controls' => 'Propón controles para el peligro o la tarea descrita, ordenados por la jerarquía de controles (eliminación, sustitución, ingeniería, administrativos, EPP).',
        'causes' => 'Propón preguntas guía y posibles causas (inmediatas y básicas) para investigar el accidente o incidente descrito.',
        'talk' => 'Escribe los puntos de una charla de seguridad de 5 minutos sobre el tema indicado.',
    ];

    /** Panel › Proveedores › Asistente IA (encendida y con clave) o, si no, las variables AI_* del servidor. */
    private static function settings(): array
    {
        try {
            $p = \App\Core\Support\Providers::get('ai');
            if ($p['live'] && $p['provider'] === 'anthropic') {
                return ['key' => (string) $p['secrets']['api_key'], 'model' => trim((string) ($p['settings']['model'] ?? '')) ?: 'claude-opus-5-5'];
            }
        } catch (\Throwable) {
            // Sin tabla o sin conexión: se usa el servidor.
        }
        if (config('escudo.ai.provider') === 'anthropic' && (string) config('escudo.ai.api_key') !== '') {
            return ['key' => (string) config('escudo.ai.api_key'), 'model' => (string) config('escudo.ai.model', 'claude-opus-5-5')];
        }

        return ['key' => '', 'model' => 'claude-opus-5-5'];
    }

    public static function enabled(): bool
    {
        return self::settings()['key'] !== '';
    }

    public static function name(): string
    {
        return self::enabled() ? 'anthropic' : 'escudosst_library';
    }

    public static function model(): string
    {
        return self::settings()['model'];
    }

    /** @return list<array{title: string, detail: string}>|null  null = sin respuesta válida (se usa la guía general) */
    public static function suggest(string $feature, string $redactedText): ?array
    {
        if (! self::enabled() || ! isset(self::FEATURE_PROMPT[$feature])) {
            return null;
        }
        try {
            $client = new Client(apiKey: self::settings()['key']);
            $message = $client->messages->create(
                model: self::model(),
                maxTokens: 4000,
                system: 'Eres un asistente de seguridad y salud en el trabajo para empresas peruanas (Ley 29783 y su reglamento). '
                    .'Responde en español sencillo, con sugerencias prácticas que una persona revisará antes de usarlas. '
                    .'No inventes artículos de normas ni cifras legales; si algo depende de la norma, dilo como verificación pendiente. '
                    .'El texto puede tener marcadores como [PERSONA] o [DNI]: no intentes adivinar los datos ocultos.',
                messages: [['role' => 'user', 'content' => self::FEATURE_PROMPT[$feature]."\n\nTexto:\n".mb_substr($redactedText, 0, 1500)]],
                outputConfig: ['format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['items' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => ['title' => ['type' => 'string'], 'detail' => ['type' => 'string']],
                            'required' => ['title', 'detail'],
                            'additionalProperties' => false,
                        ]]],
                        'required' => ['items'],
                        'additionalProperties' => false,
                    ],
                ]],
            );
            if ($message->stopReason === 'refusal') {
                return null;
            }
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    return self::validate(json_decode($block->text, true));
                }
            }

            return null;
        } catch (APIStatusException|APIConnectionException) {
            // Sin conexión, sin cupo o error del proveedor: el asistente sigue con su guía general.
            return null;
        }
    }

    /** RN-IAA-04: solo pasa una lista de 1 a 12 sugerencias con título y detalle de texto razonable. */
    public static function validate(mixed $data): ?array
    {
        if (! is_array($data) || ! isset($data['items']) || ! is_array($data['items']) || $data['items'] === []) {
            return null;
        }
        $out = [];
        foreach (array_slice($data['items'], 0, 12) as $it) {
            if (! is_array($it) || ! is_string($it['title'] ?? null) || ! is_string($it['detail'] ?? null) || trim($it['title']) === '') {
                return null;
            }
            $out[] = ['title' => mb_substr(trim($it['title']), 0, 200), 'detail' => mb_substr(trim($it['detail']), 0, 1000)];
        }

        return $out;
    }
}
