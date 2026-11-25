<?php

// M-PSE §5: el paquete instala «obligación SUNAFIL» (se suma al autodiagnóstico de la empresa y se evalúa con la evidencia de DOC,
// M-SUN RN-SUN-03) y «formato de documento» (documento de DOC en borrador con su contenido). Una sola lista de sectores para
// empresas y paquetes (clínicas = «health»).

use App\Core\Support\Sectors;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled', company_enabled = true where company_id = ? and module_code in ('PSE', 'SUN', 'DOC') and deleted_at is null", [HUAYLAS_ID]);
    \App\Core\Auth\Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->pack = DB::connection('platform')->selectOne("select id from sector_packs where status = 'published' and deleted_at is null order by name limit 1")->id;
    $k = substr(md5(uniqid()), 0, 6);
    $this->code = 'SUN-ALT-'.$k;
    $p = DB::connection('platform');
    $this->items = [];
    foreach ([
        ['sunafil_obligation', $this->code, 'Trabajos en altura con permiso escrito', ['code' => $this->code, 'name' => 'Trabajos en altura con permiso escrito',
            'legal_basis' => 'G.050', 'severity' => 'very_serious', 'evidence_hint' => 'Permisos de trabajo en altura firmados']],
        ['document_template', 'DOC-PLAN-'.$k, 'Plan de contingencia del sector', ['code' => 'DOC-PLAN-'.$k, 'title' => 'Plan de contingencia del sector',
            'content_html' => '<p>Objetivo</p><p>Alcance</p>']],
    ] as [$type, $code, $name, $pl]) {
        $this->items[$type] = $p->selectOne("insert into sector_pack_items (sector_pack_id, item_type, code, name, payload, sort_order, item_key, version) values (?, ?, ?, ?, ?::jsonb, 99, ?, 1) returning id",
            [$this->pack, $type, $code, $name, json_encode($pl), $type.':'.$code])->id;
    }
});

afterEach(function () {
    DB::connection('platform')->update('update sector_pack_items set deleted_at = now() where id = any(?::uuid[])', ['{'.implode(',', $this->items).'}']);
});

it('instala la obligación SUNAFIL en el autodiagnóstico y el formato de documento como borrador de DOC', function () {
    $prev = collect($this->getJson("/api/v1/pse/packs/{$this->pack}/preview", $this->h)->assertOk()->json('data.items'));
    expect($prev->firstWhere('id', $this->items['sunafil_obligation'])['action'])->toBe('install')
        ->and($prev->firstWhere('id', $this->items['document_template'])['action'])->toBe('install');

    $r = $this->postJson("/api/v1/pse/packs/{$this->pack}/install", ['items' => array_values($this->items)], $this->h);
    expect($r->status())->toBe(201, $r->getContent());
    expect($r->json('data.skipped'))->toBe([]);

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $code = strtolower(str_replace('-', '_', $this->code));
    $o = DB::selectOne('select code, severity, sector_pack_id, status from sunafil_obligations where company_id = ? and code = ? and deleted_at is null', [HUAYLAS_ID, $code]);
    expect($o)->not->toBeNull()->and($o->severity)->toBe('very_serious')->and($o->sector_pack_id)->toBe($this->pack);
    $doc = DB::selectOne("select d.status, c.code as category, v.content_html from documents d join document_categories c on c.id = d.category_id
                           join document_versions v on v.document_id = d.id where d.company_id = ? and d.title = 'Plan de contingencia del sector' and d.deleted_at is null", [HUAYLAS_ID]);
    expect($doc->status)->toBe('draft')->and($doc->category)->toBe('OTHER')->and($doc->content_html)->toContain('Alcance');

    // En el autodiagnóstico: sin evidencia no cumple (RN-SUN-03); con un documento vigente vinculado, cumple.
    $area = collect($this->getJson('/api/v1/sun/diagnosis', $this->h)->assertOk()->json('data.areas'))->firstWhere('code', $code);
    expect($area)->not->toBeNull()->and($area['result'])->toBe('fails')->and($area['severity'])->toBe('very_serious')->and($area['sector'])->toBeTrue()
        ->and($area['detail'])->toContain('paquete sectorial');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $cat = DB::selectOne("select id from document_categories where code = 'OTHER' and company_id is null")->id;
    $ev = DB::selectOne("insert into documents (company_id, category_id, code, title, scope_type, status) values (?, ?, ?, 'Permisos en altura 2026', 'company', 'active') returning id",
        [HUAYLAS_ID, $cat, 'EV-'.substr(md5(uniqid()), 0, 6)])->id;
    $v = DB::selectOne("insert into document_versions (company_id, document_id, version_number, version_label, content_html, status, approved_at) values (?, ?, 1, '1.0', '<p>ok</p>', 'approved', now()) returning id", [HUAYLAS_ID, $ev])->id;
    DB::update('update documents set current_version_id = ? where id = ?', [$v, $ev]);
    $this->postJson("/api/v1/sun/diagnosis/{$code}/evidence", ['document_id' => $ev], $this->h)->assertCreated();
    $area = collect($this->getJson('/api/v1/sun/diagnosis', $this->h)->json('data.areas'))->firstWhere('code', $code);
    expect($area['result'])->toBe('complies')->and($area['evidence'])->toHaveCount(1);

    // Reinstalar no duplica; desinstalar no borra la obligación (RN-PSE-06), solo la desvincula del paquete.
    $this->postJson("/api/v1/pse/packs/{$this->pack}/install", ['items' => array_values($this->items)], $this->h)->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select count(*) as n from sunafil_obligations where company_id = ? and code = ? and deleted_at is null', [HUAYLAS_ID, $code])->n)->toBe(1);
    $this->postJson("/api/v1/pse/packs/{$this->pack}/uninstall", [], $this->h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select sector_pack_id from sunafil_obligations where company_id = ? and code = ?', [HUAYLAS_ID, $code])->sector_pack_id)->toBeNull();
});

it('usa una sola lista de sectores para empresas y paquetes', function () {
    $db = DB::connection('platform');
    $check = fn (string $table, string $name) => $db->selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conrelid = ?::regclass and conname = ?", [$table, $name])->d;
    foreach ([['companies', 'companies_economic_sector_check'], ['sector_packs', 'sector_packs_sector_check']] as [$t, $c]) {
        preg_match_all("/'([a-z_]+)'/", $check($t, $c), $m);
        expect($m[1])->toEqualCanonicalizing(Sectors::codes(), "{$t}.{$c}");
    }
    expect($db->selectOne("select count(*) as n from sector_packs where sector = 'clinic'")->n)->toBe(0)
        ->and(Sectors::label('health'))->toBe('Salud');
    $this->postJson('/api/v1/public/demo-requests', ['economic_sector' => 'clinic'])->assertStatus(422)->assertJsonValidationErrors('economic_sector');
});
