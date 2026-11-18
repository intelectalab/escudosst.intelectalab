<?php

// M-AUI RN-AUI-01/02/04/06/07: registro MTPE al guardar, cierre con informe y bloqueo, justificación al descartar, checklist desde la
// plantilla, acceso temporal del auditor externo y calendario.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'AUI' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('aplica las reglas de auditoría y prepara el checklist y el acceso del auditor', function () {
    Mail::fake();
    $this->postJson('/api/v1/records/AUI/audits', ['audit_type' => 'legal_mandatory', 'standard' => 'ley_29783', 'starts_on' => '2031-05-01', 'ends_on' => '2031-05-03'], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('auditor_registration');
    $id = $this->postJson('/api/v1/records/AUI/audits', ['audit_type' => 'legal_mandatory', 'standard' => 'ley_29783', 'starts_on' => '2031-05-01', 'ends_on' => '2031-05-03',
        'auditor_registration' => 'REG-123', 'lead_auditor_name' => 'Ana Auditora'], $this->h)->assertCreated()->json('data.id');

    $n = $this->postJson("/api/v1/aui/audits/{$id}/checklist", [], $this->h)->assertOk()->json('data.added');
    expect($n)->toBeGreaterThan(0);
    expect($this->postJson("/api/v1/aui/audits/{$id}/checklist", [], $this->h)->json('data.added'))->toBe(0);

    $f = $this->postJson('/api/v1/records/AUI/audit_findings', ['audit_id' => $id, 'finding_type' => 'observation', 'description' => 'Señalización incompleta'], $this->h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/AUI/audit_findings/{$f}", ['status' => 'dismissed'], $this->h)->assertStatus(422)->assertJsonValidationErrors('dismiss_reason');
    $this->patchJson("/api/v1/records/AUI/audit_findings/{$f}", ['status' => 'dismissed', 'dismiss_reason' => 'Se corrigió en el acto'], $this->h)->assertOk();
    // §11: hallazgos por cláusula del año en Excel.
    expect($this->get('/api/v1/aui/findings.xlsx?year=2031', $this->h)->assertOk()->headers->get('Content-Type'))->toContain('spreadsheetml');

    $acc = $this->postJson("/api/v1/aui/audits/{$id}/auditor-access", ['email' => 'auditor'.random_int(1000, 999999).'@ejemplo.test', 'full_name' => 'Auditor Externo'], $this->h)
        ->assertCreated()->json('data');
    expect($acc['expires_on'])->toBe('2031-05-03');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select external_auditor_membership_id from audits where id = ?', [$id])->external_auditor_membership_id)->toBe($acc['membership_id']);
    DB::select("select set_config('app.company_id', '', false)");

    foreach (['in_progress', 'completed'] as $st) {
        $this->patchJson("/api/v1/records/AUI/audits/{$id}", ['status' => $st, 'conclusions' => 'Cumple en lo sustancial.'], $this->h)->assertOk();
    }
    $this->patchJson("/api/v1/records/AUI/audits/{$id}", ['status' => 'closed'], $this->h)->assertStatus(422)->assertJsonValidationErrors('report_file_id');

    $cal = $this->getJson('/api/v1/aui/calendar?year=2031', $this->h)->assertOk()->json('data');
    expect(collect($cal['audits'])->pluck('id'))->toContain($id);
});
