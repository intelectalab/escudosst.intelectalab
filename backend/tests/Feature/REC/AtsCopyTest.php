<?php

// M-ATS SCR-ATS-02: copiar pasos a un ATS en borrador desde un ATS anterior y desde filas del IPERC vigente (RN-ATS-08).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'ATS' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('copia pasos desde un ATS anterior y desde el IPERC, solo a un ATS en borrador', function () {
    [$old, $draft, $signed, $row] = (function () {
        DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
        $site = DB::selectOne('select id from sites where company_id = ? order by is_main desc limit 1', [HUAYLAS_ID])->id;
        $mk = fn ($code, $st) => DB::selectOne("insert into ats_forms (company_id, code, task, site_id, performed_on, status) values (?, ?, 'Cambio de luminaria', ?, current_date, ?) returning id",
            [HUAYLAS_ID, $code.random_int(1000, 999999), $site, $st])->id;
        $old = $mk('ATS-OLD-', 'closed');
        DB::insert("insert into ats_steps (company_id, ats_form_id, step_number, description, hazards, controls) values (?, ?, 1, 'Bloquear energía', 'Contacto eléctrico', 'Candado y tarjeta')", [HUAYLAS_ID, $old]);
        DB::insert("insert into ats_steps (company_id, ats_form_id, step_number, description, hazards, controls) values (?, ?, 2, 'Subir a la escalera', 'Caída', 'Escalera inspeccionada')", [HUAYLAS_ID, $old]);
        $draft = $mk('ATS-NEW-', 'draft');
        $signed = $mk('ATS-SIG-', 'signed');
        $crit = DB::selectOne('select id from risk_criteria where company_id = ? or company_id is null order by company_id nulls last limit 1', [HUAYLAS_ID])->id;
        $m = DB::selectOne("insert into iperc_matrices (company_id, code, title, risk_criteria_id) values (?, ?, 'IPERC ATS', ?) returning id", [HUAYLAS_ID, 'IPC-A-'.random_int(1000, 999999), $crit])->id;
        $v = DB::selectOne("insert into iperc_versions (company_id, iperc_matrix_id, version_number, status, approved_at) values (?, ?, 1, 'current', now()) returning id", [HUAYLAS_ID, $m])->id;
        DB::update('update iperc_matrices set current_version_id = ? where id = ?', [$v, $m]);
        $row = DB::selectOne("insert into iperc_rows (company_id, iperc_version_id, row_number, activity, task, hazard, risk, probability_value, severity_value, risk_level)
            values (?, ?, 1, 'Mantenimiento', 'Cambiar fluorescente', 'Energía eléctrica', 'Electrocución', 2, 3, 'important') returning id", [HUAYLAS_ID, $v])->id;
        DB::insert("insert into iperc_controls (company_id, iperc_row_id, hierarchy, description, is_existing) values (?, ?, 'administrative', 'Bloqueo y etiquetado', true)", [HUAYLAS_ID, $row]);
        DB::select("select set_config('app.company_id', '', false)");

        return [$old, $draft, $signed, $row];
    })();

    $s = $this->getJson("/api/v1/ats/forms/{$draft}/sources", $this->h)->assertOk()->json('data');
    expect(collect($s['ats'])->pluck('id'))->toContain($old)->and(collect($s['iperc'])->pluck('id'))->toContain($row);

    $this->postJson("/api/v1/ats/forms/{$draft}/copy", ['source' => 'ats', 'source_id' => $old], $this->h)->assertOk()->assertJsonPath('data.added', 2);
    $this->postJson("/api/v1/ats/forms/{$draft}/copy", ['source' => 'iperc', 'iperc_row_ids' => [$row]], $this->h)->assertOk()->assertJsonPath('data.added', 1);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $steps = DB::select('select step_number, description, hazards, controls from ats_steps where ats_form_id = ? and deleted_at is null order by step_number', [$draft]);
    DB::select("select set_config('app.company_id', '', false)");
    expect($steps)->toHaveCount(3)->and($steps[2]->description)->toBe('Cambiar fluorescente')->and($steps[2]->controls)->toBe('Bloqueo y etiquetado')->and($steps[2]->step_number)->toBe(3);

    $this->postJson("/api/v1/ats/forms/{$signed}/copy", ['source' => 'ats', 'source_id' => $old], $this->h)->assertStatus(409);
});
