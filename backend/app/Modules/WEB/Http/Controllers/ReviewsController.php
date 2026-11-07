<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Http\Controllers\Concerns\SitePanelHelpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Reseñas de Google (SCR-WEB-80 bloque público y SCR-WEB-81 panel).
 *  Público: GET /public/reviews   copia vigente (si no hay, el bloque no se muestra)
 *  Panel:   GET /internal/site/reviews, PUT /internal/site/reviews, POST /internal/site/reviews/refresh (Dueño)
 *
 * Se leen por la API oficial de Google Places (perfil de Google Business): sin editar el texto ni elegir a mano
 * cuáles mostrar; solo se filtra por calificación mínima y cantidad. La llave va en el servidor (GOOGLE_PLACES_API_KEY);
 * la base guarda solo la referencia «env://GOOGLE_PLACES_API_KEY». Se renuevan solas al vencer la copia (cada hora se revisa).
 */
class ReviewsController extends Controller
{
    use SitePanelHelpers;

    private const SECRET_REF = 'env://GOOGLE_PLACES_API_KEY';

    public function publicIndex(): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_reviews() as r')->r, true);

        return response()->json(['data' => ['attribution' => $r['attribution'], 'reviews' => $r['reviews']]])->header('Cache-Control', 'public, max-age=300');
    }

    public function show(): JsonResponse
    {
        $s = $this->source();

        return response()->json(['data' => [
            'source' => $s,
            'key_ready' => (bool) config('escudo.google.places_api_key'),
            'snapshots' => $s ? $this->db()->select(
                'select author_name, rating, body, review_published_at, expires_at from review_snapshots where review_source_id = ? and deleted_at is null order by review_published_at desc nulls last', [$s->id]
            ) : [],
        ]]);
    }

    public function save(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate([
            'place_id' => ['required', 'string', 'min:10', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/'],
            'min_rating' => ['required', 'integer', 'between:1,5'],
            'max_to_show' => ['required', 'integer', 'between:1,50'],
            'cache_ttl_hours' => ['required', 'integer', 'between:1,720'],
            'active' => ['boolean'],
        ], self::MESSAGES + ['place_id.regex' => 'El ID del lugar tiene solo letras, números, guiones y guion bajo (empieza con «ChIJ»).']);
        $active = (bool) ($d['active'] ?? false);
        if ($active && ! config('escudo.google.places_api_key')) {
            $this->fail('active', 'Falta la llave de la API de Google en el servidor: pídesela al equipo técnico antes de activar.');
        }
        $row = [
            'place_id' => $d['place_id'], 'api_secret_ref' => self::SECRET_REF, 'min_rating' => $d['min_rating'], 'max_to_show' => $d['max_to_show'],
            'cache_ttl_hours' => $d['cache_ttl_hours'], 'status' => $active ? 'active' : 'paused', 'updated_by' => $request->user()->id, 'updated_at' => now(),
        ];
        $s = $this->source();
        if ($s) {
            if ($s->place_id !== $d['place_id']) {
                $this->db()->delete('delete from review_snapshots where review_source_id = ?', [$s->id]);   // otro perfil: la copia anterior ya no aplica
            }
            $this->db()->table('review_sources')->where('id', $s->id)->update($row);
        } else {
            $this->db()->table('review_sources')->insert($row + ['provider' => 'google', 'created_by' => $request->user()->id]);
        }
        $accounts->audit($request->user()->id, 'review_source_saved', 'review_sources', $s->id ?? null, ['place_id' => $d['place_id'], 'status' => $row['status']]);
        $fetched = $active ? $this->refreshSource($this->source()) : null;

        return response()->json(['data' => ['fetched' => $fetched]]);
    }

    public function refresh(Request $request, AccountsService $accounts): JsonResponse
    {
        $s = $this->source() ?? $this->fail('place_id', 'Primero guarda el ID del lugar.');
        $n = $this->refreshSource($s);
        $accounts->audit($request->user()->id, 'review_source_refreshed', 'review_sources', $s->id, ['count' => $n]);

        return response()->json(['data' => ['fetched' => $n]]);
    }

    /** Renueva las fuentes activas cuya copia venció (programador, cada hora). */
    public static function refreshDue(): int
    {
        $c = new self;
        $n = 0;
        foreach ($c->db()->select("select * from review_sources where status in ('active', 'error') and deleted_at is null
                                    and (last_fetched_at is null or last_fetched_at < now() - make_interval(hours => cache_ttl_hours))") as $s) {
            try {
                $n += $c->refreshSource($s);
            } catch (\Throwable) {
                // el error queda en la fuente (last_error) y se reintenta en la próxima vuelta
            }
        }

        return $n;
    }

    private function source(): ?object
    {
        return $this->db()->selectOne("select * from review_sources where provider = 'google' and deleted_at is null order by created_at limit 1");
    }

    /** Lee las reseñas de Google Places y reemplaza la copia. Devuelve cuántas guardó. */
    private function refreshSource(object $s): int
    {
        $key = config('escudo.google.places_api_key');
        if (! $key) {
            $this->db()->update("update review_sources set status = 'error', last_error = ? where id = ?", ['Falta la llave de la API de Google en el servidor.', $s->id]);
            $this->fail('active', 'Falta la llave de la API de Google en el servidor: pídesela al equipo técnico.');
        }
        try {
            $res = Http::timeout(15)->withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'reviews,googleMapsUri',
            ])->get('https://places.googleapis.com/v1/places/'.rawurlencode($s->place_id), ['languageCode' => 'es']);
        } catch (\Throwable $e) {
            return $this->sourceError($s, 'No se pudo conectar con Google: '.mb_substr($e->getMessage(), 0, 150));
        }
        if (! $res->successful()) {
            return $this->sourceError($s, 'Google respondió con error '.$res->status().': '.mb_substr((string) ($res->json('error.message') ?? ''), 0, 200));
        }
        $expires = now()->addHours((int) $s->cache_ttl_hours);
        $rows = [];
        foreach ($res->json('reviews') ?? [] as $r) {
            $rating = (int) ($r['rating'] ?? 0);
            if ($rating < 1 || $rating > 5) {
                continue;
            }
            $photo = $r['authorAttribution']['photoUri'] ?? null;
            $url = $r['googleMapsUri'] ?? ($r['authorAttribution']['uri'] ?? null);
            $rows[] = [
                'review_source_id' => $s->id,
                'author_name' => mb_substr((string) ($r['authorAttribution']['displayName'] ?? 'Usuario de Google'), 0, 200),
                'author_photo_url' => is_string($photo) && str_starts_with($photo, 'https://') ? $photo : null,
                'rating' => $rating,
                'body' => $r['originalText']['text'] ?? ($r['text']['text'] ?? null),
                'review_published_at' => $r['publishTime'] ?? null,
                'provider_review_url' => is_string($url) && str_starts_with($url, 'https://') ? $url : null,
                'fetched_at' => now(), 'expires_at' => $expires,
            ];
        }
        $this->db()->transaction(function () use ($s, $rows) {
            $this->db()->delete('delete from review_snapshots where review_source_id = ?', [$s->id]);
            foreach ($rows as $row) {
                $this->db()->table('review_snapshots')->insert($row);
            }
            $this->db()->update("update review_sources set last_fetched_at = now(), last_error = null,
                                        status = case when status = 'error' then 'active' else status end where id = ?", [$s->id]);
        });

        return count($rows);
    }

    private function sourceError(object $s, string $msg): never
    {
        $this->db()->update("update review_sources set status = 'error', last_error = ? where id = ?", [$msg, $s->id]);
        $this->fail('place_id', $msg);
    }
}
