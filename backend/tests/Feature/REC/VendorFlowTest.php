<?php

// M-HOM RN-HOM-01/02/03 y flujo: pesos que suman 100, promedio ponderado, validación SST, aprobación solo por gerencia y vigencia.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('HOM', 'CTR') and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('calcula el puntaje ponderado, exige pesos que sumen 100 y deja la aprobación a gerencia', function () {
    $this->postJson('/api/v1/records/HOM/vendor_evaluation_templates', ['code' => 'T'.random_int(1000, 99999), 'name' => 'Mala', 'passing_score' => 70,
        'criteria' => [['name' => 'A', 'weight' => 50], ['name' => 'B', 'weight' => 30]]], $this->h)->assertStatus(422)->assertJsonValidationErrors('criteria');
    $tpl = $this->postJson('/api/v1/records/HOM/vendor_evaluation_templates', ['code' => 'T'.random_int(1000, 99999), 'name' => 'Plantilla RR', 'passing_score' => 70, 'validity_months' => 6,
        'criteria' => [['name' => 'Gestión SST', 'weight' => 70], ['name' => 'Accidentabilidad', 'weight' => 30]]], $this->h)->assertCreated()->json('data.id');
    $ev = $this->postJson('/api/v1/records/HOM/vendor_evaluations', ['template_id' => $tpl, 'vendor_name' => 'Proveedor RR', 'vendor_ruc' => '20100070970', 'evaluated_on' => '2031-01-10'], $this->h)->assertCreated()->json('data.id');
    // Puntajes de 0 a 5: 4,5/5 × 70 + 2/5 × 30 = 75 (promedio simple sería 65); aprueba (≥ 70) sin llegar a A → B.
    $r = $this->patchJson("/api/v1/records/HOM/vendor_evaluations/{$ev}", ['scores' => ['Gestión SST' => ['score' => 4.5], 'Accidentabilidad' => ['score' => 2]], 'status' => 'completed'], $this->h)
        ->assertOk()->json('data');
    expect((float) $r['total_score'])->toEqual(75)->and($r['rating'])->toBe('conditionally_approved');
    // El Jefe SST no aprueba la homologación: la aprueba gerencia.
    $this->patchJson("/api/v1/records/HOM/vendor_evaluations/{$ev}", ['status' => 'approved'], $this->h)->assertStatus(422)->assertJsonValidationErrors('status');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update vendor_evaluations set status = 'approved', valid_until = evaluated_on + interval '6 months' where id = ?", [$ev]);
    DB::select("select set_config('app.company_id', '', false)");
    $this->patchJson("/api/v1/records/HOM/vendor_evaluations/{$ev}", ['vendor_name' => 'Otro'], $this->h)->assertStatus(409);
});
