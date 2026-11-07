<?php

// Boletín normativo (SCR-WEB-36/47, RN-WEB-49 a 51): doble confirmación, envío solo a confirmados con temas en
// común, sin repetir por suscriptor, baja de un clic con List-Unsubscribe y edición enviada inalterable.

use App\Modules\WEB\Mail\NewsletterIssueMail;
use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

/** Suscribe y devuelve el token de confirmación que llegó por correo. */
function nlSubscribe(string $email, array $topics): string
{
    test()->postJson('/api/v1/public/newsletter', ['email' => $email, 'topics' => $topics, 'privacy_consent' => true])->assertCreated();
    $url = null;
    Mail::assertSent(SiteRequestMail::class, function ($m) use ($email, &$url) {
        if ($m->hasTo($email)) {
            $url = $m->buttonUrl;
        }

        return $m->hasTo($email);
    });

    return basename((string) $url);
}

it('pide privacidad y temas, guarda solo el hash y confirma con el enlace del correo', function () {
    $this->postJson('/api/v1/public/newsletter', ['email' => 'ana@boletin.test', 'topics' => ['regulations']])->assertStatus(422)->assertJsonValidationErrors('privacy_consent');
    $this->postJson('/api/v1/public/newsletter', ['email' => 'ana@boletin.test', 'topics' => ['status'], 'privacy_consent' => true])->assertStatus(422);
    $token = nlSubscribe('ana@boletin.test', ['regulations', 'sunafil']);
    $row = DB::connection('platform')->selectOne("select status, encode(confirm_token_hash, 'hex') as h from newsletter_subscribers where email = 'ana@boletin.test'");
    expect($row->status)->toBe('pending')->and($row->h)->not->toContain($token);
    expect(DB::connection('platform')->selectOne("select source from marketing_leads where email = 'ana@boletin.test'")->source)->toBe('newsletter');

    $this->postJson('/api/v1/public/newsletter/confirm', ['token' => 'x'.$token])->assertJsonPath('data.result', 'invalid');
    $this->postJson('/api/v1/public/newsletter/confirm', ['token' => $token])->assertJsonPath('data.result', 'confirmed');
    $this->postJson('/api/v1/public/newsletter/confirm', ['token' => $token])->assertJsonPath('data.result', 'invalid');   // un solo uso
});

it('envía solo a confirmados con temas en común, no repite, y la baja de un clic funciona', function () {
    $this->postJson('/api/v1/public/newsletter/confirm', ['token' => nlSubscribe('ana@boletin.test', ['regulations'])]);
    $this->postJson('/api/v1/public/newsletter/confirm', ['token' => nlSubscribe('beto@boletin.test', ['sectors'])]);
    nlSubscribe('caro@boletin.test', ['regulations']);   // sin confirmar

    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $id = $this->postJson('/api/v1/internal/site/newsletter/issues', [
        'subject' => 'Cambios en el reglamento de SST', 'topics' => ['regulations'], 'body' => "## Qué cambió\n\n- Nuevo plazo para el IPERC\n- Registros digitales",
    ], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/internal/site/newsletter/issues/{$id}/test", [], $h)->assertOk();
    Mail::assertSent(NewsletterIssueMail::class, fn ($m) => $m->hasTo(ADMIN_EMAIL) && $m->isTest);

    $this->postJson("/api/v1/internal/site/newsletter/issues/{$id}/send", [], $h)->assertOk()->assertJsonPath('data.sent', 1);
    Mail::assertSent(NewsletterIssueMail::class, fn ($m) => $m->hasTo('ana@boletin.test') && ! $m->isTest && str_contains($m->unsubscribeApiUrl, '/public/newsletter/unsubscribe/'));
    Mail::assertNotSent(NewsletterIssueMail::class, fn ($m) => $m->hasTo('beto@boletin.test') || $m->hasTo('caro@boletin.test'));
    expect(app(\App\Modules\WEB\Services\NewsletterService::class)->sendDue($id))->toBe(0);   // ya enviada: no repite

    $this->patchJson("/api/v1/internal/site/newsletter/issues/{$id}", ['subject' => 'Otro asunto', 'topics' => ['regulations'], 'body' => 'Texto suficientemente largo.'], $h)->assertStatus(422);
    $this->getJson('/api/v1/public/newsletter/issues')->assertOk()->assertJsonPath('data.0.slug', 'cambios-en-el-reglamento-de-sst');

    $token = app(\App\Modules\WEB\Services\NewsletterService::class)->unsubscribeToken('ana@boletin.test');
    $this->postJson("/api/v1/public/newsletter/unsubscribe/{$token}?e={$id}")->assertJsonPath('data.result', 'unsubscribed');
    $db = DB::connection('platform');
    expect($db->selectOne("select status from newsletter_subscribers where email = 'ana@boletin.test'")->status)->toBe('unsubscribed')
        ->and($db->selectOne('select unsubscribes_count from newsletter_issues where id = ?', [$id])->unsubscribes_count)->toBe(1);
});

it('programa, cancela y solo el Dueño envía', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $id = $this->postJson('/api/v1/internal/site/newsletter/issues', ['subject' => 'Edición de diciembre', 'topics' => ['sunafil'], 'body' => 'Resumen de las inspecciones del mes.'], $h)->json('data.id');
    $this->postJson("/api/v1/internal/site/newsletter/issues/{$id}/send", ['at' => now()->subHour()->toIso8601String()], $h)->assertStatus(422);
    $this->postJson("/api/v1/internal/site/newsletter/issues/{$id}/send", ['at' => now()->addDay()->toIso8601String()], $h)->assertOk()->assertJsonPath('data.status', 'scheduled');
    $this->postJson("/api/v1/internal/site/newsletter/issues/{$id}/cancel", [], $h)->assertNoContent();

    $admin = DB::connection('platform')->selectOne('select id from users where email = ?', [ADMIN_EMAIL])->id;
    DB::connection('pgsql')->select("select set_config('app.user_id', ?, false)", [$admin]);
    DB::connection('pgsql')->update("update users set platform_role = 'support' where id = ?", [$admin]);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/internal/site/newsletter', $h)->assertOk();
    $this->postJson("/api/v1/internal/site/newsletter/issues/{$id}/send", [], $h)->assertForbidden();
});
