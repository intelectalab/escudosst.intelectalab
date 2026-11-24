<?php

// Investigación (M-INV, SCR-INV-03): editor visual del árbol de causas. Agregar bajo el evento o bajo una causa, editar,
// mover sin ciclos y reordenar, quitar con las hijas que suben a la causa superior, bloqueo con el informe aprobado
// (RN-INV-05) y permisos del rol.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    Permissions::flush();
});

function treeInvestigation($t, array $h): string
{
    $site = collect($t->getJson('/api/v1/records/ACC/sst_events/options/site_id', $h)->json('data'))->first()['id'];
    $event = $t->postJson('/api/v1/records/ACC/sst_events', ['code' => 'ACC-ARB-'.random_int(100, 999), 'event_type' => 'incident', 'site_id' => $site,
        'occurred_at' => now('America/Lima')->subDay()->toIso8601String(), 'description' => 'Resbalón en piso mojado del almacén'], $h)->assertCreated()->json('data.id');

    return $t->postJson('/api/v1/records/INV/investigations', ['event_id' => $event, 'method' => 'cause_tree'], $h)->assertCreated()->json('data.id');
}

function treeAdd($t, array $h, string $inv, ?string $parent, string $level, string $text, ?string $category = null): string
{
    return $t->postJson("/api/v1/inv/investigations/{$inv}/tree/causes", ['parent_cause_id' => $parent, 'cause_level' => $level, 'cause_category' => $category, 'description' => $text], $h)
        ->assertCreated()->json('data.id');
}

it('agrega, edita, mueve, reordena y quita causas en el árbol', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $inv = treeInvestigation($this, $h);

    $a = treeAdd($this, $h, $inv, null, 'immediate', 'Piso mojado sin señalizar', 'unsafe_condition');
    $b = treeAdd($this, $h, $inv, $a, 'basic', 'No hay rutina de limpieza', 'job_factor');
    $c = treeAdd($this, $h, $inv, $b, 'lack_of_control', 'Sin estándar de orden y limpieza');
    $d = treeAdd($this, $h, $inv, null, 'immediate', 'Caminaba apurado', 'unsafe_act');

    $tree = $this->getJson("/api/v1/inv/investigations/{$inv}/tree", $h)->assertOk()->json('data');
    $byId = collect($tree['causes'])->keyBy('id');
    expect($tree['causes'])->toHaveCount(4)->and($byId[$b]['parent_cause_id'])->toBe($a)->and($byId[$c]['parent_cause_id'])->toBe($b)
        ->and($tree['locked'])->toBeFalse()->and($tree['can'])->toMatchArray(['create' => true, 'update' => true, 'delete' => true]);

    // Editar: descripción, nivel y categoría; queda en la bitácora como las demás escrituras de INV.
    $this->patchJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$b}", ['description' => 'No hay rutina de limpieza por turno', 'cause_category' => 'management_system'], $h)->assertOk();
    $row = DB::selectOne('select description, cause_level, cause_category from investigation_causes where id = ?', [$b]);
    expect($row->description)->toBe('No hay rutina de limpieza por turno')->and($row->cause_category)->toBe('management_system')->and($row->cause_level)->toBe('basic');
    expect(DB::selectOne("select count(*) as n from audit_logs where entity_type = 'investigation_causes' and entity_id = ?", [$b])->n)->toBeGreaterThanOrEqual(2);
    $this->patchJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$b}", ['cause_level' => 'otra'], $h)->assertStatus(422);

    // Mover sin ciclos: ni bajo sí misma ni bajo una causa que depende de ella.
    $this->postJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$a}/move", ['parent_cause_id' => $a], $h)->assertStatus(422);
    $this->postJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$a}/move", ['parent_cause_id' => $c], $h)->assertStatus(422);
    expect(DB::selectOne('select parent_cause_id from investigation_causes where id = ?', [$a])->parent_cause_id)->toBeNull();

    // Mover la básica bajo la otra inmediata, y reordenar las inmediatas (d primero).
    $this->postJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$b}/move", ['parent_cause_id' => $d], $h)->assertOk();
    expect(DB::selectOne('select parent_cause_id from investigation_causes where id = ?', [$b])->parent_cause_id)->toBe($d);
    $this->postJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$d}/move", ['parent_cause_id' => null, 'position' => 0], $h)->assertOk();
    $top = array_column(DB::select('select id from investigation_causes where investigation_id = ? and parent_cause_id is null and deleted_at is null order by sort_order', [$inv]), 'id');
    expect($top)->toBe([$d, $a]);

    // Una causa de otra investigación no sirve de causa superior.
    $other = treeInvestigation($this, $h);
    $x = treeAdd($this, $h, $other, null, 'immediate', 'Otra causa');
    $this->postJson("/api/v1/inv/investigations/{$inv}/tree/causes", ['parent_cause_id' => $x, 'cause_level' => 'basic', 'description' => 'Mal enlazada'], $h)->assertStatus(422);

    // Quitar la básica con acción correctiva: su hija sube a la inmediata y la acción se conserva.
    $user = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    $this->postJson("/api/v1/acr/from/investigation_causes/{$b}", ['title' => 'Rutina de limpieza por turno', 'responsible_user_id' => $user,
        'due_on' => now()->addDays(10)->toDateString()], $h)->assertSuccessful();
    $action = DB::selectOne('select corrective_action_id from investigation_causes where id = ?', [$b])->corrective_action_id;
    expect($action)->not->toBeNull();
    $this->deleteJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$b}", [], $h)->assertOk()->assertJsonPath('data.moved_children', 1);
    expect(DB::selectOne('select deleted_at from investigation_causes where id = ?', [$b])->deleted_at)->not->toBeNull()
        ->and(DB::selectOne('select parent_cause_id from investigation_causes where id = ?', [$c])->parent_cause_id)->toBe($d)
        ->and(DB::selectOne('select deleted_at from corrective_actions where id = ?', [$action])->deleted_at)->toBeNull();
    expect($this->getJson("/api/v1/inv/investigations/{$inv}/tree", $h)->json('data.causes'))->toHaveCount(3);
});

it('bloquea el árbol con el informe aprobado', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $inv = treeInvestigation($this, $h);
    $a = treeAdd($this, $h, $inv, null, 'immediate', 'Piso mojado');
    DB::update("update investigations set status = 'approved', approved_at = now() where id = ?", [$inv]);

    $this->postJson("/api/v1/inv/investigations/{$inv}/tree/causes", ['cause_level' => 'basic', 'description' => 'Hallazgo tardío'], $h)->assertStatus(409);
    $this->patchJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$a}", ['description' => 'Cambio'], $h)->assertStatus(409);
    $this->postJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$a}/move", ['parent_cause_id' => null, 'position' => 0], $h)->assertStatus(409);
    $this->deleteJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$a}", [], $h)->assertStatus(409);
    expect($this->getJson("/api/v1/inv/investigations/{$inv}/tree", $h)->json('data.locked'))->toBeTrue();
    // El motor de registros aplica el mismo bloqueo a la lista «Causas».
    $this->patchJson("/api/v1/records/INV/investigation_causes/{$a}", ['description' => 'Cambio'], $h)->assertStatus(409);
});

it('un rol sin permiso sobre las causas no edita el árbol', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $inv = treeInvestigation($this, $h);
    $a = treeAdd($this, $h, $inv, null, 'immediate', 'Piso mojado');

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    $role = DB::selectOne("select id from roles where account_id is null and code = 'management'")->id;
    DB::update('update memberships set role_id = ? where user_id = ? and company_id = ?', [$role, $uid, HUAYLAS_ID]);
    $this->app['auth']->forgetGuards();
    Permissions::flush();

    $this->postJson("/api/v1/inv/investigations/{$inv}/tree/causes", ['cause_level' => 'basic', 'description' => 'Otra'], $h)->assertForbidden();
    $this->patchJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$a}", ['description' => 'Cambio'], $h)->assertForbidden();
    $this->postJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$a}/move", ['parent_cause_id' => null], $h)->assertForbidden();
    $this->deleteJson("/api/v1/inv/investigations/{$inv}/tree/causes/{$a}", [], $h)->assertForbidden();
    expect($this->getJson("/api/v1/inv/investigations/{$inv}/tree", $h)->assertOk()->json('data.can'))->toMatchArray(['create' => false, 'update' => false, 'delete' => false]);
});
