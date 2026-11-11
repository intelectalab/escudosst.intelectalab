<?php

// Investigación (M-INV): asistente SCAT (contacto → causas inmediatas → básicas → NAC) guardado como causas con código,
// NAC que se convierten en acción correctiva e informe imprimible con evento, equipo, causas y acciones.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    Permissions::flush();
});

function scatInvestigation($t, array $h): string
{
    $site = collect($t->getJson('/api/v1/records/ACC/sst_events/options/site_id', $h)->json('data'))->first()['id'];
    $event = $t->postJson('/api/v1/records/ACC/sst_events', ['code' => 'ACC-SCAT-'.random_int(100, 999), 'event_type' => 'incident', 'site_id' => $site,
        'occurred_at' => now('America/Lima')->subDay()->toIso8601String(), 'description' => 'Golpe con pieza que cayó del estante'], $h)->assertCreated()->json('data.id');

    return $t->postJson('/api/v1/records/INV/investigations', ['event_id' => $event, 'method' => 'scat'], $h)->assertCreated()->json('data.id');
}

it('entrega el catálogo SCAT', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $c = $this->getJson('/api/v1/inv/scat/catalog', $h)->assertOk()->json('data');
    expect($c['contact'])->not->toBeEmpty()->and($c['acts'])->not->toBeEmpty()->and($c['nac'])->toHaveCount(20)->and($c['pec'])->toHaveCount(3);
});

it('guarda el análisis SCAT como causas enlazadas y la NAC se convierte en acción correctiva', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $inv = scatInvestigation($this, $h);

    $body = [
        'contact' => ['C02'],
        'immediate' => [['code' => 'A09', 'note' => 'Cajas apiladas sobre el borde'], ['code' => 'S07']],
        'basic' => [['code' => 'FT06', 'parent' => 'A09'], ['code' => 'FP05', 'parent' => 'S07']],
        'nac' => [['code' => 'NAC04', 'pec' => 'E', 'parent' => 'FT06', 'note' => 'Falta estándar de almacenamiento']],
    ];
    $r = $this->putJson("/api/v1/inv/investigations/{$inv}/scat", $body, $h)->assertOk();
    expect($r->json('data.contact'))->toBe(['C02'])->and($r->json('data.causes'))->toHaveCount(5)->and($r->json('data.editable'))->toBeTrue();

    $rows = collect(DB::select('select id, scat_code, cause_level, cause_category, parent_cause_id, description from investigation_causes where investigation_id = ? and deleted_at is null', [$inv]))->keyBy('scat_code');
    expect($rows['A09']->cause_category)->toBe('unsafe_act')->and($rows['A09']->description)->toContain('Cajas apiladas')
        ->and($rows['S07']->cause_category)->toBe('unsafe_condition')
        ->and($rows['FT06']->cause_level)->toBe('basic')->and($rows['FT06']->parent_cause_id)->toBe($rows['A09']->id)
        ->and($rows['NAC04-E']->cause_level)->toBe('lack_of_control')->and($rows['NAC04-E']->parent_cause_id)->toBe($rows['FT06']->id);
    expect(DB::selectOne('select status from investigations where id = ?', [$inv])->status)->toBe('analysis');

    // La NAC se convierte en acción correctiva con el flujo existente.
    $user = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    $this->postJson("/api/v1/acr/from/investigation_causes/{$rows['NAC04-E']->id}", ['title' => 'Elaborar estándar de almacenamiento', 'responsible_user_id' => $user,
        'due_on' => now()->addDays(15)->toDateString()], $h)->assertSuccessful();

    // Al volver a guardar sin esa NAC ni S07, se quita S07 (y su básica) pero la NAC con acción se conserva.
    $body['immediate'] = [['code' => 'A09']];
    $body['basic'] = [['code' => 'FT06', 'parent' => 'A09']];
    $body['nac'] = [];
    $r2 = $this->putJson("/api/v1/inv/investigations/{$inv}/scat", $body, $h)->assertOk();
    expect($r2->json('data.kept'))->toBe(['NAC04-E'])->and(collect($r2->json('data.causes'))->pluck('scat_code')->sort()->values()->all())->toBe(['A09', 'FT06', 'NAC04-E']);

    // Informe imprimible.
    $rep = $this->getJson("/api/v1/inv/investigations/{$inv}/report", $h)->assertOk()->json('data');
    expect($rep['investigation']['method'])->toBe('scat')->and($rep['investigation']['scat_contact'][0]['code'])->toBe('C02')
        ->and($rep['event']['description'])->toContain('Golpe')->and($rep['causes'])->toHaveCount(3)->and($rep['actions'])->toHaveCount(1)
        ->and($rep['company']['ruc'])->not->toBeEmpty();
});

it('valida los pasos del asistente y bloquea la investigación aprobada', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $inv = scatInvestigation($this, $h);
    $this->putJson("/api/v1/inv/investigations/{$inv}/scat", ['contact' => [], 'immediate' => [], 'basic' => [], 'nac' => []], $h)->assertStatus(422);
    $this->putJson("/api/v1/inv/investigations/{$inv}/scat", ['contact' => ['C01'], 'immediate' => [['code' => 'FT01']], 'basic' => [['code' => 'FP01']], 'nac' => []], $h)
        ->assertStatus(422)->assertJsonPath('errors', fn ($e) => isset($e['immediate.0.code']));
    $this->putJson("/api/v1/inv/investigations/{$inv}/scat", ['contact' => ['C01'], 'immediate' => [['code' => 'A01']], 'basic' => [['code' => 'FP01', 'parent' => 'S03']], 'nac' => []], $h)
        ->assertStatus(422);

    DB::update("update investigations set status = 'approved', approved_at = now() where id = ?", [$inv]);
    $this->putJson("/api/v1/inv/investigations/{$inv}/scat", ['contact' => ['C01'], 'immediate' => [['code' => 'A01']], 'basic' => [['code' => 'FP01']], 'nac' => []], $h)
        ->assertStatus(409);
    expect($this->getJson("/api/v1/inv/investigations/{$inv}/scat", $h)->assertOk()->json('data.editable'))->toBeFalse();
});
