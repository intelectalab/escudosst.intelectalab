<?php

// Accesos de soporte (docs/05 §4.6): solicitud, autorización del administrador de cuenta,
// entrada en solo lectura sin módulos médicos, y revocación.

use App\Modules\USR\Mail\AccountInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->started = DB::connection('platform')->selectOne("select now() - interval '1 second' as t")->t;
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    $db->update('update support_access_grants set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
    $db->update('update subscriptions set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
    $db->update('update companies set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
    $db->update('update accounts set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
    $db->update("update users set deleted_at = now() where created_at >= ? and email like '%@soporte.test' and deleted_at is null", [$this->started]);
});

/** Crea una empresa en prueba con su administrador de cuenta ya activo (contraseña demo) y devuelve [companyId, email]. */
function companyWithAdmin(): array
{
    Mail::fake();
    $email = 'admin.'.bin2hex(random_bytes(4)).'@soporte.test';
    $base = '20'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $sum = 0;
    foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $w) {
        $sum += (int) $base[$i] * $w;
    }
    $c = 11 - ($sum % 11);
    $ruc = $base.($c === 10 ? 0 : ($c === 11 ? 1 : $c));

    $res = test()->postJson('/api/v1/internal/accounts', [
        'account_type' => 'direct', 'ruc' => $ruc, 'legal_name' => 'Empresa Soporte S.A.C.', 'headcount' => 30,
        'start' => 'trial', 'billing_period' => 'monthly', 'admin_name' => 'Ana Admin', 'admin_email' => $email,
    ], authHeaders(tokenFor(ADMIN_EMAIL)))->assertCreated();

    $url = null;
    Mail::assertSent(AccountInvitationMail::class, function ($m) use (&$url) {
        $url = $m->url;

        return true;
    });
    test()->postJson('/api/v1/auth/invitations/'.basename($url).'/accept', ['password' => DEMO_PASSWORD, 'password_confirmation' => DEMO_PASSWORD])->assertOk();

    return [$res->json('data.company_id'), $email];
}

it('soporte pide acceso, el administrador lo autoriza y soporte entra solo en lectura y sin módulos médicos', function () {
    [$companyId, $adminEmail] = companyWithAdmin();
    $staff = fn () => authHeaders(tokenFor(ADMIN_EMAIL));

    // Sin autorización, soporte no tiene acceso a la empresa.
    expect(collect($this->getJson('/api/v1/me/companies', $staff())->json('data'))->pluck('company_id'))->not->toContain($companyId);

    $this->postJson('/api/v1/internal/support-access', ['company_id' => $companyId, 'reason' => 'corto', 'hours' => 8], $staff())->assertStatus(422);
    $grantId = $this->postJson('/api/v1/internal/support-access', [
        'company_id' => $companyId, 'reason' => 'Revisar un error al cargar trabajadores', 'ticket_reference' => 'TCK-101', 'hours' => 8,
    ], $staff())->assertCreated()->json('data.id');

    // No se duplica la solicitud.
    $this->postJson('/api/v1/internal/support-access', ['company_id' => $companyId, 'reason' => 'Revisar otra cosa distinta', 'hours' => 2], $staff())->assertStatus(422);

    // El administrador de cuenta la ve (con quién la pide) y la autoriza.
    $admin = fn () => authHeaders(tokenFor($adminEmail), $companyId);
    $list = $this->getJson('/api/v1/account/support-access', $admin())->assertOk();
    expect($list->json('data.0.status'))->toBe('requested')
        ->and($list->json('data.0.support_name'))->toBe('Equipo EscudoSST')
        ->and($list->json('can_manage'))->toBeTrue();
    $this->postJson("/api/v1/account/support-access/{$grantId}/approve", [], $admin())->assertNoContent();

    // Soporte ya ve la empresa y entra solo en lectura, sin módulos médicos.
    expect(collect($this->getJson('/api/v1/me/companies', $staff())->json('data'))->pluck('company_id'))->toContain($companyId);
    $perms = $this->getJson('/api/v1/me/permissions', authHeaders(tokenFor(ADMIN_EMAIL), $companyId))->assertOk()->json('data');
    expect($perms)->not->toBeEmpty()
        ->and(collect($perms)->every(fn ($p) => str_ends_with($p, '.read')))->toBeTrue()
        ->and(collect($perms)->contains(fn ($p) => str_starts_with($p, 'emo.') || str_starts_with($p, 'vmd.')))->toBeFalse();
    $modules = collect($this->getJson('/api/v1/me/modules', authHeaders(tokenFor(ADMIN_EMAIL), $companyId))->json('data'))->keyBy('code');
    expect($modules['IPC']['role_can_use'])->toBeTrue()
        ->and($modules['EMO']['role_can_use'])->toBeFalse();

    // El administrador revoca: soporte pierde el acceso al momento.
    $this->postJson("/api/v1/account/support-access/{$grantId}/revoke", [], $admin())->assertNoContent();
    expect(collect($this->getJson('/api/v1/me/companies', $staff())->json('data'))->pluck('company_id'))->not->toContain($companyId);
});

it('solo el administrador de cuenta decide; el Jefe SST solo mira', function () {
    $sst = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->getJson('/api/v1/account/support-access', $sst)->assertOk()->assertJsonPath('can_manage', false);
    $this->postJson('/api/v1/account/support-access/'.Str::uuid().'/approve', [], $sst)->assertStatus(403);
    $this->getJson('/api/v1/internal/support-access', authHeaders(tokenFor(PROFESIONAL_EMAIL)))->assertStatus(403);
});

it('no se puede pedir acceso a una empresa sin administrador de cuenta', function () {
    $this->postJson('/api/v1/internal/support-access', [
        'company_id' => ANDINAS_ID, 'reason' => 'Revisar un problema del cliente', 'hours' => 2,
    ], authHeaders(tokenFor(ADMIN_EMAIL)))->assertStatus(422)->assertJsonPath('errors.company_id.0', 'Esta empresa no tiene un administrador de cuenta activo que pueda autorizar el acceso.');
});
