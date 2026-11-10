<?php

namespace App\Modules\IPC\Services;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        return [$this->filename($d, 'xlsx'), \App\Core\Support\Xlsx::build('IPERC', $rows, 5)];
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
}
