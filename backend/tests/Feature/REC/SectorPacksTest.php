<?php

// Paquetes sectoriales (M-PSE): vista previa, instalación idempotente por item_key (RN-PSE-02/08, criterio 1), contenido
// copiado como propio, actualización que no pisa lo editado sin confirmar (RN-PSE-03, criterio 2), desinstalación que
// conserva lo usado (RN-PSE-06, criterio 5) e inventario (§11).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('PSE', 'INS', 'PTR', 'CAP') and deleted_at is null", [HUAYLAS_ID]);
    \App\Core\Auth\Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    // Paquete con plantilla de inspección y tipo de permiso globales.
    $this->pack = DB::connection('platform')->selectOne("select p.id from sector_packs p where p.status = 'published' and p.deleted_at is null
        and exists (select 1 from sector_pack_items i where i.sector_pack_id = p.id and i.item_type = 'inspection_template')
        and exists (select 1 from sector_pack_items i where i.sector_pack_id = p.id and i.item_type = 'permit_type') order by p.name limit 1")->id;
});

afterEach(function () {
    DB::connection('platform')->update('update sector_pack_items set version = 1 where sector_pack_id = ?', [$this->pack]);
    DB::connection('platform')->update("update sector_packs set version_number = 1 where id = ?", [$this->pack]);
});

it('muestra qué hará cada ítem, instala copiando el contenido y reinstalar no duplica', function () {
    $resp = $this->getJson("/api/v1/pse/packs/{$this->pack}/preview", $this->h);
    expect($resp->status())->toBe(200, $resp->getContent());
    $prev = collect($resp->json('data.items'));
    expect($prev)->not->toBeEmpty()->and($prev->pluck('action')->unique()->all())->each->toBeIn(['install', 'link', 'installed', 'unsupported']);

    $r = $this->postJson("/api/v1/pse/packs/{$this->pack}/install", [], $this->h)->assertCreated()->json('data');
    expect(count($r['installed']) + count($r['linked']))->toBeGreaterThan(0);

    $count = fn () => [
        DB::selectOne('select count(*) as n from inspection_templates where company_id = ? and deleted_at is null', [HUAYLAS_ID])->n,
        DB::selectOne('select count(*) as n from permit_types where company_id = ? and deleted_at is null', [HUAYLAS_ID])->n,
        DB::selectOne('select count(*) as n from trainings where company_id = ? and deleted_at is null', [HUAYLAS_ID])->n,
    ];
    $before = $count();
    $tpl = DB::selectOne('select id, sector_pack_id from inspection_templates where company_id = ? and sector_pack_id = ? limit 1', [HUAYLAS_ID, $this->pack]);
    expect($tpl)->not->toBeNull();
    expect(DB::selectOne('select count(*) as n from inspection_template_items where template_id = ? and deleted_at is null', [$tpl->id])->n)->toBeGreaterThan(0);

    // Criterio 1: reinstalar no duplica.
    $this->postJson("/api/v1/pse/packs/{$this->pack}/install", [], $this->h)->assertCreated();
    expect($count())->toBe($before);
    expect(collect($this->getJson("/api/v1/pse/packs/{$this->pack}/preview", $this->h)->json('data.items'))->where('action', 'install'))->toBeEmpty();

    $inv = $this->getJson('/api/v1/pse/inventory', $this->h)->assertOk()->json('data');
    expect($inv)->not->toBeEmpty()->and($inv[0])->toHaveKeys(['pack', 'type', 'name', 'installed_version', 'latest_version']);
});

it('una actualización no pisa una plantilla editada sin confirmar; desinstalar conserva el contenido', function () {
    $this->postJson("/api/v1/pse/packs/{$this->pack}/install", [], $this->h)->assertCreated();
    $item = DB::connection('platform')->selectOne("select id from sector_pack_items where sector_pack_id = ? and item_type = 'inspection_template' limit 1", [$this->pack])->id;
    $tpl = DB::selectOne('select id from inspection_templates where company_id = ? and sector_pack_id = ? limit 1', [HUAYLAS_ID, $this->pack])->id;

    // La empresa edita su plantilla y luego EscudoSST publica una nueva versión del ítem.
    DB::update("update inspection_templates set name = 'Plantilla propia de la obra', updated_at = now() where id = ?", [$tpl]);
    DB::connection('platform')->update('update sector_pack_items set version = 2 where id = ?', [$item]);
    DB::connection('platform')->update('update sector_packs set version_number = 2 where id = ?', [$this->pack]);

    $u = collect($this->getJson("/api/v1/pse/packs/{$this->pack}/updates", $this->h)->assertOk()->json('data.items'));
    expect($u->firstWhere('id', $item))->toMatchArray(['change' => 'changed', 'edited' => true]);

    $r = $this->postJson("/api/v1/pse/packs/{$this->pack}/updates", ['items' => [$item]], $this->h)->assertOk()->json('data');
    expect($r['kept'])->toHaveCount(1);
    expect(DB::selectOne('select name from inspection_templates where id = ?', [$tpl])->name)->toBe('Plantilla propia de la obra');

    $this->postJson("/api/v1/pse/packs/{$this->pack}/updates", ['items' => [$item], 'confirm' => [$item]], $this->h)->assertOk()->assertJsonCount(1, 'data.updated');
    expect(DB::selectOne('select name from inspection_templates where id = ?', [$tpl])->name)->not->toBe('Plantilla propia de la obra');

    // Criterio 5: desinstalar conserva la plantilla (deja de estar ligada al paquete).
    $this->postJson("/api/v1/pse/packs/{$this->pack}/uninstall", [], $this->h)->assertOk();
    $after = DB::selectOne('select deleted_at, sector_pack_id from inspection_templates where id = ?', [$tpl]);
    expect($after->deleted_at)->toBeNull()->and($after->sector_pack_id)->toBeNull();
    expect(DB::selectOne("select status from company_sector_packs where sector_pack_id = ? order by created_at desc limit 1", [$this->pack])->status)->toBe('uninstalled');
});
