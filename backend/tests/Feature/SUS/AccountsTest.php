<?php

// SCR-SUS-02 / SCR-SUS-03 · Cuentas y suscripciones (docs/modulos/M-SUS.md, docs/03 §8 y §9, docs/06 §20).
//
// La conexión 'platform' no entra en la transacción de la prueba: se guarda el estado de las suscripciones
// y feature flags demo antes de cada prueba y se restaura después; las cuentas creadas se dan de baja lógica.

use App\Modules\SUS\Mail\SubscriptionNoticeMail;
use App\Modules\SUS\Services\RenewalService;
use App\Modules\USR\Mail\AccountInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** RUC 20xxxxxxxxx con dígito verificador correcto (módulo 11). */
const DEMO_ACCOUNT_ID = '01900000-0000-7000-8000-000000000001';

function randomRuc(): string
{
    $base = '20'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $sum = 0;
    foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $w) {
        $sum += (int) $base[$i] * $w;
    }
    $check = 11 - ($sum % 11);

    return $base.($check === 10 ? 0 : ($check === 11 ? 1 : $check));
}

function flagMode(string $companyId, string $module): string
{
    return DB::connection('platform')->selectOne(
        'select mode from feature_flags where company_id = ? and module_code = ? and deleted_at is null', [$companyId, $module]
    )->mode;
}

function subscriptionOf(string $companyId): object
{
    return DB::connection('platform')->selectOne(
        'select s.id, s.status, p.code as plan from subscriptions s join plans p on p.id = s.plan_id
          where s.company_id = ? and s.deleted_at is null order by s.started_at desc limit 1', [$companyId]
    );
}

function newAccountPayload(array $overrides = []): array
{
    return $overrides + [
        'account_type' => 'direct',
        'ruc' => randomRuc(),
        'legal_name' => 'Minera Prueba S.A.C.',
        'trade_name' => 'Minera Prueba',
        'economic_sector' => 'mining',
        'headcount' => 35,
        'start' => 'trial',
        'plan' => null,
        'billing_period' => 'monthly',
        'admin_name' => 'Lucía Prueba Ramos',
        'admin_email' => 'admin.'.bin2hex(random_bytes(4)).'@prueba.test',
    ];
}

function internal(string $method, string $uri, array $data = [])
{
    return test()->json($method, '/api/v1/internal/'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->started = $db->selectOne("select now() - interval '1 second' as t")->t;   // reloj de la base
    $this->snapshotSubs = $db->select('select id, plan_id, status, cancel_reason, grace_until, current_period_start, current_period_end from subscriptions');
    $this->snapshotFlags = $db->select('select id, mode, source from feature_flags');
});

afterEach(function () {
    // Primero se revierte la transacción de la prueba: sus bloqueos (p. ej. al aceptar la invitación)
    // harían esperar para siempre a la limpieza por la conexión 'platform'.
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    $db->transaction(function () use ($db) {
        foreach ($this->snapshotSubs as $s) {
            $db->update(
                'update subscriptions set plan_id = ?, status = ?, cancel_reason = ?, grace_until = ?, current_period_start = ?, current_period_end = ? where id = ?',
                [$s->plan_id, $s->status, $s->cancel_reason, $s->grace_until, $s->current_period_start, $s->current_period_end, $s->id]
            );
        }
        // Complementos agregados en la prueba: baja lógica (refresh_feature_flags ignora deleted_at).
        $db->update('update subscription_addons set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        foreach ($this->snapshotFlags as $f) {
            $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
        }
        // Pagos y comprobantes de la prueba: baja lógica.
        $db->update('update payments set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $db->update('update invoices set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        // Cuentas creadas en la prueba: baja lógica (libera el RUC).
        $db->update('update subscriptions set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $db->update('update companies set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $db->update('update accounts set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $db->update("update users set deleted_at = now() where created_at >= ? and email like '%@prueba.test' and deleted_at is null", [$this->started]);
    });
});

it('solo el equipo de la plataforma ve las cuentas', function () {
    $this->getJson('/api/v1/internal/accounts', authHeaders(tokenFor(PROFESIONAL_EMAIL)))->assertStatus(403);
});

it('lista la cuenta demo con sus 3 empresas y el plan de cada una', function () {
    $accounts = internal('GET', 'accounts')->assertOk()->json('data');
    $demo = collect($accounts)->firstWhere('id', DEMO_ACCOUNT_ID);

    expect($demo['companies'])->toHaveCount(3);
    $plans = collect($demo['companies'])->mapWithKeys(fn ($c) => [$c['id'] => $c['subscription']['plan_code']]);
    expect($plans[AGRO_ID])->toBe('E')
        ->and($plans[ANDINAS_ID])->toBe('P')
        ->and($plans[HUAYLAS_ID])->toBe('C');
});

it('muestra el detalle con módulos, complementos y administradores', function () {
    $andinas = collect(internal('GET', 'accounts/'.DEMO_ACCOUNT_ID)->assertOk()->json('data.companies'))->firstWhere('id', ANDINAS_ID);

    expect($andinas['modules']['counts']['enabled'])->toBeGreaterThan(20)
        ->and($andinas['addons'])->toBeArray()
        ->and($andinas['admins'])->toBeArray();

    internal('GET', 'accounts/00000000-0000-0000-0000-000000000000')->assertStatus(404);
});

it('crea una cuenta en prueba con plan Profesional e invita al administrador', function () {
    Mail::fake();
    $payload = newAccountPayload();

    $res = internal('POST', 'accounts', $payload)->assertCreated();
    $companyId = $res->json('data.company_id');
    expect($res->json('data.invitation'))->toBe('invited');

    $sub = subscriptionOf($companyId);
    expect($sub->status)->toBe('trialing')->and($sub->plan)->toBe('P');
    expect(flagMode($companyId, 'MRL'))->toBe('enabled')      // incluido en Profesional
        ->and(flagMode($companyId, 'PTR'))->toBe('locked');   // solo Corporativo

    $company = DB::connection('platform')->selectOne('select sst_model from companies where id = ?', [$companyId]);
    expect($company->sst_model)->toBe('committee');           // 35 trabajadores ≥ 20

    $detail = internal('GET', 'accounts/'.$res->json('data.account_id'))->assertOk();
    expect($detail->json('data.companies.0.admins.0.status'))->toBe('invited')
        ->and($detail->json('data.timeline.0.action'))->toBe('account_created');

    // Acepta la invitación: crea su contraseña y el primer ingreso le pide configurar MFA.
    $url = null;
    Mail::assertSent(AccountInvitationMail::class, function (AccountInvitationMail $m) use (&$url, $payload) {
        $url = $m->url;

        return $m->hasTo($payload['admin_email']);
    });
    $token = basename($url);

    $this->getJson("/api/v1/auth/invitations/{$token}")->assertOk()->assertJsonPath('data.company_name', 'Minera Prueba');
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => 'corta', 'password_confirmation' => 'corta'])->assertStatus(422);
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => 'ClaveSegura2026', 'password_confirmation' => 'ClaveSegura2026'])
        ->assertOk()->assertJsonPath('data.email', $payload['admin_email']);

    $this->postJson('/api/v1/auth/login', ['email' => $payload['admin_email'], 'password' => 'ClaveSegura2026'])
        ->assertOk()->assertJsonPath('mfa_setup_required', true);

    // El enlace sirve una sola vez.
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertStatus(410);
});

it('valida el RUC y no repite empresas', function () {
    internal('POST', 'accounts', newAccountPayload(['ruc' => '20518472631']))
        ->assertStatus(422)->assertJsonPath('errors.ruc.0', 'El RUC no es válido: revisa los 11 dígitos.');
    internal('POST', 'accounts', newAccountPayload(['ruc' => '20518472632']))
        ->assertStatus(422)->assertJsonPath('errors.ruc.0', 'Ya existe una empresa con este RUC.');
    internal('POST', 'accounts', newAccountPayload(['start' => 'active', 'plan' => null]))
        ->assertStatus(422)->assertJsonPath('errors.plan.0', 'Elige el plan contratado.');
});

it('crea una cuenta contratada con el plan elegido y supervisor SST si tiene menos de 20 trabajadores', function () {
    Mail::fake();
    $res = internal('POST', 'accounts', newAccountPayload(['start' => 'active', 'plan' => 'E', 'headcount' => 12, 'billing_period' => 'annual']))->assertCreated();
    $companyId = $res->json('data.company_id');

    expect(subscriptionOf($companyId)->status)->toBe('active')->and(subscriptionOf($companyId)->plan)->toBe('E');
    expect(DB::connection('platform')->selectOne('select sst_model from companies where id = ?', [$companyId])->sst_model)->toBe('supervisor');
});

it('bajar de plan deja en solo lectura los módulos que salen y subir los habilita', function () {
    $sub = subscriptionOf(ANDINAS_ID);

    internal('POST', "subscriptions/{$sub->id}/change-plan", ['plan' => 'P'])->assertStatus(422);
    internal('POST', "subscriptions/{$sub->id}/change-plan", ['plan' => 'E', 'reason' => 'Pedido del cliente'])
        ->assertOk()->assertJsonPath('data.direction', 'downgrade');
    expect(flagMode(ANDINAS_ID, 'MRL'))->toBe('read_only');

    internal('POST', "subscriptions/{$sub->id}/change-plan", ['plan' => 'C'])->assertOk()->assertJsonPath('data.direction', 'upgrade');
    expect(flagMode(ANDINAS_ID, 'MRL'))->toBe('enabled')
        ->and(flagMode(ANDINAS_ID, 'PTR'))->toBe('enabled');
});

it('suspender deja toda la empresa en solo lectura y reactivar la devuelve', function () {
    $sub = subscriptionOf(AGRO_ID);

    internal('POST', "subscriptions/{$sub->id}/suspend", [])->assertStatus(422);
    internal('POST', "subscriptions/{$sub->id}/suspend", ['reason' => 'Pago vencido'])->assertNoContent();
    expect(subscriptionOf(AGRO_ID)->status)->toBe('read_only')
        ->and(flagMode(AGRO_ID, 'IPC'))->toBe('read_only');

    internal('POST', "subscriptions/{$sub->id}/change-plan", ['plan' => 'P'])->assertStatus(409);
    internal('POST', "subscriptions/{$sub->id}/suspend", ['reason' => 'Otra vez'])->assertStatus(409);

    internal('POST', "subscriptions/{$sub->id}/reactivate")->assertNoContent();
    expect(subscriptionOf(AGRO_ID)->status)->toBe('active')
        ->and(flagMode(AGRO_ID, 'IPC'))->toBe('enabled');
});

it('agrega y quita complementos; en prueba no se permiten', function () {
    $sub = subscriptionOf(ANDINAS_ID);

    internal('POST', "subscriptions/{$sub->id}/addons", ['addons' => []])->assertStatus(422);
    internal('POST', "subscriptions/{$sub->id}/addons", ['addons' => ['IAA', 'ISO']])
        ->assertOk()->assertJsonCount(2, 'data.added');
    expect(flagMode(ANDINAS_ID, 'IAA'))->toBe('enabled')
        ->and(flagMode(ANDINAS_ID, 'ISO'))->toBe('enabled');
    // Todo o nada: si uno ya lo tiene, no agrega ninguno.
    internal('POST', "subscriptions/{$sub->id}/addons", ['addons' => ['AMB', 'IAA']])->assertStatus(422);
    expect(flagMode(ANDINAS_ID, 'AMB'))->toBe('locked');

    internal('DELETE', "subscriptions/{$sub->id}/addons/IAA")->assertNoContent();
    expect(flagMode(ANDINAS_ID, 'IAA'))->toBe('read_only');   // nada se borra
    internal('DELETE', "subscriptions/{$sub->id}/addons/IAA")->assertStatus(404);

    Mail::fake();
    $trial = internal('POST', 'accounts', newAccountPayload())->assertCreated()->json('data.subscription_id');
    internal('POST', "subscriptions/{$trial}/addons", ['addons' => ['IAA']])->assertStatus(409);
});

it('rechaza enlaces de invitación inválidos', function () {
    $this->getJson('/api/v1/auth/invitations/'.str_repeat('x', 48))->assertStatus(410)->assertJsonPath('type', 'invitation_invalid');
});

/** Fija el fin del periodo de la suscripción de AgroExport (conexión platform, fuera de la transacción de la prueba). */
function setAgroPeriod(string $endSql, string $status = 'active', ?string $graceSql = null): string
{
    $sub = subscriptionOf(AGRO_ID);
    DB::connection('platform')->update(
        "update subscriptions set status = ?, current_period_end = {$endSql}, grace_until = ".($graceSql ?? 'null').' where id = ?',
        [$status, $sub->id]
    );

    return $sub->id;
}

function esencialNotifications(): array
{
    return test()->getJson('/api/v1/me/notifications', authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID))->assertOk()->json();
}

it('avisa 3 días antes por correo y en el sistema, una sola vez', function () {
    Mail::fake();
    setAgroPeriod("now() + interval '3 days'");

    $first = app(RenewalService::class)->run();
    expect($first['reminders'])->toBeGreaterThanOrEqual(1);
    Mail::assertSent(SubscriptionNoticeMail::class, fn ($m) => $m->hasTo(ESENCIAL_EMAIL) && str_contains($m->title, 'vence en 3 días'));

    $n = esencialNotifications();
    expect($n['unread'])->toBe(1)
        ->and($n['data'][0]['event_code'])->toBe('subscription.reminder');

    // El banner del cliente sabe cuántos días quedan.
    $this->getJson('/api/v1/me/subscription', authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID))
        ->assertOk()->assertJsonPath('data.days_left', 3)->assertJsonPath('data.billing_contact', true);

    // Otra corrida el mismo día no repite el aviso.
    expect(app(RenewalService::class)->run()['reminders'])->toBe(0);
    expect(esencialNotifications()['unread'])->toBe(1);

    $id = esencialNotifications()['data'][0]['id'];
    $this->postJson("/api/v1/me/notifications/{$id}/read", [], authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID))->assertNoContent();
    expect(esencialNotifications()['unread'])->toBe(0);
});

it('al vencer pasa a pago pendiente con días de gracia y luego a solo lectura', function () {
    Mail::fake();
    setAgroPeriod("now() - interval '1 hour'");

    expect(app(RenewalService::class)->run()['past_due'])->toBe(1);
    $sub = DB::connection('platform')->selectOne('select status, grace_until, current_period_end from subscriptions where id = ?', [subscriptionOf(AGRO_ID)->id]);
    expect($sub->status)->toBe('past_due')
        ->and($sub->grace_until)->not->toBeNull()
        ->and(flagMode(AGRO_ID, 'IPC'))->toBe('enabled');   // durante la gracia sigue trabajando

    // Termina la gracia sin pago.
    setAgroPeriod("now() - interval '5 days'", 'past_due', "now() - interval '1 hour'");
    expect(app(RenewalService::class)->run()['blocked'])->toBe(1);
    expect(subscriptionOf(AGRO_ID)->status)->toBe('read_only')
        ->and(flagMode(AGRO_ID, 'IPC'))->toBe('read_only');
    Mail::assertSent(SubscriptionNoticeMail::class, fn ($m) => str_contains($m->title, 'solo lectura'));
});

it('registrar el pago desbloquea al momento y extiende el periodo', function () {
    Mail::fake();
    $subId = setAgroPeriod("now() - interval '5 days'", 'read_only');
    DB::connection('platform')->select('select refresh_feature_flags(?)', [AGRO_ID]);
    expect(flagMode(AGRO_ID, 'IPC'))->toBe('read_only');

    internal('POST', "subscriptions/{$subId}/payments", ['amount' => 0, 'method' => 'yape', 'paid_at' => now()->toDateString(), 'periods' => 1])
        ->assertStatus(422)->assertJsonPath('errors.amount.0', 'El monto debe ser mayor que cero.');
    internal('POST', "subscriptions/{$subId}/payments", ['amount' => 10, 'method' => 'yape', 'paid_at' => now()->addDays(3)->toDateString(), 'periods' => 1])
        ->assertStatus(422);

    internal('POST', "subscriptions/{$subId}/payments", [
        'amount' => 250.5, 'method' => 'transfer', 'paid_at' => now()->toDateString(), 'reference' => 'OP-123', 'periods' => 1,
    ])->assertCreated()->assertJsonPath('data.status', 'active');

    expect(subscriptionOf(AGRO_ID)->status)->toBe('active')
        ->and(flagMode(AGRO_ID, 'IPC'))->toBe('enabled');
    $end = DB::connection('platform')->selectOne("select current_period_end > now() + interval '27 days' as ok from subscriptions where id = ?", [$subId]);
    expect($end->ok)->toBeTrue();

    $agro = collect(internal('GET', 'accounts/'.DEMO_ACCOUNT_ID)->json('data.companies'))->firstWhere('id', AGRO_ID);
    expect($agro['payments'][0]['reference'])->toBe('OP-123')
        ->and($agro['payments'][0]['amount'])->toBe(250.5);
    Mail::assertSent(SubscriptionNoticeMail::class, fn ($m) => str_contains($m->title, 'Pago registrado'));

    // Aparece en Facturación con la factura SUNAT pendiente (sin proveedor OSE todavía).
    $billing = internal('GET', 'billing')->assertOk()->json('data');
    $row = collect($billing['payments'])->firstWhere('reference', 'OP-123');
    expect($row['sunat_status'])->toBe('pending')->and($row['company_name'])->toBe('AgroExport Valle de Chincha');
    $this->getJson('/api/v1/internal/billing', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertStatus(403);
});

it('pagar antes de vencer no hace perder días', function () {
    Mail::fake();
    $subId = setAgroPeriod("now() + interval '10 days'");
    $before = DB::connection('platform')->selectOne('select current_period_end from subscriptions where id = ?', [$subId])->current_period_end;

    internal('POST', "subscriptions/{$subId}/payments", ['amount' => 100, 'method' => 'plin', 'paid_at' => now()->toDateString(), 'periods' => 2])->assertCreated();

    $months = DB::connection('platform')->selectOne(
        "select current_period_end = (?::timestamptz + interval '2 months') as ok from subscriptions where id = ?", [$before, $subId]
    );
    expect($months->ok)->toBeTrue();
});

it('muestra métricas agregadas solo al equipo de la plataforma', function () {
    $m = internal('GET', 'metrics')->assertOk()->json('data');

    expect($m['companies'])->toBeGreaterThanOrEqual(3)
        ->and($m['by_plan'])->toHaveCount(3)
        ->and($m['months'])->toHaveCount(6)
        ->and($m['trials'])->toHaveKeys(['started', 'active', 'expired', 'converted', 'list'])
        ->and(collect($m['modules'])->pluck('code'))->toContain('IPC');

    $this->getJson('/api/v1/internal/metrics', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertStatus(403);
});

it('«Prueba gratis» del sitio: la empresa se registra sola con su RUC, una prueba por RUC', function () {
    Mail::fake();
    $ruc = randomRuc();
    $email = 'auto.'.bin2hex(random_bytes(4)).'@prueba.test';
    $form = ['ruc' => $ruc, 'legal_name' => 'Constructora Autoservicio S.A.C.', 'headcount' => 12, 'full_name' => 'Ana Registro', 'email' => $email, 'accept_legal' => true];

    // Sin aceptar Términos y Privacidad no avanza; RUC con dígito verificador incorrecto tampoco.
    $this->postJson('/api/v1/public/trial', ['accept_legal' => false] + $form)->assertStatus(422)->assertJsonValidationErrors('accept_legal');
    $this->postJson('/api/v1/public/trial', ['ruc' => '20123456781'] + $form)->assertStatus(422)->assertJsonValidationErrors('ruc');

    $this->postJson('/api/v1/public/trial', $form)->assertCreated()->assertJsonPath('data.email', $email);
    $company = DB::connection('platform')->selectOne('select id, sst_model from companies where ruc = ? and deleted_at is null', [$ruc]);
    expect($company->sst_model)->toBe('supervisor')
        ->and(subscriptionOf($company->id)->status)->toBe('trialing')
        ->and(subscriptionOf($company->id)->plan)->toBe('P');
    Mail::assertSent(AccountInvitationMail::class, fn ($m) => $m->hasTo($email));
    $log = DB::connection('platform')->selectOne("select actor_type, changes->>'source' as source from audit_logs where action = 'account_created' and entity_id = (select account_id from companies where id = ?)", [$company->id]);
    expect($log->actor_type)->toBe('system')->and($log->source)->toBe('self_signup');

    // El mismo RUC no se registra dos veces, ni un correo que ya tiene cuenta.
    $this->postJson('/api/v1/public/trial', ['email' => 'otro.'.bin2hex(random_bytes(3)).'@prueba.test'] + $form)->assertStatus(422)->assertJsonValidationErrors('ruc');
    $this->postJson('/api/v1/public/trial', ['ruc' => randomRuc()] + $form)->assertStatus(422)->assertJsonValidationErrors('email');

    // Campo trampa lleno (bot): responde igual pero no crea nada.
    $botRuc = randomRuc();
    $this->postJson('/api/v1/public/trial', ['ruc' => $botRuc, 'email' => 'bot@prueba.test', 'website' => 'http://spam'] + $form)->assertCreated();
    expect(DB::connection('platform')->selectOne('select 1 from companies where ruc = ?', [$botRuc]))->toBeNull();
});
