<?php

// SCR-SUS-08 · Contenido que valida un especialista SST/legal, editable por el Dueño de la plataforma:
// familias de peligro prohibidas para menores (con valor propio por empresa), requisitos del catálogo por norma
// (dispara «requiere revisión» en las empresas) y catálogo SCAT (lo lee el asistente de investigación).
// La conexión 'platform' no entra en la transacción de la prueba: se guarda el estado y se restaura.

use App\Core\Tenancy\CompanyContext;
use App\Modules\INV\ScatCatalog;
use App\Modules\VUL\MinorGuard;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->started = $db->selectOne("select now() - interval '1 second' as t")->t;
    $this->params = $db->select('select id, valid_to from legal_parameters where company_id is null');
    $this->reqs = $db->select('select * from legal_norm_requirements');
    $this->scat = $db->select('select * from scat_catalog_items');
    ScatCatalog::flush();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    $db->transaction(function () use ($db) {
        $db->delete('delete from legal_parameters where company_id is null and created_at >= ?', [$this->started]);
        foreach ($this->params as $p) {
            $db->update('update legal_parameters set valid_to = ? where id = ?', [$p->valid_to, $p->id]);
        }
        $db->delete('delete from legal_norm_requirements where created_at >= ?', [$this->started]);
        foreach ($this->reqs as $r) {
            $db->update('update legal_norm_requirements set article = ?, requirement = ?, topic = ?, applicability = ?, evidence_expected = ?, frequency = ?,
                                to_confirm = ?, confirmed_by = ?, confirmed_at = ?, deleted_at = ?, sort_order = ? where id = ?',
                [$r->article, $r->requirement, $r->topic, $r->applicability, $r->evidence_expected, $r->frequency, $r->to_confirm, $r->confirmed_by, $r->confirmed_at,
                    $r->deleted_at, $r->sort_order, $r->id]);
        }
        $db->delete('delete from scat_catalog_items where created_at >= ?', [$this->started]);
        foreach ($this->scat as $i) {
            $db->update('update scat_catalog_items set label = ?, explanation = ?, category = ?, sort_order = ?, is_active = ?, to_confirm = ?, confirmed_by = ?, confirmed_at = ? where id = ?',
                [$i->label, $i->explanation, $i->category, $i->sort_order, $i->is_active, $i->to_confirm, $i->confirmed_by, $i->confirmed_at, $i->id]);
        }
    });
    ScatCatalog::flush();
});

function specCat(string $method, string $uri = '', array $data = [])
{
    return test()->json($method, '/api/v1/internal/catalogs'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

function specInvestigation($t, array $h): string
{
    $site = collect($t->getJson('/api/v1/records/ACC/sst_events/options/site_id', $h)->json('data'))->first()['id'];
    $event = $t->postJson('/api/v1/records/ACC/sst_events', ['code' => 'ACC-SPEC-'.random_int(100, 999), 'event_type' => 'incident', 'site_id' => $site,
        'occurred_at' => now('America/Lima')->subDay()->toIso8601String(), 'description' => 'Evento de prueba del catálogo SCAT'], $h)->assertCreated()->json('data.id');

    return $t->postJson('/api/v1/records/INV/investigations', ['event_id' => $event, 'method' => 'scat'], $h)->assertCreated()->json('data.id');
}

/** Pasa al Dueño de prueba a Soporte (dentro de la transacción de la prueba). */
function specAsSupport(): void
{
    $admin = DB::selectOne('select id from app_auth_lookup_user(?)', [ADMIN_EMAIL])->id;
    DB::connection('pgsql')->select("select set_config('app.user_id', ?, false)", [$admin]);
    DB::connection('pgsql')->update("update users set platform_role = 'support' where id = ?", [$admin]);
    app('auth')->forgetGuards();
}

it('el parámetro de menores: lista de familias validada, dónde se usa y valor propio de una empresa', function () {
    $p = collect(specCat('GET', '/legal-parameters')->assertOk()->json('data'))->first(fn ($x) => $x['key'] === 'minor_forbidden_hazard_families' && $x['is_current']);
    expect($p['used_in'])->not->toBeEmpty()->and(json_decode($p['value'], true))->toContain('chemical');

    specCat('POST', "/legal-parameters/{$p['id']}/versions", ['value' => '["chemical","radiactivo"]', 'valid_from' => '2030-01-01', 'reason' => 'Prueba de familias'])
        ->assertStatus(422)->assertJsonPath('errors.value.0', 'Elige familias de peligro del catálogo.');
    specCat('POST', "/legal-parameters/{$p['id']}/versions", ['value' => '["electrical","chemical","chemical"]', 'valid_from' => '2030-01-01', 'reason' => 'Prueba de familias'])
        ->assertCreated();
    $new = collect(specCat('GET', '/legal-parameters')->json('data'))->first(fn ($x) => $x['key'] === 'minor_forbidden_hazard_families' && $x['valid_from'] === '2030-01-01');
    expect($new['value'])->toBe('["chemical","electrical"]')->and($new['to_confirm'])->toBeTrue();

    // Valor propio de una empresa: reemplaza al global solo para ella.
    specCat('PUT', "/legal-parameters/{$p['id']}/companies/".ANDINAS_ID, ['value' => '["mechanical"]', 'reason' => 'Política más estricta de la empresa'])->assertOk();
    $o = specCat('GET', "/legal-parameters/{$p['id']}/companies")->assertOk()->json('data');
    expect(collect($o['overrides'])->firstWhere('company_id', ANDINAS_ID)['value'])->toBe('["mechanical"]')
        ->and(collect($o['companies'])->pluck('id'))->toContain(HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    app()->instance(CompanyContext::class, new CompanyContext(ANDINAS_ID, '', 'sst_manager', 'Andinas', ''));
    expect(MinorGuard::forbiddenFamilies())->toBe(['mechanical']);
    app()->forgetInstance(CompanyContext::class);

    specCat('DELETE', "/legal-parameters/{$p['id']}/companies/".ANDINAS_ID, ['reason' => 'Vuelve al valor general'])->assertNoContent();
    expect(collect(specCat('GET', "/legal-parameters/{$p['id']}/companies")->json('data.overrides'))->firstWhere('company_id', ANDINAS_ID))->toBeNull();
    specCat('DELETE', "/legal-parameters/{$p['id']}/companies/".ANDINAS_ID)->assertNotFound();
    expect(DB::connection('platform')->selectOne("select count(*) as n from audit_logs where action in ('legal_parameter_company_set', 'legal_parameter_company_removed') and created_at >= ?", [$this->started])->n)->toBe(2);
});

it('edita los requisitos del catálogo de una norma: crea, cambia, confirma, archiva y marca la revisión en las empresas', function () {
    $norm = collect(specCat('GET', '/legal-norms')->json('data'))->firstWhere('number', '29783');
    $list = specCat('GET', "/legal-norms/{$norm['id']}/requirements")->assertOk()->json('data.requirements');
    expect($list)->not->toBeEmpty()->and($list[0]['to_confirm'])->toBeTrue();

    // Una empresa ya agregó un requisito de esa norma: al cambiar el catálogo queda «requiere revisión».
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $me = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    $req = $this->postJson('/api/v1/mrl/catalog/add', ['items' => [['template_id' => $list[0]['id']]], 'responsible_user_id' => $me], $h)->assertCreated()->json('data.ids.0');

    specCat('POST', "/legal-norms/{$norm['id']}/requirements", ['requirement' => 'corto'])->assertStatus(422);
    $id = specCat('POST', "/legal-norms/{$norm['id']}/requirements", ['article' => 'Art. 999', 'requirement' => 'Requisito de prueba del catálogo de la norma',
        'applicability' => 'Empresas con 20 o más trabajadores', 'evidence_expected' => 'Registro de prueba', 'frequency' => 'Anual'])
        ->assertCreated()->assertJsonPath('data.flagged.requirements', fn ($n) => $n >= 1)->json('data.id');
    specCat('POST', "/legal-norms/{$norm['id']}/requirements", ['article' => 'Art. 999', 'requirement' => 'Requisito de prueba del catálogo de la norma'])
        ->assertStatus(422)->assertJsonPath('errors.requirement.0', 'Esa norma ya tiene ese requisito.');

    specCat('POST', "/norm-requirements/{$id}/confirm", ['confirmed_by' => 'Ing. Rosa Salas'])->assertNoContent();
    specCat('POST', "/norm-requirements/{$id}/confirm", ['confirmed_by' => 'Ing. Rosa Salas'])->assertStatus(409);
    specCat('PATCH', "/norm-requirements/{$id}", ['article' => 'Art. 999', 'requirement' => 'Requisito de prueba del catálogo de la norma (modificado)'])->assertOk();
    $row = collect(specCat('GET', "/legal-norms/{$norm['id']}/requirements")->json('data.requirements'))->firstWhere('id', $id);
    expect($row['to_confirm'])->toBeTrue()->and($row['confirmed_by'])->toBeNull()->and($row['applicability'])->toBeNull();

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $flagged = DB::selectOne('select needs_review, review_reason from legal_requirements where id = ?', [$req]);
    expect($flagged->needs_review)->toBeTrue()->and($flagged->review_reason)->toContain('Art. 999');

    specCat('POST', "/norm-requirements/{$id}/archive", ['reason' => 'Ya no aplica'])->assertOk();
    $row = collect(specCat('GET', "/legal-norms/{$norm['id']}/requirements")->json('data.requirements'))->firstWhere('id', $id);
    expect($row['archived'])->toBeTrue();
    // Archivado: ya no aparece en «Agregar desde el catálogo» de las empresas.
    $tpl = collect($this->getJson("/api/v1/mrl/catalog/requirements?norm_ids={$norm['id']}", authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->json('data.0.requirements'));
    expect($tpl->firstWhere('id', $id))->toBeNull();
    specCat('POST', "/norm-requirements/{$id}/restore")->assertOk();
    specCat('POST', "/norm-requirements/{$id}/restore")->assertStatus(409);
});

it('el catálogo SCAT se edita en la plataforma y lo usa el asistente de investigación', function () {
    $items = collect(specCat('GET', '/scat')->assertOk()->json('data'));
    expect($items->where('level', 'nac'))->toHaveCount(20)->and($items->firstWhere('code', 'A01')['to_confirm'])->toBeTrue();

    specCat('POST', '/scat', ['level' => 'immediate_act', 'code' => 'S99', 'label' => 'Prefijo equivocado'])->assertStatus(422);
    specCat('POST', '/scat', ['level' => 'immediate_act', 'code' => 'A01', 'label' => 'Duplicado'])->assertStatus(422);
    $id = specCat('POST', '/scat', ['level' => 'immediate_act', 'code' => 'a99', 'label' => 'Acto de prueba del catálogo', 'explanation' => 'Para la prueba'])
        ->assertCreated()->assertJsonPath('data.code', 'A99')->json('data.id');

    $a02 = $items->firstWhere('code', 'A02');
    specCat('PATCH', "/scat/{$a02['id']}", ['label' => $a02['label'], 'is_active' => false])->assertOk();
    $a01 = $items->firstWhere('code', 'A01');
    specCat('POST', "/scat/{$a01['id']}/confirm", ['confirmed_by' => 'Ing. Rosa Salas'])->assertNoContent();
    specCat('PATCH', "/scat/{$a01['id']}", ['label' => 'Operar equipos sin autorización (texto revisado)'])->assertOk();
    expect(collect(specCat('GET', '/scat')->json('data'))->firstWhere('id', $a01['id'])['to_confirm'])->toBeTrue();

    // Reordenar: A99 primero.
    $acts = collect(specCat('GET', '/scat')->json('data'))->where('level', 'immediate_act')->pluck('id')->all();
    specCat('POST', '/scat/reorder', ['level' => 'immediate_act', 'ids' => array_slice($acts, 1)])->assertStatus(422);
    specCat('POST', '/scat/reorder', ['level' => 'immediate_act', 'ids' => [$id, ...array_values(array_diff($acts, [$id]))]])->assertNoContent();

    ScatCatalog::flush();
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $c = $this->getJson('/api/v1/inv/scat/catalog', $h)->assertOk()->json('data');
    $codes = array_column($c['acts'], 'code');
    expect($codes[0])->toBe('A99')->and($codes)->not->toContain('A02')
        ->and(collect($c['acts'])->firstWhere('code', 'A01')['label'])->toBe('Operar equipos sin autorización (texto revisado)')
        ->and(collect($c['acts'])->firstWhere('code', 'A99')['explanation'])->toBe('Para la prueba');

    // Un código desactivado ya no se puede elegir en una investigación nueva.
    $inv = specInvestigation($this, $h);
    $this->putJson("/api/v1/inv/investigations/{$inv}/scat", ['contact' => ['C02'], 'immediate' => [['code' => 'A02']], 'basic' => [['code' => 'FP01']], 'nac' => []], $h)
        ->assertStatus(422);
    $this->putJson("/api/v1/inv/investigations/{$inv}/scat", ['contact' => ['C02'], 'immediate' => [['code' => 'A99']], 'basic' => [['code' => 'FP01', 'parent' => 'A99']], 'nac' => []], $h)
        ->assertOk();
});

it('Soporte solo lee; una empresa no entra; los cambios son solo del Dueño', function () {
    $p = collect(specCat('GET', '/legal-parameters')->json('data'))->firstWhere('key', 'minor_forbidden_hazard_families');
    $norm = collect(specCat('GET', '/legal-norms')->json('data'))->firstWhere('number', '29783');
    $item = collect(specCat('GET', '/scat')->json('data'))->firstWhere('code', 'A01');

    $company = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->getJson('/api/v1/internal/catalogs/scat', $company)->assertForbidden();
    $this->patchJson("/api/v1/internal/catalogs/scat/{$item['id']}", ['label' => 'Cambio no permitido'], $company)->assertForbidden();
    $this->postJson("/api/v1/internal/catalogs/legal-norms/{$norm['id']}/requirements", ['requirement' => 'Requisito que no debe crearse'], $company)->assertForbidden();
    $this->putJson("/api/v1/internal/catalogs/legal-parameters/{$p['id']}/companies/".HUAYLAS_ID, ['value' => '[]', 'reason' => 'No permitido'], $company)->assertForbidden();

    specAsSupport();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->getJson('/api/v1/internal/catalogs/scat', $h)->assertOk();
    $this->getJson("/api/v1/internal/catalogs/legal-norms/{$norm['id']}/requirements", $h)->assertOk();
    $this->patchJson("/api/v1/internal/catalogs/scat/{$item['id']}", ['label' => 'Cambio de soporte'], $h)->assertForbidden();
    $this->postJson("/api/v1/internal/catalogs/legal-norms/{$norm['id']}/requirements", ['requirement' => 'Requisito que no debe crearse'], $h)->assertForbidden();
    $this->postJson("/api/v1/internal/catalogs/legal-parameters/{$p['id']}/confirm", ['confirmed_by' => 'Soporte'], $h)->assertForbidden();
    $this->putJson("/api/v1/internal/catalogs/legal-parameters/{$p['id']}/companies/".HUAYLAS_ID, ['value' => '[]', 'reason' => 'No permitido'], $h)->assertForbidden();
});
