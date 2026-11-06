<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WEB\Services\ComplaintsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Avisos del panel de la plataforma (campana de la barra superior).
 *  GET /internal/notifications
 *
 * Se calculan al momento desde lo que ya está pendiente (no hay tabla propia): solicitudes e interesados nuevos,
 * reclamos por vencer o vencidos, testimonios y casos de éxito por aprobar, y pruebas gratis que terminan pronto.
 * Cada aviso dice cuántos hay, desde cuándo (`at`, lo más reciente) y a dónde ir. «Leído» lo guarda el navegador.
 * Solo cuenta: no muestra datos de negocio de las empresas.
 */
class PlatformNotificationsController extends Controller
{
    public function index(ComplaintsService $complaints): JsonResponse
    {
        $db = DB::connection('platform');
        $items = [];
        $add = function (string $key, string $group, string $icon, string $tone, int $count, ?string $at, string $title, string $detail, string $href) use (&$items) {
            if ($count > 0) {
                $items[] = compact('key', 'group', 'icon', 'tone', 'count', 'at', 'title', 'detail', 'href');
            }
        };
        $plural = fn (int $n, string $one, string $many) => $n === 1 ? $one : $many;

        // Reclamos: lo más urgente primero (plazo legal).
        $open = collect($complaints->list())->whereIn('light', ['red', 'amber']);
        $red = $open->where('light', 'red')->count();
        $amber = $open->where('light', 'amber')->count();
        $add('complaints_overdue', 'Libro de Reclamaciones', 'history', 'bad', $red, $open->where('light', 'red')->max('filed_at'),
            $plural($red, '1 reclamo vencido', "{$red} reclamos vencidos"), 'Pasó el plazo de respuesta. Respóndelo cuanto antes.', '/plataforma/sitio/reclamos');
        $add('complaints_due', 'Libro de Reclamaciones', 'history', 'amber', $amber, $open->where('light', 'amber')->max('filed_at'),
            $plural($amber, '1 reclamo por vencer', "{$amber} reclamos por vencer"), 'Quedan pocos días para responder.', '/plataforma/sitio/reclamos');

        $r = $db->selectOne("select count(*) as n, max(created_at) as at from demo_requests where status = 'new' and deleted_at is null");
        $add('requests_new', 'Ventas', 'clock', 'brand', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 solicitud nueva', "{$r->n} solicitudes nuevas"), 'Demos, cotizaciones y consultas sin atender.', '/plataforma/sitio/solicitudes');

        $r = $db->selectOne("select count(*) as n, max(created_at) as at from marketing_leads where status = 'new' and deleted_at is null");
        $add('leads_new', 'Ventas', 'people', 'brand', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 interesado nuevo', "{$r->n} interesados nuevos"), 'Personas que dejaron sus datos en el sitio.', '/plataforma/sitio/interesados');

        $r = $db->selectOne("select count(*) as n, max(created_at) as at from site_testimonials where status = 'pending' and deleted_at is null");
        $add('testimonials_pending', 'Sitio web', 'heart', 'amber', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 testimonio por aprobar', "{$r->n} testimonios por aprobar"), 'Revísalos antes de que salgan en el sitio.', '/plataforma/sitio/contenido');

        $r = $db->selectOne("select count(*) as n, max(updated_at) as at from success_stories where status = 'draft' and deleted_at is null and (consent_file_id is not null or is_example)");
        $add('stories_ready', 'Sitio web', 'star', 'amber', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 caso de éxito listo para aprobar', "{$r->n} casos de éxito listos para aprobar"), 'Ya tienen la autorización del cliente.', '/plataforma/sitio/casos');

        $r = $db->selectOne(
            "select count(*) as n, max(created_at) as at from subscriptions
              where status = 'trialing' and trial_ends_at between now() and now() + interval '3 days'"
        );
        $add('trials_ending', 'Clientes', 'building', 'amber', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 prueba gratis termina en 3 días o menos', "{$r->n} pruebas gratis terminan en 3 días o menos"), 'Buen momento para contactarlos.', '/plataforma/cuentas');

        return response()->json(['data' => $items, 'total' => array_sum(array_column($items, 'count'))]);
    }
}
