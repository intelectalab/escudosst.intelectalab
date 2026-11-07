<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Http\Controllers\Concerns\SitePanelHelpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Campañas de anuncios (SCR-WEB-69 landing pública /l/{slug} y SCR-WEB-70 panel).
 *  Público: GET /public/campaigns/{slug}   landing vigente; si la campaña ya terminó, result=ended (aviso + portada)
 *  Panel:   GET/POST /internal/site/campaigns, PUT .../{id}, POST .../{id}/{activate|pause|finish} (activar: Dueño)
 *
 * La landing es una página del CMS de tipo «campaign» (Sitio web › Páginas, dirección l/…), noindex por defecto.
 * Las métricas salen de site_events (visitas y envíos de formulario con el utm_campaign) y de marketing_leads.
 */
class CampaignsController extends Controller
{
    use SitePanelHelpers;

    public const CHANNELS = ['google_ads' => 'Google Ads', 'meta_ads' => 'Meta (Facebook e Instagram)', 'linkedin' => 'LinkedIn', 'email' => 'Correo', 'other' => 'Otro'];

    public const FORMS = ['demo' => 'Agenda una demo', 'quote' => 'Pedir cotización', 'contact' => 'Hacer una consulta', 'resource' => 'Descargar un recurso', 'assessment' => 'Autodiagnóstico', 'calculator' => 'Calculadora de multas'];

    // ---------------------------------------------------------------- Público

    public function publicShow(string $slug): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_campaign_page(?) as r', ["l/{$slug}"])->r, true);
        if (($r['result'] ?? '') === 'ok') {
            unset($r['result']);
            $r['blocks'] = SitePagesController::withImages($r['blocks'] ?? []);

            return response()->json(['data' => ['result' => 'ok'] + $r])->header('Cache-Control', 'public, max-age=60');
        }
        // Página publicada pero campaña pausada, terminada o fuera de fechas: aviso y enlace a la portada.
        $exists = $this->db()->selectOne(
            "select 1 from site_pages where slug = ? and page_type = 'campaign' and status = 'published' and deleted_at is null", ["l/{$slug}"]
        );
        if ($exists) {
            return response()->json(['data' => ['result' => 'ended']]);
        }
        throw ApiProblem::notFound('La página no existe.');
    }

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        $rows = $this->db()->select(
            "select c.*, p.slug as landing_slug, p.title as landing_title, p.status as landing_status,
                    (select count(*) from site_events e where e.utm_campaign = c.utm_campaign and e.event_type in ('page_view', 'campaign_view')
                       and e.occurred_at >= coalesce(c.starts_on::timestamptz, c.created_at)) as visits,
                    (select count(*) from site_events e where e.utm_campaign = c.utm_campaign and e.event_type in ('form_submit', 'demo_booked')
                       and e.occurred_at >= coalesce(c.starts_on::timestamptz, c.created_at)) as conversions,
                    (select count(*) from marketing_leads l where l.utm_campaign = c.utm_campaign and l.deleted_at is null
                       and l.created_at >= coalesce(c.starts_on::timestamptz, c.created_at)) as leads
               from campaigns c left join site_pages p on p.id = c.landing_page_id
              where c.deleted_at is null
              order by (c.status = 'active') desc, c.created_at desc"
        );
        $pages = $this->db()->select("select id, slug, title, status from site_pages where page_type = 'campaign' and deleted_at is null order by title");

        return response()->json(['data' => $rows, 'landings' => $pages, 'channels' => self::CHANNELS, 'forms' => self::FORMS]);
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $row = $this->validated($request, null);
        $actor = $request->user()->id;
        $id = $this->db()->table('campaigns')->insertGetId($row + ['created_by' => $actor, 'updated_by' => $actor]);
        $accounts->audit($actor, 'campaign_created', 'campaigns', $id, ['name' => $row['name']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): Response
    {
        $c = $this->row('campaigns', $id, 'La campaña no existe.');
        $row = $this->validated($request, $c);
        if ($c->status === 'active' && ! $row['landing_page_id']) {
            $this->fail('landing_page_id', 'Una campaña activa necesita su landing.');
        }
        $this->db()->table('campaigns')->where('id', $id)->update($row + ['updated_by' => $request->user()->id, 'updated_at' => now()]);
        $accounts->audit($request->user()->id, 'campaign_updated', 'campaigns', $id, ['name' => $row['name']]);

        return response()->noContent();
    }

    public function act(Request $request, string $id, string $action, AccountsService $accounts): Response
    {
        $c = $this->row('campaigns', $id, 'La campaña no existe.');
        $status = ['activate' => 'active', 'pause' => 'paused', 'finish' => 'finished'][$action];
        if ($status === 'active') {
            if ($request->user()->platform_role !== 'owner') {
                throw ApiProblem::forbidden('Solo el Dueño de la plataforma activa campañas.');
            }
            $page = $c->landing_page_id ? $this->db()->selectOne('select status from site_pages where id = ?', [$c->landing_page_id]) : null;
            if (! $page) {
                $this->fail('landing_page_id', 'Elige la landing de la campaña antes de activarla.');
            }
            if ($page->status !== 'published') {
                $this->fail('landing_page_id', 'Publica primero la landing en Sitio web › Páginas.');
            }
            if (! $c->utm_campaign) {
                $this->fail('utm_campaign', 'Escribe el nombre de campaña (utm_campaign) para poder medirla.');
            }
        }
        $this->db()->update('update campaigns set status = ?, updated_by = ?, updated_at = now() where id = ?', [$status, $request->user()->id, $id]);
        $accounts->audit($request->user()->id, "campaign_{$action}", 'campaigns', $id, ['name' => $c->name]);

        return response()->noContent();
    }

    private function validated(Request $request, ?object $current): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'channel' => ['required', 'in:'.implode(',', array_keys(self::CHANNELS))],
            'utm_source' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_.-]+$/'],
            'utm_medium' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_.-]+$/'],
            'utm_campaign' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/'],
            'landing_page_id' => ['nullable', 'uuid'],
            'form_type' => ['nullable', 'in:'.implode(',', array_keys(self::FORMS))],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'goal' => ['nullable', 'string', 'max:300'],
        ], self::MESSAGES + ['utm_campaign.regex' => 'Solo minúsculas, números, guiones y guion bajo (por ejemplo: sst-construccion-2026).']);

        if (! empty($d['landing_page_id']) && ! $this->db()->selectOne("select 1 from site_pages where id = ? and page_type = 'campaign' and deleted_at is null", [$d['landing_page_id']])) {
            $this->fail('landing_page_id', 'Elige una landing de campaña (Sitio web › Páginas, tipo «Landing de campaña»).');
        }
        if (! empty($d['utm_campaign']) && $this->db()->selectOne('select 1 from campaigns where utm_campaign = ? and deleted_at is null and id is distinct from ?::uuid', [$d['utm_campaign'], $current?->id])) {
            $this->fail('utm_campaign', 'Otra campaña ya usa ese nombre: así no se podrían separar sus métricas.');
        }

        return [
            'name' => trim($d['name']), 'channel' => $d['channel'], 'utm_source' => $this->clean($d['utm_source'] ?? null),
            'utm_medium' => $this->clean($d['utm_medium'] ?? null), 'utm_campaign' => $this->clean($d['utm_campaign'] ?? null),
            'landing_page_id' => $d['landing_page_id'] ?? null, 'form_type' => $d['form_type'] ?? null,
            'starts_on' => $d['starts_on'] ?? null, 'ends_on' => $d['ends_on'] ?? null, 'goal' => $this->clean($d['goal'] ?? null),
        ];
    }
}
