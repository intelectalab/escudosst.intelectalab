<?php

namespace App\Core\Support;

use ZipArchive;

/** Libro de Excel (.xlsx) mínimo, sin librerías: una hoja, texto y números, fila de títulos en negrita, filtros y panel fijo. */
final class Xlsx
{
    /** Libro .xlsx mínimo (Office Open XML) con texto en línea; la fila $headerRow va en negrita. */
    public static function build(string $sheet, array $rows, int $headerRow): string
    {
        $esc = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string {
            $s = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
                $s = chr(65 + ($i - 1) % 26).$s;
            }

            return $s;
        };
        $xmlRows = '';
        foreach ($rows as $ri => $row) {
            $n = $ri + 1;
            $cells = '';
            foreach (array_values($row) as $ci => $val) {
                if ($val === null || $val === '') {
                    continue;
                }
                $ref = $col($ci).$n;
                $style = $n === $headerRow || $n === 1 ? ' s="1"' : ' s="2"';
                $cells .= is_int($val) || is_float($val)
                    ? "<c r=\"{$ref}\"{$style}><v>{$val}</v></c>"
                    : "<c r=\"{$ref}\" t=\"inlineStr\"{$style}><is><t xml:space=\"preserve\">{$esc($val)}</t></is></c>";
            }
            $xmlRows .= "<row r=\"{$n}\">{$cells}</row>";
        }
        $last = $col(max(array_map('count', $rows)) - 1);
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            ."<sheetViews><sheetView workbookViewId=\"0\"><pane ySplit=\"{$headerRow}\" topLeftCell=\"A".($headerRow + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols><col min="1" max="1" width="6" customWidth="1"/><col min="2" max="40" width="22" customWidth="1"/></cols>'
            ."<sheetData>{$xmlRows}</sheetData>"
            ."<autoFilter ref=\"A{$headerRow}:{$last}".count($rows).'"/>'
            .'</worksheet>';

        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$esc($sheet).'" sheetId="1" r:id="rId1"/></sheets>'
            ."<definedNames><definedName name=\"_xlnm._FilterDatabase\" localSheetId=\"0\" hidden=\"1\">'".$esc($sheet)."'!\$A\${$headerRow}:\${$last}\$".count($rows).'</definedName></definedNames></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="3"><xf/><xf fontId="1" applyFont="1"/><xf applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();
        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }
}
