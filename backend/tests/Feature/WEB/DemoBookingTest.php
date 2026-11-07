<?php

// Agenda de demos (SCR-WEB-51 a 58, RN-WEB-68 a 73): solo horarios libres, un horario no se reserva dos veces,
// enlace de gestión con respuesta idéntica si no sirve, confirmación con enlace, recordatorios una vez y permisos.

use App\Modules\WEB\Mail\SiteRequestMail;
use App\Modules\WEB\Services\DemoService;
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

it('ofrece horarios libres y un mismo horario no se reserva dos veces', function () {
    $this->getJson('/api/v1/public/demo/slots')->assertOk()->assertJsonPath('data.result', 'disabled');
    demoSetup();
    $from = now('America/Lima')->addDays(3)->toDateString();
    $slots = $this->getJson("/api/v1/public/demo/slots?from={$from}&to={$from}")->assertOk()->json('data');
    expect($slots['duration_minutes'])->toBe(30)->and($slots['slots'])->toHaveCount(4);   // 9:00, 9:45, 10:30, 11:15

    $slot = $slots['slots'][0]['starts_at'];
    $this->postJson('/api/v1/public/demo/book', bookingForm($slot, ['privacy_consent' => false]))->assertStatus(422)->assertJsonValidationErrors('privacy_consent');
    $this->postJson('/api/v1/public/demo/book', bookingForm($slot, ['meeting_provider' => 'phone']))->assertStatus(422)->assertJsonValidationErrors('phone');
    $r = $this->postJson('/api/v1/public/demo/book', bookingForm($slot), ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid7()])->assertCreated()->json('data');
    expect($r['manage_token'])->toHaveLength(48)->and($r['host_name'])->toBe('Asesora Comercial');
    Mail::assertSent(SiteRequestMail::class, fn ($m) => $m->hasTo('ana@visita.test') && $m->ics !== null && str_contains($m->ics[0], 'BEGIN:VEVENT'));

    $this->postJson('/api/v1/public/demo/book', bookingForm($slot, ['email' => 'otro@visita.test']))->assertStatus(409);
    expect($this->getJson("/api/v1/public/demo/slots?from={$from}&to={$from}")->json('data.slots'))->toHaveCount(3);
    expect(DB::connection('platform')->selectOne("select source from marketing_leads where email = 'ana@visita.test'")->source)->toBe('demo');
});

it('el visitante gestiona su cita con el enlace; uno falso responde igual que uno vencido', function () {
    demoSetup();
    $day = now('America/Lima')->addDays(3)->toDateString();
    $slots = $this->getJson("/api/v1/public/demo/slots?from={$day}&to={$day}")->json('data.slots');
    $token = $this->postJson('/api/v1/public/demo/book', bookingForm($slots[0]['starts_at']))->json('data.manage_token');

    $this->getJson('/api/v1/public/demo/manage/'.str_repeat('a', 48))->assertNotFound();
    $this->getJson("/api/v1/public/demo/manage/{$token}")->assertOk()->assertJsonPath('data.status', 'proposed')->assertJsonPath('data.can_change', true);
    $this->get("/api/v1/public/demo/manage/{$token}/ics")->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');

    $this->postJson("/api/v1/public/demo/manage/{$token}/reschedule", ['starts_at' => $slots[2]['starts_at']])->assertOk();
    expect(substr($this->getJson("/api/v1/public/demo/manage/{$token}")->json('data.starts_at'), 0, 16))->toBe(substr(\Illuminate\Support\Carbon::parse($slots[2]['starts_at'])->toIso8601String(), 0, 16));
    $this->postJson("/api/v1/public/demo/manage/{$token}/cancel", ['reason' => 'Cambio de planes'])->assertOk();
    $this->getJson("/api/v1/public/demo/manage/{$token}")->assertJsonPath('data.can_change', false);
    $this->postJson("/api/v1/public/demo/manage/{$token}/reschedule", ['starts_at' => $slots[1]['starts_at']])->assertStatus(409);
});

it('el equipo confirma con enlace, los recordatorios salen una vez y Soporte solo ve lo suyo', function () {
    demoSetup();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $day = now('America/Lima')->addDays(3)->toDateString();
    $slot = $this->getJson("/api/v1/public/demo/slots?from={$day}&to={$day}")->json('data.slots.0.starts_at');
    $this->postJson('/api/v1/public/demo/book', bookingForm($slot))->assertCreated();
    $id = $this->getJson('/api/v1/internal/site/demos?from='.$day.'&to='.$day, $h)->assertOk()->json('data.meetings.0.id');

    $this->postJson("/api/v1/internal/site/demos/meetings/{$id}/confirm", ['meeting_provider' => 'meet'], $h)->assertStatus(422)->assertJsonValidationErrors('meeting_url');
    $this->postJson("/api/v1/internal/site/demos/meetings/{$id}/confirm", ['meeting_provider' => 'meet', 'meeting_url' => 'https://meet.google.com/abc-defg-hij'], $h)->assertOk()->assertJsonPath('data.status', 'confirmed');
    Mail::assertSent(SiteRequestMail::class, fn ($m) => $m->title === 'Tu demo está confirmada' && $m->buttonUrl === 'https://meet.google.com/abc-defg-hij');

    DB::connection('platform')->update("update demo_meetings set starts_at = now() + interval '23 hours 30 minutes', ends_at = now() + interval '24 hours' where id = ?", [$id]);
    $svc = app(DemoService::class);
    expect($svc->sendReminders())->toBe(1)->and($svc->sendReminders())->toBe(0);

    $this->postJson("/api/v1/internal/site/demos/meetings/{$id}/complete", ['no_show' => false], $h)->assertStatus(422)->assertJsonValidationErrors('outcome');
    $this->postJson("/api/v1/internal/site/demos/meetings/{$id}/complete", ['outcome' => 'interested', 'notes' => 'Pidió cotización'], $h)->assertOk()->assertJsonPath('data.status', 'completed');

    $admin = DB::connection('platform')->selectOne('select id from users where email = ?', [ADMIN_EMAIL])->id;
    DB::connection('pgsql')->select("select set_config('app.user_id', ?, false)", [$admin]);
    DB::connection('pgsql')->update("update users set platform_role = 'support' where id = ?", [$admin]);
    app('auth')->forgetGuards();
    $range = '?from='.now('America/Lima')->toDateString().'&to='.$day;
    expect($this->getJson('/api/v1/internal/site/demos'.$range, $h)->assertOk()->json('data.meetings'))->toHaveCount(1);   // es su anfitrión
    DB::connection('platform')->update('update demo_hosts set user_id = (select id from users where id <> ? and platform_role is null limit 1) where user_id = ?', [$admin, $admin]);
    expect($this->getJson('/api/v1/internal/site/demos'.$range, $h)->json('data.meetings'))->toBe([]);   // ya no lo es
    $this->postJson("/api/v1/internal/site/demos/meetings/{$id}/cancel", ['reason' => 'Prueba'], $h)->assertForbidden();
    $this->patchJson('/api/v1/internal/site/demos/settings', ['public_booking_enabled' => false], $h)->assertForbidden();
});
