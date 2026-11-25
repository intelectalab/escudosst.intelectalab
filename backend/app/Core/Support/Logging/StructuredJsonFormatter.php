<?php

namespace App\Core\Support\Logging;

use App\Core\Tenancy\CompanyContext;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * Logs técnicos en JSON, una línea por registro (docs/02-convenciones.md §12): ts, level, trace_id, company_id, user_id,
 * route, job, duration_ms, message y el contexto saneado.
 *
 * Nunca se escriben contraseñas, tokens ni secretos (se reemplazan por «[oculto]»); el DNI y los teléfonos que aparezcan en
 * el texto se enmascaran (4567****). La auditoría de negocio no va aquí: va a audit_logs y sensitive_access_logs.
 */
class StructuredJsonFormatter extends NormalizerFormatter
{
    /** Claves del contexto que nunca se escriben (contraseñas, tokens, secretos, cuerpos médicos, prompts). */
    private const HIDDEN = '/pass(word)?|token|secret|authorization|cookie|api[_-]?key|private|otp|pin$|prompt|clinical|diagnos/i';

    private static ?string $requestTrace = null;

    public function __construct()
    {
        parent::__construct(\DateTimeInterface::RFC3339_EXTENDED);
    }

    /** Identificador de la petición en curso (el de X-Request-Id si llega y es seguro; si no, uno nuevo). */
    public static function traceId(): string
    {
        if (self::$requestTrace === null) {
            $given = app()->bound('request') ? (string) request()->header('X-Request-Id', '') : '';
            self::$requestTrace = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $given) ? $given : strtoupper(bin2hex(random_bytes(10)));
        }

        return self::$requestTrace;
    }

    /** Las pruebas y los procesos largos (cola) empiezan un identificador nuevo por trabajo. */
    public static function resetTrace(): void
    {
        self::$requestTrace = null;
    }

    public function format(LogRecord $record): string
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        $out = [
            'ts' => $record->datetime->setTimezone(new \DateTimeZone('America/Lima'))->format(\DateTimeInterface::RFC3339_EXTENDED),
            'level' => strtolower($record->level->getName()),
            'trace_id' => (string) ($context['trace_id'] ?? self::traceId()),
            'company_id' => $context['company_id'] ?? $this->companyId(),
            'user_id' => $context['user_id'] ?? $this->userId(),
            'route' => $context['route'] ?? $this->route(),
            'job' => $context['job'] ?? $this->job(),
            'duration_ms' => $context['duration_ms'] ?? (defined('LARAVEL_START') ? (int) round((microtime(true) - LARAVEL_START) * 1000) : null),
            'message' => self::mask($record->message),
        ];
        unset($context['trace_id'], $context['company_id'], $context['user_id'], $context['route'], $context['job'], $context['duration_ms']);
        if ($context !== []) {
            $out['context'] = $this->clean($this->normalize($context));
        }
        if ($exception instanceof \Throwable) {
            $out['exception'] = [
                'class' => $exception::class,
                'message' => self::mask($exception->getMessage()),
                'at' => basename($exception->getFile()).':'.$exception->getLine(),
            ];
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
    }

    /** DNI (8 dígitos) y celulares (9 dígitos) en el texto: se dejan los 4 primeros (docs/02 §12: «4567****»). */
    public static function mask(string $text): string
    {
        return (string) preg_replace_callback('/(?<![\d\-])(\d{4})(\d{4,5})(?![\d\-])/', fn ($m) => $m[1].str_repeat('*', strlen($m[2])), $text);
    }

    private function clean(mixed $value, string $key = ''): mixed
    {
        if ($key !== '' && preg_match(self::HIDDEN, $key)) {
            return '[oculto]';
        }
        if (is_array($value)) {
            $r = [];
            foreach ($value as $k => $v) {
                $r[$k] = $this->clean($v, (string) $k);
            }

            return $r;
        }

        return is_string($value) ? self::mask(mb_substr($value, 0, 2000)) : $value;
    }

    private function companyId(): ?string
    {
        return app()->bound(CompanyContext::class) ? (app(CompanyContext::class)->companyId ?? null) : null;
    }

    private function userId(): ?string
    {
        try {
            $guard = app()->bound('auth') ? app('auth')->guard() : null;

            return $guard && $guard->hasUser() ? (string) $guard->id() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function route(): ?string
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return null;
        }
        $r = request();
        $uri = $r->route()?->uri();

        return $r->method().' /'.ltrim($uri ?? $r->path(), '/');
    }

    private function job(): ?string
    {
        if (! app()->runningInConsole()) {
            return null;
        }
        $argv = $_SERVER['argv'] ?? [];

        return isset($argv[1]) ? mb_substr(implode(' ', array_slice($argv, 1, 2)), 0, 120) : null;
    }
}
