<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * GET /public/blog-posts — M-WEB (bloque «últimos artículos del blog»): con «show_latest_blog_posts» activo y el enlace del blog
 * de WordPress configurado, trae los últimos latest_blog_posts_count artículos de su API pública (título, enlace, fecha y resumen
 * en texto). Se guarda una hora; si el blog no responde, la portada simplemente no muestra el bloque.
 */
class BlogPostsController extends Controller
{
    public function index(): JsonResponse
    {
        $row = DB::connection('platform')->selectOne('select blog_url, show_latest_blog_posts, latest_blog_posts_count from site_settings where deleted_at is null limit 1');
        $s = (array) ($row ?? []);
        $url = is_string($s['blog_url'] ?? null) ? rtrim($s['blog_url'], '/') : null;
        if (! ($s['show_latest_blog_posts'] ?? false) || ! $url || ! str_starts_with($url, 'https://')) {
            return response()->json(['data' => []]);
        }
        $n = max(1, min(12, (int) ($s['latest_blog_posts_count'] ?? 3)));
        $posts = Cache::remember('web.blog_posts:'.md5($url.$n), now()->addHour(), function () use ($url, $n) {
            try {
                $r = Http::timeout(5)->acceptJson()->get($url.'/wp-json/wp/v2/posts', ['per_page' => $n, '_fields' => 'title,link,date,excerpt']);
                if (! $r->ok() || ! is_array($r->json())) {
                    return [];
                }

                return array_values(array_filter(array_map(fn ($p) => [
                    'title' => html_entity_decode(strip_tags((string) ($p['title']['rendered'] ?? '')), ENT_QUOTES, 'UTF-8'),
                    'link' => (string) ($p['link'] ?? ''),
                    'date' => (string) ($p['date'] ?? ''),
                    'excerpt' => mb_substr(trim(html_entity_decode(strip_tags((string) ($p['excerpt']['rendered'] ?? '')), ENT_QUOTES, 'UTF-8')), 0, 220),
                ], $r->json()), fn ($p) => $p['title'] !== '' && str_starts_with($p['link'], 'https://')));
            } catch (\Throwable) {
                return [];
            }
        });

        return response()->json(['data' => $posts]);
    }
}
