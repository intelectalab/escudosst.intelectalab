<?php

namespace App\Modules\IAA;

/**
 * Texto de un PDF digital (no escaneado), sin librerías externas: descomprime los flujos (FlateDecode) y toma el texto de
 * los operadores Tj / TJ / ' / ". Alcanza para requerimientos e informes generados por computadora; un PDF escaneado
 * (imagen) devuelve texto vacío y se avisa a la persona.
 */
final class PdfText
{
    /**
     * Texto del PDF: primero con pdftotext (poppler, si está instalado), si no con el lector propio; si el PDF es escaneado
     * (sin texto) se lee con OCR. Todo es software libre que corre en el servidor: no se envía el documento a nadie.
     * @return array{0: string, 1: bool} texto y si se usó OCR
     */
    public static function read(string $pdf): array
    {
        foreach ([fn () => self::poppler($pdf), fn () => self::extract($pdf)] as $reader) {
            $text = (string) $reader();
            if (mb_strlen(trim($text)) >= 20) {
                return [$text, false];
            }
        }

        return [self::ocr($pdf), true];
    }

    /** pdftotext conserva mejor el orden de las líneas que el lector propio. null si no está instalado. */
    public static function poppler(string $pdf): ?string
    {
        if (! is_executable('/usr/bin/pdftotext')) {
            return null;
        }
        $in = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($in, $pdf);
        try {
            $r = \Illuminate\Support\Facades\Process::timeout(30)->run(['/usr/bin/pdftotext', '-layout', '-l', '20', $in, '-']);

            return $r->successful() ? (string) $r->output() : null;
        } finally {
            @unlink($in);
        }
    }

    /**
     * OCR gratuito (Tesseract en español) para PDF escaneados o fotos: convierte hasta 5 páginas a imagen y las lee.
     * Devuelve '' si las herramientas no están instaladas o no se reconoce texto.
     */
    public static function ocr(string $pdf): string
    {
        if (! is_executable('/usr/bin/pdftoppm') || ! is_executable('/usr/bin/tesseract')) {
            return '';
        }
        $dir = sys_get_temp_dir().'/ocr-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0700);
        file_put_contents("{$dir}/in.pdf", $pdf);
        try {
            $conv = \Illuminate\Support\Facades\Process::timeout(60)->run(['/usr/bin/pdftoppm', '-r', '250', '-gray', '-png', '-l', '5', "{$dir}/in.pdf", "{$dir}/p"]);
            if (! $conv->successful()) {
                return '';
            }
            $out = [];
            foreach (glob("{$dir}/p*.png") ?: [] as $img) {
                $r = \Illuminate\Support\Facades\Process::timeout(60)->run(['/usr/bin/tesseract', $img, 'stdout', '-l', 'spa', '--psm', '4']);
                if ($r->successful()) {
                    $out[] = trim((string) $r->output());
                }
            }

            return trim(implode("\n", $out));
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }

    public static function extract(string $pdf): string
    {
        $out = [];
        if (! preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        foreach ($m[1] as [$raw, $offset]) {
            $dict = substr($pdf, max(0, $offset - 400), min(400, $offset));
            // Las imágenes (fotos escaneadas) no llevan texto: sus bytes podrían parecer operadores de texto.
            $dict = substr($dict, (int) strrpos($dict, 'obj'));
            if (str_contains($dict, '/Image') || str_contains($dict, 'DCTDecode') || str_contains($dict, 'JPXDecode')) {
                continue;
            }
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
