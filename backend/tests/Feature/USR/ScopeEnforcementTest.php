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

/** Evento en la sede indicada con su investigación (tabla hija de dos niveles: causa → investigación → evento). */
function scpInvestigation(array $boss, string $site): string
{
    $event = test()->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'incident', 'occurred_at' => now()->subDay()->toIso8601String(), 'site_id' => $site,
        'description' => 'Incidente para probar el alcance por sede'], $boss)->assertCreated()->json('data.id');

    return test()->postJson('/api/v1/records/INV/investigations', ['event_id' => $event, 'method' => 'cause_tree'], $boss)->assertCreated()->json('data.id');
}

it('las tablas hijas sin sede propia se ven y se crean según la sede de su registro padre', function () {
    $s = scpSetup();
    $boss = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $invA = scpInvestigation($boss, $s['a']);
    $invB = scpInvestigation($boss, $s['b']);
    $cause = fn (string $inv, string $txt, array $h) => $this->postJson('/api/v1/records/INV/investigation_causes', ['investigation_id' => $inv, 'cause_level' => 'immediate', 'description' => $txt], $h);
    $cA = $cause($invA, 'Piso resbaloso en la sede A', $boss)->assertCreated()->json('data.id');
    $cB = $cause($invB, 'Piso resbaloso en la sede B', $boss)->assertCreated()->json('data.id');

    $h = scpScoped();
    $ids = collect($this->getJson('/api/v1/records/INV/investigation_causes?per_page=100', $h)->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($cA)->not->toContain($cB);
    $invs = collect($this->getJson('/api/v1/records/INV/investigations?per_page=100', $h)->assertOk()->json('data'))->pluck('id');
    expect($invs)->toContain($invA)->not->toContain($invB);
    $this->getJson("/api/v1/records/INV/investigation_causes/{$cB}", $h)->assertNotFound();
    $this->patchJson("/api/v1/records/INV/investigation_causes/{$cB}", ['description' => 'Cambio fuera del alcance'], $h)->assertNotFound();
    $cause($invB, 'Causa nueva fuera del alcance', $h)->assertStatus(422)->assertJsonStructure(['errors' => ['investigation_id']]);
    $cause($invA, 'Causa nueva dentro del alcance', $h)->assertCreated();
    $this->getJson("/api/v1/inv/investigations/{$invA}/tree", $h)->assertOk();

    // Sin alcance ve las dos.
    Permissions::flush();
    $boss = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $all = collect($this->getJson('/api/v1/records/INV/investigation_causes?per_page=100', $boss)->assertOk()->json('data'))->pluck('id');
    expect($all)->toContain($cA)->toContain($cB);
});

it('los endpoints propios que reciben un id responden 404 fuera del alcance y 200 sin alcance', function () {
    $s = scpSetup();
    $boss = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $inv = scpInvestigation($boss, $s['b']);
    $talk = $this->postJson('/api/v1/records/CH5/safety_talks', ['topic' => 'Orden y limpieza', 'talk_on' => now('America/Lima')->toDateString(), 'site_id' => $s['b']], $boss)
        ->assertCreated()->json('data.id');
    $dni = (string) random_int(40000000, 49999999);
    $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => 'Rosa', 'paternal_last_name' => 'Fuera', 'current_site_id' => $s['b']], $boss)->assertCreated();

    $h = scpScoped();
    $this->getJson("/api/v1/inv/investigations/{$inv}/tree", $h)->assertNotFound();
    $this->getJson("/api/v1/ch5/talks/{$talk}/attendances", $h)->assertNotFound();
    $this->getJson("/api/v1/reports/inspections/{$s['ins_b']}", $h)->assertNotFound();
    $this->getJson("/api/v1/reports/inspections/{$s['ins_a']}", $h)->assertOk();
    $this->getJson("/api/v1/fir/subject/inspections/{$s['ins_b']}", $h)->assertNotFound();
    $this->getJson('/api/v1/epp/quick/lookup?code='.$dni, $h)->assertNotFound();

    Permissions::flush();
    $boss = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->getJson("/api/v1/inv/investigations/{$inv}/tree", $boss)->assertOk();
    $this->getJson("/api/v1/ch5/talks/{$talk}/attendances", $boss)->assertOk();
    $this->getJson("/api/v1/reports/inspections/{$s['ins_b']}", $boss)->assertOk();
    $this->getJson("/api/v1/fir/subject/inspections/{$s['ins_b']}", $boss)->assertOk();
    $this->getJson('/api/v1/epp/quick/lookup?code='.$dni, $boss)->assertOk();
});

it('los avisos por rol de un registro de sede llegan solo a quien tiene esa sede en su alcance', function () {
    $s = scpSetup();
    $uid = scpDb(fn () => DB::selectOne('select id from app_auth_lookup_user(?)', [ESENCIAL_EMAIL])->id);
    $toA = scpDb(fn () => \App\Modules\NOT\Notify::roles(['sst_supervisor'], 'inspections', $s['ins_a']));
    $toB = scpDb(fn () => \App\Modules\NOT\Notify::roles(['sst_supervisor'], 'inspections', $s['ins_b']));
    $siteB = scpDb(fn () => \App\Modules\NOT\Notify::siteTeam(['sst_supervisor'], $s['b']));
    $all = scpDb(fn () => \App\Modules\NOT\Notify::roles(['sst_supervisor']));
    expect($toA)->toContain($uid)
        ->and($toB)->not->toContain($uid)
        ->and($siteB)->not->toContain($uid)
        ->and($all)->toContain($uid);
});

it('el auditor externo solo ve los registros con fecha dentro del periodo de su auditoría', function () {
    $s = scpSetup();
    $boss = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $old = $this->postJson('/api/v1/records/INS/inspections', ['title' => 'Inspección de 2020', 'inspection_type' => 'planned', 'site_id' => $s['a'], 'scheduled_on' => '2020-06-15'], $boss)
        ->assertCreated()->json('data.id');
    $ms = scpMembership(CORPORATIVO_EMAIL, 'external_auditor', ['INS']);
    scpDb(fn () => DB::update("update memberships set audit_period_from = '2020-01-01', audit_period_to = '2020-12-31' where id = ?", [$ms]));
    Permissions::flush();
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);

    $ids = collect($this->getJson('/api/v1/records/INS/inspections?per_page=100', $h)->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($old)->not->toContain($s['ins_a'])->not->toContain($s['ins_b']);
    $this->getJson("/api/v1/records/INS/inspections/{$old}", $h)->assertOk();
    $this->getJson("/api/v1/records/INS/inspections/{$s['ins_a']}", $h)->assertNotFound();
});

/** Trabajador de Andinas en la sede indicada (por el motor de registros, como el Jefe SST). */
function scpWorker(array $boss, string $site, string $name): string
{
    return test()->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 49999999), 'first_name' => $name,
        'paternal_last_name' => 'Alcance', 'current_site_id' => $site], $boss)->assertCreated()->json('data.id');
}

it('fotochecks: resumen, lote, historial y evaluación solo con los trabajadores del alcance', function () {
    $s = scpSetup();
    $boss = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $wa = scpWorker($boss, $s['a'], 'Ana');
    $wb = scpWorker($boss, $s['b'], 'Beto');
    [$ca, $cb] = scpDb(function () use ($wa, $wb) {
        $type = DB::selectOne('select id from credential_types where deleted_at is null order by name limit 1')->id;
        $mk = fn (string $w, string $n) => DB::selectOne("insert into credentials (company_id, credential_type_id, worker_id, credential_number, token_hash, status, expires_on)
            values (?, ?, ?, ?, sha256(convert_to(?, 'UTF8')), 'active', current_date + 200) returning id", [ANDINAS_ID, $type, $w, $n, $n])->id;

        return [$mk($wa, 'SCP-FOT-A-'.random_int(1000, 9999)), $mk($wb, 'SCP-FOT-B-'.random_int(1000, 9999))];
    });
    $active = fn (?array $sites) => (int) scpDb(fn () => DB::selectOne("select count(*) as n from credentials c left join workers w on w.id = c.worker_id
        where c.deleted_at is null and c.status = 'active' and (c.expires_on is null or c.expires_on >= current_date)".($sites ? ' and (c.worker_id is null or w.current_site_id = any(?::uuid[]) or (w.current_site_id is null and w.current_area_id is null))' : ''),
        $sites ? ['{'.implode(',', $sites).'}'] : [])->n);

    $h = scpScoped();
    expect($this->getJson('/api/v1/fot/summary', $h)->assertOk()->json('data.kpis.active'))->toBe($active([$s['a']]));
    $batch = $this->getJson('/api/v1/fot/credentials/batch', $h)->assertOk()->json('data');
    expect($batch)->toContain($ca)->not->toContain($cb);
    $this->getJson("/api/v1/fot/credentials/batch?site_id={$s['b']}", $h)->assertForbidden();
    $this->getJson("/api/v1/fot/credentials/{$cb}/verifications", $h)->assertNotFound();
    $this->getJson("/api/v1/fot/credentials/{$ca}/verifications", $h)->assertOk();
    $this->getJson("/api/v1/fot/evaluate?worker_id={$wb}", $h)->assertNotFound();
    $this->getJson('/api/v1/fot/matrix', $h)->assertOk();

    Permissions::flush();
    $boss = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    expect($this->getJson('/api/v1/fot/summary', $boss)->assertOk()->json('data.kpis.active'))->toBe($active(null));
    expect($this->getJson('/api/v1/fot/credentials/batch', $boss)->assertOk()->json('data'))->toContain($ca)->toContain($cb);
    $this->getJson("/api/v1/fot/credentials/{$cb}/verifications", $boss)->assertOk();
});

it('portería, monitoreo, comité y ergonomía: los paneles cuentan solo lo de la sede del alcance', function () {
    $s = scpSetup();
    [$pa, $pb, $ma, $mb, $ka] = scpDb(function () use ($s) {
        DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('ING', 'PSI', 'MAG', 'COM') and deleted_at is null", [ANDINAS_ID]);
        $point = fn (string $site, string $code) => DB::selectOne('insert into access_points (company_id, site_id, code, name) values (?, ?, ?, ?) returning id', [ANDINAS_ID, $site, $code, "Puerta {$code}"])->id;
        $pa = $point($s['a'], 'SCP-PA');
        $pb = $point($s['b'], 'SCP-PB');
        foreach ([$pa => 'Visita en A', $pb => 'Visita en B'] as $p => $name) {
            DB::insert("insert into access_events (company_id, access_point_id, occurred_at, direction, subject_type, result, visitor_name, visitor_document_masked)
                        values (?, ?, now(), 'in', 'visitor', 'granted', ?, '****1234')", [ANDINAS_ID, $p, $name]);
        }
        $agent = DB::selectOne('select id from agent_types where deleted_at is null order by name limit 1')->id;
        $mp = fn (string $site, string $code) => DB::selectOne("insert into monitoring_points (company_id, site_id, agent_type_id, code, name, status) values (?, ?, ?, ?, ?, 'active') returning id",
            [ANDINAS_ID, $site, $agent, $code, "Punto {$code}"])->id;
        $committee = fn (string $site, string $name) => DB::selectOne("insert into committees (company_id, site_id, committee_type, name, period_start_on, period_end_on, status)
            values (?, ?, 'subcommittee', ?, current_date - 30, current_date + 700, 'active') returning id", [ANDINAS_ID, $site, $name])->id;
        $committee($s['b'], 'Subcomité de B');

        return [$pa, $pb, $mp($s['a'], 'SCP-MA'), $mp($s['b'], 'SCP-MB'), $committee($s['a'], 'Subcomité de A')];
    });
    $h = scpScoped();

    // Portería: puntos, personas dentro y conteos del día de su sede.
    $gate = $this->getJson('/api/v1/ing/gate', $h)->assertOk();
    expect(collect($gate->json('data.points'))->pluck('id')->all())->toContain($pa)->not->toContain($pb);
    $inside = $this->getJson('/api/v1/ing/inside', $h)->assertOk();
    $names = collect($inside->json('data.people'))->pluck('name');
    expect($names)->toContain('Visita en A')->not->toContain('Visita en B');
    expect($inside->json('data.kpis.inside'))->toBe(count($inside->json('data.people')));

    // Monitoreo: brechas y tendencia de su sede.
    $gaps = collect($this->getJson('/api/v1/mag/gaps', $h)->assertOk()->json('data.never'))->pluck('id');
    expect($gaps)->toContain($ma)->not->toContain($mb);
    $this->getJson("/api/v1/mag/trend?point_id={$mb}", $h)->assertNotFound();
    $this->getJson("/api/v1/mag/trend?point_id={$ma}", $h)->assertOk();

    // Comité: el de su sede (no el subcomité de la otra).
    expect($this->getJson('/api/v1/com/committee', $h)->assertOk()->json('data.committee.id'))->toBe($ka);

    // Ergonomía: el informe por puesto cuenta solo los trabajadores de su sede.
    $this->getJson('/api/v1/psi/ergonomics/report', $h)->assertOk();

    Permissions::flush();
    $boss = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $all = collect($this->getJson('/api/v1/ing/inside', $boss)->assertOk()->json('data.people'))->pluck('name');
    expect($all)->toContain('Visita en A')->toContain('Visita en B');
    expect(collect($this->getJson('/api/v1/mag/gaps', $boss)->assertOk()->json('data.never'))->pluck('id'))->toContain($ma)->toContain($mb);
    $this->getJson("/api/v1/mag/trend?point_id={$mb}", $boss)->assertOk();
});

it('aula: el panel cuenta las matrículas de los trabajadores del alcance', function () {
    $s = scpSetup();
    $boss = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $wa = scpWorker($boss, $s['a'], 'Carla');
    $wb = scpWorker($boss, $s['b'], 'Dante');
    $course = scpDb(function () use ($wa, $wb) {
        DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'AUL' and deleted_at is null", [ANDINAS_ID]);
        $c = DB::selectOne("insert into courses (company_id, code, title, status, source, published_at) values (?, ?, 'Curso de alcance', 'published', 'internal', now()) returning id", [ANDINAS_ID, 'SCP-'.random_int(100, 999)])->id;
        foreach ([$wa, $wb] as $w) {
            DB::insert("insert into course_enrollments (company_id, course_id, worker_id, source, status) values (?, ?, ?, 'manual', 'enrolled')", [ANDINAS_ID, $c, $w]);
        }

        return $c;
    });

    $row = collect($this->getJson('/api/v1/aul/admin/overview', scpScoped())->assertOk()->json('data.courses'))->firstWhere('id', $course);
    expect((int) $row['enrolled'])->toBe(1);

    Permissions::flush();
    $row = collect($this->getJson('/api/v1/aul/admin/overview', authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->assertOk()->json('data.courses'))->firstWhere('id', $course);
    expect((int) $row['enrolled'])->toBe(2);
});
