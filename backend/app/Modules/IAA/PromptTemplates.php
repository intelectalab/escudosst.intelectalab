<?php

namespace App\Modules\IAA;

use RuntimeException;

/**
 * Prompts versionados del asistente (docs/17 §3.6): un archivo por caso de uso en app/Modules/IAA/Prompts/{código}.md con su
 * cabecera (code, version, schema) entre líneas «---» y el texto con marcadores {{nombre}}. «system.md» son las instrucciones
 * comunes del sistema. La versión se guarda en ai_requests.prompt_version de cada solicitud al proveedor; cambiar el texto de
 * un prompt obliga a subir su versión (lo verifican las pruebas de regresión).
 */
final class PromptTemplates
{
    /** @var array<string, array{code: string, version: string, schema: ?string, body: string}> */
    private static array $cache = [];

    public static function dir(): string
    {
        return __DIR__.'/Prompts';
    }

    /** @return list<string> códigos de los prompts de casos de uso (sin «system») */
    public static function codes(): array
    {
        $out = [];
        foreach (glob(self::dir().'/*.md') ?: [] as $f) {
            $c = basename($f, '.md');
            if ($c !== 'system') {
                $out[] = $c;
            }
        }
        sort($out);

        return $out;
    }

    /** @return array{code: string, version: string, schema: ?string, body: string} */
    public static function load(string $code): array
    {
        if (isset(self::$cache[$code])) {
            return self::$cache[$code];
        }
        if (! preg_match('/^[a-z_]{2,40}$/', $code) || ! is_file($f = self::dir()."/{$code}.md")) {
            throw new RuntimeException("No existe el prompt «{$code}».");
        }
        $raw = str_replace("\r\n", "\n", (string) file_get_contents($f));
        if (! preg_match('/^---\n(.*?)\n---\n(.*)$/s', $raw, $m)) {
            throw new RuntimeException("El prompt «{$code}» no tiene cabecera de versión.");
        }
        $head = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.+)$/', trim($line), $h)) {
                $head[$h[1]] = trim($h[2]);
            }
        }
        if (($head['code'] ?? '') !== $code || ! preg_match('/^\d+$/', (string) ($head['version'] ?? ''))) {
            throw new RuntimeException("La cabecera del prompt «{$code}» debe traer su código y una versión numérica.");
        }

        return self::$cache[$code] = ['code' => $code, 'version' => $head['version'], 'schema' => $head['schema'] ?? null, 'body' => trim($m[2])];
    }

    public static function version(string $code): string
    {
        return self::load($code)['version'];
    }

    public static function system(): string
    {
        return self::load('system')['body'];
    }

    /** Texto del prompt con sus marcadores reemplazados; un marcador sin valor es un error (no se envía un prompt a medias). */
    public static function render(string $code, array $vars): string
    {
        $out = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vars, $code) {
            if (! array_key_exists($m[1], $vars)) {
                throw new RuntimeException("Falta el dato «{$m[1]}» del prompt «{$code}».");
            }

            return (string) $vars[$m[1]];
        }, self::load($code)['body']);

        return (string) $out;
    }
}
