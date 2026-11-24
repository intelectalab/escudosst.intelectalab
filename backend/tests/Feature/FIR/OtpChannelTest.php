<?php

// M-FIR §7.3 «OTP por correo/WhatsApp»: el código va por WhatsApp si está en vivo con la plantilla «template_otp» y la
// persona tiene celular y aceptó el canal; si no, por correo. El canal queda en signatures.otp_channel.

use App\Modules\SUS\Services\AccountsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    $this->waRow = DB::connection('platform')->selectOne("select * from platform_providers where code = 'whatsapp'");
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->me = DB::connection('platform')->selectOne('select id from users where email = ?', [PROFESIONAL_EMAIL])->id;
    $this->request = function () {
        $doc = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'suggestion', 'description' => 'Firma OTP canal'], $this->h)->json('data.id');

        return $this->postJson('/api/v1/fir/requests', ['subject_table' => 'substandard_reports', 'subject_id' => $doc, 'signer_user_id' => $this->me, 'role_in_document' => 'responsible'], $this->h)
            ->assertCreated()->json('data.id');
    };
});

afterEach(function () {
    $p = $this->waRow;
    DB::connection('platform')->update('update platform_providers set provider = ?, mode = ?, settings = ?::jsonb, secret_enc = ? where code = ?', [$p->provider, $p->mode, $p->settings, $p->secret_enc, 'whatsapp']);
});

function firOtpSigned(string $req): object
{
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);

    return DB::selectOne('select method, otp_channel, evidence from signatures where signature_request_id = ?', [$req]);
}

it('sin WhatsApp en vivo el código va al correo y la firma guarda otp_channel = email', function () {
    $req = ($this->request)();
    $this->postJson("/api/v1/fir/requests/{$req}/otp", [], $this->h)->assertOk()->assertJsonPath('data.channel', 'email');
    $code = null;
    Mail::assertSent(\App\Modules\WEB\Mail\SiteRequestMail::class, function ($m) use (&$code) {
        $code = $m->rows[0][1];

        return true;
    });
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['otp' => $code], $this->h)->assertOk()->assertJsonPath('data.method', 'otp');
    $s = firOtpSigned($req);
    expect($s->otp_channel)->toBe('email')->and(json_decode($s->evidence, true)['otp_channel'])->toBe('email');
});

it('con WhatsApp en vivo, plantilla y aceptación del trabajador vinculado, el código va por WhatsApp y no por correo', function () {
    DB::connection('platform')->update("update platform_providers set provider = 'meta', mode = 'live', settings = ?::jsonb, secret_enc = ? where code = 'whatsapp'",
        [json_encode(['phone_id' => '111222333', 'template_alert' => 'alerta_sst', 'template_otp' => 'codigo_firma', 'template_lang' => 'es']), Crypt::encryptString(json_encode(['token' => 'EAA-token-otp']))]);
    app(AccountsService::class)->withCompany(ANDINAS_ID, function ($db) {
        $db->update('update workers set user_id = null where company_id = ? and user_id = ?', [ANDINAS_ID, $this->me]);
        $doc = (string) random_int(40000000, 79999999);
        $db->insert("insert into workers (company_id, document_type, document_number, document_number_hash, first_name, paternal_last_name, user_id, whatsapp_opt_in, phone_e164)
                     values (?, 'DNI', ?, decode(?, 'hex'), 'Prueba', 'OTP', ?, true, ?)",
            [ANDINAS_ID, Crypt::encryptString($doc), hash('sha256', $doc.Str::random(6)), $this->me, Crypt::encryptString('+51 987 333 444')]);
    });
    $code = null;
    Http::fake(['graph.facebook.com/*' => function ($r) use (&$code) {
        $code = $r->data()['template']['components'][0]['parameters'][0]['text'] ?? null;

        return Http::response(['messages' => [['id' => 'wamid.OTP1']]], 200);
    }]);

    $req = ($this->request)();
    $this->postJson("/api/v1/fir/requests/{$req}/otp", [], $this->h)->assertOk()->assertJsonPath('data.channel', 'whatsapp')->assertJsonPath('data.sent_to', 'WhatsApp •••••444');
    Mail::assertNotSent(\App\Modules\WEB\Mail\SiteRequestMail::class);
    expect($code)->toMatch('/^\d{6}$/');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['otp' => $code], $this->h)->assertOk();
    expect(firOtpSigned($req)->otp_channel)->toBe('whatsapp');
});
