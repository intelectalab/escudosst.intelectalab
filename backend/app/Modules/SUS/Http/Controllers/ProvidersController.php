<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Core\Support\Providers;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Panel › Proveedores y claves: IA, pagos, facturación electrónica, WhatsApp y firma digital.
 *  GET  /internal/providers               lo configurado (las claves nunca se devuelven: solo sus últimos 4 caracteres)
 *  PUT  /internal/providers/{code}        {provider, mode, settings{}, secrets{}} (un secreto vacío conserva el anterior)
 *  POST /internal/providers/{code}/test   prueba la conexión cuando es posible (IA)
 *  GET  /providers/status                 para la app de la empresa: qué servicios están en modo de prueba
 */
class ProvidersController extends Controller
{
    public function index(): JsonResponse
    {
        $out = [];
        foreach (Providers::CATALOG as $code => [$name, $options, $fields, $secretFields, $what, $test]) {
            $r = DB::connection('platform')->selectOne('select * from platform_providers where code = ?', [$code]);
            $p = Providers::get($code);
            $out[] = [
                'code' => $code, 'name' => $name, 'what' => $what, 'test_note' => $test, 'options' => $options, 'fields' => $fields, 'secret_fields' => $secretFields,
                'provider' => $p['provider'], 'mode' => $p['mode'], 'settings' => $p['settings'], 'live' => $p['live'],
                'secrets_set' => array_map(fn ($v) => $v ? '••••'.substr((string) $v, -4) : null, array_intersect_key($p['secrets'], $secretFields)),
                'last_test' => $r?->last_tested_at ? ['at' => $r->last_tested_at, 'ok' => (bool) $r->last_test_ok, 'message' => $r->last_test_message] : null,
                'updated_at' => $r?->updated_at,
            ];
        }

        return response()->json(['data' => $out]);
    }

    public function update(Request $request, string $code, AccountsService $accounts): JsonResponse
    {
        $c = Providers::CATALOG[$code] ?? throw ApiProblem::notFound('Proveedor desconocido.');
        $d = $request->validate([
            'provider' => ['nullable', 'in:'.implode(',', array_keys($c[1]))],
            'mode' => ['required', 'in:test,live'],
            'settings' => ['array'],
            'settings.*' => ['nullable', 'string', 'max:200'],
            'secrets' => ['array'],
            'secrets.*' => ['nullable', 'string', 'max:500'],
        ], ['provider.in' => 'Elige un proveedor de la lista.']);
        $current = Providers::get($code);
        $secrets = $current['secrets'];
        foreach (array_keys($c[3]) as $k) {
            $v = trim((string) ($d['secrets'][$k] ?? ''));
            if ($v !== '') {
                $secrets[$k] = $v;   // vacío = se conserva la anterior
            }
        }
        if (($d['mode'] === 'live') && (! ($d['provider'] ?? null) || array_diff(array_keys($c[3]), array_keys(array_filter($secrets))))) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Para pasar a «en vivo» elige el proveedor y completa sus claves.',
                ['errors' => ['mode' => ['Para pasar a «en vivo» elige el proveedor y completa sus claves.']]]);
        }
        $settings = array_intersect_key(array_map(fn ($v) => trim((string) $v), $d['settings'] ?? []), $c[2]);
        DB::connection('platform')->update(
            'update platform_providers set provider = ?, mode = ?, settings = ?::jsonb, secret_enc = ?, secret_hint = ?, updated_at = now(), updated_by = ? where code = ?',
            [$d['provider'] ?? null, $d['mode'], json_encode((object) $settings), $secrets ? Crypt::encryptString(json_encode($secrets)) : null,
                $secrets ? implode(',', array_keys($secrets)) : null, $request->user()->id, $code]
        );
        // En la bitácora: qué cambió, nunca las claves.
        $accounts->audit($request->user()->id, 'platform_provider_updated', 'platform_providers', null, ['code' => $code, 'provider' => $d['provider'] ?? null, 'mode' => $d['mode'],
            'secrets_changed' => array_keys(array_filter($d['secrets'] ?? []))]);

        return response()->json(['data' => ['live' => Providers::live($code)]]);
    }

    public function test(Request $request, string $code): JsonResponse
    {
        Providers::CATALOG[$code] ?? throw ApiProblem::notFound('Proveedor desconocido.');
        $p = Providers::get($code);
        [$ok, $msg] = match (true) {
            ! $p['provider'] => [false, 'Elige el proveedor primero.'],
            $code === 'ai' && $p['provider'] === 'anthropic' => $this->testAnthropic($p),
            default => [null, 'La prueba automática no está disponible para este proveedor: verifica con una operación real en modo de prueba.'],
        };
        if ($ok !== null) {
            DB::connection('platform')->update('update platform_providers set last_tested_at = now(), last_test_ok = ?, last_test_message = ? where code = ?', [$ok ? 'true' : 'false', $msg, $code]);
        }

        return response()->json(['data' => ['ok' => $ok, 'message' => $msg]]);
    }

    public function status(): JsonResponse
    {
        return response()->json(['data' => Providers::status()]);
    }

    /** @return array{0: bool, 1: string} */
    private function testAnthropic(array $p): array
    {
        $key = $p['secrets']['api_key'] ?? null;
        if (! $key) {
            return [false, 'Falta la clave de la API.'];
        }
        try {
            $r = Http::timeout(20)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->post('https://api.anthropic.com/v1/messages', [
                'model' => $p['settings']['model'] ?? 'claude-sonnet-5-5', 'max_tokens' => 16, 'messages' => [['role' => 'user', 'content' => 'Responde solo: ok']],
            ]);

            return $r->successful() ? [true, 'Conexión correcta.'] : [false, 'El proveedor respondió '.$r->status().': '.mb_substr((string) ($r->json('error.message') ?? $r->body()), 0, 200)];
        } catch (\Throwable $e) {
            return [false, 'No se pudo conectar: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
