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

    /** Enlaces del menú de cabecera (los visibles, en orden), para el sitio público. */
    public static function headerItems(bool $onlyVisible = true): array
    {
        $row = DB::connection('platform')->selectOne("select items from site_menus where location = 'header' and is_active and deleted_at is null order by created_at limit 1");
        $items = $row ? json_decode($row->items, true) : self::DEFAULT;

        return array_values($onlyVisible ? array_filter($items, fn ($i) => $i['visible'] ?? true) : $items);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => self::headerItems(false), 'defaults' => self::DEFAULT]);
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
