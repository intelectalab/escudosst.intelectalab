<?php

// Cronograma del Programa Anual SST (SCR-PAS-01): celdas programado / ejecutado / atrasado y % por objetivo (RN-PAS-01/02).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function pasH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('arma el cronograma actividad × mes con estados y avance por objetivo', function () {
    $h = pasH();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update companies set sst_model = 'supervisor' where id = ?", [ANDINAS_ID]);
    $year = (int) now('America/Lima')->year - 1;   // año pasado: todos los meses ya cerraron
    DB::update('update annual_programs set deleted_at = now() where year = ?', [$year]);
    $obj = DB::selectOne(
        "insert into sst_objectives (company_id, year, code, title, status) values (?, ?, 'OBJ-T', 'Reducir accidentes', 'active') returning id",
        [ANDINAS_ID, $year]
    )->id;
    $prog = $this->postJson('/api/v1/records/PAS/annual_programs', ['year' => $year, 'title' => "PASST {$year}"], $h)->assertCreated()->json('data.id');
    $a1 = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'A1', 'title' => 'Inspección', 'activity_type' => 'inspection',
        'goal_quantity' => 2, 'planned_months' => [3, 6], 'objective_id' => $obj], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'A2', 'title' => 'Simulacro', 'activity_type' => 'drill',
        'goal_quantity' => 1, 'planned_months' => [9]], $h)->assertCreated();

    $draft = $this->getJson("/api/v1/pas/schedule?year={$year}", $h)->assertOk();
    expect($draft->json('data.program.id'))->toBe($prog)->and($draft->json('data.can_register'))->toBeFalse();

    $this->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $h)->assertOk();
    $this->postJson('/api/v1/records/PAS/activity_executions', ['activity_id' => $a1, 'executed_on' => "{$year}-03-20", 'quantity' => 1, 'description' => 'Acta INS-01'], $h)->assertCreated();
    $this->postJson('/api/v1/records/PAS/activity_executions', ['activity_id' => $a1, 'executed_on' => "{$year}-04-02", 'quantity' => 1, 'description' => 'Acta INS-02'], $h)->assertCreated();

    $d = $this->getJson("/api/v1/pas/schedule?year={$year}", $h)->assertOk()->json('data');
    expect($d['can_register'])->toBeTrue()->and($d['cutoff_month'])->toBe(12);
    $act = collect($d['activities'])->firstWhere('id', $a1);
    $cell = fn (int $m) => collect($act['cells'])->firstWhere('month', $m)['state'];
    expect($cell(3))->toBe('executed')->and($cell(4))->toBe('extra')->and($cell(6))->toBe('overdue')->and($cell(1))->toBeNull();
    expect($d['progress'])->toMatchArray(['due' => 3, 'done' => 1, 'pct' => 33, 'overdue' => 2]);
    $o = collect($d['objectives'])->firstWhere('id', $obj);
    expect($o)->toMatchArray(['due' => 2, 'done' => 1, 'pct' => 50]);
    expect(collect($d['objectives'])->firstWhere('id', null)['pct'])->toBe(0);
});

it('sin programa del año responde vacío y valida el programa', function () {
    $this->getJson('/api/v1/pas/schedule?year=2001', pasH())->assertOk()->assertJsonPath('data.program', null);
    $this->getJson('/api/v1/pas/schedule?program_id=x', pasH())->assertNotFound();
});
