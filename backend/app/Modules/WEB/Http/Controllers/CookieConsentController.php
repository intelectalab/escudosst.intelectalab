<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Preferencias de cookies (SCR-WEB-16). Cada elección del visitante queda como una fila nueva (prueba del
 * consentimiento, con la versión vigente de la Política de cookies). El visitante es anónimo: un id aleatorio del navegador.
 *  POST /public/cookie-consent
 */
class CookieConsentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_id' => ['required', 'uuid'],
            'action' => ['required', 'in:accept_all,reject_all,custom,withdraw'],
            'categories' => ['present', 'array'],
            'categories.preferences' => ['boolean'],
            'categories.analytics' => ['boolean'],
            'categories.marketing' => ['boolean'],
        ]);
        $r = json_decode(DB::selectOne(
            "select public_record_cookie_consent(?::uuid, ?::jsonb, ?, null, decode(?, 'hex'), ?) as r",
            [
                $data['visitor_id'], json_encode((object) $data['categories']), $data['action'],
                hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), mb_substr((string) $request->userAgent(), 0, 300),
            ]
        )->r, true);

        return response()->json(['data' => $r], 201);
    }
}
