<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Biblioteca de medios del sitio (SCR-WEB-65/66, RN-WEB-82).
 *  GET    /internal/site/media                 lista (?kind=&folder=&q=) y carpetas
 *  POST   /internal/site/media                 subir (archivo + título, texto alternativo, carpeta, etiquetas, «Ejemplo»)
 *  PATCH  /internal/site/media/{id}            datos (no el archivo)
 *  POST   /internal/site/media/{id}/replace    reemplazar el archivo conservando el registro y sus usos
 *  GET    /internal/site/media/{id}/usages     dónde se usa
 *  DELETE /internal/site/media/{id}            solo si no se usa en ningún lado
 *
 * Tipos por el contenido real del archivo (no por el nombre): imágenes PNG/JPG/WEBP/AVIF, íconos PNG/WEBP, documentos
 * PDF y videos MP4/WEBM. SVG no se acepta porque puede llevar código. Las imágenes exigen texto alternativo.
 * Los archivos son públicos (son del sitio) y se sirven por /public/site/assets/{file_id}.
 */
class MediaController extends Controller
{
    private const TYPES = [
        'image/png' => ['png', 'image'], 'image/jpeg' => ['jpg', 'image'], 'image/webp' => ['webp', 'image'], 'image/avif' => ['avif', 'image'],
        'application/pdf' => ['pdf', 'document'],
        'video/mp4' => ['mp4', 'video'], 'video/webm' => ['webm', 'video'],
    ];

    /** Tamaño máximo por tipo, en KB. */
    private const MAX_KB = ['image' => 5120, 'icon' => 1024, 'document' => 15360, 'video' => 51200];

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'regex' => 'Solo letras, números, guiones y espacios.',
        'file.required' => 'Elige un archivo.',
        'file.max' => 'El archivo pesa demasiado (máximo 50 MB).',
        'alt_text.required_if' => 'Describe la imagen: lo leen los lectores de pantalla y Google.',
    ];

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    public function index(Request $request): JsonResponse
    {
        $kind = $request->query('kind');
        $folder = $request->query('folder');
        $q = trim((string) $request->query('q', ''));
        $rows = $this->db()->select(
            "select m.id, m.file_id, m.kind, m.title, m.alt_text, m.width, m.height, m.size_bytes, m.folder, m.tags_json, m.is_example,
                    m.usage_count, m.created_at, m.updated_at, a.mime_type, a.original_name
               from site_media m join site_assets a on a.id = m.file_id
              where m.deleted_at is null
                and (?::text is null or m.kind = ?)
                and (?::text is null or coalesce(m.folder, '') = ?)
                and (? = '' or m.title ilike ? or coalesce(m.alt_text, '') ilike ? or m.tags_json::text ilike ?)
              order by m.created_at desc limit 300",
            [$kind ?: null, $kind ?: null, $folder !== null ? (string) $folder : null, (string) $folder, $q, "%{$q}%", "%{$q}%", "%{$q}%"]
        );
        $folders = array_map(fn ($r) => $r->folder, $this->db()->select(
            "select distinct folder from site_media where deleted_at is null and folder is not null and folder <> '' order by folder"
        ));

        return response()->json(['data' => array_map($this->decode(...), $rows), 'folders' => $folders]);
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:51200']], self::MESSAGES);
        $file = $request->file('file');
        [$ext, $kind] = $this->type($file, (bool) $request->boolean('as_icon'));
        $meta = $this->meta($request, $kind);
        $asset = $this->storeAsset($file, $ext, $request->user()->id);

        $id = $this->db()->table('site_media')->insertGetId($meta + [
            'file_id' => $asset['id'], 'kind' => $kind, 'width' => $asset['w'], 'height' => $asset['h'], 'size_bytes' => $file->getSize(),
            'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
        ]);
        $accounts->audit($request->user()->id, 'site_media_uploaded', 'site_media', $id, ['kind' => $kind, 'title' => $meta['title']]);

        return response()->json(['data' => $this->decode($this->row($id))], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $m = $this->find($id);
        $meta = $this->meta($request, $m->kind);
        $this->db()->table('site_media')->where('id', $id)->update($meta + ['updated_by' => $request->user()->id]);
        $accounts->audit($request->user()->id, 'site_media_updated', 'site_media', $id, ['fields' => array_keys($meta)]);

        return response()->json(['data' => $this->decode($this->row($id))]);
    }

    /** Reemplaza el archivo: mismo registro y mismos usos (RN-WEB-82). Debe ser del mismo tipo. */
    public function replace(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $m = $this->find($id);
        $request->validate(['file' => ['required', 'file', 'max:51200']], self::MESSAGES);
        $file = $request->file('file');
        [$ext, $kind] = $this->type($file, $m->kind === 'icon');
        if ($kind !== $m->kind) {
            $this->fail('file', 'El archivo nuevo debe ser del mismo tipo que el actual ('.$this->kindLabel($m->kind).').');
        }
        $asset = $this->storeAsset($file, $ext, $request->user()->id);
        $this->db()->update(
            'update site_media set file_id = ?, width = ?, height = ?, size_bytes = ?, updated_by = ? where id = ?',
            [$asset['id'], $asset['w'], $asset['h'], $file->getSize(), $request->user()->id, $id]
        );
        // Si estaba incrustado por su archivo en páginas o casos, se actualiza la referencia.
        $this->db()->update('update success_stories set logo_file_id = ? where logo_file_id = ?', [$asset['id'], $m->file_id]);
        $accounts->audit($request->user()->id, 'site_media_replaced', 'site_media', $id, ['from' => $m->file_id, 'to' => $asset['id']]);

        return response()->json(['data' => $this->decode($this->row($id))]);
    }

    public function usages(string $id): JsonResponse
    {
        $this->find($id);
        $r = json_decode($this->db()->selectOne('select web_media_usages(?) as r', [$id])->r, true);
        $labels = [
            'success_story_media' => 'Galería de un caso de éxito', 'success_stories' => 'Logo de un caso de éxito', 'site_pages' => 'Página del sitio',
            'site_settings' => 'Logo o favicon del sitio', 'site_popups' => 'Ventana emergente', 'site_integrations' => 'Integración',
            'webinars' => 'Webinar', 'chatbot_settings' => 'Chatbot', 'resource_items' => 'Recurso descargable', 'site_products' => 'Otro producto',
            'site_testimonials' => 'Testimonio',
        ];
        $items = array_map(function ($u) use ($labels) {
            $name = match ($u['table']) {
                'success_story_media', 'success_stories' => $this->db()->selectOne('select title from success_stories where id = ?', [$u['id']])?->title,
                'site_pages' => $u['slug'] ?? null,
                default => null,
            };
            $href = match ($u['table']) {
                'success_story_media', 'success_stories' => "/plataforma/sitio/casos/{$u['id']}",
                'site_pages' => "/plataforma/sitio/paginas/{$u['id']}",
                'site_settings' => '/plataforma/sitio/marca',
                default => null,
            };

            return ['label' => $labels[$u['table']] ?? $u['table'], 'name' => $name, 'href' => $href];
        }, $r['usages'] ?? []);

        return response()->json(['data' => ['count' => $r['usage_count'] ?? 0, 'items' => $items]]);
    }

    public function destroy(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->find($id);
        $count = json_decode($this->db()->selectOne('select web_media_usages(?) as r', [$id])->r, true)['usage_count'] ?? 0;
        if ($count > 0) {
            $this->fail('id', "No se puede eliminar: se usa en {$count} ".($count === 1 ? 'lugar' : 'lugares').'. Quítalo de ahí primero o reemplaza el archivo.');
        }
        $this->db()->update('update site_media set deleted_at = now(), updated_by = ? where id = ?', [$request->user()->id, $id]);
        $accounts->audit($request->user()->id, 'site_media_deleted', 'site_media', $id, []);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Apoyo

    /** [extensión, tipo] según el contenido real del archivo. */
    private function type(UploadedFile $file, bool $asIcon): array
    {
        $mime = (string) $file->getMimeType();
        if (! isset(self::TYPES[$mime])) {
            $real = strtoupper(explode('/', $mime)[1] ?? 'desconocido');
            $this->fail('file', "Este archivo es {$real} por dentro. Se aceptan imágenes PNG, JPG, WEBP o AVIF, documentos PDF y videos MP4 o WEBM (SVG no, por seguridad).");
        }
        [$ext, $kind] = self::TYPES[$mime];
        if ($asIcon && $kind === 'image' && in_array($ext, ['png', 'webp'], true)) {
            $kind = 'icon';
        }
        if ($file->getSize() > self::MAX_KB[$kind] * 1024) {
            $this->fail('file', 'Pesa demasiado para un '.$this->kindLabel($kind).': máximo '.(self::MAX_KB[$kind] / 1024).' MB.');
        }

        return [$ext, $kind];
    }

    private function meta(Request $request, string $kind): array
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'alt_text' => [in_array($kind, ['image', 'icon'], true) ? 'required' : 'nullable', 'string', 'max:250'],
            'folder' => ['nullable', 'string', 'max:60', 'regex:/^[\pL\pN _\-]+$/u'],
            'tags' => ['array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'is_example' => ['boolean'],
        ], self::MESSAGES + ['alt_text.required' => 'Describe la imagen: lo leen los lectores de pantalla y Google.']);

        return [
            'title' => trim($d['title']),
            'alt_text' => isset($d['alt_text']) && trim($d['alt_text']) !== '' ? trim($d['alt_text']) : null,
            'folder' => isset($d['folder']) && trim($d['folder']) !== '' ? trim($d['folder']) : null,
            'tags_json' => json_encode(array_values(array_unique(array_filter(array_map('trim', $d['tags'] ?? []))))),
            'is_example' => (bool) ($d['is_example'] ?? false),
        ];
    }

    /** @return array{id: string, w: ?int, h: ?int} */
    private function storeAsset(UploadedFile $file, string $ext, string $actor): array
    {
        [$w, $h] = in_array($ext, ['png', 'jpg', 'webp', 'avif'], true) ? (@getimagesize($file->getRealPath()) ?: [null, null]) : [null, null];
        $disk = config('escudo.site_assets_disk', 'local');
        $id = (string) Str::uuid7();
        $path = "site/media-{$id}.{$ext}";
        $stream = fopen($file->getRealPath(), 'rb');
        Storage::disk($disk)->writeStream($path, $stream);
        is_resource($stream) && fclose($stream);
        $this->db()->insert(
            "insert into site_assets (id, kind, disk, path, mime_type, size_bytes, width, height, original_name, created_by) values (?, 'media', ?, ?, ?, ?, ?, ?, ?, ?)",
            [$id, $disk, $path, $file->getMimeType(), $file->getSize(), $w ?: null, $h ?: null, mb_substr($file->getClientOriginalName(), 0, 200), $actor]
        );

        return ['id' => $id, 'w' => $w ?: null, 'h' => $h ?: null];
    }

    private function row(string $id): object
    {
        return $this->db()->selectOne(
            'select m.*, a.mime_type, a.original_name from site_media m join site_assets a on a.id = m.file_id where m.id = ?', [$id]
        );
    }

    private function find(string $id): object
    {
        return $this->db()->selectOne('select * from site_media where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('El archivo no existe.');
    }

    private function decode(object $r): array
    {
        $a = (array) $r;
        $a['tags'] = is_string($a['tags_json'] ?? null) ? json_decode($a['tags_json'], true) : ($a['tags_json'] ?? []);
        unset($a['tags_json'], $a['created_by'], $a['updated_by'], $a['deleted_at']);

        return $a;
    }

    private function kindLabel(string $kind): string
    {
        return ['image' => 'imagen', 'icon' => 'ícono', 'document' => 'documento', 'video' => 'video'][$kind] ?? $kind;
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', $msg, ['errors' => [$field => [$msg]]]);
    }
}
