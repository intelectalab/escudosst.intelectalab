<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Proveedores externos (tabla platform_providers). Un proveedor está «en vivo» cuando tiene proveedor elegido, claves
 * y modo «live»; si no, el módulo que lo usa trabaja en modo de prueba (simula y lo dice en pantalla).
 */
final class Providers
{
    /** Qué se configura de cada uno: [nombre, proveedores posibles, campos visibles, campos secretos, para qué sirve, qué pasa en modo de prueba]. */
    public const CATALOG = [
        'ai' => ['Inteligencia artificial', ['anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI', 'other' => 'Otro'], ['model' => 'Modelo'], ['api_key' => 'Clave de la API'],
            'Asistente IA: sugiere controles del IPERC, causas de una investigación y redacta textos.',
            'Sugiere desde el catálogo de EscudoSST (sin IA) y lo marca como «sugerencia de prueba».'],
        'payments' => ['Pagos en línea', ['culqi' => 'Culqi', 'niubiz' => 'Niubiz', 'izipay' => 'Izipay', 'mercadopago' => 'Mercado Pago', 'other' => 'Otro'],
            ['public_key' => 'Clave pública', 'merchant_id' => 'Código de comercio'], ['secret_key' => 'Clave secreta'],
            'Venta de cursos (VTA) y, más adelante, pago de la suscripción con tarjeta.',
            'Los pedidos se pagan con «Simular pago aprobado»: no se cobra nada y queda marcado como prueba.'],
        'einvoice' => ['Facturación electrónica (OSE)', ['nubefact' => 'Nubefact', 'efact' => 'Efact', 'other' => 'Otro'], ['ruc' => 'RUC emisor', 'series_invoice' => 'Serie de factura', 'series_receipt' => 'Serie de boleta'],
            ['token' => 'Token o clave del OSE'],
            'Emitir facturas y boletas válidas ante SUNAT por suscripciones y cursos.',
            'Los comprobantes quedan «de prueba» (no válidos ante SUNAT) para revisar montos y datos.'],
        'whatsapp' => ['WhatsApp', ['meta' => 'API Cloud de Meta', 'twilio' => 'Twilio', 'bsp_peru' => 'Proveedor (BSP) en Perú', 'other' => 'Otro'], ['phone' => 'Número que envía', 'phone_id' => 'ID del número'],
            ['token' => 'Token de acceso'],
            'Avisos por WhatsApp (vencimientos, acciones) y reportes de trabajadores por WhatsApp.',
            'Los mensajes quedan en la bandeja de prueba (se ven en el panel) y no se envían.'],
        'esign' => ['Firma digital certificada', ['llama_pe' => 'Llama.pe', 'reniec' => 'RENIEC (DNIe)', 'other' => 'Otro'], ['account' => 'Cuenta'], ['api_key' => 'Clave de la API'],
            'Firma con certificado digital (además de la firma electrónica simple que ya trae EscudoSST).',
            'Se usa la firma electrónica simple con evidencia (contraseña o firma asistida).'],
    ];

    /** @return array{code: string, provider: ?string, mode: string, settings: array, secrets: array, live: bool} */
    public static function get(string $code): array
    {
        $r = DB::selectOne('select * from platform_providers where code = ?', [$code]);
        $secrets = [];
        if ($r?->secret_enc) {
            try {
                $secrets = json_decode(Crypt::decryptString($r->secret_enc), true) ?: [];
            } catch (\Throwable) {
                $secrets = [];
            }
        }
        $needed = array_keys(self::CATALOG[$code][3] ?? []);
        $complete = $r?->provider && $needed && ! array_diff($needed, array_keys(array_filter($secrets)));

        return ['code' => $code, 'provider' => $r?->provider, 'mode' => $r?->mode ?? 'test', 'settings' => json_decode($r?->settings ?? '{}', true) ?: [],
            'secrets' => $secrets, 'live' => (bool) ($complete && ($r?->mode === 'live'))];
    }

    public static function live(string $code): bool
    {
        return self::get($code)['live'];
    }

    /** Estado de todos, sin claves (para mostrar avisos de «modo de prueba»). */
    public static function status(): array
    {
        $out = [];
        foreach (self::CATALOG as $code => $c) {
            $p = self::get($code);
            $out[$code] = ['name' => $c[0], 'live' => $p['live'], 'provider' => $p['provider'], 'test_note' => $c[5]];
        }

        return $out;
    }
}
