<?php

namespace App\Modules\FIR;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Firma manuscrita digitalizada:
 *  GET    /me/signature                 mi firma guardada (como imagen data:)
 *  PUT    /me/signature {image, kind}   guardar la firma: dibujada en pantalla o foto subida (PNG o JPG, máximo 450 KB)
 *  DELETE /me/signature                 quitarla
 *  GET    /fir/signatures/{id}/image    imagen de una firma hecha (para mostrarla en el documento)
 */
class SignatureImages extends Controller
{
    public function show(): JsonResponse
    {
        $s = DB::selectOne('select image, mime_type, kind, updated_at from user_signatures where user_id = ?', [(string) auth()->id()]);

        return response()->json(['data' => $s ? ['image' => self::dataUrl($s->image, $s->mime_type), 'kind' => $s->kind, 'updated_at' => $s->updated_at] : null]);
    }

    public function save(Request $request): JsonResponse
    {
        $d = $request->validate(['image' => ['required', 'string', 'max:700000'], 'kind' => ['required', 'in:drawn,uploaded']], ['image.required' => 'Dibuja tu firma o sube una foto.']);
        [$bytes, $mime] = self::decode($d['image']);
        DB::statement(
            "insert into user_signatures (user_id, image, mime_type, kind, updated_at) values (?, decode(?, 'base64'), ?, ?, now())
             on conflict (user_id) do update set image = excluded.image, mime_type = excluded.mime_type, kind = excluded.kind, updated_at = now()",
            [(string) auth()->id(), base64_encode($bytes), $mime, $d['kind']]
        );

        return response()->json(['data' => ['saved' => true]]);
    }

    public function destroy(): Response
    {
        DB::delete('delete from user_signatures where user_id = ?', [(string) auth()->id()]);

        return response()->noContent();
    }

    public function image(string $id): Response
    {
        // La firma solo se ve si existe en la empresa activa (RLS de signatures).
        $sig = Str::isUuid($id) ? DB::selectOne('select id from signatures where id = ?', [$id]) : null;
        $img = $sig ? DB::selectOne('select image, mime_type from signature_strokes where signature_id = ?', [$id]) : null;
        if (! $img) {
            throw ApiProblem::notFound('La firma no tiene imagen.');
        }

        return response(self::bytes($img->image), 200, ['Content-Type' => $img->mime_type, 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Imagen de la firma guardada de un usuario como data: (o null). */
    public static function userDataUrl(?string $userId): ?string
    {
        if (! $userId) {
            return null;
        }
        $s = DB::selectOne('select image, mime_type from user_signatures where user_id = ?', [$userId]);

        return $s ? self::dataUrl($s->image, $s->mime_type) : null;
    }

    /** Copia la firma (guardada o recién dibujada) a la firma hecha. */
    public static function stamp(string $signatureId, string $companyId, ?string $userId, ?string $drawn): void
    {
        if ($drawn) {
            [$bytes, $mime] = self::decode($drawn);
        } elseif ($userId && ($s = DB::selectOne('select image, mime_type from user_signatures where user_id = ?', [$userId]))) {
            [$bytes, $mime] = [self::bytes($s->image), $s->mime_type];
        } else {
            return;
        }
        DB::insert("insert into signature_strokes (signature_id, company_id, image, mime_type) values (?, ?, decode(?, 'base64'), ?)", [$signatureId, $companyId, base64_encode($bytes), $mime]);
    }

    /** @return array{0: string, 1: string} bytes y tipo, validando que de verdad sea PNG o JPG. */
    public static function decode(string $dataUrl): array
    {
        if (! preg_match('#^data:(image/(?:png|jpeg));base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa la firma', 'La firma debe ser una imagen PNG o JPG.', ['errors' => ['image' => ['La firma debe ser una imagen PNG o JPG.']]]);
        }
        $bytes = (string) base64_decode($m[2], true);
        $real = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($real, ['image/png', 'image/jpeg'], true) || strlen($bytes) > 460000) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa la firma', 'La imagen no es válida o pesa más de 450 KB.', ['errors' => ['image' => ['La imagen no es válida o pesa más de 450 KB.']]]);
        }

        return [$bytes, $real];
    }

    private static function dataUrl(mixed $bytea, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode(self::bytes($bytea));
    }

    private static function bytes(mixed $v): string
    {
        if (is_resource($v)) {
            $v = stream_get_contents($v);
        }
        $v = (string) $v;

        return str_starts_with($v, '\\x') ? (string) hex2bin(substr($v, 2)) : $v;
    }
}
