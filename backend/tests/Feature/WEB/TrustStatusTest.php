<?php

// Centro de confianza y estado del servicio (SCR-WEB-37/38/48/49, RN-WEB-52 a 54): subencargados activos, estado
// general = peor componente, incidentes visibles solo al publicarse, actualizaciones inalterables y avisos por correo.

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

it('el centro de confianza muestra solo subencargados activos y el Dueño los mantiene', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->postJson('/api/v1/internal/site/subprocessors', ['name' => 'Proveedor X', 'purpose' => 'Pruebas internas', 'country' => 'pe', 'data_categories' => 'Ninguno real',
        'transfer_basis' => 'No aplica', 'website' => 'http://x.test', 'status' => 'inactive'], $h)->assertStatus(422)->assertJsonValidationErrors('website');
    $id = $this->postJson('/api/v1/internal/site/subprocessors', ['name' => 'Proveedor X', 'purpose' => 'Pruebas internas', 'country' => 'pe', 'data_categories' => 'Ninguno real',
        'transfer_basis' => 'No aplica', 'status' => 'inactive'], $h)->assertCreated()->json('data.id');
    $names = fn () => collect($this->getJson('/api/v1/public/trust')->assertOk()->json('data.subprocessors'))->pluck('name');
    expect($names())->not->toContain('Proveedor X');
    $this->patchJson("/api/v1/internal/site/subprocessors/{$id}", ['name' => 'Proveedor X', 'purpose' => 'Pruebas internas', 'country' => 'PE', 'data_categories' => 'Ninguno real',
        'transfer_basis' => 'No aplica', 'status' => 'active'], $h)->assertCreated();
    expect($names())->toContain('Proveedor X');

    $sent = $this->postJson('/api/v1/internal/site/subprocessors/notify', ['summary' => 'Agregamos a Proveedor X para pruebas internas.'], $h)->assertOk()->json('data.sent');
    Mail::assertSent(SiteRequestMail::class, $sent);   // uno por administrador de cuenta activo (puede ser 0 en la base de pruebas)
});

it('un incidente solo se ve al publicarse, cambia los componentes y avisa a los suscriptores', function () {
    $db = DB::connection('platform');
    $db->insert("insert into newsletter_subscribers (email, topics_json, status, confirmed_at, unsubscribe_token_hash) values ('aviso@estado.test', '[\"status\"]', 'confirmed', now(), sha256('x'::bytea))");
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $api = $db->selectOne("select id from status_components where code = 'api'")->id;

    $this->postJson('/api/v1/internal/site/status-incidents', ['title' => 'Lentitud', 'impact' => 'major', 'components' => []], $h)->assertStatus(422)->assertJsonValidationErrors('components');
    $id = $this->postJson('/api/v1/internal/site/status-incidents', ['title' => 'Lentitud al guardar registros', 'impact' => 'major', 'components' => [$api]], $h)->assertCreated()->json('data.id');
    expect($this->getJson('/api/v1/public/status')->json('data.incidents'))->toBe([]);

    $this->postJson("/api/v1/internal/site/status-incidents/{$id}/publish", ['message' => 'Estamos revisando la lentitud.'], $h)->assertOk()->assertJsonPath('data.notified', 1);
    $s = $this->getJson('/api/v1/public/status')->json('data');
    expect($s['overall_status'])->toBe('partial_outage')->and($s['incidents'][0]['title'])->toBe('Lentitud al guardar registros');
    Mail::assertSent(NewsletterIssueMail::class, fn ($m) => $m->hasTo('aviso@estado.test') && $m->kicker === 'ESTADO DEL SERVICIO');

    $this->patchJson("/api/v1/internal/site/status-incidents/{$id}", ['title' => 'Otro', 'impact' => 'minor', 'components' => [$api]], $h)->assertStatus(422);
    $this->postJson("/api/v1/internal/site/status-incidents/{$id}/updates", ['status' => 'resolved', 'message' => 'Todo funciona con normalidad.'], $h)->assertOk();
    $s = $this->getJson('/api/v1/public/status')->json('data');
    expect($s['overall_status'])->toBe('operational')->and($s['incidents'][0]['updates'])->toHaveCount(2);
    $this->deleteJson("/api/v1/internal/site/status-incidents/{$id}", [], $h)->assertStatus(422);

    expect(fn () => $db->update('update status_incident_updates set message = ? where incident_id = ?', ['cambio', $id]))->toThrow(Exception::class);
});

it('la suscripción a avisos pide privacidad y no crea un interesado; Soporte no publica', function () {
    $this->postJson('/api/v1/public/status/subscribe', ['email' => 'ops@estado.test'])->assertStatus(422)->assertJsonValidationErrors('privacy_consent');
    $this->postJson('/api/v1/public/status/subscribe', ['email' => 'ops@estado.test', 'privacy_consent' => true])->assertCreated();
    Mail::assertSent(SiteRequestMail::class, fn ($m) => $m->hasTo('ops@estado.test') && str_contains($m->title, 'estado'));
    expect(DB::connection('platform')->selectOne("select 1 from marketing_leads where email = 'ops@estado.test'"))->toBeNull();

    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $api = DB::connection('platform')->selectOne("select id from status_components where code = 'api'")->id;
    $id = $this->postJson('/api/v1/internal/site/status-incidents', ['title' => 'Mantenimiento de la base', 'impact' => 'maintenance', 'components' => [$api]], $h)->json('data.id');
    $admin = DB::connection('platform')->selectOne('select id from users where email = ?', [ADMIN_EMAIL])->id;
    DB::connection('pgsql')->select("select set_config('app.user_id', ?, false)", [$admin]);
    DB::connection('pgsql')->update("update users set platform_role = 'support' where id = ?", [$admin]);
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/internal/site/status-incidents/{$id}/publish", ['message' => 'Mantenimiento programado.'], $h)->assertForbidden();
    $this->postJson("/api/v1/internal/site/status-incidents/{$id}/updates", ['status' => 'identified', 'message' => 'Preparando el mantenimiento.'], $h)->assertOk();
});
