<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Panel del dueño · Sitio web → Marca, contacto y ajustes (SCR-WEB-21).
 * Número y mensaje de WhatsApp de EscudoSST, barra de anuncio, datos legales del titular, correos, redes y enlaces.
 *
 *  GET   /internal/site/settings
 *  PATCH /internal/site/settings
 */
class SiteSettingsController extends Controller
{
    private const FIELDS = [
        'trade_name', 'legal_name', 'ruc', 'legal_address', 'primary_domain', 'contact_email', 'sales_email', 'support_email',
        'privacy_email', 'contact_phone', 'whatsapp_number', 'whatsapp_default_message', 'blog_url', 'show_latest_blog_posts',
        'latest_blog_posts_count', 'interactive_demo_url', 'complaints_officer_name', 'complaints_officer_email',
        'complaints_officer_phone', 'social_links', 'announcement_bar',
    ];

    public function show(): JsonResponse
    {
        $s = DB::connection('platform')->selectOne('select * from site_settings where singleton and deleted_at is null');
        $data = [];
        foreach (self::FIELDS as $f) {
            $data[$f] = in_array($f, ['social_links', 'announcement_bar'], true) ? json_decode($s->{$f} ?? 'null', true) : $s->{$f};
        }
        $data['ruc'] = trim((string) $data['ruc']);

        foreach (['logo_file_id', 'logo_dark_file_id', 'favicon_file_id'] as $f) {
            $data[$f] = $s->{$f};
        }

        $data['home_video'] = self::homeVideoOf($s->hero_video_urls);
        if ($data['home_video']['media_id'] ?? null) {
            $data['home_video']['title'] = DB::connection('platform')->selectOne('select title from site_media where id = ?', [$data['home_video']['media_id']])?->title;
        }

        return response()->json(['data' => $data + ['content_blocked_terms' => json_decode($s->content_blocked_terms, true), 'updated_at' => $s->updated_at]]);
    }

    /** El video de la portada es la entrada «presentation» de hero_video_urls. */
    public static function homeVideoOf(?string $json): ?array
    {
        foreach (json_decode($json ?? '[]', true) ?: [] as $v) {
            if (($v['kind'] ?? null) === 'presentation') {
                return $v;
            }
        }

        return null;
    }

    /** Proveedor del enlace (youtube | vimeo) o null si es de otra página. */
    public static function videoProvider(string $url): ?string
    {
        if (preg_match('~^https://(www\.|m\.)?(youtube\.com/watch\?v=|youtu\.be/)[\w-]{6,}~i', $url)) {
            return 'youtube';
        }

        return preg_match('~^https://(www\.)?vimeo\.com/\d+~i', $url) ? 'vimeo' : null;
    }

    /**
     * PUT /internal/site/settings/home-video
     * Video de la sección «Demostración» de la portada: un video de la Biblioteca de medios (media_id) o un enlace de YouTube o Vimeo (url).
     */
    public function saveHomeVideo(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate([
            'media_id' => ['nullable', 'uuid', 'required_without:url'],
            'url' => ['nullable', 'string', 'max:250', 'required_without:media_id'],
        ], ['*.required_without' => 'Elige un video de la biblioteca o pega un enlace de YouTube o de Vimeo.', 'media_id.uuid' => 'Elige un video de la biblioteca.']);
        $db = DB::connection('platform');

        if (! empty($d['media_id'])) {
            $m = $db->selectOne('select id, file_id, title, kind from site_media where id = ? and deleted_at is null', [$d['media_id']]);
            if (! $m || $m->kind !== 'video') {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', 'Elige un video (MP4 o WEBM) de la Biblioteca de medios.',
                    ['errors' => ['media_id' => ['Elige un video (MP4 o WEBM) de la Biblioteca de medios.']]]);
            }
            $video = ['kind' => 'presentation', 'provider' => 'upload', 'media_id' => $m->id, 'file_id' => $m->file_id, 'url' => null];
        } else {
            $url = trim($d['url']);
            $provider = self::videoProvider($url);
            if (! $provider) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', 'Pega un enlace de YouTube o de Vimeo.',
                    ['errors' => ['url' => ['Pega un enlace de YouTube o de Vimeo.']]]);
            }
            $video = ['kind' => 'presentation', 'provider' => $provider, 'url' => $url, 'media_id' => null, 'file_id' => null];
        }

        $this->putHomeVideo($video, $request->user()->id);
        $accounts->audit($request->user()->id, 'site_home_video_set', 'site_settings', null, ['provider' => $video['provider']]);

        return response()->json(['data' => $video]);
    }

    /** DELETE /internal/site/settings/home-video: la portada vuelve al reproductor de muestra. */
    public function removeHomeVideo(Request $request, AccountsService $accounts): Response
    {
        $this->putHomeVideo(null, $request->user()->id);
        $accounts->audit($request->user()->id, 'site_home_video_removed', 'site_settings', null, []);

        return response()->noContent();
    }

    private function putHomeVideo(?array $video, string $userId): void
    {
        $db = DB::connection('platform');
        $list = array_values(array_filter(json_decode($db->selectOne('select hero_video_urls from site_settings where singleton')->hero_video_urls, true) ?: [],
            fn ($v) => ($v['kind'] ?? null) !== 'presentation'));
        if ($video) {
            array_unshift($list, $video);
        }
        $db->table('site_settings')->where('singleton', true)->update(['hero_video_urls' => json_encode($list, JSON_UNESCAPED_UNICODE), 'updated_by' => $userId]);
    }

    public function update(Request $request, AccountsService $accounts): Response
    {
        $email = ['nullable', 'email:rfc', 'max:160'];
        $data = $request->validate([
            'trade_name' => ['required', 'string', 'max:60'],
            'legal_name' => ['required', 'string', 'max:160'],
            'ruc' => ['required', 'regex:/^(10|15|17|20)\d{9}$/'],
            'legal_address' => ['nullable', 'string', 'max:250'],
            'primary_domain' => ['nullable', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/'],
            'contact_email' => $email, 'sales_email' => $email, 'support_email' => $email, 'privacy_email' => $email,
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'whatsapp_number' => ['nullable', 'regex:/^\d{8,15}$/'],
            'whatsapp_default_message' => ['nullable', 'string', 'max:300'],
            'blog_url' => ['nullable', 'url', 'starts_with:https://', 'max:250'],
            'show_latest_blog_posts' => ['boolean'],
            'latest_blog_posts_count' => ['integer', 'between:1,12'],
            'interactive_demo_url' => ['nullable', 'url', 'starts_with:https://', 'max:250'],
            'complaints_officer_name' => ['nullable', 'string', 'max:120'],
            'complaints_officer_email' => $email,
            'complaints_officer_phone' => ['nullable', 'string', 'max:30'],
            'social_links' => ['array'],
            'social_links.*' => ['nullable', 'url', 'starts_with:https://', 'max:250'],
            'announcement_bar' => ['nullable', 'array'],
            'announcement_bar.text' => ['nullable', 'string', 'max:140'],
            'announcement_bar.link' => ['nullable', 'string', 'max:250'],
            'announcement_bar.active' => ['boolean'],
            'announcement_bar.until' => ['nullable', 'date'],
        ], [
            'ruc.regex' => 'El RUC debe tener 11 dígitos y empezar con 10, 15, 17 o 20.',
            'primary_domain.regex' => 'Escribe solo el dominio, sin https:// (por ejemplo: escudosst.com).',
            'whatsapp_number.regex' => 'Escribe solo números con el código de país, sin + ni espacios (por ejemplo: 51987654321).',
            '*.email' => 'El correo no es válido.',
            'blog_url.starts_with' => 'El enlace debe empezar con https://.',
            'interactive_demo_url.starts_with' => 'El enlace debe empezar con https://.',
            'social_links.*.starts_with' => 'Los enlaces deben empezar con https://.',
            'social_links.*.url' => 'El enlace no es válido.',
        ]);

        $db = DB::connection('platform');
        $current = $db->selectOne('select * from site_settings where singleton and deleted_at is null');

        // Lineamientos de contenido (RN-WEB-58): frases que el sitio no puede publicar por no ser veraces o no tener respaldo.
        $blocked = json_decode($current->content_blocked_terms, true) ?: [];
        foreach (['announcement_bar.text' => $data['announcement_bar']['text'] ?? null, 'whatsapp_default_message' => $data['whatsapp_default_message'] ?? null] as $field => $text) {
            foreach ($blocked as $term) {
                if ($text && mb_stripos($text, $term) !== false) {
                    $msg = "No se puede publicar «{$term}»: es una afirmación que EscudoSST no puede respaldar (lineamientos de contenido).";
                    throw new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', $msg, ['errors' => [$field => [$msg]]]);
                }
            }
        }

        $row = [];
        foreach (self::FIELDS as $f) {
            if (! array_key_exists($f, $data)) {
                continue;
            }
            $v = $data[$f];
            $row[$f] = in_array($f, ['social_links', 'announcement_bar'], true)
                ? json_encode($f === 'social_links' ? (object) array_filter($v ?? []) : $v, JSON_UNESCAPED_UNICODE)
                : (is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v);
        }
        $db->table('site_settings')->where('singleton', true)->update($row + ['updated_by' => $request->user()->id]);

        $changed = [];
        foreach ($row as $f => $v) {
            if ((string) ($current->{$f} ?? '') !== (string) ($v ?? '')) {
                $changed[$f] = $v;
            }
        }
        $accounts->audit($request->user()->id, 'site_settings_updated', 'site_settings', $current->id, ['fields' => array_keys($changed)]);

        return response()->noContent();
    }
}
