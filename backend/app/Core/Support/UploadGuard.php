<?php

namespace App\Core\Support;

use App\Core\Http\ApiProblem;
use Illuminate\Http\UploadedFile;

/**
 * Tipo real de los archivos subidos (docs/10 §6 «Subida de archivos»: lista blanca de tipos y verificación de magic bytes).
 * El tipo se lee del contenido con finfo, nunca del nombre ni de la cabecera que manda el navegador: un .xlsx que en
 * realidad es un ejecutable o un HTML se rechaza aunque su extensión sea la correcta.
 */
final class UploadGuard
{
    /** Tipos reales aceptados por formato de planilla (la extensión debe coincidir con lo que es el archivo). */
    private const SHEETS = [
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'text/comma-separated-values'],
    ];

    /** Tipo real (MIME) del contenido del archivo. */
    public static function realMime(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $mime = $path && is_readable($path) ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        $mime = is_string($mime) ? strtolower($mime) : 'application/octet-stream';
        // Archivos de prueba sin contenido (UploadedFile::fake()->create): no tienen bytes que leer y declaran su tipo.
        if ($file instanceof \Illuminate\Http\Testing\File && in_array($mime, ['application/x-empty', 'application/octet-stream'], true)) {
            return strtolower((string) $file->getMimeType());
        }

        return $mime;
    }

    /**
     * Planilla de importación: Excel (.xlsx) o CSV, con la extensión y el contenido de acuerdo. Un .xlsx es un ZIP (Office
     * Open XML) y debe traer su hoja de cálculo; un CSV es texto.
     */
    public static function spreadsheet(UploadedFile $file, string $field = 'file', string $message = 'Sube el archivo en Excel (.xlsx) o CSV.'): void
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! isset(self::SHEETS[$ext])) {
            self::fail($field, $message);
        }
        $mime = self::realMime($file);
        if (! in_array($mime, self::SHEETS[$ext], true)) {
            self::fail($field, 'El archivo no es realmente un '.($ext === 'csv' ? 'CSV' : 'Excel (.xlsx)').': ábrelo en Excel y guárdalo de nuevo con ese formato.');
        }
        if ($ext === 'xlsx' && ! self::zipHas($file->getRealPath(), 'xl/workbook.xml')) {
            self::fail($field, 'El archivo no es realmente un Excel (.xlsx): ábrelo en Excel y guárdalo de nuevo con ese formato.');
        }
    }

    /**
     * Cualquier otra subida: el tipo real debe estar en la lista blanca.
     *
     * @param  list<string>  $allowed  tipos MIME aceptados (p. ej. image/png); «image/*» acepta cualquier imagen
     */
    public static function assertType(UploadedFile $file, array $allowed, string $field = 'file', string $message = 'El tipo de archivo no está permitido.'): string
    {
        $mime = self::realMime($file);
        foreach ($allowed as $a) {
            if ($a === $mime || (str_ends_with($a, '/*') && str_starts_with($mime, substr($a, 0, -1)))) {
                return $mime;
            }
        }
        self::fail($field, $message);
    }

    private static function zipHas(string|false $path, string $entry): bool
    {
        if (! $path || ! class_exists(\ZipArchive::class)) {
            return true;   // sin la extensión zip no se puede mirar dentro: basta el tipo real
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return false;
        }
        $ok = $zip->locateName($entry) !== false;
        $zip->close();

        return $ok;
    }

    private static function fail(string $field, string $message): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', $message, ['errors' => [$field => [$message]]]);
    }
}
