<?php

// M-VMD: SST ve indicadores agregados sin nombres y los grupos con menos de min_group_size casos no se muestran (RN-VMD-01);
// la restricción funcional del caso pasa a la aptitud del trabajador (RN-VMD-05); la nota se puede compartir con el trabajador.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('medical')->beginTransaction();
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [ESENCIAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'VMD' and deleted_at is null", [HUAYLAS_ID]);
    $role = DB::selectOne("select id from roles where account_id is null and code = 'occupational_doctor'")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);
    DB::select("select set_config('app.company_id', '', false)");
});

afterEach(function () {
    DB::connection('medical')->rollBack();
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
});

it('muestra indicadores agregados ocultando grupos pequeños y publica la restricción del caso en la aptitud', function () {
    $sst = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '63636363', 'first_name' => 'Ana', 'paternal_last_name' => 'Ruido'], $sst)->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into fitness_status (company_id, worker_id, status, valid_from, valid_until, is_current) values (?, ?, 'fit', current_date, current_date + 300, true)", [HUAYLAS_ID, $w]);
    DB::select("select set_config('app.company_id', '', false)");

    $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    $r = medReauth('/api/v1/vmd/reauth', $doc, ESENCIAL_EMAIL)->assertOk()->json('data.reauth_id');
    $m = $doc + ['X-Medical-Reauth' => $r];
    $p = $this->postJson('/api/v1/vmd/programs', ['code' => 'VMD-IND', 'name' => 'Conservación auditiva IND', 'target_risk' => 'noise'], $m)->assertCreated()->json('data.id');
    $case = $this->postJson('/api/v1/vmd/cases', ['program_id' => $p, 'worker_id' => $w, 'risk_classification' => 'high'], $m)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/vmd/cases/{$case}/notes", ['note_type' => 'follow_up', 'plan' => 'Usar protector auditivo doble', 'share_with_worker' => true], $m)->assertCreated();
    $notes = $this->getJson("/api/v1/vmd/cases/{$case}", $m)->assertOk()->json('data.notes');
    expect($notes[0]['shared'])->toBeTrue();

    $this->patchJson("/api/v1/vmd/cases/{$case}", ['work_restrictions' => 'Sin exposición a ruido mayor a 80 dB'], $m)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $f = DB::selectOne('select status, work_restrictions from fitness_status where worker_id = ? and is_current', [$w]);
    DB::select("select set_config('app.company_id', '', false)");
    expect($f->status)->toBe('fit_with_restrictions')->and($f->work_restrictions)->toBe('Sin exposición a ruido mayor a 80 dB');

    // Un solo caso: por debajo del grupo mínimo nada se muestra.
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $ind = $this->getJson('/api/v1/vmd/indicators', authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID))->assertOk()->json('data');
    expect($ind['total'])->toBeNull()->and($ind['by_program']['groups'])->toBe([])->and($ind['by_program']['hidden'])->toBeGreaterThan(0);
});

it('las áreas con menos casos que el grupo mínimo se suman a nivel de su sede (criterio 3)', function () {
    $sst = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'VMD-'.random_int(1000, 99999), 'name' => 'Planta Vigilancia'], $sst)->json('data.id');
    $areas = [];
    foreach (['Molienda', 'Envasado'] as $n) {
        $areas[] = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'A'.random_int(1000, 99999), 'name' => $n], $sst)->json('data.id');
    }
    $workers = [];
    foreach ([0, 0, 0, 1, 1] as $i => $a) {
        $id = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(64000000, 64999999), 'first_name' => "T{$i}", 'paternal_last_name' => 'Vigilancia'], $sst)->json('data.id');
        DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
        DB::update('update workers set current_site_id = ?, current_area_id = ? where id = ?', [$site, $areas[$a], $id]);
        DB::select("select set_config('app.company_id', '', false)");
        $workers[] = $id;
    }
    $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    $m = $doc + ['X-Medical-Reauth' => medReauth('/api/v1/vmd/reauth', $doc, ESENCIAL_EMAIL)->assertOk()->json('data.reauth_id')];
    $p = $this->postJson('/api/v1/vmd/programs', ['code' => 'VMD-SITE', 'name' => 'Conservación auditiva planta', 'target_risk' => 'noise'], $m)->assertCreated()->json('data.id');
    foreach ($workers as $w) {
        $this->postJson('/api/v1/vmd/cases', ['program_id' => $p, 'worker_id' => $w, 'risk_classification' => 'medium'], $m)->assertCreated();
    }

    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $ind = $this->getJson('/api/v1/vmd/indicators', authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID))->assertOk()->json('data');
    $names = collect($ind['by_area']['groups'])->pluck('cases', 'name');
    expect($names['Sede Planta Vigilancia (áreas con pocos casos)'] ?? null)->toBe(5)->and($names->has('Molienda'))->toBeFalse();
});
