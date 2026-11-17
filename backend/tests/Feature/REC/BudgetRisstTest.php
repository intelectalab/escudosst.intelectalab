<?php

// M-PRE RN-PRE-02/05 y flujo (envío a gerencia, aprobación solo por gerencia, reasignación auditada, tablero de ejecución);
// M-RIS RN-RIS-02/06/08 (secciones del art. 74, plazo de gracia, entrega a contratistas); M-COM RN-COM-07 (mandato vencido).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

function brAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

function brRole(string $role): array
{
    brAs(function () use ($role) {
        $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
        DB::update("update memberships set role_id = (select id from roles where code = ? and account_id is null) where user_id = ? and company_id = ?", [$role, $uid, HUAYLAS_ID]);
    });
    $GLOBALS['sus_tokens'] = [];
    app('auth')->forgetGuards();
    Permissions::flush();

    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

afterEach(function () {
    // Algunas pruebas cambian el rol del usuario: los permisos guardados en memoria no deben pasar a la siguiente.
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $GLOBALS['sus_tokens'] = [];
});

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    brAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('PRE', 'RIS', 'COM', 'CTR') and deleted_at is null", [HUAYLAS_ID]));
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('exige que las líneas sumen el total, deja la aprobación a gerencia y reasigna entre líneas con auditoría', function () {
    $b = $this->postJson('/api/v1/records/PRE/budgets', ['year' => 2039, 'title' => 'Presupuesto SST 2039', 'currency' => 'PEN', 'total_approved' => 10000], $this->h)->assertCreated()->json('data.id');
    $l1 = $this->postJson('/api/v1/records/PRE/budget_lines', ['budget_id' => $b, 'category' => 'ppe', 'description' => 'Cascos y guantes', 'planned_amount' => 6000], $this->h)->assertCreated()->json('data.id');
    $l2 = $this->postJson('/api/v1/records/PRE/budget_lines', ['budget_id' => $b, 'category' => 'training', 'description' => 'Cursos', 'planned_amount' => 3000], $this->h)->assertCreated()->json('data.id');
    // 6000 + 3000 ≠ 10000: no se envía.
    $this->patchJson("/api/v1/records/PRE/budgets/{$b}", ['status' => 'in_review'], $this->h)->assertStatus(422)->assertJsonValidationErrors('total_approved');
    // Sin pasar por «enviado» no se aprueba.
    $this->patchJson("/api/v1/records/PRE/budget_lines/{$l2}", ['planned_amount' => 4000], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/PRE/budgets/{$b}", ['status' => 'approved'], $this->h)->assertStatus(422);
    $this->patchJson("/api/v1/records/PRE/budgets/{$b}", ['status' => 'in_review'], $this->h)->assertOk();
    // El Jefe SST no aprueba.
    $this->patchJson("/api/v1/records/PRE/budgets/{$b}", ['status' => 'approved'], $this->h)->assertStatus(422)->assertJsonValidationErrors('status');

    $mg = brRole('management');
    $this->patchJson("/api/v1/records/PRE/budgets/{$b}", ['status' => 'approved'], $mg)->assertOk()->assertJsonPath('data.status', 'approved');
    $this->patchJson("/api/v1/records/PRE/budgets/{$b}", ['title' => 'Otro'], $mg)->assertStatus(409);

    // Reasignación: se pide, gerencia la aprueba, las líneas cambian y el total no.
    $this->postJson('/api/v1/pre/transfers', ['budget_id' => $b, 'from_line_id' => $l1, 'to_line_id' => $l2, 'amount' => 7000, 'reason' => 'Más cursos'], $mg)->assertStatus(422);
    $t = $this->postJson('/api/v1/pre/transfers', ['budget_id' => $b, 'from_line_id' => $l1, 'to_line_id' => $l2, 'amount' => 1500, 'reason' => 'Más cursos de altura'], $mg)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/pre/transfers/{$t}/decide", ['decision' => 'approved'], $mg)->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/pre/transfers/{$t}/decide", ['decision' => 'approved'], $mg)->assertStatus(409);
    $lines = brAs(fn () => DB::select('select id, planned_amount from budget_lines where budget_id = ?', [$b]));
    expect((float) collect($lines)->firstWhere('id', $l1)->planned_amount)->toEqual(4500)
        ->and((float) collect($lines)->firstWhere('id', $l2)->planned_amount)->toEqual(5500)
        ->and(brAs(fn () => DB::selectOne("select count(*) as n from audit_logs where entity_id = ? and action = 'budget_transfer_approved'", [$t])->n))->toBe(1);

    $x = $this->getJson("/api/v1/pre/execution?budget_id={$b}", $mg)->assertOk()->json('data');
    expect($x['planned'])->toEqual(10000)->and(collect($x['by_category'])->pluck('name')->all())->toContain('EPP', 'Capacitación')->and($x['transfers'])->toHaveCount(1);
    $this->get("/api/v1/pre/execution.xlsx?budget_id={$b}", $mg)->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('no envía el RISST sin las secciones del art. 74 y muestra entregados, en plazo y fuera de plazo', function () {
    $v = $this->postJson('/api/v1/records/RIS/risst_versions', ['title' => 'RISST 2039', 'content_html' => '<p>Reglamento</p>'], $this->h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/RIS/risst_versions/{$v}", ['content_outline' => ['Objetivos y alcance', 'Liderazgo'], 'status' => 'in_review'], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('content_outline');
    $all = $this->getJson('/api/v1/ris/overview', $this->h)->assertOk()->json('data.sections');
    expect($all)->toContain('Preparación y respuesta a emergencias');
    $this->patchJson("/api/v1/records/RIS/risst_versions/{$v}", ['content_outline' => $all, 'status' => 'in_review'], $this->h)->assertOk();

    // Con un RISST vigente: un trabajador antiguo sin cargo está fuera de plazo; uno recién ingresado, en plazo.
    brAs(function () use ($v) {
        DB::update("update risst_versions set status = 'obsolete' where status = 'current' and id <> ?", [$v]);
        DB::update("update risst_versions set status = 'current', effective_on = current_date where id = ?", [$v]);
    });
    $workers = brAs(fn () => [
        DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status, hire_on)
                       values (?, ?, decode(md5(random()::text), 'hex'), 'Antiguo', 'Sincargo', 'active', current_date - 400) returning id", [HUAYLAS_ID, (string) random_int(10000000, 99999999)]),
        DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status, hire_on)
                       values (?, ?, decode(md5(random()::text), 'hex'), 'Nuevo', 'Enplazo', 'active', current_date - 2) returning id", [HUAYLAS_ID, (string) random_int(10000000, 99999999)]),
    ]);
    $this->postJson('/api/v1/ris/deliver-all', [], $this->h)->assertOk();
    $o = $this->getJson('/api/v1/ris/overview', $this->h)->assertOk()->json('data');
    $state = collect($o['workers'])->pluck('state', 'id');
    expect($state[$workers[0]->id])->toBe('late')->and($state[$workers[1]->id])->toBe('in_grace')->and($o['stats']['late'])->toBeGreaterThan(0);
    // La entrega física sin cargo escaneado se rechaza; con firma en la app queda entregada.
    $this->postJson('/api/v1/records/RIS/risst_deliveries', ['risst_version_id' => $v, 'worker_id' => $workers[0]->id, 'method' => 'physical_signed'], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('acknowledgement_file_id');
    $this->postJson('/api/v1/records/RIS/risst_deliveries', ['risst_version_id' => $v, 'worker_id' => $workers[0]->id, 'method' => 'in_person'], $this->h)->assertCreated();
    $o = $this->getJson('/api/v1/ris/overview', $this->h)->json('data');
    expect(collect($o['workers'])->firstWhere('id', $workers[0]->id)['state'])->toBe('delivered');
    $this->get('/api/v1/ris/deliveries.xlsx', $this->h)->assertOk();
});

it('el comité con mandato vencido queda finalizado en la revisión diaria', function () {
    $id = brAs(fn () => DB::selectOne("insert into committees (company_id, committee_type, name, period_start_on, period_end_on, status)
                                        values (?, 'committee', 'Comité 2022-2024', '2022-01-01', '2024-01-01', 'active') returning id", [HUAYLAS_ID])->id);
    brAs(fn () => app(\App\Modules\NOT\SstAlerts::class)->refreshStatuses(DB::connection()));
    expect(brAs(fn () => DB::selectOne('select status from committees where id = ?', [$id])->status))->toBe('ended');
});
