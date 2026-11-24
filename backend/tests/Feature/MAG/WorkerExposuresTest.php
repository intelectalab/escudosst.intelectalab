<?php

// M-MAG RN-MAG-06 / §12 «Produce: exposiciones para EMO y VMD»: marca de exposición por trabajador (sin datos médicos),
// usada por la orden y los protocolos de EMO y por los programas de vigilancia por exposición de VMD.

use Illuminate\Support\Facades\DB;

function weAs(string $company, callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [$company]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

/** Puesto, trabajador del puesto, punto y medición aprobada sobre el límite; devuelve [worker, position, measurement, agent code]. */
function weExposed(\Tests\TestCase $t, array $h, string $company, string $doc): array
{
    $lim = weAs($company, fn () => DB::selectOne("select l.id, l.agent_type_id, l.limit_value, l.unit, a.code from exposure_limits l join agent_types a on a.id = l.agent_type_id
                                                   where l.deleted_at is null and l.limit_value > 0 and (l.valid_from is null or l.valid_from <= current_date)
                                                     and (l.valid_to is null or l.valid_to >= current_date) order by l.created_at limit 1"));
    $pos = $t->postJson('/api/v1/records/TEN/positions', ['code' => 'EXP-'.$doc, 'name' => 'Prensista '.$doc], $h)->assertCreated()->json('data.id');
    $w = weAs($company, fn () => DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status, current_position_id)
                                                values (?, ?, decode(md5(?), 'hex'), 'Expuesto', 'Ruido', 'active', ?) returning id", [$company, $doc, $doc, $pos])->id);
    $site = $t->postJson('/api/v1/records/TEN/sites', ['code' => 'S-'.$doc, 'name' => 'Sede '.$doc], $h)->assertCreated()->json('data.id');
    $point = $t->postJson('/api/v1/records/MAG/monitoring_points', ['code' => 'P-'.$doc, 'name' => 'Prensa', 'agent_type_id' => $lim->agent_type_id, 'position_id' => $pos, 'site_id' => $site], $h)
        ->assertCreated()->json('data.id');
    $camp = $t->postJson('/api/v1/records/MAG/monitoring_campaigns', ['title' => 'Monitoreo '.$doc, 'year' => 2026], $h)->assertCreated()->json('data.id');
    $m = $t->postJson('/api/v1/records/MAG/measurements', ['campaign_id' => $camp, 'monitoring_point_id' => $point, 'agent_type_id' => $lim->agent_type_id, 'exposure_limit_id' => $lim->id,
        'unit' => $lim->unit, 'measured_at' => now()->toIso8601String(), 'value' => (float) $lim->limit_value * 1.3], $h)->assertCreated()->json('data.id');

    return [$w, $pos, $m, $lim->code];
}

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

afterEach(function () {
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $GLOBALS['sus_tokens'] = [];
});

it('marca la exposición al aprobar la medición, la muestra en EMO y la anula al anular la medición', function () {
    weAs(ANDINAS_ID, fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('MAG', 'EMO') and deleted_at is null", [ANDINAS_ID]));
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    [$w, $pos, $m, $code] = weExposed($this, $h, ANDINAS_ID, '71717171');

    $count = fn () => weAs(ANDINAS_ID, fn () => (int) DB::selectOne("select count(*) as n from worker_exposures where measurement_id = ? and status = 'active'", [$m])->n);
    expect($count())->toBe(0);   // en borrador no marca
    $this->patchJson("/api/v1/records/MAG/measurements/{$m}", ['status' => 'approved'], $h)->assertOk();
    expect($count())->toBe(1);
    $e = weAs(ANDINAS_ID, fn () => DB::selectOne('select * from worker_exposures where measurement_id = ?', [$m]));
    expect($e->worker_id)->toBe($w)->and($e->level)->toBe('exceeds_limit')->and($e->position_id)->toBe($pos)
        ->and($e->valid_until)->toBe(now()->addMonths((int) weAs(ANDINAS_ID, fn () => \App\Core\Support\LegalParams::number('monitoring_frequency_months', 12)))->toDateString());

    // /mag/exposed lee la misma marca.
    expect(collect($this->getJson('/api/v1/mag/exposed', $h)->assertOk()->json('data.workers'))->pluck('worker_id'))->toContain($w);

    // EMO: el puesto muestra el agente medido y la orden del trabajador (también «por exposición») trae su exposición.
    $p = collect($this->getJson('/api/v1/emo/protocols', $h)->assertOk()->json('data.positions'))->firstWhere('id', $pos);
    expect($p['mag_exposures'])->toHaveCount(1)->and($p['mag_exposures'][0]['workers'])->toBe(1);
    $order = weAs(ANDINAS_ID, fn () => DB::selectOne("insert into medical_exam_orders (company_id, worker_id, exam_type, scheduled_at) values (?, ?, 'exposure', now() + interval '7 days') returning id",
        [ANDINAS_ID, $w])->id);
    $x = $this->getJson("/api/v1/emo/orders/{$order}/exposures", $h)->assertOk();
    expect($x->json('data.exposures'))->toHaveCount(1)->and($x->json('data.exposures.0.measurement_id'))->toBe($m)->and($x->json('data.exam_type'))->toBe('exposure');

    // Anulada la medición, la marca queda anulada.
    $this->patchJson("/api/v1/records/MAG/measurements/{$m}", ['status' => 'voided', 'void_reason' => 'Equipo descalibrado'], $h)->assertOk();
    expect($count())->toBe(0);
    expect($this->getJson("/api/v1/emo/orders/{$order}/exposures", $h)->json('data.exposures'))->toBeEmpty();
});

it('el programa de vigilancia por exposición cuenta sus expuestos y lista los que no tienen caso', function () {
    DB::connection('medical')->beginTransaction();
    try {
        weAs(HUAYLAS_ID, fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('MAG', 'VMD') and deleted_at is null", [HUAYLAS_ID]));
        $uid = weAs(AGRO_ID, fn () => DB::selectOne('select id from users where email = ?', [ESENCIAL_EMAIL])->id);
        weAs(HUAYLAS_ID, fn () => DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at)
                                               values (?, ?, (select id from roles where account_id is null and code = 'occupational_doctor'), 'active', now())", [HUAYLAS_ID, $uid]));
        $sst = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
        [$w, $pos, $m, $code] = weExposed($this, $sst, HUAYLAS_ID, '72727272');
        $this->patchJson("/api/v1/records/MAG/measurements/{$m}", ['status' => 'approved'], $sst)->assertOk();

        $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
        // Agentes con expuestos para definir el programa (sin datos médicos).
        $ag = collect($this->getJson('/api/v1/vmd/exposures', $doc)->assertOk()->json('data'))->firstWhere('agent_code', $code);
        expect($ag['workers'])->toBeGreaterThanOrEqual(1)->and(collect($ag['positions'])->pluck('id'))->toContain($pos);

        $r = medReauth('/api/v1/vmd/reauth', $doc, ESENCIAL_EMAIL)->assertOk()->json('data.reauth_id');
        $med = $doc + ['X-Medical-Reauth' => $r];
        $this->postJson('/api/v1/vmd/programs', ['code' => 'VMD-X', 'name' => 'Por exposición', 'target_risk' => 'noise', 'agent_type_code' => 'NO-EXISTE'], $med)
            ->assertStatus(422)->assertJsonValidationErrors('agent_type_code');
        $p = $this->postJson('/api/v1/vmd/programs', ['code' => 'VMD-X', 'name' => 'Por exposición', 'target_risk' => 'noise', 'target_positions' => [$pos]], $med)
            ->assertCreated()->json('data.id');
        $row = collect($this->getJson('/api/v1/vmd/programs', $med)->assertOk()->json('data'))->firstWhere('id', $p);
        expect($row['exposed_workers'])->toBe(1)->and($row['exposed_without_case'])->toBe(1);
        $list = $this->getJson("/api/v1/vmd/programs/{$p}/exposed", $med)->assertOk()->json('data');
        expect($list)->toHaveCount(1)->and($list[0]['worker_id'])->toBe($w)->and($list[0]['has_open_case'])->toBeFalse();

        $this->postJson('/api/v1/vmd/cases', ['program_id' => $p, 'worker_id' => $w, 'reason' => 'Expuesto sobre el límite'], $med)->assertCreated();
        $row = collect($this->getJson('/api/v1/vmd/programs', $med)->json('data'))->firstWhere('id', $p);
        expect($row['exposed_without_case'])->toBe(0);
        expect($this->getJson("/api/v1/vmd/programs/{$p}/exposed", $med)->json('data.0.has_open_case'))->toBeTrue();
        // Sin clave médica no se ve la lista de expuestos del programa.
        $this->getJson("/api/v1/vmd/programs/{$p}/exposed", $doc)->assertStatus(403);
    } finally {
        DB::connection('medical')->rollBack();
    }
});
