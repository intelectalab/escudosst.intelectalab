<?php

// Brechas de las fichas: página pública de verificación por empresa con su texto y su límite (M-CAP SCR-CAP-12/13, RN-CAP-14);
// propuesta de la matriz por cargo desde el IPERC y el PCA (SCR-CAP-07); plantilla del certificado en la impresión (SCR-CAP-11);
// reporte de capacitación imprimible con permanencia y cumplimiento por área y cargo (SCR-CAP-14); aviso del fotocheck con la
// recomendación de su requisito (RN-FOT-12) y límite de la verificación del fotocheck (RN-FOT-06); aviso de brigadista sin
// capacitación elegida (RN-SIM-03).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $this->site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null order by is_main desc limit 1', [HUAYLAS_ID])->id;
});

function scCtx(): void
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
}

function scTraining(string $title, ?string $course = null): string
{
    scCtx();

    return DB::selectOne("insert into trainings (company_id, code, title, training_type, course_id) values (?, ?, ?, 'specific', ?) returning id",
        [HUAYLAS_ID, 'SC-'.substr(uniqid(), -6).random_int(10, 99), $title, $course])->id;
}

function scPosition(string $name, ?string $area = null): string
{
    scCtx();

    return DB::selectOne('insert into positions (company_id, code, name, area_id) values (?, ?, ?, ?) returning id', [HUAYLAS_ID, 'SCP-'.substr(uniqid(), -6).random_int(10, 99), $name, $area])->id;
}

it('la empresa fija el texto y el límite de su página pública de verificación y el límite rige en la consulta (SCR-CAP-13)', function () {
    $this->putJson('/api/v1/cap/verification', ['page_text' => 'Verifica aquí nuestros certificados.', 'rate_limit' => 2], $this->h)->assertOk()
        ->assertJsonPath('data.page_text', 'Verifica aquí nuestros certificados.')->assertJsonPath('data.rate_limit', 2);
    $this->putJson('/api/v1/cap/verification', ['rate_limit' => 0], $this->h)->assertStatus(422)->assertJsonValidationErrors('rate_limit');
    $v = $this->getJson('/api/v1/cap/verification', $this->h)->assertOk()->json('data');
    expect($v['page_url'])->toEndWith('/verificar/empresa/'.HUAYLAS_ID);

    // Página pública de la empresa (sin sesión): el texto solo se muestra con la verificación activa.
    $this->putJson('/api/v1/cap/verification', ['enabled' => false], $this->h)->assertOk();
    $ip = ['REMOTE_ADDR' => '10.77.'.random_int(1, 250).'.'.random_int(1, 250)];
    $this->withServerVariables($ip)->getJson('/api/v1/public/verify/company/'.HUAYLAS_ID)->assertOk()
        ->assertJsonPath('data.result', 'ok')->assertJsonPath('data.enabled', false)->assertJsonPath('data.text', '');
    $this->putJson('/api/v1/cap/verification', ['enabled' => true], $this->h)->assertOk();
    $ip = ['REMOTE_ADDR' => '10.78.'.random_int(1, 250).'.'.random_int(1, 250)];
    $this->withServerVariables($ip)->getJson('/api/v1/public/verify/company/'.HUAYLAS_ID)->assertOk()
        ->assertJsonPath('data.enabled', true)->assertJsonPath('data.text', 'Verifica aquí nuestros certificados.');
    $this->withServerVariables($ip)->getJson('/api/v1/public/verify/company/00000000-0000-0000-0000-000000000000')->assertOk()->assertJsonPath('data.result', 'invalid');

    // Desde la página de la empresa (?empresa=) rige su límite: 2 por minuto.
    $ip = ['REMOTE_ADDR' => '10.79.'.random_int(1, 250).'.'.random_int(1, 250)];
    foreach ([200, 200, 429] as $status) {
        $this->withServerVariables($ip)->getJson('/api/v1/public/verify/certificate/NOEXISTE'.random_int(10000000, 99999999).'?empresa='.HUAYLAS_ID)->assertStatus($status);
    }
    // Sin la página de la empresa rige el límite global (30), con su propio contador.
    $this->withServerVariables($ip)->getJson('/api/v1/public/verify/certificate/NOEXISTE'.random_int(10000000, 99999999))->assertOk();
});

it('la verificación pública del fotocheck usa el límite credential_verify_rate_limit (RN-FOT-06)', function () {
    // El fotocheck se verifica sin sesión ni empresa: rige el parámetro global.
    DB::connection('platform')->update("update legal_parameters set value = '2' where company_id is null and key = 'credential_verify_rate_limit'");
    try {
        $ip = ['REMOTE_ADDR' => '10.80.'.random_int(1, 250).'.'.random_int(1, 250)];
        foreach ([200, 200, 429] as $status) {
            $this->withServerVariables($ip)->getJson('/api/v1/public/verify/credential/ABCDEF'.random_int(10000000, 99999999))->assertStatus($status);
        }
    } finally {
        DB::connection('platform')->update("update legal_parameters set value = '30' where company_id is null and key = 'credential_verify_rate_limit'");
    }
});

it('propone la matriz por cargo desde el IPERC y el PCA, sin aplicarla, y acepta las elegidas con su origen (SCR-CAP-07)', function () {
    scCtx();
    $area = DB::selectOne('select id from areas where company_id = ? and deleted_at is null limit 1', [HUAYLAS_ID])->id;
    $soldador = scPosition('Soldador propuesta');
    $areaPos = scPosition('Operario del área propuesta', $area);
    $altura = scTraining('Trabajo en altura propuesta');
    $epp = scTraining('Uso de EPP propuesta');
    scCtx();
    $crit = DB::selectOne('select id from risk_criteria where company_id = ? or company_id is null order by company_id nulls last limit 1', [HUAYLAS_ID])->id;
    // IPERC vigente: una fila con puesto (control con capacitación) y una matriz de área (sus puestos).
    $m = DB::selectOne("insert into iperc_matrices (company_id, code, title, risk_criteria_id, area_id) values (?, ?, 'IPERC propuesta', ?, ?) returning id", [HUAYLAS_ID, 'IPC-SC-'.random_int(1000, 999999), $crit, $area])->id;
    $v = DB::selectOne("insert into iperc_versions (company_id, iperc_matrix_id, version_number, status, approved_at, effective_on) values (?, ?, 1, 'current', now(), current_date) returning id", [HUAYLAS_ID, $m])->id;
    DB::update('update iperc_matrices set current_version_id = ? where id = ?', [$v, $m]);
    $row = fn (?string $pos) => DB::selectOne("insert into iperc_rows (company_id, iperc_version_id, row_number, activity, task, hazard, risk, probability_value, severity_value, risk_level, position_id)
        values (?, ?, ?, 'Montaje', 'Soldar en altura', 'Altura', 'Caída', 3, 3, 'important', ?) returning id", [HUAYLAS_ID, $v, random_int(1, 999), $pos])->id;
    DB::insert("insert into iperc_controls (company_id, iperc_row_id, hierarchy, description, training_id) values (?, ?, 'administrative', 'Capacitar en trabajo en altura', ?)", [HUAYLAS_ID, $row($soldador), $altura]);
    DB::insert("insert into iperc_controls (company_id, iperc_row_id, hierarchy, description, training_id) values (?, ?, 'administrative', 'Capacitar en EPP', ?)", [HUAYLAS_ID, $row(null), $epp]);
    // PCA aprobado: tema con capacitación y puesto objetivo.
    $prog = DB::selectOne("insert into training_programs (company_id, year, title, status) values (?, ?, 'PCA propuesta', 'approved') returning id", [HUAYLAS_ID, (int) date('Y')])->id;
    DB::insert("insert into training_program_items (company_id, training_program_id, topic, training_type, status, training_id, target_positions, is_mandatory_legal)
                values (?, ?, 'Uso de EPP', 'specific', 'planned', ?, array[?]::uuid[], false)", [HUAYLAS_ID, $prog, $epp, $soldador]);

    $rows = collect($this->getJson('/api/v1/cap/matrix/proposals', $this->h)->assertOk()->json('data'));
    $find = fn ($pos, $tr, $src) => $rows->first(fn ($r) => $r['position_id'] === $pos && $r['training_id'] === $tr && $r['source'] === $src);
    expect($find($soldador, $altura, 'iperc'))->toMatchArray(['level' => 'mandatory', 'action' => 'create'])
        ->and($find($areaPos, $epp, 'iperc'))->not->toBeNull()
        ->and($find($soldador, $epp, 'pca'))->toMatchArray(['level' => 'suggested', 'action' => 'create']);
    // Nada se aplicó solo.
    scCtx();
    expect((int) DB::selectOne('select count(*) as n from position_training_requirements where position_id in (?, ?) and deleted_at is null', [$soldador, $areaPos])->n)->toBe(0);

    // Se aceptan dos; una propuesta que el IPERC/PCA no respalda no se crea.
    $r = $this->postJson('/api/v1/cap/matrix/proposals', ['items' => [
        ['position_id' => $soldador, 'training_id' => $altura, 'source' => 'iperc'],
        ['position_id' => $soldador, 'training_id' => $epp, 'source' => 'pca', 'requirement_level' => 'mandatory'],
        ['position_id' => $areaPos, 'training_id' => $altura, 'source' => 'pca'],
    ]], $this->h)->assertOk()->json('data');
    expect($r['saved'])->toBe(2)->and($r['errors'])->toHaveCount(1);
    scCtx();
    $saved = collect(DB::select('select training_id, source, requirement_level from position_training_requirements where position_id = ? and deleted_at is null', [$soldador]))->keyBy('training_id');
    expect($saved[$altura]->source)->toBe('iperc')->and($saved[$epp]->source)->toBe('pca')->and($saved[$epp]->requirement_level)->toBe('mandatory');
    expect($find($soldador, $altura, 'iperc'))->not->toBeNull();
    $again = collect($this->getJson('/api/v1/cap/matrix/proposals', $this->h)->json('data'))
        ->first(fn ($x) => $x['position_id'] === $soldador && $x['training_id'] === $altura);
    expect($again['action'])->toBe('exists');
});

it('el certificado impreso trae el diseño de su plantilla y la verificación desde la página de la empresa (SCR-CAP-11/12)', function () {
    scCtx();
    $tpl = DB::selectOne("insert into certificate_templates (company_id, code, name, orientation, body_template, signatory_name, signatory_title, shows_qr, shows_score)
                          values (?, ?, 'Plantilla vista previa', 'portrait', 'Se certifica que {{holder_name}} aprobó «{{course_title}}».', 'Jefe SST', 'Jefe de Seguridad', false, true) returning id",
        [HUAYLAS_ID, 'TPL-'.random_int(1000, 99999)])->id;
    $training = scTraining('Curso con plantilla');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '74747401', 'first_name' => 'Plantilla', 'paternal_last_name' => 'Impresa'], $this->h)->json('data.id');
    scCtx();
    $me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    $cert = DB::selectOne("insert into training_certificates (company_id, worker_id, training_id, certificate_template_id, certificate_code, verification_code, holder_name, course_title, issued_on, status, validated_by, validated_at)
                           values (?, ?, ?, ?, ?, ?, 'Plantilla Impresa', 'Curso con plantilla', current_date, 'validated', ?, now()) returning id",
        [HUAYLAS_ID, $w, $training, $tpl, sprintf('SC-%s-%06d', date('Y'), random_int(1, 999999)), strtoupper(bin2hex(random_bytes(8))), $me])->id;
    $p = $this->getJson("/api/v1/cap/certificates/{$cert}/print", $this->h)->assertOk()->json('data');
    expect($p['template'])->toMatchArray(['orientation' => 'portrait', 'signatory_name' => 'Jefe SST', 'shows_qr' => false, 'shows_score' => true])
        ->and($p['template']['body_template'])->toContain('{{holder_name}}')
        ->and($p['verify_url'])->toEndWith('?empresa='.HUAYLAS_ID);
});

it('el reporte de capacitación sale para imprimir con permanencia y cumplimiento por área y cargo, y queda en la bitácora (SCR-CAP-14)', function () {
    scCtx();
    $area = DB::selectOne('select id, name from areas where company_id = ? and deleted_at is null limit 1', [HUAYLAS_ID]);
    $pos = scPosition('Cargo del reporte', $area->id);
    $training = scTraining('Tema del reporte');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '74747402', 'first_name' => 'Reporte', 'paternal_last_name' => 'Anual'], $this->h)->json('data.id');
    scCtx();
    DB::update('update workers set current_position_id = ?, current_area_id = ? where id = ?', [$pos, $area->id, $w]);
    DB::insert("insert into position_training_requirements (company_id, position_id, training_id, requirement_level) values (?, ?, ?, 'mandatory')", [HUAYLAS_ID, $pos, $training]);
    $course = DB::selectOne("insert into courses (company_id, code, title, status, hours, published_at) values (?, ?, 'Curso permanencia', 'published', 1, now()) returning id", [HUAYLAS_ID, 'PR'.random_int(1000, 99999)])->id;
    DB::insert("insert into course_enrollments (company_id, course_id, worker_id, source, status, enrolled_at, completed_at, active_seconds_total, passed) values (?, ?, ?, 'manual', 'completed', now(), now(), 1800, true)", [HUAYLAS_ID, $course, $w]);

    $d = $this->getJson('/api/v1/cap/report?year='.date('Y'), $this->h)->assertOk()->json('data');
    $perm = collect($d['permanence'])->firstWhere('course', 'Curso permanencia');
    expect($perm)->toMatchArray(['enrolled' => 1, 'completed' => 1, 'passed' => 1])->and((float) $perm['avg_minutes'])->toBe(30.0);
    $byPos = collect($d['compliance']['by_position'])->firstWhere('name', 'Cargo del reporte');
    expect($byPos)->toMatchArray(['workers' => 1, 'required' => 1, 'missing' => 1, 'pct' => 0])
        ->and(collect($d['compliance']['by_area'])->pluck('name'))->toContain($area->name);
    $x = $this->get('/api/v1/cap/reports.xlsx?year='.date('Y'), $this->h)->assertOk();
    expect($x->headers->get('Content-Type'))->toContain('spreadsheetml');
    scCtx();
    expect(DB::selectOne("select action from audit_logs where changes->>'route' = 'cap/report' order by created_at desc limit 1")?->action)->toBe('print');
});

it('el aviso del fotocheck por vencer enlaza a la recomendación de recapacitación de su requisito, no a cualquiera (RN-FOT-12)', function () {
    scCtx();
    $course = DB::selectOne("insert into courses (company_id, code, title, status, hours, published_at) values (?, ?, 'Altura (recapacitación)', 'published', 1, now()) returning id", [HUAYLAS_ID, 'RF'.random_int(1000, 99999)])->id;
    $other = DB::selectOne("insert into courses (company_id, code, title, status, hours, published_at) values (?, ?, 'Otro curso', 'published', 1, now()) returning id", [HUAYLAS_ID, 'OT'.random_int(1000, 99999)])->id;
    $training = scTraining('Altura del fotocheck', $course);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '74747403', 'first_name' => 'Foto', 'paternal_last_name' => 'Check'], $this->h)->json('data.id');
    scCtx();
    $type = DB::selectOne("insert into credential_types (company_id, code, name) values (?, ?, 'Fotocheck recapacitación') returning id", [HUAYLAS_ID, 'FR'.random_int(1000, 99999)])->id;
    DB::insert("insert into credential_requirements (company_id, credential_type_id, requirement_type, training_id, description) values (?, ?, 'training', ?, 'Trabajo en altura')", [HUAYLAS_ID, $type, $training]);
    $cred = DB::selectOne("insert into credentials (company_id, credential_type_id, worker_id, credential_number, token_hash, status, valid_from, expires_on)
                           values (?, ?, ?, ?, decode(md5(random()::text), 'hex'), 'active', current_date - 300, current_date + 5) returning id", [HUAYLAS_ID, $type, $w, 'FR-'.random_int(100000, 999999)])->id;
    $me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    $rec = fn (string $c, string $reason, string $ago) => DB::selectOne("insert into course_recommendations (company_id, course_id, worker_id, source, recommended_by, target_scope, reason_code, message, channel, status, sent_at, dedupe_key, created_at)
        values (?, ?, ?, 'manual', ?, 'person', ?, 'Recomendado', 'in_app', 'sent', now(), ?, now() - ?::interval) returning id", [HUAYLAS_ID, $c, $w, $me, $reason, 'sc-'.uniqid(), $ago])->id;
    $right = $rec($course, 'certificate_expiring', '2 days');
    $rec($other, 'certificate_expiring', '1 hour');   // de otro curso, más reciente: no corresponde al requisito
    $rec($course, 'matrix_gap', '1 minute');          // del mismo curso, pero no es la recapacitación por vencer

    $check = collect(\App\Modules\NOT\SstAlerts::checks())->first(fn ($c) => $c[2] === 'fot.expiring');
    $row = collect(DB::select($check[1]))->firstWhere('id', $cred);
    expect($row->rec_id)->toBe($right)->and($row->name)->toBe('Altura (recapacitación)')
        ->and($row->link)->toBe('/m/aul?t=course_recommendations&open='.$right)->and($row->worker_link)->toBe('/mi/capacitaciones');

    // Sin la recomendación de su requisito, el aviso no menciona ninguna y enlaza al fotocheck.
    scCtx();
    DB::update('update course_recommendations set deleted_at = now() where id = ?', [$right]);
    $row = collect(DB::select($check[1]))->firstWhere('id', $cred);
    expect($row->rec_id)->toBeNull()->and($row->name)->toBe('')->and($row->link)->toBeNull();
});

it('avisa al brigadista sin capacitación elegida cuando venció la exigida por el tipo de brigada (RN-SIM-03)', function () {
    $b = $this->postJson('/api/v1/records/SIM/brigades', ['site_id' => $this->site, 'brigade_type' => 'first_aid', 'name' => 'Brigada del aviso', 'status' => 'inactive'], $this->h)
        ->assertCreated()->json('data.id');
    $worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '74747404', 'first_name' => 'Brigadista', 'paternal_last_name' => 'Por tipo'], $this->h)->json('data.id');
    $aid = scTraining('Primeros auxilios por tipo');
    scCtx();
    $code = DB::selectOne('select code from trainings where id = ?', [$aid])->code;
    \App\Core\Support\LegalParams::setForCompany('brigade_required_trainings', json_encode(['first_aid' => [$code]]));
    $me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    $cert = DB::selectOne("insert into training_certificates (company_id, worker_id, training_id, certificate_code, verification_code, holder_name, course_title, issued_on, expires_on, status, validated_by, validated_at)
                           values (?, ?, ?, ?, ?, 'Brigadista Por tipo', 'Primeros auxilios por tipo', current_date - 400, current_date + 30, 'validated', ?, now()) returning id",
        [HUAYLAS_ID, $worker, $aid, sprintf('SCB-%s-%06d', date('Y'), random_int(1, 999999)), strtoupper(bin2hex(random_bytes(8))), $me])->id;
    $m = $this->postJson('/api/v1/records/SIM/brigade_members', ['brigade_id' => $b, 'worker_id' => $worker, 'role' => 'member'], $this->h)->assertCreated()->json('data.id');

    $ids = fn () => collect(DB::select(collect(\App\Modules\NOT\SstAlerts::checks())->first(fn ($c) => $c[2] === 'sim.brigadist_training')[1]))->pluck('id')->all();
    scCtx();
    expect($ids())->not->toContain($m);
    // Vence la capacitación exigida por el tipo de brigada: el Jefe SST recibe el aviso.
    DB::update('update training_certificates set expires_on = current_date - 1 where id = ?', [$cert]);
    expect($ids())->toContain($m);
    // Con otra capacitación vigente de las exigidas para su tipo, ya no se avisa.
    $aid2 = scTraining('Primeros auxilios avanzado');
    scCtx();
    $code2 = DB::selectOne('select code from trainings where id = ?', [$aid2])->code;
    \App\Core\Support\LegalParams::setForCompany('brigade_required_trainings', json_encode(['first_aid' => [$code, $code2]]));
    DB::insert("insert into training_certificates (company_id, worker_id, training_id, certificate_code, verification_code, holder_name, course_title, issued_on, expires_on, status, validated_by, validated_at)
                values (?, ?, ?, ?, ?, 'Brigadista Por tipo', 'Avanzado', current_date, current_date + 300, 'validated', ?, now())",
        [HUAYLAS_ID, $worker, $aid2, sprintf('SCB-%s-%06d', date('Y'), random_int(1, 999999)), strtoupper(bin2hex(random_bytes(8))), $me]);
    expect($ids())->not->toContain($m);
});
