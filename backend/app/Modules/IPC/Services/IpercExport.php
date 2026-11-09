<?php

namespace App\Modules\IPC\Services;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

/** Matriz IPERC en PDF y Excel (M-IPC §11): formato referencial R.M. 050-2013-TR. */
final class IpercExport
{
    public const HIERARCHY = [
        'elimination' => 'Eliminación', 'substitution' => 'Sustitución', 'engineering' => 'Ingeniería',
        'administrative' => 'Administrativo', 'signage' => 'Señalización', 'ppe' => 'EPP',
    ];

    private const STATUS = ['draft' => 'borrador', 'in_review' => 'en revisión', 'approved' => 'aprobada', 'current' => 'vigente', 'under_update' => 'en actualización', 'obsolete' => 'obsoleta'];

    public function __construct(private IpercWorkflow $flow, private RiskEvaluator $eval) {}

    /** @return array{0: string, 1: string} [nombre del archivo, contenido] */
    public function pdf(string $versionId): array
    {
        $d = $this->data($versionId);
        $html = view('reports.iperc', $d + [
            'statusLabel' => self::STATUS[$d['v']->status] ?? $d['v']->status,
            'approved' => $d['v']->approved_at ? \Illuminate\Support\Carbon::parse($d['v']->approved_at)->timezone('America/Lima')->format('d/m/Y') : null,
            'hierarchy' => self::HIERARCHY,
            'isIndex' => $d['criteria']['method'] === 'pxs_index',
            'label' => fn (?string $l) => $l ? $this->eval->label($d['criteria'], $l) : '',
            'color' => fn (?string $l) => $l ? (collect($d['criteria']['bands'])->firstWhere('code', $l)['color'] ?? '') : '',
        ])->render();

        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return [$this->filename($d, 'pdf'), $dompdf->output()];
    }

    /** Excel (.xlsx) armado a mano: una hoja con la matriz, sin librerías extra. */
    public function xlsx(string $versionId): array
    {
        $d = $this->data($versionId);
        $c = $d['criteria'];
        $isIndex = $c['method'] === 'pxs_index';
        $header = ['N.°', 'Proceso', 'Actividad', 'Tarea', 'Rutinaria', 'Puesto', 'Peligro', 'Riesgo', 'Consecuencia'];
        if ($isIndex) {
            foreach ($c['factors'] as $f) {
                $header[] = $f['label'];
            }
        }
        array_push($header, 'Probabilidad', 'Severidad', 'Puntaje', 'Nivel', 'Significativo', 'Controles', 'Notas', 'P. residual', 'S. residual', 'Puntaje residual', 'Nivel residual');

        $rows = [
            ["MATRIZ IPERC · {$d['m']->title}"],
            [$d['company']->legal_name.' · RUC '.trim($d['company']->ruc)],
            ["Código {$d['m']->code} · versión {$d['v']->version_number} (".(self::STATUS[$d['v']->status] ?? $d['v']->status).") · criterios: {$c['name']}"],
            [],
            $header,
        ];
        foreach ($d['rows'] as $r) {
            $line = [$r['row_number'], $r['process'], $r['activity'], $r['task'], $r['is_routine'] ? 'Sí' : 'No', $r['position_name'], $r['hazard'], $r['risk'], $r['consequence']];
            if ($isIndex) {
                foreach ($c['factors'] as $f) {
                    $line[] = $r['probability_detail'][$f['code']] ?? null;
                }
            }
            $controls = implode("\n", array_map(fn ($x) => (self::HIERARCHY[$x['hierarchy']] ?? $x['hierarchy']).': '.$x['description']
                .($x['responsible_name'] ? " ({$x['responsible_name']}".($x['due_on'] ? ', '.$x['due_on'] : '').')' : ''), $r['controls']));
            array_push($line, $r['probability_value'], $r['severity_value'], $r['risk_score'], $this->eval->label($c, $r['risk_level']),
                $r['is_significant'] ? 'Sí' : 'No', $controls, $r['notes'], $r['residual_probability'], $r['residual_severity'], $r['residual_score'],
                $r['residual_level'] ? $this->eval->label($c, $r['residual_level']) : null);
            $rows[] = $line;
        }

        return [$this->filename($d, 'xlsx'), $this->buildXlsx('IPERC', $rows, 5)];
    }

    private function data(string $versionId): array
    {
        if (! Str::isUuid($versionId)) {
            throw ApiProblem::notFound('La versión no existe.');
        }
        $v = DB::selectOne(
            'select v.*, pu.full_name as prepared_by_name, au.full_name as approved_by_name from iperc_versions v
               left join users pu on pu.id = v.prepared_by left join users au on au.id = v.approved_by
              where v.id = ? and v.deleted_at is null', [$versionId]);
        if (! $v) {
            throw ApiProblem::notFound('La versión no existe o es de otra empresa.');
        }
        $ws = $this->flow->workspace($v->iperc_matrix_id, $versionId);

        return [
            'm' => $ws['matrix'],
            'v' => $v,
            'criteria' => $ws['criteria'],
            'rows' => $ws['rows'],
            'participants' => json_decode((string) $v->participants, true) ?? [],
            'company' => DB::selectOne('select legal_name, ruc from companies where id = ?', [app(CompanyContext::class)->companyId]),
        ];
    }

    private function filename(array $d, string $ext): string
    {
        return 'IPERC-'.preg_replace('/[^A-Za-z0-9_-]/', '', $d['m']->code)."-v{$d['v']->version_number}.{$ext}";
    }

    /** Libro .xlsx mínimo (Office Open XML) con texto en línea; la fila $headerRow va en negrita. */
    private function buildXlsx(string $sheet, array $rows, int $headerRow): string
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
