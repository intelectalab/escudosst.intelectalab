<?php

namespace App\Modules\INS\Http;

use App\Http\Controllers\Controller;
use App\Modules\INS\Services\InspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ejecución de inspecciones (M-INS), con empresa activa:
 *
 *  GET  /ins/inspections/{id}/checklist   ítems de la plantilla con sus respuestas y hallazgos
 *  POST /ins/inspections/{id}/answers     guardar respuestas {answers: [{item_id, answer, comment}]}
 *  POST /ins/inspections/{id}/finish      finalizar: puntaje y hallazgos {summary}
 *  POST /ins/findings/{id}/action         acción correctiva desde un hallazgo {title, responsible_user_id, due_on}
 */
class InspectionController extends Controller
{
    public function __construct(private InspectionService $service) {}

    public function checklist(string $inspection): JsonResponse
    {
        return response()->json(['data' => $this->service->checklist($inspection)]);
    }

    public function answers(Request $request, string $inspection): JsonResponse
    {
        $answers = $request->input('answers');

        return response()->json(['data' => $this->service->answer($inspection, is_array($answers) ? $answers : [])]);
    }

    public function finish(Request $request, string $inspection): JsonResponse
    {
        return response()->json(['data' => $this->service->finish($inspection, $request->input('summary'))]);
    }

    public function action(Request $request, string $finding): JsonResponse
    {
        return response()->json(['data' => $this->service->actionFromFinding($finding, $request->all())], 201);
    }
}
