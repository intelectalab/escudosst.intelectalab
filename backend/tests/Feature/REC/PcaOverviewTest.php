<?php

// Programa de capacitación (M-PCA): vista anual con estado por tema y cumplimiento (SCR-PCA-01, RN-PCA-03/04) y
// reprogramación con motivo de un tema no ejecutado de un mes cerrado (RN-PCA-08).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('muestra los temas por mes con su estado y reprograma con motivo un tema no ejecutado', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $month = (int) now('America/Lima')->format('n');
    if ($month === 1) {
        $this->markTestSkipped('En enero no hay meses cerrados en el año.');
    }
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'PCA' and deleted_at is null", [HUAYLAS_ID]);
    $year = (int) now('America/Lima')->format('Y');
    $prog = DB::selectOne("insert into training_programs (company_id, year, title, status) values (?, ?, 'Programa PCA prueba', 'approved') returning id", [HUAYLAS_ID, $year])->id;
    $item = DB::selectOne("insert into training_program_items (company_id, training_program_id, topic, training_type, planned_month, status) values (?, ?, 'Orden y limpieza', 'specific', ?, 'planned') returning id",
        [HUAYLAS_ID, $prog, $month - 1])->id;
    DB::select("select set_config('app.company_id', '', false)");

    $o = $this->getJson("/api/v1/pca/overview?program_id={$prog}", $h)->assertOk();
    $it = collect($o->json('data.items'))->firstWhere('id', $item);
    expect($it['state'])->toBe('not_executed')->and($it)->toHaveKeys(['target', 'done', 'pct']);

    // El programa aprobado no cambia, salvo reprogramar el tema no ejecutado con motivo y a este mes o uno siguiente.
    $this->patchJson("/api/v1/records/PCA/training_program_items/{$item}", ['topic' => 'Otro tema'], $h)->assertStatus(409);
    $this->patchJson("/api/v1/records/PCA/training_program_items/{$item}", ['planned_month' => $month], $h)->assertStatus(422)->assertJsonValidationErrors('reschedule_reason');
    $this->patchJson("/api/v1/records/PCA/training_program_items/{$item}", ['planned_month' => $month, 'reschedule_reason' => 'No llegó el capacitador'], $h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $row = DB::selectOne('select planned_month, original_month from training_program_items where id = ?', [$item]);
    expect((int) $row->planned_month)->toBe($month)->and((int) $row->original_month)->toBe($month - 1);
});
