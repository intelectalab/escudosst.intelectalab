<?php

// M-AUI RN-AUI-07: el ítem del checklist vincula registros del sistema como evidencia (se validan y guardan con su etiqueta, salen en
// el checklist en Excel), y SCR-AUI-02: cada requisito del autodiagnóstico SUNAFIL enlaza a los registros de su módulo.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'AUI' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
});

it('vincula registros del sistema como evidencia del ítem y los muestra en el checklist en Excel', function () {
    $audit = $this->postJson('/api/v1/records/AUI/audits', ['audit_type' => 'internal', 'standard' => 'ley_29783', 'starts_on' => '2032-03-01', 'ends_on' => '2032-03-02'], $this->h)
        ->assertCreated()->json('data');
    $item = $this->postJson('/api/v1/records/AUI/audit_checklist_items', ['audit_id' => $audit['id'], 'clause_code' => 'L01', 'requirement' => '¿Se capacita al personal?'], $this->h)
        ->assertCreated()->json('data.id');
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'AUIL-'.random_int(1000, 9999), 'name' => 'Planta evidencia'], $this->h)->assertCreated()->json('data.id');

    $types = collect($this->getJson('/api/v1/aui/record-types', $this->h)->assertOk()->json('data'));
    expect($types->pluck('table'))->toContain('sites')->toContain('sst_events')->not->toContain('audit_checklist_items')
        ->and($types->pluck('module')->intersect(Permissions::MEDICAL_MODULES)->all())->toBe([]);

    $this->patchJson("/api/v1/records/AUI/audit_checklist_items/{$item}", ['linked_records' => [['table' => 'no_existe', 'id' => $site]]], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('linked_records');
    $this->patchJson("/api/v1/records/AUI/audit_checklist_items/{$item}", ['linked_records' => [['table' => 'sites', 'id' => '01900000-0000-7000-8000-00000000dead']]], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('linked_records');
    $this->patchJson("/api/v1/records/AUI/audit_checklist_items/{$item}", ['linked_records' => [['table' => 'sites', 'id' => $site], ['table' => 'sites', 'id' => $site], ['table' => 'audits', 'id' => $audit['id']]]], $this->h)
        ->assertOk();

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $links = json_decode(DB::selectOne('select linked_records from audit_checklist_items where id = ?', [$item])->linked_records, true);
    DB::select("select set_config('app.company_id', '', false)");
    expect($links)->toHaveCount(2)
        ->and($links[0])->toMatchArray(['module' => 'TEN', 'table' => 'sites', 'id' => $site])->and($links[0]['label'])->toContain('Planta evidencia')
        ->and($links[1])->toMatchArray(['module' => 'AUI', 'table' => 'audits', 'id' => $audit['id']]);

    $res = $this->get("/api/v1/aui/audits/{$audit['id']}/checklist.xlsx", $this->h)->assertOk();
    expect($res->headers->get('Content-Disposition'))->toContain('checklist-'.$audit['code']);
});

it('enlaza cada requisito del autodiagnóstico a los registros de su módulo', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'AUIP-'.random_int(1000, 9999), 'name' => 'Planta preparación'], $this->h)->assertCreated()->json('data.id');
    $items = ['areas' => [
        ['code' => 'committee', 'name' => 'Comité SST', 'module' => 'TEN', 'table' => 'sites', 'pct' => 50, 'result' => 'fails', 'severity_label' => 'Grave', 'detail' => 'Prueba'],
        ['code' => 'emo', 'name' => 'EMO', 'module' => 'EMO', 'table' => 'medical_exam_orders', 'pct' => 80, 'result' => 'partial', 'severity_label' => 'Grave', 'detail' => 'Agregado'],
    ]];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into self_diagnostics (company_id, title, diagnostic_on, checklist_version, items, score_pct, critical_gaps, status)
                values (?, 'Autodiagnóstico de prueba', current_date + 3650, 'SUN-2026.1', ?::jsonb, 65, 0, 'completed')", [HUAYLAS_ID, json_encode($items)]);
    DB::select("select set_config('app.company_id', '', false)");

    $req = collect($this->getJson('/api/v1/aui/calendar', $this->h)->assertOk()->json('data.preparation.requirements'));
    $sites = $req->firstWhere('code', 'committee');
    expect($sites['readable'])->toBeTrue()->and($sites['link'])->toBe('/m/ten?t=sites')->and($sites['records_total'])->toBeGreaterThan(0)
        ->and(collect($sites['records'])->pluck('id'))->toContain($site)
        ->and(collect($sites['records'])->firstWhere('id', $site)['link'])->toBe("/m/ten?t=sites&open={$site}");
    $emo = $req->firstWhere('code', 'emo');
    expect($emo['records'])->toBe([])->and($emo['result'])->toBe('partial');
});
