<?php

// M-DES §7.3 / M-ACC RN-ACC-07: los días perdidos del afectado y del evento salen de los descansos vigentes ligados al
// evento; se recalculan desde cero, así que acortar, anular o archivar un descanso los baja. A quien no tiene descansos
// ligados se le respeta el valor escrito a mano.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $role = DB::selectOne("select id from roles where account_id is null and code = 'hr'")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);
    DB::select("select set_config('app.company_id', '', false)");
});

function eldAs(string $email): array
{
    app('auth')->forgetGuards();
    Permissions::flush();
    app()->forgetInstance(\App\Core\Tenancy\CompanyContext::class);

    return authHeaders(tokenFor($email), HUAYLAS_ID);
}

function eldDays(string $table, string $id): int
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $n = (int) DB::selectOne("select lost_days from {$table} where id = ?", [$id])->lost_days;
    DB::select("select set_config('app.company_id', '', false)");

    return $n;
}

it('al acortar, anular o archivar un descanso bajan los días perdidos del evento', function () {
    $sst = eldAs(CORPORATIVO_EMAIL);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ELD-S', 'name' => 'Planta Días'], $sst)->assertCreated()->json('data.id');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '66551122', 'first_name' => 'Marco', 'paternal_last_name' => 'Días'], $sst)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $ev = DB::selectOne("insert into sst_events (company_id, code, event_type, occurred_at, site_id, description) values (?, ?, 'disabling_accident', now() - interval '20 days', ?, 'Golpe con carga') returning id",
        [HUAYLAS_ID, 'ACC-ELD-'.random_int(1000, 99999), $site])->id;
    $person = DB::selectOne("insert into event_people (company_id, event_id, worker_id, person_role, lost_days) values (?, ?, ?, 'injured', 0) returning id", [HUAYLAS_ID, $ev, $w])->id;
    // Otro afectado (de contratista, sin descansos en DES) con días escritos a mano: se respetan.
    DB::insert("insert into event_people (company_id, event_id, external_person_name, person_role, lost_days) values (?, ?, 'Tercero', 'injured', 2)", [HUAYLAS_ID, $ev]);
    DB::select("select set_config('app.company_id', '', false)");

    $hr = eldAs(PROFESIONAL_EMAIL);
    $base = ['worker_id' => $w, 'absence_type' => 'occupational_accident', 'is_work_related' => true, 'sst_event_id' => $ev];
    $a1 = $this->postJson('/api/v1/records/DES/absences', $base + ['starts_on' => now()->subDays(20)->toDateString(), 'ends_on' => now()->subDays(11)->toDateString()], $hr)
        ->assertCreated()->json('data.id');   // 10 días
    $a2 = $this->postJson('/api/v1/records/DES/absences', $base + ['starts_on' => now()->subDays(10)->toDateString(), 'ends_on' => now()->subDays(6)->toDateString()], $hr)
        ->assertCreated()->json('data.id');   // 5 días
    expect(eldDays('event_people', $person))->toBe(15)->and(eldDays('sst_events', $ev))->toBe(17);

    // Se acorta el primero a 4 días: 4 + 5.
    $this->patchJson("/api/v1/records/DES/absences/{$a1}", ['ends_on' => now()->subDays(17)->toDateString()], $hr)->assertOk();
    expect(eldDays('event_people', $person))->toBe(9)->and(eldDays('sst_events', $ev))->toBe(11);

    // Se anula el segundo: solo quedan los 4 días.
    $this->patchJson("/api/v1/records/DES/absences/{$a2}", ['status' => 'cancelled'], $hr)->assertOk();
    expect(eldDays('event_people', $person))->toBe(4)->and(eldDays('sst_events', $ev))->toBe(6);

    // Se archiva el primero: el afectado queda sin días por descanso; el tercero conserva los suyos.
    $this->deleteJson("/api/v1/records/DES/absences/{$a1}", [], $hr)->assertNoContent();
    expect(eldDays('event_people', $person))->toBe(0)->and(eldDays('sst_events', $ev))->toBe(2);
});
