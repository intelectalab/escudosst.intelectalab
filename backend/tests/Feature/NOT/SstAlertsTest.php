<?php

// Alertas SST: una acción vencida avisa al responsable y al equipo SST, sin repetirse.

use App\Modules\NOT\SstAlerts;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('avisa de una acción vencida en la campana y no repite el aviso', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $id = $this->postJson('/api/v1/records/ACR/corrective_actions', ['source_type' => 'other', 'description' => 'x', 'title' => 'Reparar escalera',
        'responsible_user_id' => $me, 'due_on' => now('America/Lima')->toDateString()], $h)->json('data.id');
    DB::update('update corrective_actions set due_on = due_on - 5 where id = ?', [$id]);

    $alerts = app(SstAlerts::class);
    $n = app(AccountsService::class)->withCompany(ANDINAS_ID, fn ($db) => $alerts->forCompany($db, ANDINAS_ID));
    expect($n)->toBeGreaterThanOrEqual(1);
    $again = app(AccountsService::class)->withCompany(ANDINAS_ID, fn ($db) => $alerts->forCompany($db, ANDINAS_ID));
    expect($again)->toBe(0);

    $bell = collect($this->getJson('/api/v1/me/notifications', authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->json('data'));
    $mine = $bell->firstWhere('event_code', 'acr.overdue');
    expect($mine['title'])->toBe('Acción correctiva vencida')->and($mine['link'])->toBe("/m/acr?t=corrective_actions&open={$id}");
});
