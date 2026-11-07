<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Menú de la cabecera del sitio (SCR-WEB-21, editor de menús).
 * «Productos» (desplegable) va siempre primero y no se edita aquí; el resto de enlaces sí: texto, destino,
 * orden y si se muestra. Si el dueño nunca lo editó, se usa el menú por defecto.
 * Los enlaces internos solo pueden ir a páginas que existen: las fijas del sitio y las publicadas (páginas por bloques,
 * casos y políticas). Si luego una página se quita del sitio, su enlace deja de mostrarse solo (no lleva a un error).
 *  GET /internal/site/menu
 *  PUT /internal/site/menu
 */
class SiteMenuController extends Controller
{
    public const DEFAULT = [
        ['label' => 'Cómo funciona', 'url' => '/como-funciona', 'visible' => true],
        ['label' => 'Planes', 'url' => '/planes', 'visible' => true],
        ['label' => 'Contacto', 'url' => '/demo?tipo=contacto', 'visible' => true],
    ];

    /** Páginas fijas del sitio (las demás salen de la base). Igual que frontend/lib/seo.ts. */
    public const FIXED = [
        '/' => 'Portada', '/producto' => 'Producto y módulos', '/como-funciona' => 'Cómo funciona', '/planes' => 'Planes',
        '/complementos' => 'Complementos', '/sectores' => 'Sectores', '/casos' => 'Casos de éxito', '/recursos' => 'Recursos gratis', '/herramientas/calculadora-multas' => 'Calculadora de multas SUNAFIL', '/herramientas/autodiagnostico' => 'Autodiagnóstico Ley 29783',
        '/preguntas-frecuentes' => 'Preguntas frecuentes', '/ayuda' => 'Centro de ayuda', '/partners' => 'Programa de partners', '/boletin' => 'Boletín normativo SST', '/confianza' => 'Centro de confianza', '/estado' => 'Estado del servicio', '/demo' => 'Agendar demo o contacto', '/demo/agendar' => 'Elegir horario de demo', '/prueba-gratis' => 'Prueba gratis',
        '/libro-de-reclamaciones' => 'Libro de Reclamaciones',
    ];

    /** Páginas a las que puede llevar un enlace del menú: [ruta => [nombre, grupo]]. */
    public static function linkablePages(): array
    {
        $db = DB::connection('platform');
        $out = [];
        foreach (self::FIXED as $path => $label) {
            $out[$path] = [$label, 'Páginas del sitio'];
        }
        foreach ($db->select("select slug, title, page_type from site_pages where status = 'published' and deleted_at is null and page_type <> 'campaign' order by page_type, title") as $p) {
            $out['/'.$p->slug] = [$p->title, ['sector' => 'Sectores', 'product' => 'Diferenciadores'][$p->page_type] ?? 'Otras páginas'];
        }
        foreach ($db->select("select slug, title from success_stories where status = 'published' and deleted_at is null and (consent_file_id is not null or is_example) order by title") as $c) {
            $out['/casos/'.$c->slug] = [$c->title, 'Casos de éxito'];
        }
        foreach ($db->select("select d.slug, d.title from legal_documents d join legal_document_versions v on v.id = d.current_version_id and v.status = 'published' where d.is_active and d.deleted_at is null") as $l) {
            $out['/legal/'.$l->slug] = [$l->title, 'Políticas'];
        }

        return $out;
    }

    /** Ruta interna sin «?…» ni «#…» (null si es un enlace externo https o un ancla). */
    private static function internalPath(string $url): ?string
    {
        if (! str_starts_with($url, '/')) {
            return null;
        }
        $path = strtok(strtok($url, '?'), '#') ?: '/';

        return strlen($path) > 1 ? rtrim($path, '/') : $path;
    }

    /** Enlaces del menú de cabecera (los visibles, en orden), para el sitio público. */
    public static function headerItems(bool $onlyVisible = true): array
    {
        $row = DB::connection('platform')->selectOne("select items from site_menus where location = 'header' and is_active and deleted_at is null order by created_at limit 1");
        $items = $row ? json_decode($row->items, true) : self::DEFAULT;
        if (! $onlyVisible) {
            return array_values($items);
        }
        // En el sitio no se muestra un enlace a una página que ya no está publicada (no llevaría a nada).
        $pages = self::linkablePages();

        return array_values(array_filter($items, fn ($i) => ($i['visible'] ?? true)
            && (($p = self::internalPath($i['url'])) === null || isset($pages[$p]))));
    }

    public function show(): JsonResponse
    {
        $pages = self::linkablePages();
        $items = array_map(function ($i) use ($pages) {
            $p = self::internalPath($i['url']);
            $i['available'] = $p === null || isset($pages[$p]);

            return $i;
        }, self::headerItems(false));

        return response()->json([
            'data' => $items,
            'defaults' => self::DEFAULT,
            'pages' => array_map(fn ($path, $v) => ['url' => $path, 'label' => $v[0], 'group' => $v[1]], array_keys($pages), $pages),
        ]);
    }

    public function update(Request $request, AccountsService $accounts): Response
    {
        $data = $request->validate([
            'items' => ['present', 'array', 'max:8'],
            'items.*.label' => ['required', 'string', 'min:2', 'max:30'],
            'items.*.url' => ['required', 'string', 'max:250', 'regex:/^(\/[^\s]*|#[\w-]*|https:\/\/[^\s]+)$/'],
            'items.*.visible' => ['boolean'],
        ], [
            'items.max' => 'Máximo 8 enlaces: un menú largo no cabe en la cabecera.',
            'items.*.label.required' => 'Escribe el texto del enlace.',
            'items.*.label.max' => 'Máximo 30 caracteres.',
            'items.*.url.required' => 'Escribe el destino.',
            'items.*.url.regex' => 'Usa una página del sitio (empieza con /) o un enlace https://.',
        ]);
        $items = array_map(fn ($i) => ['label' => trim($i['label']), 'url' => trim($i['url']), 'visible' => (bool) ($i['visible'] ?? true)], $data['items']);
        $pages = self::linkablePages();
        $errors = [];
        foreach ($items as $k => $i) {
            $p = self::internalPath($i['url']);
            if ($p !== null && ! isset($pages[$p])) {
                $errors["items.{$k}.url"] = ["La página {$p} no existe o no está publicada. Elige una de la lista o publícala primero."];
            }
        }
        if ($errors) {
            throw new \App\Core\Http\ApiProblem(422, 'validation_failed', 'Revisa los enlaces', 'Hay enlaces a páginas que no existen.', ['errors' => $errors]);
        }

        $db = DB::connection('platform');
        $actor = $request->user()->id;
        $id = $db->selectOne("select id from site_menus where location = 'header' and deleted_at is null order by created_at limit 1")?->id;
        if ($id) {
            $db->update('update site_menus set items = ?::jsonb, is_active = true, updated_by = ? where id = ?', [json_encode($items, JSON_UNESCAPED_UNICODE), $actor, $id]);
        } else {
            $id = $db->selectOne("insert into site_menus (location, name, items, created_by) values ('header', 'Cabecera', ?::jsonb, ?) returning id", [json_encode($items, JSON_UNESCAPED_UNICODE), $actor])->id;
        }
        $accounts->audit($actor, 'site_menu_updated', 'site_menus', $id, ['items' => count($items)]);

        return response()->noContent();
    }
}
