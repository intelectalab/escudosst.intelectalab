<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Proveedores externos (tabla platform_providers). Un proveedor está «en vivo» cuando tiene proveedor elegido, claves,
 * los datos obligatorios y modo «live»; si no, el módulo que lo usa trabaja en modo de prueba (simula y lo dice en pantalla).
 * Todo se configura desde Panel › Proveedores y claves: nadie tiene que tocar el código ni el servidor para conectarlos.
 */
final class Providers
{
    /** Qué se configura de cada uno: [nombre, proveedores posibles, campos visibles, campos secretos, para qué sirve, qué pasa en modo de prueba]. */
    public const CATALOG = [
        'payments' => ['Pagos en línea', ['culqi' => 'Culqi', 'niubiz' => 'Niubiz', 'izipay' => 'Izipay', 'mercadopago' => 'Mercado Pago', 'other' => 'Otro'],
            ['public_key' => 'Clave pública', 'merchant_id' => 'Código de comercio'], ['secret_key' => 'Clave secreta', 'webhook_secret' => 'Secreto del webhook'],
            'Venta de cursos (VTA) y, más adelante, pago de la suscripción con tarjeta.',
            'Los pedidos se pagan con «Simular pago aprobado» o con pago manual (número de operación): no se cobra nada en línea.'],
        'einvoice' => ['Facturación electrónica (OSE)', ['nubefact' => 'Nubefact', 'efact' => 'Efact', 'other' => 'Otro'], ['ruc' => 'RUC emisor', 'series_invoice' => 'Serie de factura', 'series_receipt' => 'Serie de boleta', 'api_url' => 'Dirección de la API (la da el OSE)'],
            ['token' => 'Token o clave del OSE'],
            'Emitir facturas y boletas válidas ante SUNAT por suscripciones y cursos.',
            'Los comprobantes quedan «de prueba» (no válidos ante SUNAT) para revisar montos y datos.'],
        'whatsapp' => ['WhatsApp', ['meta' => 'API Cloud de Meta', 'twilio' => 'Twilio', 'bsp_peru' => 'Proveedor (BSP) en Perú', 'other' => 'Otro'],
            ['phone' => 'Número que envía', 'phone_id' => 'ID del número de teléfono', 'waba_id' => 'ID de la cuenta de WhatsApp Business', 'template_lang' => 'Idioma de las plantillas',
                'template_alert' => 'Plantilla: alerta', 'template_digest' => 'Plantilla: resumen diario', 'template_vote' => 'Plantilla: enlace de votación', 'template_report' => 'Plantilla: respuesta a un reporte',
                'template_test' => 'Plantilla: prueba', 'verify_token' => 'Token de verificación del webhook'],
            ['token' => 'Token de acceso', 'app_secret' => 'Clave secreta de la app (firma del webhook)'],
            'Avisos por WhatsApp (alertas críticas, resumen diario, enlaces de votación y respuesta a reportes) a quienes lo aceptaron, y reportes de actos y condiciones por chat desde el QR.',
            'No se envía nada por WhatsApp: los avisos llegan a la campana de la app y por correo.'],
        'esign' => ['Firma digital certificada', ['llama_pe' => 'Llama.pe', 'reniec' => 'RENIEC (DNIe)', 'other' => 'Otro'], ['account' => 'Cuenta', 'api_url' => 'Dirección de la API'], ['api_key' => 'Clave de la API'],
            'Firma con certificado digital (además de la firma electrónica simple que ya trae EscudoSST).',
            'Se usa la firma electrónica simple con evidencia (contraseña o firma asistida).'],
        'backups' => ['Copias de seguridad externas', ['b2' => 'Backblaze B2', 's3' => 'Amazon S3', 'other' => 'Otro compatible con S3 (MinIO, Wasabi, DigitalOcean Spaces…)'],
            ['endpoint' => 'Dirección (endpoint)', 'bucket' => 'Bucket', 'region' => 'Región', 'retention_days' => 'Días que se guardan'],
            ['key_id' => 'ID de la clave (Key ID)', 'secret' => 'Clave secreta (Application key)'],
            'Sube cada copia de seguridad (ya cifrada) a un almacenamiento fuera del servidor y borra las que pasan los días indicados.',
            'Se usan las variables BACKUP_S3_* del servidor si existen; si no, las copias solo quedan en el servidor.'],
    ];

    /**
     * Detalles de cada uno para el panel: datos obligatorios para «en vivo», claves opcionales, nombre de los modos, ayudas por
     * campo, dirección de webhook para copiar, dato que pide la prueba y las preguntas para el equipo. «pending» = el proveedor
     * aún no está decidido ni integrado: se guardan los datos, pero sigue en modo de prueba hasta integrarlo.
     */
    public const META = [
        'payments' => [
            'required' => [],
            'optional_secrets' => ['webhook_secret'],
            'webhook' => '/api/v1/webhooks/payments/platform',
            'webhook_note' => 'Dirección que pedirá la pasarela para avisar los pagos. La recepción se activa cuando se integre la pasarela elegida.',
            'pending' => true,
            'questions' => [
                '¿Qué pasarela usarán: Culqi, Niubiz, Izipay o Mercado Pago?',
                '¿Una sola cuenta de EscudoSST (suscripciones y cursos) o cada empresa que vende cursos conecta la suya (docs/09 §12 dice que el dinero va a la empresa)?',
                '¿Se cobrará Yape a través de esa pasarela?',
                'Datos que hacen falta: clave pública, clave secreta, código de comercio, secreto del webhook y claves del entorno de pruebas (sandbox).',
            ],
        ],
        'einvoice' => [
            'required' => ['ruc'],
            'pending' => true,
            'questions' => [
                '¿Qué OSE o PSE usarán: Nubefact, Efact u otro?',
                '¿RUC emisor y series de factura y boleta (por ejemplo F001 y B001)?',
                '¿Las empresas que venden cursos emitirán con su propio OSE o solo factura EscudoSST?',
                'Datos que hacen falta: dirección de la API, usuario o token, series, ambiente de pruebas (beta) y si el OSE pide certificado digital propio.',
            ],
        ],
        'whatsapp' => [
            'required' => ['phone_id', 'template_alert'],
            // La clave de la app solo hace falta para recibir reportes por chat; los avisos salen sin ella.
            'optional_secrets' => ['app_secret'],
            'hints' => [
                'phone' => 'Formato internacional, por ejemplo +51 987 654 321.',
                'phone_id' => 'Meta › WhatsApp › Configuración de la API: «Identificador del número de teléfono».',
                'waba_id' => 'Meta › WhatsApp › Configuración de la API: «Identificador de la cuenta de WhatsApp Business».',
                'template_lang' => 'Código del idioma con que se aprobaron las plantillas, por ejemplo es, es_PE o es_MX.',
                'template_alert' => 'Obligatoria. Variables: {{1}} título, {{2}} detalle, {{3}} enlace.',
                'template_digest' => 'Opcional. Variables: {{1}} nombre, {{2}} cantidad de alertas, {{3}} enlace. Sin ella se usa la de alerta.',
                'template_vote' => 'Opcional. Variables: {{1}} elección, {{2}} enlace para votar. Sin ella se usa la de alerta.',
                'template_report' => 'Opcional. Variables: {{1}} código del reporte, {{2}} enlace. Sin ella se usa la de alerta.',
                'template_test' => 'Para «Probar». Vacío = hello_world (la plantilla de ejemplo de Meta, en inglés).',
                'verify_token' => 'Una palabra que inventas y pegas también en Meta › Webhooks › Token de verificación.',
                'app_secret' => 'Meta › Configuración de la app › Básica › Clave secreta de la app. Sin ella no se aceptan mensajes entrantes.',
            ],
            'webhook' => '/api/v1/webhooks/whatsapp',
            'webhook_note' => 'Pégala en Meta › WhatsApp › Configuración › Webhook y suscríbete al campo «messages»: así llegan los reportes por chat desde el QR.',
            'test_input' => ['key' => 'to', 'label' => 'Número para la prueba', 'placeholder' => '+51 987 654 321',
                'hint' => 'Vacío: solo se verifica el número y el token. Con un número: se envía la plantilla de prueba.'],
            'notice' => 'Solo se usa la API oficial de WhatsApp Business (plantillas aprobadas por Meta y aceptación de cada persona). No se conectan apps que vinculan un teléfono con código QR: no son oficiales y Meta puede bloquear el número. El envío automático funciona hoy con la API Cloud de Meta; con Twilio u otro BSP falta integrarlo.',
            'questions' => [
                '¿Usarán la API Cloud de Meta directa o un BSP (docs/13 DA-02 recomienda un BSP con soporte local)? Con Meta ya funciona; con otro BSP hay que integrarlo.',
                '¿Qué número enviará los mensajes (uno solo para la plataforma, DA-14)?',
                'Nombres exactos de las plantillas aprobadas por Meta y su idioma.',
            ],
        ],
        'esign' => [
            'required' => [],
            'pending' => true,
            'questions' => [
                '¿Qué proveedor de firma digital acreditado por INDECOPI (IOFE) usarán?',
                '¿Firmará la empresa con un certificado propio o cada persona con su certificado (DNIe u otro)?',
                '¿Qué documentos deben llevar firma digital certificada (el resto sigue con firma electrónica simple)?',
                'Datos que hacen falta: cuenta, clave de la API, dirección de la API de pruebas y de producción y cómo se verifica una firma.',
            ],
        ],
        'backups' => [
            'required' => ['endpoint', 'bucket'],
            'modes' => ['test' => 'Desactivada (usa la configuración del servidor, si hay)', 'live' => 'Activa'],
            'hints' => [
                'endpoint' => 'Por ejemplo https://s3.us-west-004.backblazeb2.com (B2) o https://s3.amazonaws.com.',
                'bucket' => 'Privado y solo para copias.',
                'region' => 'Por ejemplo us-west-004. Vacío = us-east-1.',
                'retention_days' => 'Vacío = 30.',
            ],
            'test_label' => 'Probar conexión (sube y borra un archivo pequeño)',
        ],
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
        $settings = json_decode($r?->settings ?? '{}', true) ?: [];

        return ['code' => $code, 'provider' => $r?->provider, 'mode' => $r?->mode ?? 'test', 'settings' => $settings,
            'secrets' => $secrets, 'live' => (bool) (self::complete($code, $r?->provider, $settings, $secrets) && ($r?->mode === 'live'))];
    }

    /** ¿Tiene proveedor, todas las claves y los datos obligatorios? */
    public static function complete(string $code, ?string $provider, array $settings, array $secrets): bool
    {
        $needed = array_diff(array_keys(self::CATALOG[$code][3] ?? []), self::META[$code]['optional_secrets'] ?? []);
        $required = self::META[$code]['required'] ?? [];

        return $provider && $needed && ! array_diff($needed, array_keys(array_filter($secrets)))
            && ! array_filter($required, fn ($k) => trim((string) ($settings[$k] ?? '')) === '');
    }

    public static function live(string $code): bool
    {
        return self::get($code)['live'];
    }

    /** Estado de los que usan las empresas, sin claves (para mostrar avisos de «modo de prueba»). */
    public static function status(): array
    {
        $out = [];
        foreach (self::CATALOG as $code => $c) {
            if ($code === 'backups') {
                continue;   // interno de la plataforma
            }
            $p = self::get($code);
            $out[$code] = ['name' => $c[0], 'live' => $p['live'], 'provider' => $p['provider'], 'test_note' => $c[5]];
        }

        return $out;
    }
}
