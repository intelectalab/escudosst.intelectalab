<?php

// Carga masiva (M-TRB SCR-TRB-04/05): cada importación queda en bulk_imports con el archivo original y sus filas;
// se listan las cargas anteriores; modo «cesar» con fecha y motivo.

use App\Core\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function biSheet(array $rows, bool $withTermination = false): UploadedFile
{
    $head = ['Tipo de documento', 'Número de documento', 'Nombres', 'Apellido paterno', 'Apellido materno', 'Fecha de nacimiento', 'Sexo', 'Celular', 'Correo',
        'Fecha de ingreso', 'Tipo de vínculo', 'Sede', 'Área', 'Puesto', 'Código de empleado'];
    if ($withTermination) {
        $head = [...$head, 'Fecha de cese', 'Motivo de cese'];
    }
    $bytes = Xlsx::build('Trabajadores', [['EscudoSST · Plantilla de trabajadores · versión TRB-W1'], ['Instrucciones'], ['Sedes'], ['Puestos'], $head, ...$rows], 5);

    return UploadedFile::fake()->createWithContent('trabajadores.xlsx', $bytes);
}

it('registra la carga con su archivo y filas, la lista y cesa trabajadores con fecha y motivo', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->postJson('/api/v1/records/TEN/sites', ['code' => 'BI-S', 'name' => 'Planta BI'], $h)->assertCreated();
    $this->postJson('/api/v1/records/TEN/positions', ['code' => 'BI-P', 'name' => 'Operario BI'], $h)->assertCreated();
    $rows = [
        ['DNI', '72223333', 'Marta', 'Carga', '', '', 'F', '', '', '01/02/2024', 'Propio', 'BI-S', '', 'BI-P', ''],
        ['DNI', '72224444', 'Pablo', 'Carga', '', '', 'M', '', '', '01/02/2024', 'Propio', 'BI-S', '', 'BI-P', ''],
        ['DNI', '7222', 'Mal', 'Documento', '', '', '', '', '', '', '', 'BI-S', '', 'BI-P', ''],
    ];
    $r = $this->post('/api/v1/trb/imports', ['file' => biSheet($rows), 'mode' => 'create'], $h)->assertOk();
    expect($r->json('data.created'))->toBe(2)->and($r->json('data.failed'))->toBe(1);
    $import = $r->json('data.import_id');

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $b = DB::selectOne('select b.*, f.original_name, f.module_code, f.entity_id from bulk_imports b join files f on f.id = b.file_id where b.id = ?', [$import]);
    expect($b)->toMatchArray(['status' => 'completed', 'mode' => 'create', 'total_rows' => 3, 'ok_rows' => 2, 'error_rows' => 1, 'template_version' => 'TRB-W1',
        'original_name' => 'trabajadores.xlsx', 'module_code' => 'TRB', 'entity_id' => $import]);

    $list = collect($this->getJson('/api/v1/trb/imports', $h)->assertOk()->json('data'));
    expect($list->firstWhere('id', $import))->toMatchArray(['ok_rows' => 2, 'error_rows' => 1, 'file_name' => 'trabajadores.xlsx'])
        ->and($list->firstWhere('id', $import)['options'])->toBe(['created' => 2, 'updated' => 0, 'terminated' => 0]);
    $detail = $this->getJson("/api/v1/trb/imports/{$import}", $h)->assertOk()->json('data.rows');
    expect($detail)->toHaveCount(3)->and($detail[2]['status'])->toBe('error')->and($detail[2]['errors'])->toContain('DNI debe tener 8 dígitos.')
        ->and($detail[0]['document'])->toBe('DNI ****3333');

    // Modo «cesar»: trabajadores listados por documento con fecha y motivo.
    $term = [
        ['DNI', '72223333', '', '', '', '', '', '', '', '', '', '', '', '', '', '15/10/2026', 'Renuncia voluntaria'],
        ['DNI', '72224444', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Sin fecha'],
        ['DNI', '79999999', '', '', '', '', '', '', '', '', '', '', '', '', '', '15/10/2026', 'No existe'],
        ['DNI', '72224444', '', '', '', '', '', '', '', '', '', '', '', '', '', '01/01/2020', 'Antes del ingreso'],
    ];
    $p = $this->post('/api/v1/trb/imports/preview', ['file' => biSheet($term, true), 'mode' => 'terminate'], $h)->assertOk();
    $pr = collect($p->json('data.rows'))->keyBy('row');
    expect($p->json('data.valid'))->toBe(1)->and($pr[6]['action'])->toBe('terminate')->and($pr[6]['name'])->toBe('Marta Carga')
        ->and($pr[7]['errors'])->toContain('Falta la fecha de cese.')
        ->and($pr[8]['errors'])->toContain('No hay un trabajador con ese documento.');
    $t = $this->post('/api/v1/trb/imports', ['file' => biSheet($term, true), 'mode' => 'terminate'], $h)->assertOk();
    expect($t->json('data.terminated'))->toBe(1)->and($t->json('data.failed'))->toBe(3);
    $wid = collect($t->json('data.rows'))->firstWhere('row', 6)['worker_id'];
    $w = $this->getJson("/api/v1/records/TRB/workers/{$wid}", $h)->assertOk();
    expect($w->json('data.status'))->toBe('terminated')->and(substr((string) $w->json('data.termination_on'), 0, 10))->toBe('2026-10-15')
        ->and($w->json('data.termination_reason'))->toBe('Renuncia voluntaria');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne('select count(*) as n from worker_assignments where worker_id = ? and ends_on is null and deleted_at is null', [$wid])->n)->toBe(0);
    expect(DB::selectOne("select mode, ok_rows from bulk_imports where id = ?", [$t->json('data.import_id')]))->mode->toBe('terminate');
});

it('rechaza un modo de carga desconocido', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->post('/api/v1/trb/imports', ['file' => biSheet([['DNI', '72225555', '', '', '', '', '', '', '', '', '', '', '', '', '', '15/10/2026', 'Motivo']], true), 'mode' => 'otro'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('mode');
});
