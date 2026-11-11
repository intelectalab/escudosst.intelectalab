<?php

// Portería (M-ING): escaneo del QR del fotocheck con la regla común de acceso, motivos sin términos médicos,
// ingreso por excepción con aviso al Jefe SST, visitante con inducción y «quiénes están dentro».

use App\Modules\ING\GateController;
use App\Modules\REC\Rules\PeopleRules;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function gh(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

/** Sede, punto de acceso y un trabajador con fotocheck que exige aptitud médica (bloqueado). */
function gateSetup($t): array
{
    $h = gh();
    $site = $t->postJson('/api/v1/records/TEN/sites', ['code' => 'GAR-S', 'name' => 'Sede garita QR'], $h)->json('data.id');
    $pt = $t->postJson('/api/v1/records/ING/access_points', ['site_id' => $site, 'code' => 'GQR1', 'name' => 'Garita QR', 'point_type' => 'main_gate'], $h)
        ->assertCreated()->json('data.id');
    $w = $t->postJson('/api/v1/records/TRB/workers', ['document_number' => '95959595', 'first_name' => 'Lucía', 'paternal_last_name' => 'Vera'], $h)->json('data.id');
    $type = $t->postJson('/api/v1/records/FOT/credential_types', ['code' => 'FOT-GAR', 'name' => 'Fotocheck garita', 'validity_months' => 12, 'applies_to' => 'workers'], $h)
        ->assertCreated()->json('data.id');
    $t->postJson('/api/v1/records/FOT/credential_requirements', ['credential_type_id' => $type, 'requirement_type' => 'emo_fitness', 'is_blocking' => true,
        'description' => 'Aptitud médica vigente'], $h)->assertCreated();
    $cred = $t->postJson('/api/v1/records/FOT/credentials', ['credential_type_id' => $type, 'worker_id' => $w], $h)->assertCreated()->json('data');

    return [$h, $pt, $w, $cred];
}

it('lista los puntos de acceso activos para la portería', function () {
    [$h, $pt] = gateSetup($this);
    $r = $this->getJson('/api/v1/ing/gate', $h)->assertOk();
    expect(collect($r->json('data.points'))->pluck('id'))->toContain($pt)
        ->and($r->json('data.kpis'))->toHaveKeys(['granted_today', 'denied_today', 'inside']);
});

it('evalúa el QR del fotocheck y muestra el motivo sin términos médicos', function () {
    [$h, $pt, $w, $cred] = gateSetup($this);
    $token = PeopleRules::credentialToken(HUAYLAS_ID, $cred['credential_number']);
    $url = 'https://app.escudosst.pe/verificar/fotocheck/'.$token;

    $r = $this->postJson('/api/v1/ing/gate/scan', ['access_point_id' => $pt, 'code' => $url], $h)->assertCreated();
    expect($r->json('data.result'))->toBe('denied')
        ->and($r->json('data.granted'))->toBeFalse()
        ->and($r->json('data.person.name'))->toBe('Lucía Vera')
        ->and(json_encode($r->json('data.reasons')))->not->toContain('dica')
        ->and($r->json('data.reasons.0'))->toContain('Requisito pendiente');

    // Ya apto: con el mismo QR (o su número), habilitado.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into fitness_status (company_id, worker_id, status, valid_from, valid_until, is_current) values (?, ?, 'fit', current_date, current_date + 365, true)", [HUAYLAS_ID, $w]);
    $this->postJson('/api/v1/ing/gate/scan', ['access_point_id' => $pt, 'code' => $cred['credential_number']], $h)->assertCreated()
        ->assertJsonPath('data.result', 'granted')->assertJsonPath('data.granted', true);

    // El evento queda inalterable.
    $this->patchJson('/api/v1/records/ING/access_events/'.$r->json('data.id'), ['visitor_name' => 'X'], $h)->assertStatus(403);

    // Está dentro; al registrar la salida, ya no.
    $inside = $this->getJson('/api/v1/ing/inside', $h)->assertOk()->json('data.people');
    $row = collect($inside)->firstWhere('name', 'Lucía Vera');
    expect($row)->not->toBeNull();
    $this->postJson('/api/v1/ing/gate/exit', ['event_id' => $row['event_id']], $h)->assertCreated();
    expect(collect($this->getJson('/api/v1/ing/inside', $h)->json('data.people'))->firstWhere('name', 'Lucía Vera'))->toBeNull();
});

it('reconoce el DNI escrito a mano y rechaza un código desconocido sin registrar', function () {
    [$h, $pt] = gateSetup($this);
    $this->postJson('/api/v1/ing/gate/scan', ['access_point_id' => $pt, 'code' => '95959595'], $h)->assertCreated()
        ->assertJsonPath('data.person.name', 'Lucía Vera');
    $this->postJson('/api/v1/ing/gate/scan', ['access_point_id' => $pt, 'code' => 'ZZZ-NO-EXISTE'], $h)->assertOk()
        ->assertJsonPath('data.result', 'unknown');
});

it('permite el ingreso por excepción con motivo y avisa al Jefe SST', function () {
    [$h, $pt, , $cred] = gateSetup($this);
    $r = $this->postJson('/api/v1/ing/gate/scan', ['access_point_id' => $pt, 'code' => $cred['credential_number'], 'override_reason' => 'Autorizado por el jefe de planta para emergencia'], $h)
        ->assertCreated()->assertJsonPath('data.result', 'overridden')->assertJsonPath('data.granted', true);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where event_code = 'ing.access_override' and subject_id = ?", [$r->json('data.id')])->n)->toBeGreaterThan(0);
});

it('registra al visitante solo con la inducción de visita aceptada', function () {
    [$h, $pt] = gateSetup($this);
    $base = ['access_point_id' => $pt, 'full_name' => 'Mario Quispe', 'document_number' => '41414141', 'host_name' => 'Ana Torres (SST)'];
    $this->postJson('/api/v1/ing/gate/visitor', $base, $h)->assertStatus(422)->assertJsonValidationErrors('induction_accepted');
    $this->postJson('/api/v1/ing/gate/visitor', $base + ['induction_accepted' => true], $h)->assertCreated()
        ->assertJsonPath('data.result', 'granted')->assertJsonPath('data.person.document_masked', '****4141');
    $row = collect($this->getJson('/api/v1/ing/inside', $h)->json('data.people'))->firstWhere('name', 'Mario Quispe');
    expect($row['host_name'])->toBe('Ana Torres (SST)')->and($row['kind'])->toBe('visitor');
    // Salida con el mismo documento.
    $this->postJson('/api/v1/ing/gate/visitor', ['access_point_id' => $pt, 'full_name' => 'Mario Quispe', 'document_number' => '41414141', 'direction' => 'out'], $h)->assertCreated();
    expect(collect($this->getJson('/api/v1/ing/inside', $h)->json('data.people'))->firstWhere('name', 'Mario Quispe'))->toBeNull();
});

it('no deja operar la portería a un rol sin permiso de registrar ingresos', function () {
    // Plan Esencial: el módulo no está contratado.
    $this->getJson('/api/v1/ing/gate', authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID))->assertStatus(403);
});

it('oculta los motivos médicos', function () {
    expect(GateController::safeReasons(['Fotocheck no habilitado: Aptitud médica vigente, SCTR salud y pensión vigentes', 'Sin fotocheck']))
        ->toBe(['Fotocheck no habilitado: Requisito pendiente, SCTR salud y pensión vigentes', 'Sin fotocheck']);
});
