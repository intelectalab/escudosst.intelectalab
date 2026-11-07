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
use Illuminate\Support\Str;

/**
 * Centro de ayuda (SCR-WEB-32/33 públicas, SCR-WEB-34 ayuda contextual, SCR-WEB-45 panel; RN-WEB-43 a 45).
 *  Público: GET  /public/help/categories
 *           GET  /public/help/articles?categoria=&q=          solo publicados de categorías activas
 *           GET  /public/help/articles/{slug}                  con relacionados publicados
 *           POST /public/help/articles/{slug}/feedback         «¿Te sirvió?» (inalterable, IP en hash)
 *  Sesión:  GET  /me/help?screen_code=SCR-…                    artículos de esa pantalla (solo el código viaja)
 *  Panel:   GET  /internal/site/help, CRUD de categorías y artículos, publicar/archivar (Dueño), comentarios.
 *
 * El cuerpo es texto simple: párrafos separados por una línea vacía, «## » para subtítulos y «- » para listas.
 * El sitio lo dibuja sin HTML, así nadie puede colar código.
 */
class HelpCenterController extends Controller
{
    public const ICONS = ['play', 'people', 'user', 'shield', 'file', 'book', 'help', 'building', 'clock', 'bell', 'lock', 'database', 'tag', 'check', 'star', 'globe'];

    private const SCREEN = '/^SCR-[A-Z]{2,4}-\d{2,3}$/';

    private const VIDEO = '~^https://(www\.)?(youtube\.com/watch\?v=[\w-]{6,}|youtu\.be/[\w-]{6,}|vimeo\.com/\d+)~';

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'regex' => 'Formato no válido.',
        'uuid' => 'Elige una opción de la lista.',
        'boolean' => 'Elige sí o no.',
    ];

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    private function call(string $sql, array $args): array
    {
        return json_decode($this->db()->selectOne("select {$sql} as r", $args)->r, true);
    }

    // ---------------------------------------------------------------- Público

    public function categories(): JsonResponse
    {
        return response()->json(['data' => $this->call('public_help_categories()', [])['categories'] ?? []])
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function articles(Request $request): JsonResponse
    {
        $cat = $request->query('categoria');
        $q = $request->query('q');
        $r = $this->call('public_help_articles(?, ?, null, ?)', [
            is_string($cat) && preg_match('/^[a-z0-9-]{1,80}$/', $cat) ? $cat : null,
            is_string($q) ? mb_substr(trim($q), 0, 100) : null,
            50,
        ]);

        return response()->json(['data' => $this->excerpts($r['articles'] ?? [])])->header('Cache-Control', 'public, max-age=60');
    }

    public function article(string $slug): JsonResponse
    {
        $r = $this->call('public_help_article(?)', [$slug]);
        if (($r['result'] ?? '') !== 'ok') {
            throw ApiProblem::notFound('El artículo no existe o ya no está publicado.');
        }
        unset($r['result']);

        return response()->json(['data' => $r])->header('Cache-Control', 'public, max-age=60');
    }

    public function feedback(Request $request, string $slug): JsonResponse
    {
        $d = $request->validate([
            'was_helpful' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'key' => ['nullable', 'uuid'],
        ], self::MESSAGES);
        $user = auth('sanctum')->user();
        $r = $this->call("public_help_feedback(?, ?, ?, null, ?, decode(?, 'hex'), ?)", [
            $slug, (bool) $d['was_helpful'], isset($d['comment']) ? strip_tags($d['comment']) : null, $user?->id,
            hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            $d['key'] ?? Str::uuid7()->toString(),
        ]);
        if (($r['result'] ?? '') !== 'ok') {
            throw ApiProblem::notFound('El artículo no existe o ya no está publicado.');
        }

        return response()->json(['data' => ['ok' => true]], 201);
    }

    /** Ayuda contextual: solo recibe el código de pantalla; si no hay artículos, el panel muestra el buscador. */
    public function contextual(Request $request): JsonResponse
    {
        $code = (string) $request->query('screen_code', '');
        if (! preg_match(self::SCREEN, $code)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Código de pantalla no válido.', ['errors' => ['screen_code' => ['Código de pantalla no válido.']]]);
        }
        $r = $this->call('public_help_articles(null, null, ?, 10)', [$code]);

        return response()->json(['data' => $this->excerpts($r['articles'] ?? [])]);
    }

    /** El resumen se arma con el texto limpio (sin «## » ni «- ») y cortado en una palabra. */
    private function excerpts(array $rows): array
    {
        return array_map(function ($a) {
            $plain = trim(preg_replace(['/^#+\s*/m', '/^-\s+/m', '/\s+/'], ['', '', ' '], (string) $a['excerpt']));
            $a['excerpt'] = mb_strlen($plain) > 180 ? rtrim(mb_substr($plain, 0, mb_strrpos(mb_substr($plain, 0, 180), ' ') ?: 180), ' .,;').'…' : $plain;

            return $a;
        }, $rows);
    }

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        $cats = $this->db()->select(
            "select c.id, c.name, c.slug, c.icon, c.description, c.sort_order, c.status,
                    (select count(*) from help_articles a where a.category_id = c.id and a.deleted_at is null) as articles,
                    (select count(*) from help_articles a where a.category_id = c.id and a.deleted_at is null and a.status = 'published') as published
               from help_categories c where c.deleted_at is null order by c.sort_order, c.name"
        );
        $arts = $this->db()->select(
            "select a.id, a.category_id, a.title, a.slug, a.body, a.video_url, a.screen_code, a.related_article_ids, a.status,
                    a.published_at, a.helpful_count, a.not_helpful_count, a.sort_order, a.seo_title, a.seo_description, a.updated_at,
                    (select count(*) from help_article_feedback f where f.article_id = a.id and f.comment is not null) as comments
               from help_articles a where a.deleted_at is null order by (a.status = 'draft') desc, a.sort_order, a.title"
        );
        foreach ($arts as $a) {
            $a->related_article_ids = $a->related_article_ids === '{}' ? [] : explode(',', trim($a->related_article_ids, '{}'));
        }

        return response()->json(['data' => ['categories' => $cats, 'articles' => $arts], 'icons' => self::ICONS]);
    }

    public function storeCategory(Request $request, AccountsService $accounts): JsonResponse
    {
        $row = $this->categoryData($request, null);
        $actor = $request->user()->id;
        $id = $this->db()->table('help_categories')->insertGetId($row + ['created_by' => $actor, 'updated_by' => $actor]);
        $accounts->audit($actor, 'help_category_created', 'help_categories', $id, ['slug' => $row['slug']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function updateCategory(Request $request, string $id, AccountsService $accounts): Response
    {
        $current = $this->findCategory($id);
        $row = $this->categoryData($request, $current);
        $this->db()->table('help_categories')->where('id', $id)->update($row + ['updated_by' => $request->user()->id, 'updated_at' => now()]);
        $accounts->audit($request->user()->id, 'help_category_updated', 'help_categories', $id, ['slug' => $row['slug']]);

        return response()->noContent();
    }

    public function destroyCategory(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->findCategory($id);
        if ($this->db()->selectOne('select 1 from help_articles where category_id = ? and deleted_at is null', [$id])) {
            $this->fail('id', 'La categoría tiene artículos: muévelos o elimínalos primero, o déjala oculta.');
        }
        $this->db()->update('update help_categories set deleted_at = now(), updated_by = ? where id = ?', [$request->user()->id, $id]);
        $accounts->audit($request->user()->id, 'help_category_deleted', 'help_categories', $id, []);

        return response()->noContent();
    }

    public function storeArticle(Request $request, AccountsService $accounts): JsonResponse
    {
        $row = $this->articleData($request, null);
        $actor = $request->user()->id;
        $id = $this->db()->table('help_articles')->insertGetId($row + ['created_by' => $actor, 'updated_by' => $actor]);
        $accounts->audit($actor, 'help_article_created', 'help_articles', $id, ['slug' => $row['slug']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function updateArticle(Request $request, string $id, AccountsService $accounts): Response
    {
        $current = $this->findArticle($id);
        $row = $this->articleData($request, $current);
        $this->db()->table('help_articles')->where('id', $id)->update($row + ['updated_by' => $request->user()->id, 'updated_at' => now()]);
        $accounts->audit($request->user()->id, 'help_article_updated', 'help_articles', $id, ['slug' => $row['slug']]);
        if ($current->status === 'published' && $current->slug !== $row['slug']) {
            SeoController::onPageMoved($this->db(), '/ayuda/'.$current->slug, '/ayuda/'.$row['slug'], $request->user()->id);
        }

        return response()->noContent();
    }

    public function actArticle(Request $request, string $id, string $action, AccountsService $accounts): Response
    {
        $this->findArticle($id);
        $db = $this->db();
        if ($action === 'publish') {
            $db->transaction(function () use ($db, $id, $request) {
                $db->select("select set_config('app.user_id', ?, true)", [$request->user()->id]);
                $db->select('select web_publish_help_article(?)', [$id]);
            });
        } else {
            $db->update("update help_articles set status = 'archived', updated_by = ?, updated_at = now() where id = ?", [$request->user()->id, $id]);
        }
        $accounts->audit($request->user()->id, "help_article_{$action}", 'help_articles', $id, []);

        return response()->noContent();
    }

    public function destroyArticle(Request $request, string $id, AccountsService $accounts): Response
    {
        $a = $this->findArticle($id);
        if ($a->status === 'published') {
            $this->fail('id', 'Está publicado: quítalo del sitio (archivar) antes de eliminarlo.');
        }
        $this->db()->update('update help_articles set deleted_at = now(), updated_by = ? where id = ?', [$request->user()->id, $id]);
        $this->db()->update('update help_articles set related_article_ids = array_remove(related_article_ids, ?::uuid) where ? = any(related_article_ids)', [$id, $id]);
        $accounts->audit($request->user()->id, 'help_article_deleted', 'help_articles', $id, []);

        return response()->noContent();
    }

    /** Opiniones de un artículo: votos y comentarios (sin datos de la persona). */
    public function articleFeedback(string $id): JsonResponse
    {
        $a = $this->findArticle($id);
        $rows = $this->db()->select(
            'select was_helpful, comment, created_at from help_article_feedback where article_id = ? and comment is not null order by created_at desc limit 50', [$id]
        );

        return response()->json(['data' => ['helpful' => $a->helpful_count, 'not_helpful' => $a->not_helpful_count, 'comments' => $rows]]);
    }

    // ---------------------------------------------------------------- Apoyo

    private function categoryData(Request $request, ?object $current): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'icon' => ['nullable', 'in:'.implode(',', self::ICONS)],
            'description' => ['nullable', 'string', 'max:200'],
            'status' => ['required', 'in:active,hidden'],
            'sort_order' => ['integer', 'between:0,999'],
        ], self::MESSAGES);
        $slug = ($d['slug'] ?? null) ?: Str::slug($d['name']);
        if ($this->db()->selectOne('select 1 from help_categories where slug = ? and deleted_at is null and id is distinct from ?::uuid', [$slug, $current?->id])) {
            $this->fail('slug', 'Ya hay otra categoría con esta dirección.');
        }

        return [
            'name' => trim($d['name']), 'slug' => $slug, 'icon' => $d['icon'] ?? null,
            'description' => filled($d['description'] ?? null) ? trim($d['description']) : null,
            'status' => $d['status'], 'sort_order' => $d['sort_order'] ?? 0,
        ];
    }

    private function articleData(Request $request, ?object $current): array
    {
        $d = $request->validate([
            'category_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'min:5', 'max:140'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'body' => ['required', 'string', 'min:20', 'max:20000'],
            'video_url' => ['nullable', 'string', 'max:300', 'regex:'.self::VIDEO],
            'screen_code' => ['nullable', 'string', 'regex:'.self::SCREEN],
            'related' => ['array', 'max:6'],
            'related.*' => ['uuid'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:170'],
            'sort_order' => ['integer', 'between:0,999'],
        ], self::MESSAGES + ['video_url.regex' => 'Solo videos de YouTube o Vimeo (enlace https).', 'screen_code.regex' => 'Usa el código de la pantalla, por ejemplo SCR-USR-05.']);

        $this->db()->selectOne('select 1 from help_categories where id = ? and deleted_at is null', [$d['category_id']])
            ?? $this->fail('category_id', 'Elige una categoría.');
        $slug = ($d['slug'] ?? null) ?: Str::slug($d['title']);
        if ($this->db()->selectOne('select 1 from help_articles where slug = ? and deleted_at is null and id is distinct from ?::uuid', [$slug, $current?->id])) {
            $this->fail('slug', 'Ya hay otro artículo con esta dirección.');
        }
        $related = array_values(array_unique(array_filter($d['related'] ?? [], fn ($r) => $r !== $current?->id)));
        if ($related) {
            $found = $this->db()->selectOne(
                'select count(*) as n from help_articles where id = any(?::uuid[]) and deleted_at is null', ['{'.implode(',', $related).'}']
            )->n;
            if ($found !== count($related)) {
                $this->fail('related', 'Uno de los artículos relacionados ya no existe.');
            }
        }
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'category_id' => $d['category_id'], 'title' => trim($d['title']), 'slug' => $slug,
            'body' => str_replace("\r\n", "\n", trim(strip_tags($d['body']))), 'video_url' => $clean($d['video_url'] ?? null),
            'screen_code' => $clean($d['screen_code'] ?? null), 'related_article_ids' => '{'.implode(',', $related).'}',
            'seo_title' => $clean($d['seo_title'] ?? null), 'seo_description' => $clean($d['seo_description'] ?? null),
            'sort_order' => $d['sort_order'] ?? 0,
        ];
    }

    private function findCategory(string $id): object
    {
        return $this->db()->selectOne('select * from help_categories where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('La categoría no existe.');
    }

    private function findArticle(string $id): object
    {
        return $this->db()->selectOne('select * from help_articles where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('El artículo no existe.');
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
