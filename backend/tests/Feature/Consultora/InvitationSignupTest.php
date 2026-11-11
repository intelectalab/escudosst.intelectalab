<?php

// Alta desde la invitación de la consultora (M-CON §7.1, RN-CON-02/03/06): si la empresa invitada aún no usa
// EscudoSST, crea su cuenta con el enlace (registro autoservicio en prueba, administrador = correo invitado) y queda
// vinculada a la consultora, con quien invitó como consultor asignado. La invitación sirve una sola vez.
//
// La cuenta, la empresa y la invitación se escriben con la conexión de la plataforma (fuera de la transacción de la
// prueba): en afterEach se dan de baja lógica (libera el RUC).

use App\Modules\Consultora\ClientInvitationMail;
use App\Modules\USR\Mail\AccountInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function cisAccount(): string
{
    return '01900000-0000-7000-8000-0000000c0a01';
}

function cisRuc(): string
{
    $base = '20'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $sum = 0;
    foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $w) {
        $sum += (int) $base[$i] * $w;
    }
    $check = 11 - ($sum % 11);

    return $base.($check === 10 ? 0 : ($check === 11 ? 1 : $check));
}

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->started = DB::connection('platform')->selectOne('select now() as t')->t;
    $o = DB::connection('platform');
    $o->insert("insert into accounts (id, name, account_type) values (?, 'Prevencia SST', 'consultancy') on conflict (id) do update set name = excluded.name, account_type = 'consultancy'", [cisAccount()]);
    $o->update('update companies set managed_by_account_id = ? where id = ?', [cisAccount(), ANDINAS_ID]);
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $o = DB::connection('platform');
    $o->transaction(function () use ($o) {
        $o->update('update companies set managed_by_account_id = null where managed_by_account_id = ?', [cisAccount()]);
        $o->update('update client_invitations set deleted_at = now() where consultancy_account_id = ? and deleted_at is null', [cisAccount()]);
        $o->update('update subscriptions set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $o->update('update companies set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $o->update("update accounts set deleted_at = now() where created_at >= ? and deleted_at is null and account_type = 'direct'", [$this->started]);
        $o->update("update users set deleted_at = now() where created_at >= ? and email like '%@prueba.test' and deleted_at is null", [$this->started]);
    });
});

/** El usuario corporativo entra a Andinas como consultor de Prevencia (dentro de la transacción de la prueba). */
function cisConsultant(): string
{
    $user = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $m = DB::selectOne("insert into memberships (company_id, user_id, role_id, status, accepted_at)
                         select ?, ?, r.id, 'active', now() from roles r where r.account_id is null and r.code = 'consultant' returning id", [ANDINAS_ID, $user])->id;
    DB::insert('insert into consultant_assignments (company_id, consultancy_account_id, consultant_user_id, membership_id, is_lead) values (?, ?, ?, ?, true)',
        [ANDINAS_ID, cisAccount(), $user, $m]);
    DB::select("select set_config('app.company_id', '', false)");

    return $user;
}

/** Invita una empresa y devuelve el token del enlace enviado por correo. */
function cisInvite(array $h, string $ruc, string $email): string
{
    test()->postJson('/api/v1/con/invitations', ['ruc' => $ruc, 'legal_name' => 'Nueva Cliente S.A.C.', 'admin_email' => $email, 'suggested_plan' => 'P'], $h)->assertCreated();
    $url = null;
    Mail::assertSent(ClientInvitationMail::class, function (ClientInvitationMail $m) use (&$url, $email) {
        if ($m->hasTo($email)) {
            $url = $m->url;
        }

        return true;
    });

    return basename((string) $url);
}

it('la empresa invitada sin cuenta la crea desde la invitación y queda vinculada a la consultora', function () {
    Mail::fake();
    $consultant = cisConsultant();
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);
    $ruc = cisRuc();
    $email = 'gerencia.'.bin2hex(random_bytes(4)).'@prueba.test';
    $token = cisInvite($h, $ruc, $email);

    $preview = $this->getJson("/api/v1/public/con/invitations/{$token}")->assertOk()->assertJsonPath('data.company_exists', false);
    expect($preview->json('data.invited_email'))->toStartWith('g')->not->toBe($email)->toEndWith('@prueba.test');

    $this->postJson("/api/v1/public/con/invitations/{$token}/signup", ['full_name' => 'Gina Gerente', 'headcount' => 40])
        ->assertStatus(422)->assertJsonValidationErrors('accept_legal');
    $res = $this->postJson("/api/v1/public/con/invitations/{$token}/signup", ['full_name' => 'Gina Gerente', 'headcount' => 40, 'trade_name' => 'Nueva Cliente', 'accept_legal' => true])
        ->assertCreated()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.consultancy', 'Prevencia SST')->assertJsonPath('data.invitation', 'invited');
    $company = $res->json('data.company_id');

    $o = DB::connection('platform');
    $c = $o->selectOne('select ruc, legal_name, managed_by_account_id from companies where id = ?', [$company]);
    expect(trim($c->ruc))->toBe($ruc)->and($c->legal_name)->toBe('Nueva Cliente S.A.C.')->and($c->managed_by_account_id)->toBe(cisAccount());
    $inv = $o->selectOne('select status, company_id from client_invitations where consultancy_account_id = ? and target_ruc = ? and deleted_at is null', [cisAccount(), $ruc]);
    expect($inv->status)->toBe('accepted')->and($inv->company_id)->toBe($company);
    // El administrador es el correo invitado (recibe el enlace para crear su contraseña) y quien invitó es consultor líder.
    Mail::assertSent(AccountInvitationMail::class, fn ($m) => $m->hasTo($email));
    DB::select("select set_config('app.company_id', ?, false)", [$company]);
    $roles = DB::select('select u.email, r.code, m.status from memberships m join roles r on r.id = m.role_id join users u on u.id = m.user_id where m.company_id = ? order by r.code', [$company]);
    expect(collect($roles)->pluck('code')->all())->toEqualCanonicalizing(['account_admin', 'consultant'])
        ->and(collect($roles)->firstWhere('code', 'account_admin')->email)->toBe($email);
    expect(DB::selectOne('select is_lead from consultant_assignments where company_id = ? and consultant_user_id = ?', [$company, $consultant])->is_lead)->toBeTrue();
    DB::select("select set_config('app.company_id', '', false)");

    // Un solo uso.
    $this->postJson("/api/v1/public/con/invitations/{$token}/signup", ['full_name' => 'Gina Gerente', 'headcount' => 40, 'accept_legal' => true])->assertStatus(409);
});

it('no crea cuenta desde la invitación si la empresa ya usa EscudoSST', function () {
    Mail::fake();
    cisConsultant();
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);
    $token = cisInvite($h, '20605478914', 'gerencia.'.bin2hex(random_bytes(4)).'@prueba.test');   // AgroExport ya existe
    $this->postJson("/api/v1/public/con/invitations/{$token}/signup", ['full_name' => 'Gina Gerente', 'headcount' => 40, 'accept_legal' => true])->assertStatus(409);
    $this->postJson('/api/v1/public/con/invitations/'.str_repeat('a', 48).'/signup', ['full_name' => 'Gina Gerente', 'headcount' => 40, 'accept_legal' => true])->assertNotFound();
});
