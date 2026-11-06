<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\CatalogsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Panel del dueño · Catálogos globales (SCR-SUS-08).
 *
 *  GET   /internal/catalogs                                    resumen
 *  GET   /internal/catalogs/legal-parameters                   todas las versiones
 *  POST  /internal/catalogs/legal-parameters/{id}/versions     { value, valid_from, legal_reference?, reason }
 *  POST  /internal/catalogs/legal-parameters/{id}/confirm      { confirmed_by }
 *  GET   /internal/catalogs/legal-norms            POST, PATCH /{id}
 *  GET   /internal/catalogs/hazards                POST, PATCH /{id}
 *  GET   /internal/catalogs/sector-packs           PATCH /{id} { status }
 *  GET   /internal/catalogs/holidays               POST, PATCH /{id}, DELETE /{id}
 */
class CatalogsController extends Controller
{
    private const SECTORS = 'construction,mining,agroindustry,manufacturing,retail,services,office,health,education,transport,energy,other';

    public function __construct(private readonly CatalogsService $service) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->service->summary()]);
    }

    public function legalParameters(): JsonResponse
    {
        return response()->json(['data' => $this->service->legalParameters()]);
    }

    public function newVersion(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'string', 'max:2000'],
            'valid_from' => ['required', 'date'],
            'legal_reference' => ['nullable', 'string', 'max:200'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], [
            'value.required' => 'Escribe el nuevo valor.',
            'valid_from.required' => 'Indica desde cuándo rige.',
            'reason.required' => 'Explica por qué cambia (por ejemplo, la norma que lo modifica).',
            'reason.min' => 'Detalla un poco más el motivo.',
        ]);

        return response()->json(['data' => $this->service->newParameterVersion($id, $data, $request->user()->id)], 201);
    }

    public function confirm(Request $request, string $id): Response
    {
        $data = $request->validate(
            ['confirmed_by' => ['required', 'string', 'min:3', 'max:120']],
            ['confirmed_by.required' => 'Escribe el nombre del especialista que validó el valor.']
        );
        $this->service->confirmParameter($id, trim($data['confirmed_by']), $request->user()->id);

        return response()->noContent();
    }

    public function legalNorms(): JsonResponse
    {
        return response()->json(['data' => $this->service->legalNorms()]);
    }

    public function saveNorm(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'norm_type' => ['required', 'in:law,supreme_decree,ministerial_resolution,technical_standard,ordinance,directive,other'],
            'number' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'min:5', 'max:300'],
            'issuing_entity' => ['nullable', 'string', 'max:120'],
            'published_on' => ['nullable', 'date'],
            'effective_on' => ['nullable', 'date'],
            'repealed_on' => ['nullable', 'date', 'required_if:status,repealed'],
            'scope' => ['required', 'in:general,sector'],
            'sector' => ['nullable', 'required_if:scope,sector', 'in:'.self::SECTORS],
            'summary' => ['nullable', 'string', 'max:1000'],
            'official_url' => ['nullable', 'url', 'max:300'],
            'status' => ['required', 'in:current,amended,repealed'],
        ], [
            'number.required' => 'Escribe el número de la norma.',
            'title.required' => 'Escribe el título.',
            'repealed_on.required_if' => 'Indica la fecha de derogación.',
            'sector.required_if' => 'Elige el sector.',
            'official_url.url' => 'El enlace no es válido (debe empezar con https://).',
        ]);

        return response()->json(['data' => $this->service->saveNorm($id, $data, $request->user()->id)], $id ? 200 : 201);
    }

    public function hazards(): JsonResponse
    {
        return response()->json(['data' => $this->service->hazards()]);
    }

    public function saveHazard(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'code' => [$id ? 'nullable' : 'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
            'hazard_family' => ['required', 'in:physical,chemical,biological,ergonomic,psychosocial,mechanical,electrical,locative,fire_explosion,natural,public_safety,vehicular,other'],
            'hazard' => ['required', 'string', 'min:3', 'max:200'],
            'risk' => ['required', 'string', 'min:3', 'max:200'],
            'possible_consequence' => ['nullable', 'string', 'max:300'],
            'typical_controls' => ['array', 'max:12'],
            'typical_controls.*' => ['nullable', 'string', 'max:200'],
            'sector' => ['nullable', 'in:'.self::SECTORS],
            'is_active' => ['boolean'],
        ], [
            'code.required' => 'Escribe un código (por ejemplo, MEC-07).',
            'code.regex' => 'Usa solo letras, números y guiones.',
            'hazard.required' => 'Describe el peligro.',
            'risk.required' => 'Describe el riesgo.',
        ]);

        return response()->json(['data' => $this->service->saveHazard($id, $data, $request->user()->id)], $id ? 200 : 201);
    }

    public function sectorPacks(): JsonResponse
    {
        return response()->json(['data' => $this->service->sectorPacks()]);
    }

    public function packStatus(Request $request, string $id): Response
    {
        $data = $request->validate(['status' => ['required', 'in:draft,published,retired']]);
        $this->service->setPackStatus($id, $data['status'], $request->user()->id);

        return response()->noContent();
    }

    public function holidays(): JsonResponse
    {
        return response()->json(['data' => $this->service->holidays()]);
    }

    public function createHoliday(Request $request): JsonResponse
    {
        $data = $request->validate([
            'holiday_on' => ['required', 'date'],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'is_non_working' => ['boolean'],
        ], ['holiday_on.required' => 'Elige la fecha.', 'name.required' => 'Escribe el nombre del feriado.']);

        return response()->json(['data' => $this->service->createHoliday($data, $request->user()->id)], 201);
    }

    public function updateHoliday(Request $request, string $id): Response
    {
        $data = $request->validate(['to_confirm' => ['boolean'], 'is_non_working' => ['boolean']]);
        $this->service->updateHoliday($id, $data, $request->user()->id);

        return response()->noContent();
    }

    public function deleteHoliday(Request $request, string $id): Response
    {
        $this->service->deleteHoliday($id, $request->user()->id);

        return response()->noContent();
    }
}
