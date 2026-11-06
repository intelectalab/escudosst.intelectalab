<?php

// SCR-SUS-08 · Catálogos globales (parámetros legales con vigencia, normas, peligros, paquetes, feriados).
// La conexión 'platform' no entra en la transacción de la prueba: se guarda el estado y se restaura.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->started = $db->selectOne("select now() - interval '1 second' as t")->t;
    $this->params = $db->select('select id, valid_to, to_confirm, confirmed_by, confirmed_at from legal_parameters where company_id is null');
    $this->packs = $db->select('select id, status from sector_packs');
});

afterEach(function () {
    $db = DB::connection('platform');
    $db->transaction(function () use ($db) {
        foreach (['legal_parameters' => 'company_id is null and ', 'legal_norms' => '', 'hazard_catalog' => 'company_id is null and ', 'holidays' => ''] as $t => $extra) {
            $db->delete("delete from {$t} where {$extra}created_at >= ?", [$this->started]);
        }
        foreach ($this->params as $p) {
            $db->update('update legal_parameters set to_confirm = ?, confirmed_by = ?, confirmed_at = ?, valid_to = ? where id = ?',
                [$p->to_confirm, $p->confirmed_by, $p->confirmed_at, $p->valid_to, $p->id]);
        }
        foreach ($this->packs as $p) {
            $db->update('update sector_packs set status = ? where id = ?', [$p->status, $p->id]);
        }
        $db->update('update holidays set deleted_at = null where deleted_at >= ?', [$this->started]);
    });
});

function catalogs(string $method, string $uri = '', array $data = [])
{
    return test()->json($method, '/api/v1/internal/catalogs'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

it('solo el equipo de la plataforma ve los catálogos', function () {
    $this->getJson('/api/v1/internal/catalogs', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertStatus(403);
    $s = catalogs('GET')->assertOk()->json('data');
    expect($s['legal_parameters']['total'])->toBeGreaterThan(40)
        ->and($s['legal_norms']['total'])->toBeGreaterThan(10)
        ->and($s['hazards']['total'])->toBeGreaterThan(40);
});

it('un parámetro legal nunca se sobrescribe: se crea una versión nueva y se cierra la anterior', function () {
    $params = collect(catalogs('GET', '/legal-parameters')->assertOk()->json('data'));
    $p = $params->firstWhere('key', 'committee_threshold_workers');
    expect($p['value'])->toBe('20')->and($p['is_current'])->toBeTrue();

    catalogs('POST', "/legal-parameters/{$p['id']}/versions", ['value' => 'veinte', 'valid_from' => '2030-01-01', 'reason' => 'Prueba de formato'])
        ->assertStatus(422)->assertJsonPath('errors.value.0', 'El valor debe ser un número entero.');
    catalogs('POST', "/legal-parameters/{$p['id']}/versions", ['value' => '25', 'valid_from' => '2000-01-01', 'reason' => 'Fecha anterior'])
        ->assertStatus(422);

    catalogs('POST', "/legal-parameters/{$p['id']}/versions", ['value' => '25', 'valid_from' => '2030-01-01', 'reason' => 'Modificación de prueba de la norma'])
        ->assertCreated();

    $versions = collect(catalogs('GET', '/legal-parameters')->json('data'))->where('key', 'committee_threshold_workers')->values();
    expect($versions)->toHaveCount(2)
        ->and($versions[0]['value'])->toBe('25')
        ->and($versions[0]['valid_from'])->toBe('2030-01-01')
        ->and($versions[0]['to_confirm'])->toBeTrue()
        ->and($versions[1]['valid_to'])->toBe('2029-12-31')
        ->and($versions[1]['is_current'])->toBeTrue();   // hoy sigue rigiendo 20
});

it('un especialista confirma el valor y queda registrado', function () {
    $p = collect(catalogs('GET', '/legal-parameters')->json('data'))->firstWhere('key', 'committee_min_members');
    catalogs('POST', "/legal-parameters/{$p['id']}/confirm", [])->assertStatus(422);
    catalogs('POST', "/legal-parameters/{$p['id']}/confirm", ['confirmed_by' => 'Ing. Rosa Salas (especialista SST)'])->assertNoContent();
    catalogs('POST', "/legal-parameters/{$p['id']}/confirm", ['confirmed_by' => 'Otra persona'])->assertStatus(409);

    $after = collect(catalogs('GET', '/legal-parameters')->json('data'))->firstWhere('id', $p['id']);
    expect($after['to_confirm'])->toBeFalse()->and($after['confirmed_by'])->toBe('Ing. Rosa Salas (especialista SST)');
});

it('agrega y edita normas, peligros y feriados; cambia el estado de un paquete', function () {
    $norm = ['norm_type' => 'ministerial_resolution', 'number' => '999-2026-TR', 'title' => 'Norma de prueba', 'scope' => 'general', 'status' => 'current'];
    $id = catalogs('POST', '/legal-norms', $norm)->assertCreated()->json('data.id');
    catalogs('POST', '/legal-norms', $norm)->assertStatus(422)->assertJsonPath('errors.number.0', 'Ya existe una norma de ese tipo con ese número.');
    catalogs('PATCH', "/legal-norms/{$id}", ['status' => 'repealed'] + $norm)->assertStatus(422);
    catalogs('PATCH', "/legal-norms/{$id}", ['status' => 'repealed', 'repealed_on' => '2026-11-01'] + $norm)->assertOk();

    $hz = catalogs('POST', '/hazards', ['code' => 'PRB-01', 'hazard_family' => 'mechanical', 'hazard' => 'Peligro de prueba', 'risk' => 'Riesgo de prueba', 'typical_controls' => ['Guarda', ' ']])
        ->assertCreated()->json('data.id');
    catalogs('PATCH', "/hazards/{$hz}", ['hazard_family' => 'mechanical', 'hazard' => 'Peligro de prueba', 'risk' => 'Riesgo', 'is_active' => false])->assertOk();
    $row = collect(catalogs('GET', '/hazards')->json('data'))->firstWhere('id', $hz);
    expect($row['is_active'])->toBeFalse()->and($row['typical_controls'])->toBe([]);

    $hol = catalogs('POST', '/holidays', ['holiday_on' => '2030-03-15', 'name' => 'Feriado de prueba'])->assertCreated()->json('data.id');
    catalogs('POST', '/holidays', ['holiday_on' => '2030-03-15', 'name' => 'Repetido'])->assertStatus(422);
    catalogs('DELETE', "/holidays/{$hol}")->assertNoContent();

    $pack = catalogs('GET', '/sector-packs')->assertOk()->json('data.0');
    expect($pack['items'])->not->toBeEmpty();
    catalogs('PATCH', "/sector-packs/{$pack['id']}", ['status' => 'retired'])->assertNoContent();
    expect(collect(catalogs('GET', '/sector-packs')->json('data'))->firstWhere('id', $pack['id'])['status'])->toBe('retired');
});

it('clasifica los parámetros por área y tipo, y no deja confirmar uno sin valor', function () {
    $params = collect(catalogs('GET', '/legal-parameters')->json('data'))->keyBy('key');

    expect($params['committee_threshold_workers'])->toMatchArray(['area' => 'sst', 'kind' => 'legal'])
        ->and($params['arco_response_business_days'])->toMatchArray(['area' => 'privacy', 'kind' => 'legal'])
        ->and($params['web.popup_max_per_visit'])->toMatchArray(['area' => 'web', 'kind' => 'internal'])
        ->and($params['web.cookie_consent_validity']['kind'])->toBe('pending')
        ->and($params['web.fine_table_micro']['kind'])->toBe('pending')
        ->and($params['web.business_holidays']['kind'])->toBe('reference');

    catalogs('POST', "/legal-parameters/{$params['web.cookie_consent_validity']['id']}/confirm", ['confirmed_by' => 'Alguien'])
        ->assertStatus(409);
    catalogs('POST', "/legal-parameters/{$params['web.business_holidays']['id']}/confirm", ['confirmed_by' => 'Alguien'])
        ->assertStatus(409);
});
