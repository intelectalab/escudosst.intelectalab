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
 * Ventanas emergentes (SCR-WEB-71 pública y SCR-WEB-72 panel).
 *  Público: GET /public/popups?path=/planes&device=mobile   las que aplican a esa página (nunca en Libro de
 *           Reclamaciones, políticas ni formularios: lo decide la base con web_popup_forbidden_path)
 *  Panel:   GET/POST /internal/site/popups, PUT .../{id}, POST .../{id}/{activate|pause} (activar: Dueño), DELETE .../{id}
 *
 * Métricas: eventos popup_view, popup_click y popup_close con element_key = id de la ventana.
 */
class PopupsController extends Controller
{
    use SitePanelHelpers;

    public const TRIGGERS = ['exit_intent' => 'Al intentar salir de la página', 'scroll_percent' => 'Al bajar un % de la página', 'time_on_page' => 'Después de unos segundos'];

    public const OFFERS = [
        'fine_calculator' => ['Calculadora de multas', '/herramientas/calculadora-multas'],
        'savings_calculator' => ['Calculadora de ahorro', '/herramientas/calculadora-ahorro'],
        'self_assessment' => ['Autodiagnóstico Ley 29783', '/herramientas/autodiagnostico'],
        'resource' => ['Recursos gratis', '/recursos'],
        'demo' => ['Agenda una demo', '/demo'],
        'newsletter' => ['Boletín normativo', '/boletin'],
        'webinar' => ['Webinars', '/webinars'],
    ];

    public function publicIndex(Request $request): JsonResponse
    {
        $path = (string) $request->query('path', '/');
        $path = str_starts_with($path, '/') ? mb_substr(strtok($path, '?#') ?: '/', 0, 300) : '/';
        $device = in_array($request->query('device'), ['desktop', 'mobile', 'tablet'], true) ? $request->query('device') : 'desktop';
        $r = json_decode($this->db()->selectOne('select public_site_popups(?, ?) as r', [$path, $device])->r, true);
        foreach ($r['popups'] as &$p) {
            $p['href'] = self::OFFERS[$p['offer_type']][1] ?? '/';
        }

        return response()->json(['data' => ['max_per_visit' => $r['max_per_visit'], 'popups' => $r['popups']]])->header('Cache-Control', 'public, max-age=60');
    }

    public function index(): JsonResponse
    {
        $rows = $this->db()->select(
            "select w.*,
                    (select count(*) from site_events e where e.event_type = 'popup_view' and e.element_key = w.id::text) as views,
                    (select count(*) from site_events e where e.event_type = 'popup_click' and e.element_key = w.id::text) as clicks,
                    (select count(*) from site_events e where e.event_type = 'popup_close' and e.element_key = w.id::text) as closes
               from site_popups w where w.deleted_at is null order by (w.status = 'active') desc, w.created_at desc"
        );

        return response()->json(['data' => $rows, 'triggers' => self::TRIGGERS, 'offers' => array_map(fn ($o) => $o[0], self::OFFERS)]);
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $row = $this->validated($request);
        $actor = $request->user()->id;
        $id = $this->db()->table('site_popups')->insertGetId($row + ['created_by' => $actor, 'updated_by' => $actor]);
        $accounts->audit($actor, 'site_popup_created', 'site_popups', $id, ['name' => $row['name']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->row('site_popups', $id, 'La ventana no existe.');
        $row = $this->validated($request);
        $this->db()->table('site_popups')->where('id', $id)->update($row + ['updated_by' => $request->user()->id, 'updated_at' => now()]);
        $accounts->audit($request->user()->id, 'site_popup_updated', 'site_popups', $id, ['name' => $row['name']]);

        return response()->noContent();
    }

    public function act(Request $request, string $id, string $action, AccountsService $accounts): Response
    {
        $w = $this->row('site_popups', $id, 'La ventana no existe.');
        if ($action === 'activate') {
            if ($request->user()->platform_role !== 'owner') {
                throw ApiProblem::forbidden('Solo el Dueño de la plataforma activa ventanas emergentes.');
            }
            $this->assertAllowedText('title', $w->title, (string) $w->body);
        }
        $this->db()->update('update site_popups set status = ?, updated_by = ?, updated_at = now() where id = ?', [$action === 'activate' ? 'active' : 'paused', $request->user()->id, $id]);
        $accounts->audit($request->user()->id, "site_popup_{$action}", 'site_popups', $id, ['name' => $w->name]);

        return response()->noContent();
    }

    public function destroy(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->row('site_popups', $id, 'La ventana no existe.');
        $this->db()->update("update site_popups set deleted_at = now(), status = 'paused', updated_by = ? where id = ?", [$request->user()->id, $id]);
        $accounts->audit($request->user()->id, 'site_popup_deleted', 'site_popups', $id, []);

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'trigger_type' => ['required', 'in:'.implode(',', array_keys(self::TRIGGERS))],
            'trigger_value' => ['nullable', 'integer', 'required_unless:trigger_type,exit_intent'],
            'pages' => ['array', 'max:20'],
            'pages.*' => ['string', 'max:120', 'regex:~^/[a-z0-9/_*-]*$~'],
            'excluded_pages' => ['array', 'max:20'],
            'excluded_pages.*' => ['string', 'max:120', 'regex:~^/[a-z0-9/_*-]*$~'],
            'devices' => ['required', 'in:all,desktop,mobile'],
            'offer_type' => ['required', 'in:'.implode(',', array_keys(self::OFFERS))],
            'title' => ['required', 'string', 'min:3', 'max:80'],
            'body' => ['nullable', 'string', 'max:300'],
            'cta_label' => ['nullable', 'string', 'max:30'],
            'image_media_id' => ['nullable', 'uuid'],
            'frequency_days' => ['required', 'integer', 'between:1,365'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ], self::MESSAGES + [
            'trigger_value.required_unless' => 'Indica el % de la página o los segundos.',
            'pages.*.regex' => 'Escribe direcciones que empiecen con / (por ejemplo: /planes o /sectores/*).',
            'excluded_pages.*.regex' => 'Escribe direcciones que empiecen con / (por ejemplo: /planes).',
        ]);
        $value = $d['trigger_type'] === 'exit_intent' ? null : (int) $d['trigger_value'];
        if ($d['trigger_type'] === 'scroll_percent' && ($value < 1 || $value > 100)) {
            $this->fail('trigger_value', 'El porcentaje va de 1 a 100.');
        }
        if ($d['trigger_type'] === 'time_on_page' && ($value < 3 || $value > 3600)) {
            $this->fail('trigger_value', 'Los segundos van de 3 a 3600.');
        }
        $this->assertAllowedText('title', $d['title'], (string) ($d['body'] ?? ''));

        return [
            'name' => trim($d['name']), 'trigger_type' => $d['trigger_type'], 'trigger_value' => $value,
            'pages_json' => json_encode(array_values(array_unique($d['pages'] ?? []))), 'excluded_pages_json' => json_encode(array_values(array_unique($d['excluded_pages'] ?? []))),
            'devices' => $d['devices'], 'offer_type' => $d['offer_type'], 'title' => trim($d['title']), 'body' => $this->clean($d['body'] ?? null),
            'cta_label' => $this->clean($d['cta_label'] ?? null), 'image_media_id' => $this->media($d['image_media_id'] ?? null, 'image_media_id'),
            'frequency_days' => (int) $d['frequency_days'], 'starts_at' => $d['starts_at'] ?? null, 'ends_at' => $d['ends_at'] ?? null,
        ];
    }
}
