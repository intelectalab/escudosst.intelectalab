<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Señales livianas para «Salud del sistema» (panel del dueño), guardadas en la caché (Redis):
 *  - correos enviados y fallidos por día (últimos 7 días) y el último error;
 *  - latido del programador de tareas (cada minuto) y resultado del último proceso diario.
 * No guarda destinatarios ni contenido: solo conteos.
 */
final class SystemPulse
{
    private const DAYS = 8;

    public static function mailSent(): void
    {
        self::bump('sent');
    }

    public static function mailFailed(string $error): void
    {
        self::bump('failed');
        Cache::put('pulse:mail:last_error', ['at' => now()->toIso8601String(), 'error' => mb_substr($error, 0, 300)], now()->addDays(self::DAYS));
    }

    /** @return list<array{day: string, sent: int, failed: int}> */
    public static function mailLastDays(int $days = 7): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now('America/Lima')->subDays($i)->toDateString();
            $out[] = ['day' => $day, 'sent' => (int) Cache::get("pulse:mail:sent:{$day}", 0), 'failed' => (int) Cache::get("pulse:mail:failed:{$day}", 0)];
        }

        return $out;
    }

    public static function lastMailError(): ?array
    {
        return Cache::get('pulse:mail:last_error');
    }

    public static function schedulerBeat(): void
    {
        Cache::put('pulse:scheduler:beat', now()->toIso8601String(), now()->addHour());
    }

    public static function schedulerLastBeat(): ?string
    {
        return Cache::get('pulse:scheduler:beat');
    }

    public static function dailyRun(array $summary): void
    {
        Cache::forever('pulse:daily:last', ['at' => now()->toIso8601String(), 'summary' => $summary]);
    }

    public static function lastDailyRun(): ?array
    {
        return Cache::get('pulse:daily:last');
    }

    private static function bump(string $kind): void
    {
        $key = "pulse:mail:{$kind}:".now('America/Lima')->toDateString();
        Cache::add($key, 0, now()->addDays(self::DAYS));
        Cache::increment($key);
    }
}
