<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Panel interno · acciones sobre una suscripción (SCR-SUS-02; permiso sus.subscriptions.update).
 *
 *  POST   /internal/subscriptions/{id}/change-plan      { plan, reason? }
 *  POST   /internal/subscriptions/{id}/suspend          { reason }
 *  POST   /internal/subscriptions/{id}/reactivate       { reason? }
 *  POST   /internal/subscriptions/{id}/addons           { addons: [códigos] }
 *  DELETE /internal/subscriptions/{id}/addons/{addon}   { reason? }
 *  POST   /internal/subscriptions/{id}/payments         { amount, method, paid_at, reference?, periods, note? }
 */
class SubscriptionsController extends Controller
{
    public function __construct(private readonly SubscriptionService $service) {}

    public function changePlan(Request $request, string $subscription): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'in:E,P,C'],
            'reason' => ['nullable', 'string', 'max:300'],
        ], ['plan.required' => 'Elige el nuevo plan.', 'plan.in' => 'Elige Esencial, Profesional o Corporativo.']);

        return response()->json(['data' => $this->service->changePlan($subscription, $data['plan'], $data['reason'] ?? null, $request->user()->id)]);
    }

    public function suspend(Request $request, string $subscription): Response
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:300']],
            ['reason.required' => 'Escribe el motivo de la suspensión.', 'reason.min' => 'Detalla un poco más el motivo.']
        );
        $this->service->suspend($subscription, $data['reason'], $request->user()->id);

        return response()->noContent();
    }

    public function reactivate(Request $request, string $subscription): Response
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $this->service->reactivate($subscription, $data['reason'] ?? null, $request->user()->id);

        return response()->noContent();
    }

    public function addAddon(Request $request, string $subscription): JsonResponse
    {
        $data = $request->validate(
            ['addons' => ['required', 'array', 'min:1', 'max:10'], 'addons.*' => ['string', 'size:3', 'distinct']],
            ['addons.required' => 'Elige al menos un complemento.', 'addons.min' => 'Elige al menos un complemento.']
        );

        return response()->json(['data' => ['added' => $this->service->addAddons($subscription, $data['addons'], $request->user()->id)]]);
    }

    public function removeAddon(Request $request, string $subscription, string $addon): Response
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $this->service->removeAddon($subscription, $addon, $data['reason'] ?? null, $request->user()->id);

        return response()->noContent();
    }

    public function registerPayment(Request $request, string $subscription): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'method' => ['required', 'in:transfer,deposit,yape,plin,card,other'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:60'],
            'periods' => ['required', 'integer', 'min:1', 'max:12'],
            'note' => ['nullable', 'string', 'max:300'],
        ], [
            'amount.required' => 'Escribe el monto pagado.',
            'amount.numeric' => 'El monto debe ser un número.',
            'amount.min' => 'El monto debe ser mayor que cero.',
            'method.required' => 'Elige el medio de pago.',
            'paid_at.required' => 'Indica la fecha del pago.',
            'paid_at.before_or_equal' => 'La fecha del pago no puede ser futura.',
            'periods.required' => 'Indica cuántos periodos cubre el pago.',
            'periods.min' => 'Debe cubrir al menos un periodo.',
            'periods.max' => 'Hasta 12 periodos por pago.',
        ]);

        return response()->json(['data' => $this->service->registerPayment($subscription, $data, $request->user()->id)], 201);
    }
}
