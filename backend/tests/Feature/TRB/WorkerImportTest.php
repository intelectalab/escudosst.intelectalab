<?php

// Carga de trabajadores desde Excel (M-TRB SCR-TRB-04/05) y semáforo de requisitos (SCR-TRB-02, RN-PTR-01).

use App\Core\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function trbSetup(array $h): array
{
    $site = test()->postJson('/api/v1/records/TEN/sites', ['code' => 'IMP-S', 'name' => 'Planta Importación'], $h)->assertCreated()->json('data.id');
    $area = test()->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'IMP-A', 'name' => 'Almacén Importación'], $h)->assertCreated()->json('data.id');
    $pos = test()->postJson('/api/v1/records/TEN/positions', ['code' => 'IMP-P', 'name' => 'Operario de importación', 'area_id' => $area], $h)->assertCreated()->json('data.id');

    return [$site, $area, $pos];
}

function trbSheet(array $rows, string $version = 'TRB-W1'): UploadedFile
{
    $head = ['Tipo de documento', 'Número de documento', 'Nombres', 'Apellido paterno', 'Apellido materno', 'Fecha de nacimiento', 'Sexo', 'Celular', 'Correo',
        'Fecha de ingreso', 'Tipo de vínculo', 'Sede', 'Área', 'Puesto', 'Código de empleado'];
    $bytes = Xlsx::build('Trabajadores', [["EscudoSST · Plantilla de trabajadores · versión {$version}"], ['Instrucciones'], ['Sedes'], ['Puestos'], $head, ...$rows], 5);

    return UploadedFile::fake()->createWithContent('trabajadores.xlsx', $bytes);
}

it('descarga la plantilla versionada', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $res = $this->get('/api/v1/trb/imports/template', $h)->assertOk();
    expect($res->headers->get('content-type'))->toContain('spreadsheetml')
        ->and($res->headers->get('content-disposition'))->toContain('TRB-W1');
});

it('previsualiza con errores por fila e importa solo las válidas', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    trbSetup($h);
    $rows = [
        ['DNI', '71112222', 'Rosa', 'Quispe', 'Mamani', '15/03/1990', 'F', '987654321', 'rosa@correo.test', '01/02/2024', 'Propio', 'IMP-S', 'Almacén Importación', 'IMP-P', 'E-1'],
        ['DNI', '7111222', 'Corto', 'Digitos', '', '', '', '', '', '', '', 'IMP-S', '', 'IMP-P', ''],
        ['DNI', '71112222', 'Repetida', 'Fila', '', '', '', '', '', '', '', 'IMP-S', '', 'IMP-P', ''],
        ['', '73334444', 'Juan', 'Sin Sede', '', '', '', '', '', '', '', 'No existe', '', 'Puesto fantasma', ''],
        ['CE', 'X12345', 'Ana', 'Extranjera', '', '', 'F', '', '', '2024-05-01', 'Intermediado', 'Planta Importación', '', 'Operario de importación', ''],
    ];
    $p = $this->post('/api/v1/trb/imports/preview', ['file' => trbSheet($rows), 'mode' => 'create'], $h)->assertOk();
    expect($p->json('data.total'))->toBe(5)->and($p->json('data.valid'))->toBe(2)->and($p->json('data.with_errors'))->toBe(3);
    $errs = collect($p->json('data.rows'))->keyBy('row');
    expect($errs[7]['errors'])->toContain('DNI debe tener 8 dígitos.')
        ->and($errs[8]['errors'][0])->toContain('Documento duplicado en el archivo (fila 6)')
        ->and(implode(' ', $errs[9]['errors']))->toContain('La sede «No existe» no existe')->toContain('El puesto «Puesto fantasma» no existe')
        ->and($errs[6]['document'])->toBe('DNI ****2222');
    // La previsualización no guarda nada.
    expect($this->getJson('/api/v1/records/TRB/workers?q=Rosa', $h)->json('meta.total'))->toBe(0);

    $r = $this->post('/api/v1/trb/imports', ['file' => trbSheet($rows), 'mode' => 'create'], $h)->assertOk();
    expect($r->json('data.created'))->toBe(2)->and($r->json('data.failed'))->toBe(3);
    $id = collect($r->json('data.rows'))->firstWhere('row', 6)['worker_id'];
    $w = $this->getJson("/api/v1/records/TRB/workers/{$id}", $h)->assertOk();
    expect($w->json('data.full_name'))->toContain('Rosa')->and($w->json('data.current_position_id'))->not->toBeNull()
        ->and($w->json('data.hire_on'))->toStartWith('2024-02-01');
    expect($this->getJson("/api/v1/records/TRB/worker_assignments?f[worker_id]={$id}", $h)->json('meta.total'))->toBe(1);

    // Segunda carga en modo «solo altas»: el documento ya existe. En «actualizar», no duplica.
    $again = $this->post('/api/v1/trb/imports/preview', ['file' => trbSheet([$rows[0]]), 'mode' => 'create'], $h)->assertOk();
    expect($again->json('data.rows.0.errors.0'))->toContain('Ya existe un trabajador');
    $up = $this->post('/api/v1/trb/imports', ['file' => trbSheet([$rows[0]]), 'mode' => 'upsert'], $h)->assertOk();
    expect($up->json('data.updated'))->toBe(1)->and($up->json('data.created'))->toBe(0);
    expect($this->getJson('/api/v1/records/TRB/workers?q=Rosa', $h)->json('meta.total'))->toBe(1);
});

it('rechaza una plantilla de otra versión', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->post('/api/v1/trb/imports/preview', ['file' => trbSheet([['DNI', '71112222', 'A', 'B']], 'TRB-W0')], $h)
        ->assertStatus(422)->assertJsonValidationErrors('file');
});

it('muestra el semáforo de requisitos del trabajador sin datos clínicos', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    [$site, , $pos] = trbSetup($h);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '76665555', 'first_name' => 'Luis', 'paternal_last_name' => 'Requisitos',
        'current_site_id' => $site, 'current_position_id' => $pos], $h)->assertCreated()->json('data.id');
    $r = $this->getJson("/api/v1/trb/workers/requirements?ids={$w}", $h)->assertOk();
    $checks = collect($r->json('data.0.checks'))->keyBy('code');
    expect($r->json('data.0.enabled'))->toBeFalse()
        ->and($checks->keys()->all())->toBe(['induction', 'emo', 'sctr', 'training', 'ppe'])
        ->and($checks['emo']['status'])->toBe('bad')->and($checks['emo']['detail'])->toBe('Examen médico pendiente')
        ->and($checks['training']['status'])->toBe('na');

    DB::insert("insert into fitness_status (company_id, worker_id, status, valid_from, valid_until, is_current) values (?, ?, 'fit', current_date, current_date + 365, true)", [HUAYLAS_ID, $w]);
    $r = $this->getJson("/api/v1/trb/workers/requirements?ids={$w}", $h)->assertOk();
    expect(collect($r->json('data.0.checks'))->firstWhere('code', 'emo')['status'])->toBe('ok');

    $this->getJson('/api/v1/trb/workers/requirements?ids=nada', $h)->assertStatus(422);
});
