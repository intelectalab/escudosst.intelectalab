<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Http\Controllers\Concerns\SitePanelHelpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Novedades del producto (SCR-WEB-75 /novedades, SCR-WEB-76 en la aplicación, SCR-WEB-77 panel).
 *  Público:  GET /public/product-updates, GET /public/product-updates/{slug}
 *  App:      GET /me/product-updates   (las publicadas con «mostrar en la app»; lo leído lo guarda el navegador)
 *  Panel:    GET/POST /internal/site/product-updates, PUT .../{id}, POST .../{id}/{publish|archive} (publicar: Dueño)
 *
 * «Avisar a los suscriptores del boletín»: al publicar se arma una edición del boletín con la novedad y se envía con
 * el mismo proceso del boletín (escudo:newsletter:send), solo a suscriptores confirmados.
 */
class ProductUpdatesController extends Controller
{
    use SitePanelHelpers;

    public const CATEGORIES = ['new' => 'Nuevo', 'improvement' => 'Mejora', 'fix' => 'Corrección'];

    public function publicIndex(): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_product_updates(50, false) as r')->r, true);

        return response()->json(['data' => $r['updates'], 'categories' => self::CATEGORIES])->header('Cache-Control', 'public, max-age=60');
    }

    public function publicShow(string $slug): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_product_update(?) as r', [$slug])->r, true);
        if (($r['result'] ?? '') !== 'ok') {
            throw ApiProblem::notFound('La novedad no existe.');
        }
        unset($r['result']);

        return response()->json(['data' => $r])->header('Cache-Control', 'public, max-age=60');
    }

    public function inApp(): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_product_updates(20, true) as r')->r, true);

        return response()->json(['data' => $r['updates'], 'categories' => self::CATEGORIES]);
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->db()->select("select * from product_updates where deleted_at is null order by (status = 'draft') desc, coalesce(published_at, created_at) desc"),
            'categories' => self::CATEGORIES,
            'modules' => $this->db()->select('select trim(code) as code, name from modules where deleted_at is null order by name'),
        ]);
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $row = $this->validated($request, null);
        $actor = $request->user()->id;
        $id = $this->db()->table('product_updates')->insertGetId($row + ['created_by' => $actor, 'updated_by' => $actor]);
        $accounts->audit($actor, 'product_update_created', 'product_updates', $id, ['title' => $row['title']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): Response
    {
        $current = $this->row('product_updates', $id, 'La novedad no existe.');
        $row = $this->validated($request, $current);
        if ($current->status === 'published') {
            $this->assertAllowedText('body', $row['title'], $row['body']);
        }
        $this->db()->table('product_updates')->where('id', $id)->update($row + ['updated_by' => $request->user()->id, 'updated_at' => now()]);
        $accounts->audit($request->user()->id, 'product_update_updated', 'product_updates', $id, ['title' => $row['title']]);

        return response()->noContent();
    }

    public function act(Request $request, string $id, string $action, AccountsService $accounts): Response
    {
        $u = $this->row('product_updates', $id, 'La novedad no existe.');
        $actor = $request->user()->id;
        if ($action === 'archive') {
            $this->db()->update("update product_updates set status = 'archived', updated_by = ?, updated_at = now() where id = ?", [$actor, $id]);
            $accounts->audit($actor, 'product_update_archived', 'product_updates', $id, []);

            return response()->noContent();
        }
        if ($request->user()->platform_role !== 'owner') {
            throw ApiProblem::forbidden('Solo el Dueño de la plataforma publica novedades.');
        }
        $this->assertAllowedText('body', $u->title, $u->body);
        $first = $u->published_at === null;
        $this->db()->transaction(function () use ($u, $actor, $first) {
            $this->db()->update("update product_updates set status = 'published', published_at = coalesce(published_at, now()), updated_by = ?, updated_at = now() where id = ?", [$actor, $u->id]);
            // Aviso al boletín solo la primera vez que se publica (republicar no reenvía).
            if ($first && $u->notify_subscribers) {
                $link = rtrim((string) config('escudo.frontend_url'), '/')."/novedades/{$u->slug}";
                $this->db()->insert(
                    "insert into newsletter_issues (subject, slug, preheader, body, topics_json, status, scheduled_at, created_by)
                     values (?, ?, ?, ?, '[]'::jsonb, 'scheduled', now(), ?)",
                    [
                        'Novedad: '.$u->title, 'novedad-'.$u->slug.'-'.now()->format('ymdHis'), mb_substr(strip_tags($u->body), 0, 120),
                        $u->body."\n\nLéela completa: {$link}", $actor,
                    ]
                );
            }
        });
        $accounts->audit($actor, 'product_update_published', 'product_updates', $id, ['title' => $u->title, 'notified' => $first && $u->notify_subscribers]);

        return response()->noContent();
    }

    private function validated(Request $request, ?object $current): array
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'body' => ['required', 'string', 'min:20', 'max:5000'],
            'category' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'modules' => ['array', 'max:20'],
            'modules.*' => ['string', 'max:10'],
            'notify_subscribers' => ['boolean'],
            'show_in_app' => ['boolean'],
        ], self::MESSAGES);
        $slug = ($d['slug'] ?? null) ?: Str::slug($d['title']);
        if ($this->db()->selectOne('select 1 from product_updates where slug = ? and deleted_at is null and id is distinct from ?::uuid', [$slug, $current?->id])) {
            $this->fail('slug', 'Ya hay otra novedad con esta dirección.');
        }
        $known = array_map(fn ($r) => trim($r->code), $this->db()->select('select code from modules where deleted_at is null'));
        $mods = array_values(array_intersect(array_map(fn ($m) => Str::upper(trim($m)), $d['modules'] ?? []), $known));
        $this->assertAllowedText('body', $d['title'], $d['body']);

        return [
            'title' => trim($d['title']), 'slug' => $slug, 'body' => trim($d['body']), 'category' => $d['category'],
            'modules_json' => json_encode($mods), 'notify_subscribers' => (bool) ($d['notify_subscribers'] ?? false),
            'show_in_app' => (bool) ($d['show_in_app'] ?? true),
        ];
    }
}
