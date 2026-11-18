<?php

namespace App\Modules\IAA;

/**
 * Texto de un PDF digital (no escaneado), sin librerías externas: descomprime los flujos (FlateDecode) y toma el texto de
 * los operadores Tj / TJ / ' / ". Alcanza para requerimientos e informes generados por computadora; un PDF escaneado
 * (imagen) devuelve texto vacío y se avisa a la persona.
 */
final class PdfText
{
    public static function extract(string $pdf): string
    {
        $out = [];
        if (! preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        foreach ($m[1] as [$raw, $offset]) {
            $dict = substr($pdf, max(0, $offset - 400), min(400, $offset));
            $data = $raw;
            if (str_contains($dict, 'FlateDecode')) {
                $data = @gzuncompress($raw);
                if ($data === false) {
                    $data = @gzinflate(substr($raw, 2)) ?: '';
                }
            }
            if ($data === '' || ! preg_match('/\b(Tj|TJ|BT)\b/', $data)) {
                continue;
            }
            $out[] = self::textOps($data);
        }

        return trim(preg_replace("/[ \t]+/", ' ', implode("\n", $out)) ?? '');
    }

    private static function textOps(string $s): string
    {
        $lines = [];
        $cur = '';
        preg_match_all('/\[(.*?)\]\s*TJ|\((?:\\\\.|[^\\\\)])*\)\s*(?:Tj|\'|")|(T\*|Td|TD|Tm|ET)(?=\s|$)/s', $s, $mm, PREG_SET_ORDER);
        foreach ($mm as $x) {
            if (! empty($x[2])) {
                if ($cur !== '' && in_array($x[2], ['T*', 'Td', 'TD', 'Tm', 'ET'], true)) {
                    $lines[] = $cur;
                    $cur = '';
                }

                continue;
            }
            if (($x[1] ?? '') !== '') {
                preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|(-?\d+(?:\.\d+)?)/s', $x[1], $parts, PREG_SET_ORDER);
                foreach ($parts as $p) {
                    if (isset($p[1]) && $p[1] !== '') {
                        if ((float) $p[1] < -200) {
                            $cur .= ' ';
                        }
                    } else {
                        $cur .= self::str($p[0]);
                    }
                }
            } else {
                $cur .= self::str(preg_replace('/\s*(Tj|\'|")$/', '', $x[0]));
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }

        return implode("\n", $lines);
    }

    private static function str(string $lit): string
    {
        $t = substr(trim($lit), 1, -1);
        $t = preg_replace_callback('/\\\\([0-7]{1,3}|.)/s', function ($e) {
            $c = $e[1];

            return match (true) {
                ctype_digit($c) => chr(octdec($c)),
                $c === 'n' => "\n", $c === 'r' => '', $c === 't' => ' ',
                default => $c,
            };
        }, $t) ?? $t;

        return mb_check_encoding($t, 'UTF-8') ? $t : mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
    }
}
