<?php

// M-PSE: criterios IPERC del sector instalados como alternativos, valores legales del sector como parámetros «por
// confirmar», indicadores con sus umbrales, y contenido en solo lectura con el complemento apagado (RN-PSE-07, criterio 4).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled', company_enabled = true where company_id = ? and module_code in ('PSE', 'IPR', 'DSH', 'INS', 'PTR', 'CAP') and deleted_at is null", [HUAYLAS_ID]);
    \App\Core\Auth\Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->pack = DB::connection('platform')->selectOne("select id from sector_packs where status = 'published' and deleted_at is null order by name limit 1")->id;
    $this->key = 'test.pse_'.substr(md5(uniqid()), 0, 8);
    $p = DB::connection('platform');
    $this->items = [];
    foreach ([
        ['risk_criteria', 'MATRIZ-5X5', 'Matriz 5x5 del sector', ['code' => 'MATRIZ-5X5']],
        ['kpi', 'accident_rate', 'Índice de accidentes', ['code' => 'accident_rate', 'green_threshold' => 1.5]],
        ['legal_parameter', $this->key, 'Valor legal del sector', ['key' => $this->key, 'value' => 30, 'value_type' => 'days', 'description' => 'Plazo del sector']],
    ] as [$type, $code, $name, $pl]) {
        $this->items[$type] = $p->selectOne("insert into sector_pack_items (sector_pack_id, item_type, code, name, payload, sort_order, item_key, version) values (?, ?, ?, ?, ?::jsonb, 99, ?, 1) returning id",
            [$this->pack, $type, 'T-'.substr(md5($this->key.$type), 0, 10), $name, json_encode($pl), 'test-'.$type.'-'.$this->key])->id;
    }
});

afterEach(function () {
    DB::connection('platform')->update('update sector_pack_items set deleted_at = now() where id = any(?::uuid[])', ['{'.implode(',', $this->items).'}']);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'PSE'", [HUAYLAS_ID]);
});

it('instala criterios IPERC como alternativos, indicadores y valores legales «por confirmar»', function () {
    $prev = collect($this->getJson("/api/v1/pse/packs/{$this->pack}/preview", $this->h)->assertOk()->json('data.items'));
    expect($prev->firstWhere('id', $this->items['risk_criteria'])['action'])->not->toBe('unsupported');

    $r = $this->postJson("/api/v1/pse/packs/{$this->pack}/install", ['items' => array_values($this->items)], $this->h);
    expect($r->status())->toBe(201, $r->getContent());

    $crit = DB::selectOne("select is_default, status from risk_criteria where company_id = ? and code = 'MATRIZ-5X5' and deleted_at is null order by created_at desc limit 1", [HUAYLAS_ID]);
    expect($crit)->not->toBeNull()->and($crit->is_default)->toBeFalse();

    $kpi = DB::selectOne("select green_threshold from kpi_definitions where company_id = ? and code = 'accident_rate' and deleted_at is null limit 1", [HUAYLAS_ID]);
    expect($kpi)->not->toBeNull();

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $param = DB::selectOne('select value, value_type, to_confirm from legal_parameters where company_id = ? and key = ? and deleted_at is null', [HUAYLAS_ID, $this->key]);
    expect($param->value)->toBe('30')->and($param->value_type)->toBe('days')->and($param->to_confirm)->toBeTrue();

    // Reinstalar no duplica el parámetro.
    $this->postJson("/api/v1/pse/packs/{$this->pack}/install", ['items' => array_values($this->items)], $this->h)->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select count(*) as n from legal_parameters where company_id = ? and key = ? and deleted_at is null', [HUAYLAS_ID, $this->key])->n)->toBe(1);
});

it('con el complemento apagado el contenido instalado queda en solo lectura', function () {
    $this->postJson("/api/v1/pse/packs/{$this->pack}/install", ['items' => [$this->items['risk_criteria']]], $this->h)->assertCreated();
    $map = json_decode(DB::selectOne("select item_map from company_sector_packs where sector_pack_id = ? and status not in ('uninstalled', 'removed') and deleted_at is null", [$this->pack])->item_map, true);
    $id = collect($map)->firstWhere('type', 'risk_criteria')['ids'][0];

    DB::update("update feature_flags set mode = 'locked' where company_id = ? and module_code = 'PSE'", [HUAYLAS_ID]);
    expect(fn () => \App\Modules\PSE\SectorPacks::assertEditable('risk_criteria', $id))->toThrow(\App\Core\Http\ApiProblem::class);
    // Un registro que no vino de un paquete sigue editable.
    \App\Modules\PSE\SectorPacks::assertEditable('risk_criteria', '00000000-0000-0000-0000-000000000000');

    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'PSE'", [HUAYLAS_ID]);
    \App\Modules\PSE\SectorPacks::assertEditable('risk_criteria', $id);
    expect(true)->toBeTrue();
});
