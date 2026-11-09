<?php

// Motor de registros de los módulos SST: catálogo, altas/ediciones/archivo, reglas TEN/TRB,
// cifrado del documento, aislamiento entre empresas y control de plan.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function rec(string $email, string $company): array
{
    return authHeaders(tokenFor($email), $company);
}

it('describe las pestañas del módulo con sus campos y permisos', function () {
    $res = $this->getJson('/api/v1/records/TEN', rec(PROFESIONAL_EMAIL, ANDINAS_ID))->assertOk();

    $tables = collect($res->json('resources'))->pluck('table')->all();
    expect($tables)->toContain('sites', 'areas', 'positions', 'work_shifts');

    $sites = collect($res->json('resources'))->firstWhere('table', 'sites');
    expect($sites['can'])->toMatchArray(['read' => true, 'create' => true, 'update' => true, 'delete' => true])
        ->and(collect($sites['fields'])->firstWhere('name', 'site_type')['options'][0])->toHaveKeys(['value', 'label']);
});

it('crea, edita y archiva una sede con auditoría', function () {
    $h = rec(PROFESIONAL_EMAIL, ANDINAS_ID);

    $id = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'OBRA-T1', 'name' => 'Obra de prueba', 'site_type' => 'project',
        'is_temporary' => true, 'starts_on' => '2026-11-01'], $h)
        ->assertCreated()->assertJsonPath('data.name', 'Obra de prueba')->json('data.id');

    $this->patchJson("/api/v1/records/TEN/sites/{$id}", ['name' => 'Obra Norte'], $h)
        ->assertOk()->assertJsonPath('data.name', 'Obra Norte');

    $this->getJson('/api/v1/records/TEN/sites?q=norte', $h)->assertOk()->assertJsonPath('data.0.id', $id);

    $this->deleteJson("/api/v1/records/TEN/sites/{$id}", [], $h)->assertNoContent();
    $this->getJson("/api/v1/records/TEN/sites/{$id}", $h)->assertNotFound();

    $actions = DB::table('audit_logs')->where('entity_id', $id)->orderBy('chain_seq')->pluck('action')->all();
    expect($actions)->toBe(['create', 'update', 'delete']);
});

it('valida campos obligatorios, opciones y códigos repetidos con mensajes claros', function () {
    $h = rec(PROFESIONAL_EMAIL, ANDINAS_ID);

    $this->postJson('/api/v1/records/TEN/sites', ['site_type' => 'nave'], $h)
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', 'Completa «Código».')
        ->assertJsonStructure(['errors' => ['name', 'site_type']]);

    $this->postJson('/api/v1/records/TEN/sites', ['code' => 'DUP-1', 'name' => 'Uno'], $h)->assertCreated();
    $this->postJson('/api/v1/records/TEN/sites', ['code' => 'DUP-1', 'name' => 'Dos'], $h)
        ->assertStatus(422)->assertJsonPath('type', 'validation_failed');

    $this->postJson('/api/v1/records/TEN/sites', ['code' => 'TMP-1', 'name' => 'Temporal', 'is_temporary' => true], $h)
        ->assertStatus(422)->assertJsonPath('errors.starts_on.0', 'Una sede temporal (obra o proyecto) necesita fecha de inicio.');
});

it('no deja sin sede activa a la empresa', function () {
    $h = rec(PROFESIONAL_EMAIL, ANDINAS_ID);
    // Deja una sola sede activa.
    $active = collect($this->getJson('/api/v1/records/TEN/sites?per_page=100&f[status]=active', $h)->json('data'))->pluck('id');
    $keep = $active->shift();
    foreach ($active as $id) {
        $this->patchJson("/api/v1/records/TEN/sites/{$id}", ['status' => 'inactive', 'is_main' => false], $h)->assertOk();
    }

    $this->patchJson("/api/v1/records/TEN/sites/{$keep}", ['status' => 'inactive'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'Es la única sede activa de la empresa: no puede quedar inactiva.');
    $this->deleteJson("/api/v1/records/TEN/sites/{$keep}", [], $h)->assertStatus(409);
});

it('arma el árbol de áreas sin ciclos y calcula el nivel', function () {
    $h = rec(PROFESIONAL_EMAIL, ANDINAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ARB', 'name' => 'Planta árbol'], $h)->json('data.id');

    $a = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'PRD', 'name' => 'Producción'], $h)
        ->assertCreated()->assertJsonPath('data.depth', 0)->json('data.id');
    $b = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'L1', 'name' => 'Línea 1', 'parent_area_id' => $a], $h)
        ->assertCreated()->assertJsonPath('data.depth', 1)->json('data.id');

    $this->patchJson("/api/v1/records/TEN/areas/{$a}", ['parent_area_id' => $b], $h)
        ->assertStatus(422)->assertJsonPath('errors.parent_area_id.0', 'Esa área ya depende de la que estás editando: se formaría un ciclo.');

    $this->deleteJson("/api/v1/records/TEN/areas/{$a}", [], $h)->assertStatus(409);

    $opts = $this->getJson('/api/v1/records/TEN/areas/options/parent_area_id?q=prod', $h)->assertOk()->json('data');
    expect(collect($opts)->pluck('id'))->toContain($a);
});

it('calcula las horas del turno, incluso si pasa la medianoche', function () {
    $h = rec(PROFESIONAL_EMAIL, ANDINAS_ID);

    $this->postJson('/api/v1/records/TEN/work_shifts', ['code' => 'NOCHE', 'name' => 'Noche', 'start_time' => '22:00', 'end_time' => '06:00'], $h)
        ->assertCreated()->assertJsonPath('data.hours_per_day', 8)->assertJsonPath('data.is_night', true)->assertJsonPath('data.start_time', '22:00');
});

it('guarda el documento del trabajador cifrado, evita duplicados y registra su asignación', function () {
    $h = rec(PROFESIONAL_EMAIL, ANDINAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'TRB-S', 'name' => 'Sede trabajadores'], $h)->json('data.id');
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'OPE-T', 'name' => 'Operario'], $h)->json('data.id');

    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_type' => 'DNI', 'document_number' => '4567 8912', 'first_name' => 'Rosa',
        'paternal_last_name' => 'Quispe', 'hire_on' => '2026-10-01', 'current_site_id' => $site, 'current_position_id' => $pos], $h)
        ->assertCreated()
        ->assertJsonPath('data.document_number', '45678912')
        ->assertJsonPath('data.document_last4', '8912')
        ->assertJsonPath('data.full_name', 'Rosa Quispe')
        ->json('data.id');

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $raw = DB::table('workers')->where('id', $w)->value('document_number');
    expect($raw)->not->toContain('45678912');

    $this->getJson('/api/v1/records/TRB/workers', $h)->assertOk()->assertJsonMissingPath('data.0.document_number');

    $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '45678912', 'first_name' => 'Otra', 'paternal_last_name' => 'Persona'], $h)
        ->assertStatus(422)->assertJsonPath('errors.document_number.0', 'Ya hay un trabajador con ese documento: Rosa Quispe.');
    $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '123', 'first_name' => 'X', 'paternal_last_name' => 'Y'], $h)
        ->assertStatus(422)->assertJsonPath('errors.document_number.0', 'El DNI tiene 8 dígitos.');

    $assign = $this->getJson("/api/v1/records/TRB/worker_assignments?f[worker_id]={$w}", $h)->assertOk()->json('data');
    expect($assign)->toHaveCount(1)->and($assign[0]['starts_on'])->toBe('2026-10-01');

    // La sede con trabajadores activos no se archiva.
    $this->deleteJson("/api/v1/records/TEN/sites/{$site}", [], $h)->assertStatus(409);
});

it('aísla los registros entre empresas', function () {
    $id = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'PRIV', 'name' => 'Privada Andinas'], rec(PROFESIONAL_EMAIL, ANDINAS_ID))->json('data.id');

    $other = rec(CORPORATIVO_EMAIL, HUAYLAS_ID);
    $this->getJson("/api/v1/records/TEN/sites/{$id}", $other)->assertNotFound();
    $this->patchJson("/api/v1/records/TEN/sites/{$id}", ['name' => 'X'], $other)->assertNotFound();
    expect(collect($this->getJson('/api/v1/records/TEN/sites?per_page=100', $other)->json('data'))->pluck('id'))->not->toContain($id);
});

it('respeta el plan: un módulo fuera del plan responde 403', function () {
    // MAG (agentes ocupacionales) es del plan Profesional: la empresa Esencial no lo tiene.
    $this->getJson('/api/v1/records/MAG', rec(ESENCIAL_EMAIL, AGRO_ID))
        ->assertStatus(403)->assertJsonPath('type', 'module_not_in_plan');
});

it('no acepta tablas de otro módulo ni identificadores inválidos', function () {
    $h = rec(PROFESIONAL_EMAIL, ANDINAS_ID);
    $this->getJson('/api/v1/records/TEN/workers', $h)->assertNotFound();
    $this->getJson('/api/v1/records/TEN/users', $h)->assertNotFound();
    $this->getJson('/api/v1/records/TEN/sites/no-es-uuid', $h)->assertNotFound();
});

it('abre la lista y el detalle de todas las tablas del catálogo sin errores', function () {
    $h = rec(CORPORATIVO_EMAIL, HUAYLAS_ID);
    $failures = [];
    foreach (\App\Modules\REC\Registry::data()['modules'] as $module => $tables) {
        $schema = $this->getJson("/api/v1/records/{$module}", $h);
        if ($schema->status() === 403) {
            continue; // fuera del plan Corporativo (complementos o Consultora)
        }
        if ($schema->status() !== 200) {
            $failures[] = "{$module}: {$schema->status()}";

            continue;
        }
        foreach ($tables as $table) {
            $list = $this->getJson("/api/v1/records/{$module}/{$table}?q=a", $h);
            if (! in_array($list->status(), [200, 403], true)) {
                $failures[] = "{$module}/{$table}: {$list->status()} ".$list->json('detail');
            }
            foreach (\App\Modules\REC\Registry::resource($module, $table)['fields'] as $f) {
                if ($f['type'] === 'ref' && $list->status() === 200) {
                    $o = $this->getJson("/api/v1/records/{$module}/{$table}/options/{$f['name']}?q=a", $h);
                    if ($o->status() !== 200) {
                        $failures[] = "{$module}/{$table}.{$f['name']}: {$o->status()}";
                    }
                }
            }
        }
    }
    expect($failures)->toBe([]);
});
