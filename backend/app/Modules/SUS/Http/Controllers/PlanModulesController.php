<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\PlanModulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel interno · Planes y módulos (SCR-SUS-01, permiso sus.plan_modules.update).
 *
 *  GET /internal/plans                               matriz de módulos por plan y complementos
 *  PUT /internal/plans/{plan}/modules/{module}       { "included": true|false }
 */
class PlanModulesController extends Controller
{
    public function __construct(private readonly PlanModulesService $service) {}

    public function index(): JsonResponse
    {
        return response()->json($this->service->matrix());
    }

    public function update(Request $request, string $plan, string $module): JsonResponse
    {
        if (! is_bool($request->input('included'))) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', null,
                ['errors' => ['included' => ['Indica si el módulo se incluye (true) o no (false).']]]);
        }

        $result = $this->service->setIncluded($plan, $module, $request->boolean('included'), $request->user()->id);

        return response()->json($result + ['matrix' => $this->service->matrix()]);
    }
}
