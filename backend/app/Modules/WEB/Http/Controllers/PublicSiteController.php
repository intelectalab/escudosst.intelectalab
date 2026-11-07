<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Modules\SUS\Support\Pricing;
use Illuminate\Support\Facades\DB;

/**
 * Sitio web público (M-WEB, Parte 1). Sin sesión: lee solo lo publicado mediante las funciones public_* de la base
 * (SECURITY DEFINER), así nunca expone datos internos ni de empresas.
 *
 *  GET /public/site      ajustes públicos (marca, contacto, WhatsApp, barra de anuncio, datos legales) y otros productos
 *  GET /public/pricing   planes visibles, complementos y comparativa módulo × plan (lo mismo que se edita en Planes y módulos)
 *  GET /public/modules   módulos por grupo, con qué resuelve cada uno y el plan más bajo que lo incluye
 */
class PublicSiteController extends Controller
{
    public function site(): JsonResponse
    {
        $settings = json_decode(DB::selectOne('select public_site_settings() as s')->s, true);
        $products = json_decode(DB::connection('platform')->selectOne('select public_site_products() as p')->p, true)['products'] ?? [];

        // Barra de anuncio: solo si está activa y vigente.
        $bar = $settings['announcement_bar'] ?? null;
        if ($bar && (empty($bar['active']) || (! empty($bar['until']) && $bar['until'] < now('America/Lima')->toDateString()))) {
            $settings['announcement_bar'] = null;
        }
        // Google: la verificación va como meta en el HTML y el ID de Analytics se carga solo si la persona aceptó las
        // cookies analíticas. Correo de ventas: interno, recibe los avisos de solicitudes.
        $ids = is_array($settings['analytics_ids'] ?? null) ? $settings['analytics_ids'] : (json_decode((string) ($settings['analytics_ids'] ?? ''), true) ?: []);
        $settings['google'] = ['verification' => $settings['search_console_verification'] ?? null, 'ga4' => $ids['ga4'] ?? null];
        unset($settings['analytics_ids'], $settings['search_console_verification'], $settings['sales_email']);

        $menu = SiteMenuController::headerItems();
        // Páginas opcionales que el pie enlaza solo cuando ya están publicadas.
        $optional = [
            'comparison' => (bool) DB::connection('platform')->selectOne("select 1 as x from site_pages where slug = 'comparativa' and page_type = 'comparison' and status = 'published' and deleted_at is null"),
            'savings_calculator' => (bool) DB::connection('platform')->selectOne(
                "select 1 as x from savings_calculator_settings where singleton and status = 'published' and deleted_at is null
                    and assumptions_json ->> 'minutes_saved_per_record' is not null and assumptions_json ->> 'manual_hours_reduction_pct' is not null"
            ),
        ];

        return response()->json(['data' => ['settings' => $settings, 'products' => $products, 'menu' => $menu, 'optional' => $optional]])
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function pricing(): JsonResponse
    {
        $data = json_decode(DB::selectOne('select public_pricing() as p')->p, true);
        // Los precios son mensuales; el sitio calcula el anual con este descuento (0 = sin descuento, no se anuncia).
        $data['annual_discount_pct'] = Pricing::annualDiscountPct();

        return response()->json(['data' => $data])
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function modules(): JsonResponse
    {
        $rows = DB::select(
            "select trim(m.code) as code, m.name, m.solves, m.group_number, m.plan_code, m.is_addon,
                    (select p.code from plan_modules pm join plans p on p.id = pm.plan_id
                      where pm.module_code = m.code and p.code in ('E', 'P', 'C') and p.is_public and p.is_active
                      order by p.sort_order limit 1) as from_plan
               from modules m
              where m.deleted_at is null and m.plan_code not in ('I')
              order by m.group_number, m.number"
        );

        return response()->json(['data' => $rows])->header('Cache-Control', 'public, max-age=300');
    }
}
