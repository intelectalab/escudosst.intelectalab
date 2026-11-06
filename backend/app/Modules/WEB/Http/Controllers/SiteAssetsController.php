<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Logo y favicon del sitio (SCR-WEB-21).
 *  POST   /internal/site/assets/{kind}   sube y reemplaza (kind: logo | logo_dark | favicon)
 *  DELETE /internal/site/assets/{kind}   vuelve al logo de EscudoSST por defecto
 *  GET    /public/site/assets/{id}       entrega el archivo (caché larga: un archivo nuevo tiene otro id)
 *
 * Solo imágenes de mapa de bits (PNG, JPG, WEBP, AVIF; PNG o ICO para el favicon): SVG no, porque puede llevar código.
 */
class SiteAssetsController extends Controller
{
    private const COLUMN = ['logo' => 'logo_file_id', 'logo_dark' => 'logo_dark_file_id', 'favicon' => 'favicon_file_id'];

    public function store(Request $request, string $kind, AccountsService $accounts): JsonResponse
    {
        $column = self::COLUMN[$kind] ?? throw ApiProblem::notFound('Tipo de archivo desconocido.');
        $isFavicon = $kind === 'favicon';
        $request->validate([
            'file' => ['required', 'file', $isFavicon ? 'max:200' : 'max:1024'],
        ], [
            'file.required' => 'Elige un archivo.',
            'file.max' => $isFavicon ? 'El favicon debe pesar menos de 200 KB.' : 'El logo debe pesar menos de 1 MB.',
        ]);
        $file = $request->file('file');

        // Se mira el contenido real, no la terminación del nombre: muchas imágenes bajadas de internet dicen
        // «.png» pero por dentro son AVIF o WEBP.
        $mime = (string) $file->getMimeType();
        $allowed = $isFavicon
            ? ['image/png' => 'png', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico']
            : ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/avif' => 'avif'];
        if (! isset($allowed[$mime])) {
            $real = strtoupper(explode('/', $mime)[1] ?? 'desconocido');
            $name = strtolower($file->getClientOriginalName());
            $msg = ($isFavicon ? 'El favicon debe ser PNG o ICO.' : 'El logo debe ser PNG, JPG, WEBP o AVIF.')
                .(str_contains($name, '.') ? " Este archivo es {$real} por dentro, aunque su nombre diga «.".pathinfo($name, PATHINFO_EXTENSION)."»." : '');
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', $msg, ['errors' => ['file' => [$msg]]]);
        }
        $ext = $allowed[$mime];
        [$w, $h] = @getimagesize($file->getRealPath()) ?: [null, null];
        if ($ext !== 'ico' && $ext !== 'avif' && ! $w) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'No es una imagen válida.', ['errors' => ['file' => ['No es una imagen válida.']]]);
        }
        if ($isFavicon && $w && ($w < 32 || $w !== $h)) {
            $msg = "El favicon debe ser cuadrado y de al menos 32 × 32 px (ideal 512 × 512). Este mide {$w} × {$h} px.";
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', $msg, ['errors' => ['file' => [$msg]]]);
        }

        $disk = config('escudo.site_assets_disk', 'local');
        $id = (string) Str::uuid7();
        $path = "site/{$kind}-{$id}.{$ext}";
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        $db = DB::connection('platform');
        $actor = $request->user()->id;
        $db->transaction(function () use ($db, $id, $kind, $disk, $path, $file, $w, $h, $actor, $column, $mime) {
            $db->insert(
                'insert into site_assets (id, kind, disk, path, mime_type, size_bytes, width, height, original_name, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $kind, $disk, $path, $mime, $file->getSize(), $w, $h, mb_substr($file->getClientOriginalName(), 0, 200), $actor]
            );
            $db->update("update site_settings set {$column} = ?, updated_by = ? where singleton", [$id, $actor]);
        });
        $accounts->audit($actor, 'site_asset_uploaded', 'site_settings', null, ['kind' => $kind, 'asset_id' => $id]);

        return response()->json(['data' => ['id' => $id, 'width' => $w, 'height' => $h]], 201);
    }

    public function destroy(Request $request, string $kind, AccountsService $accounts): Response
    {
        $column = self::COLUMN[$kind] ?? throw ApiProblem::notFound('Tipo de archivo desconocido.');
        DB::connection('platform')->update("update site_settings set {$column} = null, updated_by = ? where singleton", [$request->user()->id]);
        $accounts->audit($request->user()->id, 'site_asset_removed', 'site_settings', null, ['kind' => $kind]);

        return response()->noContent();
    }

    public function show(string $id): StreamedResponse
    {
        $a = DB::connection('platform')->selectOne('select disk, path, mime_type from site_assets where id = ?', [$id]);
        if (! $a || ! Storage::disk($a->disk)->exists($a->path)) {
            throw ApiProblem::notFound('El archivo no existe.');
        }

        return Storage::disk($a->disk)->response($a->path, null, [
            'Content-Type' => $a->mime_type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
