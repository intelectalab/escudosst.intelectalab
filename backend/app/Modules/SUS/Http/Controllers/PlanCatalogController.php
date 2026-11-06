<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\PlanCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Panel interno · datos de planes y complementos (SCR-SUS-01; permisos sus.plans.update, sus.addons.update).
 *
 *  PATCH /internal/plans/{plan}     nombre, descripción, límites, precio, periodos, visible, activo
 *  PATCH /internal/addons/{addon}   nombre, descripción, precio, activo
 */
class PlanCatalogController extends Controller
{
    public function __construct(private readonly PlanCatalogService $service) {}

    public function updatePlan(Request $request, string $plan): Response
    {
        $limitRules = [];
        foreach (PlanCatalogService::LIMIT_FIELDS as $f) {
            $limitRules['limits.'.$f] = ['nullable', 'integer', 'min:0', 'max:10000000'];
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'price_reference' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'billing_periods' => ['required', 'array', 'min:1'],
            'billing_periods.*' => ['in:monthly,annual', 'distinct'],
            'is_public' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'limits' => ['array'],
        ] + $limitRules, [
            'name.required' => 'Escribe el nombre del plan.',
            'name.max' => 'El nombre puede tener hasta 60 caracteres.',
            'description.max' => 'La descripción puede tener hasta 500 caracteres.',
            'price_reference.numeric' => 'El precio debe ser un número.',
            'price_reference.min' => 'El precio no puede ser negativo.',
            'billing_periods.required' => 'Elige al menos un periodo de cobro.',
            'billing_periods.min' => 'Elige al menos un periodo de cobro.',
            'limits.*.integer' => 'Los límites deben ser números enteros.',
            'limits.*.min' => 'Los límites no pueden ser negativos.',
        ]);

        $this->service->updatePlan($plan, $data, $request->user()->id);

        return response()->noContent();
    }

    public function updateAddon(Request $request, string $addon): Response
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'price_reference' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'is_active' => ['required', 'boolean'],
        ], [
            'name.required' => 'Escribe el nombre del complemento.',
            'price_reference.numeric' => 'El precio debe ser un número.',
            'price_reference.min' => 'El precio no puede ser negativo.',
        ]);

        $this->service->updateAddon($addon, $data, $request->user()->id);

        return response()->noContent();
    }
}
