<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Páginas del sitio por bloques (SCR-WEB-03, 04, 08 y 20): sectores, diferenciadores y consultoras.
 *  Público: GET /public/pages?tipo=sector|product|landing   publicadas (título, resumen, slug)
 *           GET /public/pages/{slug}                         copia publicada (solo lo publicado sale a la web)
 *  Panel:   GET/POST /internal/site/pages, GET/PATCH /internal/site/pages/{id}
 *           POST /internal/site/pages/{id}/publish | archive
 *
 * Se edita un borrador (blocks); al publicar, la base copia título, bloques y SEO a published_snapshot y revisa los
 * términos bloqueados (RN-WEB-58). Editar después no cambia la web hasta volver a publicar.
 */
class SitePagesController extends Controller
{
    /** Tipos de bloque del editor y sus campos de texto (con su largo máximo). */
    public const BLOCKS = [
        'hero' => ['eyebrow' => 60, 'title' => 120, 'text' => 400],
        'text' => ['title' => 120, 'body' => 3000],
        'bullets' => ['title' => 120],
        'norms' => ['title' => 120],
        'modules' => ['title' => 120],
        'video' => ['title' => 120, 'url' => 250],
        'faq' => ['title' => 120],
        'cta' => ['title' => 120, 'text' => 300, 'button' => 20],
        'image' => ['title' => 120, 'caption' => 200],
    ];

    private const TYPES = ['sector', 'product', 'landing'];

    // ---------------------------------------------------------------- Público

    public function publicIndex(Request $request): JsonResponse
    {
        $type = $request->query('tipo');
        $rows = DB::connection('platform')->select(
            "select slug, page_type, sector_code, published_snapshot->>'title' as title, published_snapshot->>'summary' as summary
               from site_pages where status = 'published' and deleted_at is null and page_type = any(?::text[])
              order by sort_order, published_snapshot->>'title'",
            ['{'.implode(',', in_array($type, self::TYPES, true) ? [$type] : self::TYPES).'}']
        );

        return response()->json(['data' => $rows])->header('Cache-Control', 'public, max-age=60');
    }

    public function publicShow(string $slug): JsonResponse
    {
        $r = json_decode(DB::connection('platform')->selectOne('select public_site_page(?) as r', [$slug])->r, true);
        if (($r['result'] ?? '') !== 'ok') {
            throw ApiProblem::notFound('La página no existe o aún no se publica.');
        }
        unset($r['result']);
        $r['blocks'] = self::withImages($r['blocks'] ?? []);

        return response()->json(['data' => $r])->header('Cache-Control', 'public, max-age=60');
    }

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        $rows = DB::connection('platform')->select(
            "select id, slug, page_type, sector_code, title, summary, status, published_at, updated_at, sort_order,
                    (status = 'published' and published_snapshot is not null
                      and (published_snapshot->'blocks' is distinct from blocks or published_snapshot->>'title' is distinct from title)) as has_changes
               from site_pages where deleted_at is null and page_type = any(?::text[])
              order by page_type, sort_order, title",
            ['{'.implode(',', self::TYPES).'}']
        );

        return response()->json(['data' => $rows]);
    }

    public function show(string $id): JsonResponse
    {
        $p = $this->find($id);
        $row = (array) $p;
        foreach (['blocks', 'published_snapshot', 'structured_data'] as $k) {
            $row[$k] = $row[$k] === null ? null : json_decode($row[$k], true);
        }
        $row['blocks'] = self::withImages($row['blocks'] ?? []);

        return response()->json(['data' => $row]);
    }

    /** Agrega a los bloques «image» el archivo y el texto alternativo actuales de su medio. */
    public static function withImages(array $blocks): array
    {
        $ids = array_values(array_filter(array_map(fn ($b) => ($b['type'] ?? '') === 'image' ? ($b['media_id'] ?? null) : null, $blocks)));
        if (! $ids) {
            return $blocks;
        }
        $media = collect(DB::connection('platform')->select(
            'select id, file_id, alt_text, width, height from site_media where id = any(?::uuid[]) and deleted_at is null', ['{'.implode(',', $ids).'}']
        ))->keyBy('id');

        return array_values(array_filter(array_map(function ($b) use ($media) {
            if (($b['type'] ?? '') !== 'image') {
                return $b;
            }
            $m = $media[$b['media_id'] ?? ''] ?? null;

            return $m ? $b + ['file_id' => $m->file_id, 'alt' => $m->alt_text, 'width' => $m->width, 'height' => $m->height] : null;
        }, $blocks)));
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $data = $this->validated($request, true);
        $actor = $request->user()->id;
        $id = $this->save(null, $data, $actor);
        $accounts->audit($actor, 'site_page_created', 'site_pages', $id, ['slug' => $data['slug']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $p = $this->find($id);
        $data = $this->validated($request, false);
        if ((int) $data['version'] !== (int) $p->version) {
            throw new ApiProblem(409, 'conflict', 'Alguien más cambió la página', 'Otra persona guardó cambios mientras editabas. Recarga para ver la última versión.');
        }
        $db = DB::connection('platform');
        $db->transaction(function () use ($db, $id, $data, $p, $request) {
            $this->save($id, $data, $request->user()->id);
            // Si la página ya está publicada y cambia su dirección, la anterior redirige (301) a la nueva.
            if ($p->status === 'published' && $p->slug !== $data['slug']) {
                SeoController::onPageMoved($db, '/'.$p->slug, '/'.$data['slug'], $request->user()->id);
            }
        });
        $accounts->audit($request->user()->id, 'site_page_updated', 'site_pages', $id, ['slug' => $data['slug'], 'previous_slug' => $p->slug !== $data['slug'] ? $p->slug : null]);

        // La versión la puede subir también la base (trigger): se devuelve la real para el próximo guardado.
        return response()->json(['data' => ['version' => (int) DB::connection('platform')->selectOne('select version from site_pages where id = ?', [$id])->version]]);
    }

    public function publish(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->find($id);
        $this->asUser($request->user()->id, function ($db) use ($id) {
            try {
                $db->select('select web_publish_page(?)', [$id]);
            } catch (QueryException $e) {
                if (preg_match('/término bloqueado «(.+?)»/u', $e->getMessage(), $m)) {
                    $msg = "No se puede publicar: contiene «{$m[1]}», una afirmación que EscudoSST no puede respaldar (lineamientos de contenido).";
                    throw new ApiProblem(422, 'validation_failed', 'Revisa el contenido', $msg, ['errors' => ['blocks' => [$msg]]]);
                }
                throw $e;
            }
        });
        $accounts->audit($request->user()->id, 'site_page_published', 'site_pages', $id, []);

        return response()->noContent();
    }

    public function archive(Request $request, string $id, AccountsService $accounts): Response
    {
        $p = $this->find($id);
        if ($p->status !== 'published') {
            throw new ApiProblem(409, 'conflict', 'No se puede', 'Solo se quita del sitio una página publicada.');
        }
        $this->asUser($request->user()->id, fn ($db) => $db->select('select web_archive_page(?)', [$id]));
        $accounts->audit($request->user()->id, 'site_page_archived', 'site_pages', $id, []);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Apoyo

    private function find(string $id): object
    {
        return DB::connection('platform')->selectOne('select * from site_pages where id = ? and deleted_at is null', [$id])
            ?? throw ApiProblem::notFound('La página no existe.');
    }

    private function asUser(string $user, \Closure $fn): mixed
    {
        $db = DB::connection('platform');

        return $db->transaction(function () use ($db, $user, $fn) {
            $db->select("select set_config('app.user_id', ?, true)", [$user]);

            return $fn($db);
        });
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'page_type' => [$creating ? 'required' : 'sometimes', 'in:'.implode(',', self::TYPES)],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*(\/[a-z0-9]+(-[a-z0-9]+)*)*$/'],
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'summary' => ['nullable', 'string', 'max:300'],
            'blocks' => ['present', 'array', 'max:20'],
            'blocks.*.type' => ['required', 'in:'.implode(',', array_keys(self::BLOCKS))],
            'blocks.*.items' => ['sometimes', 'array', 'max:20'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:170'],
            'noindex' => ['boolean'],
            'whatsapp_message' => ['nullable', 'string', 'max:300'],
            'sector_code' => ['nullable', 'in:construction,mining,agroindustry,manufacturing,retail,services,office,health,education,transport,energy,other'],
            'sort_order' => ['integer', 'between:0,999'],
            'version' => [$creating ? 'nullable' : 'required', 'integer'],
        ], [
            'slug.regex' => 'Usa solo minúsculas, números y guiones (por ejemplo: sectores/construccion).',
            'meta_title.max' => 'Máximo 70 caracteres: Google corta el resto.',
            'meta_description.max' => 'Máximo 170 caracteres: Google corta el resto.',
            'blocks.max' => 'Máximo 20 bloques por página.',
        ]);
        // validate() solo devuelve las claves con regla; los campos de cada bloque se limpian aparte (cleanBlocks).
        $data['blocks'] = $this->cleanBlocks((array) $request->input('blocks', []));

        return $data;
    }

    /** Deja solo los campos conocidos de cada bloque, recortados; valida enlaces de video y módulos. */
    private function cleanBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $i => $b) {
            $type = $b['type'];
            $clean = ['type' => $type];
            foreach (self::BLOCKS[$type] as $field => $max) {
                $v = trim((string) ($b[$field] ?? ''));
                if (mb_strlen($v) > $max) {
                    $this->fail("blocks.{$i}.{$field}", "Máximo {$max} caracteres.");
                }
                $clean[$field] = $v;
            }
            if (in_array($type, ['bullets', 'norms'], true)) {
                $clean['items'] = array_values(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 200), $b['items'] ?? [])));
            }
            if ($type === 'modules') {
                $codes = array_values(array_unique(array_filter(array_map(fn ($x) => strtoupper(trim((string) $x)), $b['items'] ?? []))));
                $known = array_map(fn ($r) => trim($r->code), DB::select('select code from modules where deleted_at is null'));
                if ($bad = array_diff($codes, $known)) {
                    $this->fail("blocks.{$i}.items", 'Módulos desconocidos: '.implode(', ', $bad).'.');
                }
                $clean['items'] = $codes;
            }
            if ($type === 'faq') {
                $clean['items'] = array_values(array_filter(array_map(fn ($x) => [
                    'q' => mb_substr(trim((string) ($x['q'] ?? '')), 0, 200), 'a' => mb_substr(trim((string) ($x['a'] ?? '')), 0, 1000),
                ], $b['items'] ?? []), fn ($x) => $x['q'] !== '' && $x['a'] !== ''));
            }
            if ($type === 'video' && $clean['url'] !== ''
                && ! preg_match('~^https://(www\.)?(youtube\.com/watch\?v=[\w-]{6,}|youtu\.be/[\w-]{6,}|vimeo\.com/\d+)~', $clean['url'])) {
                $this->fail("blocks.{$i}.url", 'Solo videos de YouTube o Vimeo (enlace https).');
            }
            if ($type === 'image') {
                // Solo se guarda el medio de la biblioteca; el archivo y su texto alternativo se leen al mostrar la
                // página, así reemplazar la imagen en la biblioteca la cambia también aquí.
                $media = (string) ($b['media_id'] ?? '');
                if (! \Illuminate\Support\Str::isUuid($media) || ! DB::connection('platform')->selectOne("select 1 from site_media where id = ? and kind in ('image', 'icon') and deleted_at is null", [$media])) {
                    $this->fail("blocks.{$i}.media_id", 'Elige una imagen de la biblioteca de medios.');
                }
                $clean['media_id'] = $media;
            }
            if ($type === 'cta' && ! in_array($clean['button'], ['trial', 'demo', 'consultancy'], true)) {
                $clean['button'] = 'demo';
            }
            $out[] = $clean;
        }

        return $out;
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los bloques', $msg, ['errors' => [$field => [$msg]]]);
    }

    private function save(?string $id, array $d, string $actor): string
    {
        $db = DB::connection('platform');
        $row = [
            'slug' => $d['slug'],
            'title' => trim($d['title']),
            'summary' => trim((string) ($d['summary'] ?? '')) ?: null,
            'blocks' => json_encode($d['blocks'], JSON_UNESCAPED_UNICODE),
            'meta_title' => trim((string) ($d['meta_title'] ?? '')) ?: null,
            'meta_description' => trim((string) ($d['meta_description'] ?? '')) ?: null,
            'noindex' => (bool) ($d['noindex'] ?? false),
            'whatsapp_message' => trim((string) ($d['whatsapp_message'] ?? '')) ?: null,
            'sector_code' => $d['sector_code'] ?? null,
            'sort_order' => (int) ($d['sort_order'] ?? 0),
        ];
        $dup = $db->selectOne('select 1 from site_pages where slug = ? and deleted_at is null and id is distinct from ?', [$row['slug'], $id]);
        if ($dup) {
            $this->fail('slug', 'Ya existe otra página con esa dirección.');
        }
        if ($id) {
            $db->update(
                'update site_pages set slug = ?, title = ?, summary = ?, blocks = ?::jsonb, meta_title = ?, meta_description = ?, noindex = ?,
                        whatsapp_message = ?, sector_code = ?, sort_order = ?, updated_by = ? where id = ?   -- la versión la sube el trigger',
                [...array_values($row), $actor, $id]
            );

            return $id;
        }

        return $db->selectOne(
            'insert into site_pages (slug, page_type, title, summary, blocks, meta_title, meta_description, noindex, whatsapp_message, sector_code, sort_order, created_by)
             values (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?) returning id',
            [$row['slug'], $d['page_type'], $row['title'], $row['summary'], $row['blocks'], $row['meta_title'], $row['meta_description'],
                $row['noindex'], $row['whatsapp_message'], $row['sector_code'], $row['sort_order'], $actor]
        )->id;
    }
}
