<?php

// M-MOC RN-MOC-03 / criterio 3: el cambio de impacto alto que aprueba el Jefe SST queda «pendiente de gerencia»; solo gerencia
// lo aprueba o rechaza (con aviso a gerencia). Los de impacto bajo o medio los aprueba el Jefe SST directamente.

use Illuminate\Support\Facades\DB;

function mocAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

function mocFresh(): void
{
    $GLOBALS['sus_tokens'] = [];
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
}

afterEach(fn () => mocFresh());

beforeEach(function () {
    mocFresh();
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    mocAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'MOC' and deleted_at is null", [ANDINAS_ID]));
    $this->uid = mocAs(fn () => DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id);
    mocFresh();
});

/** Cambio en revisión con su revisión aprobada, listo para que el Jefe SST lo apruebe. */
function mocReady($test, string $impact): string
{
    $c = $test->postJson('/api/v1/records/MOC/change_requests', ['title' => "Cambio {$impact}", 'change_type' => 'equipment', 'description' => 'Equipo nuevo'], $test->h)->assertCreated()->json('data.id');
    $test->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'submitted', 'impact_level' => $impact], $test->h)->assertOk();
    $test->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'under_review'], $test->h)->assertOk();
    $test->postJson('/api/v1/records/MOC/change_reviews', ['change_request_id' => $c, 'reviewer_user_id' => $test->uid, 'review_area' => 'SST', 'decision' => 'approve'], $test->h)->assertCreated();

    return $c;
}

/** El mismo usuario pasa a gerencia (rol base «management»); devuelve sus cabeceras nuevas. */
function mocAsManagement($test): array
{
    mocAs(fn () => DB::update("update memberships set role_id = (select id from roles where code = 'management' and account_id is null) where user_id = ? and company_id = ?", [$test->uid, ANDINAS_ID]));
    mocFresh();

    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('impacto alto: el Jefe SST lo deja pendiente de gerencia, no puede darle la aprobación final y gerencia lo aprueba', function () {
    // Una persona de gerencia en la empresa (para el aviso).
    $manager = mocAs(function () {
        $u = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
        DB::insert("insert into memberships (company_id, user_id, role_id, status) values (?, ?, (select id from roles where code = 'management' and account_id is null), 'active')", [ANDINAS_ID, $u]);

        return $u;
    });
    mocFresh();
    $c = mocReady($this, 'high');
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'approved'], $this->h)->assertOk()
        ->assertJsonPath('data.status', 'pending_management')->assertJsonPath('data.management_approved_by', null)->assertJsonPath('data.decision_at', null);
    // Aviso a gerencia.
    expect(mocAs(fn () => DB::selectOne("select recipient_user_id, title from notifications where event_code = 'moc.pending_management' and subject_id = ?", [$c])))
        ->recipient_user_id->toBe($manager)->title->toBe('Cambio de impacto alto por aprobar');

    // El Jefe SST no lo aprueba ni lo rechaza estando pendiente de gerencia.
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'approved'], $this->h)->assertStatus(403);
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'rejected'], $this->h)->assertStatus(403);
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'implemented', 'risk_assessment' => ['Evaluado']], $this->h)->assertStatus(422);

    $g = mocAsManagement($this);
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'approved'], $g)->assertOk()
        ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.management_approved_by', $this->uid);
});

it('impacto alto: gerencia puede rechazar el cambio pendiente de gerencia', function () {
    $c = mocReady($this, 'high');
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'approved'], $this->h)->assertOk()->assertJsonPath('data.status', 'pending_management');
    $g = mocAsManagement($this);
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'rejected'], $g)->assertOk()->assertJsonPath('data.status', 'rejected');
});

it('impacto bajo o medio: el Jefe SST lo aprueba directamente', function () {
    foreach (['low', 'medium'] as $impact) {
        $c = mocReady($this, $impact);
        $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'approved'], $this->h)->assertOk()->assertJsonPath('data.status', 'approved');
    }
});

it('sin el permiso de aprobar cambios no se aprueba', function () {
    $c = mocReady($this, 'low');
    mocAs(fn () => DB::update("update memberships set role_id = (select id from roles where code = 'sst_supervisor' and account_id is null) where user_id = ? and company_id = ?", [$this->uid, ANDINAS_ID]));
    mocFresh();
    $s = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->patchJson("/api/v1/records/MOC/change_requests/{$c}", ['status' => 'approved'], $s)->assertStatus(403);
});
