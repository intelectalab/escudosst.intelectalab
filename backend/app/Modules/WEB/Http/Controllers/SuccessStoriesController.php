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
 * Casos de éxito (SCR-WEB-09 pública /casos y SCR-WEB-44 panel).
 *  Público: GET /public/success-stories[?sector=], GET /public/success-stories/{slug}  (solo publicados)
 *  Panel:   GET/POST /internal/site/success-stories, GET/PATCH/DELETE .../{id}
 *           POST .../{id}/actions {action: approve | publish | hide | withdraw_consent}
 *           POST .../{id}/logo, POST .../{id}/consent (archivo), GET .../{id}/consent (descarga, solo panel)
 *
 * Reglas (RN-WEB-41, máquina de estados 40): borrador → aprobado → publicado ⇄ oculto.
 * Aprobar exige la autorización escrita del cliente (archivo) o la marca «Ejemplo». Retirar la autorización lo
 * oculta al momento. La autorización nunca sale por la web pública.
 * La galería de imágenes (success_story_media) llega con la biblioteca de medios.
 */
class SuccessStoriesController extends Controller
{
    public const SECTORS = ['construction', 'mining', 'agroindustry', 'manufacturing', 'retail', 'services', 'office', 'health', 'education', 'transport', 'energy', 'other'];

    private const PUBLIC_COLUMNS = 'slug, title, client_name, sector, logo_file_id, challenge, solution, results_json, quote, project_year, modules_json, is_example, published_at';

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'between' => 'Debe estar entre :min y :max.',
        'regex' => 'Solo minúsculas, números y guiones.',
        'results.max' => 'Hasta 4 resultados.',
        'modules.max' => 'Hasta 10 módulos.',
    ];

    // ---------------------------------------------------------------- Público

    public function publicIndex(Request $request): JsonResponse
    {
        $sector = $request->query('sector');
        $rows = DB::connection('platform')->select(
            'select '.self::PUBLIC_COLUMNS." from success_stories
              where status = 'published' and deleted_at is null and (consent_file_id is not null or is_example)
                and (?::text is null or sector = ?)
              order by sort_order, published_at desc",
            [is_string($sector) ? $sector : null, is_string($sector) ? $sector : null]
        );

        return response()->json(['data' => array_map($this->decode(...), $rows)])->header('Cache-Control', 'public, max-age=60');
    }

    public function publicShow(string $slug): JsonResponse
    {
        $row = DB::connection('platform')->selectOne(
            'select '.self::PUBLIC_COLUMNS." from success_stories
              where slug = ? and status = 'published' and deleted_at is null and (consent_file_id is not null or is_example)",
            [$slug]
        ) ?? throw ApiProblem::notFound('El caso no existe o aún no se publica.');

        return response()->json(['data' => $this->decode($row)])->header('Cache-Control', 'public, max-age=60');
    }

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        $rows = DB::connection('platform')->select(
            "select s.id, s.slug, s.title, s.client_name, s.sector, s.logo_file_id, s.project_year, s.is_example, s.status,
                    s.consent_file_id is not null as has_consent, s.published_at, s.updated_at
               from success_stories s where s.deleted_at is null
              order by (s.status = 'draft') desc, s.sort_order, s.updated_at desc"
        );

        return response()->json(['data' => $rows]);
    }

    public function show(string $id): JsonResponse
    {
        $row = $this->decode($this->find($id));
        $row['has_consent'] = $row['consent_file_id'] !== null;
        $row['consent_name'] = $row['consent_file_id']
            ? DB::connection('platform')->selectOne('select original_name from site_assets where id = ?', [$row['consent_file_id']])?->original_name
            : null;
        unset($row['consent_file_id']);
        if ($row['approved_by']) {
            $row['approved_by_name'] = DB::connection('platform')->selectOne('select full_name from users where id = ?', [$row['approved_by']])?->full_name;
        }

        return response()->json(['data' => $row]);
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $row = $this->validated($request, null);
        $actor = $request->user()->id;
        $id = DB::connection('platform')->table('success_stories')->insertGetId($row + ['created_by' => $actor, 'updated_by' => $actor]);
        $accounts->audit($actor, 'success_story_created', 'success_stories', $id, ['slug' => $row['slug'], 'is_example' => $row['is_example']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): Response
    {
        $current = $this->find($id);
        $row = $this->validated($request, $current);
        if (in_array($current->status, ['approved', 'published'], true) && ! $row['is_example'] && ! $current->consent_file_id) {
            $this->fail('is_example', 'Este caso ya está aprobado como «Ejemplo». Para quitarle la marca, primero adjunta la autorización del cliente.');
        }
        $actor = $request->user()->id;
        DB::connection('platform')->table('success_stories')->where('id', $id)->update($row + ['updated_by' => $actor]);
        $accounts->audit($actor, 'success_story_updated', 'success_stories', $id, ['fields' => array_keys($row)]);

        return response()->noContent();
    }

    public function destroy(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->find($id);
        DB::connection('platform')->table('success_stories')->where('id', $id)->update(['deleted_at' => now(), 'updated_by' => $request->user()->id]);
        $accounts->audit($request->user()->id, 'success_story_deleted', 'success_stories', $id, []);

        return response()->noContent();
    }

    public function act(Request $request, string $id, AccountsService $accounts): Response
    {
        $action = $request->validate(['action' => ['required', 'in:approve,publish,hide,withdraw_consent']], self::MESSAGES)['action'];
        $s = $this->find($id);
        $actor = $request->user()->id;
        $backed = $s->consent_file_id !== null || $s->is_example;
        $noBacking = 'Adjunta la autorización escrita del cliente o marca el caso como «Ejemplo».';

        $change = match ($action) {
            'approve' => match (true) {
                // En borrador, o oculto tras retirar la autorización (perdió la aprobación).
                ! ($s->status === 'draft' || ($s->status === 'hidden' && $s->approved_by === null)) => $this->fail('action', 'Este caso ya está aprobado.'),
                ! $backed => $this->fail('action', $noBacking),
                default => ['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now()],
            },
            'publish' => match (true) {
                ! in_array($s->status, ['approved', 'hidden'], true) => $this->fail('action', 'Primero apruébalo.'),
                ! $backed => $this->fail('action', $noBacking),
                // Un caso oculto por retiro de la autorización vuelve a pasar por la aprobación.
                $s->approved_by === null => $this->fail('action', 'Primero apruébalo.'),
                default => ['status' => 'published', 'published_at' => $s->published_at ?? now()],
            },
            'hide' => in_array($s->status, ['approved', 'published'], true)
                ? ['status' => 'hidden']
                : $this->fail('action', 'Solo se oculta un caso aprobado o publicado.'),
            'withdraw_consent' => $s->consent_file_id === null
                ? $this->fail('action', 'Este caso no tiene autorización adjunta.')
                : ['consent_file_id' => null]
                    + ($s->is_example ? [] : (in_array($s->status, ['approved', 'published'], true) ? ['status' => 'hidden', 'approved_by' => null, 'approved_at' => null] : [])),
        };

        // hidden sin aprobación ni autorización es válido; approved/published exigen aprobación (ck_success_stories_3).
        DB::connection('platform')->table('success_stories')->where('id', $id)->update($change + ['updated_by' => $actor]);
        if ($action === 'withdraw_consent') {
            DB::connection('platform')->update(
                "update success_story_consents set withdrawn_at = now(), withdraw_reason = 'Registrado por el equipo desde el panel' where story_id = ? and withdrawn_at is null",
                [$id]
            );
        }
        $accounts->audit($actor, "success_story_{$action}", 'success_stories', $id, ['from' => $s->status, 'to' => $change['status'] ?? $s->status]);

        return response()->noContent();
    }

    /** Logo del cliente (imagen pública) o autorización escrita (privada). */
    public function upload(Request $request, string $id, string $kind, AccountsService $accounts): JsonResponse
    {
        $s = $this->find($id);
        $isConsent = $kind === 'consent';
        $request->validate(['file' => ['required', 'file', $isConsent ? 'max:5120' : 'max:1024']], [
            'file.required' => 'Elige un archivo.',
            'file.max' => $isConsent ? 'La autorización debe pesar menos de 5 MB.' : 'El logo debe pesar menos de 1 MB.',
        ]);
        $file = $request->file('file');
        $mime = (string) $file->getMimeType();
        $allowed = $isConsent
            ? ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg']
            : ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/avif' => 'avif'];
        if (! isset($allowed[$mime])) {
            $real = strtoupper(explode('/', $mime)[1] ?? 'desconocido');
            $this->fail('file', ($isConsent ? 'La autorización debe ser PDF, PNG o JPG.' : 'El logo debe ser PNG, JPG, WEBP o AVIF.')." Este archivo es {$real} por dentro.");
        }
        $ext = $allowed[$mime];
        [$w, $h] = $ext === 'pdf' ? [null, null] : (@getimagesize($file->getRealPath()) ?: [null, null]);

        $assetKind = $isConsent ? 'story_consent' : 'story_logo';
        $disk = config('escudo.site_assets_disk', 'local');
        $assetId = (string) Str::uuid7();
        $path = ($isConsent ? 'private/' : 'site/')."{$assetKind}-{$assetId}.{$ext}";
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        $db = DB::connection('platform');
        $actor = $request->user()->id;
        $column = $isConsent ? 'consent_file_id' : 'logo_file_id';
        $db->transaction(function () use ($db, $assetId, $assetKind, $disk, $path, $mime, $file, $w, $h, $actor, $column, $id) {
            $db->insert(
                'insert into site_assets (id, kind, disk, path, mime_type, size_bytes, width, height, original_name, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$assetId, $assetKind, $disk, $path, $mime, $file->getSize(), $w, $h, mb_substr($file->getClientOriginalName(), 0, 200), $actor]
            );
            $db->update("update success_stories set {$column} = ?, updated_by = ? where id = ?", [$assetId, $actor, $id]);
        });
        $accounts->audit($actor, $isConsent ? 'success_story_consent_attached' : 'success_story_logo_uploaded', 'success_stories', $s->id, ['asset_id' => $assetId]);

        return response()->json(['data' => ['id' => $assetId]], 201);
    }

    public function removeLogo(Request $request, string $id): Response
    {
        $this->find($id);
        DB::connection('platform')->update('update success_stories set logo_file_id = null, updated_by = ? where id = ?', [$request->user()->id, $id]);

        return response()->noContent();
    }

    /** Descarga de la autorización: solo el equipo de la plataforma, con registro en la bitácora. */
    public function consent(Request $request, string $id, AccountsService $accounts): StreamedResponse
    {
        $s = $this->find($id);
        $a = $s->consent_file_id
            ? DB::connection('platform')->selectOne('select disk, path, mime_type, original_name from site_assets where id = ?', [$s->consent_file_id])
            : null;
        if (! $a || ! Storage::disk($a->disk)->exists($a->path)) {
            throw ApiProblem::notFound('Este caso no tiene autorización adjunta.');
        }
        $accounts->audit($request->user()->id, 'success_story_consent_downloaded', 'success_stories', $id, []);

        return Storage::disk($a->disk)->download($a->path, $a->original_name ?: 'autorizacion', [
            'Content-Type' => $a->mime_type,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ---------------------------------------------------------------- Apoyo

    private function validated(Request $request, ?object $current): array
    {
        $d = $request->validate([
            'client_name' => ['required', 'string', 'min:2', 'max:120'],
            'title' => ['required', 'string', 'min:5', 'max:140'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'sector' => ['required', 'in:'.implode(',', self::SECTORS)],
            'project_year' => ['nullable', 'integer', 'between:2000,'.((int) date('Y'))],
            'challenge' => ['required', 'string', 'min:10', 'max:1500'],
            'solution' => ['required', 'string', 'min:10', 'max:1500'],
            'results' => ['array', 'max:4'],
            'results.*.value' => ['required', 'string', 'max:20'],
            'results.*.label' => ['required', 'string', 'max:80'],
            'quote' => ['nullable', 'string', 'max:400'],
            'modules' => ['array', 'max:10'],
            'modules.*' => ['string', 'max:5'],
            'is_example' => ['boolean'],
            'sort_order' => ['integer', 'between:0,999'],
        ], self::MESSAGES + ['results.*.value.required' => 'Escribe la cifra.', 'results.*.label.required' => 'Escribe qué mide.']);

        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $slug = $d['slug'] ?? null ?: Str::slug($d['client_name']);
        $taken = DB::connection('platform')->selectOne(
            'select 1 from success_stories where slug = ? and deleted_at is null and id is distinct from ?::uuid', [$slug, $current?->id]
        );
        if ($taken) {
            $this->fail('slug', 'Ya hay otro caso con esta dirección. Cámbiala.');
        }
        $known = array_map(fn ($r) => trim($r->code), DB::select('select code from modules where deleted_at is null'));
        $modules = array_values(array_unique(array_map('trim', $d['modules'] ?? [])));
        if (array_diff($modules, $known)) {
            $this->fail('modules', 'Hay un módulo que no existe.');
        }
        $results = array_map(fn ($r) => ['value' => trim($r['value']), 'label' => trim($r['label'])], $d['results'] ?? []);

        return [
            'client_name' => trim($d['client_name']),
            'title' => trim($d['title']),
            'slug' => $slug,
            'sector' => $d['sector'],
            'project_year' => $d['project_year'] ?? null,
            'challenge' => trim($d['challenge']),
            'solution' => trim($d['solution']),
            'results_json' => json_encode($results, JSON_UNESCAPED_UNICODE),
            'quote' => $clean($d['quote'] ?? null),
            'modules_json' => json_encode($modules),
            'is_example' => (bool) ($d['is_example'] ?? false),
            'sort_order' => $d['sort_order'] ?? 0,
        ];
    }

    private function find(string $id): object
    {
        return DB::connection('platform')->table('success_stories')->where('id', $id)->whereNull('deleted_at')->first()
            ?? throw ApiProblem::notFound('El caso no existe.');
    }

    private function decode(object $row): array
    {
        $r = (array) $row;
        foreach (['results_json' => 'results', 'modules_json' => 'modules'] as $col => $key) {
            if (array_key_exists($col, $r)) {
                $r[$key] = is_string($r[$col]) ? json_decode($r[$col], true) : ($r[$col] ?? []);
                unset($r[$col]);
            }
        }

        return $r;
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
