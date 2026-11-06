<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WEB\Services\LegalDocumentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Políticas del sitio (SCR-WEB-15 público y SCR-WEB-24 panel).
 *
 *  GET   /internal/site/legal                              documentos con sus versiones
 *  PATCH /internal/site/legal/versions/{id}                guardar borrador { content, summary_of_changes? }
 *  POST  /internal/site/legal/{document}/versions          nueva versión (copia de la última)
 *  POST  /internal/site/legal/versions/{id}/publish        { legal_reviewed_by?, confirm_unreviewed?, requires_reacceptance?, effective_from? }
 *  GET   /public/legal/{slug}                              página pública del documento
 */
class LegalDocumentsController extends Controller
{
    public function __construct(private readonly LegalDocumentsService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->list()]);
    }

    public function saveDraft(Request $request, string $version): Response
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:200000'],
            'summary_of_changes' => ['nullable', 'string', 'max:300'],
        ], ['content.required' => 'El documento no puede quedar vacío.']);
        $this->service->saveDraft($version, $data['content'], $data['summary_of_changes'] ?? null, $request->user()->id);

        return response()->noContent();
    }

    public function newVersion(Request $request, string $document): JsonResponse
    {
        return response()->json(['data' => $this->service->newVersion($document, $request->user()->id)], 201);
    }

    public function publish(Request $request, string $version): Response
    {
        $data = $request->validate([
            'legal_reviewed_by' => ['nullable', 'string', 'max:120'],
            'confirm_unreviewed' => ['boolean'],
            'requires_reacceptance' => ['boolean'],
            'effective_from' => ['nullable', 'date', 'after_or_equal:today'],
        ], ['effective_from.after_or_equal' => 'La vigencia no puede empezar en el pasado.']);
        $this->service->publish($version, $data, $request->user()->id);

        return response()->noContent();
    }

    public function publicPage(string $slug): JsonResponse
    {
        return response()->json(['data' => $this->service->publicPage($slug)])->header('Cache-Control', 'public, max-age=60');
    }
}
