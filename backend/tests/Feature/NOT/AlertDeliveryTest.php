<?php

// Entrega de avisos por correo y WhatsApp (M-NOT, docs/09 §2–3): un aviso crítico sale por correo al momento y por
// WhatsApp a quien lo aceptó; sin aceptación o con el canal apagado queda «suppressed»; sin WhatsApp en vivo no se intenta;
// el resumen diario va en un solo WhatsApp por persona; el reportante recibe la respuesta al cerrarse su reporte.
// Todo queda en notification_deliveries. Nada sale a internet (Http::fake, Mail::fake).

use App\Modules\NOT\AlertDelivery;
use App\Modules\NOT\AlertMail;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    $this->waRow = DB::connection('platform')->selectOne("select * from platform_providers where code = 'whatsapp'");
    $this->uid = DB::connection('platform')->selectOne('select id from users where email = ?', [PROFESIONAL_EMAIL])->id;
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $p = $this->waRow;
    DB::connection('platform')->update('update platform_providers set provider = ?, mode = ?, settings = ?::jsonb, secret_enc = ? where code = ?', [$p->provider, $p->mode, $p->settings, $p->secret_enc, 'whatsapp']);
});

function deliveryWaLive(array $settings = []): void
{
    DB::connection('platform')->update("update platform_providers set provider = 'meta', mode = 'live', settings = ?::jsonb, secret_enc = ? where code = 'whatsapp'",
        [json_encode(['phone_id' => '111222333', 'template_alert' => 'alerta_sst', 'template_lang' => 'es'] + $settings), Crypt::encryptString(json_encode(['token' => 'EAA-token-entrega']))]);
}

/** Vincula a la persona con un trabajador de la empresa con (o sin) aceptación de WhatsApp. */
function deliveryWorker(string $uid, bool $optIn): void
{
    app(AccountsService::class)->withCompany(ANDINAS_ID, function ($db) use ($uid, $optIn) {
        $db->update('update workers set user_id = null where company_id = ? and user_id = ?', [ANDINAS_ID, $uid]);
        $doc = (string) random_int(40000000, 79999999);
        $db->insert("insert into workers (company_id, document_type, document_number, document_number_hash, first_name, paternal_last_name, user_id, whatsapp_opt_in, phone_e164)
                     values (?, 'DNI', ?, decode(?, 'hex'), 'Prueba', 'WhatsApp', ?, ?, ?)",
            [ANDINAS_ID, Crypt::encryptString($doc), hash('sha256', $doc.Str::random(6)), $uid, $optIn ? 'true' : 'false', Crypt::encryptString('+51 987 111 222')]);
    });
}

function deliveryNotify(string $uid, string $event, string $priority, string $title = 'Accidente mortal registrado: EV-1'): string
{
    return app(AccountsService::class)->withCompany(ANDINAS_ID, fn ($db) => $db->selectOne(
        "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
         values (?, ?, 'sst_events', ?, ?, ?, 'Accidente mortal EV-1 el 10/11/2026 en Planta.', '/m/acc?t=sst_events&open=x', ?, ?) returning id",
        [ANDINAS_ID, $event, (string) Str::uuid(), $uid, $title, $priority, $event.':'.Str::random(8).':'.$uid])->id);
}

function deliveryRun(): array
{
    return app(AccountsService::class)->withCompany(ANDINAS_ID, fn ($db) => app(AlertDelivery::class)->forCompany($db, ANDINAS_ID));
}

function deliveryRows(string $notification): array
{
    return app(AccountsService::class)->withCompany(ANDINAS_ID, fn ($db) => $db->select('select channel, status, provider_message_id, error_code from notification_deliveries where notification_id = ? order by channel', [$notification]));
}

it('un aviso crítico sale por correo y por WhatsApp a quien lo aceptó, una sola vez', function () {
    deliveryWaLive();
    deliveryWorker($this->uid, true);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.CRIT1']]], 200)]);
    $n = deliveryNotify($this->uid, 'acc.event_registered', 'critical');

    expect(deliveryRun())->toBe(['email' => 1, 'whatsapp' => 1]);
    Mail::assertSent(AlertMail::class, fn ($m) => $m->hasTo(PROFESIONAL_EMAIL) && $m->title === 'Accidente mortal registrado: EV-1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/111222333/messages') && $r['to'] === '51987111222' && $r['template']['name'] === 'alerta_sst'
        && $r['template']['components'][0]['parameters'][0]['text'] === 'Accidente mortal registrado: EV-1');
    $rows = deliveryRows($n);
    expect(array_map(fn ($r) => [$r->channel, $r->status, $r->provider_message_id], $rows))->toBe([['email', 'sent', null], ['whatsapp', 'sent', 'wamid.CRIT1']]);

    expect(deliveryRun())->toBe(['email' => 0, 'whatsapp' => 0]);   // no se repite
    Mail::assertSentCount(1);
});

it('sin aceptación de WhatsApp o con el correo apagado queda registrado como omitido', function () {
    deliveryWaLive();
    deliveryWorker($this->uid, false);
    Http::fake();
    app(AccountsService::class)->withCompany(ANDINAS_ID, fn ($db) => $db->insert(
        "insert into user_notification_prefs (company_id, user_id, channel, event_category, enabled) values (?, ?, 'email', 'acc', false)", [ANDINAS_ID, $this->uid]));
    $n = deliveryNotify($this->uid, 'acc.event_registered', 'critical');

    expect(deliveryRun())->toBe(['email' => 0, 'whatsapp' => 0]);
    Mail::assertNothingSent();
    Http::assertNothingSent();
    expect(array_map(fn ($r) => [$r->channel, $r->status, $r->error_code], deliveryRows($n)))->toBe([['email', 'suppressed', 'preference_off'], ['whatsapp', 'suppressed', 'no_opt_in']]);
});

it('sin WhatsApp en vivo solo hay correo y la campana; el resumen diario va en un solo WhatsApp', function () {
    deliveryWorker($this->uid, true);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.DIG1']]], 200)]);
    DB::connection('platform')->update("update platform_providers set mode = 'test' where code = 'whatsapp'");
    $a = deliveryNotify($this->uid, 'acr.overdue', 'high', 'Acción correctiva vencida');
    $b = deliveryNotify($this->uid, 'cap.expiring', 'normal', 'Capacitación por vencer');
    expect(deliveryRun()['whatsapp'])->toBe(0);
    Http::assertNothingSent();
    expect(deliveryRows($a))->toBe([]);

    deliveryWaLive(['template_digest' => 'resumen_sst']);
    expect(deliveryRun()['whatsapp'])->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r['template']['name'] === 'resumen_sst' && $r['template']['components'][0]['parameters'][1]['text'] === '2');
    expect(deliveryRows($a)[0]->provider_message_id)->toBe('wamid.DIG1')->and(deliveryRows($b)[0]->provider_message_id)->toBe('wamid.DIG1');
});

it('al cerrarse un reporte, el reportante identificado recibe la respuesta por WhatsApp', function () {
    deliveryWaLive(['template_report' => 'reporte_atendido']);
    deliveryWorker($this->uid, true);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REP1']]], 200)]);
    $rep = app(AccountsService::class)->withCompany(ANDINAS_ID, fn ($db) => $db->selectOne(
        "insert into substandard_reports (company_id, code, report_type, origin, description, reporter_user_id, status, closed_at)
         values (?, 'REP-T-0001', 'unsafe_condition', 'app', 'Cable suelto', ?, 'closed', now()) returning id", [ANDINAS_ID, $this->uid])->id);

    expect(deliveryRun()['whatsapp'])->toBe(1);
    Http::assertSent(fn (Request $r) => $r['template']['name'] === 'reporte_atendido' && $r['template']['components'][0]['parameters'][0]['text'] === 'REP-T-0001');
    $n = app(AccountsService::class)->withCompany(ANDINAS_ID, fn ($db) => $db->selectOne("select id from notifications where event_code = 'act.report_closed' and subject_id = ?", [$rep]));
    expect($n)->not->toBeNull();
    expect(deliveryRun()['whatsapp'])->toBe(0);
});

it('con WhatsApp en vivo, el enlace personal para votar llega por WhatsApp a quien lo aceptó', function () {
    deliveryWaLive(['template_vote' => 'enlace_voto']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.VOTO1']]], 200)]);
    $h = authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID);
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    DB::update("update elections set deleted_at = now() where deleted_at is null and status not in ('results_published', 'cancelled', 'annulled')");
    $w = [];
    foreach (['Delia', 'Ernesto'] as $i => $name) {
        $w[] = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) (73000010 + $i), 'first_name' => $name, 'paternal_last_name' => 'Voto'], $h)->assertCreated()->json('data.id');
    }
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    DB::update("update workers set hire_on = '2015-01-01', birth_on = '1990-01-01' where id = any(?::uuid[])", ['{'.implode(',', $w).'}']);
    DB::update('update workers set whatsapp_opt_in = true, phone_e164 = ? where id = ?', [Crypt::encryptString('987333444'), $w[1]]);
    $e = $this->postJson('/api/v1/com/elections', ['title' => 'Elección 2026', 'seats_titular' => 1, 'seats_alternate' => 0], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/com/elections/{$e}/open-nominations", [], $h)->assertOk();
    $this->postJson("/api/v1/com/elections/{$e}/credentials", [], $h)->assertCreated();

    $sent = app(AccountsService::class)->withCompany(AGRO_ID, fn ($db) => app(AlertDelivery::class)->forCompany($db, AGRO_ID));
    expect($sent['whatsapp'])->toBe(1);
    Http::assertSent(fn (Request $r) => $r['to'] === '51987333444' && $r['template']['name'] === 'enlace_voto'
        && $r['template']['components'][0]['parameters'][0]['text'] === 'Elección 2026'
        && str_contains($r['template']['components'][0]['parameters'][1]['text'], '/votar/'.AGRO_ID.'/'));
});
