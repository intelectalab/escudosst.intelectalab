<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Editor de la portada (Sitio web › Portada).
 *  GET /internal/site/home   secciones guardadas (solo lo cambiado; lo demás es el «texto de fábrica» del sitio)
 *  PUT /internal/site/home   guarda y publica
 *  GET /public/home          lo publicado, para la portada
 *
 * Se guarda como la página de tipo «home» (slug «portada») con un bloque por sección:
 * {type: home_section, key, visible, eyebrow, title, text}. Un texto vacío (null) usa el de fábrica.
 * Al guardar se publica y la base revisa los términos bloqueados (lineamientos de contenido).
 */
class HomeEditorController extends Controller
{
    /** Secciones de la portada. El héroe siempre se muestra. */
    public const KEYS = ['hero', 'problem', 'pillars', 'modules', 'features', 'how', 'demo', 'plans', 'testimonials', 'reviews', 'faq', 'seals'];

    public function show(): JsonResponse
    {
        $p = DB::connection('platform')->selectOne("select blocks, published_at, updated_at from site_pages where page_type = 'home' and deleted_at is null limit 1");

        return response()->json(['data' => ['sections' => self::sections($p?->blocks), 'published_at' => $p?->published_at]]);
    }

    public function update(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate([
            'sections' => ['required', 'array', 'max:'.count(self::KEYS)],
            'sections.*.key' => ['required', 'string', 'in:'.implode(',', self::KEYS), 'distinct'],
            'sections.*.visible' => ['boolean'],
            'sections.*.eyebrow' => ['nullable', 'string', 'max:60'],
            'sections.*.title' => ['nullable', 'string', 'max:120'],
            'sections.*.text' => ['nullable', 'string', 'max:300'],
        ], [
            'sections.*.key.in' => 'Sección desconocida.',
            'sections.*.eyebrow.max' => 'El texto pequeño de arriba admite hasta 60 letras.',
            'sections.*.title.max' => 'El título admite hasta 120 letras.',
            'sections.*.text.max' => 'El texto admite hasta 300 letras.',
        ]);

        $blocks = [];
        foreach ($d['sections'] as $s) {
            $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
            $blocks[] = [
                'type' => 'home_section', 'key' => $s['key'],
                'visible' => $s['key'] === 'hero' ? true : (bool) ($s['visible'] ?? true),
                'eyebrow' => $clean($s['eyebrow'] ?? null), 'title' => $clean($s['title'] ?? null), 'text' => $clean($s['text'] ?? null),
            ];
        }

        $db = DB::connection('platform');
        try {
            $db->transaction(function () use ($db, $blocks, $request) {
                $json = json_encode($blocks, JSON_UNESCAPED_UNICODE);
                $id = $db->selectOne("select id from site_pages where page_type = 'home' and deleted_at is null limit 1")?->id;
                if ($id) {
                    $db->update('update site_pages set blocks = ?::jsonb, updated_by = ? where id = ?', [$json, $request->user()->id, $id]);
                } else {
                    $id = $db->selectOne("insert into site_pages (slug, page_type, title, blocks, created_by) values ('portada', 'home', 'Portada', ?::jsonb, ?) returning id",
                        [$json, $request->user()->id])->id;
                }
                $db->statement('select web_publish_page(?)', [$id]);
            });
        } catch (QueryException $e) {
            if (preg_match('/término bloqueado «([^»]+)»/u', $e->getMessage(), $m)) {
                $msg = "No se puede publicar «{$m[1]}»: es una afirmación que EscudoSST no puede respaldar (lineamientos de contenido).";
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', $msg, ['errors' => ['sections' => [$msg]]]);
            }
            throw $e;
        }

        $accounts->audit($request->user()->id, 'site_home_updated', 'site_pages', null,
            ['hidden' => array_values(array_map(fn ($b) => $b['key'], array_filter($blocks, fn ($b) => ! $b['visible'])))]);

        return response()->json(['data' => ['sections' => self::sections(json_encode($blocks))]]);
    }

    public function publicShow(): JsonResponse
    {
        $r = json_decode(DB::selectOne("select public_site_page('') as p")->p, true);

        return response()->json(['data' => ['sections' => ($r['result'] ?? null) === 'ok' ? self::sections(json_encode($r['blocks'] ?? [])) : []]]);
    }

    /** Bloques guardados → [{key, visible, eyebrow, title, text}] solo de secciones conocidas. */
    private static function sections(?string $blocks): array
    {
        $out = [];
        foreach (json_decode($blocks ?? '[]', true) ?: [] as $b) {
            if (($b['type'] ?? null) === 'home_section' && in_array($b['key'] ?? null, self::KEYS, true)) {
                $out[] = ['key' => $b['key'], 'visible' => (bool) ($b['visible'] ?? true), 'eyebrow' => $b['eyebrow'] ?? null, 'title' => $b['title'] ?? null, 'text' => $b['text'] ?? null];
            }
        }

        return $out;
    }
}
