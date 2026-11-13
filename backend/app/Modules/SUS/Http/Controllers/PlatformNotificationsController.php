<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WEB\Services\ComplaintsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Avisos del panel de la plataforma (campana de la barra superior).
 *  GET /internal/notifications
 *
 * Se calculan al momento desde lo que ya está pendiente (no hay tabla propia): solicitudes e interesados nuevos,
 * reclamos por vencer o vencidos, testimonios y casos de éxito por aprobar, pruebas gratis que terminan pronto y los avisos
 * de M-WEB §10 (interesados por anonimizar, demos sin resultado, calendario desconectado, reseñas con error, campañas por terminar).
 * Cada aviso dice cuántos hay, desde cuándo (`at`, lo más reciente) y a dónde ir. «Leído» lo guarda el navegador.
 * Solo cuenta: no muestra datos de negocio de las empresas.
 */
class PlatformNotificationsController extends Controller
{
    public function index(Request $request, ComplaintsService $complaints): JsonResponse
    {
        $db = DB::connection('platform');
        $isOwner = $request->user()?->platform_role === 'owner';
        $me = (string) $request->user()?->id;
        $param = fn (string $key) => (int) ($db->selectOne("select web_try_numeric(platform_legal_param(?)) as v", [$key])->v ?? 0);
        $items = [];
        $add = function (string $key, string $group, string $icon, string $tone, int $count, ?string $at, string $title, string $detail, string $href) use (&$items) {
            if ($count > 0) {
                $items[] = compact('key', 'group', 'icon', 'tone', 'count', 'at', 'title', 'detail', 'href');
            }
        };
        $plural = fn (int $n, string $one, string $many) => $n === 1 ? $one : $many;

        // Copias de seguridad: si la última buena es vieja o la última falló (docs/15 §10).
        foreach (app(\App\Modules\SUS\Services\BackupService::class)->status()['targets'] as $b) {
            $failed = $b['last_status'] === 'failed';
            $add("backup_{$b['target']}", 'Sistema', 'database', 'bad', ($b['overdue'] || $failed) ? 1 : 0, $b['last_ok_at'],
                $failed ? "Falló la copia de seguridad: {$b['label']}" : "Copia de seguridad atrasada: {$b['label']}",
                $failed ? 'Revisa el error en Salud del sistema y vuelve a intentarlo.' : 'No hay una copia reciente. Revisa Salud del sistema.', '/plataforma/salud');
        }

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

        // Cotizaciones: constancia de pago por revisar (el cliente espera su acceso) y aceptadas que aún no pagan.
        $r = $db->selectOne("select count(*) as n, max(payment_submitted_at) as at from sales_quotes where status = 'payment_submitted' and deleted_at is null");
        $add('quotes_payment', 'Ventas', 'check', 'amber', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 pago por confirmar', "{$r->n} pagos por confirmar"), 'Revisa la constancia y confirma el pago para crear la cuenta.', '/plataforma/sitio/cotizaciones');
        $r = $db->selectOne("select count(*) as n, max(accepted_at) as at from sales_quotes where status = 'accepted' and deleted_at is null");
        $add('quotes_accepted', 'Ventas', 'clock', 'brand', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 cotización aceptada', "{$r->n} cotizaciones aceptadas"), 'Esperando que el cliente pague y envíe su constancia.', '/plataforma/sitio/cotizaciones');
        $r = $db->selectOne("select count(*) as n, max(created_at) as at from marketing_leads where status = 'new' and deleted_at is null");
        $add('leads_new', 'Ventas', 'people', 'brand', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 interesado nuevo', "{$r->n} interesados nuevos"), 'Personas que dejaron sus datos en el sitio.', '/plataforma/sitio/interesados');

        $r = $db->selectOne("select count(*) as n, max(started_at) as at from status_incidents where status <> 'resolved' and published_at is not null and deleted_at is null");
        $add('incidents_open', 'Estado del servicio', 'shield', 'bad', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 incidente abierto', "{$r->n} incidentes abiertos"), 'Se ve en /estado. Agrega actualizaciones hasta resolverlo.', '/plataforma/sitio/estado');
        $r = $db->selectOne("select count(*) as n, max(created_at) as at from status_incidents where published_at is null and deleted_at is null");
        $add('incidents_draft', 'Estado del servicio', 'shield', 'amber', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 incidente por publicar', "{$r->n} incidentes por publicar"), 'Registrado por el equipo; aún no se ve en /estado.', '/plataforma/sitio/estado');

        $r = $db->selectOne("select count(*) as n, max(updated_at) as at from crm_sync_events where status = 'failed' and attempts >= ?", [\App\Modules\WEB\Services\CrmService::MAX_ATTEMPTS]);
        $add('crm_failed', 'Ventas', 'refresh', 'bad', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 envío al CRM falló', "{$r->n} envíos al CRM fallaron"), 'Se agotaron los reintentos. Revisa la conexión y reintenta.', '/plataforma/sitio/crm');

        $r = $db->selectOne("select count(*) as n, max(created_at) as at from partner_applications where status = 'pending' and deleted_at is null");
        $add('partners_pending', 'Ventas', 'building', 'brand', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 solicitud de partner', "{$r->n} solicitudes de partner"), 'Consultoras o profesionales que quieren referir clientes.', '/plataforma/sitio/partners');

        $r = $db->selectOne("select count(*) as n, max(created_at) as at from site_testimonials where status = 'pending' and deleted_at is null");
        $add('testimonials_pending', 'Sitio web', 'heart', 'amber', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 testimonio por aprobar', "{$r->n} testimonios por aprobar"), 'Revísalos antes de que salgan en el sitio.', '/plataforma/sitio/contenido');

        $r = $db->selectOne("select count(*) as n, max(updated_at) as at from success_stories where status = 'draft' and deleted_at is null and (consent_file_id is not null or is_example)");
        $add('stories_ready', 'Sitio web', 'star', 'amber', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 caso de éxito listo para aprobar', "{$r->n} casos de éxito listos para aprobar"), 'Ya tienen la autorización del cliente.', '/plataforma/sitio/casos');

        // Avisos de M-WEB §10 (sitio web, agenda y captación).
        $days = $param('web.lead_retention_notice_days');
        if ($days > 0) {
            $r = $db->selectOne(
                "select count(*) as n, max(last_activity_at) as at from marketing_leads
                  where status <> 'converted' and deleted_at is null and retain_until is not null and retain_until <= current_date + ?::int",
                [$days]
            );
            $add('leads_retention', 'Ventas', 'people', 'amber', (int) $r->n, $r->at,
                $plural((int) $r->n, '1 interesado por anonimizar', "{$r->n} interesados por anonimizar"),
                "Se cumple su plazo de conservación en {$days} días o menos. Si sigues en contacto, actualiza su ficha.", '/plataforma/sitio/interesados');
        }

        // Demos que ya terminaron sin resultado: el dueño ve todas; el anfitrión, las suyas.
        $r = $db->selectOne(
            "select count(*) as n, max(m.ends_at) as at from demo_meetings m left join demo_hosts h on h.id = m.host_id
              where m.status in ('confirmed', 'rescheduled') and m.outcome is null and m.ends_at < now() and m.deleted_at is null
                and (?::boolean or h.user_id = ?::uuid)",
            [$isOwner ? 'true' : 'false', $me ?: null]
        );
        $add('demos_no_outcome', 'Ventas', 'clock', 'amber', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 demo sin resultado', "{$r->n} demos sin resultado"), 'La reunión ya terminó. Registra cómo te fue o marca que no asistió.', '/plataforma/sitio/agenda');

        $r = $db->selectOne(
            'select count(*) as n, max(t.updated_at) as at from demo_calendar_tokens t join demo_hosts h on h.id = t.host_id
              where t.last_error = ? and h.deleted_at is null and (?::boolean or h.user_id = ?::uuid)',
            [\App\Modules\WEB\Services\GoogleCalendarService::REFRESH_FAILED, $isOwner ? 'true' : 'false', $me ?: null]
        );
        $add('calendar_disconnected', 'Ventas', 'refresh', 'bad', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 calendario desconectado', "{$r->n} calendarios desconectados"), 'Google ya no acepta el permiso. Vuelve a conectar el calendario en la agenda.', '/plataforma/sitio/agenda');

        if ($isOwner) {
            $r = $db->selectOne("select count(*) as n, max(updated_at) as at from review_sources where status = 'error' and deleted_at is null");
            $add('reviews_error', 'Sitio web', 'star', 'bad', (int) $r->n, $r->at,
                'No se pudieron leer las reseñas de Google', 'Revisa el error en Reseñas. Mientras tanto se muestra la última copia.', '/plataforma/sitio/resenas');

            $days = $param('web.campaign_end_notice_days');
            if ($days > 0) {
                $r = $db->selectOne(
                    "select count(*) as n, max(updated_at) as at from campaigns
                      where status = 'active' and deleted_at is null and ends_on between current_date and current_date + ?::int",
                    [$days]
                );
                $add('campaigns_ending', 'Sitio web', 'clock', 'amber', (int) $r->n, $r->at,
                    $plural((int) $r->n, '1 campaña por terminar', "{$r->n} campañas por terminar"), "Termina en {$days} días o menos. Decide si la extiendes o la cierras.", '/plataforma/sitio/campanas');
            }
        }

        $r = $db->selectOne(
            "select count(*) as n, max(created_at) as at from subscriptions
              where status = 'trialing' and trial_ends_at between now() and now() + interval '3 days'"
        );
        $add('trials_ending', 'Clientes', 'building', 'amber', (int) $r->n, $r->at,
            $plural((int) $r->n, '1 prueba gratis termina en 3 días o menos', "{$r->n} pruebas gratis terminan en 3 días o menos"), 'Buen momento para contactarlos.', '/plataforma/cuentas');

        return response()->json(['data' => $items, 'total' => array_sum(array_column($items, 'count'))]);
    }
}
