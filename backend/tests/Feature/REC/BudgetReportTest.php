<?php

// M-PRE §11: ejecución presupuestal imprimible (PDF desde el navegador) con los mismos datos del Excel; la descarga queda en la bitácora.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

function bepAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    bepAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'PRE' and deleted_at is null", [HUAYLAS_ID]));
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('arma el informe imprimible de la ejecución presupuestal por categoría, sede, actividad y línea', function () {
    $b = $this->postJson('/api/v1/records/PRE/budgets', ['year' => 2038, 'title' => 'Presupuesto SST 2038', 'currency' => 'PEN', 'total_approved' => 9000], $this->h)->assertCreated()->json('data.id');
    $l1 = $this->postJson('/api/v1/records/PRE/budget_lines', ['budget_id' => $b, 'category' => 'ppe', 'description' => 'Cascos', 'planned_amount' => 6000], $this->h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/PRE/budget_lines', ['budget_id' => $b, 'category' => 'training', 'description' => 'Cursos', 'planned_amount' => 3000], $this->h)->assertCreated();
    bepAs(fn () => DB::update('update budget_lines set executed_amount = 1500 where id = ?', [$l1]));

    $d = $this->getJson("/api/v1/reports/budget_execution?budget_id={$b}", $this->h)->assertOk()->json('data');
    expect($d['company']['ruc'])->not->toBeEmpty()->and($d['budget']['title'])->toBe('Presupuesto SST 2038')
        ->and((float) $d['planned'])->toBe(9000.0)->and((float) $d['executed'])->toBe(1500.0)->and((float) $d['pct'])->toBe(16.7);
    $ppe = collect($d['by_category'])->firstWhere('name', 'EPP');
    expect((float) $ppe['pct'])->toBe(25.0);
    expect(collect($d['lines'])->firstWhere('description', 'Cascos'))->toMatchArray(['category' => 'EPP', 'planned' => 6000, 'executed' => 1500, 'pct' => 25]);
    expect($d)->not->toHaveKey('transfers');

    // M-AUD RN-AUD-05: el imprimible (bajo /reports/) queda registrado como exportación.
    $n = bepAs(fn () => (int) DB::selectOne("select count(*) as n from audit_logs where company_id = ? and changes->>'route' = 'reports/budget_execution' and occurred_at > now() - interval '2 minutes'", [HUAYLAS_ID])->n);
    expect($n)->toBeGreaterThanOrEqual(1);
});
