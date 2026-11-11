<?php

namespace App\Modules\TRB;

use ZipArchive;

/**
 * Lector mínimo de la primera hoja de un Excel (.xlsx) o de un CSV, sin librerías: devuelve filas de texto
 * (índice de columna desde 0). Los números de fecha de Excel se dejan como número; WorkerImport los convierte.
 */
final class SheetReader
{
    /** @return array<int, array<int, string>> filas numeradas desde 1 */
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return $ext === 'csv' || $ext === 'txt' ? self::csv($path) : self::xlsx($path);
    }

    private static function csv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($raw, "\n") ?: '';
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $rows = [];
        $n = 0;
        foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
            $n++;
            if (trim($line) === '') {
                continue;
            }
            $rows[$n] = array_map(fn ($v) => trim((string) $v), str_getcsv($line, $sep, '"', ''));
        }

        return $rows;
    }

    private static function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('El archivo no es un Excel válido (.xlsx).');
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = @simplexml_load_string($xml);
            foreach ($sx?->si ?? [] as $si) {
                $shared[] = self::text($si);
            }
        }
        $sheet = self::firstSheet($zip);
        $xml = $sheet ? $zip->getFromName($sheet) : false;
        $zip->close();
        if ($xml === false) {
            throw new \RuntimeException('El Excel no tiene hojas.');
        }
        $sx = @simplexml_load_string($xml);
        if (! $sx) {
            throw new \RuntimeException('No se pudo leer la hoja del Excel.');
        }
        $rows = [];
        foreach ($sx->sheetData->row ?? [] as $row) {
            $n = (int) $row['r'];
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::colIndex(preg_replace('/\d+/', '', $ref));
                $t = (string) $c['t'];
                $v = match ($t) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                    default => (string) $c->v,
                };
                $cells[$col] = trim($v);
            }
            if ($cells !== [] && implode('', $cells) !== '') {
                $max = max(array_keys($cells));
                $rows[$n] = array_map(fn ($i) => $cells[$i] ?? '', range(0, $max));
            }
        }

        return $rows;
    }

    private static function firstSheet(ZipArchive $zip): ?string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $w = @simplexml_load_string($wb);
            $r = @simplexml_load_string($rels);
            $first = $w?->sheets?->sheet[0] ?? null;
            if ($first !== null && $r) {
                $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($r->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');

                        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                    }
                }
            }
        }

        return $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
    }

    private static function text(?\SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t) && ! isset($node->r)) {
            return (string) $node->t;
        }
        $s = '';
        foreach ($node->r ?? [] as $r) {
            $s .= (string) $r->t;
        }

        return $s !== '' ? $s : (string) ($node->t ?? '');
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}
