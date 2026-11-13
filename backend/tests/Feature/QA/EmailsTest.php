<?php

// QA de correos: cada correo que envía la aplicación se dispara por su flujo real (API o servicio), y se revisa
// destinatario, asunto en español, y el cuerpo HTML y de texto ya armados: el enlace o dato clave está, y no quedan
// marcadores sin reemplazar, claves de traducción, «null», entidades HTML en el texto plano ni saludos repetidos.
// Lo escrito por la conexión 'platform' va en una transacción que se revierte (o se da de baja al final).

use App\Modules\COM\CommitteeNoticeMail;
use App\Modules\Consultora\ClientInvitationMail;
use App\Modules\NOT\AlertDelivery;
use App\Modules\NOT\AlertMail;
use App\Modules\NOT\SstAlerts;
use App\Modules\NOT\SstDigestMail;
use App\Modules\SUS\Mail\SubscriptionNoticeMail;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\SUS\Services\SubscriptionNotifier;
use App\Modules\USR\Mail\AccountInvitationMail;
use App\Modules\USR\Mail\MfaCodeMail;
use App\Modules\WEB\Mail\NewsletterIssueMail;
use App\Modules\WEB\Mail\SiteRequestMail;
use App\Modules\WEB\Services\DemoService;
use App\Modules\WEB\Services\WebinarsService;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

const QA_MAIL_CONSULTANCY = '01900000-0000-7000-8000-0000000c0a01';   // la misma cuenta consultora de ConsultancyPanelTest

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $GLOBALS['qa_mail_cleanup'] = [];
    Mail::fake();
    Http::fake();
    Storage::fake('local');
    Storage::fake(config('escudo.site_assets_disk', 'local'));
    $this->started = DB::connection('platform')->selectOne("select now() - interval '1 second' as t")->t;
});

afterEach(function () {
    // Primero se suelta la transacción de la prueba (sus bloqueos harían esperar a la limpieza por 'platform').
    DB::connection('pgsql')->rollBack(0);
    if (DB::connection('platform')->transactionLevel() > 0) {
        DB::connection('platform')->rollBack(0);
    }
    foreach (array_reverse($GLOBALS['qa_mail_cleanup']) as $fn) {
        $fn(DB::connection('platform'));
    }
    $GLOBALS['qa_mail_cleanup'] = [];
});

// ---------------------------------------------------------------- Apoyo

/** Lo que se haga por la conexión 'platform' desde aquí se revierte al final de la prueba. */
function qaMailPlatformTx(): void
{
    DB::connection('platform')->beginTransaction();
}

/** Limpieza (por 'platform', ya sin transacción) que corre al final de la prueba. */
function qaMailCleanup(callable $fn): void
{
    $GLOBALS['qa_mail_cleanup'][] = $fn;
}

/**
 * Correo de ventas del sitio guardado de verdad (lo leen funciones que corren por la conexión 'pgsql', que no ve
 * la transacción de 'platform'); se restaura al final. Llamar antes de qaMailPlatformTx().
 */
function qaMailSalesEmail(string $email = 'ventas@correo-qa.test'): string
{
    $db = DB::connection('platform');
    $prev = $db->selectOne('select sales_email from site_settings where singleton');
    $db->update('update site_settings set sales_email = ? where singleton', [$email]);
    qaMailCleanup(fn ($db) => $db->update('update site_settings set sales_email = ? where singleton', [$prev->sales_email]));

    return $email;
}

function qaMailCompanyName(string $companyId): string
{
    return DB::connection('platform')->selectOne('select coalesce(trade_name, legal_name) as n from companies where id = ?', [$companyId])->n;
}

/** El último correo de la clase enviado a $to (y que cumple $where). */
function qaMailSent(string $class, string $to, ?callable $where = null): Mailable
{
    $m = Mail::sent($class, fn ($m) => $m->hasTo($to) && (! $where || $where($m)))->last();
    expect($m)->not->toBeNull("No se envió {$class} a {$to}");

    return $m;
}

/** [html, texto] ya armados. */
function qaMailRender(Mailable $mail): array
{
    return (fn () => $this->renderForAssertions())->call($mail);
}

/**
 * Revisión común: destinatario, asunto en español con las palabras esperadas, cuerpo HTML y texto con lo que debe
 * verse y sin restos de plantilla. $mustSee se busca en ambos (en el HTML, escapado); $htmlOnly solo en el HTML.
 */
function qaMailCheck(Mailable $mail, string $to, array $subjectWords, array $mustSee, array $htmlOnly = []): array
{
    $mail->assertTo($to);
    $subject = (string) $mail->envelope()->subject;
    expect(trim($subject))->not->toBe('');
    foreach ($subjectWords as $w) {
        expect($subject)->toContain($w);
    }
    expect(preg_match('/\b(the|your|you|hello|dear|invitation|invite|password|reset|code|verify|verification|welcome|notice|alert|subscription|quote|booking|meeting|request|account|payment|reminder|issue)\b/i', $subject))
        ->toBe(0, "Asunto con palabras en inglés: {$subject}");
    expect($subject)->not->toMatch('/\{|\}|:[a-z_]+\b|\bnull\b|__\(/');

    [$html, $text] = qaMailRender($mail);
    expect($text)->not->toBe('', 'El correo no tiene versión de texto');
    foreach ($mustSee as $s) {
        $mail->assertSeeInHtml($s);
        $mail->assertSeeInText($s);
    }
    foreach ($htmlOnly as $s) {
        $mail->assertSeeInHtml($s);
    }

    foreach (['html' => $html, 'texto' => $text] as $kind => $body) {
        foreach (['{{', '}}', '{!!', '!!}', '@{{', '@if', '@else', '@endif', '@foreach', '@php', '{$', '__(', 'Undefined', 'Array'] as $bad) {
            expect(str_contains($body, $bad))->toBeFalse("Resto de plantilla «{$bad}» en el {$kind} de «{$subject}»");
        }
        $visible = $kind === 'html' ? html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $body;
        expect(preg_match('/\bnull\b/i', $visible))->toBe(0, "«null» en el {$kind} de «{$subject}»");
        expect(preg_match('/(?<![\w@\/.-])(mail|lang|validation|messages|passwords)\.[a-z_]+/', $visible))->toBe(0, "Clave de traducción en el {$kind} de «{$subject}»");
        expect(preg_match('/(?<!\w):(name|title|company|url|count|attribute|code)\b/', $visible))->toBe(0, "Marcador «:x» en el {$kind} de «{$subject}»");
        expect(preg_match('/\$[a-zA-Z_]/', $visible))->toBe(0, "Variable sin reemplazar en el {$kind} de «{$subject}»");
        expect(preg_match('/Hola, (hola|Hola)?:/u', $visible))->toBe(0, "Saludo repetido o vacío en el {$kind} de «{$subject}»");
        expect(preg_match('/\b(Hello|Dear|Click here|Regards|Unsubscribe|View in browser)\b/i', $visible))->toBe(0, "Texto en inglés en el {$kind} de «{$subject}»");
    }
    // El texto plano no lleva etiquetas ni entidades HTML (rompen los enlaces con «&» y las comillas).
    expect(preg_match('/<\/?[a-z][^>]*>/i', $text))->toBe(0, "Etiquetas HTML en el texto de «{$subject}»");
    expect(preg_match('/&(amp|lt|gt|quot|#0?39|#x27);/', $text))->toBe(0, "Entidades HTML en el texto de «{$subject}»");

    return [$html, $text];
}

/** Trabajador de AGRO (por la API) con fecha de ingreso. */
function qaMailWorker(array $h, string $doc, string $name): string
{
    $id = test()->postJson('/api/v1/records/TRB/workers', ['document_number' => $doc, 'first_name' => $name, 'paternal_last_name' => 'Correo'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    DB::update("update workers set hire_on = '2015-01-01', birth_on = '1990-01-01' where id = ?", [$id]);

    return $id;
}

/** Membresía de administrador de cuenta de $email en ANDINAS (dentro de la transacción de la prueba). */
function qaMailAndinasAdmin(string $email): void
{
    $user = DB::selectOne('select id from app_auth_lookup_user(?)', [$email])->id;
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at)
                select ?, ?, r.id, 'active', now() from roles r where r.account_id is null and r.code = 'account_admin'", [ANDINAS_ID, $user]);
    DB::select("select set_config('app.company_id', '', false)");
}

function qaMailRuc(): string
{
    $base = '20'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $sum = 0;
    foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $w) {
        $sum += (int) $base[$i] * $w;
    }
    $check = 11 - ($sum % 11);

    return $base.($check === 10 ? 0 : ($check === 11 ? 1 : $check));
}

// ---------------------------------------------------------------- Cuenta y acceso

it('invitación a la cuenta: persona nueva invitada por la empresa con su rol', function () {
    $email = 'qa.invitada.'.Str::lower(Str::random(8)).'@correo-qa.test';
    qaMailCleanup(fn ($db) => $db->update('update users set deleted_at = now() where lower(email) = ? and deleted_at is null', [$email]));
    $this->postJson('/api/v1/company/users', ['email' => $email, 'full_name' => 'Sara Supervisora Prueba', 'role_code' => 'sst_supervisor'],
        authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->assertCreated();

    $mail = qaMailSent(AccountInvitationMail::class, $email);
    expect($mail->url)->toContain('/invitacion/');
    $company = qaMailCompanyName(ANDINAS_ID);
    qaMailCheck($mail, $email, ['invitación', $company, 'Supervisor SST'], [$mail->url, $company, 'Supervisor SST', 'Sara Supervisora Prueba', "{$mail->hours} horas"], ['Crear mi contraseña']);
    $mail->assertDontSeeInHtml('administrador de cuenta');
});

it('invitación a la cuenta: quien ya tiene usuario solo recibe el aviso de acceso', function () {
    $this->postJson('/api/v1/company/users', ['email' => ESENCIAL_EMAIL, 'full_name' => 'Lucía Esencial', 'role_code' => 'worker'],
        authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->assertCreated()->assertJsonPath('data.status', 'active');

    $mail = qaMailSent(AccountInvitationMail::class, ESENCIAL_EMAIL);
    expect($mail->url)->toBeNull();
    $company = qaMailCompanyName(ANDINAS_ID);
    [$html] = qaMailCheck($mail, ESENCIAL_EMAIL, ['Ya tienes acceso', $company], [$company, 'Trabajador', 'contraseña de siempre']);
    expect($html)->not->toContain('/invitacion/');
});

it('invitación al administrador de cuenta: «Prueba gratis» del sitio', function () {
    $ruc = qaMailRuc();
    $email = 'qa.prueba.'.Str::lower(Str::random(8)).'@correo-qa.test';
    qaMailCleanup(function ($db) use ($ruc, $email) {
        $c = $db->selectOne('select id, account_id from companies where ruc = ? and deleted_at is null', [$ruc]);
        if ($c) {
            $db->update('update subscriptions set deleted_at = now() where company_id = ? and deleted_at is null', [$c->id]);
            $db->update('update companies set deleted_at = now() where id = ?', [$c->id]);
            $db->update('update accounts set deleted_at = now() where id = ? and deleted_at is null', [$c->account_id]);
        }
        $db->update('update users set deleted_at = now() where lower(email) = ? and deleted_at is null', [$email]);
    });
    $this->postJson('/api/v1/public/trial', ['ruc' => $ruc, 'legal_name' => 'Constructora Correo QA S.A.C.', 'headcount' => 12, 'full_name' => 'Ana Registro QA',
        'email' => $email, 'accept_legal' => true])->assertCreated();

    $mail = qaMailSent(AccountInvitationMail::class, $email);
    qaMailCheck($mail, $email, ['Te invitaron a administrar', 'Constructora Correo QA'], [$mail->url, 'administrador de cuenta', 'Constructora Correo QA', 'verificación en dos pasos'], ['Crear mi contraseña']);
});

it('código MFA por correo', function () {
    $c = $this->postJson('/api/v1/auth/login', ['email' => ESENCIAL_EMAIL, 'password' => DEMO_PASSWORD])->assertOk()->json();
    $this->postJson('/api/v1/auth/mfa/email', ['mfa_challenge_id' => $c['mfa_challenge_id']])->assertStatus(202);

    $mail = qaMailSent(MfaCodeMail::class, ESENCIAL_EMAIL);
    expect($mail->code)->toMatch('/^\d{6}$/');
    qaMailCheck($mail, ESENCIAL_EMAIL, [$mail->code, 'código de verificación'], [$mail->code, "{$mail->minutes} minutos", 'Lucía']);
});

it('restablecer contraseña («Olvidé mi contraseña»)', function () {
    qaMailPlatformTx();
    $this->postJson('/api/v1/auth/password/forgot', ['email' => ESENCIAL_EMAIL])->assertStatus(202);

    $mail = qaMailSent(SiteRequestMail::class, ESENCIAL_EMAIL, fn ($m) => $m->title === 'Crea una contraseña nueva');
    expect($mail->buttonUrl)->toContain('/restablecer/');
    qaMailCheck($mail, ESENCIAL_EMAIL, ['contraseña nueva'], [$mail->buttonUrl, 'Crear contraseña nueva', '60 minutos', 'Hola, Lucía']);
});

it('cambio de contraseña: aviso a la persona', function () {
    qaMailPlatformTx();
    $this->postJson('/api/v1/me/password', ['current_password' => DEMO_PASSWORD, 'password' => 'NuevaClaveQa2026', 'password_confirmation' => 'NuevaClaveQa2026'],
        authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertNoContent();

    $mail = qaMailSent(SiteRequestMail::class, ESENCIAL_EMAIL, fn ($m) => $m->title === 'Cambiaste tu contraseña');
    qaMailCheck($mail, ESENCIAL_EMAIL, ['Cambiaste tu contraseña'], ['cerramos tus otras sesiones', '¿Olvidaste tu contraseña?']);
});

it('cambio de correo: confirmación al correo nuevo y aviso al anterior', function () {
    qaMailPlatformTx();
    $nuevo = 'qa.nuevo.'.Str::lower(Str::random(6)).'@correo-qa.test';
    $this->postJson('/api/v1/me/email', ['email' => $nuevo, 'current_password' => DEMO_PASSWORD], authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertNoContent();

    $confirm = qaMailSent(SiteRequestMail::class, $nuevo);
    expect($confirm->buttonUrl)->toContain('/confirmar-correo/');
    qaMailCheck($confirm, $nuevo, ['Confirma tu correo nuevo'], [$confirm->buttonUrl, 'Confirmar mi correo', ESENCIAL_EMAIL]);

    $this->postJson('/api/v1/auth/email/confirm/'.basename($confirm->buttonUrl))->assertOk();
    $aviso = qaMailSent(SiteRequestMail::class, ESENCIAL_EMAIL, fn ($m) => $m->title === 'Tu correo de ingreso cambió');
    qaMailCheck($aviso, ESENCIAL_EMAIL, ['correo de ingreso cambió'], [$nuevo]);
});

it('invitación al equipo de la plataforma', function () {
    qaMailPlatformTx();
    $email = 'qa.soporte.'.Str::lower(Str::random(6)).'@correo-qa.test';
    $this->postJson('/api/v1/internal/team', ['full_name' => 'Ana Soporte QA', 'email' => $email, 'platform_role' => 'support'], authHeaders(tokenFor(ADMIN_EMAIL)))->assertCreated();

    $mail = qaMailSent(SiteRequestMail::class, $email);
    expect($mail->buttonUrl)->toContain('/restablecer/');
    qaMailCheck($mail, $email, ['Te invitaron al panel de EscudoSST'], [$mail->buttonUrl, 'Soporte de la plataforma', 'Crear mi contraseña', 'Hola, Ana Soporte QA:']);
});

it('avisos de acceso de soporte al administrador de cuenta', function () {
    qaMailAndinasAdmin(ESENCIAL_EMAIL);
    qaMailCleanup(fn ($db) => $db->update('update support_access_grants set deleted_at = now() where company_id = ? and created_at >= ? and deleted_at is null', [ANDINAS_ID, $this->started]));
    $this->postJson('/api/v1/internal/support-access', ['company_id' => ANDINAS_ID, 'reason' => 'Revisar un error al cargar trabajadores', 'ticket_reference' => 'TCK-QA', 'hours' => 8],
        authHeaders(tokenFor(ADMIN_EMAIL)))->assertCreated();

    $mail = qaMailSent(SubscriptionNoticeMail::class, ESENCIAL_EMAIL);
    qaMailCheck($mail, ESENCIAL_EMAIL, ['Soporte de EscudoSST pide acceso', 'EscudoSST'],
        [qaMailCompanyName(ANDINAS_ID), '8 horas', 'Revisar un error al cargar trabajadores', 'Nunca verá datos médicos', rtrim(config('escudo.frontend_url'), '/').'/login']);
});

// ---------------------------------------------------------------- Suscripción

it('avisos de suscripción', function (string $kind, array $data, array $subjectWords, array $mustSee) {
    $agro = DB::connection('platform')->selectOne(
        "select s.id, p.name as plan_name from subscriptions s join plans p on p.id = s.plan_id where s.company_id = ? and s.deleted_at is null order by s.started_at desc limit 1", [AGRO_ID]);
    $sub = (object) ['id' => $agro->id, 'company_id' => AGRO_ID, 'company_name' => qaMailCompanyName(AGRO_ID), 'plan_name' => $agro->plan_name,
        'billing_email' => 'facturacion@correo-qa.test'];
    expect(app(SubscriptionNotifier::class)->notify($sub, $kind, $data, 'qa.mail.'.$kind.':'.Str::random(6)))->toBeGreaterThanOrEqual(1);

    $subjectWords = array_map(fn ($w) => str_replace(':plan', $agro->plan_name, $w), $subjectWords);
    foreach ([ESENCIAL_EMAIL, 'facturacion@correo-qa.test'] as $to) {
        $mail = qaMailSent(SubscriptionNoticeMail::class, $to);
        qaMailCheck($mail, $to, $subjectWords, array_merge([$sub->company_name, rtrim(config('escudo.frontend_url'), '/').'/login', 'Tus registros nunca se borran'], $mustSee));
    }
})->with([
    'por vencer' => ['reminder', ['days' => 3, 'end' => '2026-12-15T17:00:00Z'], ['Tu plan :plan vence en 3 días'], ['15 de diciembre de 2026', 'Registra tu pago']],
    'vence hoy' => ['reminder', ['days' => 0, 'end' => '2026-12-15T17:00:00Z'], ['vence hoy'], ['15 de diciembre de 2026']],
    'prueba por terminar' => ['trial_reminder', ['days' => 1, 'end' => '2026-12-15T17:00:00Z'], ['Tu prueba gratuita termina en 1 día'], ['15 de diciembre de 2026', 'tus datos se conservan']],
    'vencida con gracia' => ['past_due', ['end' => '2026-12-15T17:00:00Z', 'grace_until' => '2026-12-22T17:00:00Z'], ['Tu suscripción venció'], ['15 de diciembre de 2026', '22 de diciembre de 2026', 'solo lectura']],
    'prueba terminada' => ['trial_expired', ['end' => '2026-12-15T17:00:00Z'], ['Terminó tu prueba gratuita'], ['15 de diciembre de 2026', 'Contrata un plan']],
    'bloqueada' => ['blocked', [], ['Tu cuenta quedó en solo lectura'], ['No se registró el pago']],
    'pago registrado' => ['paid', ['end' => '2027-12-15T17:00:00Z'], ['Pago registrado'], ['15 de diciembre de 2027', '¡Gracias!']],
]);

// ---------------------------------------------------------------- Avisos SST

it('resumen SST diario', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $id = $this->postJson('/api/v1/records/ACR/corrective_actions', ['source_type' => 'other', 'description' => 'x', 'title' => 'Reparar escalera QA',
        'responsible_user_id' => $me, 'due_on' => now('America/Lima')->toDateString()], $h)->json('data.id');
    DB::update('update corrective_actions set due_on = due_on - 5 where id = ?', [$id]);

    app(AccountsService::class)->withCompany(ANDINAS_ID, fn ($db) => app(SstAlerts::class)->forCompany($db, ANDINAS_ID, digest: true));

    $mail = qaMailSent(SstDigestMail::class, PROFESIONAL_EMAIL);
    $link = rtrim(config('escudo.frontend_url'), '/')."/m/acr?t=corrective_actions&open={$id}";
    $company = qaMailCompanyName(ANDINAS_ID);
    qaMailCheck($mail, PROFESIONAL_EMAIL, ['alerta(s) SST para hoy', $company], ['Acción correctiva vencida', $link, $company, 'Rocío'], ['Abrir el registro', 'Ir al inicio']);
});

it('aviso de alerta crítica por correo', function () {
    $uid = DB::connection('platform')->selectOne('select id from users where email = ?', [PROFESIONAL_EMAIL])->id;
    app(AccountsService::class)->withCompany(ANDINAS_ID, fn ($db) => $db->insert(
        "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
         values (?, 'acc.event_registered', 'sst_events', ?, ?, 'Accidente mortal registrado: EV-QA', 'Accidente en la Planta «Norte» & almacén.', '/m/acc?t=sst_events&open=qa', 'critical', ?)",
        [ANDINAS_ID, (string) Str::uuid(), $uid, 'qa.mail:'.Str::random(8)]));

    $sent = app(AccountsService::class)->withCompany(ANDINAS_ID, fn ($db) => app(AlertDelivery::class)->forCompany($db, ANDINAS_ID));
    expect($sent['email'])->toBeGreaterThanOrEqual(1);

    $mail = qaMailSent(AlertMail::class, PROFESIONAL_EMAIL, fn ($m) => $m->title === 'Accidente mortal registrado: EV-QA');
    $company = qaMailCompanyName(ANDINAS_ID);
    qaMailCheck($mail, PROFESIONAL_EMAIL, ['URGENTE: ', 'Accidente mortal registrado: EV-QA', $company],
        ['Accidente en la Planta «Norte» & almacén.', rtrim(config('escudo.frontend_url'), '/').'/m/acc?t=sst_events&open=qa', $company], ['Abrir en EscudoSST']);
});

it('convocatoria del comité', function () {
    $h = authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID);
    $a = qaMailWorker($h, '74100001', 'Luis');
    $b = qaMailWorker($h, '74100002', 'Marta');
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [ESENCIAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    DB::update('update workers set user_id = null where user_id = ?', [$uid]);
    DB::update('update workers set user_id = ? where id = ?', [$uid, $a]);

    $cm = $this->postJson('/api/v1/records/COM/committees', ['committee_type' => 'committee', 'name' => 'Comité SST QA',
        'period_start_on' => date('Y-m-d'), 'period_end_on' => date('Y-m-d', strtotime('+2 years'))], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/COM/committee_members', ['committee_id' => $cm, 'worker_id' => $a, 'representation' => 'employer', 'role_in_committee' => 'president',
        'starts_on' => date('Y-m-d')], $h)->assertCreated();
    $this->postJson('/api/v1/records/COM/committee_members', ['committee_id' => $cm, 'worker_id' => $b, 'representation' => 'workers', 'starts_on' => date('Y-m-d')], $h)->assertCreated();
    $meet = $this->postJson('/api/v1/records/COM/committee_meetings', ['committee_id' => $cm, 'meeting_type' => 'ordinary', 'scheduled_at' => now()->addDays(3)->toIso8601String(),
        'location' => 'Sala de directorio', 'agenda' => 'Revisión de accidentes & incidentes'], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/com/meetings/{$meet}/notify", [], $h)->assertOk()->assertJsonPath('data.emailed', 1);

    $mail = qaMailSent(CommitteeNoticeMail::class, ESENCIAL_EMAIL);
    $company = qaMailCompanyName(AGRO_ID);
    qaMailCheck($mail, ESENCIAL_EMAIL, ['Convocatoria: reunión ordinaria', 'Comité SST', $company],
        ['Sala de directorio', 'Revisión de accidentes & incidentes', rtrim(config('escudo.frontend_url'), '/').'/m/com', $company, 'Hola, Luis:']);
});

it('invitación de consultora a una empresa cliente', function () {
    $o = DB::connection('platform');
    $o->insert("insert into accounts (id, name, account_type) values (?, 'Prevencia SST', 'consultancy') on conflict (id) do update set name = excluded.name, account_type = 'consultancy'", [QA_MAIL_CONSULTANCY]);
    $o->update('update companies set managed_by_account_id = ? where id = ?', [QA_MAIL_CONSULTANCY, ANDINAS_ID]);
    qaMailCleanup(function ($db) {
        $db->update('update companies set managed_by_account_id = null where managed_by_account_id = ?', [QA_MAIL_CONSULTANCY]);
        $db->update('update client_invitations set deleted_at = now() where consultancy_account_id = ? and deleted_at is null', [QA_MAIL_CONSULTANCY]);
    });
    // El usuario corporativo entra a Andinas como consultor de la consultora (dentro de la transacción de la prueba).
    $user = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $m = DB::selectOne("insert into memberships (company_id, user_id, role_id, status, accepted_at)
                         select ?, ?, r.id, 'active', now() from roles r where r.account_id is null and r.code = 'consultant' returning id", [ANDINAS_ID, $user])->id;
    DB::insert('insert into consultant_assignments (company_id, consultancy_account_id, consultant_user_id, membership_id, is_lead) values (?, ?, ?, ?, true)',
        [ANDINAS_ID, QA_MAIL_CONSULTANCY, $user, $m]);
    DB::select("select set_config('app.company_id', '', false)");

    $this->postJson('/api/v1/con/invitations', ['ruc' => '20605478914', 'legal_name' => 'AgroExport', 'admin_email' => 'gerencia.qa@agro.test', 'suggested_plan' => 'P'],
        authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID))->assertCreated();

    $mail = qaMailSent(ClientInvitationMail::class, 'gerencia.qa@agro.test');
    qaMailCheck($mail, 'gerencia.qa@agro.test', ['Prevencia SST te invita a gestionar la SST de', 'en EscudoSST'],
        [$mail->url, 'Prevencia SST', 'RUC 20605478914', "{$mail->days} días", $mail->companyName], ['Ver la invitación']);
});

// ---------------------------------------------------------------- Sitio web: formularios

it('solicitud de demo, consulta y cotización del sitio: respuesta al visitante y aviso a ventas', function () {
    $ventas = qaMailSalesEmail();
    $base = ['full_name' => 'Carlos Visitante QA', 'company_name' => 'Agro Prueba QA', 'privacy_consent' => true];

    $this->postJson('/api/v1/public/demo-requests', $base + ['request_type' => 'demo', 'email' => 'demo.qa@correo-qa.test', 'headcount' => 40, 'interest_plan_code' => 'P'])->assertCreated();
    $mail = qaMailSent(SiteRequestMail::class, 'demo.qa@correo-qa.test');
    qaMailCheck($mail, 'demo.qa@correo-qa.test', ['Recibimos tu solicitud de demo'], ['Hola, Carlos:', $mail->buttonUrl, $mail->buttonText]);
    $team = qaMailSent(SiteRequestMail::class, $ventas, fn ($m) => str_starts_with($m->title, 'Nueva solicitud de demo'));
    qaMailCheck($team, $ventas, ['Nueva solicitud de demo: Agro Prueba QA'], ['Hola, equipo:', 'Carlos Visitante QA', 'demo.qa@correo-qa.test', 'Profesional', '/plataforma/sitio/solicitudes']);

    $this->postJson('/api/v1/public/demo-requests', $base + ['request_type' => 'contact', 'email' => 'consulta.qa@correo-qa.test', 'message' => '¿Tienen módulo de EPP?'])->assertCreated();
    qaMailCheck(qaMailSent(SiteRequestMail::class, 'consulta.qa@correo-qa.test'), 'consulta.qa@correo-qa.test', ['Recibimos tu consulta'], ['Te responderemos']);
    $team = qaMailSent(SiteRequestMail::class, $ventas, fn ($m) => str_starts_with($m->title, 'Nueva consulta'));
    qaMailCheck($team, $ventas, ['Nueva consulta: Agro Prueba QA'], ['¿Tienen módulo de EPP?']);

    $this->postJson('/api/v1/public/demo-requests', $base + ['request_type' => 'quote', 'email' => 'cotiza.qa@correo-qa.test', 'ruc' => '20100070970', 'interest_plan_code' => 'P', 'companies_count' => 3])->assertCreated();
    $mail = qaMailSent(SiteRequestMail::class, 'cotiza.qa@correo-qa.test');
    qaMailCheck($mail, 'cotiza.qa@correo-qa.test', ['Tu cotización de EscudoSST'], ['Plan Profesional']);
    $team = qaMailSent(SiteRequestMail::class, $ventas, fn ($m) => str_starts_with($m->title, 'Nuevo pedido de cotización'));
    qaMailCheck($team, $ventas, ['Nuevo pedido de cotización: Agro Prueba QA'], ['RUC 20100070970', 'Empresas del grupo: 3']);
});

it('reserva de demo: solicitud al visitante y aviso al anfitrión', function () {
    qaMailPlatformTx();
    demoSetup();
    $day = now('America/Lima')->addDays(3)->toDateString();
    $slot = $this->getJson("/api/v1/public/demo/slots?from={$day}&to={$day}")->assertOk()->json('data.slots.0.starts_at');
    $this->postJson('/api/v1/public/demo/book', bookingForm($slot, ['email' => 'ana.qa@visita.test']))->assertCreated();

    $mail = qaMailSent(SiteRequestMail::class, 'ana.qa@visita.test');
    expect($mail->ics[0])->toContain('BEGIN:VEVENT');
    qaMailCheck($mail, 'ana.qa@visita.test', ['Recibimos tu solicitud de demo'], ['Hola, Ana:', $mail->buttonUrl, 'Reprogramar o cancelar', '30 minutos', 'Asesora Comercial']);
    expect($mail->buttonUrl)->toContain('/demo/gestionar/');
    $host = qaMailSent(SiteRequestMail::class, 'asesora@escudo.test');
    qaMailCheck($host, 'asesora@escudo.test', ['Nueva demo agendada: Obras Ejemplo SAC'], ['Ana Visitante', 'ana.qa@visita.test', 'No autorizada', '/plataforma/sitio/agenda']);
});

it('reserva de demo: confirmación, recordatorio, cambio de horario y cancelación', function () {
    qaMailPlatformTx();
    demoSetup();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $day = now('America/Lima')->addDays(3)->toDateString();
    $slots = $this->getJson("/api/v1/public/demo/slots?from={$day}&to={$day}")->json('data.slots');
    $this->postJson('/api/v1/public/demo/book', bookingForm($slots[0]['starts_at'], ['email' => 'ana.qa@visita.test']))->assertCreated();
    $id = $this->getJson('/api/v1/internal/site/demos?from='.$day.'&to='.$day, $h)->assertOk()->json('data.meetings.0.id');

    $this->postJson("/api/v1/internal/site/demos/meetings/{$id}/confirm", ['meeting_provider' => 'meet', 'meeting_url' => 'https://meet.google.com/abc-defg-hij'], $h)->assertOk();
    $mail = qaMailSent(SiteRequestMail::class, 'ana.qa@visita.test', fn ($m) => $m->title === 'Tu demo está confirmada');
    qaMailCheck($mail, 'ana.qa@visita.test', ['Tu demo está confirmada'], ['https://meet.google.com/abc-defg-hij', 'Entrar a la reunión']);

    DB::connection('platform')->update("update demo_meetings set starts_at = now() + interval '23 hours 30 minutes', ends_at = now() + interval '24 hours' where id = ?", [$id]);
    expect(app(DemoService::class)->sendReminders())->toBe(1);
    qaMailCheck(qaMailSent(SiteRequestMail::class, 'ana.qa@visita.test', fn ($m) => $m->title === 'Mañana es tu demo'), 'ana.qa@visita.test', ['Mañana es tu demo'], ['https://meet.google.com/abc-defg-hij']);

    $this->postJson("/api/v1/internal/site/demos/meetings/{$id}/move", ['starts_at' => $slots[2]['starts_at']], $h)->assertOk();
    qaMailCheck(qaMailSent(SiteRequestMail::class, 'ana.qa@visita.test', fn ($m) => $m->title === 'Cambiamos el horario de tu demo'), 'ana.qa@visita.test',
        ['Cambiamos el horario de tu demo'], ['nuevo horario']);

    $this->postJson("/api/v1/internal/site/demos/meetings/{$id}/cancel", ['reason' => 'El anfitrión tuvo un imprevisto'], $h)->assertOk();
    $mail = qaMailSent(SiteRequestMail::class, 'ana.qa@visita.test', fn ($m) => $m->title === 'Tu demo fue cancelada');
    expect($mail->ics)->toBeNull();
    qaMailCheck($mail, 'ana.qa@visita.test', ['Tu demo fue cancelada'], ['Motivo: El anfitrión tuvo un imprevisto', 'Agendar otra demo', '/demo/agendar']);
});

it('cotización enviada desde el panel y aceptada por el cliente', function () {
    qaMailPlatformTx();
    $db = DB::connection('platform');
    $db->update("update plans set price_reference = case trim(code) when 'E' then 100 when 'P' then 500 when 'C' then 1500 else price_reference end");
    $db->update("update site_settings set payment_instructions = 'BCP Cuenta corriente 000-0000000-0-00', sales_email = 'ventas@correo-qa.test' where singleton");
    $email = 'rosa.'.bin2hex(random_bytes(4)).'@correo-qa.test';
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $id = $this->postJson('/api/v1/internal/site/quotes', ['contact_name' => 'Rosa Compras QA', 'contact_email' => $email, 'billing_period' => 'monthly', 'discount_amount' => 0,
        'client_notes' => 'Precios sujetos a la vigencia indicada.', 'companies' => [['ruc' => qaMailRuc(), 'legal_name' => 'Constructora Cotiza QA S.A.C.', 'trade_name' => '',
            'headcount' => 40, 'economic_sector' => 'construction', 'plan_code' => 'P', 'addon_codes' => []]]], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/internal/site/quotes/{$id}/send", [], $h)->assertNoContent();

    $mail = qaMailSent(SiteRequestMail::class, $email);
    expect($mail->attachment)->not->toBeNull()->and($mail->attachment[0])->toStartWith('%PDF');
    expect($mail->buttonUrl)->toMatch('#/cotizacion/[A-Za-z0-9]{48}$#');
    $number = DB::connection('platform')->selectOne('select number from sales_quotes where id = ?', [$id])->number;
    qaMailCheck($mail, $email, ['Cotización', $number], ['Hola, Rosa:', $mail->buttonUrl, 'adjunta en PDF']);

    $this->postJson('/api/v1/public/quotes/'.basename($mail->buttonUrl).'/accept', ['accept' => true])->assertNoContent();
    $client = qaMailSent(SiteRequestMail::class, $email, fn ($m) => str_starts_with($m->title, 'Aceptaste la cotización'));
    qaMailCheck($client, $email, ['Aceptaste la cotización', $number], ['datos para el pago']);
    $team = qaMailSent(SiteRequestMail::class, 'ventas@correo-qa.test', fn ($m) => str_contains($m->title, 'aceptada'));
    qaMailCheck($team, 'ventas@correo-qa.test', ['Cotización aceptada', $number], ['Rosa Compras QA', $email, 'S/', '/plataforma/sitio/cotizaciones/'.$id]);
});

it('boletín: confirmación de la suscripción, edición de prueba y número enviado', function () {
    qaMailPlatformTx();
    $email = 'qa.boletin.'.Str::lower(Str::random(6)).'@correo-qa.test';
    $this->postJson('/api/v1/public/newsletter', ['email' => $email, 'topics' => ['regulations'], 'privacy_consent' => true])->assertCreated();
    $confirm = qaMailSent(SiteRequestMail::class, $email);
    expect($confirm->buttonUrl)->toContain('/boletin/confirmar/');
    qaMailCheck($confirm, $email, ['Confirma tu suscripción al boletín'], ['Hola:', $confirm->buttonUrl, 'Confirmar mi correo', 'horas']);
    $this->postJson('/api/v1/public/newsletter/confirm', ['token' => basename($confirm->buttonUrl)])->assertJsonPath('data.result', 'confirmed');

    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $id = $this->postJson('/api/v1/internal/site/newsletter/issues', ['subject' => 'Cambios en el reglamento de SST QA', 'topics' => ['regulations'],
        'body' => "## Qué cambió\n\n- Nuevo plazo para el IPERC\n- Registros digitales & firmas"], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/internal/site/newsletter/issues/{$id}/test", [], $h)->assertOk();
    $test = qaMailSent(NewsletterIssueMail::class, ADMIN_EMAIL, fn ($m) => $m->isTest);
    qaMailCheck($test, ADMIN_EMAIL, ['[Prueba] Cambios en el reglamento de SST QA'], ['Nuevo plazo para el IPERC', 'Registros digitales & firmas']);

    $this->postJson("/api/v1/internal/site/newsletter/issues/{$id}/send", [], $h)->assertOk();
    $mail = qaMailSent(NewsletterIssueMail::class, $email, fn ($m) => ! $m->isTest);
    [$html, $text] = qaMailCheck($mail, $email, ['Cambios en el reglamento de SST QA'],
        ['Nuevo plazo para el IPERC', 'Registros digitales & firmas', $mail->webUrl, $mail->unsubscribeUrl, 'no reemplaza la lectura de la norma'],
        ['Qué cambió', 'BOLETÍN NORMATIVO SST', 'Darme de baja', 'Ver en el navegador']);
    expect($text)->toContain('QUÉ CAMBIÓ')->toContain('- Nuevo plazo para el IPERC')->toContain('Darme de baja: '.$mail->unsubscribeUrl);
    expect($html)->toContain('<h2')->toContain('<li');
    expect($mail->headers()->text['List-Unsubscribe'])->toContain('/public/newsletter/unsubscribe/');
});

it('estado del servicio: confirmación del aviso e incidente publicado', function () {
    qaMailPlatformTx();
    $email = 'qa.estado.'.Str::lower(Str::random(6)).'@correo-qa.test';
    $this->postJson('/api/v1/public/status/subscribe', ['email' => $email, 'privacy_consent' => true])->assertCreated();
    $confirm = qaMailSent(SiteRequestMail::class, $email);
    qaMailCheck($confirm, $email, ['Confirma los avisos de estado del servicio'], ['Hola:', $confirm->buttonUrl]);
    $this->postJson('/api/v1/public/newsletter/confirm', ['token' => basename($confirm->buttonUrl)])->assertJsonPath('data.result', 'confirmed');

    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $api = DB::connection('platform')->selectOne("select id from status_components where code = 'api'")->id;
    $id = $this->postJson('/api/v1/internal/site/status-incidents', ['title' => 'Lentitud al guardar registros QA', 'impact' => 'major', 'components' => [$api]], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/internal/site/status-incidents/{$id}/publish", ['message' => 'Estamos revisando la lentitud.'], $h)->assertOk();

    $mail = qaMailSent(NewsletterIssueMail::class, $email);
    [$html] = qaMailCheck($mail, $email, ['Incidente: Lentitud al guardar registros QA'], ['Estamos revisando la lentitud.', $mail->unsubscribeUrl], ['ESTADO DEL SERVICIO', 'pediste avisos del estado']);
    expect($html)->not->toContain('no reemplaza la lectura de la norma');
});

it('cambio de subencargados: aviso a los administradores de cuenta', function () {
    qaMailAndinasAdmin(ESENCIAL_EMAIL);
    qaMailPlatformTx();
    $this->postJson('/api/v1/internal/site/subprocessors/notify', ['summary' => 'Agregamos a Proveedor QA para el envío de correos.'], authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk();

    $mail = qaMailSent(SiteRequestMail::class, ESENCIAL_EMAIL);
    qaMailCheck($mail, ESENCIAL_EMAIL, ['Actualizamos la lista de subencargados'], ['Hola, Lucía:', 'Agregamos a Proveedor QA para el envío de correos.', 'Ver el centro de confianza', '/confianza']);
});

it('libro de reclamaciones: constancia al consumidor y aviso al encargado', function () {
    qaMailPlatformTx();
    DB::connection('platform')->update("update site_settings set complaints_officer_email = 'reclamos@correo-qa.test' where singleton");
    $r = $this->postJson('/api/v1/public/complaints', [
        'consumer_full_name' => 'Juana Consumidora Pérez', 'consumer_document_type' => 'DNI', 'consumer_document_number' => '45678912',
        'consumer_email' => 'juana.qa@correo-qa.test', 'consumer_address' => 'Av. Arequipa 123, Lima', 'item_type' => 'service',
        'item_description' => 'Suscripción plan Esencial', 'complaint_type' => 'claim', 'detail' => 'Me cobraron dos veces el mismo mes de la suscripción.',
        'consumer_request' => 'Devolución del cobro duplicado.', 'privacy_consent' => true,
    ])->assertCreated()->json('data');

    $mail = qaMailSent(SiteRequestMail::class, 'juana.qa@correo-qa.test');
    qaMailCheck($mail, 'juana.qa@correo-qa.test', ['Constancia de tu', $r['complaint_code']],
        ['Hola, Juana:', $r['complaint_code'], $r['tracking_key'], 'Detalle: Me cobraron dos veces', 'Pedido: Devolución del cobro duplicado.', $mail->buttonUrl]);
    expect($mail->buttonUrl)->toContain('?codigo='.$r['complaint_code']);
    $officer = qaMailSent(SiteRequestMail::class, 'reclamos@correo-qa.test');
    qaMailCheck($officer, 'reclamos@correo-qa.test', ['Nueva hoja del Libro de Reclamaciones', $r['complaint_code']], ['Atender en el panel', '/plataforma/sitio/reclamos']);
});

it('libro de reclamaciones: prórroga y respuesta por correo', function () {
    qaMailPlatformTx();
    $code = $this->postJson('/api/v1/public/complaints', [
        'consumer_full_name' => 'Juana Consumidora Pérez', 'consumer_document_type' => 'DNI', 'consumer_document_number' => '45678912',
        'consumer_email' => 'juana.qa@correo-qa.test', 'consumer_address' => 'Av. Arequipa 123, Lima', 'item_type' => 'service',
        'item_description' => 'Suscripción plan Esencial', 'complaint_type' => 'complaint', 'detail' => 'La atención por teléfono demoró demasiado.',
        'consumer_request' => 'Mejorar los tiempos.', 'privacy_consent' => true,
    ])->assertCreated()->json('data.complaint_code');
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $item = collect($this->getJson('/api/v1/internal/site/complaints', $h)->json('data'))->firstWhere('complaint_code', $code);
    $detail = $this->getJson("/api/v1/internal/site/complaints/{$item['id']}", $h)->json('data');
    $act = fn (array $b) => $this->postJson("/api/v1/internal/site/complaints/{$item['id']}/actions", $b, $h)->assertNoContent();
    $act(['action' => 'start']);
    $act(['action' => 'extend', 'text' => 'Revisión con el área de atención', 'until' => $detail['max_extension_on']]);
    $act(['action' => 'answer', 'text' => 'Reforzamos el equipo de atención telefónica.', 'channel' => 'email']);

    $ext = qaMailSent(SiteRequestMail::class, 'juana.qa@correo-qa.test', fn ($m) => str_starts_with($m->title, 'Necesitamos más tiempo'));
    qaMailCheck($ext, 'juana.qa@correo-qa.test', ['Necesitamos más tiempo para responder tu queja', $code],
        ['Motivo: Revisión con el área de atención', date('d/m/Y', strtotime($detail['max_extension_on']))]);
    $ans = qaMailSent(SiteRequestMail::class, 'juana.qa@correo-qa.test', fn ($m) => str_starts_with($m->title, 'Respuesta a tu'));
    qaMailCheck($ans, 'juana.qa@correo-qa.test', ['Respuesta a tu queja', $code], ['Reforzamos el equipo de atención telefónica.', 'respuesta formal']);
});

// ---------------------------------------------------------------- Sitio web: herramientas y programas

it('autodiagnóstico: resultado con el informe PDF', function () {
    qaMailPlatformTx();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $draft = collect($this->getJson('/api/v1/internal/site/self-assessment', $h)->json('data.versions'))->firstWhere('status', 'draft');
    $this->postJson("/api/v1/internal/site/self-assessment/{$draft['id']}/publish", [], $h)->assertOk();
    $q = $this->getJson('/api/v1/public/tools/self-assessment')->assertOk()->json('data');
    $ids = collect($q['sections'])->flatMap(fn ($s) => collect($s['questions'])->pluck('id'))->all();
    $answers = array_fill_keys($ids, 'yes');
    $answers[$ids[0]] = 'no';
    $r = $this->postJson('/api/v1/public/tools/self-assessment', ['questionnaire_id' => $draft['id'], 'full_name' => 'Rosa Prueba', 'email' => 'rosa.auto.qa@correo-qa.test',
        'company_name' => 'Obras Prueba QA', 'privacy_consent' => true, 'answers' => $answers])->assertOk()->json('data');

    $mail = qaMailSent(SiteRequestMail::class, 'rosa.auto.qa@correo-qa.test');
    expect($mail->buttonUrl)->toBe($r['report_url']);
    qaMailCheck($mail, 'rosa.auto.qa@correo-qa.test', ['Tu autodiagnóstico de la Ley 29783'],
        ['Hola, Rosa:', 'Obras Prueba QA', 'Puntaje total', $r['report_url'], 'Descargar mi informe (PDF)', 'Lo que te falta trabajar', 'No es una auditoría']);
});

it('calculadora de ahorro: resultado por correo', function () {
    qaMailPlatformTx();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->putJson('/api/v1/internal/site/savings-calculator', ['minutes_saved_per_record' => 10, 'manual_hours_reduction_pct' => 30, 'hourly_cost_pen' => 25,
        'disclaimer' => 'Estimación referencial, no garantizada.', 'to_validate' => true], $h)->assertOk();
    $this->postJson('/api/v1/internal/site/savings-calculator/publish', [], $h)->assertOk();
    $this->postJson('/api/v1/public/tools/savings-calculator', ['workers' => 50, 'sites' => 2, 'records_per_month' => 120, 'hours_per_month' => 40,
        'email' => 'calc.qa@correo-qa.test', 'full_name' => 'Carla Cálculo', 'privacy_consent' => true])->assertOk();

    $mail = qaMailSent(SiteRequestMail::class, 'calc.qa@correo-qa.test');
    qaMailCheck($mail, 'calc.qa@correo-qa.test', ['Tu estimación de ahorro con EscudoSST'],
        ['Hola, Carla:', '50 trabajadores', '32.0 h', 'S/ 800.00', 'Estimación referencial, no garantizada.', 'Agenda una demo']);
});

it('calculadora de multas: resultado por correo', function () {
    qaMailPlatformTx();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $sev = fn ($a, $b) => ['min' => $a, 'max' => $b];
    $rows = [
        ['workers_from' => 1, 'workers_to' => 10, 'minor' => $sev(0.1, 0.2), 'serious' => $sev(0.3, 0.5), 'very_serious' => $sev(0.6, 1)],
        ['workers_from' => 11, 'workers_to' => null, 'minor' => $sev(0.2, 0.4), 'serious' => $sev(0.5, 0.8), 'very_serious' => $sev(1, 2)],
    ];
    $tomorrow = now()->addDay()->toDateString();
    $this->postJson('/api/v1/internal/site/fine-calculator/micro', ['value' => ['rows' => $rows], 'valid_from' => $tomorrow, 'reason' => 'Prueba de tabla'], $h)->assertCreated();
    DB::connection('platform')->update("update legal_parameters set valid_from = current_date where key = 'web.fine_table_micro' and valid_from = ?", [$tomorrow]);
    DB::connection('platform')->update("update legal_parameters set valid_to = current_date - 1 where key = 'web.fine_table_micro' and valid_from < current_date");
    $this->postJson('/api/v1/public/tools/fine-calculator', ['company_size' => 'micro', 'affected_workers' => 3, 'severity' => 'minor', 'email' => 'luz.qa@correo-qa.test',
        'full_name' => 'Luz Prueba', 'privacy_consent' => true])->assertOk();

    $mail = qaMailSent(SiteRequestMail::class, 'luz.qa@correo-qa.test');
    qaMailCheck($mail, 'luz.qa@correo-qa.test', ['Tu cálculo de multa SUNAFIL'], ['Hola, Luz:', 'Microempresa', 'Leve', 'UIT', 'S/ ', 'no es asesoría legal', 'Ver cómo evitarlas']);
});

it('webinar: inscripción y recordatorio', function () {
    qaMailPlatformTx();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $id = $this->postJson('/api/v1/internal/site/webinars', ['title' => 'Webinar QA de correos', 'speaker_name' => 'Ana Ponente', 'starts_at' => now()->addHours(30)->toIso8601String(),
        'duration_minutes' => 60, 'meeting_provider' => 'meet', 'meeting_url' => 'https://meet.google.com/abc-defg-hij', 'capacity' => 10], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/internal/site/webinars/{$id}/publish", [], $h)->assertNoContent();
    $this->postJson('/api/v1/public/webinars/webinar-qa-de-correos/register', ['full_name' => 'Luis Inscrito', 'email' => 'luis.qa@correo-qa.test', 'privacy_consent' => true])->assertCreated();

    $mail = qaMailSent(SiteRequestMail::class, 'luis.qa@correo-qa.test');
    expect($mail->ics[0])->toContain('SUMMARY:Webinar QA de correos');
    qaMailCheck($mail, 'luis.qa@correo-qa.test', ['Estás inscrito: Webinar QA de correos'], ['Hola, Luis:', 'Ana Ponente', 'https://meet.google.com/abc-defg-hij', 'Entrar al webinar', '/webinars/gestionar/']);

    DB::connection('platform')->update("update webinars set starts_at = now() + interval '20 hours' where id = ?", [$id]);
    expect(app(WebinarsService::class)->sendReminders())->toBe(1);
    $rem = qaMailSent(SiteRequestMail::class, 'luis.qa@correo-qa.test', fn ($m) => str_starts_with($m->title, 'Recordatorio'));
    qaMailCheck($rem, 'luis.qa@correo-qa.test', ['Recordatorio: Webinar QA de correos'], ['Te recordamos que el webinar empieza', 'https://meet.google.com/abc-defg-hij']);
});

it('partners: solicitud recibida, aprobación y rechazo', function () {
    qaMailPlatformTx();
    $apply = fn (string $email, string $ruc) => $this->postJson('/api/v1/public/partners', ['partner_type' => 'consultancy', 'full_name' => 'Rosa Consultora', 'email' => $email,
        'company_name' => 'Consultora Rosa QA SAC', 'ruc' => $ruc, 'website' => 'https://consultora.test', 'privacy_consent' => true])->assertCreated();
    $apply('rosa.qa@consultora.test', '20100047218');
    $apply('beto.qa@consultora.test', qaMailRuc());

    qaMailCheck(qaMailSent(SiteRequestMail::class, 'rosa.qa@consultora.test'), 'rosa.qa@consultora.test', ['Recibimos tu solicitud de partner'],
        ['Hola, Rosa:', 'Consultora Rosa QA SAC', 'Consultora', 'código de referido']);

    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $apps = collect($this->getJson('/api/v1/internal/site/partners', $h)->assertOk()->json('data.applications'));
    $this->postJson("/api/v1/internal/site/partners/{$apps->firstWhere('email', 'rosa.qa@consultora.test')['id']}/review", ['approve' => true, 'code' => 'rosa-qa'], $h)->assertOk();
    $ok = qaMailSent(SiteRequestMail::class, 'rosa.qa@consultora.test', fn ($m) => str_starts_with($m->title, '¡Bienvenido'));
    qaMailCheck($ok, 'rosa.qa@consultora.test', ['¡Bienvenido al programa de partners!'], ['ROSA-QA', '/prueba-gratis?ref=ROSA-QA', 'Ver la página del programa']);

    $this->postJson("/api/v1/internal/site/partners/{$apps->firstWhere('email', 'beto.qa@consultora.test')['id']}/review", ['approve' => false, 'reason' => 'Aún no tienen cartera de clientes'], $h)->assertOk();
    $no = qaMailSent(SiteRequestMail::class, 'beto.qa@consultora.test', fn ($m) => $m->title === 'Sobre tu solicitud de partner');
    qaMailCheck($no, 'beto.qa@consultora.test', ['Sobre tu solicitud de partner'], ['Aún no tienen cartera de clientes', 'volver a postular']);
});

it('chatbot: aviso a ventas cuando el visitante deja sus datos', function () {
    qaMailPlatformTx();
    DB::connection('platform')->update("update site_settings set sales_email = 'ventas@correo-qa.test' where singleton");
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->patchJson('/api/v1/internal/site/chatbot/settings', ['enabled' => true, 'greeting' => 'Hola, ¿en qué te ayudo?', 'handoff_whatsapp' => true, 'ask_lead_after_messages' => 2], $h)->assertNoContent();
    $secret = bin2hex(random_bytes(32));
    $conv = $this->postJson('/api/v1/public/chatbot/start', ['session' => $secret, 'path' => '/planes'])->assertCreated()->json('data.conversation_id');
    $this->postJson('/api/v1/public/chatbot/lead', ['conversation_id' => $conv, 'session' => $secret, 'full_name' => 'Ana Chat', 'email' => 'ana.chat.qa@correo-qa.test',
        'company_name' => 'Chat Prueba QA', 'phone' => '987654321', 'privacy_consent' => true])->assertCreated();

    $mail = qaMailSent(SiteRequestMail::class, 'ventas@correo-qa.test');
    qaMailCheck($mail, 'ventas@correo-qa.test', ['Nuevo contacto desde el chatbot: Chat Prueba QA'], ['Hola, equipo:', 'Ana Chat', 'ana.chat.qa@correo-qa.test', '987654321', '/plataforma/sitio/chatbot?c='.$conv]);
});

it('casos de éxito: invitación, copia de la autorización, aviso al equipo y retiro', function () {
    qaMailPlatformTx();
    DB::connection('platform')->update("update site_settings set sales_email = 'ventas@correo-qa.test' where singleton");
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->postJson('/api/v1/internal/site/success-stories/invites', ['client_name' => 'Minera Correo QA SAC', 'sector' => 'mining', 'contact_name' => 'Rosa Prueba',
        'contact_email' => 'rosa.caso.qa@correo-qa.test'], $h)->assertCreated();
    $invite = qaMailSent(SiteRequestMail::class, 'rosa.caso.qa@correo-qa.test');
    expect($invite->buttonUrl)->toContain('/casos/compartir/');
    qaMailCheck($invite, 'rosa.caso.qa@correo-qa.test', ['Cuéntanos tu historia con EscudoSST'], ['Minera Correo QA SAC', 'El enlace vence', $invite->buttonUrl, 'Contar mi historia']);

    $img = imagecreatetruecolor(400, 140);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $ink = imagecolorallocate($img, 13, 59, 110);
    imagesetthickness($img, 3);
    for ($x = 20; $x < 380; $x += 18) {
        imageline($img, $x, 70 + (int) (30 * sin($x / 20)), $x + 18, 70 + (int) (30 * sin(($x + 18) / 20)), $ink);
    }
    ob_start();
    imagepng($img);
    $signature = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    $this->postJson('/api/v1/public/success-stories/share/'.basename($invite->buttonUrl), [
        'company_name' => 'Minera Correo QA SAC', 'company_ruc' => '20123456789', 'sector' => 'mining', 'project_year' => 2026,
        'challenge' => 'Teníamos los permisos de trabajo en papel y se perdían entre turnos.',
        'solution' => 'Pasamos los PETAR y las inspecciones a EscudoSST y los supervisores firman en el celular.',
        'results' => [['value' => '0', 'label' => 'permisos perdidos']], 'quote' => 'Ahora todo queda registrado.', 'modules' => ['PTR', 'INS'],
        'signer_name' => 'Rosa Prueba Quispe', 'signer_role' => 'Jefa de SST', 'signer_doc_type' => 'DNI', 'signer_doc_number' => '12345678',
        'signer_email' => 'rosa.caso.qa@correo-qa.test', 'authorize' => true, 'signature' => $signature,
    ])->assertCreated();

    $copy = qaMailSent(SiteRequestMail::class, 'rosa.caso.qa@correo-qa.test', fn ($m) => $m->title === 'Recibimos tu historia y tu autorización');
    expect($copy->buttonUrl)->toContain('/casos/retirar/');
    qaMailCheck($copy, 'rosa.caso.qa@correo-qa.test', ['Recibimos tu historia y tu autorización'], ['Rosa Prueba Quispe', 'Retirar mi autorización', $copy->buttonUrl]);
    $team = qaMailSent(SiteRequestMail::class, 'ventas@correo-qa.test', fn ($m) => str_starts_with($m->title, 'Caso de éxito recibido'));
    qaMailCheck($team, 'ventas@correo-qa.test', ['Caso de éxito recibido: Minera Correo QA SAC'], ['Hola, equipo:', 'Ver en el panel']);

    $this->postJson('/api/v1/public/success-stories/withdraw/'.basename($copy->buttonUrl), ['reason' => 'Cambio de gerencia'])->assertNoContent();
    $bye = qaMailSent(SiteRequestMail::class, 'rosa.caso.qa@correo-qa.test', fn ($m) => $m->title === 'Retiramos tu autorización');
    qaMailCheck($bye, 'rosa.caso.qa@correo-qa.test', ['Retiramos tu autorización'], ['Minera Correo QA SAC', 'ya no se muestra']);
    qaMailCheck(qaMailSent(SiteRequestMail::class, 'ventas@correo-qa.test', fn ($m) => str_starts_with($m->title, 'Autorización retirada')), 'ventas@correo-qa.test',
        ['Autorización retirada: Minera Correo QA SAC'], ['Rosa Prueba Quispe']);
});
