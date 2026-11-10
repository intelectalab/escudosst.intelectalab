<?php

namespace App\Modules\SUN;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /sun/dossiers/{id}/zip — descarga el expediente armado: un índice (indice.html, se abre en cualquier navegador)
 * con cada punto, su estado y su brecha, y una carpeta por punto con los documentos adjuntos que tenga en EscudoSST
 * (política, RISST, procedimientos, documentos, actas, certificados, constancias). Nunca incluye archivos médicos.
 */
class DossierZip extends Controller
{
    /** tabla de origen => columnas con archivos. */
    private const FILE_COLUMNS = [
        'sst_policies' => ['file_id'], 'risst_versions' => ['file_id'], 'procedure_versions' => ['file_id'], 'document_versions' => ['file_id'],
        'committee_meetings' => ['minutes_file_id'], 'training_certificates' => [], 'annual_plans' => [], 'event_notifications' => ['receipt_file_id'],
        'baseline_assessments' => [], 'iperc_matrices' => [],
    ];

    public function __invoke(string $dossier): BinaryFileResponse
    {
        $d = Str::isUuid($dossier) ? DB::selectOne('select * from inspection_dossiers where id = ? and deleted_at is null', [$dossier]) : null;
        if (! $d) {
            throw ApiProblem::notFound('El expediente no existe o es de otra empresa.');
        }
        if (! $d->built_at) {
            throw new ApiProblem(409, 'conflict', 'Arma el expediente', 'Primero arma el expediente.');
        }
        $company = DB::selectOne('select legal_name, ruc from companies where id = ?', [app(CompanyContext::class)->companyId]);
        $items = DB::select('select * from dossier_items where dossier_id = ? and deleted_at is null order by sort_order', [$dossier]);
        $tmp = tempnam(sys_get_temp_dir(), 'exp');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $st = ['included' => 'Incluido', 'missing' => 'Pendiente', 'expired' => 'Vencido', 'not_applicable' => 'No aplica'];
        $rows = '';
        $files = 0;
        foreach ($items as $it) {
            $attached = [];
            $cols = self::FILE_COLUMNS[$it->source_type] ?? [];
            if ($it->source_id && $cols) {
                $src = DB::selectOne('select '.implode(', ', array_map(fn ($c) => "\"{$c}\"", $cols))." from \"{$it->source_type}\" where id = ?", [$it->source_id]);
                foreach ($cols as $c) {
                    $fid = $src?->{$c} ?? null;
                    $f = $fid ? DB::selectOne('select bucket, object_key, original_name from files where id = ? and deleted_at is null and not is_medical', [$fid]) : null;
                    if ($f && Storage::disk($f->bucket)->exists($f->object_key)) {
                        $name = sprintf('%s/%s', $it->section_code, preg_replace('/[^\w.\- ]+/u', '_', (string) $f->original_name));
                        $zip->addFromString($name, Storage::disk($f->bucket)->get($f->object_key));
                        $attached[] = $name;
                        $files++;
                    }
                }
            }
            $rows .= '<tr><td>'.e($it->section_code).'</td><td>'.e($it->title).($it->gap_description ? '<br><small>'.e($it->gap_description).'</small>' : '').'</td><td>'.e($st[$it->status] ?? $it->status).'</td><td>'
                .($attached ? implode('<br>', array_map(fn ($a) => '<a href="'.e($a).'">'.e(basename($a)).'</a>', $attached)) : '—').'</td></tr>';
        }
        $html = '<!doctype html><html lang="es"><meta charset="utf-8"><title>'.e($d->title).'</title><style>body{font-family:Arial,sans-serif;margin:24px;color:#0b2440}table{border-collapse:collapse;width:100%}td,th{border:1px solid #cdd8e4;padding:6px;text-align:left;vertical-align:top}th{background:#eef4fb}small{color:#5b6b7c}</style>'
            .'<h1>'.e($d->title).'</h1><p>'.e($company->legal_name).' · RUC '.e(trim($company->ruc)).'<br>Periodo: '.e(date('d/m/Y', strtotime($d->period_from))).' al '.e(date('d/m/Y', strtotime($d->period_to)))
            .' · Revisado: '.e(date('d/m/Y H:i', strtotime($d->built_at))).'</p><table><tr><th>N.°</th><th>Punto</th><th>Estado</th><th>Documentos</th></tr>'.$rows.'</table>'
            .'<p><small>Generado con EscudoSST. Los registros sin archivo adjunto están en el sistema y se muestran en la diligencia.</small></p></html>';
        $zip->addFromString('indice.html', $html);
        $zip->close();

        return response()->download($tmp, 'expediente-'.Str::slug($d->title).'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
