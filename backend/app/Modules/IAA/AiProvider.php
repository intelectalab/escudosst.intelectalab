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
 *  - El texto de cada caso sale de su prompt versionado (Prompts/{código}.md, docs/17 §3.6).
 *  - La respuesta debe cumplir el esquema JSON del caso de uso; si no lo cumple se descarta (RN-IAA-04).
 *  - Se llama solo desde el trabajador de la cola (AiRequestJob, docs/17 §3.6 «Llamadas solo desde workers»).
 */
final class AiProvider
{
    /** Esquemas JSON de salida por tipo (cabecera «schema» del prompt). */
    private const SCHEMAS = [
        'items' => [
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
        'minutes' => [
            'type' => 'object',
            'properties' => [
                'minutes_text' => ['type' => 'string'],
                'agreements' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['description' => ['type' => 'string'], 'responsible_position' => ['type' => ['string', 'null']], 'due_on' => ['type' => ['string', 'null']]],
                    'required' => ['description', 'responsible_position', 'due_on'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['minutes_text', 'agreements'],
            'additionalProperties' => false,
        ],
    ];

    /** Pruebas: respuesta simulada del proveedor (fn (string $code, string $prompt) => array|null). Con ella el proveedor figura «encendido». */
    private static ?\Closure $fake = null;

    /** Tokens de la última llamada (para ai_requests). */
    public static ?array $lastUsage = null;

    public static function fake(?callable $fn): void
    {
        self::$fake = $fn ? \Closure::fromCallable($fn) : null;
    }

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
        return self::$fake !== null || self::settings()['key'] !== '';
    }

    public static function name(): string
    {
        return self::enabled() ? 'anthropic' : 'escudosst_library';
    }

    public static function model(): string
    {
        return self::settings()['model'];
    }

    /** ¿Hay prompt versionado para el caso? (los demás casos solo usan la biblioteca y las reglas). */
    public static function supports(string $code): bool
    {
        return in_array($code, PromptTemplates::codes(), true);
    }

    /** @return list<array{title: string, detail: string}>|null  null = sin respuesta válida (se usa la guía general) */
    public static function suggest(string $feature, string $redactedText): ?array
    {
        return self::validate(self::call($feature, ['text' => mb_substr($redactedText, 0, 1500)]));
    }

    /** IA-04: borrador de acta. @return array{minutes_text: string, agreements: list<array>}|null */
    public static function minutes(string $redactedInput): ?array
    {
        return self::validateMinutes(self::call('minutes_draft', ['text' => mb_substr($redactedInput, 0, 12000)]));
    }

    /** Llama al proveedor con el prompt versionado del caso; null si está apagado, falla o no responde JSON. */
    private static function call(string $code, array $vars): mixed
    {
        self::$lastUsage = null;
        if (! self::enabled() || ! self::supports($code)) {
            return null;
        }
        $tpl = PromptTemplates::load($code);
        $prompt = PromptTemplates::render($code, $vars);
        if (self::$fake !== null) {
            return (self::$fake)($code, $prompt);
        }
        try {
            $client = new Client(apiKey: self::settings()['key']);
            $message = $client->messages->create(
                model: self::model(),
                maxTokens: $code === 'minutes_draft' ? 8000 : 4000,
                system: PromptTemplates::system(),
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::SCHEMAS[$tpl['schema'] ?? 'items'] ?? self::SCHEMAS['items']]],
            );
            self::$lastUsage = ['in' => $message->usage->inputTokens, 'out' => $message->usage->outputTokens];
            if ($message->stopReason === 'refusal') {
                return null;
            }
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    return json_decode($block->text, true);
                }
            }

            return null;
        } catch (APIStatusException|APIConnectionException) {
            // Sin conexión, sin cupo o error del proveedor: la solicitud queda como error y el asistente sigue con su guía general.
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

    /** RN-IAA-04 para IA-04: texto del acta y hasta 30 acuerdos con responsable por cargo y fecha AAAA-MM-DD (o vacía). */
    public static function validateMinutes(mixed $data): ?array
    {
        if (! is_array($data) || ! is_string($data['minutes_text'] ?? null) || mb_strlen(trim($data['minutes_text'])) < 20 || ! is_array($data['agreements'] ?? null)) {
            return null;
        }
        $agreements = [];
        foreach (array_slice($data['agreements'], 0, 30) as $a) {
            if (! is_array($a) || ! is_string($a['description'] ?? null) || trim($a['description']) === '') {
                return null;
            }
            $due = is_string($a['due_on'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['due_on']) && strtotime($a['due_on']) ? $a['due_on'] : null;
            $agreements[] = ['description' => mb_substr(trim($a['description']), 0, 1000),
                'responsible_position' => is_string($a['responsible_position'] ?? null) && trim($a['responsible_position']) !== '' ? mb_substr(trim($a['responsible_position']), 0, 150) : null,
                'due_on' => $due];
        }

        return ['minutes_text' => mb_substr(trim($data['minutes_text']), 0, 20000), 'agreements' => $agreements];
    }
}
