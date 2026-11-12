<?php

// Reglas del grupo de eventos según sus fichas: ampliación de plazo de una acción (RN-ACR-06), estados vencidos por el
// paso del tiempo (M-ACC §9, RN-ACR-07), causa básica con acción y causa de gestión al aprobar (RN-INV-02/06) y
// cierre automático de la investigación (M-INV criterio 5).

use App\Modules\NOT\SstAlerts;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $this->site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null order by is_main desc limit 1', [HUAYLAS_ID])->id;
});

function evAction(array $h, string $me, int $days = 10): string
{
    return test()->postJson('/api/v1/records/ACR/corrective_actions', ['title' => 'Señalizar zona de carga', 'source_type' => 'other', 'description' => 'Recorrido del gerente',
        'responsible_user_id' => $me, 'due_on' => now()->addDays($days)->toDateString()], $h)->assertCreated()->json('data.id');
}

it('ampliar el plazo pide motivo, guarda la fecha original y pasado el límite lo aprueba la gerencia', function () {
    $id = evAction($this->h, $this->me);
    $orig = DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]) ? DB::selectOne('select due_on from corrective_actions where id = ?', [$id])->due_on : null;

    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$id}", ['due_on' => now()->addDays(20)->toDateString()], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('extension_reason');
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$id}", ['due_on' => now()->addDays(20)->toDateString(), 'extension_reason' => 'Demora del proveedor de señales'], $this->h)->assertOk();
    $a = DB::selectOne('select original_due_on, extension_count from corrective_actions where id = ?', [$id]);
    expect($a->original_due_on)->toBe($orig)->and($a->extension_count)->toBe(1);

    // La segunda ampliación supera el límite (1): el Jefe SST no puede aprobarla.
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$id}", ['due_on' => now()->addDays(30)->toDateString(), 'extension_reason' => 'Otra demora'], $this->h)
        ->assertStatus(422)->assertJsonPath('errors.due_on.0', fn ($m) => str_contains($m, 'gerencia'));
});

it('una acción y una notificación pasan a «vencida» cuando pasa su plazo', function () {
    $id = evAction($this->h, $this->me, 1);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update corrective_actions set due_on = current_date - 2 where id = ?", [$id]);
    $ev = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'disabling_accident', 'occurred_at' => now()->subDays(40)->toIso8601String(), 'description' => 'Caída de escalera', 'site_id' => $this->site], $this->h)
        ->assertCreated()->json('data.id');
    DB::update("update event_notifications set deadline_at = now() - interval '1 day' where event_id = ?", [$ev]);

    app(SstAlerts::class)->refreshStatuses(DB::connection());
    expect(DB::selectOne('select status from corrective_actions where id = ?', [$id])->status)->toBe('overdue');
    $n = DB::selectOne('select status, notification_overdue from event_notifications where event_id = ?', [$ev]);
    expect($n->status)->toBe('overdue')->and($n->notification_overdue)->toBeTrue();
    // Con la notificación vencida, el evento no se cierra.
    $this->patchJson("/api/v1/records/ACC/sst_events/{$ev}", ['status' => 'closed'], $this->h)->assertStatus(422);
});

it('al aprobar exige acción en cada causa básica y causa de gestión; se cierra sola al verificar sus acciones', function () {
    $ev = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'disabling_accident', 'occurred_at' => now()->subDay()->toIso8601String(), 'description' => 'Golpe con carga', 'site_id' => $this->site], $this->h)
        ->assertCreated()->json('data.id');
    $inv = $this->getJson("/api/v1/records/INV/investigations?f[event_id]={$ev}", $this->h)->json('data.0.id');
    $w = fn ($dni, $n) => $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => $n, 'paternal_last_name' => 'Prueba'], $this->h)->json('data.id');
    $this->postJson('/api/v1/records/INV/investigation_team', ['investigation_id' => $inv, 'worker_id' => $w('61616161', 'Lider'), 'role' => 'leader'], $this->h)->assertCreated();
    $this->postJson('/api/v1/records/INV/investigation_team', ['investigation_id' => $inv, 'worker_id' => $w('61616162', 'Comite'), 'role' => 'committee_representative'], $this->h)->assertCreated();
    $this->postJson('/api/v1/records/INV/investigation_causes', ['investigation_id' => $inv, 'cause_level' => 'immediate', 'cause_category' => 'unsafe_act', 'description' => 'Carga mal estibada'], $this->h)->assertCreated();
    $basic = $this->postJson('/api/v1/records/INV/investigation_causes', ['investigation_id' => $inv, 'cause_level' => 'basic', 'cause_category' => 'management_system',
        'description' => 'Sin procedimiento de estiba'], $this->h)->assertCreated()->json('data.id');
    $body = ['status' => 'approved', 'conclusions' => 'Falta procedimiento de estiba.'];

    // RN-INV-02: la causa básica no tiene acción.
    $this->patchJson("/api/v1/records/INV/investigations/{$inv}", $body, $this->h)->assertStatus(422)->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'acción correctiva'));
    $action = $this->postJson("/api/v1/acr/from/investigation_causes/{$basic}", ['title' => 'Elaborar procedimiento de estiba', 'responsible_user_id' => $this->me,
        'due_on' => now()->addDays(5)->toDateString()], $this->h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/INV/investigations/{$inv}", $body, $this->h)->assertOk();

    // Criterio 5: al verificar la última acción, la investigación queda cerrada.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    // La verifica otra persona (RN-ACR-04): se usa otro usuario cualquiera de la plataforma como verificador.
    $verifier = DB::connection('platform')->selectOne('select id from users where id <> ? and deleted_at is null order by created_at limit 1', [$this->me])->id;
    DB::update("update corrective_actions set status = 'verified', verified_by = ?, verified_at = now(), effectiveness = 'effective' where id = ?", [$verifier, $action]);
    app(\App\Modules\REC\Rules\AcrRules::class)->after('update', ['table' => 'corrective_actions'], $action, ['status' => 'verified'], null);
    expect(DB::selectOne('select status from investigations where id = ?', [$inv])->status)->toBe('closed');
});
