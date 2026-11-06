<?php

// Salud del sistema (panel del dueño, docs/03 §11).

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('muestra el estado de bases, caché, correo y procesos solo al equipo de la plataforma', function () {
    $this->getJson('/api/v1/internal/health', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertStatus(403);

    $res = $this->getJson('/api/v1/internal/health', authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk();
    $checks = collect($res->json('data.checks'))->keyBy('key');

    expect($checks->keys()->all())->toContain('db_main', 'db_medical', 'redis', 'disk', 'files', 'scheduler', 'queue', 'failed_jobs', 'mail', 'backups', 'whatsapp')
        ->and($checks['db_main']['status'])->toBe('ok')
        ->and($checks['db_medical']['status'])->toBe('ok')
        ->and($checks['redis']['status'])->toBe('ok')
        ->and($checks['whatsapp']['status'])->toBe('off')
        ->and($checks['db_main']['help'])->not->toBeEmpty()
        ->and($res->json('data.mail_days'))->toHaveCount(7);
});

it('permite correr a mano el proceso diario y queda en la bitácora', function () {
    $this->postJson('/api/v1/internal/health/run-daily', [], authHeaders(tokenFor(ADMIN_EMAIL)))
        ->assertOk()
        ->assertJsonStructure(['data' => ['reminders', 'past_due', 'trial_expired', 'blocked']]);

    $this->getJson('/api/v1/internal/audit?action=daily_process_run', authHeaders(tokenFor(ADMIN_EMAIL)))
        ->assertOk()
        ->assertJsonPath('data.0.action', 'daily_process_run');
});
