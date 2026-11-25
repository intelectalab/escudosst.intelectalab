<?php

// Brechas de las fichas: aportes de CH5, SIM y EQE al expediente SUNAFIL; checklist por tipo y foto de la inspección por QR (M-EQE);
// capacitación de brigada por tipo de brigada (M-SIM RN-SIM-03); ranking de condiciones recurrentes (M-ACT §11);
// inspección programada vencida y su ubicación GPS en el informe (M-INS §9, SCR-INS-03).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $this->site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null order by is_main desc limit 1', [HUAYLAS_ID])->id;
});

function sgCtx(): void
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
}

/** Certificado validado y vigente de una capacitación para el trabajador. */
function sgCert(string $worker, string $training): void
{
    sgCtx();
    $me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    DB::insert("insert into training_certificates (company_id, worker_id, training_id, certificate_code, verification_code, holder_name, course_title, issued_on, expires_on, status, validated_by, validated_at)
                values (?, ?, ?, ?, ?, 'Brigadista', 'Brigada', current_date - 10, current_date + 300, 'validated', ?, now())",
        [HUAYLAS_ID, $worker, $training, sprintf('SG-%s-%06d', date('Y'), random_int(1, 999999)), strtoupper(bin2hex(random_bytes(8))), $me]);
}

it('el expediente SUNAFIL incluye charlas, simulacros, brigadas y equipos de emergencia, y por trabajador sus charlas y simulacros', function () {
    $worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '71717101', 'first_name' => 'Rosa', 'paternal_last_name' => 'Expediente'], $this->h)->assertCreated()->json('data.id');
    sgCtx();
    $talk = DB::selectOne("insert into safety_talks (company_id, topic, talk_on, site_id, status) values (?, 'Orden y limpieza', current_date, ?, 'held') returning id", [HUAYLAS_ID, $this->site])->id;
    DB::insert("insert into talk_attendances (company_id, safety_talk_id, worker_id, method) values (?, ?, ?, 'list')", [HUAYLAS_ID, $talk, $worker]);
    $drill = DB::selectOne("insert into drills (company_id, title, drill_type, site_id, executed_at, status) values (?, 'Sismo', 'earthquake', ?, now(), 'executed') returning id", [HUAYLAS_ID, $this->site])->id;
    DB::insert('insert into drill_participants (company_id, drill_id, worker_id) values (?, ?, ?)', [HUAYLAS_ID, $drill, $worker]);
    DB::insert("insert into emergency_equipment (company_id, code, equipment_type, site_id, next_recharge_on, status) values (?, ?, 'fire_extinguisher', ?, current_date - 5, 'operational')",
        [HUAYLAS_ID, 'EXT-SG-'.substr(uniqid(), -5), $this->site]);

    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente con emergencias', 'period_from' => date('Y').'-01-01', 'period_to' => date('Y').'-12-31'], $this->h)
        ->assertCreated()->json('data.id');
    $items = collect($this->postJson("/api/v1/sun/dossiers/{$d}/build", ['worker_ids' => [$worker]], $this->h)->assertOk()->json('data.items'));
    $by = fn (string $code) => $items->first(fn ($i) => $i['code'] === $code && $i['worker_id'] === null);

    expect($by('29')['module'])->toBe('CH5')
        ->and($by('29')['status'])->toBe('missing')   // la charla no tiene asistencia firmada
        ->and($by('29')['gap'])->toContain('sin asistencia firmada')
        ->and($by('30')['module'])->toBe('SIM')
        ->and($by('30')['table'])->toBe('drills')
        ->and($by('31')['table'])->toBe('brigades')
        ->and($by('32')['module'])->toBe('EQE')
        ->and($by('32')['status'])->toBeIn(['expired', 'missing'])   // recarga vencida (y quizá sedes bajo el mínimo)
        ->and($by('32')['gap'])->toContain('vencido');
    $w9 = $items->first(fn ($i) => $i['code'] === 'W9' && $i['worker_id'] === $worker);
    $w10 = $items->first(fn ($i) => $i['code'] === 'W10' && $i['worker_id'] === $worker);
    expect($w9['status'])->toBe('included')->and($w9['title'])->toContain('(1)')
        ->and($w10['status'])->toBe('included')->and($w10['module'])->toBe('SIM');
    sgCtx();
    expect((int) DB::selectOne("select count(*) as n from dossier_items where dossier_id = ? and section_code in ('29','30','31','32') and deleted_at is null", [$d])->n)->toBe(4);
});

it('la inspección por QR usa el checklist del tipo de equipo y guarda la foto', function () {
    $this->getJson('/api/v1/eqe/checklists', $this->h)->assertOk()
        ->assertJsonPath('data.types.fire_extinguisher', ['Presión', 'Precinto', 'Señalización', 'Acceso libre'])
        ->assertJsonPath('data.types.eyewash', ['Presión', 'Precinto', 'Señalización', 'Acceso libre']);
    $this->putJson('/api/v1/eqe/checklists', ['checklists' => ['nope' => ['x']]], $this->h)->assertStatus(422)->assertJsonValidationErrors('checklists');
    $r = $this->putJson('/api/v1/eqe/checklists', ['checklists' => ['eyewash' => ['Caudal', ' Agua limpia ', '']]], $this->h)->assertOk();
    expect($r->json('data.types.eyewash'))->toBe(['Caudal', 'Agua limpia'])
        ->and($r->json('data.types.alarm'))->toBe(['Presión', 'Precinto', 'Señalización', 'Acceso libre'])
        ->and($r->json('data.own'))->toBe(['eyewash'])
        ->and(\App\Modules\REC\EquipmentChecklistController::forType('eyewash'))->toBe(['Caudal', 'Agua limpia']);

    $eq = $this->postJson('/api/v1/records/EQE/emergency_equipment', ['code' => 'LAV-'.substr(uniqid(), -5), 'equipment_type' => 'eyewash', 'site_id' => $this->site], $this->h)->assertCreated()->json('data.id');
    $photo = $this->post('/api/v1/files', ['file' => \Illuminate\Http\UploadedFile::fake()->image('lavaojos.jpg'), 'module' => 'EQE'], $this->h + ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $c = $this->postJson('/api/v1/records/EQE/equipment_checks', ['equipment_id' => $eq, 'check_type' => 'monthly_inspection', 'checked_at' => now()->toIso8601String(), 'result' => 'ok',
        'checklist' => [['item' => 'Caudal', 'answer' => 'compliant']], 'photo_file_id' => $photo], $this->h)->assertCreated()->json('data.id');
    sgCtx();
    expect(DB::selectOne('select photo_file_id from equipment_checks where id = ?', [$c])->photo_file_id)->toBe($photo);
});

it('el brigadista sin capacitación elegida necesita la de brigada de su tipo (RN-SIM-03)', function () {
    $b = $this->postJson('/api/v1/records/SIM/brigades', ['site_id' => $this->site, 'brigade_type' => 'firefighting', 'name' => 'Brigada contra incendio', 'status' => 'inactive'], $this->h)
        ->assertCreated()->json('data.id');
    $worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '71717111', 'first_name' => 'Brigadista', 'paternal_last_name' => 'Sin curso'], $this->h)->json('data.id');
    // Sin ninguna capacitación de brigada vigente: no entra.
    $this->postJson('/api/v1/records/SIM/brigade_members', ['brigade_id' => $b, 'worker_id' => $worker, 'role' => 'member'], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('training_id');
    // Por defecto vale cualquier «Entrenamiento de simulacro» vigente.
    sgCtx();
    $drillTraining = DB::selectOne("insert into trainings (company_id, code, title, training_type) values (?, ?, 'Entrenamiento de brigadas', 'drill_training') returning id", [HUAYLAS_ID, 'SG-BRG-'.substr(uniqid(), -5)])->id;
    sgCert($worker, $drillTraining);
    expect(\App\Modules\REC\Rules\FieldOpsRules::brigadeTrainingValid($worker, 'firefighting', null))->toBeTrue();
    // Con el parámetro por tipo de brigada, se exige la capacitación indicada (por código).
    sgCtx();
    $fire = DB::selectOne("insert into trainings (company_id, code, title, training_type) values (?, ?, 'Uso de extintores', 'specific') returning id, code", [HUAYLAS_ID, 'SG-FIRE-'.substr(uniqid(), -5)]);
    \App\Core\Support\LegalParams::setForCompany('brigade_required_trainings', json_encode(['firefighting' => [$fire->code]]));
    $this->postJson('/api/v1/records/SIM/brigade_members', ['brigade_id' => $b, 'worker_id' => $worker, 'role' => 'member'], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('training_id');
    sgCert($worker, $fire->id);
    $this->postJson('/api/v1/records/SIM/brigade_members', ['brigade_id' => $b, 'worker_id' => $worker, 'role' => 'member'], $this->h)->assertCreated();
    // Otro tipo de brigada sin entrada en el parámetro (ni «*») vuelve a la regla por defecto.
    expect(\App\Modules\REC\Rules\FieldOpsRules::brigadeTrainingValid($worker, 'evacuation', null))->toBeTrue();
});

it('el ranking agrupa las condiciones recurrentes por tipo, área y lugar, en pantalla y en Excel', function () {
    sgCtx();
    $area = DB::selectOne('select id, name from areas where company_id = ? and deleted_at is null limit 1', [HUAYLAS_ID]);
    $ins = fn (string $class, string $place, string $status = 'triaged', string $type = 'unsafe_condition', int $daysAgo = 3) => DB::insert(
        "insert into substandard_reports (company_id, code, report_type, origin, site_id, area_id, description, location_text, classification, status, occurred_at, reported_at, closed_at)
         values (?, ?, ?, 'app', ?, ?, 'Condición', ?, ?, ?, now() - make_interval(days => ?), now() - make_interval(days => ?), case when ? = 'closed' then now() - make_interval(days => ?) else null end)",
        [HUAYLAS_ID, 'SG-'.substr(uniqid(), -6).random_int(10, 99), $type, $this->site, $area?->id, $place, $class, $status, $daysAgo, $daysAgo, $status, $daysAgo]);
    $ins('Piso resbaladizo', 'Pasillo de almacén', 'closed', 'unsafe_condition', 20);
    $ins('piso resbaladizo ', 'pasillo de almacén');
    $ins('Piso resbaladizo', 'Pasillo de almacén');
    $ins('Cable expuesto', 'Taller');
    $ins('Piso resbaladizo', 'Pasillo de almacén', 'triaged', 'unsafe_act');   // los actos no cuentan

    $from = now()->subDays(40)->toDateString();
    $d = $this->getJson("/api/v1/act/recurrent-conditions?from={$from}", $this->h)->assertOk()->json('data');
    $top = $d['groups'][0];
    expect($top['condition'])->toBe('Piso resbaladizo')
        ->and($top['count'])->toBe(3)
        ->and($top['recurrent'])->toBeTrue()
        ->and($top['after_close'])->toBe(2)
        ->and($top['open'])->toBe(2)
        ->and(collect($d['groups'])->firstWhere('condition', 'Cable expuesto')['recurrent'])->toBeFalse()
        ->and($d['by_condition'][0]['count'])->toBeGreaterThanOrEqual(3)
        ->and($d['min'])->toBe(2);
    $this->getJson('/api/v1/act/recurrent-conditions?from=2026-12-01&to=2026-01-01', $this->h)->assertStatus(422);
    $x = $this->get("/api/v1/act/recurrent-conditions.xlsx?from={$from}", $this->h)->assertOk();
    expect($x->headers->get('Content-Type'))->toContain('spreadsheetml');
});

it('la inspección programada sin ejecutar queda vencida y el informe trae su ubicación GPS', function () {
    sgCtx();
    $late = DB::selectOne("insert into inspections (company_id, code, title, inspection_type, site_id, scheduled_on, status) values (?, ?, 'Inspección atrasada', 'planned', ?, current_date - 2, 'scheduled') returning id",
        [HUAYLAS_ID, 'INS-SG-'.substr(uniqid(), -5), $this->site])->id;
    $today = DB::selectOne("insert into inspections (company_id, code, title, inspection_type, site_id, scheduled_on, status, latitude, longitude, location_accuracy_m)
                            values (?, ?, 'Inspección de hoy', 'planned', ?, current_date, 'scheduled', -12.046374, -77.042793, 8) returning id",
        [HUAYLAS_ID, 'INS-SG-'.substr(uniqid(), -5), $this->site])->id;
    app(\App\Modules\NOT\SstAlerts::class)->refreshStatuses(DB::connection());
    expect(DB::selectOne('select status from inspections where id = ?', [$late])->status)->toBe('overdue')
        ->and(DB::selectOne('select status from inspections where id = ?', [$today])->status)->toBe('scheduled');
    $check = collect(\App\Modules\NOT\SstAlerts::checks())->first(fn ($c) => $c[2] === 'ins.late');
    expect(collect(DB::select($check[1]))->pluck('id')->all())->toContain($late);

    $this->getJson("/api/v1/reports/inspections/{$today}", $this->h)->assertOk()
        ->assertJsonPath('data.inspection.latitude', -12.046374)
        ->assertJsonPath('data.inspection.longitude', -77.042793)
        ->assertJsonPath('data.inspection.location_accuracy_m', 8);
});
