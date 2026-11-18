<?php

// M-FIR RN-FIR-08 / flujo §7.3: firma con código de un solo uso enviado al correo (OTP), con vencimiento e intentos limitados.

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

it('firma con el código enviado al correo y lo rechaza si no coincide', function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $doc = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'suggestion', 'description' => 'Firma con OTP'], $h)->json('data.id');
    $req = $this->postJson('/api/v1/fir/requests', ['subject_table' => 'substandard_reports', 'subject_id' => $doc, 'signer_user_id' => $me, 'role_in_document' => 'responsible'], $h)->assertCreated()->json('data.id');

    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['otp' => '123456'], $h)->assertStatus(422)->assertJsonValidationErrors('otp');
    $this->postJson("/api/v1/fir/requests/{$req}/otp", [], $h)->assertOk()->assertJsonStructure(['data' => ['sent_to', 'minutes']]);
    $this->postJson("/api/v1/fir/requests/{$req}/otp", [], $h)->assertStatus(429);
    Mail::assertSent(\App\Modules\WEB\Mail\SiteRequestMail::class, 1);

    // El código es el que se envió: se reconstruye desde el correo enviado.
    $code = null;
    Mail::assertSent(\App\Modules\WEB\Mail\SiteRequestMail::class, function ($m) use (&$code) {
        $code = $m->rows[0][1];

        return true;
    });
    $wrong = $code === '000000' ? '111111' : '000000';
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['otp' => $wrong], $h)->assertStatus(422);
    // Se guarda dónde se firmó (si el celular lo comparte); un equipo que no es de quien firma no se guarda.
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['otp' => $code, 'geo_lat' => -12.0464, 'geo_lng' => -77.0428, 'device_id' => (string) \Illuminate\Support\Str::uuid()], $h)
        ->assertOk()->assertJsonPath('data.method', 'otp');
    $sig = \Illuminate\Support\Facades\DB::selectOne('select geo_lat, geo_lng, device_id from signatures where signature_request_id = ?', [$req]);
    expect((float) $sig->geo_lat)->toBe(-12.0464)->and((float) $sig->geo_lng)->toBe(-77.0428)->and($sig->device_id)->toBeNull();
});
