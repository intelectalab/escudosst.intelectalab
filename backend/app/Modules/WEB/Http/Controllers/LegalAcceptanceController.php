<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Re-aceptación de políticas (SCR-WEB-24, RN de versiones): cuando el dueño publica una versión nueva marcando
 * «pedir que la vuelvan a aceptar», cada usuario la ve al entrar al sistema y debe aceptarla para seguir.
 * Queda la prueba en legal_acceptances (versión exacta, fecha, huella de IP).
 *  GET  /me/legal-pending   documentos por aceptar del usuario de la sesión
 *  POST /me/legal-accept    { version_ids: [...] }
 */
class LegalAcceptanceController extends Controller
{
    /** Corre en la conexión de la plataforma con app.user_id fijado solo dentro de la transacción. */
    private function asUser(string $userId, \Closure $fn): mixed
    {
        $db = DB::connection('platform');

        return $db->transaction(function () use ($db, $userId, $fn) {
            $db->select("select set_config('app.user_id', ?, true)", [$userId]);

            return $fn($db);
        });
    }

    public function pending(Request $request): JsonResponse
    {
        $rows = $this->asUser($request->user()->id, fn ($db) => $db->select(
            "select p.doc_type, p.title, p.version_id, p.version_label, p.effective_from, p.is_reacceptance, d.slug
               from app_pending_legal_acceptances() p join legal_documents d on d.doc_type = p.doc_type and d.deleted_at is null"
        ));

        return response()->json(['data' => $rows]);
    }

    public function accept(Request $request): Response
    {
        $data = $request->validate(['version_ids' => ['required', 'array', 'min:1', 'max:10'], 'version_ids.*' => ['uuid']]);
        $user = $request->user()->id;
        $ip = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        $this->asUser($user, function ($db) use ($data, $user, $ip, $request) {
            $pending = collect($db->select('select version_id, is_reacceptance from app_pending_legal_acceptances()'))->keyBy('version_id');
            foreach (array_unique($data['version_ids']) as $id) {
                if (! $pending->has($id)) {
                    throw ValidationException::withMessages(['version_ids' => 'Ese documento ya no está pendiente. Recarga la página.']);
                }
                $db->select(
                    "select public_record_legal_acceptance(?::uuid, ?, ?::uuid, null, null, null, null, decode(?, 'hex'), ?)",
                    [$id, $pending[$id]->is_reacceptance ? 'reacceptance' : 'signup', $user, $ip, mb_substr((string) $request->userAgent(), 0, 300)]
                );
            }
        });

        return response()->noContent();
    }
}
