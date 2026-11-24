<?php

// M-MOC RN-MOC-01/03 y «verificado»; M-MAG RN-MAG-05/06/07/08; M-PET brecha SUNAFIL, PDF y versión vigente por QR;
// M-IPC RN-IPC-07 (acta del comité) con el PDF en la gestión documental y criterios editables; M-MAP RN-MAP-01/02.

use Illuminate\Support\Facades\DB;

function rgAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

afterEach(function () {
    // Algunas pruebas cambian el rol del usuario: los permisos guardados en memoria no deben pasar a la siguiente.
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $GLOBALS['sus_tokens'] = [];
});

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    rgAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('MOC', 'MAG', 'PET', 'IPC', 'MAP', 'SUN') and deleted_at is null", [ANDINAS_ID]));
    $this->uid = rgAs(fn () => DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id);
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
});

it('el cambio pide su impacto, espera las revisiones obligatorias, el de impacto alto lo aprueba gerencia y se verifica antes de cerrar', function () {
    $c = $this->postJson('/api/v1/records/MOC/change_requests', ['title' => 'Nueva prensa', 'change_type' => 'equipment', 'description' => 'Cambio de prensa', 'justification' => 'Producción'], $this->h)
        ->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'submitted'], $this->h)->assertStatus(422)->assertJsonValidationErrors('impact_level');
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'submitted', 'impact_level' => 'high'], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'under_review'], $this->h)->assertOk();
    // Revisor obligatorio asignado (pendiente) y una revisión que aprueba.
    $r = $this->postJson('/api/v1/records/MOC/change_reviews', ['change_request_id' => $c, 'reviewer_user_id' => $this->uid, 'review_area' => 'Mantenimiento'], $this->h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/MOC/change_reviews', ['change_request_id' => $c, 'reviewer_user_id' => $this->uid, 'review_area' => 'SST', 'decision' => 'approve'], $this->h)->assertCreated();
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'approved'], $this->h)->assertStatus(422)->assertJsonPath('errors.status.0', 'Faltan revisiones obligatorias resueltas a favor: Mantenimiento.');
    $this->patchJson("/api/v1/records/MOC/change_reviews/{$r}", ['decision' => 'approve'], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/MOC/change_reviews/{$r}", ['comments' => 'otro'], $this->h)->assertStatus(409);
    // Impacto alto: lo que aprueba el Jefe SST queda pendiente de gerencia.
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'approved'], $this->h)->assertOk()->assertJsonPath('data.status', 'pending_management');
    rgAs(fn () => DB::update("update memberships set role_id = (select id from roles where code = 'management' and account_id is null) where user_id = ? and company_id = ?", [$this->uid, ANDINAS_ID]));
    $GLOBALS['sus_tokens'] = [];
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $g = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'approved'], $g)->assertOk()->assertJsonPath('data.management_approved_by', $this->uid);
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'implemented', 'risk_assessment' => ['Evaluado en el IPERC']], $g)->assertOk();
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'closed'], $g)->assertStatus(422);
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'verified'], $g)->assertStatus(422)->assertJsonValidationErrors('verification_notes');
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'verified', 'verification_notes' => 'Funciona y sin incidentes en 30 días'], $g)->assertOk();
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'closed'], $g)->assertOk();
});

it('la medición pasa por borrador, aprobada y anulada; la campaña no cierra con borradores; hay expuestos y puntos sin medir', function () {
    $lim = rgAs(fn () => DB::selectOne("select id, agent_type_id, limit_value, unit from exposure_limits where deleted_at is null and limit_value > 0
                                             and (valid_from is null or valid_from <= current_date) and (valid_to is null or valid_to >= current_date) order by created_at limit 1"));
    $agent = $lim->agent_type_id;
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'PRN-T', 'name' => 'Prensista'], $this->h)->json('data.id');
    $w = rgAs(fn () => DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status, current_position_id) values (?, '44556677', decode(md5('rg'), 'hex'), 'Expuesto', 'Ruido', 'active', ?) returning id", [ANDINAS_ID, $pos])->id);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'S-MAG', 'name' => 'Sede MAG'], $this->h)->json('data.id');
    $point = $this->postJson('/api/v1/records/MAG/monitoring_points', ['code' => 'P-RG', 'name' => 'Prensa 1', 'agent_type_id' => $agent, 'position_id' => $pos, 'site_id' => $site], $this->h)->assertCreated()->json('data.id');
    $never = $this->postJson('/api/v1/records/MAG/monitoring_points', ['code' => 'P-RG2', 'name' => 'Almacén', 'agent_type_id' => $agent, 'site_id' => $site], $this->h)->assertCreated()->json('data.id');
    $camp = $this->postJson('/api/v1/records/MAG/monitoring_campaigns', ['title' => 'Monitoreo RG', 'year' => 2026], $this->h)->assertCreated()->json('data.id');
    $m = $this->postJson('/api/v1/records/MAG/measurements', ['campaign_id' => $camp, 'monitoring_point_id' => $point, 'agent_type_id' => $agent, 'exposure_limit_id' => $lim->id, 'unit' => $lim->unit, 'measured_at' => now()->toIso8601String(), 'value' => (float) $lim->limit_value * 1.2], $this->h)
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.result', 'exceeds_limit')->json('data.id');
    $file = evidenceFile($this->h);
    $this->patchJson("/api/v1/records/MAG/monitoring_campaigns/{$camp}", ['status' => 'closed', 'report_file_id' => $file], $this->h)->assertStatus(422);
    expect($this->getJson('/api/v1/mag/exposed', $this->h)->json('data.workers'))->toBeEmpty();   // en borrador aún no cuenta
    $this->patchJson("/api/v1/records/MAG/measurements/{$m}", ['status' => 'approved'], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/MAG/measurements/{$m}", ['value' => 80], $this->h)->assertStatus(409);
    expect(collect($this->getJson('/api/v1/mag/exposed', $this->h)->json('data.workers'))->pluck('worker_id'))->toContain($w);
    expect(collect($this->getJson('/api/v1/mag/gaps', $this->h)->json('data.never'))->pluck('id'))->toContain($never)->not->toContain($point);
    $this->patchJson("/api/v1/records/MAG/monitoring_campaigns/{$camp}", ['status' => 'closed', 'report_file_id' => $file], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/MAG/measurements/{$m}", ['status' => 'voided'], $this->h)->assertStatus(422)->assertJsonValidationErrors('void_reason');
    $this->patchJson("/api/v1/records/MAG/measurements/{$m}", ['status' => 'voided', 'void_reason' => 'Equipo descalibrado'], $this->h)->assertOk();
    expect($this->getJson('/api/v1/mag/exposed', $this->h)->json('data.workers'))->toBeEmpty();
});

it('un puesto crítico sin PETS es brecha en el diagnóstico y el PETS tiene PDF y versión vigente', function () {
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'ELEC-RG', 'name' => 'Electricista RG', 'is_critical' => true], $this->h)->assertCreated()->json('data.id');
    $area = collect($this->getJson('/api/v1/sun/diagnosis', $this->h)->assertOk()->json('data.areas'))->firstWhere('module', 'PET');
    expect($area['pct'])->toBeLessThan(100)->and($area['detail'])->toContain('Electricista RG');
    $p = $this->postJson('/api/v1/records/PET/procedures', ['code' => 'PETS-RG', 'title' => 'Trabajos eléctricos', 'procedure_type' => 'pets', 'position_id' => $pos], $this->h)->assertCreated()->json('data.id');
    $this->getJson("/api/v1/pet/procedures/{$p}/current", $this->h)->assertStatus(409);
    $v = $this->postJson('/api/v1/records/PET/procedure_versions', ['procedure_id' => $p, 'content_html' => '<p>Bloqueo y etiquetado</p>'], $this->h)->assertCreated()->json('data.id');
    $this->getJson("/api/v1/pet/versions/{$v}/report", $this->h)->assertOk()->assertJsonPath('data.procedure.code', 'PETS-RG')->assertJsonPath('data.version.is_current', false);
    rgAs(function () use ($p, $v) {
        DB::update("update procedure_versions set status = 'current', approved_at = now(), effective_on = current_date where id = ?", [$v]);
        DB::update('update procedures set current_version_id = ? where id = ?', [$v, $p]);
    });
    $this->getJson("/api/v1/pet/procedures/{$p}/current", $this->h)->assertOk()->assertJsonPath('data.version.id', $v);
    expect(collect($this->getJson('/api/v1/sun/diagnosis', $this->h)->json('data.areas'))->firstWhere('module', 'PET')['detail'])->not->toContain('Electricista RG');
});

it('valida las bandas de los criterios del IPERC', function () {
    $id = collect($this->getJson('/api/v1/records/IPC/iperc_matrices/options/risk_criteria_id?q=R.M.', $this->h)->json('data'))->first()['id'];
    $this->patchJson("/api/v1/records/IPC/risk_criteria/{$id}", ['level_bands' => [['label' => 'Bajo', 'min' => 1, 'max' => 10, 'color' => 'green'], ['label' => 'Alto', 'min' => 8, 'max' => 36, 'color' => 'red']]], $this->h)
        ->assertStatus(422)->assertJsonPath('errors.level_bands.0', 'Las bandas «Bajo» y «Alto» se cruzan.');
    $this->patchJson("/api/v1/records/IPC/risk_criteria/{$id}", ['level_bands' => [['label' => 'Bajo', 'min' => 1, 'max' => 10, 'color' => 'blue']]], $this->h)->assertStatus(422);
});

it('con comité el IPERC se aprueba con el acta y su PDF queda en la gestión documental', function () {
    rgAs(fn () => DB::update("update companies set sst_model = 'committee' where id = ?", [ANDINAS_ID]));
    $crit = collect($this->getJson('/api/v1/records/IPC/iperc_matrices/options/risk_criteria_id?q=R.M.', $this->h)->json('data'))->first()['id'];
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'SOLD-RG', 'name' => 'Soldador RG'], $this->h)->json('data.id');
    $matrix = $this->postJson('/api/v1/records/IPC/iperc_matrices', ['code' => 'IPERC-RG', 'title' => 'Taller RG', 'risk_criteria_id' => $crit], $this->h)->assertCreated()->json('data.id');
    $version = $this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", $this->h)->json('data.version_id');
    $this->postJson('/api/v1/records/IPC/iperc_rows', ['iperc_version_id' => $version, 'activity' => 'Soldadura', 'task' => 'Soldar', 'hazard' => 'Chispas', 'risk' => 'Quemadura',
        'probability_detail' => ['exposed_people' => 1, 'procedures' => 1, 'training' => 1, 'exposure' => 1], 'severity_value' => 1, 'position_id' => $pos], $this->h)->assertCreated();
    $this->postJson("/api/v1/ipc/versions/{$version}/submit", ['participants' => ['Ana Quispe']], $this->h)->assertNoContent();
    rgAs(fn () => DB::update('update iperc_versions set prepared_by = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $version]));
    $this->postJson("/api/v1/ipc/versions/{$version}/approve", [], $this->h)->assertStatus(422)->assertJsonValidationErrors('committee_meeting_id');
    $meet = heldMeetingForTests(ANDINAS_ID);
    $this->postJson("/api/v1/ipc/versions/{$version}/approve", ['committee_meeting_id' => $meet], $this->h)->assertOk();
    $v = rgAs(fn () => DB::selectOne('select v.committee_meeting_id, v.file_id, m.document_id from iperc_versions v join iperc_matrices m on m.id = v.iperc_matrix_id where v.id = ?', [$version]));
    expect($v->committee_meeting_id)->toBe($meet)->and($v->file_id)->not->toBeNull()->and($v->document_id)->not->toBeNull();
    expect(rgAs(fn () => DB::selectOne('select d.status, dv.file_id from documents d join document_versions dv on dv.id = d.current_version_id where d.id = ?', [$v->document_id])))
        ->status->toBe('active')->file_id->toBe($v->file_id);
});

it('el pictograma de peligro se liga al IPERC vigente de la sede y el mapa no se aprueba con riesgos significativos sin marcar', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'S-RG', 'name' => 'Sede RG'], $this->h)->json('data.id');
    [$row] = rgAs(function () use ($site) {
        $crit = DB::selectOne('select id from risk_criteria where deleted_at is null order by created_at limit 1')->id;
        $mx = DB::selectOne("insert into iperc_matrices (company_id, code, title, site_id, risk_criteria_id, status) values (?, 'IP-RGM', 'Planta RG', ?, ?, 'active') returning id", [ANDINAS_ID, $site, $crit])->id;
        $v = DB::selectOne("insert into iperc_versions (company_id, iperc_matrix_id, version_number, status, approved_at) values (?, ?, 1, 'current', now()) returning id", [ANDINAS_ID, $mx])->id;
        DB::update('update iperc_matrices set current_version_id = ? where id = ?', [$v, $mx]);
        $r = DB::selectOne("insert into iperc_rows (company_id, iperc_version_id, row_number, activity, task, hazard, risk, probability_value, severity_value, risk_level, is_significant) values (?, ?, 1, 'Corte', 'Cortar', 'Sierra', 'Corte', 9, 2, 'important', true) returning id", [ANDINAS_ID, $v])->id;

        return [$r];
    });
    $map = $this->postJson('/api/v1/records/MAP/risk_maps', ['site_id' => $site, 'title' => 'Mapa RG', 'plan_file_id' => evidenceFile($this->h)], $this->h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/MAP/risk_map_markers', ['risk_map_id' => $map, 'marker_type' => 'hazard', 'x_pct' => 10, 'y_pct' => 10], $this->h)->assertStatus(422)->assertJsonValidationErrors('iperc_row_id');
    $this->patchJson("/api/v1/records/MAP/risk_maps/{$map}", ['status' => 'in_review'], $this->h)->assertStatus(422);
    $ctx = $this->getJson("/api/v1/map/maps/{$map}/context", $this->h)->assertOk()->json('data');
    expect($ctx['unmarked'])->toBe(1)->and(collect($ctx['rows'])->pluck('id'))->toContain($row);
    $this->postJson('/api/v1/records/MAP/risk_map_markers', ['risk_map_id' => $map, 'marker_type' => 'hazard', 'x_pct' => 10, 'y_pct' => 10, 'iperc_row_id' => $row], $this->h)->assertCreated();
    expect($this->getJson("/api/v1/map/maps/{$map}/context", $this->h)->json('data.unmarked'))->toBe(0);
});
