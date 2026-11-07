<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WEB\Services\SalesQuotesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Cotizaciones (venta con pago manual). Ver SalesQuotesService para la secuencia completa.
 *
 * Panel (equipo de la plataforma):
 *  GET   /internal/site/quotes                         lista
 *  GET   /internal/site/quotes/settings | PUT           datos para el pago y vigencia por defecto
 *  GET   /internal/site/quotes/new?request={id}         datos para empezar (desde una solicitud)
 *  POST  /internal/site/quotes/preview                  calcula montos sin guardar
 *  POST  /internal/site/quotes | PUT /{id}              guarda (borrador)
 *  GET   /internal/site/quotes/{id}
 *  POST  /internal/site/quotes/{id}/send | cancel | reject-evidence | confirm-payment
 *  GET   /internal/site/quotes/{id}/file/{pdf|evidence}
 *
 * Cliente (enlace personal de 48 caracteres):
 *  GET   /public/quotes/{token}  ·  GET /public/quotes/{token}/pdf
 *  POST  /public/quotes/{token}/accept | reject | payment
 */
class SalesQuotesController extends Controller
{
    public function __construct(private readonly SalesQuotesService $quotes) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->quotes->list()]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->quotes->settings()]);
    }

    public function saveSettings(Request $request): Response
    {
        $d = $request->validate([
            'payment_instructions' => ['nullable', 'string', 'max:2000'],
            'quote_validity_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);
        $this->quotes->saveSettings($d, $request->user()->id);

        return response()->noContent();
    }

    public function prefill(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->quotes->prefill($request->query('request'))]);
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->quotes->preview($this->validated($request, preview: true))]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->quotes->show($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $id = $this->quotes->save(null, $this->validated($request), $request->user()->id);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id): Response
    {
        $this->quotes->save($id, $this->validated($request), $request->user()->id);

        return response()->noContent();
    }

    public function act(Request $request, string $id, string $action): JsonResponse|Response
    {
        $actor = $request->user()->id;
        switch ($action) {
            case 'send':
                $this->quotes->send($id, $actor);
                break;
            case 'cancel':
                $this->quotes->cancel($id, $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']], ['reason.required' => 'Escribe el motivo.'])['reason'], $actor);
                break;
            case 'reject-evidence':
                $this->quotes->rejectEvidence($id, $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']], ['reason.required' => 'Escribe el motivo para el cliente.'])['reason'], $actor);
                break;
            case 'confirm-payment':
                $d = $request->validate([
                    'paid_on' => ['required', 'date', 'before_or_equal:today'],
                    'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
                    'method' => ['required', 'in:'.implode(',', array_keys(SalesQuotesService::METHODS))],
                    'reference' => ['nullable', 'string', 'max:120'],
                ], ['paid_on.before_or_equal' => 'La fecha de pago no puede ser futura.']);

                return response()->json(['data' => $this->quotes->confirmPayment($id, $d, $actor)]);
        }

        return response()->noContent();
    }

    public function file(string $id, string $kind): Response
    {
        $f = $this->quotes->file($id, $kind);

        return $this->download($f);
    }

    // ------------------------------------------------------------------ Cliente

    public function publicShow(string $token): JsonResponse
    {
        return response()->json(['data' => $this->quotes->publicView($token)]);
    }

    public function publicPdf(string $token): Response
    {
        return $this->download($this->quotes->publicPdf($token));
    }

    public function accept(Request $request, string $token): Response
    {
        $request->validate(['accept' => ['accepted']], ['accept.accepted' => 'Marca que aceptas la cotización y sus condiciones.']);
        $this->quotes->accept($token, hash('sha256', (string) $request->ip()));

        return response()->noContent();
    }

    public function reject(Request $request, string $token): Response
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->quotes->reject($token, $d['reason'] ?? null);

        return response()->noContent();
    }

    public function payment(Request $request, string $token): Response
    {
        $d = $request->validate([
            'method' => ['required', 'in:'.implode(',', array_keys(SalesQuotesService::METHODS))],
            'paid_on' => ['required', 'date', 'before_or_equal:today', 'after:-120 days'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'reference' => ['nullable', 'string', 'max:120'],
            'file' => ['required', 'file', 'max:5120'],
        ], [
            'file.required' => 'Adjunta la constancia de pago.',
            'file.max' => 'La constancia no debe pasar de 5 MB.',
            'paid_on.before_or_equal' => 'La fecha de pago no puede ser futura.',
            'paid_on.after' => 'Revisa la fecha de pago.',
        ]);
        $this->quotes->submitPayment($token, $d, $request->file('file'));

        return response()->noContent();
    }

    // ------------------------------------------------------------------ Apoyo

    private function validated(Request $request, bool $preview = false): array
    {
        $req = $preview ? 'nullable' : 'required';

        return $request->validate([
            'demo_request_id' => ['nullable', 'uuid'],
            'contact_name' => [$req, 'string', 'min:3', 'max:120'],
            'contact_email' => [$req, 'email:rfc', 'max:160'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'billing_period' => ['required', 'in:monthly,annual'],
            'companies' => ['required', 'array', 'min:1', 'max:30'],
            'companies.*.ruc' => [$req, 'string', 'max:11'],
            'companies.*.legal_name' => [$req, 'string', 'min:2', 'max:160'],
            'companies.*.trade_name' => ['nullable', 'string', 'max:160'],
            'companies.*.headcount' => [$req, 'integer', 'min:1', 'max:1000000'],
            'companies.*.economic_sector' => ['nullable', 'string', 'max:40'],
            'companies.*.plan_code' => ['required', 'in:E,P,C'],
            'companies.*.addon_codes' => ['nullable', 'array', 'max:15'],
            'companies.*.addon_codes.*' => ['string', 'max:10'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'valid_until' => ['nullable', 'date', $preview ? 'nullable' : 'after_or_equal:today'],
            'client_notes' => ['nullable', 'string', 'max:3000'],
            'internal_notes' => ['nullable', 'string', 'max:3000'],
        ], [
            'companies.*.ruc.required' => 'Escribe el RUC.',
            'companies.*.legal_name.required' => 'Escribe la razón social.',
            'companies.*.headcount.required' => 'Indica cuántos trabajadores tiene.',
            'valid_until.after_or_equal' => 'La fecha de vigencia no puede ser pasada.',
        ]);
    }

    /** @param  array{content: string, mime: string, name: string}  $f */
    private function download(array $f): Response
    {
        return response($f['content'], 200, [
            'Content-Type' => $f['mime'],
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $f['name']).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
