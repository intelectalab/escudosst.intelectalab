<?php

namespace App\Modules\DOC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Archivos de la empresa (evidencias, fotos, planos, documentos):
 *  POST /files        subir {file, module?} → {id, original_name, mime_type, size_bytes}
 *  GET  /files/{id}   ver o descargar (solo la empresa dueña, por RLS)
 *
 * - Tipos permitidos: PDF, imágenes (JPG, PNG, WEBP), Word, Excel; hasta 15 MB.
 * - Se guarda su huella SHA-256 (integridad) y quién lo subió.
 * - Los archivos de módulos médicos se marcan is_medical y solo los descargan roles con permiso sensible.
 */
class FilesController extends Controller
{
    private const TYPES = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function store(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'Elige un archivo.', ['errors' => ['file' => ['Elige un archivo.']]]);
        }
        $mime = \App\Core\Support\UploadGuard::realMime($file);
        $ext = self::TYPES[$mime] ?? null;
        if (! $ext) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'Solo se aceptan PDF, imágenes (JPG, PNG, WEBP), Word o Excel.',
                ['errors' => ['file' => ['Tipo de archivo no permitido.']]]);
        }
        if ($file->getSize() > 15 * 1024 * 1024) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'El archivo pesa más de 15 MB.', ['errors' => ['file' => ['Máximo 15 MB.']]]);
        }
        $module = strtoupper(substr((string) $request->input('module', ''), 0, 3)) ?: null;
        $id = self::put($file, $mime, $ext, $module);

        return response()->json(['data' => ['id' => $id, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $mime, 'size_bytes' => $file->getSize()]], 201);
    }

    /**
     * Guarda un archivo subido en el almacenamiento de la empresa activa (con su huella SHA-256) y devuelve su id.
     * Lo usan otros módulos que conservan el archivo original (p. ej. la carga masiva de trabajadores).
     */
    public static function put(UploadedFile $file, string $mime, string $ext, ?string $module = null, ?string $entityType = null, ?string $entityId = null): string
    {
        // docs/03 §8: con el almacenamiento del plan lleno no se suben más archivos (los ya subidos siguen disponibles).
        \App\Modules\SUS\Services\PlanLimits::assertCanCreate('storage', (int) $file->getSize());
        $medical = $module && in_array($module, Permissions::MEDICAL_MODULES, true);
        $ctx = app(CompanyContext::class);
        $disk = config('escudo.files_disk', 'local');
        $id = (string) Str::uuid7();
        $path = "company/{$ctx->companyId}/".date('Y/m')."/{$id}.{$ext}";
        $stream = fopen($file->getRealPath(), 'rb');
        Storage::disk($disk)->writeStream($path, $stream);
        is_resource($stream) && fclose($stream);

        DB::insert(
            "insert into files (id, company_id, bucket, object_key, original_name, mime_type, size_bytes, sha256, module_code, entity_type, entity_id, status, exif_stripped,
                                uploaded_by, is_medical, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), ?, ?, ?, 'available', false, ?, ?, ?, ?)",
            [$id, $ctx->companyId, $disk, $path, mb_substr($file->getClientOriginalName(), 0, 200), $mime, $file->getSize(), hash_file('sha256', $file->getRealPath()),
                $module, $entityType, $entityId, (string) auth()->id(), $medical ? 'true' : 'false', (string) auth()->id(), (string) auth()->id()]
        );

        return $id;
    }

    public function show(string $id): StreamedResponse
    {
        $f = Str::isUuid($id) ? DB::selectOne("select * from files where id = ? and deleted_at is null and status = 'available'", [$id]) : null;
        if (! $f) {
            throw ApiProblem::notFound('El archivo no existe o es de otra empresa.');
        }
        if ($f->is_medical && ! array_filter(Permissions::effective(), fn ($p) => str_ends_with($p, '.read_sensitive') || str_starts_with($p, 'emo.'))) {
            throw ApiProblem::forbidden('Es un archivo de salud: solo lo ve el personal autorizado.');
        }
        if (app(CompanyContext::class)->roleCode === 'worker' && ! $this->workerMayRead($f)) {
            throw ApiProblem::forbidden('Este archivo no es tuyo.');
        }
        return self::stream($f);
    }

    /**
     * RN-DOC-07: enlace de descarga firmado y de corta duración (signed_url_ttl minutos), para abrir o compartir el archivo
     * sin la sesión (p. ej. en otra pestaña o en el celular). Pasado el tiempo deja de funcionar. Los archivos médicos no
     * tienen enlace: se abren con la reautenticación médica.
     */
    public function link(string $id): \Illuminate\Http\JsonResponse
    {
        $f = Str::isUuid($id) ? DB::selectOne("select * from files where id = ? and deleted_at is null and status = 'available'", [$id]) : null;
        if (! $f) {
            throw ApiProblem::notFound('El archivo no existe o es de otra empresa.');
        }
        if ($f->is_medical) {
            throw ApiProblem::forbidden('Los archivos de salud no tienen enlace de descarga: se abren con la clave adicional.');
        }
        if (app(CompanyContext::class)->roleCode === 'worker' && ! $this->workerMayRead($f)) {
            throw ApiProblem::forbidden('Este archivo no es tuyo.');
        }
        $minutes = max(1, (int) \App\Core\Support\LegalParams::number('signed_url_ttl', 10));
        $expires = now()->addMinutes($minutes);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('public.files.signed', $expires, ['company' => $f->company_id, 'id' => $f->id]);
        $ctx = app(CompanyContext::class);
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes) values (?, ?, ?, 'user', 'export', 'DOC', 'files', ?, ?::jsonb)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $f->id, json_encode(['signed_link_minutes' => $minutes])]);

        return response()->json(['data' => ['url' => $url, 'expires_at' => $expires->toIso8601String(), 'minutes' => $minutes]]);
    }

    /** GET /public/files/{company}/{id}?expires=&signature= — descarga con el enlace firmado (sin sesión). */
    public function signed(string $company, string $id): StreamedResponse
    {
        if (! Str::isUuid($company) || ! Str::isUuid($id)) {
            throw ApiProblem::notFound('El archivo no existe.');
        }

        return DB::transaction(function () use ($company, $id) {
            \App\Core\Tenancy\DbContext::setCompany($company);
            $f = DB::selectOne("select * from files where id = ? and deleted_at is null and status = 'available' and not is_medical", [$id]);
            if (! $f) {
                throw ApiProblem::notFound('El archivo ya no está disponible.');
            }

            return self::stream($f);
        });
    }

    private static function stream(object $f): StreamedResponse
    {
        $name = str_replace('"', '', (string) ($f->original_name ?: 'archivo'));

        return Storage::disk($f->bucket)->response($f->object_key, $name, [
            'Content-Type' => $f->mime_type,
            'Content-Disposition' => (str_starts_with($f->mime_type, 'image/') || $f->mime_type === 'application/pdf' ? 'inline' : 'attachment')."; filename=\"{$name}\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Un trabajador solo abre lo que subió, lo ligado a su ficha y los documentos que la empresa le da a leer y firmar. */
    private function workerMayRead(object $f): bool
    {
        if ((string) $f->uploaded_by === (string) auth()->id()) {
            return true;
        }
        $workerId = \App\Modules\TRB\WorkerSelf::id();
        if ($f->entity_type === 'workers' && (string) $f->entity_id === $workerId) {
            return true;
        }

        // El plano del mapa de riesgos vigente lo ve el trabajador (M-MAP: consulta el mapa de su sede).
        return (bool) DB::selectOne("select 1 as ok where exists (select 1 from document_versions where file_id = ?) or exists (select 1 from risst_versions where file_id = ?)
                                       or exists (select 1 from risk_maps where plan_file_id = ? and status in ('approved', 'current') and deleted_at is null)", [$f->id, $f->id, $f->id]);
    }
}
