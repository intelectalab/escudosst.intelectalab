<?php

// M-ACC D.S. 005-2012-TR art. 110: el plazo de notificación de la enfermedad ocupacional corre desde la fecha del diagnóstico
// (obligatoria en ese tipo de evento); el reporte agrupado respeta el periodo elegido.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
});

function accdQuery(string $sql, array $b = []): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $r = DB::select($sql, $b);
    DB::select("select set_config('app.company_id', '', false)");

    return $r;
}

it('exige la fecha del diagnóstico y cuenta el plazo de la enfermedad ocupacional desde ella', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ACCD-S', 'name' => 'Planta diagnóstico'], $h)->assertCreated()->json('data.id');
    $base = ['event_type' => 'occupational_disease', 'site_id' => $site, 'occurred_at' => '2026-10-01T08:00:00-05:00', 'description' => 'Hipoacusia por exposición a ruido en la línea'];

    $this->postJson('/api/v1/records/ACC/sst_events', $base, $h)->assertStatus(422)->assertJsonValidationErrors('diagnosed_on');
    $this->postJson('/api/v1/records/ACC/sst_events', $base + ['diagnosed_on' => now('America/Lima')->addDays(3)->toDateString()], $h)
        ->assertStatus(422)->assertJsonValidationErrors('diagnosed_on');

    // Diagnóstico el lunes 16/11/2026: 5 días hábiles → lunes 23/11/2026 23:59 (no desde la ocurrencia del 01/10).
    $id = $this->postJson('/api/v1/records/ACC/sst_events', $base + ['diagnosed_on' => '2026-11-16'], $h)->assertCreated()->json('data.id');
    $deadline = fn () => accdQuery("select to_char(notification_deadline_at at time zone 'America/Lima', 'YYYY-MM-DD HH24:MI') as e,
                                           (select to_char(deadline_at at time zone 'America/Lima', 'YYYY-MM-DD HH24:MI') from event_notifications n
                                             where n.event_id = sst_events.id and n.authority = 'mtpe_sat' and n.deleted_at is null) as n
                                      from sst_events where id = ?", [$id])[0];
    expect($deadline()->e)->toBe('2026-11-23 23:59')->and($deadline()->n)->toBe('2026-11-23 23:59');

    // Se corrige la fecha del diagnóstico: el plazo del evento y de la notificación pendiente se recalculan.
    $this->patchJson("/api/v1/records/ACC/sst_events/{$id}", ['diagnosed_on' => '2026-11-17'], $h)->assertOk();
    expect($deadline()->e)->toBe('2026-11-24 23:59')->and($deadline()->n)->toBe('2026-11-24 23:59');

    // Un accidente no pide fecha de diagnóstico.
    $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'minor_accident', 'site_id' => $site, 'occurred_at' => now('America/Lima')->subHour()->toIso8601String(),
        'description' => 'Corte leve en la mano con una lámina'], $h)->assertCreated();
});

it('el reporte agrupado de eventos usa el periodo elegido y valida las fechas', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->get('/api/v1/acc/report.xlsx?from=2026-02-01&to=2026-03-31', $h)->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="eventos-2026-02-01-2026-03-31.xlsx"');
    $this->getJson('/api/v1/acc/report.xlsx?from=2026-03-31&to=2026-02-01', $h)->assertStatus(422)->assertJsonValidationErrors('to');
    $this->getJson('/api/v1/acc/report.xlsx?from=ayer', $h)->assertStatus(422)->assertJsonValidationErrors('from');
});
