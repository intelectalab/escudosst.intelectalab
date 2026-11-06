<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Services\SuccessStoryInvitesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Casos de éxito por invitación (ver SuccessStoryInvitesService).
 *  Panel:   GET/POST /internal/site/success-stories/invites, POST .../invites/{id}/resend, DELETE .../invites/{id}
 *           GET /internal/site/success-stories/{id}/consents, GET .../consents/{consentId}/file (constancia)
 *  Público: GET/POST /public/success-stories/share/{token}     (formulario del cliente)
 *           GET/POST /public/success-stories/withdraw/{token}  (retiro de la autorización)
 */
class SuccessStoryInvitesController extends Controller
{
    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'accepted' => 'Para enviar, marca la casilla de autorización.',
        'email' => 'Escribe un correo válido.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'regex' => 'El formato no es válido.',
        'between' => 'Debe estar entre :min y :max.',
        'company_ruc.regex' => 'El RUC tiene 11 números.',
        'results.max' => 'Hasta 4 resultados.',
        'logo.max' => 'El logo debe pesar menos de 1 MB.',
        'logo.mimetypes' => 'El logo debe ser PNG, JPG o WEBP.',
        'signature.required' => 'Dibuja tu firma en el recuadro.',
    ];

    public function __construct(private SuccessStoryInvitesService $svc) {}

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->svc->list()]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'client_name' => ['required', 'string', 'min:2', 'max:120'],
            'sector' => ['nullable', 'in:'.implode(',', SuccessStoryInvitesService::sectors())],
            'contact_name' => ['required', 'string', 'min:2', 'max:120'],
            'contact_email' => ['required', 'email', 'max:160'],
            'message' => ['nullable', 'string', 'max:600'],
        ], self::MESSAGES);

        return response()->json(['data' => ['id' => $this->svc->invite($d, $request->user()->id)]], 201);
    }

    public function resend(Request $request, string $id): Response
    {
        $this->svc->resend($id, $request->user()->id);

        return response()->noContent();
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->svc->cancel($id, $request->user()->id);

        return response()->noContent();
    }

    public function consents(string $id): JsonResponse
    {
        return response()->json(['data' => $this->svc->consents($id)]);
    }

    /** Constancia de una autorización (aunque se haya retirado: es la evidencia). Queda en la bitácora. */
    public function consentFile(Request $request, string $id, string $consentId, AccountsService $accounts): StreamedResponse
    {
        $a = DB::connection('platform')->selectOne(
            'select a.disk, a.path, a.mime_type, a.original_name from success_story_consents c join site_assets a on a.id = c.file_id
              where c.id = ? and c.story_id = ?',
            [$consentId, $id]
        );
        if (! $a || ! Storage::disk($a->disk)->exists($a->path)) {
            throw ApiProblem::notFound('No se encontró la constancia.');
        }
        $accounts->audit($request->user()->id, 'success_story_consent_downloaded', 'success_stories', $id, ['consent_id' => $consentId]);

        return Storage::disk($a->disk)->download($a->path, $a->original_name ?: 'constancia.html', [
            'Content-Type' => $a->mime_type.'; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function template(): JsonResponse
    {
        return response()->json(['data' => $this->svc->template()]);
    }

    public function saveTemplate(Request $request): Response
    {
        $d = $request->validate(['template' => ['nullable', 'string', 'min:200', 'max:6000']], self::MESSAGES + ['template.min' => 'El texto es muy corto para una autorización.']);
        $this->svc->saveTemplate($d['template'] ?? null, $request->user()->id);

        return response()->noContent();
    }

    public function clients(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->svc->clients((string) $request->query('q', ''))]);
    }

    // ---------------------------------------------------------------- Público

    public function showShare(string $token): JsonResponse
    {
        return response()->json(['data' => $this->svc->publicInfo($token)])->header('Cache-Control', 'no-store');
    }

    public function submitShare(Request $request, string $token): JsonResponse
    {
        if (filled($request->input('website'))) {   // trampa para robots
            return response()->json(['data' => ['ok' => true]], 201);
        }
        $d = $request->validate([
            'company_name' => ['required', 'string', 'min:2', 'max:120'],
            'company_ruc' => ['nullable', 'regex:/^[0-9]{11}$/'],
            'sector' => ['required', 'in:'.implode(',', SuccessStoryInvitesService::sectors())],
            'project_year' => ['nullable', 'integer', 'between:2000,'.((int) date('Y'))],
            'title' => ['nullable', 'string', 'max:140'],
            'challenge' => ['required', 'string', 'min:20', 'max:1500'],
            'solution' => ['required', 'string', 'min:20', 'max:1500'],
            'results' => ['array', 'max:4'],
            'results.*.value' => ['nullable', 'string', 'max:20'],
            'results.*.label' => ['nullable', 'string', 'max:80'],
            'quote' => ['nullable', 'string', 'max:400'],
            'modules' => ['array', 'max:10'],
            'modules.*' => ['string', 'max:5'],
            'logo' => ['nullable', 'file', 'max:1024', 'mimetypes:image/png,image/jpeg,image/webp'],
            'signer_name' => ['required', 'string', 'min:5', 'max:120'],
            'signer_role' => ['required', 'string', 'min:2', 'max:80'],
            'signer_doc_type' => ['required', 'in:DNI,CE,PAS'],
            'signer_doc_number' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8,12}$/'],
            'signer_email' => ['required', 'email', 'max:160'],
            'authorize' => ['accepted'],
            'signature' => ['required', 'string', 'max:560000'],
        ], self::MESSAGES + ['signer_doc_number.regex' => 'Revisa el número: de 8 a 12 letras o números, sin espacios.']);

        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        $this->svc->submit($token, $d, $request->file('logo'), $ipHash, $request->userAgent());

        return response()->json(['data' => ['ok' => true]], 201);
    }

    public function showWithdraw(string $token): JsonResponse
    {
        return response()->json(['data' => $this->svc->withdrawInfo($token)])->header('Cache-Control', 'no-store');
    }

    public function withdraw(Request $request, string $token): Response
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:300']], self::MESSAGES);
        $this->svc->withdraw($token, $d['reason'] ?? null);

        return response()->noContent();
    }
}
