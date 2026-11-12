<?php

// Accidentes (plazo de notificación, investigación obligatoria, cierre), EPP (vencimiento y stock),
// Comité (paridad, quórum y acuerdos) y acción correctiva desde cualquier registro de origen.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function op(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

function opSite($test): string
{
    return $test->postJson('/api/v1/records/TEN/sites', ['code' => 'OP-S', 'name' => 'Planta operaciones'], op())->json('data.id');
}

it('un accidente incapacitante calcula su plazo, abre la investigación y no se cierra sin ella', function () {
    $h = op();
    $site = opSite($this);
    $occurred = '2026-10-15T10:00:00-05:00';
    $ev = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'disabling_accident', 'occurred_at' => $occurred, 'site_id' => $site,
        'description' => 'Corte en mano al cambiar disco de amoladora'], $h)->assertCreated();
    $id = $ev->json('data.id');
    expect($ev->json('data.code'))->toStartWith('ACC-')
        ->and(substr($ev->json('data.notification_deadline_at'), 0, 10))->toBe('2026-11-30'); // último día hábil del mes siguiente

    $notif = $this->getJson("/api/v1/records/ACC/event_notifications?f[event_id]={$id}", $h)->json('data');
    expect($notif)->toHaveCount(1)->and($notif[0]['status'])->toBe('pending');
    $inv = $this->getJson("/api/v1/records/INV/investigations?f[event_id]={$id}", $h)->json('data');
    expect($inv)->toHaveCount(1);
    $this->getJson("/api/v1/records/ACC/sst_events/{$id}", $h)->assertJsonPath('data.status', 'under_investigation');

    // Afectado con días perdidos → se suman al evento.
    $worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '40404040', 'first_name' => 'Ana', 'paternal_last_name' => 'Rojas'], $h)->json('data.id');
    $this->postJson('/api/v1/records/ACC/event_people', ['event_id' => $id, 'worker_id' => $worker, 'person_role' => 'injured', 'lost_days' => 5,
        'injury_type' => 'Corte', 'body_part' => 'Mano', 'injury_agent' => 'Herramienta manual'], $h)->assertCreated();
    $this->getJson("/api/v1/records/ACC/sst_events/{$id}", $h)->assertJsonPath('data.lost_days', 5);
    // Un testigo no suma días perdidos y no se aceptan días negativos.
    $this->postJson('/api/v1/records/ACC/event_people', ['event_id' => $id, 'worker_id' => $worker, 'person_role' => 'witness', 'lost_days' => -3], $h)
        ->assertStatus(422)->assertJsonValidationErrors('lost_days');
    $this->postJson('/api/v1/records/ACC/event_people', ['event_id' => $id, 'worker_id' => $worker, 'person_role' => 'witness', 'lost_days' => 4], $h)->assertCreated();
    $this->getJson("/api/v1/records/ACC/sst_events/{$id}", $h)->assertJsonPath('data.lost_days', 5);

    $this->patchJson("/api/v1/records/ACC/sst_events/{$id}", ['status' => 'closed'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'Este evento exige investigación: ciérrala antes de cerrar el evento.');

    // Notificar dentro del plazo.
    $this->patchJson("/api/v1/records/ACC/event_notifications/{$notif[0]['id']}", ['status' => 'sent', 'notified_at' => '2026-10-20T09:00:00-05:00',
        'notification_number' => 'SAT-123'], $h)->assertOk()->assertJsonPath('data.notification_overdue', false);

    // Investigación: sin causas no se aprueba.
    $this->patchJson("/api/v1/records/INV/investigations/{$inv[0]['id']}", ['status' => 'approved'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'Registra al menos una causa inmediata y una causa básica (o raíz) antes de aprobar la investigación.');
    $cause = $this->postJson('/api/v1/records/INV/investigation_causes', ['investigation_id' => $inv[0]['id'], 'cause_level' => 'basic',
        'cause_category' => 'job_factor', 'description' => 'Procedimiento de cambio de disco inexistente'], $h)->assertCreated()->json('data.id');

    // Acción correctiva desde la causa (genérico) → origen «investigación».
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $action = $this->postJson("/api/v1/acr/from/investigation_causes/{$cause}", ['title' => 'Elaborar PETS de cambio de disco', 'responsible_user_id' => $me,
        'due_on' => now()->addDays(5)->toDateString()], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/acr/from/investigation_causes/{$cause}", ['title' => 'Otra', 'responsible_user_id' => $me, 'due_on' => now()->addDays(5)->toDateString()], $h)
        ->assertStatus(409);
    $tr = $this->getJson("/api/v1/records/ACR/corrective_actions/{$action}/trace", $h)->json('data');
    expect(collect($tr['parents'])->pluck('table')->all())->toContain('investigations');

    // Para aprobar: causa inmediata y básica, líder del equipo y conclusiones.
    $this->postJson('/api/v1/records/INV/investigation_causes', ['investigation_id' => $inv[0]['id'], 'cause_level' => 'immediate',
        'cause_category' => 'unsafe_act', 'description' => 'Cambio de disco sin desenergizar'], $h)->assertCreated();
    $this->patchJson("/api/v1/records/INV/investigations/{$inv[0]['id']}", ['status' => 'approved'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'Indica quién lidera la investigación en el equipo investigador.');
    $this->postJson('/api/v1/records/INV/investigation_team', ['investigation_id' => $inv[0]['id'], 'worker_id' => $worker, 'role' => 'leader'], $h)->assertCreated();
    $this->patchJson("/api/v1/records/INV/investigations/{$inv[0]['id']}", ['status' => 'approved'], $h)->assertStatus(422)->assertJsonValidationErrors('conclusions');
    // RN-INV-03: el equipo incluye al representante del comité.
    $this->patchJson("/api/v1/records/INV/investigations/{$inv[0]['id']}", ['status' => 'approved', 'conclusions' => 'Falta de procedimiento y de supervisión.'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'equipo investigador'));
    $rep = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '40404099', 'first_name' => 'Luz', 'paternal_last_name' => 'Comité'], $h)->json('data.id');
    $this->postJson('/api/v1/records/INV/investigation_team', ['investigation_id' => $inv[0]['id'], 'worker_id' => $rep, 'role' => 'committee_representative'], $h)->assertCreated();
    $this->patchJson("/api/v1/records/INV/investigations/{$inv[0]['id']}", ['status' => 'approved', 'conclusions' => 'Falta de procedimiento y de supervisión.'], $h)->assertOk();
    // RN-INV-05: aprobado, el informe no cambia (solo adendas).
    $this->patchJson("/api/v1/records/INV/investigations/{$inv[0]['id']}", ['conclusions' => 'Otra cosa'], $h)->assertStatus(409);
    $this->patchJson("/api/v1/records/INV/investigations/{$inv[0]['id']}", ['status' => 'closed'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'Hay acciones correctivas de las causas sin verificar.');
});

it('marca fuera de plazo la notificación tardía de un accidente mortal (24 h)', function () {
    $h = op();
    $id = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'fatal_accident', 'occurred_at' => '2026-10-01T08:00:00-05:00', 'site_id' => opSite($this),
        'description' => 'Caída de altura'], $h)->assertCreated()->assertJsonPath('data.notification_deadline_at', '2026-10-02 08:00:00-05')->json('data.id');
    $n = $this->getJson("/api/v1/records/ACC/event_notifications?f[event_id]={$id}", $h)->json('data.0.id');
    // Sin número ni constancia no se da por notificada; tampoco con fecha anterior al evento.
    $this->patchJson("/api/v1/records/ACC/event_notifications/{$n}", ['status' => 'sent', 'notified_at' => '2026-10-03T10:00:00-05:00'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('notification_number');
    $this->patchJson("/api/v1/records/ACC/event_notifications/{$n}", ['status' => 'sent', 'notified_at' => '2026-09-30T10:00:00-05:00', 'notification_number' => 'SAT-9'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('notified_at');
    $this->patchJson("/api/v1/records/ACC/event_notifications/{$n}", ['status' => 'sent', 'notified_at' => '2026-10-03T10:00:00-05:00', 'notification_number' => 'SAT-9'], $h)
        ->assertOk()->assertJsonPath('data.notification_overdue', true);
    $this->getJson("/api/v1/records/ACC/sst_events/{$id}", $h)->assertJsonPath('data.notification_overdue', true);
    $this->patchJson("/api/v1/records/ACC/event_notifications/{$n}", ['notified_at' => '2026-10-01T10:00:00-05:00'], $h)->assertStatus(409);
});

it('entrega EPP con vencimiento por vida útil y controla el stock', function () {
    $h = op();
    $site = opSite($this);
    $worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '50505050', 'first_name' => 'Jorge', 'paternal_last_name' => 'Díaz',
        'current_site_id' => $site], $h)->json('data.id');
    $item = $this->postJson('/api/v1/records/EPP/ppe_items', ['code' => 'CASCO-1', 'name' => 'Casco de seguridad', 'category' => 'head', 'useful_life_days' => 365, 'unit' => 'unidad'], $h)
        ->assertCreated()->json('data.id');

    $this->postJson('/api/v1/records/EPP/ppe_deliveries', ['worker_id' => $worker, 'ppe_item_id' => $item, 'quantity' => 1], $h)
        ->assertStatus(422)->assertJsonPath('errors.quantity.0', 'No hay stock suficiente de «Casco de seguridad» en la sede (quedan 0). Registra primero el ingreso en «Movimientos de stock».');

    $this->postJson('/api/v1/records/EPP/ppe_stock_movements', ['ppe_item_id' => $item, 'site_id' => $site, 'movement_type' => 'in', 'quantity' => 10], $h)->assertCreated();
    $del = $this->postJson('/api/v1/records/EPP/ppe_deliveries', ['worker_id' => $worker, 'ppe_item_id' => $item, 'quantity' => 1, 'delivered_at' => '2026-11-01T09:00:00-05:00'], $h)
        ->assertCreated()->assertJsonPath('data.expires_on', '2027-11-01')->assertJsonPath('data.site_id', $site)->json('data.id');

    $moves = $this->getJson("/api/v1/records/EPP/ppe_stock_movements?f[ppe_item_id]={$item}", $h)->json('data');
    expect(collect($moves)->pluck('movement_type')->sort()->values()->all())->toBe(['in', 'out']);

    $this->patchJson("/api/v1/records/EPP/ppe_deliveries/{$del}", ['quantity' => 2], $h)->assertStatus(409);
    $this->patchJson("/api/v1/records/EPP/ppe_deliveries/{$del}", ['status' => 'returned', 'return_condition' => 'Dañado'], $h)->assertOk();
    expect(collect($this->getJson("/api/v1/records/EPP/ppe_stock_movements?f[ppe_item_id]={$item}", $h)->json('data'))->pluck('movement_type')->all())->toContain('return');
});

it('el comité exige titulares pares y mitad por lado, y sin quórum no hay acuerdos', function () {
    $h = op();
    $com = $this->postJson('/api/v1/records/COM/committees', ['committee_type' => 'committee', 'name' => 'Comité SST 2026-2028', 'period_start_on' => '2026-01-01',
        'period_end_on' => '2027-12-31', 'members_required' => 4, 'status' => 'forming'], $h)->assertCreated()->json('data.id');
    $w = [];
    foreach (['60606061', '60606062', '60606063'] as $k => $dni) {
        $w[] = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => "M{$k}", 'paternal_last_name' => 'Comité'], $h)->json('data.id');
    }
    foreach ([[$w[0], 'employer'], [$w[1], 'workers'], [$w[2], 'workers']] as [$wid, $rep]) {
        $this->postJson('/api/v1/records/COM/committee_members', ['committee_id' => $com, 'worker_id' => $wid, 'representation' => $rep, 'member_type' => 'titular',
            'role_in_committee' => 'member', 'starts_on' => '2026-01-01', 'status' => 'active'], $h)->assertCreated();
    }
    $this->patchJson("/api/v1/records/COM/committees/{$com}", ['status' => 'active'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'Para activar el comité, los titulares deben ser pares y mitad por cada lado (hoy: 1 del empleador y 2 de los trabajadores).');

    $m1 = $this->postJson('/api/v1/records/COM/committee_meetings', ['committee_id' => $com, 'meeting_type' => 'ordinary', 'scheduled_at' => now()->toIso8601String(),
        'status' => 'held', 'quorum_reached' => false], $h)->assertCreated()->assertJsonPath('data.number', 1)->json('data.id');
    $this->postJson('/api/v1/records/COM/meeting_agreements', ['meeting_id' => $m1, 'description' => 'Comprar extintores'], $h)
        ->assertStatus(422)->assertJsonPath('errors.meeting_id.0', 'La sesión no tuvo quórum: no se adoptan acuerdos (RN-COM-08).');

    $m2 = $this->postJson('/api/v1/records/COM/committee_meetings', ['committee_id' => $com, 'meeting_type' => 'ordinary', 'scheduled_at' => now()->toIso8601String(),
        'status' => 'held', 'quorum_reached' => true], $h)->assertJsonPath('data.number', 2)->json('data.id');
    $ag = $this->postJson('/api/v1/records/COM/meeting_agreements', ['meeting_id' => $m2, 'description' => 'Comprar extintores'], $h)->assertCreated()
        ->assertJsonPath('data.number', 1)->json('data.id');
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $this->postJson("/api/v1/acr/from/meeting_agreements/{$ag}", ['title' => 'Comprar 10 extintores PQS', 'responsible_user_id' => $me, 'due_on' => now()->addDays(15)->toDateString()], $h)
        ->assertCreated();
    $this->getJson("/api/v1/records/COM/meeting_agreements/{$ag}", $h)->assertJsonPath('data.status', 'in_progress');

    // El acta se firma con su texto (o el acta escaneada).
    $this->patchJson("/api/v1/records/COM/committee_meetings/{$m2}", ['status' => 'minutes_signed'], $h)->assertStatus(422)->assertJsonValidationErrors('minutes_html');
    $this->patchJson("/api/v1/records/COM/committee_meetings/{$m2}", ['status' => 'minutes_signed', 'minutes_html' => '<p>Se acordó comprar extintores.</p>'], $h)->assertOk();
    $this->patchJson("/api/v1/records/COM/committee_meetings/{$m2}", ['location' => 'Otra'], $h)->assertStatus(409);
});
