<?php

// M-ACT RN-ACT-08 y docs/10: el contacto de quien reporta por QR se guarda cifrado; solo los roles SST ven la identidad del
// reportante (nombre/contacto, trabajador, usuario); el jefe de área y el trabajador la ven «Reservado» y no la cambian.
// La migración 000970 cifra los contactos antiguos guardados en texto plano.

use App\Core\Auth\Permissions;
use App\Modules\ACT\ReportPointController;
use App\Modules\REC\RecordsService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    app('auth')->forgetGuards();
    Permissions::flush();
});

/** Punto de reporte con nombre obligatorio. @return array{0: string, 1: string} [id, token] */
function riPoint(): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $site = DB::selectOne('select id from sites where company_id = ? order by is_main desc limit 1', [HUAYLAS_ID])->id;
    $id = DB::selectOne("insert into report_points (company_id, code, name, site_id, token_hash, allow_anonymous, is_active)
                         values (?, ?, 'Almacén', ?, decode(repeat('00', 32), 'hex'), true, true) returning id", [HUAYLAS_ID, 'RI-'.uniqid(), $site])->id;
    $token = ReportPointController::token(HUAYLAS_ID, $id);
    DB::update("update report_points set token_hash = decode(?, 'hex') where id = ?", [hash('sha256', $token), $id]);
    DB::select("select set_config('app.company_id', '', false)");

    return [$id, $token];
}

/** Da a un usuario de prueba un rol en Huaylas y devuelve sus cabeceras. */
function riAs(string $email, string $role): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [$email])->id;
    $rid = DB::selectOne('select id from roles where account_id is null and code = ?', [$role])->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $rid]);
    DB::select("select set_config('app.company_id', '', false)");

    return riSwitch($email);
}

function riSwitch(string $email): array
{
    app('auth')->forgetGuards();
    Permissions::flush();
    app()->forgetInstance(\App\Core\Tenancy\CompanyContext::class);

    return authHeaders(tokenFor($email), HUAYLAS_ID);
}

it('guarda cifrado el contacto del reporte por QR y solo SST ve quién reportó', function () {
    [$point, $token] = riPoint();
    $this->postJson('/api/v1/public/report/'.HUAYLAS_ID.'/'.$token, ['report_type' => 'unsafe_condition', 'description' => 'Piso mojado sin señalización en el almacén',
        'reporter_name' => 'Carmen Ríos', 'reporter_phone' => '987 111 222'])->assertCreated();

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $r = DB::selectOne('select id, reporter_contact from substandard_reports where report_point_id = ?', [$point]);
    DB::select("select set_config('app.company_id', '', false)");
    expect($r->reporter_contact)->not->toContain('Carmen')
        ->and(RecordsService::decryptValue($r->reporter_contact))->toBe('Carmen Ríos · 987 111 222');

    // Jefe SST: ve el nombre y el teléfono.
    $sst = riSwitch(CORPORATIVO_EMAIL);
    $this->getJson("/api/v1/records/ACT/substandard_reports/{$r->id}", $sst)->assertOk()
        ->assertJsonPath('data.reporter_contact', 'Carmen Ríos · 987 111 222')->assertJsonMissingPath('data._reserved');

    // Jefe de área (puede editar el reporte): la identidad llega vacía y «reservada»; no la puede cambiar.
    $lead = riAs(PROFESIONAL_EMAIL, 'area_lead');
    $d = $this->getJson("/api/v1/records/ACT/substandard_reports/{$r->id}", $lead)->assertOk()->json('data');
    expect($d['reporter_contact'])->toBeNull()->and($d['_reserved'])->toContain('reporter_contact', 'reporter_worker_id', 'reporter_user_id');
    $u = $this->patchJson("/api/v1/records/ACT/substandard_reports/{$r->id}", ['reporter_contact' => 'Otro', 'classification' => 'high'], $lead);
    expect($u->status())->toBeIn([200, 422]);
    expect(json_encode($u->json()))->not->toContain('Carmen');
    $list = collect($this->getJson('/api/v1/records/ACT/substandard_reports?per_page=100', $lead)->assertOk()->json('data'))->firstWhere('id', $r->id);
    expect($list['reporter_contact'] ?? null)->toBeNull();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(RecordsService::decryptValue(DB::selectOne('select reporter_contact from substandard_reports where id = ?', [$r->id])->reporter_contact))->toBe('Carmen Ríos · 987 111 222');
    DB::select("select set_config('app.company_id', '', false)");

    // Trabajador: tampoco la ve.
    $worker = riAs(ESENCIAL_EMAIL, 'worker');
    $w = $this->getJson("/api/v1/records/ACT/substandard_reports/{$r->id}", $worker);
    if ($w->status() === 200) {
        expect($w->json('data.reporter_contact'))->toBeNull()->and($w->json('data._reserved'))->toContain('reporter_contact');
    } else {
        expect($w->status())->toBeIn([403, 404]);
    }
    expect(json_encode($w->json()))->not->toContain('Carmen');
});

it('la migración cifra el contacto antiguo en texto plano y SST lo vuelve a leer', function () {
    [$point] = riPoint();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $id = DB::selectOne("insert into substandard_reports (company_id, code, report_type, origin, report_point_id, description, reported_at, is_anonymous, reporter_contact, status)
                         values (?, ?, 'unsafe_act', 'qr', ?, 'Reporte antiguo sin cifrar', now(), false, 'Pedro Soto · 999 000 111', 'received') returning id",
        [HUAYLAS_ID, 'REP-OLD-'.random_int(1000, 99999), $point])->id;
    $cipher = RecordsService::encryptValue('Ya cifrado');
    $id2 = DB::selectOne("insert into substandard_reports (company_id, code, report_type, origin, report_point_id, description, reported_at, is_anonymous, reporter_contact, status)
                          values (?, ?, 'unsafe_act', 'qr', ?, 'Reporte ya cifrado', now(), false, ?, 'received') returning id",
        [HUAYLAS_ID, 'REP-OLD-'.random_int(1000, 99999), $point, $cipher])->id;
    DB::select("select set_config('app.company_id', '', false)");

    $m = require database_path('migrations/main/2026_11_24_000970_act_reporter_contact_encrypt.php');
    $m->up();

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $raw = DB::selectOne('select reporter_contact from substandard_reports where id = ?', [$id])->reporter_contact;
    expect($raw)->not->toContain('Pedro')->and(RecordsService::decryptValue($raw))->toBe('Pedro Soto · 999 000 111')
        ->and(DB::selectOne('select reporter_contact from substandard_reports where id = ?', [$id2])->reporter_contact)->toBe($cipher);   // no se cifra dos veces
    DB::select("select set_config('app.company_id', '', false)");

    $this->getJson("/api/v1/records/ACT/substandard_reports/{$id}", riSwitch(CORPORATIVO_EMAIL))->assertOk()->assertJsonPath('data.reporter_contact', 'Pedro Soto · 999 000 111');
});
