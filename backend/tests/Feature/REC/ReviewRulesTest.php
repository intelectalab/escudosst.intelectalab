<?php

// M-RVD RN-RVD-02/03/05: decisión con responsable → acción; cierre con firma de gerencia; cerrada no cambia; entradas con periodo.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('RVD', 'ACR') and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('crea la acción de cada decisión con responsable, toma la foto del periodo y exige la firma de gerencia para cerrar', function () {
    $u = '01900000-0000-7000-8000-000000000303';
    $rev = $this->postJson('/api/v1/records/RVD/management_reviews', ['year' => 2031, 'title' => 'Revisión 2031', 'review_on' => '2031-12-10', 'period_start' => '2031-01-01', 'period_end' => '2031-11-30'], $this->h)
        ->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/RVD/management_reviews/{$rev}", ['status' => 'in_progress'], $this->h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $inputs = DB::selectOne('select inputs from management_reviews where id = ?', [$rev])->inputs;
    DB::select("select set_config('app.company_id', '', false)");
    $in = json_decode($inputs, true);
    $in = is_string($in) ? json_decode($in, true) : $in;
    expect($in['periodo']['desde'])->toBe('2031-01-01')->and($in)->toHaveKeys(['objetivos', 'cambios_legales']);

    $out = $this->postJson('/api/v1/records/RVD/management_review_outputs', ['review_id' => $rev, 'output_type' => 'resource', 'description' => 'Comprar detectores de gas',
        'responsible_user_id' => $u, 'due_on' => '2032-02-01'], $this->h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $o = DB::selectOne('select corrective_action_id, status from management_review_outputs where id = ?', [$out]);
    DB::select("select set_config('app.company_id', '', false)");
    expect($o->corrective_action_id)->not->toBeNull()->and($o->status)->toBe('in_progress');

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update management_review_outputs set status = 'done' where id = ?", [$out]);
    DB::select("select set_config('app.company_id', '', false)");
    $this->patchJson("/api/v1/records/RVD/management_reviews/{$rev}", ['status' => 'completed', 'conclusions' => 'El sistema es eficaz.', 'participants' => ['Gerente general']], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/RVD/management_reviews/{$rev}", ['status' => 'closed'], $this->h)->assertStatus(422)->assertJsonValidationErrors('status');

    $s = $this->getJson('/api/v1/rvd/summary', $this->h)->assertOk()->json('data');
    expect($s['outputs']['with_action'])->toBeGreaterThanOrEqual(1);
});
