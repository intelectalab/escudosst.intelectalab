<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Biblioteca de recursos (SCR-WEB-30 pública /recursos, SCR-WEB-43 panel, RN-WEB-40).
 *  Público: GET  /public/resources?tipo=&sector=        publicados (sin el archivo)
 *           POST /public/resources/{slug}/download       datos del lead si es «con registro»; devuelve un enlace firmado
 *           GET  /public/resources/file/{slug}           el archivo, solo con firma vigente (10 minutos)
 *  Panel:   GET/POST /internal/site/resources, PATCH .../{id}, POST .../{id}/{publish|archive}, DELETE .../{id}
 *
 * El archivo y la portada salen de la biblioteca de medios. Cada descarga queda en resource_downloads (inalterable,
 * con la IP en hash) y suma al contador; los datos de quien se registra entran a «Interesados» (marketing_leads).
 */
class ResourcesController extends Controller
{
    public const TYPES = ['template' => 'Plantilla', 'guide' => 'Guía', 'checklist' => 'Lista de verificación', 'format' => 'Formato'];

    private const LINK_MINUTES = 10;

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'email' => 'Escribe un correo válido.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'accepted' => 'Para descargar, acepta la política de privacidad.',
        'regex' => 'Solo minúsculas, números y guiones.',
        'uuid' => 'Elige un archivo de la biblioteca.',
    ];

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    // ---------------------------------------------------------------- Público

    public function publicIndex(Request $request): JsonResponse
    {
        $type = $request->query('tipo');
        $sector = $request->query('sector');
        $r = json_decode($this->db()->selectOne('select public_resources(?, ?) as r', [
            is_string($type) && isset(self::TYPES[$type]) ? $type : null,
            is_string($sector) && in_array($sector, SuccessStoriesController::SECTORS, true) ? $sector : null,
        ])->r, true);

        return response()->json(['data' => $r['resources'] ?? []])->header('Cache-Control', 'public, max-age=60');
    }

    public function download(Request $request, string $slug): JsonResponse
    {
        if (filled($request->input('website'))) {   // trampa para robots
            throw ApiProblem::notFound('El recurso no existe.');
        }
        $item = $this->db()->selectOne("select is_gated from resource_items where slug = ? and status = 'published' and deleted_at is null", [$slug])
            ?? throw ApiProblem::notFound('El recurso no existe o ya no está disponible.');
        $data = [];
        if ($item->is_gated || filled($request->input('email'))) {
            $d = $request->validate([
                'full_name' => ['required', 'string', 'min:3', 'max:120'],
                'email' => ['required', 'email', 'max:160'],
                'company_name' => ['required', 'string', 'min:2', 'max:160'],
                'phone' => ['nullable', 'string', 'max:20'],
                'sector' => ['nullable', 'in:'.implode(',', SuccessStoriesController::SECTORS)],
                'privacy_consent' => ['accepted'],
                'marketing_consent' => ['boolean'],
            ], self::MESSAGES);
            $data = $d + ['privacy_consent' => true, 'marketing_consent' => (bool) ($d['marketing_consent'] ?? false)];
        }
        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));   // nunca la IP en claro
        $r = json_decode($this->db()->selectOne(
            "select public_download_resource(?, ?::jsonb, decode(?, 'hex'), ?) as r",
            [$slug, json_encode($data, JSON_UNESCAPED_UNICODE), $ipHash, Str::uuid7()->toString()]
        )->r, true);
        if (($r['result'] ?? '') === 'lead_required') {
            throw new ApiProblem(422, 'validation_failed', 'Faltan tus datos', 'Este recurso pide tus datos antes de descargarlo.', ['errors' => ['email' => ['Completa tus datos para descargar.']]]);
        }
        if (($r['result'] ?? '') !== 'ok') {
            throw ApiProblem::notFound('El recurso no existe o ya no está disponible.');
        }

        return response()->json(['data' => [
            'title' => $r['title'],
            'url' => URL::temporarySignedRoute('public.resources.file', now()->addMinutes(self::LINK_MINUTES), ['slug' => $slug]),
            'expires_minutes' => self::LINK_MINUTES,
        ]]);
    }

    /** Entrega el archivo solo con un enlace firmado vigente (middleware «signed» en la ruta). */
    public function file(string $slug): StreamedResponse
    {
        $a = $this->db()->selectOne(
            "select a.disk, a.path, a.mime_type, r.slug from resource_items r join site_assets a on a.id = r.file_id
              where r.slug = ? and r.status = 'published' and r.deleted_at is null",
            [$slug]
        );
        if (! $a || ! Storage::disk($a->disk)->exists($a->path)) {
            throw ApiProblem::notFound('El archivo no está disponible.');
        }
        $ext = pathinfo($a->path, PATHINFO_EXTENSION);

        return Storage::disk($a->disk)->download($a->path, "escudosst-{$a->slug}.{$ext}", [
            'Content-Type' => $a->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        $rows = $this->db()->select(
            "select r.id, r.title, r.slug, r.description, r.resource_type, r.sector, r.is_gated, r.status, r.published_at, r.download_count,
                    r.sort_order, r.seo_title, r.seo_description, r.file_id, r.cover_file_id,
                    fm.id as file_media_id, fm.title as file_title, a.mime_type as file_mime, a.size_bytes as file_size,
                    cm.id as cover_media_id,
                    (select count(*) from resource_downloads d where d.resource_id = r.id and d.lead_id is not null) as leads,
                    (select max(downloaded_at) from resource_downloads d where d.resource_id = r.id) as last_download_at
               from resource_items r
               left join site_assets a on a.id = r.file_id
               left join site_media fm on fm.file_id = r.file_id and fm.deleted_at is null
               left join site_media cm on cm.file_id = r.cover_file_id and cm.deleted_at is null
              where r.deleted_at is null
              order by (r.status = 'draft') desc, r.sort_order, r.created_at desc"
        );

        return response()->json(['data' => $rows, 'types' => self::TYPES]);
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $row = $this->validated($request, null);
        $actor = $request->user()->id;
        $id = $this->db()->table('resource_items')->insertGetId($row + ['created_by' => $actor, 'updated_by' => $actor]);
        $accounts->audit($actor, 'resource_created', 'resource_items', $id, ['slug' => $row['slug']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): Response
    {
        $current = $this->find($id);
        $row = $this->validated($request, $current);
        if ($current->status === 'published' && ! $row['file_id']) {
            $this->fail('media_id', 'Un recurso publicado necesita su archivo.');
        }
        $this->db()->table('resource_items')->where('id', $id)->update($row + ['updated_by' => $request->user()->id]);
        $accounts->audit($request->user()->id, 'resource_updated', 'resource_items', $id, ['slug' => $row['slug']]);

        return response()->noContent();
    }

    public function act(Request $request, string $id, string $action, AccountsService $accounts): Response
    {
        $r = $this->find($id);
        if ($action === 'publish') {
            if (! $r->file_id) {
                $this->fail('media_id', 'Para publicarlo, elige el archivo que se descarga.');
            }
            $this->db()->update("update resource_items set status = 'published', published_at = coalesce(published_at, now()), updated_by = ? where id = ?", [$request->user()->id, $id]);
        } else {
            $this->db()->update("update resource_items set status = 'archived', updated_by = ? where id = ?", [$request->user()->id, $id]);
        }
        $accounts->audit($request->user()->id, "resource_{$action}", 'resource_items', $id, []);

        return response()->noContent();
    }

    public function destroy(Request $request, string $id, AccountsService $accounts): Response
    {
        $r = $this->find($id);
        if ($r->download_count > 0) {
            $this->fail('id', 'Ya tiene descargas registradas: quítalo del sitio en lugar de eliminarlo.');
        }
        $this->db()->update('update resource_items set deleted_at = now(), updated_by = ? where id = ?', [$request->user()->id, $id]);
        $accounts->audit($request->user()->id, 'resource_deleted', 'resource_items', $id, []);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Apoyo

    private function validated(Request $request, ?object $current): array
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:140'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'resource_type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            'sector' => ['nullable', 'in:'.implode(',', SuccessStoriesController::SECTORS)],
            'media_id' => ['nullable', 'uuid'],
            'cover_media_id' => ['nullable', 'uuid'],
            'is_gated' => ['boolean'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:170'],
            'sort_order' => ['integer', 'between:0,999'],
        ], self::MESSAGES);

        $slug = ($d['slug'] ?? null) ?: Str::slug($d['title']);
        if ($this->db()->selectOne('select 1 from resource_items where slug = ? and deleted_at is null and id is distinct from ?::uuid', [$slug, $current?->id])) {
            $this->fail('slug', 'Ya hay otro recurso con esta dirección.');
        }
        $file = null;
        if (! empty($d['media_id'])) {
            $m = $this->db()->selectOne("select file_id from site_media where id = ? and kind in ('document', 'image') and deleted_at is null", [$d['media_id']])
                ?? $this->fail('media_id', 'Elige un PDF o una imagen de la biblioteca.');
            $file = $m->file_id;
        }
        $cover = null;
        if (! empty($d['cover_media_id'])) {
            $c = $this->db()->selectOne("select file_id from site_media where id = ? and kind = 'image' and deleted_at is null", [$d['cover_media_id']])
                ?? $this->fail('cover_media_id', 'La portada debe ser una imagen de la biblioteca.');
            $cover = $c->file_id;
        }
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'title' => trim($d['title']), 'slug' => $slug, 'description' => $clean($d['description'] ?? null),
            'resource_type' => $d['resource_type'], 'sector' => $d['sector'] ?? null, 'file_id' => $file, 'cover_file_id' => $cover,
            'is_gated' => (bool) ($d['is_gated'] ?? false), 'seo_title' => $clean($d['seo_title'] ?? null),
            'seo_description' => $clean($d['seo_description'] ?? null), 'sort_order' => $d['sort_order'] ?? 0,
        ];
    }

    private function find(string $id): object
    {
        return $this->db()->selectOne('select * from resource_items where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('El recurso no existe.');
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
