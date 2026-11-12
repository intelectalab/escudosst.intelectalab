<?php

// Reglas de estructura (TEN), trabajadores (TRB), SCTR (SCT) y homologación de proveedores (HOM) según sus fichas.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('SCT', 'HOM', 'CAP') and deleted_at is null", [HUAYLAS_ID]);
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('limita los niveles del árbol de áreas y deja en solo lectura la sede temporal terminada', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'TEN-D', 'name' => 'Sede niveles'], $this->h)->assertCreated()->json('data.id');
    $parent = null;
    foreach (range(1, 5) as $i) {
        $parent = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => "NIV-{$i}", 'name' => "Nivel {$i}", 'parent_area_id' => $parent], $this->h)
            ->assertCreated()->json('data.id');
    }
    // RN-TEN-08: el sexto nivel pasa el máximo (max_area_depth = 5).
    $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'NIV-6', 'name' => 'Nivel 6', 'parent_area_id' => $parent], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('parent_area_id');

    // RN-TEN-07: la obra terminada no se edita (solo se amplía su fin) y no aparece para registros nuevos.
    $obra = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'OBRA-T', 'name' => 'Obra terminada', 'is_temporary' => true,
        'starts_on' => now()->subMonths(3)->toDateString(), 'ends_on' => now()->subDay()->toDateString()], $this->h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/TEN/sites/{$obra}", ['name' => 'Otro nombre'], $this->h)->assertStatus(409);
    $opts = collect($this->getJson('/api/v1/records/TEN/areas/options/site_id?q=Obra terminada', $this->h)->assertOk()->json('data'))->pluck('id');
    expect($opts)->not->toContain($obra);
    $this->patchJson("/api/v1/records/TEN/sites/{$obra}", ['ends_on' => now()->addMonth()->toDateString()], $this->h)->assertOk();
});

it('no ofrece trabajadores cesados en registros nuevos', function () {
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '64646464', 'first_name' => 'Cesado', 'paternal_last_name' => 'Ejemplo',
        'hire_on' => now()->subYear()->toDateString()], $this->h)->assertCreated()->json('data.id');
    $find = fn () => collect($this->getJson('/api/v1/records/CAP/training_attendances/options/worker_id?q=Cesado', $this->h)->assertOk()->json('data'))->pluck('id');
    expect($find())->toContain($w);
    $this->patchJson("/api/v1/records/TRB/workers/{$w}", ['status' => 'terminated', 'termination_on' => now()->toDateString(), 'termination_reason' => 'Renuncia'], $this->h)->assertOk();
    expect($find())->not->toContain($w);
});

it('no duplica la cobertura SCTR del mismo tipo y periodo', function () {
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '65656565', 'first_name' => 'Sara', 'paternal_last_name' => 'Vela'], $this->h)->json('data.id');
    $p = $this->postJson('/api/v1/records/SCT/sctr_policies', ['coverage_kind' => 'health', 'insurer_type' => 'essalud', 'insurer_name' => 'EsSalud', 'policy_number' => 'P-DUP',
        'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addYear()->toDateString()], $this->h)->assertCreated()->json('data.id');
    $base = ['sctr_policy_id' => $p, 'worker_id' => $w, 'covered_from' => now()->subMonth()->toDateString(), 'covered_until' => now()->addMonths(6)->toDateString()];
    $r = $this->postJson('/api/v1/records/SCT/sctr_coverages', $base, $this->h);
    expect($r->status())->toBe(201, (string) json_encode($r->json()));
    $this->postJson('/api/v1/records/SCT/sctr_coverages', $base, $this->h)->assertStatus(422)->assertJsonValidationErrors('worker_id');
});

it('la homologación aprobada vence según su vigencia y ya no se edita', function () {
    $t = $this->postJson('/api/v1/records/HOM/vendor_evaluation_templates', ['code' => 'HOM-V', 'name' => 'Proveedores generales', 'passing_score' => 60], $this->h)->assertCreated()->json('data.id');
    $this->putJson("/api/v1/hom/templates/{$t}/criteria", ['criteria' => [['name' => 'SST', 'weight' => 100]]], $this->h)->assertOk();
    $e = $this->postJson('/api/v1/records/HOM/vendor_evaluations', ['template_id' => $t, 'vendor_name' => 'Grúas del Norte', 'evaluated_on' => '2026-01-15'], $this->h)
        ->assertCreated()->json('data.id');
    $this->putJson("/api/v1/hom/evaluations/{$e}/scores", ['scores' => ['SST' => 4]], $this->h)->assertOk();
    // RN-HOM-03: vence a los 12 meses de la evaluación.
    $this->patchJson("/api/v1/records/HOM/vendor_evaluations/{$e}", ['status' => 'approved'], $this->h)->assertOk()->assertJsonPath('data.valid_until', '2027-01-15');
    // RN-HOM-04: aprobada no se edita.
    $this->patchJson("/api/v1/records/HOM/vendor_evaluations/{$e}", ['vendor_name' => 'Otro'], $this->h)->assertStatus(409);
    // RN-HOM-05: pasada su vigencia queda vencida.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update vendor_evaluations set valid_until = current_date - 1 where id = ?', [$e]);
    app(\App\Modules\NOT\SstAlerts::class)->refreshStatuses(DB::connection());
    expect(DB::selectOne('select status from vendor_evaluations where id = ?', [$e])->status)->toBe('expired');
});
