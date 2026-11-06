<?php

// Casos de éxito por invitación: el cliente cuenta su historia y firma desde su enlace; queda la evidencia y puede
// retirar la autorización con el enlace de su correo, lo que oculta el caso al momento.

use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Storage::fake('local');
    Mail::fake();
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

/** Envía una invitación y devuelve el token que llegó en el correo. */
function inviteToken(): string
{
    test()->postJson('/api/v1/internal/site/success-stories/invites', [
        'client_name' => 'Minera Prueba SAC', 'sector' => 'mining', 'contact_name' => 'Rosa Prueba', 'contact_email' => 'rosa@prueba.test',
    ], authHeaders(tokenFor(ADMIN_EMAIL)))->assertCreated();
    $url = null;
    Mail::assertSent(SiteRequestMail::class, function ($m) use (&$url) {
        $url = $m->buttonUrl;

        return $m->hasTo('rosa@prueba.test');
    });

    return basename((string) $url);
}

function shareBody(array $o = []): array
{
    return $o + [
        'company_name' => 'Minera Prueba SAC', 'company_ruc' => '20123456789', 'sector' => 'mining', 'project_year' => 2026,
        'challenge' => 'Teníamos los permisos de trabajo en papel y se perdían entre turnos.',
        'solution' => 'Pasamos los PETAR y las inspecciones a EscudoSST y los supervisores firman en el celular.',
        'results' => [['value' => '0', 'label' => 'permisos perdidos'], ['value' => '', 'label' => '']],
        'quote' => 'Ahora todo queda registrado.', 'modules' => ['PTR', 'INS'],
        'signer_name' => 'Rosa Prueba Quispe', 'signer_role' => 'Jefa de SST', 'signer_doc_type' => 'DNI', 'signer_doc_number' => '12345678',
        'signer_email' => 'rosa@prueba.test', 'authorize' => true,
    ];
}

it('el cliente envía su historia y firma: queda el caso en borrador con la evidencia', function () {
    $token = inviteToken();
    $info = $this->getJson("/api/v1/public/success-stories/share/{$token}")->assertOk()->json('data');
    expect($info['client_name'])->toBe('Minera Prueba SAC')->and($info['authorization_template'])->toContain('AUTORIZACIÓN');

    $this->postJson("/api/v1/public/success-stories/share/{$token}", shareBody(['authorize' => false]))->assertStatus(422)->assertJsonValidationErrors('authorize');
    $this->postJson("/api/v1/public/success-stories/share/{$token}", shareBody())->assertCreated();
    $this->getJson("/api/v1/public/success-stories/share/{$token}")->assertStatus(410);   // el enlace sirve una sola vez

    $story = DB::connection('platform')->selectOne("select * from success_stories where slug = 'minera-prueba-sac'");
    expect($story->status)->toBe('draft')->and($story->consent_file_id)->not->toBeNull()
        ->and(json_decode($story->results_json, true))->toHaveCount(1);   // la fila vacía no se guarda

    $c = DB::connection('platform')->selectOne('select * from success_story_consents where story_id = ?', [$story->id]);
    expect($c->text_sha256)->toBe(hash('sha256', $c->authorization_text))
        ->and($c->authorization_text)->toContain('Rosa Prueba Quispe')
        ->and($c->signer_doc_number)->not->toContain('12345678');   // cifrado

    $panel = $this->getJson("/api/v1/internal/site/success-stories/{$story->id}/consents", authHeaders(tokenFor(ADMIN_EMAIL)))->json('data.0');
    expect($panel['signer_doc_masked'])->toBe('•••••678');
    $this->get("/api/v1/internal/site/success-stories/{$story->id}/consents/{$c->id}/file", authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk();

    // La evidencia no se puede corregir.
    expect(fn () => DB::connection('platform')->update("update success_story_consents set signer_name = 'Otro' where id = ?", [$c->id]))->toThrow(Exception::class);
});

it('retirar con el enlace del correo oculta el caso publicado al momento', function () {
    $token = inviteToken();
    $this->postJson("/api/v1/public/success-stories/share/{$token}", shareBody())->assertCreated();
    $withdraw = null;
    Mail::assertSent(SiteRequestMail::class, function ($m) use (&$withdraw) {
        if (str_contains((string) $m->buttonUrl, '/casos/retirar/')) {
            $withdraw = basename($m->buttonUrl);
        }

        return true;
    });
    $id = DB::connection('platform')->selectOne("select id from success_stories where slug = 'minera-prueba-sac'")->id;
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->postJson("/api/v1/internal/site/success-stories/{$id}/actions", ['action' => 'approve'], $h)->assertNoContent();
    $this->postJson("/api/v1/internal/site/success-stories/{$id}/actions", ['action' => 'publish'], $h)->assertNoContent();
    $this->getJson('/api/v1/public/success-stories/minera-prueba-sac')->assertOk();

    expect($this->getJson("/api/v1/public/success-stories/withdraw/{$withdraw}")->json('data.withdrawn_at'))->toBeNull();
    $this->postJson("/api/v1/public/success-stories/withdraw/{$withdraw}", ['reason' => 'Cambio de gerencia'])->assertNoContent();

    $this->getJson('/api/v1/public/success-stories/minera-prueba-sac')->assertNotFound();
    $s = DB::connection('platform')->selectOne('select status, consent_file_id from success_stories where id = ?', [$id]);
    expect($s->status)->toBe('hidden')->and($s->consent_file_id)->toBeNull();
    expect(DB::connection('platform')->selectOne('select withdrawn_at from success_story_consents where story_id = ?', [$id])->withdrawn_at)->not->toBeNull();
});

it('un enlace cancelado o vencido no sirve', function () {
    $token = inviteToken();
    $id = DB::connection('platform')->selectOne("select id from success_story_invites where client_name = 'Minera Prueba SAC'")->id;
    DB::connection('platform')->update("update success_story_invites set expires_at = now() - interval '1 day' where id = ?", [$id]);
    $this->getJson("/api/v1/public/success-stories/share/{$token}")->assertStatus(410);

    $this->deleteJson("/api/v1/internal/site/success-stories/invites/{$id}", [], authHeaders(tokenFor(ADMIN_EMAIL)))->assertNoContent();
    $this->getJson("/api/v1/public/success-stories/share/{$token}")->assertNotFound();
    $this->getJson('/api/v1/public/success-stories/share/'.str_repeat('a', 48))->assertNotFound();
});
