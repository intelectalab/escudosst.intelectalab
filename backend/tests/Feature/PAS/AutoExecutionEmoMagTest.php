<?php

// M-PAS RN-PAS-05 y §12: EMO y MAG también ejecutan las actividades con regla «al registrarse en el módulo origen». Del EMO solo
// cuenta la orden de la base principal (atendida o con aptitud registrada), nunca datos clínicos; de MAG, la medición aprobada.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update companies set sst_model = 'supervisor' where id = ?", [ANDINAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('MAG', 'EMO', 'PAS') and deleted_at is null", [ANDINAS_ID]);
    $this->year = (int) now('America/Lima')->year;
    $this->month = (int) now('America/Lima')->month;
    DB::update('update annual_programs set deleted_at = now() where year = ? and deleted_at is null', [$this->year]);
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
});

function aemProgram(\Tests\TestCase $t, array $h, int $year, int $month): array
{
    $prog = $t->postJson('/api/v1/records/PAS/annual_programs', ['year' => $year, 'title' => "PASST {$year}"], $h)->assertCreated()->json('data.id');
    $emo = $t->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'E1', 'title' => 'Exámenes médicos periódicos', 'activity_type' => 'emo',
        'linked_module' => 'EMO', 'auto_execution_rule' => 'on_source_record', 'planned_months' => [$month]], $h)->assertCreated()->json('data.id');
    $mag = $t->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'M1', 'title' => 'Monitoreo de ruido', 'activity_type' => 'monitoring',
        'linked_module' => 'MAG', 'auto_execution_rule' => 'on_source_record', 'planned_months' => [$month]], $h)->assertCreated()->json('data.id');
    linkProgramObjective($prog, ANDINAS_ID);
    $t->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $h)->assertOk();

    return [$emo, $mag];
}

it('la validación acepta EMO y MAG como módulo de la ejecución automática y rechaza otros', function () {
    $prog = $this->postJson('/api/v1/records/PAS/annual_programs', ['year' => $this->year, 'title' => 'PASST'], $this->h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'X', 'title' => 'Otro', 'activity_type' => 'other',
        'linked_module' => 'ACR', 'auto_execution_rule' => 'on_source_record', 'planned_months' => [$this->month]], $this->h)
        ->assertStatus(422)->assertJsonPath('errors.linked_module.0', fn ($m) => str_contains($m, 'EMO (examen médico ocupacional realizado)') && str_contains($m, 'MAG (medición de agentes aprobada)'));
});

it('el examen médico atendido y la medición aprobada ejecutan sus actividades, una vez y pendientes de validar', function () {
    [$emoAct, $magAct] = aemProgram($this, $this->h, $this->year, $this->month);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $w = DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status) values (?, '73730001', decode(md5('aem'), 'hex'), 'Examinado', 'Prueba', 'active') returning id", [ANDINAS_ID])->id;
    $order = DB::selectOne("insert into medical_exam_orders (company_id, worker_id, exam_type, scheduled_at, status) values (?, ?, 'periodic', now(), 'scheduled') returning id", [ANDINAS_ID, $w])->id;
    $count = fn (string $a) => (int) DB::selectOne('select count(*) as n from activity_executions where activity_id = ? and deleted_at is null', [$a])->n;

    \App\Modules\REC\Rules\DocsFieldRules::emoOrderExecuted($order);   // programada: aún no
    expect($count($emoAct))->toBe(0);
    DB::update("update medical_exam_orders set status = 'attended', attended_at = now() where id = ?", [$order]);
    \App\Modules\REC\Rules\DocsFieldRules::emoOrderExecuted($order);
    \App\Modules\REC\Rules\DocsFieldRules::emoOrderExecuted($order);   // una sola vez
    $x = DB::selectOne('select * from activity_executions where activity_id = ? and deleted_at is null', [$emoAct]);
    expect($count($emoAct))->toBe(1)->and($x->source_module)->toBe('EMO')->and($x->source_id)->toBe($order)->and($x->verified_at)->toBeNull()
        ->and($x->description)->toBe('Registrado desde EMO: Examen médico ocupacional periódico');

    // MAG: la medición en borrador no cuenta; aprobada, sí.
    $lim = DB::selectOne("select id, agent_type_id, limit_value, unit from exposure_limits where deleted_at is null and limit_value > 0 and (valid_from is null or valid_from <= current_date)
                            and (valid_to is null or valid_to >= current_date) order by created_at limit 1");
    DB::select("select set_config('app.company_id', '', false)");
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'S-AEM', 'name' => 'Sede AEM'], $this->h)->assertCreated()->json('data.id');
    $point = $this->postJson('/api/v1/records/MAG/monitoring_points', ['code' => 'P-AEM', 'name' => 'Prensa', 'agent_type_id' => $lim->agent_type_id, 'site_id' => $site], $this->h)->assertCreated()->json('data.id');
    $camp = $this->postJson('/api/v1/records/MAG/monitoring_campaigns', ['title' => 'Monitoreo AEM', 'year' => $this->year], $this->h)->assertCreated()->json('data.id');
    $m = $this->postJson('/api/v1/records/MAG/measurements', ['campaign_id' => $camp, 'monitoring_point_id' => $point, 'agent_type_id' => $lim->agent_type_id, 'exposure_limit_id' => $lim->id,
        'unit' => $lim->unit, 'measured_at' => now()->toIso8601String(), 'value' => (float) $lim->limit_value * 0.5], $this->h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect($count($magAct))->toBe(0);
    DB::select("select set_config('app.company_id', '', false)");
    $this->patchJson("/api/v1/records/MAG/measurements/{$m}", ['status' => 'approved'], $this->h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect($count($magAct))->toBe(1)
        ->and(DB::selectOne('select source_module, source_id from activity_executions where activity_id = ? and deleted_at is null', [$magAct])->source_id)->toBe($m);
});
