<?php

namespace App\Modules\ACT;

use App\Core\Support\Providers;
use App\Http\Controllers\Controller;
use App\Modules\NOT\WhatsAppCloud;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook de la API Cloud de WhatsApp (Meta), configurado en Panel › Proveedores y claves › WhatsApp:
 *  GET  /webhooks/whatsapp   verificación de Meta (hub.mode=subscribe, hub.verify_token, hub.challenge)
 *  POST /webhooks/whatsapp   mensajes entrantes; se acepta solo con la firma X-Hub-Signature-256 (secreto de la app de Meta)
 * Los mensajes van a la conversación de reportes (WhatsAppIntake). Mientras WhatsApp no esté en vivo, no se procesa nada.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $p = Providers::get('whatsapp');
        $expected = trim((string) ($p['settings']['verify_token'] ?? ''));
        if ($request->query('hub_mode') === 'subscribe' && $expected !== '' && hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        $p = Providers::get('whatsapp');
        $secret = (string) ($p['secrets']['app_secret'] ?? '');
        $sig = (string) $request->header('X-Hub-Signature-256', '');
        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $sig)) {
            return response()->json(['ok' => false], 403);
        }
        $wa = new WhatsAppCloud($p);
        if (! $wa->ready()) {
            return response()->json(['ok' => true]);   // recibido, pero WhatsApp no está en vivo
        }
        $intake = new WhatsAppIntake($wa);
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['messages'] ?? []) as $msg) {
                    try {
                        $intake->handle((array) $msg);
                    } catch (Throwable $e) {
                        Log::warning('WhatsApp: no se pudo atender un mensaje', ['error' => mb_substr($e->getMessage(), 0, 200)]);
                    }
                }
            }
        }

        // Meta solo necesita un 200 rápido; si no lo recibe, reintenta.
        return response()->json(['ok' => true]);
    }
}
