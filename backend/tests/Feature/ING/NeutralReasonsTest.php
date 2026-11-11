<?php

// Motivos neutros de un ingreso negado (M-ING RN-ING-02): se guardan sin términos médicos (texto y código) y la lista
// de ingresos tampoco los muestra; el jefe del área del trabajador recibe el aviso del intento (M-ING §10).

use App\Modules\REC\Rules\AddonRules;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function inrH(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

it('guarda el motivo neutro con su código y avisa al jefe del área', function () {
    $h = inrH();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'INR-S', 'name' => 'Sede neutra'], $h)->assertCreated()->json('data.id');
    $area = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'INR-A', 'name' => 'Mantenimiento'], $h)->assertCreated()->json('data.id');
    $pt = $this->postJson('/api/v1/records/ING/access_points', ['site_id' => $site, 'code' => 'INR1', 'name' => 'Garita neutra', 'point_type' => 'main_gate'], $h)->assertCreated()->json('data.id');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '93939393', 'first_name' => 'Rosa', 'paternal_last_name' => 'Neutra'], $h)->assertCreated()->json('data.id');
    $boss = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '93939394', 'first_name' => 'Jefe', 'paternal_last_name' => 'Área'], $h)->assertCreated()->json('data.id');
    $type = $this->postJson('/api/v1/records/FOT/credential_types', ['code' => 'FOT-INR', 'name' => 'Fotocheck neutro', 'validity_months' => 12, 'applies_to' => 'workers'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/FOT/credential_requirements', ['credential_type_id' => $type, 'requirement_type' => 'emo_fitness', 'is_blocking' => true,
        'description' => 'Aptitud médica vigente (EMO)'], $h)->assertCreated();
    $cred = $this->postJson('/api/v1/records/FOT/credentials', ['credential_type_id' => $type, 'worker_id' => $w], $h)->assertCreated()->json('data');

    // El jefe del área es un trabajador con usuario.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $bossUser = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    DB::update('update workers set user_id = null where user_id = ?', [$bossUser]);
    DB::update('update workers set user_id = ? where id = ?', [$bossUser, $boss]);
    DB::update('update areas set manager_worker_id = ? where id = ?', [$boss, $area]);
    DB::update('update workers set current_area_id = ?, current_site_id = ? where id = ?', [$area, $site, $w]);

    $r = $this->postJson('/api/v1/ing/gate/scan', ['access_point_id' => $pt, 'code' => $cred['credential_number']], $h)->assertCreated();
    expect($r->json('data.result'))->toBe('denied');
    $id = $r->json('data.id');

    // En la base: texto y código neutros (no solo al mostrarlo).
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $row = DB::selectOne('select deny_reasons::text as r, deny_codes::text as c from access_events where id = ?', [$id]);
    expect($row->r)->toContain('Requisito pendiente')->not->toMatch('/dic|aptitud|EMO/iu')
        ->and(json_decode($row->c, true))->toBe(['requirement_pending']);

    // En la lista de ingresos (motor de registros) tampoco aparece.
    $ev = $this->getJson("/api/v1/records/ING/access_events/{$id}", $h)->assertOk()->json('data');
    expect(json_encode($ev['deny_reasons'], JSON_UNESCAPED_UNICODE))->toContain('Requisito pendiente')->not->toMatch('/dic|aptitud/iu');

    // Aviso inmediato al jefe del área.
    $n = DB::selectOne("select title, body, link from notifications where event_code = 'ing.access_denied' and subject_id = ? and recipient_user_id = ?", [$id, $bossUser]);
    expect($n)->not->toBeNull()->and($n->body)->toContain('Rosa Neutra')->toContain('Mantenimiento')->not->toMatch('/dic|aptitud/iu');
});

it('traduce los requisitos a códigos neutros', function () {
    expect(AddonRules::neutralReason('emo_fitness', 'Lo que sea'))->toBe(['requirement_pending', 'Requisito pendiente'])
        ->and(AddonRules::neutralReason('training', 'Examen médico ocupacional'))->toBe(['requirement_pending', 'Requisito pendiente'])
        ->and(AddonRules::neutralReason('sctr', 'SCTR salud y pensión vigentes'))->toBe(['sctr_pending', 'SCTR salud y pensión vigentes'])
        ->and(AddonRules::neutralReason('training', 'Trabajo en altura'))->toBe(['training_pending', 'Trabajo en altura']);
});

it('los ingresos antiguos con motivo médico se ven neutros en la lista', function () {
    $h = inrH();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'INR-O', 'name' => 'Sede antigua'], $h)->assertCreated()->json('data.id');
    $pt = $this->postJson('/api/v1/records/ING/access_points', ['site_id' => $site, 'code' => 'INR-OLD', 'name' => 'Garita antigua', 'point_type' => 'main_gate'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $id = DB::selectOne(
        "insert into access_events (company_id, access_point_id, subject_type, visitor_name, direction, result, deny_reasons, occurred_at)
         values (?, ?, 'visitor', 'Visita antigua', 'in', 'denied', ?::jsonb, now()) returning id",
        [HUAYLAS_ID, $pt, json_encode(['Fotocheck no habilitado: Aptitud médica vigente, Inducción'], JSON_UNESCAPED_UNICODE)]
    )->id;
    $ev = $this->getJson("/api/v1/records/ING/access_events/{$id}", $h)->assertOk()->json('data');
    expect($ev['deny_reasons'])->toBe(['Fotocheck no habilitado: Requisito pendiente, Inducción']);
});
