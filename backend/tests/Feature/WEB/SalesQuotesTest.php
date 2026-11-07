<?php

// Cotizaciones con pago manual: enviar → el cliente acepta → sube su constancia → el equipo confirma → se crea la cuenta.
// La conexión 'platform' no entra en la transacción de la prueba: lo creado se da de baja lógica al final.

use App\Modules\SUS\Services\AccountsService;
use App\Modules\USR\Mail\AccountInvitationMail;
use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** RUC 20xxxxxxxxx con dígito verificador correcto (módulo 11). */
function quoteRuc(): string
{
    $base = '20'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $sum = 0;
    foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $w) {
        $sum += (int) $base[$i] * $w;
    }
    $check = 11 - ($sum % 11);

    return $base.($check === 10 ? 0 : ($check === 11 ? 1 : $check));
}

function quoteApi(string $method, string $uri, array $data = [])
{
    return test()->json($method, '/api/v1/internal/site/'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

function quotePayload(array $companies, array $overrides = []): array
{
    return $overrides + [
        'contact_name' => 'Rosa Compras Prueba',
        'contact_email' => 'rosa.'.bin2hex(random_bytes(4)).'@prueba.test',
        'billing_period' => 'monthly',
        'companies' => $companies,
        'discount_amount' => 0,
        'client_notes' => 'Precios sujetos a la vigencia indicada.',
    ];
}

function quoteCompany(array $o = []): array
{
    return $o + ['ruc' => quoteRuc(), 'legal_name' => 'Constructora Prueba S.A.C.', 'trade_name' => '', 'headcount' => 40, 'economic_sector' => 'construction', 'plan_code' => 'P', 'addon_codes' => []];
}

/** Envía la cotización y devuelve el token del enlace que le llegó al cliente. */
function sendQuote(string $id): string
{
    quoteApi('POST', "quotes/{$id}/send")->assertNoContent();
    $mail = Mail::sent(SiteRequestMail::class)->last();
    expect($mail->attachment)->not->toBeNull();
    preg_match('#/cotizacion/([A-Za-z0-9]{48})$#', $mail->buttonUrl, $m);

    return $m[1];
}

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    Storage::fake(config('escudo.site_assets_disk', 'local'));
    $db = DB::connection('platform');
    $this->started = $db->selectOne("select now() - interval '1 second' as t")->t;
    $this->prices = $db->select('select id, price_reference from plans');
    $this->addonPrices = $db->select('select id, price_reference from addons');
    $this->settings = $db->selectOne('select payment_instructions, sales_email from site_settings where singleton');
    $db->update("update plans set price_reference = case trim(code) when 'E' then 100 when 'P' then 500 when 'C' then 1500 else price_reference end");
    $db->update('update addons set price_reference = 80');
    $db->update("update site_settings set payment_instructions = 'BCP Cuenta corriente 000-0000000-0-00', sales_email = 'ventas@prueba.test' where singleton");
    $this->addon = trim($db->selectOne('select code from addons where is_active and deleted_at is null order by code limit 1')->code);
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    $db->transaction(function () use ($db) {
        foreach ($this->prices as $p) {
            $db->update('update plans set price_reference = ? where id = ?', [$p->price_reference, $p->id]);
        }
        foreach ($this->addonPrices as $a) {
            $db->update('update addons set price_reference = ? where id = ?', [$a->price_reference, $a->id]);
        }
        $db->update('update site_settings set payment_instructions = ?, sales_email = ? where singleton', [$this->settings->payment_instructions, $this->settings->sales_email]);
        $db->update('update sales_quotes set deleted_at = now(), token_hash = null where created_at >= ? and deleted_at is null', [$this->started]);
        $db->update('update subscription_addons set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $db->update('update payments set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $db->update('update invoices set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $db->update('update subscriptions set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $db->update('update companies set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $db->update('update accounts set deleted_at = now() where created_at >= ? and deleted_at is null', [$this->started]);
        $db->update("update users set deleted_at = now() where created_at >= ? and email like '%@prueba.test' and deleted_at is null", [$this->started]);
    });
});

it('solo el equipo de la plataforma maneja cotizaciones', function () {
    $this->getJson('/api/v1/internal/site/quotes', authHeaders(tokenFor(PROFESIONAL_EMAIL)))->assertStatus(403);
});

it('calcula el grupo por empresa, el periodo anual y el descuento', function () {
    $data = quoteApi('POST', 'quotes/preview', quotePayload([
        quoteCompany(['plan_code' => 'P', 'addon_codes' => [$this->addon]]),
        quoteCompany(['plan_code' => 'E']),
    ], ['discount_amount' => 80]))->assertOk()->json('data');

    expect((float) $data['companies'][0]['amount'])->toBe(580.0)
        ->and((float) $data['companies'][1]['amount'])->toBe(100.0)
        ->and((float) $data['subtotal'])->toBe(680.0)
        ->and((float) $data['total'])->toBe(600.0);

    $annual = quoteApi('POST', 'quotes/preview', quotePayload([quoteCompany(['plan_code' => 'E'])], ['billing_period' => 'annual']))->json('data');
    expect((float) $annual['total'])->toBe(round(100 * 12 * (1 - $annual['annual_discount_pct'] / 100), 2));
});

it('grupo nuevo: envía, el cliente acepta y paga, y al confirmar se crea la cuenta con sus empresas', function () {
    $c1 = quoteCompany(['plan_code' => 'P', 'addon_codes' => [$this->addon]]);
    $c2 = quoteCompany(['plan_code' => 'E', 'legal_name' => 'Inmobiliaria Prueba S.A.C.', 'headcount' => 12]);
    $id = quoteApi('POST', 'quotes', quotePayload([$c1, $c2]))->assertCreated()->json('data.id');
    $token = sendQuote($id);

    // El cliente ve su cotización; aún no los datos de pago.
    $view = $this->getJson("/api/v1/public/quotes/{$token}")->assertOk()->json('data');
    expect($view['status'])->toBe('sent')->and($view['payment_instructions'])->toBeNull()->and((float) $view['total'])->toBe(680.0);
    $this->get("/api/v1/public/quotes/{$token}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Acepta: ve los datos de pago y al equipo le llega el aviso.
    $this->postJson("/api/v1/public/quotes/{$token}/accept", [])->assertStatus(422);
    $this->postJson("/api/v1/public/quotes/{$token}/accept", ['accept' => true])->assertNoContent();
    expect($this->getJson("/api/v1/public/quotes/{$token}")->json('data.payment_instructions'))->toContain('BCP');
    Mail::assertSent(SiteRequestMail::class, fn ($m) => $m->hasTo('ventas@prueba.test') && str_contains($m->title, 'aceptada'));

    // Sube la constancia.
    $this->post("/api/v1/public/quotes/{$token}/payment", [
        'method' => 'transfer', 'paid_on' => now()->toDateString(), 'amount' => '680', 'reference' => 'OP-123',
        'file' => UploadedFile::fake()->create('constancia.pdf', 120, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertNoContent();
    expect(quoteApi('GET', "quotes/{$id}")->json('data.status'))->toBe('payment_submitted');
    $this->get("/api/v1/internal/site/quotes/{$id}/file/evidence", authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk();

    // El equipo confirma: cuenta de grupo con 2 empresas, cada una con su suscripción activa y su pago.
    $accountId = quoteApi('POST', "quotes/{$id}/confirm-payment", ['paid_on' => now()->toDateString(), 'amount' => 680, 'method' => 'transfer', 'reference' => 'OP-123'])
        ->assertOk()->json('data.account_id');
    $db = DB::connection('platform');
    expect($db->selectOne('select account_type from accounts where id = ?', [$accountId])->account_type)->toBe('group');
    $subs = $db->select(
        "select c.ruc, s.status, trim(p.code) as plan, s.current_period_end > now() + interval '25 days' as open,
                (select sum(amount) from payments where invoice_id in (select id from invoices where subscription_id = s.id)) as paid,
                (select count(*) from subscription_addons sa where sa.subscription_id = s.id and sa.status = 'active') as addons
           from companies c join subscriptions s on s.company_id = c.id join plans p on p.id = s.plan_id
          where c.account_id = ? order by p.code desc",
        [$accountId]
    );
    expect($subs)->toHaveCount(2)
        ->and(collect($subs)->pluck('status')->unique()->all())->toBe(['active'])
        ->and(collect($subs)->every(fn ($s) => $s->open))->toBeTrue()
        ->and((float) collect($subs)->sum('paid'))->toBe(680.0);
    $p = collect($subs)->firstWhere('plan', 'P');
    expect((float) $p->paid)->toBe(580.0)->and((int) $p->addons)->toBe(1);

    // El administrador tiene acceso a las dos empresas y recibe la invitación para crear su contraseña.
    Mail::assertSent(AccountInvitationMail::class);
    Mail::assertNotSent(\App\Modules\SUS\Mail\SubscriptionNoticeMail::class);   // un solo correo de confirmación, no uno por empresa
    Mail::assertSent(SiteRequestMail::class, fn ($m) => str_contains($m->title, 'Pago confirmado'));
    $companies = $db->select('select id from companies where account_id = ?', [$accountId]);
    foreach ($companies as $c) {
        expect(app(AccountsService::class)->companyAdmins($c->id))->toHaveCount(1);
    }
    expect(quoteApi('GET', "quotes/{$id}")->json('data.status'))->toBe('paid');
    $this->getJson("/api/v1/public/quotes/{$token}")->assertStatus(404);   // el enlace se cierra
});

it('si la empresa ya hizo la prueba gratis, el pago activa esa misma cuenta con el plan cotizado', function () {
    $ruc = quoteRuc();
    $trial = app(AccountsService::class)->create([
        'account_type' => 'direct', 'ruc' => $ruc, 'legal_name' => 'Agrícola Prueba S.A.C.', 'headcount' => 25, 'start' => 'trial',
        'plan' => null, 'billing_period' => 'monthly', 'admin_name' => 'Ana Prueba', 'admin_email' => 'ana.'.bin2hex(random_bytes(3)).'@prueba.test',
    ], null);

    $id = quoteApi('POST', 'quotes', quotePayload([quoteCompany(['ruc' => $ruc, 'plan_code' => 'C'])]))->assertCreated()->json('data.id');
    $token = sendQuote($id);
    $this->postJson("/api/v1/public/quotes/{$token}/accept", ['accept' => true])->assertNoContent();
    Mail::fake();   // desde aquí solo cuentan los correos de la confirmación
    $accountId = quoteApi('POST', "quotes/{$id}/confirm-payment", ['paid_on' => now()->toDateString(), 'amount' => 1500, 'method' => 'yape'])->assertOk()->json('data.account_id');

    expect($accountId)->toBe($trial['account_id']);
    $sub = DB::connection('platform')->selectOne('select s.status, trim(p.code) as plan from subscriptions s join plans p on p.id = s.plan_id where s.id = ?', [$trial['subscription_id']]);
    expect($sub->status)->toBe('active')->and($sub->plan)->toBe('C');
    Mail::assertNotSent(AccountInvitationMail::class);   // ya tenía usuario: no se crea otra invitación
});

it('el equipo puede devolver la constancia y el cliente envía otra', function () {
    $id = quoteApi('POST', 'quotes', quotePayload([quoteCompany()]))->json('data.id');
    $token = sendQuote($id);
    $this->postJson("/api/v1/public/quotes/{$token}/accept", ['accept' => true])->assertNoContent();
    $this->post("/api/v1/public/quotes/{$token}/payment", [
        'method' => 'deposit', 'paid_on' => now()->toDateString(), 'amount' => '100', 'file' => UploadedFile::fake()->image('voucher.jpg'),
    ], ['Accept' => 'application/json'])->assertNoContent();

    quoteApi('POST', "quotes/{$id}/reject-evidence", ['reason' => 'El monto no coincide con el total.'])->assertNoContent();
    $mail = Mail::sent(SiteRequestMail::class)->last();
    preg_match('#/cotizacion/([A-Za-z0-9]{48})$#', $mail->buttonUrl, $m);
    $this->getJson("/api/v1/public/quotes/{$token}")->assertStatus(404);   // el enlace anterior ya no sirve
    $view = $this->getJson("/api/v1/public/quotes/{$m[1]}")->assertOk()->json('data');
    expect($view['status'])->toBe('accepted')->and($view['evidence_rejected_reason'])->toBe('El monto no coincide con el total.');
});

it('el cliente puede no aceptar y el equipo recibe el motivo', function () {
    $id = quoteApi('POST', 'quotes', quotePayload([quoteCompany()]))->json('data.id');
    $token = sendQuote($id);
    $this->postJson("/api/v1/public/quotes/{$token}/reject", ['reason' => 'Por ahora no tenemos presupuesto.'])->assertNoContent();
    expect(quoteApi('GET', "quotes/{$id}")->json('data'))->status->toBe('rejected')->reject_reason->toBe('Por ahora no tenemos presupuesto.');
    $this->postJson("/api/v1/public/quotes/{$token}/accept", ['accept' => true])->assertStatus(409);
});

it('no envía sin datos de pago ni sin precios, y no deja aceptar una cotización vencida', function () {
    $id = quoteApi('POST', 'quotes', quotePayload([quoteCompany()]))->json('data.id');
    DB::connection('platform')->update('update site_settings set payment_instructions = null where singleton');
    quoteApi('POST', "quotes/{$id}/send")->assertStatus(409);
    DB::connection('platform')->update("update site_settings set payment_instructions = 'BCP' where singleton");

    DB::connection('platform')->update("update plans set price_reference = null where trim(code) = 'P'");
    quoteApi('POST', "quotes/{$id}/send")->assertStatus(409);
    DB::connection('platform')->update("update plans set price_reference = 500 where trim(code) = 'P'");

    $token = sendQuote($id);
    DB::connection('platform')->update("update sales_quotes set valid_until = current_date - 1 where id = ?", [$id]);
    expect($this->getJson("/api/v1/public/quotes/{$token}")->json('data.expired'))->toBeTrue();
    $this->postJson("/api/v1/public/quotes/{$token}/accept", ['accept' => true])->assertStatus(409);
});

it('rechaza RUC inválidos, repetidos o de cuentas distintas', function () {
    quoteApi('POST', 'quotes', quotePayload([quoteCompany(['ruc' => '20123456789'])]))->assertStatus(422);
    $ruc = quoteRuc();
    quoteApi('POST', 'quotes', quotePayload([quoteCompany(['ruc' => $ruc]), quoteCompany(['ruc' => $ruc])]))->assertStatus(422);

    $make = fn () => app(AccountsService::class)->create([
        'account_type' => 'direct', 'ruc' => quoteRuc(), 'legal_name' => 'Empresa Prueba S.A.C.', 'headcount' => 10, 'start' => 'trial',
        'plan' => null, 'billing_period' => 'monthly', 'admin_name' => 'Prueba', 'admin_email' => 'p.'.bin2hex(random_bytes(3)).'@prueba.test',
    ], null);
    $a = $make();
    $b = $make();
    $rucA = DB::connection('platform')->selectOne('select ruc from companies where id = ?', [$a['company_id']])->ruc;
    $rucB = DB::connection('platform')->selectOne('select ruc from companies where id = ?', [$b['company_id']])->ruc;
    quoteApi('POST', 'quotes', quotePayload([quoteCompany(['ruc' => $rucA]), quoteCompany(['ruc' => $rucB])]))->assertStatus(422)
        ->assertJsonPath('errors.companies.0', fn ($m) => str_contains($m, 'cuentas distintas'));
});

it('prepara la cotización desde una solicitud del sitio con su plan y complementos', function () {
    $db = DB::connection('platform');
    $addonName = $db->selectOne('select name from addons where code = ?', [$this->addon])->name;
    $r = json_decode($db->selectOne("select public_create_demo_request(?::jsonb, null) as r", [json_encode([
        'request_type' => 'quote', 'full_name' => 'Luis Pedido Prueba', 'email' => 'luis@prueba.test', 'company_name' => 'Minera Pedido S.A.C.',
        'ruc' => null, 'headcount' => 80, 'interest_plan_code' => 'C', 'message' => "Complementos de interés: {$addonName}.\nQueremos empezar pronto.",
        'privacy_consent' => true, 'marketing_consent' => false,
    ])])->r, true);
    $pre = quoteApi('GET', "quotes/new?request={$r['id']}")->assertOk()->json('data');
    expect($pre['contact_name'])->toBe('Luis Pedido Prueba')
        ->and($pre['companies'][0]['plan_code'])->toBe('C')
        ->and($pre['companies'][0]['addon_codes'])->toBe([$this->addon]);
    $db->update('update demo_requests set deleted_at = now() where id = ?', [$r['id']]);
});
