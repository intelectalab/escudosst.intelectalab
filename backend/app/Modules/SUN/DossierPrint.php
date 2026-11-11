<?php

namespace App\Modules\SUN;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /sun/dossiers/{id}/print — datos del «PDF único» foliado del expediente (M-SUN SCR-SUN-05, RN-SUN-06/07/11):
 * portada e índice numerado con el folio de cada punto, y una hoja por punto con su estado, brecha y los documentos
 * adjuntos que tiene en EscudoSST. La hoja la imprime el navegador (/imprimir/expediente/{id}). Nunca lista archivos
 * médicos; los faltantes no se ocultan. Con varios trabajadores inspeccionados, el índice tiene una sección por
 * trabajador (después de los puntos de la empresa) con su primer folio (SCR-SUN-04).
 */
class DossierPrint extends Controller
{
    /** Puntos por hoja del índice. */
    public const INDEX_PER_PAGE = 22;

    public function __invoke(string $dossier): JsonResponse
    {
        $d = Str::isUuid($dossier) ? DB::selectOne(
            'select d.id, d.title, d.request_type, d.inspection_order_number, d.inspector_name, d.appearance_at, d.period_from, d.period_to, d.built_at, d.status,
                    s.name as site_name
               from inspection_dossiers d left join sites s on s.id = d.site_id where d.id = ? and d.deleted_at is null', [$dossier]) : null;
        if (! $d) {
            throw ApiProblem::notFound('El expediente no existe o es de otra empresa.');
        }
        if (! $d->built_at) {
            throw new ApiProblem(409, 'conflict', 'Arma el expediente', 'Primero arma el expediente: el PDF se genera con lo ya revisado.');
        }
        $company = DB::selectOne('select legal_name, coalesce(trade_name, legal_name) as name, ruc from companies where id = ?', [app(CompanyContext::class)->companyId]);
        $rows = DB::select(
            "select i.section_code, i.title, i.status, i.gap_description, i.source_module, i.source_type, i.source_id, i.register_type, i.updated_at,
                    i.worker_id, w.full_name as worker_name
               from dossier_items i left join workers w on w.id = i.worker_id
              where i.dossier_id = ? and i.deleted_at is null order by i.sort_order, i.section_code", [$dossier]);

        $indexPages = max(1, (int) ceil(count($rows) / self::INDEX_PER_PAGE));
        $folio = $indexPages;   // la portada con el índice ocupa los primeros folios
        $items = [];
        $sections = [];
        foreach ($rows as $n => $it) {
            $folio++;
            $key = $it->worker_id ?? 'company';
            if (! isset($sections[$key])) {
                $sections[$key] = ['key' => $key, 'worker_id' => $it->worker_id, 'title' => $it->worker_id ? 'Trabajador: '.$it->worker_name : 'Empresa',
                    'first_folio' => $folio, 'first_number' => $n + 1, 'count' => 0, 'gaps' => 0];
            }
            $sections[$key]['count']++;
            if (in_array($it->status, ['missing', 'expired'], true)) {
                $sections[$key]['gaps']++;
            }
            $items[] = [
                'number' => $n + 1,
                'folio' => $folio,
                'code' => $it->section_code,
                'title' => $it->title,
                'status' => $it->status,
                'gap' => $it->gap_description,
                'module' => $it->source_module ? trim($it->source_module) : null,
                'register_type' => $it->register_type,
                'worker' => $it->worker_name,
                'section' => $key,
                'reviewed_at' => $it->updated_at,
                'files' => $this->files($it),
            ];
        }

        return response()->json(['data' => [
            'company' => ['name' => $company->name, 'legal_name' => $company->legal_name, 'ruc' => trim((string) $company->ruc)],
            'dossier' => $d,
            'index_pages' => $indexPages,
            'index_per_page' => self::INDEX_PER_PAGE,
            'total_folios' => $folio,
            'items' => $items,
            'sections' => array_values($sections),
            'summary' => [
                'total' => count($rows),
                'included' => count(array_filter($rows, fn ($r) => $r->status === 'included')),
                'gaps' => count(array_filter($rows, fn ($r) => in_array($r->status, ['missing', 'expired'], true))),
            ],
            'generated_at' => now()->toIso8601String(),
        ]]);
    }

    /** @return list<string> nombres de los documentos adjuntos del registro de origen (sin archivos médicos). */
    private function files(object $it): array
    {
        $cols = DossierZip::FILE_COLUMNS[$it->source_type] ?? [];
        if (! $it->source_id || ! $cols) {
            return [];
        }
        $src = DB::selectOne('select '.implode(', ', array_map(fn ($c) => "\"{$c}\"", $cols))." from \"{$it->source_type}\" where id = ?", [$it->source_id]);
        $out = [];
        foreach ($cols as $c) {
            $fid = $src?->{$c} ?? null;
            $f = $fid ? DB::selectOne('select original_name from files where id = ? and deleted_at is null and not is_medical', [$fid]) : null;
            if ($f) {
                $out[] = (string) $f->original_name;
            }
        }

        return $out;
    }
}
