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
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['otp' => $code], $h)->assertOk()->assertJsonPath('data.method', 'otp');
});
