<?php

// Matriz consultor × empresa (M-CON SCR-CON-04, RN-CON-01) y «Quitar acceso a la consultora» desde la empresa cliente (RN-CON-02).

use Illuminate\Support\Facades\DB;

const CONX_ACCOUNT_ID = '01900000-0000-7000-8000-0000000c0a02';
const CONX_COMPANY_ID = '01900000-0000-7000-8000-0000000c0c02';

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    // Cuenta consultora con su empresa propia y Andinas como cliente: se escriben con la plataforma y se deshacen al final.
    $o = DB::connection('platform');
    $o->insert("insert into accounts (id, name, account_type) values (?, 'Asesores Matriz SST', 'consultancy') on conflict (id) do update set name = excluded.name, account_type = 'consultancy'", [CONX_ACCOUNT_ID]);
    $o->insert("insert into companies (id, account_id, ruc, legal_name) values (?, ?, '20609876541', 'Asesores Matriz SST S.A.C.') on conflict (id) do update set deleted_at = null", [CONX_COMPANY_ID, CONX_ACCOUNT_ID]);
    $o->update('update companies set managed_by_account_id = ? where id = ?', [CONX_ACCOUNT_ID, ANDINAS_ID]);
});

afterEach(function () {
    DB::rollBack();
    $o = DB::connection('platform');
    $o->update('update companies set managed_by_account_id = null where managed_by_account_id = ?', [CONX_ACCOUNT_ID]);
    // La empresa de prueba queda marcada como eliminada (no se borran empresas); la cuenta queda creada, como en ConsultancyPanelTest.
    $o->update('update companies set deleted_at = now() where id = ?', [CONX_COMPANY_ID]);
});

function conxUser(string $email): string
{
    return DB::selectOne('select id from app_auth_lookup_user(?)', [$email])->id;
}

/** Membresía de prueba (dentro de la transacción). */
function conxMember(string $company, string $email, string $role): void
{
    DB::select("select set_config('app.company_id', ?, false)", [$company]);
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) select ?, ?, r.id, 'active', now() from roles r where r.account_id is null and r.code = ?",
        [$company, conxUser($email), $role]);
    DB::select("select set_config('app.company_id', '', false)");
}

it('el administrador de la consultora asigna y quita consultores en la matriz', function () {
    // Corporativo: administrador de la consultora y consultor en Andinas. Esencial: consultor de la consultora, aún sin empresas.
    conxMember(CONX_COMPANY_ID, CORPORATIVO_EMAIL, 'account_admin');
    conxMember(ANDINAS_ID, CORPORATIVO_EMAIL, 'consultant');
    conxMember(CONX_COMPANY_ID, ESENCIAL_EMAIL, 'consultant');
    $admin = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);
    $esencial = conxUser(ESENCIAL_EMAIL);

    $m = $this->getJson('/api/v1/con/assignments', $admin)->assertOk();
    expect($m->json('data.can_edit'))->toBeTrue()
        ->and(collect($m->json('data.companies'))->pluck('id')->all())->toBe([ANDINAS_ID])
        ->and(collect($m->json('data.consultants'))->pluck('id')->all())->toContain($esencial)->toContain(conxUser(CORPORATIVO_EMAIL))
        ->and($m->json('data.assignments'))->toBe([]);

    // Validaciones: empresa de otra cuenta, usuario que no es de la consultora.
    $this->postJson('/api/v1/con/assignments', ['company_id' => HUAYLAS_ID, 'user_id' => $esencial, 'assigned' => true], $admin)->assertStatus(422)->assertJsonValidationErrors('company_id');
    $this->postJson('/api/v1/con/assignments', ['company_id' => ANDINAS_ID, 'user_id' => conxUser(PROFESIONAL_EMAIL), 'assigned' => true], $admin)->assertStatus(422)->assertJsonValidationErrors('user_id');

    // Asignar como responsable principal: el consultor entra a la empresa y la ve en su panel.
    $r = $this->postJson('/api/v1/con/assignments', ['company_id' => ANDINAS_ID, 'user_id' => $esencial, 'assigned' => true, 'is_lead' => true], $admin)->assertOk();
    $cell = collect($r->json('data.assignments'))->firstWhere('user_id', $esencial);
    expect($cell['company_id'])->toBe(ANDINAS_ID)->and($cell['is_lead'])->toBeTrue();
    app('auth')->forgetGuards();
    $eh = authHeaders(tokenFor(ESENCIAL_EMAIL), ANDINAS_ID);
    expect(collect($this->getJson('/api/v1/con/panel', $eh)->assertOk()->json('data.companies'))->pluck('company_id')->all())->toBe([ANDINAS_ID]);
    // Un consultor ve la matriz en solo lectura y no la cambia.
    expect($this->getJson('/api/v1/con/assignments', $eh)->assertOk()->json('data.can_edit'))->toBeFalse();
    $this->postJson('/api/v1/con/assignments', ['company_id' => ANDINAS_ID, 'user_id' => $esencial, 'assigned' => false], $eh)->assertStatus(403);

    // Quitar la asignación: pierde el acceso a la empresa de inmediato.
    app('auth')->forgetGuards();
    $r = $this->postJson('/api/v1/con/assignments', ['company_id' => ANDINAS_ID, 'user_id' => $esencial, 'assigned' => false], $admin)->assertOk();
    expect(collect($r->json('data.assignments'))->firstWhere('user_id', $esencial))->toBeNull();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne('select m.status from memberships m where m.company_id = ? and m.user_id = ?', [ANDINAS_ID, $esencial])->status)->toBe('revoked');
});

it('la empresa cliente ve a su consultora y le quita el acceso', function () {
    conxMember(CONX_COMPANY_ID, CORPORATIVO_EMAIL, 'account_admin');
    conxMember(ANDINAS_ID, ESENCIAL_EMAIL, 'consultant');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::insert('insert into consultant_assignments (company_id, consultancy_account_id, consultant_user_id, is_lead) values (?, ?, ?, true)', [ANDINAS_ID, CONX_ACCOUNT_ID, conxUser(ESENCIAL_EMAIL)]);
    DB::select("select set_config('app.company_id', '', false)");

    $client = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $l = $this->getJson('/api/v1/con/link', $client)->assertOk();
    expect($l->json('data.managed'))->toBeTrue()->and($l->json('data.consultancy'))->toBe('Asesores Matriz SST')
        ->and($l->json('data.can_revoke'))->toBeTrue()->and($l->json('data.consultants.0.is_lead'))->toBeTrue();
    // Huaylas no trabaja con consultora.
    expect($this->getJson('/api/v1/con/link', authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID))->assertOk()->json('data.managed'))->toBeFalse();

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/con/link/revoke', [], $client)->assertOk()->assertJsonPath('data.status', 'unlinked');
    expect($this->getJson('/api/v1/con/link', $client)->assertOk()->json('data.managed'))->toBeFalse();
    // Se avisa al administrador de la consultora en su empresa.
    DB::select("select set_config('app.company_id', ?, false)", [CONX_COMPANY_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where event_code = 'con.link_revoked' and recipient_user_id = ?", [conxUser(CORPORATIVO_EMAIL)])->n)->toBe(1);
});
