<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Http\Controllers\Concerns\SitePanelHelpers;
use App\Modules\WEB\Services\WebinarsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Webinars (SCR-WEB-78 /webinars y /webinars/{slug}, SCR-WEB-79 panel).
 *  Público: GET /public/webinars, GET /public/webinars/{slug}, POST /public/webinars/{slug}/register,
 *           POST /public/webinars/cancel/{token}
 *  Panel:   GET/POST /internal/site/webinars, PUT .../{id}, POST .../{id}/{publish|live|finish|cancel} (publicar: Dueño),
 *           GET .../{id}/registrations, POST .../registrations/{rid}/{attended|no_show}
 * La grabación se publica solo si se avisó a ponente y asistentes (recording_informed) y hay archivo de video.
 */
class WebinarsController extends Controller
{
    use SitePanelHelpers;

    public const PROVIDERS = ['meet' => 'Google Meet', 'teams' => 'Microsoft Teams', 'zoom' => 'Zoom', 'other' => 'Otro'];

    public function publicIndex(): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_webinars() as r')->r, true);

        return response()->json(['data' => ['upcoming' => $r['upcoming'], 'recorded' => $r['recorded']]])->header('Cache-Control', 'public, max-age=30');
    }

    public function publicShow(string $slug): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_webinar(?) as r', [$slug])->r, true);
        if (($r['result'] ?? '') !== 'ok') {
            throw ApiProblem::notFound('El webinar no existe.');
        }
        unset($r['result']);

        return response()->json(['data' => $r]);
    }

    public function register(Request $request, string $slug, WebinarsService $webinars): JsonResponse
    {
        if ($request->filled('website')) {
            return response()->json(['data' => ['result' => 'registered']], 201);   // trampa para robots
        }
        $d = $request->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:20'],
            'privacy_consent' => ['accepted'],
            'marketing_consent' => ['boolean'],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'utm_medium' => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:100'],
        ], self::MESSAGES);
        $d['privacy_consent'] = true;
        $d['marketing_consent'] = (bool) ($d['marketing_consent'] ?? false);
        $r = $webinars->register($slug, $d, hash('sha256', (string) $request->ip()));
        $messages = ['not_found' => 'El webinar no existe.', 'closed' => 'Las inscripciones ya cerraron.', 'full' => 'Ya no quedan cupos para este webinar.'];
        if (isset($messages[$r['result']])) {
            throw new ApiProblem($r['result'] === 'not_found' ? 404 : 409, 'conflict', 'No se pudo inscribir', $messages[$r['result']]);
        }

        return response()->json(['data' => ['result' => 'registered']], 201);
    }

    public function cancelRegistration(string $token): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_webinar_cancel(?) as r', [$token])->r, true);
        if (($r['result'] ?? '') === 'invalid') {
            throw ApiProblem::notFound('El enlace no es válido.');
        }
        if (($r['result'] ?? '') === 'not_allowed') {
            throw new ApiProblem(409, 'conflict', 'No se puede cancelar', 'El webinar ya pasó.');
        }

        return response()->json(['data' => ['result' => 'cancelled']]);
    }

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        $rows = $this->db()->select(
            "select w.*, cm.file_id as cover_file_id,
                    (select count(*) from webinar_registrations r where r.webinar_id = w.id and r.status in ('registered', 'attended', 'no_show') and r.deleted_at is null) as registered,
                    (select count(*) from webinar_registrations r where r.webinar_id = w.id and r.status = 'attended' and r.deleted_at is null) as attended
               from webinars w left join site_media cm on cm.id = w.cover_media_id
              where w.deleted_at is null order by (w.status in ('draft', 'published', 'live')) desc, w.starts_at desc"
        );

        return response()->json(['data' => $rows, 'providers' => self::PROVIDERS]);
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $row = $this->validated($request, null);
        $actor = $request->user()->id;
        $id = $this->db()->table('webinars')->insertGetId($row + ['created_by' => $actor, 'updated_by' => $actor]);
        $accounts->audit($actor, 'webinar_created', 'webinars', $id, ['title' => $row['title']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts, WebinarsService $webinars): Response
    {
        $current = $this->row('webinars', $id, 'El webinar no existe.');
        $row = $this->validated($request, $current);
        $this->db()->table('webinars')->where('id', $id)->update($row + ['updated_by' => $request->user()->id, 'updated_at' => now()]);
        $accounts->audit($request->user()->id, 'webinar_updated', 'webinars', $id, ['title' => $row['title']]);
        if ($row['recording_public'] && ! $current->recording_public) {
            $webinars->notifyAll($id, 'recording');   // grabación publicada: aviso a los inscritos (M-WEB §10)
        }

        return response()->noContent();
    }

    public function act(Request $request, string $id, string $action, AccountsService $accounts, WebinarsService $webinars): Response
    {
        $w = $this->row('webinars', $id, 'El webinar no existe.');
        $status = ['publish' => 'published', 'live' => 'live', 'finish' => 'finished', 'cancel' => 'cancelled'][$action];
        if ($action === 'publish') {
            if ($request->user()->platform_role !== 'owner') {
                throw ApiProblem::forbidden('Solo el Dueño de la plataforma publica webinars.');
            }
            if (strtotime($w->starts_at) <= time()) {
                $this->fail('starts_at', 'La fecha ya pasó: cámbiala antes de publicar.');
            }
            $this->assertAllowedText('description', $w->title, (string) $w->description);
        }
        $this->db()->update('update webinars set status = ?, updated_by = ?, updated_at = now() where id = ?', [$status, $request->user()->id, $id]);
        $accounts->audit($request->user()->id, "webinar_{$action}", 'webinars', $id, ['title' => $w->title]);
        if ($action === 'cancel' && $w->status !== 'cancelled') {
            $webinars->notifyAll($id, 'cancelled');   // webinar cancelado: aviso a los inscritos (M-WEB §10)
        }

        return response()->noContent();
    }

    public function registrations(string $id): JsonResponse
    {
        $this->row('webinars', $id, 'El webinar no existe.');
        $rows = $this->db()->select(
            'select r.id, r.status, r.registered_at, l.full_name, l.email, l.company_name, l.phone, l.marketing_consent
               from webinar_registrations r join marketing_leads l on l.id = r.lead_id
              where r.webinar_id = ? and r.deleted_at is null order by r.registered_at',
            [$id]
        );

        return response()->json(['data' => $rows]);
    }

    public function mark(Request $request, string $rid, string $status, AccountsService $accounts): Response
    {
        $r = $this->row('webinar_registrations', $rid, 'La inscripción no existe.');
        if ($r->status === 'cancelled') {
            $this->conflict('Esta persona canceló su inscripción.');
        }
        $this->db()->update('update webinar_registrations set status = ?, updated_by = ?, updated_at = now() where id = ?', [$status, $request->user()->id, $rid]);
        $accounts->audit($request->user()->id, 'webinar_attendance_marked', 'webinar_registrations', $rid, ['status' => $status]);

        return response()->noContent();
    }

    private function validated(Request $request, ?object $current): array
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:140'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:3000'],
            'speaker_name' => ['required', 'string', 'min:3', 'max:120'],
            'speaker_title' => ['nullable', 'string', 'max:160'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'between:10,600'],
            'meeting_provider' => ['required', 'in:'.implode(',', array_keys(self::PROVIDERS))],
            'meeting_url' => ['nullable', 'string', 'max:500', 'starts_with:https://'],
            'capacity' => ['nullable', 'integer', 'between:1,100000'],
            'cover_media_id' => ['nullable', 'uuid'],
            'recording_media_id' => ['nullable', 'uuid'],
            'recording_informed' => ['boolean'],
            'recording_public' => ['boolean'],
        ], self::MESSAGES + ['meeting_url.starts_with' => 'El enlace de la reunión debe empezar con https://.']);
        $slug = ($d['slug'] ?? null) ?: Str::slug($d['title']);
        if ($this->db()->selectOne('select 1 from webinars where slug = ? and deleted_at is null and id is distinct from ?::uuid', [$slug, $current?->id])) {
            $this->fail('slug', 'Ya hay otro webinar con esta dirección.');
        }
        $recording = $this->media($d['recording_media_id'] ?? null, 'recording_media_id', ['video'], 'La grabación debe ser un video de la biblioteca de medios.');
        $informed = (bool) ($d['recording_informed'] ?? false);
        $public = (bool) ($d['recording_public'] ?? false);
        if ($public && (! $informed || ! $recording)) {
            $this->fail('recording_public', 'Para publicar la grabación, elige el video y confirma que se avisó a ponente y asistentes.');
        }
        $this->assertAllowedText('description', $d['title'], (string) ($d['description'] ?? ''));

        return [
            'title' => trim($d['title']), 'slug' => $slug, 'description' => $this->clean($d['description'] ?? null),
            'speaker_name' => trim($d['speaker_name']), 'speaker_title' => $this->clean($d['speaker_title'] ?? null),
            'starts_at' => $d['starts_at'], 'duration_minutes' => (int) $d['duration_minutes'], 'meeting_provider' => $d['meeting_provider'],
            'meeting_url' => $this->clean($d['meeting_url'] ?? null), 'capacity' => $d['capacity'] ?? null,
            'cover_media_id' => $this->media($d['cover_media_id'] ?? null, 'cover_media_id'),
            'recording_media_id' => $recording, 'recording_informed' => $informed, 'recording_public' => $public,
        ];
    }
}
