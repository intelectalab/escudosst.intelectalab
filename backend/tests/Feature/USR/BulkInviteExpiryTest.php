<?php

// M-USR: invitar a varios trabajadores a la vez y cierre automático de los accesos vencidos.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
});

it('invita a varios trabajadores con el correo de su ficha y dice por qué no invita a los demás', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $n = random_int(100000, 999999);
    $a = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Con', 'paternal_last_name' => 'Correo', 'personal_email' => "bulk{$n}@example.com"], $h)->assertCreated()->json('data.id');
    $b = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Sin', 'paternal_last_name' => 'Correo'], $h)->assertCreated()->json('data.id');
    $r = $this->postJson('/api/v1/company/users/bulk', ['worker_ids' => [$a, $b], 'role_code' => 'worker'], $h)->assertCreated();
    expect($r->json('invited'))->toBe(1)->and(collect($r->json('data'))->firstWhere('worker_id', $b)['reason'])->toBe('Sin correo en su ficha');
});

it('cierra los accesos vencidos y los deja en la bitácora', function () {
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [ESENCIAL_EMAIL])->id;
    $role = DB::selectOne("select id from roles where account_id is null and code = 'external_auditor'")->id;
    $m = DB::selectOne("insert into memberships (company_id, user_id, role_id, status, accepted_at, expires_at) values (?, ?, ?, 'active', now(), now() - interval '1 hour') returning id", [HUAYLAS_ID, $uid, $role])->id;
    DB::select("select set_config('app.company_id', '', false)");
    expect(app(\App\Modules\USR\Services\AccessExpiry::class)->run())->toBeGreaterThanOrEqual(1);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select status from memberships where id = ?', [$m])->status)->toBe('expired');
});
