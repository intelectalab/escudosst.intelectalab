<?php

// M-SCT SCR-SCT-02 / RN-SCT-04: conciliación de la lista de asegurados con los trabajadores activos y registro de la cobertura.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'SCT' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

function sctAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

it('concilia la lista de asegurados, detecta faltantes y registra la cobertura sin duplicar', function () {
    $pos = sctAs(fn () => DB::selectOne("insert into positions (company_id, code, name, requires_sctr) values (?, ?, 'Operario SCTR', true) returning id", [HUAYLAS_ID, 'SCT-'.random_int(1000, 999999)])->id);
    $mk = function (string $first) use ($pos) {
        $dni = (string) random_int(79000000, 79999999);
        $id = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => $first, 'paternal_last_name' => 'Sctr', 'current_position_id' => $pos], $this->h)
            ->assertCreated()->json('data.id');
        sctAs(fn () => DB::update('update workers set current_position_id = ? where id = ?', [$pos, $id]));

        return [$id, $dni];
    };
    [$a, $dniA] = $mk('Asegurado');
    [$b] = $mk('Faltante');
    [$c, $dniC] = $mk('Cesado');
    sctAs(fn () => DB::update("update workers set status = 'terminated', termination_on = current_date - 1 where id = ?", [$c]));
    $pol = sctAs(fn () => DB::selectOne("insert into sctr_policies (company_id, coverage_kind, insurer_type, insurer_name, policy_number, starts_on, ends_on, status)
        values (?, 'health', 'eps', 'EPS Prueba', ?, current_date - 30, current_date + 300, 'active') returning id", [HUAYLAS_ID, 'POL-'.random_int(1000, 999999)])->id);
    $q = ['sctr_policy_id' => $pol, 'from' => date('Y-m-01'), 'to' => date('Y-m-t'), 'documents' => "{$dniA}\n{$dniC}\n12345678"];

    $r = $this->postJson('/api/v1/sct/reconcile', $q, $this->h)->assertOk()->json('data');
    expect($r['listed'])->toBe(3)
        ->and(collect($r['matched'])->pluck('full_name')->implode(' '))->toContain('Asegurado')
        ->and(collect($r['inactive'])->pluck('full_name')->implode(' '))->toContain('Cesado')
        ->and($r['unknown'])->toHaveCount(1)
        ->and(collect($r['uncovered'])->pluck('full_name')->implode(' '))->toContain('Faltante')->not->toContain('Asegurado');

    $ok = $this->postJson('/api/v1/sct/reconcile', $q + ['apply' => 1], $this->h)->assertOk()->json('data');
    expect($ok['created'])->toBe(1);
    expect(sctAs(fn () => DB::selectOne("select count(*) as n from sctr_coverages where worker_id = ? and sctr_policy_id = ? and status = 'covered'", [$a, $pol])->n))->toBe(1);
    // RN-SCT-08: volver a aplicar no duplica.
    expect($this->postJson('/api/v1/sct/reconcile', $q + ['apply' => 1], $this->h)->json('data.created'))->toBe(0);

    $this->postJson('/api/v1/sct/reconcile', ['sctr_policy_id' => $pol, 'from' => date('Y-m-01'), 'to' => date('Y-m-t')], $this->h)->assertStatus(422);
    $this->postJson('/api/v1/sct/reconcile', array_merge($q, ['from' => '2001-01-01', 'to' => '2001-01-31']), $this->h)->assertStatus(422);
});
