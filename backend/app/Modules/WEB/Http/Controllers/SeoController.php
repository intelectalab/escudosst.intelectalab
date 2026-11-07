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

/**
 * SEO técnico (SCR-WEB-27, RN-WEB-07): redirecciones 301/302, datos para el sitemap y robots, y códigos de Google.
 *  Público: GET /public/site/sitemap              rutas publicadas e indexables (páginas, políticas y casos)
 *           GET /public/site/redirects            redirecciones activas (el sitio las aplica antes de mostrar la página)
 *           GET /public/site/redirects/resolve    ?path=  destino de una ruta y suma la visita
 *  Panel:   GET/POST /internal/site/redirects, PATCH/DELETE .../{id}
 *           GET/PATCH /internal/site/seo           verificación de Search Console e ID de Google Analytics
 *
 * Reglas: el origen es una ruta del sitio («/algo»); el destino, otra ruta o una dirección https. No se permiten
 * bucles (A → B → A) ni redirigir rutas del panel, la API o el acceso. Borrar = baja lógica.
 */
class SeoController extends Controller
{
    /** Rutas que nunca se redirigen: panel, aplicación, acceso y archivos internos. */
    private const PROTECTED = ['/plataforma', '/api', '/_next', '/login', '/invitacion', '/recuperar', '/restablecer', '/confirmar-correo', '/inicio', '/m/', '/cuenta', '/soporte', '/select-company'];

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'max' => 'Es muy largo.',
        'in' => 'Elige una opción de la lista.',
        'regex' => 'El formato no es válido.',
        'source_path.regex' => 'Debe empezar con / y no llevar espacios. Ej.: /precios',
        'target_url.regex' => 'Usa una ruta del sitio (/planes) o una dirección que empiece con https://',
        'ga4.regex' => 'El ID de Google Analytics empieza con G-, por ejemplo G-ABC123XYZ.',
        'search_console_verification.regex' => 'Pega solo el código (letras, números, - y _), no la etiqueta completa.',
    ];

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    // ---------------------------------------------------------------- Público

    public function sitemap(): JsonResponse
    {
        $rows = array_map(fn ($r) => ['path' => $r->path, 'kind' => $r->kind, 'last_modified' => $r->last_modified], $this->db()->select('select * from public_sitemap()'));
        foreach ($this->db()->select(
            "select slug, greatest(published_at, updated_at) as m from success_stories
              where status = 'published' and deleted_at is null and (consent_file_id is not null or is_example)"
        ) as $s) {
            $rows[] = ['path' => "/casos/{$s->slug}", 'kind' => 'case', 'last_modified' => $s->m];
        }
        foreach ($this->db()->select(
            "select a.slug, greatest(a.published_at, a.updated_at) as m from help_articles a
               join help_categories c on c.id = a.category_id and c.status = 'active' and c.deleted_at is null
              where a.status = 'published' and a.deleted_at is null"
        ) as $h) {
            $rows[] = ['path' => "/ayuda/{$h->slug}", 'kind' => 'help', 'last_modified' => $h->m];
        }
        foreach ($this->db()->select("select slug, sent_at from newsletter_issues where status = 'sent' and deleted_at is null") as $n) {
            $rows[] = ['path' => "/boletin/{$n->slug}", 'kind' => 'newsletter', 'last_modified' => $n->sent_at];
        }
        $s = $this->db()->selectOne('select primary_domain from site_settings where singleton');
        $noindex = trim((string) $this->db()->selectOne('select public_noindex_paths()::text as p')->p, '{}');

        return response()->json(['data' => [
            'domain' => $s?->primary_domain,
            'entries' => $rows,
            'noindex' => $noindex === '' ? [] : array_map(fn ($p) => trim($p, '"'), explode(',', $noindex)),
        ]])->header('Cache-Control', 'public, max-age=300');
    }

    public function activeRedirects(): JsonResponse
    {
        $rows = $this->db()->select('select source_path, target_url, status_code from site_redirects where is_active and deleted_at is null');

        return response()->json(['data' => $rows])->header('Cache-Control', 'public, max-age=60');
    }

    public function resolve(Request $request): JsonResponse
    {
        $path = (string) $request->query('path', '');
        if ($path === '' || ! str_starts_with($path, '/')) {
            throw ApiProblem::notFound('No hay redirección.');
        }
        $r = json_decode($this->db()->selectOne('select public_resolve_redirect(?) as r', [$path])->r, true);
        if (($r['result'] ?? '') !== 'ok') {
            throw ApiProblem::notFound('No hay redirección.');
        }

        return response()->json(['data' => ['target_url' => $r['target_url'], 'status_code' => $r['status_code']]]);
    }

    // ---------------------------------------------------------------- Panel: redirecciones

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->db()->select(
            'select id, source_path, target_url, status_code, is_active, hit_count, last_hit_at, notes, created_at
               from site_redirects where deleted_at is null order by is_active desc, created_at desc'
        )]);
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $this->validated($request, null);
        $actor = $request->user()->id;
        $id = $this->db()->table('site_redirects')->insertGetId($d + ['created_by' => $actor, 'updated_by' => $actor]);
        $accounts->audit($actor, 'site_redirect_created', 'site_redirects', $id, ['from' => $d['source_path'], 'to' => $d['target_url']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->find($id);
        $d = $this->validated($request, $id);
        $this->db()->table('site_redirects')->where('id', $id)->update($d + ['updated_by' => $request->user()->id]);
        $accounts->audit($request->user()->id, 'site_redirect_updated', 'site_redirects', $id, ['from' => $d['source_path'], 'to' => $d['target_url'], 'active' => $d['is_active']]);

        return response()->noContent();
    }

    public function destroy(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->find($id);
        $this->db()->update('update site_redirects set deleted_at = now(), is_active = false, updated_by = ? where id = ?', [$request->user()->id, $id]);
        $accounts->audit($request->user()->id, 'site_redirect_deleted', 'site_redirects', $id, []);

        return response()->noContent();
    }

    /**
     * Redirección automática al cambiar la dirección de una página publicada (criterio 3 de M-WEB): la ruta anterior
     * responde 301 a la nueva. Las redirecciones que apuntaban a la ruta anterior pasan a apuntar a la nueva (sin cadenas)
     * y se desactiva la que saliera de la ruta nueva (evita el bucle).
     */
    public static function onPageMoved(ConnectionInterface $db, string $from, string $to, string $actor): void
    {
        if ($from === $to) {
            return;
        }
        $db->update('update site_redirects set target_url = ?, updated_by = ? where target_url = ? and is_active and deleted_at is null', [$to, $actor, $from]);
        $db->update('update site_redirects set is_active = false, updated_by = ? where source_path = ? and is_active and deleted_at is null', [$actor, $to]);
        $db->update('update site_redirects set is_active = false, updated_by = ? where source_path = ? and is_active and deleted_at is null', [$actor, $from]);
        $db->insert(
            "insert into site_redirects (source_path, target_url, status_code, notes, created_by, updated_by) values (?, ?, 301, 'Creada sola al cambiar la dirección de la página', ?, ?)",
            [$from, $to, $actor, $actor]
        );
    }

    /**
     * Todas las páginas del sitio y si salen en Google, con el motivo cuando no (para el panel). Es la misma regla del
     * sitemap: solo lo publicado y sin «Ocultar de Google».
     */
    public function pages(): JsonResponse
    {
        $db = $this->db();
        $out = [];
        foreach (SiteMenuController::FIXED as $path => $label) {
            $out[] = ['path' => $path, 'title' => $label, 'group' => 'Páginas fijas del sitio', 'in_google' => true, 'reason' => null, 'edit' => null];
        }
        $groups = ['sector' => 'Páginas por sector', 'product' => 'Diferenciadores', 'landing' => 'Otras páginas', 'campaign' => 'Campañas'];
        foreach ($db->select("select id, slug, title, page_type, status, noindex from site_pages where deleted_at is null and page_type <> 'home' order by page_type, title") as $p) {
            $reason = match (true) {
                $p->status === 'draft' => 'Borrador: aún no se publica',
                $p->status === 'archived' => 'Quitada del sitio',
                $p->page_type === 'campaign' => 'Las campañas no van a Google',
                (bool) $p->noindex => 'Marcada «Ocultar de Google»',
                default => null,
            };
            $out[] = ['path' => '/'.$p->slug, 'title' => $p->title, 'group' => $groups[$p->page_type] ?? 'Otras páginas', 'in_google' => $reason === null, 'reason' => $reason, 'edit' => "/plataforma/sitio/paginas/{$p->id}"];
        }
        foreach ($db->select('select id, slug, title, status, is_example, consent_file_id from success_stories where deleted_at is null order by title') as $c) {
            $reason = match (true) {
                $c->status !== 'published' => ['draft' => 'Borrador: aún no se publica', 'approved' => 'Aprobado, falta publicarlo', 'hidden' => 'Oculto'][$c->status] ?? 'No publicado',
                ! $c->consent_file_id && ! $c->is_example => 'Sin autorización del cliente',
                default => null,
            };
            $out[] = ['path' => "/casos/{$c->slug}", 'title' => $c->title, 'group' => 'Casos de éxito', 'in_google' => $reason === null, 'reason' => $reason, 'edit' => "/plataforma/sitio/casos/{$c->id}"];
        }
        foreach ($db->select(
            "select a.slug, a.title, a.status, c.status as cat from help_articles a join help_categories c on c.id = a.category_id and c.deleted_at is null
              where a.deleted_at is null order by a.title"
        ) as $h) {
            $reason = match (true) {
                $h->status !== 'published' => $h->status === 'archived' ? 'Quitado del sitio' : 'Borrador: aún no se publica',
                $h->cat !== 'active' => 'Su categoría está oculta',
                default => null,
            };
            $out[] = ['path' => "/ayuda/{$h->slug}", 'title' => $h->title, 'group' => 'Centro de ayuda', 'in_google' => $reason === null, 'reason' => $reason, 'edit' => '/plataforma/sitio/ayuda'];
        }
        foreach ($db->select("select d.slug, d.title, (v.status = 'published') as pub from legal_documents d left join legal_document_versions v on v.id = d.current_version_id where d.deleted_at is null order by d.title") as $l) {
            $out[] = ['path' => "/legal/{$l->slug}", 'title' => $l->title, 'group' => 'Políticas', 'in_google' => (bool) $l->pub, 'reason' => $l->pub ? null : 'Borrador: aún no se publica', 'edit' => '/plataforma/sitio/politicas'];
        }

        return response()->json(['data' => $out]);
    }

    // ---------------------------------------------------------------- Panel: Google

    public function seo(): JsonResponse
    {
        $s = $this->db()->selectOne('select primary_domain, search_console_verification, analytics_ids from site_settings where singleton');
        $ids = json_decode((string) ($s->analytics_ids ?? '{}'), true) ?: [];

        return response()->json(['data' => [
            'primary_domain' => $s->primary_domain,
            'search_console_verification' => $s->search_console_verification,
            'ga4' => $ids['ga4'] ?? null,
        ]]);
    }

    public function updateSeo(Request $request, AccountsService $accounts): Response
    {
        $d = $request->validate([
            'search_console_verification' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'ga4' => ['nullable', 'string', 'max:30', 'regex:/^G-[A-Z0-9]{4,20}$/'],
        ], self::MESSAGES);
        $s = $this->db()->selectOne('select analytics_ids from site_settings where singleton');
        $ids = json_decode((string) ($s->analytics_ids ?? '{}'), true) ?: [];
        $ids['ga4'] = ($d['ga4'] ?? null) ?: null;
        $this->db()->update(
            'update site_settings set search_console_verification = ?, analytics_ids = ?::jsonb, updated_by = ? where singleton',
            [($d['search_console_verification'] ?? null) ?: null, json_encode(array_filter($ids)), $request->user()->id]
        );
        $accounts->audit($request->user()->id, 'site_seo_updated', 'site_settings', null, ['fields' => array_keys($d)]);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Apoyo

    private function validated(Request $request, ?string $id): array
    {
        $d = $request->validate([
            'source_path' => ['required', 'string', 'max:300', 'regex:/^\/[^\s]*$/'],
            'target_url' => ['required', 'string', 'max:500', 'regex:/^(https:\/\/[^\s]+|\/[^\s]*)$/'],
            'status_code' => ['required', 'in:301,302'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:300'],
        ], self::MESSAGES);

        $source = $this->normalize($d['source_path']);
        $target = str_starts_with($d['target_url'], '/') ? $this->normalize($d['target_url']) : $d['target_url'];
        $active = (bool) ($d['is_active'] ?? true);

        foreach (self::PROTECTED as $p) {
            if ($source === rtrim($p, '/') || str_starts_with($source, rtrim($p, '/').'/')) {
                $this->fail('source_path', 'Esa ruta es del panel o del acceso y no se puede redirigir.');
            }
        }
        if ($source === '/') {
            $this->fail('source_path', 'La portada no se puede redirigir.');
        }
        if ($source === $target) {
            $this->fail('target_url', 'El destino no puede ser el mismo origen.');
        }
        if ($active && $this->db()->selectOne('select 1 from site_redirects where source_path = ? and is_active and deleted_at is null and id is distinct from ?::uuid', [$source, $id])) {
            $this->fail('source_path', 'Ya hay otra redirección activa desde esa ruta.');
        }
        if ($active && $this->createsLoop($source, $target, $id)) {
            $this->fail('target_url', 'Esto armaría un bucle: el destino termina volviendo a la ruta de origen.');
        }

        return ['source_path' => $source, 'target_url' => $target, 'status_code' => (int) $d['status_code'], 'is_active' => $active, 'notes' => ($d['notes'] ?? null) ?: null];
    }

    /** Sigue la cadena de redirecciones activas desde el destino; si vuelve al origen, es un bucle. */
    private function createsLoop(string $source, string $target, ?string $id): bool
    {
        $map = [];
        foreach ($this->db()->select('select source_path, target_url from site_redirects where is_active and deleted_at is null and id is distinct from ?::uuid', [$id]) as $r) {
            $map[$r->source_path] = $r->target_url;
        }
        $seen = [];
        for ($at = $target; isset($map[$at]) && ! isset($seen[$at]); $at = $map[$at]) {
            $seen[$at] = true;
            if ($map[$at] === $source) {
                return true;
            }
        }

        return $at === $source;
    }

    private function normalize(string $path): string
    {
        $p = '/'.ltrim(strtolower(trim($path)), '/');

        return strlen($p) > 1 ? rtrim($p, '/') : $p;
    }

    private function find(string $id): object
    {
        return $this->db()->selectOne('select * from site_redirects where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('La redirección no existe.');
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
