<?php

// Alcance de la membresía (docs/03 §3, docs/05 §2, M-USR RN-USR-02 y criterio 4, M-AUI RN-AUI-08): un usuario limitado a una
// sede solo ve, crea y elige lo de esa sede (lista, detalle, opciones, tablero); un auditor externo limitado a algunos
// módulos no entra a los demás ni los ve en el menú; un usuario sin alcance ve toda la empresa.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    Permissions::flush();
});

/** Ejecuta en el contexto RLS de Andinas (datos de la prueba, dentro de su transacción). */
function scpDb(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

/** Membresía activa del usuario en Andinas con el rol indicado; devuelve su id. */
function scpMembership(string $email, string $role, ?array $modules = null): string
{
    return scpDb(function () use ($email, $role, $modules) {
        $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [$email])->id;
        $roleId = DB::selectOne('select id from roles where code = ? and account_id is null', [$role])->id;

        return DB::selectOne("insert into memberships (company_id, user_id, role_id, status, accepted_at, expires_at, allowed_modules)
                              values (?, ?, ?, 'active', now(), ?, ?::text[]) returning id",
            [ANDINAS_ID, $uid, $roleId, $role === 'external_auditor' ? now()->addMonth() : null, $modules ? '{'.implode(',', $modules).'}' : null])->id;
    });
}

/** Dos sedes con un área cada una; el usuario Esencial queda como supervisor SST de Andinas limitado a la sede A. */
function scpSetup(): array
{
    $ids = scpDb(function () {
        $a = DB::selectOne("insert into sites (company_id, code, name) values (?, 'SCP-A', 'Sede Alcance A') returning id", [ANDINAS_ID])->id;
        $b = DB::selectOne("insert into sites (company_id, code, name) values (?, 'SCP-B', 'Sede Alcance B') returning id", [ANDINAS_ID])->id;
        $aa = DB::selectOne("insert into areas (company_id, site_id, code, name) values (?, ?, 'SCP-AA', 'Área de A') returning id", [ANDINAS_ID, $a])->id;
        $bb = DB::selectOne("insert into areas (company_id, site_id, code, name) values (?, ?, 'SCP-BB', 'Área de B') returning id", [ANDINAS_ID, $b])->id;

        return compact('a', 'b', 'aa', 'bb');
    });
    $ms = scpMembership(ESENCIAL_EMAIL, 'sst_supervisor');
    scpDb(fn () => DB::insert("insert into membership_scopes (company_id, membership_id, scope_type, site_id) values (?, ?, 'site', ?)", [ANDINAS_ID, $ms, $ids['a']]));

    $boss = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $today = now('America/Lima')->toDateString();
    $ids['ins_a'] = test()->postJson('/api/v1/records/INS/inspections', ['title' => 'Inspección en A', 'inspection_type' => 'planned', 'site_id' => $ids['a'], 'scheduled_on' => $today], $boss)
        ->assertCreated()->json('data.id');
    $ids['ins_b'] = test()->postJson('/api/v1/records/INS/inspections', ['title' => 'Inspección en B', 'inspection_type' => 'planned', 'site_id' => $ids['b'], 'scheduled_on' => $today], $boss)
        ->assertCreated()->json('data.id');

    return $ids;
}

function scpScoped(): array
{
    Permissions::flush();

    return authHeaders(tokenFor(ESENCIAL_EMAIL), ANDINAS_ID);
}

it('con alcance de una sede solo ve los registros de esa sede en la lista y en el detalle', function () {
    $s = scpSetup();
    $h = scpScoped();

    $ids = collect($this->getJson('/api/v1/records/INS/inspections?per_page=100', $h)->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($s['ins_a'])->not->toContain($s['ins_b']);
    $this->getJson("/api/v1/records/INS/inspections/{$s['ins_a']}", $h)->assertOk();
    $this->getJson("/api/v1/records/INS/inspections/{$s['ins_b']}", $h)->assertNotFound();
    $this->patchJson("/api/v1/records/INS/inspections/{$s['ins_b']}", ['title' => 'Cambio'], $h)->assertNotFound();
    $this->getJson("/api/v1/ins/inspections/{$s['ins_b']}/checklist", $h)->assertNotFound();

    // Las sedes y áreas que ve son las de su alcance.
    $sites = collect($this->getJson('/api/v1/records/TEN/sites?per_page=100', $h)->assertOk()->json('data'))->pluck('id');
    expect($sites->all())->toBe([$s['a']]);
    $areas = collect($this->getJson('/api/v1/records/TEN/areas?per_page=100', $h)->assertOk()->json('data'))->pluck('id');
    expect($areas->all())->toBe([$s['aa']]);
});

it('con alcance de una sede no crea ni mueve registros a otra sede y las opciones solo ofrecen su sede', function () {
    $s = scpSetup();
    $h = scpScoped();

    $this->postJson('/api/v1/records/EQE/first_aid_kits', ['code' => 'SCP-BOT-B', 'site_id' => $s['b']], $h)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['site_id']]);
    $kit = $this->postJson('/api/v1/records/EQE/first_aid_kits', ['code' => 'SCP-BOT-A', 'site_id' => $s['a'], 'area_id' => $s['aa']], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/EQE/first_aid_kits/{$kit}", ['site_id' => $s['b'], 'area_id' => null], $h)->assertStatus(422);
    $this->patchJson("/api/v1/records/EQE/first_aid_kits/{$kit}", ['area_id' => $s['bb']], $h)->assertStatus(422);

    $opts = collect($this->getJson('/api/v1/records/INS/inspections/options/site_id', $h)->assertOk()->json('data'))->pluck('id');
    expect($opts->all())->toBe([$s['a']]);
    $areaOpts = collect($this->getJson('/api/v1/records/EQE/first_aid_kits/options/area_id', $h)->assertOk()->json('data'))->pluck('id');
    expect($areaOpts->all())->toBe([$s['aa']]);
    // La etiqueta de un valor ya guardado se sigue resolviendo (búsqueda por id).
    $this->getJson("/api/v1/records/INS/inspections/options/site_id?id={$s['b']}", $h)->assertOk()->assertJsonCount(1, 'data');
});

it('el tablero cuenta solo los registros de la sede del alcance y no deja elegir otra sede', function () {
    $s = scpSetup();
    $year = (int) now('America/Lima')->year;
    $count = fn (array $sites) => (int) scpDb(fn () => DB::selectOne("select count(*) as n from inspections where deleted_at is null and status <> 'cancelled'
        and scheduled_on between ? and current_date and site_id = any(?::uuid[])", ["{$year}-01-01", '{'.implode(',', $sites).'}'])->n);

    $h = scpScoped();
    $r = $this->getJson("/api/v1/dsh/overview?year={$year}", $h)->assertOk();
    $kpi = collect($r->json('data.kpis'))->firstWhere('code', 'inspection_compliance');
    expect($kpi['denominator'])->toBe($count([$s['a']]))
        ->and(collect($r->json('data.sites'))->pluck('id')->all())->toBe([$s['a']]);
    $this->getJson("/api/v1/dsh/overview?year={$year}&site_id={$s['b']}", $h)->assertForbidden();

    // Sin alcance (Jefe SST) cuenta toda la empresa.
    Permissions::flush();
    $all = $this->getJson("/api/v1/dsh/overview?year={$year}", authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->assertOk();
    $total = (int) scpDb(fn () => DB::selectOne("select count(*) as n from inspections where deleted_at is null and status <> 'cancelled' and scheduled_on between ? and current_date", ["{$year}-01-01"])->n);
    expect(collect($all->json('data.kpis'))->firstWhere('code', 'inspection_compliance')['denominator'])->toBe($total)
        ->and($total)->toBeGreaterThan($count([$s['a']]));
});

it('un usuario sin alcance ve los registros de todas las sedes', function () {
    $s = scpSetup();
    Permissions::flush();
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $ids = collect($this->getJson('/api/v1/records/INS/inspections?per_page=100', $h)->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($s['ins_a'])->toContain($s['ins_b']);
    $opts = collect($this->getJson('/api/v1/records/INS/inspections/options/site_id', $h)->assertOk()->json('data'))->pluck('id');
    expect($opts)->toContain($s['a'])->toContain($s['b']);
});

it('el auditor externo limitado a AUI y DOC no entra a otro módulo ni lo ve en el menú', function () {
    scpMembership(CORPORATIVO_EMAIL, 'external_auditor', ['AUI', 'DOC']);
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);

    $this->getJson('/api/v1/records/INS/inspections', $h)->assertForbidden()->assertJsonPath('type', 'module_not_allowed');
    $this->getJson('/api/v1/ins/dashboard', $h)->assertForbidden();
    $this->getJson('/api/v1/records/DOC/documents', $h)->assertOk();
    $this->getJson('/api/v1/records/AUI/audits', $h)->assertOk();

    $mods = collect($this->getJson('/api/v1/me/modules', $h)->assertOk()->json('data'))->keyBy('code');
    expect($mods['INS']['role_can_use'])->toBeFalse()
        ->and($mods['DOC']['role_can_use'])->toBeTrue()
        ->and($mods['AUI']['role_can_use'])->toBeTrue();
    $perms = $this->getJson('/api/v1/me/permissions', $h)->assertOk()->json('data');
    expect(collect($perms)->filter(fn ($p) => str_starts_with($p, 'ins.'))->all())->toBe([])
        ->and($perms)->toContain('doc.documents.read');
});
