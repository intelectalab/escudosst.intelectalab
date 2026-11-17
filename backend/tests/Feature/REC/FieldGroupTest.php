<?php

// Campo: M-INS (firmada, versiones de plantilla, tablero), M-ACT (QR que cambia, límite por QR, seguimiento, escalar a evento),
// M-ATS RN-ATS-03 (capacitación exigida), M-PTR RN-PTR-08 (cierre con dos firmas), M-EPP (capacitación al entregar, stock negativo,
// «para reponer», matriz y reposición) y M-SIM RN-SIM-02 (simulacros por sede en el diagnóstico).

use App\Modules\NOT\SstAlerts;
use Illuminate\Support\Facades\DB;

function fgAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
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
    fgAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('INS', 'ACT', 'ACC', 'ATS', 'PTR', 'EPP', 'SIM', 'SUN') and deleted_at is null", [HUAYLAS_ID]));
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->me = fgAs(fn () => DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id);
    $this->site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'FG-'.substr(uniqid(), -5), 'name' => 'Sede campo'], $this->h)->json('data.id');
});

it('la plantilla usada no cambia sus preguntas: se crea una versión nueva y al activarla la anterior se retira; la inspección firmada queda «firmada»', function () {
    $t = $this->postJson('/api/v1/records/INS/inspection_templates', ['code' => 'TPL-FG', 'name' => 'Orden y limpieza', 'inspection_type' => 'planned', 'version_number' => 1, 'status' => 'active'], $this->h)
        ->assertCreated()->json('data.id');
    $item = $this->postJson('/api/v1/records/INS/inspection_template_items', ['template_id' => $t, 'item_code' => '01', 'question' => '¿Pasillos libres?', 'answer_type' => 'yes_no_na', 'weight' => 1], $this->h)
        ->assertCreated()->json('data.id');
    $ins = $this->postJson('/api/v1/records/INS/inspections', ['title' => 'Inspección FG', 'inspection_type' => 'planned', 'template_id' => $t, 'site_id' => $this->site, 'scheduled_on' => now()->toDateString()], $this->h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/INS/inspection_template_items/{$item}", ['question' => 'Otra'], $this->h)->assertStatus(409);
    $v2 = $this->postJson("/api/v1/ins/templates/{$t}/new-version", [], $this->h)->assertCreated()->json('data');
    expect($v2['version_number'])->toBe(2);
    $this->patchJson("/api/v1/records/INS/inspection_templates/{$v2['id']}", ['status' => 'active'], $this->h)->assertOk();
    expect(fgAs(fn () => DB::selectOne('select status from inspection_templates where id = ?', [$t])->status))->toBe('retired');

    fgAs(fn () => DB::update("update inspections set status = 'completed', completed_at = now(), score_pct = 80 where id = ?", [$ins]));
    $this->patchJson("/api/v1/records/INS/inspections/{$ins}", ['status' => 'signed'], $this->h)->assertStatus(422);
    $req = fgAs(fn () => DB::selectOne("insert into signature_requests (company_id, subject_type, subject_id, document_hash, signer_user_id, role_in_document, sequence_order, status, signed_at)
                                         values (?, 'inspections', ?, decode('00', 'hex'), ?, 'approver', 1, 'signed', now()) returning id", [HUAYLAS_ID, $ins, $this->me])->id);
    fgAs(fn () => \App\Modules\NOT\FichaEvents::signed($req));
    expect(fgAs(fn () => DB::selectOne('select status from inspections where id = ?', [$ins])->status))->toBe('signed');
    $d = $this->getJson('/api/v1/ins/dashboard', $this->h)->assertOk()->json('data');
    expect($d['total']['done'])->toBeGreaterThan(0);
});

it('el QR del punto cambia y el anterior deja de servir; limita reportes por hora, da seguimiento y el reporte se escala a evento', function () {
    $p = $this->postJson('/api/v1/records/ACT/report_points', ['code' => 'QR-FG', 'name' => 'Portón', 'site_id' => $this->site, 'allow_anonymous' => true], $this->h)->assertCreated()->json('data.id');
    $old = $this->getJson("/api/v1/act/report-points/{$p}/link", $this->h)->json('data.url');
    $new = $this->postJson("/api/v1/act/report-points/{$p}/rotate", [], $this->h)->assertOk()->json('data.url');
    expect($new)->not->toBe($old);
    [$c, $tok] = array_slice(explode('/', $old), -2);
    $this->getJson("/api/v1/public/report/{$c}/{$tok}")->assertNotFound();
    [, $tok2] = array_slice(explode('/', $new), -2);
    $r = $this->postJson("/api/v1/public/report/{$c}/{$tok2}", ['report_type' => 'unsafe_condition', 'description' => 'Cable pelado junto al portón', 'is_anonymous' => true])->assertCreated()->json('data');
    [$tc, $code, $sig] = array_slice(explode('/', $r['track_url']), -3);
    $this->getJson("/api/v1/public/report-status/{$tc}/{$code}/{$sig}")->assertOk()->assertJsonPath('data.label', 'Recibido')->assertJsonMissingPath('data.description');
    $this->getJson("/api/v1/public/report-status/{$tc}/{$code}/0000000000000000")->assertNotFound();

    $id = fgAs(fn () => DB::selectOne('select id from substandard_reports where code = ?', [$code])->id);
    $ev = $this->postJson("/api/v1/act/reports/{$id}/escalate", ['event_type' => 'dangerous_incident'], $this->h)->assertCreated()->json('data.event_id');
    $rep = fgAs(fn () => DB::selectOne('select status, escalated_event_id from substandard_reports where id = ?', [$id]));
    expect($rep->status)->toBe('escalated')->and($rep->escalated_event_id)->toBe($ev);
    $this->postJson("/api/v1/act/reports/{$id}/escalate", ['event_type' => 'incident'], $this->h)->assertStatus(409);

    // RN-ACT-01: límite por QR (parámetro de la empresa: 1 por hora).
    fgAs(fn () => DB::insert("insert into legal_parameters (company_id, key, value, value_type, description, valid_from, to_confirm) values (?, 'public_report_rate_limit', '1', 'integer', 'prueba', '2000-01-01', false)", [HUAYLAS_ID]));
    $this->postJson("/api/v1/public/report/{$c}/{$tok2}", ['report_type' => 'unsafe_act', 'description' => 'Otra vez el cable pelado', 'is_anonymous' => true])->assertStatus(429);
});

it('el PETAR solo se cierra con la firma del solicitante y la del supervisor SST', function () {
    $type = fgAs(fn () => DB::selectOne("insert into permit_types (company_id, code, name, category, validity_hours, requires_ats, requires_gas_test, checklist, approval_chain)
                                          values (?, 'FG-PT', 'Altura FG', 'work_at_height', 8, false, false, '[]'::jsonb, '[]'::jsonb) returning id", [HUAYLAS_ID])->id);
    $other = DB::connection('platform')->selectOne('select id from users where id <> ? and deleted_at is null order by created_at limit 1', [$this->me])->id;
    $pm = fgAs(fn () => DB::selectOne("insert into work_permits (company_id, code, permit_type_id, site_id, work_description, valid_from, valid_until, status, requested_by, activated_at)
                                        values (?, 'PTR-FG-1', ?, ?, 'Cambio de luminarias', now() - interval '1 hour', now() + interval '4 hours', 'active', ?, now()) returning id", [HUAYLAS_ID, $type, $this->site, $other])->id);
    $close = ['status' => 'closed', 'area_left_safe' => true, 'closure_notes' => 'Área limpia'];
    $this->patchJson("/api/v1/records/PTR/work_permits/{$pm}", $close, $this->h)->assertStatus(422)->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'solicitó'));
    fgAs(fn () => DB::insert("insert into signatures (company_id, subject_type, subject_id, signer_user_id, signer_name, method, document_hash, evidence_hash, signed_at) values (?, 'work_permits', ?, ?, 'Solicitante', 'pin', 'x', 'x', now())", [HUAYLAS_ID, $pm, $other]));
    $this->patchJson("/api/v1/records/PTR/work_permits/{$pm}", $close, $this->h)->assertStatus(422)->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'supervisor SST'));
    fgAs(fn () => DB::insert("insert into signatures (company_id, subject_type, subject_id, signer_user_id, signer_name, method, document_hash, evidence_hash, signed_at) values (?, 'work_permits', ?, ?, 'Jefe SST', 'pin', 'x', 'x', now())", [HUAYLAS_ID, $pm, $this->me]));
    $this->patchJson("/api/v1/records/PTR/work_permits/{$pm}", $close, $this->h)->assertOk();
});

it('el EPP exige su capacitación, no entrega sin stock, queda «para reponer», arma la matriz y recibe solicitudes de reposición', function () {
    $tr = $this->postJson('/api/v1/records/CAP/trainings', ['title' => 'Uso de arnés FG', 'training_type' => 'specific'], $this->h)->assertCreated()->json('data.id');
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'ALT-FG', 'name' => 'Trabajador en altura FG'], $this->h)->json('data.id');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '74747474', 'first_name' => 'Pedro', 'paternal_last_name' => 'Arnés', 'current_position_id' => $pos, 'current_site_id' => $this->site], $this->h)
        ->assertCreated()->json('data.id');
    $item = $this->postJson('/api/v1/records/EPP/ppe_items', ['code' => 'ARN-FG', 'name' => 'Arnés FG', 'category' => 'fall_protection', 'requires_training' => true, 'training_id' => $tr, 'useful_life_days' => 365], $this->h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/EPP/ppe_position_requirements', ['position_id' => $pos, 'ppe_item_id' => $item, 'quantity' => 1, 'is_mandatory' => true], $this->h)->assertCreated();
    $this->postJson('/api/v1/records/EPP/ppe_deliveries', ['worker_id' => $w, 'ppe_item_id' => $item], $this->h)->assertStatus(422)->assertJsonValidationErrors('worker_id');
    fgAs(fn () => DB::insert("insert into training_certificates (company_id, worker_id, training_id, certificate_code, verification_code, holder_name, course_title, issued_on, status, validated_at, validated_by)
                              values (?, ?, ?, 'CFG-2026-000001', 'VFGTEST000001', 'Pedro', 'Uso de arnés', current_date, 'validated', now(), ?)", [HUAYLAS_ID, $w, $tr, $this->me]));
    $this->postJson('/api/v1/records/EPP/ppe_deliveries', ['worker_id' => $w, 'ppe_item_id' => $item], $this->h)->assertStatus(422)->assertJsonValidationErrors('quantity');
    $this->postJson('/api/v1/records/EPP/ppe_stock_movements', ['ppe_item_id' => $item, 'site_id' => $this->site, 'movement_type' => 'in', 'quantity' => 5], $this->h)->assertCreated();
    $d = $this->postJson('/api/v1/records/EPP/ppe_deliveries', ['worker_id' => $w, 'ppe_item_id' => $item], $this->h)->assertCreated()->assertJsonPath('data.status', 'pending_signature')->json('data.id');
    // RN-EPP-02: sin firma no cuenta; con la firma del trabajador queda entregada.
    expect(collect($this->getJson('/api/v1/epp/matrix', $this->h)->json('data.workers'))->firstWhere('worker_id', $w)['compliant'])->toBeFalse();
    fgAs(function () use ($d) {
        DB::insert("insert into signatures (company_id, subject_type, subject_id, signer_user_id, signer_name, method, document_hash, evidence_hash, signed_at) values (?, 'ppe_deliveries', ?, ?, 'Pedro', 'pin', 'x', 'x', now())", [HUAYLAS_ID, $d, $this->me]);
        $r = DB::selectOne("insert into signature_requests (company_id, subject_type, subject_id, document_hash, signer_user_id, role_in_document, sequence_order, status, signed_at)
                            values (?, 'ppe_deliveries', ?, decode('00', 'hex'), ?, 'worker', 1, 'signed', now()) returning id", [HUAYLAS_ID, $d, $this->me])->id;
        \App\Modules\NOT\FichaEvents::signed($r);
    });
    expect(fgAs(fn () => DB::selectOne('select status from ppe_deliveries where id = ?', [$d])->status))->toBe('delivered');
    $m = $this->getJson('/api/v1/epp/matrix', $this->h)->assertOk()->json('data');
    expect(collect($m['workers'])->firstWhere('worker_id', $w)['compliant'])->toBeTrue();
    fgAs(function () use ($d) {
        DB::update("update ppe_deliveries set expires_on = current_date - 1 where id = ?", [$d]);
        app(SstAlerts::class)->refreshStatuses(DB::connection());
    });
    expect(fgAs(fn () => DB::selectOne('select status from ppe_deliveries where id = ?', [$d])->status))->toBe('due_for_replacement');
    expect(collect($this->getJson('/api/v1/epp/matrix', $this->h)->json('data.workers'))->firstWhere('worker_id', $w)['compliant'])->toBeFalse();
    fgAs(fn () => DB::insert("insert into ppe_replacement_requests (company_id, worker_id, ppe_delivery_id, ppe_item_id, reason) values (?, ?, ?, ?, 'worn')", [HUAYLAS_ID, $w, $d, $item]));
    expect($this->getJson('/api/v1/epp/replacement-requests', $this->h)->json('data.0.status'))->toBe('pending');
    $this->postJson('/api/v1/records/EPP/ppe_deliveries', ['worker_id' => $w, 'ppe_item_id' => $item], $this->h)->assertCreated();
    expect(fgAs(fn () => DB::selectOne('select status from ppe_deliveries where id = ?', [$d])->status))->toBe('replaced')
        ->and(fgAs(fn () => DB::selectOne('select status from ppe_replacement_requests where worker_id = ?', [$w])->status))->toBe('attended');
});

it('el diagnóstico SUNAFIL muestra las sedes sin los simulacros del año', function () {
    $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '75757575', 'first_name' => 'Ana', 'paternal_last_name' => 'Sim', 'current_site_id' => $this->site], $this->h)->assertCreated();
    $area = collect($this->getJson('/api/v1/sun/diagnosis', $this->h)->assertOk()->json('data.areas'))->firstWhere('module', 'SIM');
    expect($area['detail'])->toContain('Sede campo');
});
