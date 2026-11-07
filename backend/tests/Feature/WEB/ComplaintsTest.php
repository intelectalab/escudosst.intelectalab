<?php

// Libro de Reclamaciones (SCR-WEB-17, 18 y 25) y preferencias de cookies (SCR-WEB-16).

use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// Las hojas no se borran nunca: todo lo de la conexión 'platform' corre en una transacción que se revierte.
beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function complaintForm(array $o = []): array
{
    return $o + [
        'consumer_full_name' => 'Juana Consumidora Pérez',
        'consumer_document_type' => 'DNI',
        'consumer_document_number' => '45678912',
        'consumer_email' => 'juana@prueba.test',
        'consumer_address' => 'Av. Arequipa 123, Lima',
        'item_type' => 'service',
        'item_description' => 'Suscripción plan Esencial',
        'complaint_type' => 'claim',
        'detail' => 'Me cobraron dos veces el mismo mes de la suscripción.',
        'consumer_request' => 'Devolución del cobro duplicado.',
        'privacy_consent' => true,
    ];
}

it('registra la hoja con correlativo, plazo y datos cifrados, y envía la constancia', function () {
    Mail::fake();
    $this->postJson('/api/v1/public/complaints', complaintForm(['consumer_document_number' => '123']))->assertStatus(422)->assertJsonValidationErrors('consumer_document_number');
    $this->postJson('/api/v1/public/complaints', complaintForm(['is_minor' => true]))->assertStatus(422)->assertJsonValidationErrors('guardian_full_name');

    $r = $this->postJson('/api/v1/public/complaints', complaintForm())->assertCreated()->json('data');
    expect($r['complaint_code'])->toMatch('/^LR-\d{4}-\d{6}$/')->and(strlen($r['tracking_key']))->toBe(20);

    $row = DB::connection('platform')->selectOne('select consumer_full_name, consumer_email, response_business_days, acknowledgment_sent_at from consumer_complaints where complaint_code = ?', [$r['complaint_code']]);
    expect($row->consumer_full_name)->not->toBe('Juana Consumidora Pérez')   // cifrado
        ->and($row->response_business_days)->toBe(15)
        ->and($row->acknowledgment_sent_at)->not->toBeNull();
    Mail::assertSent(SiteRequestMail::class, fn ($m) => $m->hasTo('juana@prueba.test') && str_contains($m->title, $r['complaint_code']));

    // Seguimiento: con la clave correcta ve el estado; con otra, el mismo error (no revela si existe).
    $this->postJson('/api/v1/public/complaints/status', ['code' => $r['complaint_code'], 'key' => $r['tracking_key']])->assertOk()->assertJsonPath('data.status', 'received');
    $this->postJson('/api/v1/public/complaints/status', ['code' => $r['complaint_code'], 'key' => 'OTRACLAVE1234567890X'])->assertStatus(422);
});

it('el equipo atiende la hoja: semáforo, prórroga con tope, respuesta por correo y cierre', function () {
    Mail::fake();
    $code = $this->postJson('/api/v1/public/complaints', complaintForm())->json('data.complaint_code');
    $h = authHeaders(tokenFor(ADMIN_EMAIL));

    $item = collect($this->getJson('/api/v1/internal/site/complaints', $h)->assertOk()->json('data'))->firstWhere('complaint_code', $code);
    expect($item['consumer_name'])->toBe('Juana Consumidora Pérez')->and($item['light'])->toBe('green');

    $detail = $this->getJson("/api/v1/internal/site/complaints/{$item['id']}", $h)->assertOk()->json('data');
    expect($detail['consumer_email'])->toBe('juana@prueba.test')->and($detail['events'][0]['event_type'])->toBe('registered');

    $act = fn (array $b) => $this->postJson("/api/v1/internal/site/complaints/{$item['id']}/actions", $b, $h);
    $act(['action' => 'close'])->assertStatus(409);   // no se cierra sin responder
    $act(['action' => 'start'])->assertNoContent();
    $act(['action' => 'extend', 'text' => 'Revisión con el banco', 'until' => date('Y-m-d', strtotime($detail['max_extension_on'].' +5 days'))])->assertStatus(422)->assertJsonValidationErrors('until');
    $act(['action' => 'extend', 'text' => 'Revisión con el banco', 'until' => $detail['max_extension_on']])->assertNoContent();
    Mail::assertSent(SiteRequestMail::class, fn ($m) => $m->hasTo('juana@prueba.test') && str_starts_with($m->title, 'Necesitamos más tiempo'));
    $act(['action' => 'answer', 'text' => 'Hemos devuelto el cobro duplicado.', 'channel' => 'email'])->assertNoContent();
    Mail::assertSent(SiteRequestMail::class, fn ($m) => $m->hasTo('juana@prueba.test') && str_starts_with($m->title, 'Respuesta a tu reclamo'));
    $act(['action' => 'close'])->assertNoContent();

    $events = collect($this->getJson("/api/v1/internal/site/complaints/{$item['id']}", $h)->json('data.events'))->pluck('event_type')->all();
    expect($events)->toContain('in_progress', 'extended', 'answered', 'closed');

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/internal/site/complaints', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertStatus(403);
});

it('guarda la preferencia de cookies; «necesarias» siempre activas', function () {
    $visitor = (string) \Illuminate\Support\Str::uuid();
    $r = $this->postJson('/api/v1/public/cookie-consent', ['visitor_id' => $visitor, 'action' => 'custom', 'categories' => ['analytics' => true, 'marketing' => false]])
        ->assertCreated()->json('data');
    expect($r['categories'])->toEqualCanonicalizing(['necessary' => true, 'analytics' => true, 'marketing' => false, 'preferences' => false]);

    $r = $this->postJson('/api/v1/public/cookie-consent', ['visitor_id' => $visitor, 'action' => 'reject_all', 'categories' => ['analytics' => true]])->json('data');
    expect($r['categories']['analytics'])->toBeFalse();
});
