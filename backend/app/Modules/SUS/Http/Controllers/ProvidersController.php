<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Core\Support\Providers;
use App\Http\Controllers\Controller;
use App\Modules\NOT\WhatsAppCloud;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\SUS\Services\RemoteBackupStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Panel › Proveedores y claves: pagos, facturación electrónica, WhatsApp, firma digital y copias de seguridad externas.
 *  GET  /internal/providers               lo configurado (las claves nunca se devuelven: solo «configurada · termina en …»),
 *                                         con ayudas, webhook para copiar y preguntas pendientes para el equipo
 *  PUT  /internal/providers/{code}        {provider, mode, settings{}, secrets{}} (un secreto vacío conserva el anterior)
 *  POST /internal/providers/{code}/test   prueba la conexión: copias externas (sube y borra un archivo) y WhatsApp
 *                                         (verifica el número; con {to} envía la plantilla de prueba)
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
            $m = Providers::META[$code] ?? [];
            $out[] = [
                'code' => $code, 'name' => $name, 'what' => $what, 'test_note' => $test, 'options' => $options, 'fields' => $fields, 'secret_fields' => $secretFields,
                'provider' => $p['provider'], 'mode' => $p['mode'], 'settings' => $p['settings'], 'live' => $p['live'],
                'secrets_set' => array_map(fn ($v) => $v ? '••••'.mb_substr((string) $v, -4) : null, array_intersect_key($p['secrets'], $secretFields)),
                'required' => $m['required'] ?? [], 'optional_secrets' => $m['optional_secrets'] ?? [], 'hints' => (object) ($m['hints'] ?? []), 'modes' => $m['modes'] ?? null,
                'pending' => (bool) ($m['pending'] ?? false), 'questions' => $m['questions'] ?? [], 'notice' => $m['notice'] ?? null,
                'webhook_url' => isset($m['webhook']) ? rtrim((string) config('app.url'), '/').$m['webhook'] : null, 'webhook_note' => $m['webhook_note'] ?? null,
                'testable' => in_array($code, ['backups', 'whatsapp'], true), 'test_input' => $m['test_input'] ?? null, 'test_label' => $m['test_label'] ?? null,
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
        $settings = array_intersect_key(array_map(fn ($v) => trim((string) $v), $d['settings'] ?? []), $c[2]);
        $this->checkSettings($code, $settings);
        $meta = Providers::META[$code] ?? [];
        if ($d['mode'] === 'live' && ($meta['pending'] ?? false)) {
            $this->fail('mode', 'Este servicio aún no está integrado: guarda los datos en modo de prueba. Se activará cuando el equipo defina el proveedor.');
        }
        if ($d['mode'] === 'live' && ! Providers::complete($code, $d['provider'] ?? null, $settings, $secrets)) {
            $missing = array_map(fn ($k) => $c[2][$k], array_filter($meta['required'] ?? [], fn ($k) => ($settings[$k] ?? '') === ''));
            $this->fail('mode', 'Para pasar a «en vivo» elige el proveedor y completa sus claves'.($missing ? ' y estos datos: '.implode(', ', $missing) : '').'.');
        }
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
        $d = $request->validate(['to' => ['nullable', 'string', 'max:30']]);
        $p = Providers::get($code);
        [$ok, $msg] = match (true) {
            ! $p['provider'] => [false, 'Elige el proveedor primero.'],
            $code === 'ai' && $p['provider'] === 'anthropic' => $this->testAnthropic($p),
            $code === 'backups' => app(RemoteBackupStore::class)->using(RemoteBackupStore::fromPanel())->testConnection(),
            $code === 'whatsapp' && $p['provider'] === 'meta' => $this->testWhatsApp($p, $d['to'] ?? null),
            $code === 'whatsapp' => [null, 'El envío automático está integrado con la API Cloud de Meta. Con este proveedor falta integrarlo.'],
            default => [null, 'Este servicio aún no está integrado: la prueba estará disponible cuando el equipo defina el proveedor.'],
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
    private function testWhatsApp(array $p, ?string $to): array
    {
        $wa = new WhatsAppCloud($p);
        if (trim((string) $to) === '') {
            return $wa->check();
        }
        if (! WhatsAppCloud::normalize($to)) {
            $this->fail('to', 'Escribe el número con código de país, por ejemplo +51 987 654 321.');
        }
        $tpl = $wa->template('test') ?? 'hello_world';
        $r = $wa->sendTemplate((string) $to, $tpl, [], $tpl === 'hello_world' ? 'en_US' : null);

        return $r['ok'] ? [true, "Mensaje de prueba enviado con la plantilla «{$tpl}». Revisa el WhatsApp de ese número."] : [false, (string) $r['error']];
    }

    /** Validaciones propias de algunos datos (dirección https, números). */
    private function checkSettings(string $code, array $settings): void
    {
        if ($code === 'backups') {
            if (($settings['endpoint'] ?? '') !== '' && ! preg_match('#^https?://[^\s/]+#i', $settings['endpoint'])) {
                $this->fail('settings.endpoint', 'La dirección debe empezar con https://');
            }
            $days = $settings['retention_days'] ?? '';
            if ($days !== '' && (! ctype_digit($days) || (int) $days < 1 || (int) $days > 3650)) {
                $this->fail('settings.retention_days', 'Escribe un número de días entre 1 y 3650.');
            }
        }
        if ($code === 'whatsapp' && ($settings['phone_id'] ?? '') !== '' && ! ctype_digit($settings['phone_id'])) {
            $this->fail('settings.phone_id', 'El ID del número son solo dígitos (no es el número de teléfono).');
        }
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
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
