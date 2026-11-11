<?php

// Panel de la consultora (M-CON SCR-CON-01..03): empresas cliente con asignación vigente, invitación de empresas,
// aceptación por la empresa invitada y retiro del acceso por el cliente (RN-CON-01/02/06).

use App\Modules\Consultora\ClientInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

const CON_ACCOUNT_ID = '01900000-0000-7000-8000-0000000c0a01';

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    // La cuenta consultora y la empresa que gestiona se escriben con la conexión de la plataforma (fuera de la
    // transacción de la prueba): se deshace en afterEach. La cuenta de prueba queda creada (no se borran cuentas).
    $o = DB::connection('platform');
    $o->insert("insert into accounts (id, name, account_type) values (?, 'Prevencia SST', 'consultancy') on conflict (id) do update set name = excluded.name, account_type = 'consultancy'", [CON_ACCOUNT_ID]);
    $o->update('update companies set managed_by_account_id = ? where id = ?', [CON_ACCOUNT_ID, ANDINAS_ID]);
});

afterEach(function () {
    $o = DB::connection('platform');
    $o->update('update companies set managed_by_account_id = null where managed_by_account_id = ?', [CON_ACCOUNT_ID]);
    $o->update('update client_invitations set deleted_at = now() where consultancy_account_id = ? and deleted_at is null', [CON_ACCOUNT_ID]);
});

/** El usuario corporativo entra a Andinas como consultor de Prevencia (dentro de la transacción de la prueba). */
function conConsultant(bool $assigned = true): string
{
    $user = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $m = DB::selectOne("insert into memberships (company_id, user_id, role_id, status, accepted_at)
                         select ?, ?, r.id, 'active', now() from roles r where r.account_id is null and r.code = 'consultant' returning id", [ANDINAS_ID, $user])->id;
    if ($assigned) {
        DB::insert('insert into consultant_assignments (company_id, consultancy_account_id, consultant_user_id, membership_id, is_lead) values (?, ?, ?, ?, true)',
            [ANDINAS_ID, CON_ACCOUNT_ID, $user, $m]);
    }
    DB::select("select set_config('app.company_id', '', false)");

    return $user;
}

it('muestra al consultor sus empresas cliente con indicadores y agenda', function () {
    conConsultant();
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);
    $r = $this->getJson('/api/v1/con/panel', $h)->assertOk();
    expect($r->json('data.consultancy.name'))->toBe('Prevencia SST')
        ->and($r->json('data.kpis.companies'))->toBe(1)
        ->and($r->json('data.companies.0.company_id'))->toBe(ANDINAS_ID)
        ->and($r->json('data.companies.0.ruc'))->toBe('20518472632')
        ->and($r->json('data.companies.0'))->toHaveKeys(['workers', 'plan', 'sst_model', 'compliance', 'alerts'])
        ->and($r->json('data.kpis'))->toHaveKeys(['workers', 'compliance', 'alerts'])
        ->and($r->json('data'))->toHaveKeys(['agenda', 'critical', 'consultants']);
    // Desde Huaylas (no gestionada por la consultora) el panel no se abre.
    $this->getJson('/api/v1/con/panel', authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID))->assertStatus(403);
});

it('oculta las empresas sin asignación vigente del consultor', function () {
    conConsultant(assigned: false);
    $this->getJson('/api/v1/con/panel', authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID))->assertOk()
        ->assertJsonPath('data.kpis.companies', 0);
});

it('no abre el panel a quien no es consultor ni administrador de la consultora', function () {
    $this->getJson('/api/v1/con/panel', authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->assertStatus(403);
});

it('invita una empresa, la empresa acepta y luego puede quitar el acceso', function () {
    Mail::fake();
    conConsultant();
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);

    $this->postJson('/api/v1/con/invitations', ['ruc' => '20605478915', 'legal_name' => 'X SAC', 'admin_email' => 'a@x.pe'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('ruc');                       // RUC inválido
    $this->postJson('/api/v1/con/invitations', ['ruc' => '20518472632', 'legal_name' => 'Andinas', 'admin_email' => 'a@x.pe'], $h)
        ->assertStatus(422);                                                            // ya es cliente
    $this->postJson('/api/v1/con/invitations', ['ruc' => '20605478914', 'legal_name' => 'AgroExport', 'admin_email' => 'Gerencia@Agro.pe', 'suggested_plan' => 'P'], $h)
        ->assertCreated()->assertJsonPath('data.company_exists', true);
    $this->postJson('/api/v1/con/invitations', ['ruc' => '20605478914', 'legal_name' => 'AgroExport', 'admin_email' => 'gerencia@agro.pe'], $h)
        ->assertStatus(422);                                                            // ya hay una vigente

    $url = null;
    Mail::assertSent(ClientInvitationMail::class, function (ClientInvitationMail $m) use (&$url) {
        $url = $m->url;

        return $m->hasTo('gerencia@agro.pe') && $m->consultancy === 'Prevencia SST' && $m->planName === 'Profesional';
    });
    $token = basename((string) $url);
    expect(strlen($token))->toBe(48);
    $this->getJson('/api/v1/con/invitations', $h)->assertOk()->assertJsonPath('data.0.status', 'sent')->assertJsonPath('data.0.suggested_plan', 'Profesional');

    // Vista pública de la invitación.
    $this->getJson("/api/v1/public/con/invitations/{$token}")->assertOk()
        ->assertJsonPath('data.consultancy', 'Prevencia SST')->assertJsonPath('data.company_exists', true)->assertJsonPath('data.status', 'sent');

    // Otra empresa (RUC distinto) no puede aceptarla; la invitada sí.
    $this->postJson("/api/v1/con/invitations/{$token}/accept", [], authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->assertStatus(403);
    $this->postJson("/api/v1/con/invitations/{$token}/accept", [], authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID))->assertOk()
        ->assertJsonPath('data.status', 'accepted');
    $this->postJson("/api/v1/con/invitations/{$token}/accept", [], authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID))->assertStatus(409);   // un solo uso
    expect(DB::connection('platform')->selectOne('select managed_by_account_id from companies where id = ?', [AGRO_ID])->managed_by_account_id)->toBe(CON_ACCOUNT_ID);

    // Quien invitó ya ve la nueva empresa en su panel.
    $GLOBALS['sus_tokens'] = [];
    $codes = collect($this->getJson('/api/v1/con/panel', authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID))->assertOk()->json('data.companies'))->pluck('company_id');
    expect($codes->all())->toContain(AGRO_ID);

    // La empresa cliente quita el acceso: la consultora lo pierde de inmediato.
    $this->postJson('/api/v1/con/link/revoke', [], authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID))->assertOk();
    // Se reutiliza la sesión (un tercer ingreso con TOTP en la misma prueba cae fuera de la ventana del código).
    $codes = collect($this->getJson('/api/v1/con/panel', authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID))->assertOk()->json('data.companies'))->pluck('company_id');
    expect($codes->all())->not->toContain(AGRO_ID);
});

it('anula una invitación pendiente', function () {
    Mail::fake();
    conConsultant();
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);
    $id = $this->postJson('/api/v1/con/invitations', ['ruc' => '20605478914', 'legal_name' => 'AgroExport', 'admin_email' => 'g@agro.pe'], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/con/invitations/{$id}/revoke", [], $h)->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/con/invitations/{$id}/revoke", [], $h)->assertStatus(404);
});
