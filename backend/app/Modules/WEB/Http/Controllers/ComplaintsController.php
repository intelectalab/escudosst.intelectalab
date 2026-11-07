<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WEB\Services\ComplaintsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Libro de Reclamaciones (SCR-WEB-17, 18 y 25).
 *  Público:  GET  /public/complaints/info     datos del proveedor y plazo de respuesta
 *            POST /public/complaints          registra la hoja y devuelve la constancia
 *            POST /public/complaints/status   seguimiento con código + clave
 *  Panel:    GET  /internal/site/complaints               bandeja con semáforo
 *            GET  /internal/site/complaints/{id}          ficha con datos del consumidor e historial
 *            POST /internal/site/complaints/{id}/actions  iniciar, prorrogar, responder, cerrar o anotar
 */
class ComplaintsController extends Controller
{
    public function __construct(private readonly ComplaintsService $service) {}

    public function info(): JsonResponse
    {
        return response()->json(['data' => $this->service->publicInfo()]);
    }

    public function store(Request $request): JsonResponse
    {
        if (filled($request->input('website'))) {   // campo trampa para bots
            return response()->json(['data' => ['complaint_code' => null]], 201);
        }
        $minor = $request->boolean('is_minor');
        $data = $request->validate([
            'consumer_full_name' => ['required', 'string', 'min:3', 'max:160'],
            'consumer_document_type' => ['required', 'in:DNI,CE,PASSPORT,RUC,OTHER'],
            'consumer_document_number' => ['required', 'string', 'max:20', $this->documentRule($request->input('consumer_document_type'))],
            'consumer_address' => ['required', 'string', 'min:5', 'max:250'],
            'consumer_email' => ['required', 'email:rfc', 'max:160'],
            'consumer_phone' => ['nullable', 'string', 'max:30'],
            'is_minor' => ['boolean'],
            'guardian_full_name' => [$minor ? 'required' : 'nullable', 'string', 'max:160'],
            'guardian_document_type' => [$minor ? 'required' : 'nullable', 'in:DNI,CE,PASSPORT,OTHER'],
            'guardian_document_number' => [$minor ? 'required' : 'nullable', 'string', 'max:20'],
            'guardian_email' => ['nullable', 'email:rfc', 'max:160'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'item_type' => ['required', 'in:product,service'],
            'item_description' => ['required', 'string', 'min:3', 'max:500'],
            'amount_claimed' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'complaint_type' => ['required', 'in:claim,complaint'],
            'detail' => ['required', 'string', 'min:10', 'max:4000'],
            'consumer_request' => ['required', 'string', 'min:3', 'max:2000'],
            'privacy_consent' => ['accepted'],
        ], [
            'required' => 'Completa este campo.',
            'accepted' => 'Debes aceptar para continuar.',
            'email' => 'Escribe un correo válido.',
            'min' => 'Es muy corto.',
            'detail.min' => 'Cuéntanos un poco más (al menos 10 caracteres).',
            'max' => 'Es muy largo.',
            'in' => 'Elige una opción de la lista.',
            'numeric' => 'Escribe un monto válido.',
            'consumer_document_number.regex' => 'Revisa el número de documento.',
        ]);
        unset($data['privacy_consent']);
        $data['is_minor'] = $minor;

        $r = $this->service->submit($data, hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')));

        return response()->json(['data' => $r], 201);
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['code' => ['required', 'string', 'max:20'], 'key' => ['required', 'string', 'max:40']],
            ['required' => 'Completa este campo.']
        );

        return response()->json(['data' => $this->service->status($data['code'], $data['key'])]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->list(), 'rules' => $this->service->rules()]);
    }

    /** Reporte en CSV (se abre con Excel): una fila por hoja, sin datos personales. */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $rows = $this->service->report($data['from'] ?? null, $data['to'] ?? null);
        $name = 'libro-de-reclamaciones-'.now('America/Lima')->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "ï»¿");   // BOM: Excel lee bien las tildes
            foreach ($rows as $r) {
                fputcsv($out, $r, ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->detail($id)]);
    }

    public function act(Request $request, string $id): Response
    {
        $data = $request->validate([
            'action' => ['required', 'in:start,extend,answer,close,note'],
            'text' => ['nullable', 'required_if:action,extend,answer,note', 'string', 'max:6000'],
            'until' => ['nullable', 'required_if:action,extend', 'date'],
            'channel' => ['nullable', 'in:email,address,other'],
        ], [
            'text.required_if' => 'Escribe el texto.',
            'until.required_if' => 'Indica la nueva fecha límite.',
        ]);
        $this->service->act($id, $data, $request->user()->id);

        return response()->noContent();
    }

    private function documentRule(?string $type): string
    {
        return match ($type) {
            'DNI' => 'regex:/^\d{8}$/',
            'RUC' => 'regex:/^(10|15|17|20)\d{9}$/',
            default => 'regex:/^[A-Za-z0-9-]{4,20}$/',
        };
    }
}
