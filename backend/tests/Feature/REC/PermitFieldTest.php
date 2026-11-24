<?php

// PETAR: medición de gases antes de aprobar (RN-PTR-03, criterio 3) y suspensión en campo por el ejecutor o vigía (SCR-PTR-05, RN-PTR-06, criterio 4).

use App\Core\Auth\Permissions;
use App\Modules\TRB\WorkerRequirements;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    $this->other = DB::connection('platform')->selectOne('select id from users where id <> ? and deleted_at is null order by created_at limit 1', [$this->me])->id;
    $this->site = pfAs(fn () => DB::selectOne('select id from sites where company_id = ? and deleted_at is null order by is_main desc limit 1', [HUAYLAS_ID])->id);
});

afterEach(function () {
    app('auth')->forgetGuards();
    Permissions::flush();
});

function pfAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

/** Permiso del tipo dado (con o sin gases) en el estado indicado, solicitado por otra persona, con su líder y cuadrilla. */
function pfPermit($test, bool $gas, string $status, string $leader, array $crew = []): string
{
    return pfAs(function () use ($test, $gas, $status, $leader, $crew) {
        $type = DB::selectOne("insert into permit_types (company_id, code, name, category, validity_hours, requires_ats, requires_gas_test, checklist, approval_chain)
                               values (?, ?, 'Espacio confinado PF', 'confined_space', 8, false, ?, '[]'::jsonb, '[]'::jsonb) returning id",
            [HUAYLAS_ID, 'PF-'.substr(uniqid(), -6), $gas ? 'true' : 'false'])->id;
        $id = DB::selectOne("insert into work_permits (company_id, code, permit_type_id, site_id, work_description, valid_from, valid_until, status, requested_by,
                                 executor_leader_worker_id, crew, activated_at)
                             values (?, ?, ?, ?, 'Limpieza de tanque', now() - interval '1 minute', now() + interval '6 hours', ?, ?, ?, ?::jsonb, ?) returning id",
            [HUAYLAS_ID, 'PTR-PF-'.substr(uniqid(), -6), $type, $test->site, $status, $test->other, $leader,
                json_encode(array_merge([['worker_id' => $leader, 'role' => 'leader']], $crew)), $status === 'active' ? now()->toIso8601String() : null])->id;
        DB::insert("insert into permit_approvals (company_id, work_permit_id, step_order, approver_role, approver_user_id, decision, decided_at)
                    values (?, ?, 1, 'sst_supervisor', ?, 'approved', now())", [HUAYLAS_ID, $id, $test->me]);

        return $id;
    });
}

function pfWorker($test, string $dni, ?string $userId = null): string
{
    $id = $test->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => 'Campo', 'paternal_last_name' => 'Prueba'], $test->h)->assertCreated()->json('data.id');
    if ($userId) {
        pfAs(fn () => DB::update('update workers set user_id = ? where id = ?', [$userId, $id]));
    }

    return $id;
}

function pfRole(string $uid, string $code): void
{
    pfAs(fn () => DB::update('update memberships set role_id = (select id from roles where account_id is null and code = ?) where user_id = ? and company_id = ?', [$code, $uid, HUAYLAS_ID]));
    app('auth')->forgetGuards();
    Permissions::flush();
}

it('un permiso que exige gases no se aprueba sin medición ni con valores fuera de rango, y sí con la medición en rango', function () {
    // Los requisitos SST del ejecutante se prueban aparte: aquí se dan por cumplidos.
    app()->instance(WorkerRequirements::class, new class extends WorkerRequirements
    {
        public function __construct() {}

        public function evaluate(array $ids): array
        {
            return [];
        }
    });
    $id = pfPermit($this, true, 'pending_approval', pfWorker($this, '63636301'));

    $ctx = $this->getJson("/api/v1/ptr/work-permits/{$id}/context", $this->h)->assertOk();
    expect($ctx->json('data.requires_gas_test'))->toBeTrue()->and(collect($ctx->json('data.gas_limits'))->pluck('code')->all())->toBe(['o2', 'lel', 'co', 'h2s']);
    expect(collect($this->getJson('/api/v1/ptr/gas-limits', $this->h)->assertOk()->json('data'))->firstWhere('code', 'o2'))->toMatchArray(['min' => 19.5, 'max' => 22.5]);

    $this->patchJson("/api/v1/records/PTR/work_permits/{$id}", ['status' => 'approved'], $this->h)->assertStatus(422)->assertJsonValidationErrors('gas_measurements');
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id}", ['status' => 'approved', 'gas_measurements' => ['o2' => 20.9, 'lel' => 12, 'co' => 3, 'h2s' => 0]], $this->h)
        ->assertStatus(422)->assertJsonPath('errors.gas_measurements.0', fn ($m) => str_contains($m, 'LEL'));
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id}", ['status' => 'approved', 'gas_measurements' => ['o2' => 20.9, 'lel' => 0, 'co' => 3, 'h2s' => 0]], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.gas_measurements.o2', 20.9);

    // La medición también se registra antes, desde la ficha, y entonces la aprobación pasa sin repetirla.
    $id2 = pfPermit($this, true, 'pending_approval', pfWorker($this, '63636302'));
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id2}", ['gas_measurements' => ['o2' => 20.5, 'lel' => 1, 'co' => 0, 'h2s' => 0]], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id2}", ['status' => 'approved'], $this->h)->assertOk()->assertJsonPath('data.status', 'approved');
});

it('el ejecutor o vigía con rol trabajador suspende en campo un permiso activo con motivo y se avisa', function () {
    $watcher = pfWorker($this, '63636311', $this->me);
    $id = pfPermit($this, false, 'active', pfWorker($this, '63636312'), [['worker_id' => $watcher, 'role' => 'watcher']]);
    pfRole($this->me, 'worker');

    $ctx = $this->getJson("/api/v1/ptr/work-permits/{$id}/context", $this->h)->assertOk();
    expect($ctx->json('data.can_suspend'))->toBeTrue()->and($ctx->json('data.requires_gas_test'))->toBeFalse();
    // Con su rol no edita el permiso por la vía común.
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id}", ['status' => 'suspended', 'suspended_reason' => 'Fuga'], $this->h)->assertForbidden();

    $this->postJson("/api/v1/ptr/work-permits/{$id}/suspend", ['reason' => '  '], $this->h)->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/ptr/work-permits/{$id}/suspend", ['reason' => 'Olor a gas en el tanque'], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.suspended_reason', 'Olor a gas en el tanque');
    expect(pfAs(fn () => DB::selectOne("select count(*) as n from notifications where event_code = 'ptr.suspended' and subject_id = ?", [$id])->n))->toBeGreaterThan(0);
    // Ya suspendido, no se vuelve a suspender; y el trabajador no lo reanuda.
    $this->postJson("/api/v1/ptr/work-permits/{$id}/suspend", ['reason' => 'Otra vez'], $this->h)->assertStatus(409);
    expect($this->getJson("/api/v1/ptr/work-permits/{$id}/context", $this->h)->json('data.can_suspend'))->toBeFalse();
});

it('un trabajador que no participa en el permiso, o un rol sin permiso de suspender, no lo suspende', function () {
    pfWorker($this, '63636321', $this->me);
    $id = pfPermit($this, false, 'active', pfWorker($this, '63636322'));

    pfRole($this->me, 'worker');
    expect($this->getJson("/api/v1/ptr/work-permits/{$id}/context", $this->h)->assertOk()->json('data.can_suspend'))->toBeFalse();
    $this->postJson("/api/v1/ptr/work-permits/{$id}/suspend", ['reason' => 'Fuga'], $this->h)->assertForbidden();

    pfRole($this->me, 'management');
    $this->postJson("/api/v1/ptr/work-permits/{$id}/suspend", ['reason' => 'Fuga'], $this->h)->assertForbidden();
    expect(pfAs(fn () => DB::selectOne('select status from work_permits where id = ?', [$id])->status))->toBe('active');

    // El supervisor SST (con permiso de suspender) sí lo suspende aunque no esté en la cuadrilla.
    pfRole($this->me, 'sst_supervisor');
    $this->postJson("/api/v1/ptr/work-permits/{$id}/suspend", ['reason' => 'Viento fuerte'], $this->h)->assertOk()->assertJsonPath('data.status', 'suspended');
});
