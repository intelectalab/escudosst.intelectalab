<?php

// SCR-WEB-12 · Solicitudes de demo, contacto y cotización: formulario público y bandeja del panel.

use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// Lo del panel corre por la conexión 'platform': se revierte al final (no deja solicitudes ni bitácora).
beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

it('el visitante pide una demo; valida privacidad y datos', function () {
    Mail::fake();
    $form = ['request_type' => 'demo', 'full_name' => 'Carlos Visitante', 'email' => 'carlos@prueba.test', 'company_name' => 'Agro Prueba', 'privacy_consent' => true];

    $this->postJson('/api/v1/public/demo-requests', ['privacy_consent' => false] + $form)->assertStatus(422)->assertJsonValidationErrors('privacy_consent');
    $this->postJson('/api/v1/public/demo-requests', ['ruc' => '123'] + $form)->assertStatus(422)->assertJsonValidationErrors('ruc');
    $this->postJson('/api/v1/public/demo-requests', ['headcount' => 40, 'interest_plan_code' => 'P', 'message' => 'Quiero verlo'] + $form)
        ->assertCreated()->assertJsonStructure(['data' => ['id']]);

    // Respuesta automática al momento a quien la pidió.
    Mail::assertSent(SiteRequestMail::class, fn ($m) => $m->hasTo('carlos@prueba.test') && $m->title === 'Recibimos tu solicitud de demo');
});

it('la cotización llega al correo con el plan y los complementos elegidos', function () {
    Mail::fake();
    $form = ['request_type' => 'quote', 'full_name' => 'Rosa Cotiza', 'email' => 'rosa@prueba.test', 'company_name' => 'Textil Prueba', 'privacy_consent' => true];
    // Para cotizar, el RUC es obligatorio (cada RUC es una empresa en EscudoSST).
    $this->postJson('/api/v1/public/demo-requests', $form)->assertStatus(422)->assertJsonValidationErrors('ruc');
    $form['ruc'] = '20100070970';

    $this->postJson('/api/v1/public/demo-requests', ['addon_codes' => ['NOEXISTE']] + $form)->assertStatus(422)->assertJsonValidationErrors('addon_codes.0');
    $this->postJson('/api/v1/public/demo-requests', ['interest_plan_code' => 'P', 'addon_codes' => ['IAA', 'OFF']] + $form)->assertCreated();

    Mail::assertSent(SiteRequestMail::class, function ($m) {
        $labels = array_column($m->rows, 0);

        return $m->hasTo('rosa@prueba.test') && $m->title === 'Tu cotización de EscudoSST'
            && in_array('Plan Profesional', $labels, true) && in_array('Asistente IA', $labels, true) && in_array('App móvil offline', $labels, true);
    });
});

it('el equipo ve la solicitud y la marca como contactada o descartada', function () {
    $this->getJson('/api/v1/internal/site/requests', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertStatus(403);
    $id = DB::connection('platform')->selectOne(
        "insert into demo_requests (request_type, full_name, company_name, email, privacy_consent) values ('quote', 'Rosa Prueba', 'Textil Prueba', 'rosa@prueba.test', true) returning id"
    )->id;
    $h = authHeaders(tokenFor(ADMIN_EMAIL));

    $row = collect($this->getJson('/api/v1/internal/site/requests', $h)->assertOk()->json('data'))->firstWhere('id', $id);
    expect($row['status'])->toBe('new')->and($row['request_type'])->toBe('quote');

    $this->patchJson("/api/v1/internal/site/requests/{$id}", ['status' => 'discarded'], $h)->assertStatus(422)->assertJsonValidationErrors('discard_reason');
    $this->patchJson("/api/v1/internal/site/requests/{$id}", ['status' => 'contacted', 'notes' => 'Llamar el lunes'], $h)->assertNoContent();
    $r = DB::connection('platform')->selectOne('select status, notes, contacted_at from demo_requests where id = ?', [$id]);
    expect($r->status)->toBe('contacted')->and($r->notes)->toBe('Llamar el lunes')->and($r->contacted_at)->not->toBeNull();
});
