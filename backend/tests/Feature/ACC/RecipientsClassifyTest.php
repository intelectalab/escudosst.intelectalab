<?php

// M-ACC: una notificación por destinatario (centro asistencial, aseguradora SCTR, empresa principal) según parámetro,
// fotos del registro, paso «clasificado» antes de las acciones y días perdidos de un mes cerrado como ajuste (RN-ACC-07).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
});

function accrQuery(string $sql, array $b = []): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $r = DB::select($sql, $b);
    DB::select("select set_config('app.company_id', '', false)");

    return $r;
}

it('crea una notificación por destinatario, guarda fotos y exige clasificar antes de las acciones', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ACCR-S', 'name' => 'Planta destinatarios'], $h)->assertCreated()->json('data.id');
    $photo = evidenceFile($h, 'ACC');
    $ctr = $this->postJson('/api/v1/records/CTR/contractors', ['ruc' => '20'.random_int(100000000, 999999999), 'legal_name' => 'Contrata ACCR SAC'], $h)->assertCreated()->json('data.id');
    $ev = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'minor_accident', 'site_id' => $site, 'occurred_at' => now('America/Lima')->subHour()->toIso8601String(),
        'description' => 'Golpe en la mano con una herramienta', 'latitude' => -12.1, 'longitude' => -77.0, 'photo_file_ids' => [$photo], 'is_contractor_event' => true, 'contractor_id' => $ctr], $h)->assertCreated()->json('data');
    $auth = array_column(accrQuery('select authority from event_notifications where event_id = ? and deleted_at is null order by authority', [$ev['id']]), 'authority');
    expect($auth)->toBe(['insurer_sctr', 'medical_center', 'mtpe_sat', 'principal_company'])
        ->and(json_decode(accrQuery('select photo_file_ids from sst_events where id = ?', [$ev['id']])[0]->photo_file_ids, true))->toBe([$photo]);

    // Sin clasificar no se registran acciones del evento.
    $action = ['source_type' => 'event', 'source_id' => $ev['id'], 'action_type' => 'corrective', 'title' => 'Cambiar la herramienta', 'due_on' => now()->addDays(10)->toDateString(),
        'responsible_user_id' => DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id];
    $this->postJson('/api/v1/records/ACR/corrective_actions', $action, $h)->assertStatus(422)->assertJsonValidationErrors('source_id');
    $this->patchJson("/api/v1/records/ACC/sst_events/{$ev['id']}", ['status' => 'registered'], $h)->assertStatus(422)->assertJsonValidationErrors('severity');
    $this->patchJson("/api/v1/records/ACC/sst_events/{$ev['id']}", ['status' => 'registered', 'severity' => 'minor'], $h)->assertOk();
    expect(accrQuery('select classified_at from sst_events where id = ?', [$ev['id']])[0]->classified_at)->not->toBeNull();
    $this->postJson('/api/v1/records/ACR/corrective_actions', $action, $h)->assertCreated();
});

it('los días perdidos que cambian en un mes estadístico cerrado quedan como ajuste del mes', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $when = now('America/Lima')->subMonths(2)->startOfMonth()->addDays(3);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ACCM-S', 'name' => 'Planta mes cerrado'], $h)->assertCreated()->json('data.id');
    $ev = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'disabling_accident', 'site_id' => $site, 'occurred_at' => $when->toIso8601String(), 'description' => 'Caída al mismo nivel en el almacén'], $h)
        ->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $ms = DB::selectOne("insert into monthly_statistics (company_id, period_year, period_month, site_id, worker_count, hours_worked, accidents_fatal, accidents_disabling, lost_days,
                            frequency_index, severity_index, frequency_factor, severity_factor, accident_rate_divisor, is_closed)
                         values (?, ?, ?, null, 10, 2000, 0, 1, 0, 100, 0, 200000, 200000, 200, true) returning id", [HUAYLAS_ID, $when->year, $when->month])->id;
    DB::select("select set_config('app.company_id', '', false)");
    $this->postJson('/api/v1/records/ACC/event_people', ['event_id' => $ev, 'person_role' => 'injured', 'external_person_name' => 'Ana', 'injury_type' => 'Contusión', 'body_part' => 'Rodilla',
        'injury_agent' => 'Piso', 'lost_days' => 4], $h)->assertCreated();
    $m = accrQuery('select lost_days, adjustments from monthly_statistics where id = ?', [$ms])[0];
    expect($m->lost_days)->toBe(4)->and(json_decode($m->adjustments, true)[0]['reason'])->toContain('después del cierre');
});

it('la investigación guarda su cronología, entrevistas y evidencias como listas', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ACCI-S', 'name' => 'Planta investigación'], $h)->assertCreated()->json('data.id');
    $ev = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'disabling_accident', 'site_id' => $site, 'occurred_at' => now('America/Lima')->subHours(3)->toIso8601String(),
        'description' => 'Atrapamiento leve en una faja'], $h)->assertCreated()->json('data.id');
    $inv = accrQuery('select id from investigations where event_id = ?', [$ev])[0]->id;
    $file = evidenceFile($h, 'INV');
    $this->patchJson("/api/v1/records/INV/investigations/{$inv}", [
        'chronology' => [['at' => '2026-11-17T08:00', 'what' => 'Inicio del turno'], ['at' => '', 'what' => '']],
        'interviews' => [['name' => 'Testigo Uno', 'role' => 'testigo', 'on' => '2026-11-17', 'summary' => 'Vio que la guarda estaba retirada']],
        'evidence_file_ids' => [$file, 'no-es-uuid'],
    ], $h)->assertOk();
    $r = accrQuery('select chronology, interviews, evidence_file_ids from investigations where id = ?', [$inv])[0];
    expect(json_decode($r->chronology, true))->toHaveCount(1)
        ->and(json_decode($r->interviews, true)[0]['name'])->toBe('Testigo Uno')
        ->and(json_decode($r->evidence_file_ids, true))->toBe([$file]);
});
