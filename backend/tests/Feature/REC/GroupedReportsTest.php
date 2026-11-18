<?php

// Informes de las fichas (§11): reporte agrupado de eventos (M-ACC), constancias de notificación (M-ACC), RISST con su acta
// de aprobación (M-RIS) e informe de simulacro (M-SIM).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('exporta los eventos agrupados por tipo, gravedad, sede, parte del cuerpo y agente', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'GR-'.random_int(1000, 99999), 'name' => 'Sede agrupada'], $this->h)->json('data.id');
    $e = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'minor_accident', 'occurred_at' => now()->subDay()->toIso8601String(), 'site_id' => $site,
        'description' => 'Golpe en la mano'], $this->h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into event_people (company_id, event_id, person_role, external_person_name, body_part, injury_agent) values (?, ?, 'injured', 'Persona GR', 'Mano', 'Martillo')",
        [HUAYLAS_ID, $e]);
    DB::select("select set_config('app.company_id', '', false)");

    $r = $this->get('/api/v1/acc/report.xlsx', $this->h)->assertOk();
    expect($r->headers->get('Content-Type'))->toContain('spreadsheetml');
    $xml = (function (string $bin) {
        $f = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($f, $bin);
        $z = new ZipArchive;
        $z->open($f);
        $all = '';
        for ($i = 0; $i < $z->numFiles; $i++) {
            $all .= $z->getFromIndex($i);
        }
        $z->close();
        unlink($f);

        return $all;
    })($r->getContent());
    expect($xml)->toContain('Por parte del cuerpo')->toContain('Mano')->toContain('Martillo')->toContain('Sede agrupada')->toContain('Accidente leve');
});

it('muestra las constancias de notificación del evento', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'GN-'.random_int(1000, 99999), 'name' => 'Sede constancias'], $this->h)->json('data.id');
    $e = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'dangerous_incident', 'occurred_at' => now()->subHours(2)->toIso8601String(), 'site_id' => $site,
        'description' => 'Caída de carga suspendida'], $this->h)->assertCreated()->json('data.id');
    $d = $this->getJson("/api/v1/reports/sst_events/{$e}/notifications", $this->h)->assertOk()->json('data');
    expect($d['event']['event_type'])->toBe('dangerous_incident')->and(collect($d['notifications'])->pluck('authority'))->toContain('mtpe_sat');
});

it('el RISST impreso trae su aprobación y el acta de la sesión del comité', function () {
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $meeting = heldMeetingForTests(HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into meeting_agreements (company_id, meeting_id, number, description, status) values (?, ?, 1, 'Aprobar el RISST 2027', 'agreed')", [HUAYLAS_ID, $meeting]);
    $r = DB::selectOne("insert into risst_versions (company_id, version_number, title, content_html, status, approved_at, committee_meeting_id)
                        values (?, (select coalesce(max(version_number), 0) + 1 from risst_versions where company_id = ?), 'RISST 2027', '<p>Reglamento</p>', 'approved', now(), ?) returning id",
        [HUAYLAS_ID, HUAYLAS_ID, $meeting])->id;
    DB::select("select set_config('app.company_id', '', false)");

    $d = $this->getJson("/api/v1/reports/risst_versions/{$r}", $this->h)->assertOk()->json('data');
    expect($d['risst']['title'])->toBe('RISST 2027')->and($d['meeting']['id'])->toBe($meeting)->and($d['agreements'][0]['description'])->toBe('Aprobar el RISST 2027');
});

it('suspender un permiso deja un reporte de condición en ACT y las estadísticas cuentan la suspensión con su causa', function () {
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('ACT', 'PTR') and deleted_at is null", [HUAYLAS_ID]);
    $site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null limit 1', [HUAYLAS_ID])->id;
    $type = DB::selectOne('select id from permit_types where company_id is null or company_id = ? order by company_id nulls last limit 1', [HUAYLAS_ID])->id;
    $code = 'PT-SUS-'.random_int(1000, 999999);
    $p = DB::selectOne("insert into work_permits (company_id, code, permit_type_id, site_id, work_description, valid_from, valid_until, status)
                        values (?, ?, ?, ?, 'Soldadura en techo', now() - interval '1 hour', now() + interval '4 hours', 'active') returning id", [HUAYLAS_ID, $code, $type, $site])->id;
    DB::select("select set_config('app.company_id', '', false)");

    $this->patchJson("/api/v1/records/PTR/work_permits/{$p}", ['status' => 'suspended', 'suspended_reason' => 'Viento fuerte sobre el techo'], $this->h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $rep = DB::selectOne("select report_type, status, risk_level, description from substandard_reports where description like ? and deleted_at is null", ["Permiso {$code} suspendido%"]);
    expect($rep)->not->toBeNull()->and($rep->report_type)->toBe('unsafe_condition')->and($rep->status)->toBe('triaged')->and($rep->description)->toContain('Viento fuerte');
    DB::select("select set_config('app.company_id', '', false)");

    $r = $this->get('/api/v1/ptr/stats.xlsx', $this->h)->assertOk();
    $f = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($f, $r->getContent());
    $z = new ZipArchive;
    $z->open($f);
    $all = '';
    for ($i = 0; $i < $z->numFiles; $i++) {
        $all .= $z->getFromIndex($i);
    }
    $z->close();
    unlink($f);
    expect($all)->toContain('Suspensiones y causas')->toContain($code)->toContain('Viento fuerte sobre el techo');
});
