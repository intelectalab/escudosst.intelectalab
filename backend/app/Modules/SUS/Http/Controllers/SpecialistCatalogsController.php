<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\SpecialistCatalogsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Panel del dueño · Catálogos globales (SCR-SUS-08): contenido que valida un especialista SST/legal.
 * Lee el equipo de la plataforma; cambia solo el Dueño (middleware platform.owner en las rutas).
 *
 *  GET    /internal/catalogs/legal-parameters/{id}/companies              valores propios de empresas
 *  PUT    /internal/catalogs/legal-parameters/{id}/companies/{company}    { value, reason }
 *  DELETE /internal/catalogs/legal-parameters/{id}/companies/{company}    { reason? }  (vuelve a regir el global)
 *  GET    /internal/catalogs/legal-norms/{id}/requirements                requisitos del catálogo de la norma (con archivados)
 *  POST   /internal/catalogs/legal-norms/{id}/requirements                { article?, requirement, topic?, applicability?, evidence_expected?, frequency? }
 *  PATCH  /internal/catalogs/norm-requirements/{id}                       mismos campos (vuelve a «por confirmar»)
 *  POST   /internal/catalogs/norm-requirements/{id}/confirm               { confirmed_by }
 *  POST   /internal/catalogs/norm-requirements/{id}/archive|restore       { reason? }
 *  GET    /internal/catalogs/scat                                         catálogo SCAT completo (con inactivos)
 *  POST   /internal/catalogs/scat                                         { level, code, label, explanation?, category?, is_active? }
 *  PATCH  /internal/catalogs/scat/{id}                                    { label, explanation?, category?, is_active? }
 *  POST   /internal/catalogs/scat/{id}/confirm                            { confirmed_by }
 *  POST   /internal/catalogs/scat/reorder                                 { level, ids[] }
 */
class SpecialistCatalogsController extends Controller
{
    private const LEVELS = 'contact,immediate_act,immediate_condition,basic_personal,basic_job,nac';

    public function __construct(private readonly SpecialistCatalogsService $service) {}

    public function overrides(string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->overrides($id)]);
    }

    public function setOverride(Request $request, string $id, string $company): JsonResponse
    {
        $d = $request->validate([
            'value' => ['required', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], [
            'value.required' => 'Escribe el valor para esta empresa.',
            'reason.required' => 'Explica por qué esta empresa usa un valor propio.',
            'reason.min' => 'Detalla un poco más el motivo.',
        ]);

        return response()->json(['data' => $this->service->setOverride($id, $company, $d['value'], trim($d['reason']), $request->user()->id)]);
    }

    public function removeOverride(Request $request, string $id, string $company): Response
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $this->service->removeOverride($id, $company, $d['reason'] ?? null, $request->user()->id);

        return response()->noContent();
    }

    public function requirements(string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->normRequirements($id)]);
    }

    public function createRequirement(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->createRequirement($id, $this->requirementInput($request), $request->user()->id)], 201);
    }

    public function updateRequirement(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->updateRequirement($id, $this->requirementInput($request), $request->user()->id)]);
    }

    public function confirmRequirement(Request $request, string $id): Response
    {
        $this->service->confirmRequirement($id, $this->confirmedBy($request), $request->user()->id);

        return response()->noContent();
    }

    public function archiveRequirement(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        return response()->json(['data' => $this->service->archiveRequirement($id, true, $d['reason'] ?? null, $request->user()->id)]);
    }

    public function restoreRequirement(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        return response()->json(['data' => $this->service->archiveRequirement($id, false, $d['reason'] ?? null, $request->user()->id)]);
    }

    public function scat(): JsonResponse
    {
        return response()->json(['data' => $this->service->scat()]);
    }

    public function createScat(Request $request): JsonResponse
    {
        $d = $request->validate([
            'level' => ['required', 'in:'.self::LEVELS],
            'code' => ['required', 'string', 'max:8'],
        ] + $this->scatRules(), $this->scatMessages() + ['code.required' => 'Escribe el código (por ejemplo, A17).', 'level.required' => 'Elige el nivel.']);

        return response()->json(['data' => $this->service->createScat($d, $request->user()->id)], 201);
    }

    public function updateScat(Request $request, string $id): JsonResponse
    {
        $d = $request->validate($this->scatRules(), $this->scatMessages());

        return response()->json(['data' => $this->service->updateScat($id, $d, $request->user()->id)]);
    }

    public function confirmScat(Request $request, string $id): Response
    {
        $this->service->confirmScat($id, $this->confirmedBy($request), $request->user()->id);

        return response()->noContent();
    }

    public function reorderScat(Request $request): Response
    {
        $d = $request->validate([
            'level' => ['required', 'in:'.self::LEVELS],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['uuid', 'distinct'],
        ]);
        $this->service->reorderScat($d['level'], $d['ids'], $request->user()->id);

        return response()->noContent();
    }

    private function requirementInput(Request $request): array
    {
        return $request->validate([
            'article' => ['nullable', 'string', 'max:60'],
            'requirement' => ['required', 'string', 'min:10', 'max:1000'],
            'topic' => ['nullable', 'string', 'max:60'],
            'applicability' => ['nullable', 'string', 'max:300'],
            'evidence_expected' => ['nullable', 'string', 'max:300'],
            'frequency' => ['nullable', 'string', 'max:80'],
        ], [
            'requirement.required' => 'Escribe el requisito.',
            'requirement.min' => 'Describe el requisito con un poco más de detalle.',
        ]);
    }

    private function scatRules(): array
    {
        return [
            'label' => ['required', 'string', 'min:3', 'max:200'],
            'explanation' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:80'],
            'is_active' => ['boolean'],
        ];
    }

    private function scatMessages(): array
    {
        return ['label.required' => 'Escribe el texto que verá quien investiga.', 'label.min' => 'El texto es muy corto.'];
    }

    private function confirmedBy(Request $request): string
    {
        return trim($request->validate(
            ['confirmed_by' => ['required', 'string', 'min:3', 'max:120']],
            ['confirmed_by.required' => 'Escribe el nombre del especialista que lo validó.']
        )['confirmed_by']);
    }
}
