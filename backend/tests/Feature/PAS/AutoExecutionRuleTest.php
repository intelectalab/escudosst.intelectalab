<?php

// M-PAS SCR-PAS-02 / RN-PAS-05 y criterio 2: la regla de ejecución automática de la actividad. Un registro del módulo vinculado
// (inspección finalizada) de la misma sede y de un mes programado crea la ejecución pendiente de validar, sin vincularlo a mano;
// una vez por actividad y mes. Otra sede, otro mes o la regla «manual» no la crean.

use App\Modules\REC\Rules\DocsFieldRules;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update companies set sst_model = 'supervisor' where id = ?", [ANDINAS_ID]);
    $this->year = (int) now('America/Lima')->year;
    $this->month = (int) now('America/Lima')->month;
    DB::update('update annual_programs set deleted_at = now() where year = ? and deleted_at is null', [$this->year]);
});

function autoInspection(string $site, string $completedAt): string
{
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $id = DB::selectOne("insert into inspections (company_id, code, title, inspection_type, site_id, status, completed_at) values (?, ?, 'Inspección del almacén', 'planned', ?, 'completed', ?) returning id",
        [ANDINAS_ID, 'INS-AUTO-'.substr(uniqid(), -6), $site, $completedAt])->id;
    DocsFieldRules::programExecutionFor('inspections', $id, 'INS');

    return $id;
}

it('la inspección finalizada de la sede en el mes programado marca la actividad ejecutada, pendiente de validar', function () {
    $h = $this->h;
    $s1 = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ALM-1', 'name' => 'Almacén central'], $h)->assertCreated()->json('data.id');
    $s2 = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ALM-2', 'name' => 'Almacén norte'], $h)->assertCreated()->json('data.id');
    $prog = $this->postJson('/api/v1/records/PAS/annual_programs', ['year' => $this->year, 'title' => "PASST {$this->year}"], $h)->assertCreated()->json('data.id');

    // SCR-PAS-02: la regla automática necesita el módulo origen.
    $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'X', 'title' => 'Sin módulo', 'activity_type' => 'inspection',
        'auto_execution_rule' => 'on_source_record', 'planned_months' => [$this->month]], $h)->assertStatus(422)->assertJsonValidationErrors('linked_module');

    $auto = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'A1', 'title' => 'Inspección mensual de almacén', 'activity_type' => 'inspection',
        'linked_module' => 'INS', 'auto_execution_rule' => 'on_source_record', 'site_id' => $s1, 'goal_quantity' => 12, 'planned_months' => range(1, 12)], $h)
        ->assertCreated()->assertJsonPath('data.auto_execution_rule', 'on_source_record')->json('data.id');
    $other = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'A2', 'title' => 'Inspección del almacén norte', 'activity_type' => 'inspection',
        'linked_module' => 'INS', 'auto_execution_rule' => 'on_source_record', 'site_id' => $s2, 'planned_months' => [$this->month]], $h)->assertCreated()->json('data.id');
    $manual = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'A3', 'title' => 'Inspección manual', 'activity_type' => 'inspection',
        'linked_module' => 'INS', 'planned_months' => [$this->month]], $h)->assertCreated()->assertJsonPath('data.auto_execution_rule', 'manual')->json('data.id');
    linkProgramObjective($prog, ANDINAS_ID);

    // Programa en borrador: aún no cuenta.
    autoInspection($s1, now()->toIso8601String());
    expect(DB::selectOne('select count(*) as n from activity_executions where activity_id = ?', [$auto])->n)->toBe(0);

    $this->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $h)->assertOk();
    $ins = autoInspection($s1, now()->toIso8601String());
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $x = DB::selectOne('select * from activity_executions where activity_id = ? and deleted_at is null', [$auto]);
    expect($x->source_id)->toBe($ins)->and($x->source_module)->toBe('INS')->and((int) $x->planned_month)->toBe($this->month)->and($x->verified_at)->toBeNull();
    expect(DB::selectOne('select count(*) as n from activity_executions where activity_id in (?, ?)', [$other, $manual])->n)->toBe(0);
    expect(DB::selectOne('select status from annual_programs where id = ?', [$prog])->status)->toBe('in_execution');

    // Una sola vez por actividad y mes; la misma inspección no se cuenta dos veces.
    DocsFieldRules::programExecutionFor('inspections', $ins, 'INS');
    autoInspection($s1, now()->toIso8601String());
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne('select count(*) as n from activity_executions where activity_id = ? and deleted_at is null', [$auto])->n)->toBe(1);

    // Otro año no coincide con el programa.
    autoInspection($s1, ($this->year + 1).'-01-15T12:00:00-05:00');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne('select count(*) as n from activity_executions where activity_id = ? and deleted_at is null', [$auto])->n)->toBe(1);

    // Pendiente de validar hasta que el Jefe SST la valida; validada, cuenta en el avance.
    $this->getJson("/api/v1/records/PAS/program_activities/{$auto}", $h)->assertJsonPath('data.progress_pct', 0);
    $p = collect($this->getJson('/api/v1/pas/pending-validation', $h)->json('data'))->firstWhere('id', $x->id);
    expect($p)->not->toBeNull();
    $this->postJson("/api/v1/pas/executions/{$x->id}/validate", [], $h)->assertOk();
    $this->getJson("/api/v1/records/PAS/program_activities/{$auto}", $h)->assertJsonPath('data.executed_quantity', 1);
});
