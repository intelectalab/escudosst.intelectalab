<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Http\Controllers\Concerns\SitePanelHelpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Integraciones (SCR-WEB-73 pública /integraciones y SCR-WEB-74 panel).
 *  Público: GET /public/integrations
 *  Panel:   GET/POST /internal/site/integrations, PUT .../{id}, POST .../{id}/{publish|hide}, DELETE .../{id}
 * Estado veraz: «Disponible» solo si la integración ya funciona en el producto; si no, «Beta» o «Planificada».
 */
class IntegrationsController extends Controller
{
    use SitePanelHelpers;

    public const CATEGORIES = [
        'messaging' => 'Mensajería', 'lms' => 'Capacitación (LMS)', 'payments' => 'Pagos', 'crm' => 'CRM', 'calendar' => 'Calendario',
        'email' => 'Correo', 'storage' => 'Almacenamiento', 'ai' => 'Inteligencia artificial', 'analytics' => 'Métricas',
    ];

    public const AVAILABILITY = ['available' => 'Disponible', 'beta' => 'Beta', 'planned' => 'Planificada'];

    public function publicIndex(): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_site_integrations() as r')->r, true);

        return response()->json(['data' => $r['integrations'], 'categories' => self::CATEGORIES])->header('Cache-Control', 'public, max-age=60');
    }

    public function index(): JsonResponse
    {
        $rows = $this->db()->select(
            'select i.*, m.file_id as logo_file_id from site_integrations i left join site_media m on m.id = i.logo_media_id
              where i.deleted_at is null order by i.category, i.sort_order, i.name'
        );

        return response()->json(['data' => $rows, 'categories' => self::CATEGORIES, 'availability' => self::AVAILABILITY]);
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $row = $this->validated($request, null);
        $actor = $request->user()->id;
        $id = $this->db()->table('site_integrations')->insertGetId($row + ['created_by' => $actor, 'updated_by' => $actor]);
        $accounts->audit($actor, 'site_integration_created', 'site_integrations', $id, ['name' => $row['name']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): Response
    {
        $current = $this->row('site_integrations', $id, 'La integración no existe.');
        $row = $this->validated($request, $current);
        $this->db()->table('site_integrations')->where('id', $id)->update($row + ['updated_by' => $request->user()->id, 'updated_at' => now()]);
        $accounts->audit($request->user()->id, 'site_integration_updated', 'site_integrations', $id, ['name' => $row['name'], 'availability' => $row['availability']]);

        return response()->noContent();
    }

    public function act(Request $request, string $id, string $action, AccountsService $accounts): Response
    {
        $i = $this->row('site_integrations', $id, 'La integración no existe.');
        if ($action === 'publish') {
            $this->assertAllowedText('description', $i->name, (string) $i->description);
        }
        $this->db()->update('update site_integrations set status = ?, updated_by = ?, updated_at = now() where id = ?', [$action === 'publish' ? 'published' : 'hidden', $request->user()->id, $id]);
        $accounts->audit($request->user()->id, "site_integration_{$action}", 'site_integrations', $id, ['name' => $i->name]);

        return response()->noContent();
    }

    public function destroy(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->row('site_integrations', $id, 'La integración no existe.');
        $this->db()->update("update site_integrations set deleted_at = now(), status = 'hidden', updated_by = ? where id = ?", [$request->user()->id, $id]);
        $accounts->audit($request->user()->id, 'site_integration_deleted', 'site_integrations', $id, []);

        return response()->noContent();
    }

    private function validated(Request $request, ?object $current): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'category' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:300'],
            'availability' => ['required', 'in:'.implode(',', array_keys(self::AVAILABILITY))],
            'docs_url' => ['nullable', 'string', 'max:300', 'regex:~^(https://|/)~'],
            'logo_media_id' => ['nullable', 'uuid'],
            'sort_order' => ['integer', 'between:0,999'],
        ], self::MESSAGES + ['docs_url.regex' => 'El enlace debe empezar con https:// o con / (página del sitio).']);
        $slug = Str::slug($d['name']);
        if ($this->db()->selectOne('select 1 from site_integrations where slug = ? and deleted_at is null and id is distinct from ?::uuid', [$slug, $current?->id])) {
            $this->fail('name', 'Ya hay otra integración con ese nombre.');
        }
        $this->assertAllowedText('description', $d['name'], (string) ($d['description'] ?? ''));

        return [
            'name' => trim($d['name']), 'slug' => $slug, 'category' => $d['category'], 'description' => $this->clean($d['description'] ?? null),
            'availability' => $d['availability'], 'docs_url' => $this->clean($d['docs_url'] ?? null),
            'logo_media_id' => $this->media($d['logo_media_id'] ?? null, 'logo_media_id', ['image', 'icon'], 'El logo debe ser una imagen de la biblioteca de medios.'),
            'sort_order' => $d['sort_order'] ?? 0,
        ];
    }
}
