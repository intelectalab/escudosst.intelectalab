<?php

// Reporte de actos y condiciones por WhatsApp (M-ACT, SCR-ACT-03; RN-ACT-03): verificación del webhook de Meta, firma
// obligatoria, conversación guiada desde el código del QR y anonimato (solo la huella del número). Nada sale a internet.

use App\Modules\ACT\ReportPointController;
use App\Modules\ACT\WhatsAppIntake;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->waRow = DB::connection('platform')->selectOne("select * from platform_providers where code = 'whatsapp'");
    DB::connection('platform')->update("update platform_providers set provider = 'meta', mode = 'live', settings = ?::jsonb, secret_enc = ? where code = 'whatsapp'",
        [json_encode(['phone' => '+51 900 111 222', 'phone_id' => '111222333', 'template_alert' => 'alerta_sst', 'verify_token' => 'palabra-meta']),
            Crypt::encryptString(json_encode(['token' => 'EAA-token-wa', 'app_secret' => 'secreto-app']))]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);
});

afterEach(function () {
    $p = $this->waRow;
    DB::connection('platform')->update('update platform_providers set provider = ?, mode = ?, settings = ?::jsonb, secret_enc = ? where code = ?', [$p->provider, $p->mode, $p->settings, $p->secret_enc, 'whatsapp']);
});

/** Punto de reporte de Huaylas con su token de QR. @return array{0: string, 1: string} [id, token] */
function waPoint(bool $anonymous = true): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $site = DB::selectOne('select id from sites where company_id = ? order by is_main desc limit 1', [HUAYLAS_ID])->id;
    $id = DB::selectOne("insert into report_points (company_id, code, name, site_id, token_hash, allow_anonymous, is_active)
                         values (?, ?, 'Patio de maniobras', ?, decode(repeat('00', 32), 'hex'), ?, true) returning id", [HUAYLAS_ID, 'WA-'.uniqid(), $site, $anonymous ? 'true' : 'false'])->id;
    $token = ReportPointController::token(HUAYLAS_ID, $id);
    DB::update("update report_points set token_hash = decode(?, 'hex') where id = ?", [hash('sha256', $token), $id]);

    return [$id, $token];
}

/** Envía un mensaje firmado como lo haría Meta. */
function waSay(string $from, array $message): \Illuminate\Testing\TestResponse
{
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['messages' => [['from' => $from] + $message]]]]]]]);

    return test()->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'secreto-app')], $body);
}

function waText(string $from, string $text): void
{
    waSay($from, ['type' => 'text', 'text' => ['body' => $text]])->assertOk();
}

it('verifica el webhook con el token del panel y rechaza mensajes sin la firma de Meta', function () {
    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=palabra-meta&hub.challenge=12345')->assertOk()->assertSee('12345');
    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=otra&hub.challenge=12345')->assertForbidden();

    $body = json_encode(['entry' => []]);
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=malo'], $body)->assertForbidden();
});

it('registra el reporte anónimo por chat desde el código del QR y guarda solo la huella del número', function () {
    [$point, $token] = waPoint();
    $phone = '51987654321';
    waText($phone, WhatsAppIntake::startText(HUAYLAS_ID, $token));
    waText($phone, '7');            // opción inválida: vuelve a preguntar
    waText($phone, '2');            // condición insegura
    waText($phone, 'corto');        // muy corta
    waText($phone, 'Cable eléctrico pelado junto a la mezcladora');
    waText($phone, 'no');           // sin foto
    waText($phone, '2');            // sin mi nombre

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $r = DB::selectOne('select * from substandard_reports where report_point_id = ? and deleted_at is null', [$point]);
    expect($r)->not->toBeNull()
        ->and($r->origin)->toBe('whatsapp')->and($r->report_type)->toBe('unsafe_condition')->and($r->status)->toBe('received')
        ->and($r->is_anonymous)->toBeTrue()->and($r->reporter_contact)->toBeNull()
        ->and($r->whatsapp_conversation_id)->toBe(WhatsAppIntake::phoneHash($phone))
        ->and($r->whatsapp_conversation_id)->not->toContain('987654321');
    Http::assertSent(fn ($req) => ($req['type'] ?? null) === 'text' && str_contains($req['text']['body'], $r->code));

    // La conversación terminó: un mensaje suelto pide escanear el QR, no crea otro reporte.
    waText($phone, 'hola');
    expect((int) DB::selectOne('select count(*) as n from substandard_reports where report_point_id = ?', [$point])->n)->toBe(1);
});

it('con nombre guarda el contacto; sin código de punto no registra nada; un código inválido se rechaza', function () {
    [$point, $token] = waPoint(anonymous: false);
    $phone = '51911222333';
    waText($phone, 'quiero reportar algo');
    waText($phone, WhatsAppIntake::startText(HUAYLAS_ID, str_repeat('A', 32)));
    waText($phone, WhatsAppIntake::startText(HUAYLAS_ID, $token));
    waText($phone, '1');
    waText($phone, 'Trabajador sin arnés en el andamio del bloque B');
    waText($phone, 'no');
    waText($phone, 'Rosa Quispe');

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $rows = DB::select('select * from substandard_reports where report_point_id = ?', [$point]);
    expect($rows)->toHaveCount(1)->and($rows[0]->is_anonymous)->toBeFalse()->and($rows[0]->report_type)->toBe('unsafe_act')
        ->and($rows[0]->reporter_contact)->toBe('Rosa Quispe · +51911222333');
});

it('el formulario del QR ofrece el enlace de WhatsApp solo con WhatsApp en vivo', function () {
    [, $token] = waPoint();
    $url = $this->getJson('/api/v1/public/report/'.HUAYLAS_ID.'/'.$token)->assertOk()->json('data.whatsapp_url');
    expect($url)->toStartWith('https://wa.me/51900111222?text=');

    DB::connection('platform')->update("update platform_providers set mode = 'test' where code = 'whatsapp'");
    expect($this->getJson('/api/v1/public/report/'.HUAYLAS_ID.'/'.$token)->assertOk()->json('data.whatsapp_url'))->toBeNull();
});
