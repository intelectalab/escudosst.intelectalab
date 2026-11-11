<?php

// Asistente (complemento IAA): IPERC sugerido por puesto que se acepta al borrador de la matriz, investigación guiada
// con causas que se aceptan en el árbol, descarte e historial. Sin IA de pago y sin datos de salud.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode, source from feature_flags where company_id = ? and module_code = 'IAA'", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'IAA'", [HUAYLAS_ID]);
    Permissions::flush();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    foreach ($this->flags as $f) {
        $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
    }
    Permissions::flush();
});

function iaa(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

it('sugiere filas IPERC para el puesto y al aceptar las agrega al borrador con sus controles', function () {
    $h = iaa();
    $position = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'IAA-ANDAMIO', 'name' => 'Pintor de fachada', 'functions' => 'Pintar fachadas desde andamio'], $h)
        ->assertCreated()->json('data.id');
    $criteria = collect($this->getJson('/api/v1/records/IPC/iperc_matrices/options/risk_criteria_id?q=R.M.', $h)->json('data'))->first()['id'];
    $matrix = $this->postJson('/api/v1/records/IPC/iperc_matrices', ['code' => 'IAA-M1', 'title' => 'Pintura', 'risk_criteria_id' => $criteria, 'position_id' => $position], $h)
        ->assertCreated()->json('data.id');
    $version = $this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", $h)->json('data.version_id');

    $r = $this->postJson('/api/v1/iaa/iperc/suggest', ['position_id' => $position, 'context' => 'Trabajo en altura sobre andamio'], $h)->assertOk();
    expect($r->json('data.matrix.id'))->toBe($matrix)
        ->and($r->json('data.draft.id'))->toBe($version)
        ->and($r->json('data.suggestions'))->not->toBeEmpty();
    $s = $r->json('data.suggestions.0');
    expect($s['status'])->toBe('pending')->and($s['payload']['controls'])->not->toBeEmpty()->and($s['payload']['severity_value'])->not->toBeNull();

    // Aceptar con una edición: la fila entra al borrador marcada como asistida y la sugerencia queda «aceptada en parte».
    $a = $this->postJson("/api/v1/iaa/suggestions/{$s['id']}/accept", ['row' => ['task' => 'Pintar el segundo piso']], $h)->assertOk();
    expect($a->json('data.status'))->toBe('partially_accepted')->and($a->json('data.controls'))->toBeGreaterThan(0);
    $rows = $this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", $h)->json('data.rows');
    expect($rows)->toHaveCount(1)->and($rows[0]['task'])->toBe('Pintar el segundo piso')->and($rows[0]['controls'])->not->toBeEmpty();
    expect(DB::selectOne('select ai_assisted, ai_suggestion_id from iperc_rows where id = ?', [$rows[0]['id']]))
        ->ai_assisted->toBeTrue()->ai_suggestion_id->toBe($s['id']);

    // No se acepta dos veces; otra se descarta.
    $this->postJson("/api/v1/iaa/suggestions/{$s['id']}/accept", [], $h)->assertStatus(409);
    if ($other = $r->json('data.suggestions.1.id')) {
        $this->postJson("/api/v1/iaa/suggestions/{$other}/reject", [], $h)->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    $hist = $this->getJson('/api/v1/iaa/history', $h)->assertOk()->json('data.0');
    expect($hist['feature'])->toBe('iperc_suggestion')->and($hist['accepted'])->toBe(1);
});

it('no deja aceptar si la matriz no tiene borrador y bloquea datos de salud', function () {
    $h = iaa();
    $position = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'IAA-SIN', 'name' => 'Electricista'], $h)->json('data.id');

    $r = $this->postJson('/api/v1/iaa/iperc/suggest', ['position_id' => $position, 'context' => 'tablero eléctrico'], $h)->assertOk();
    expect($r->json('data.matrix'))->toBeNull()->and($r->json('data.note'))->toContain('no tiene matriz');
    if ($id = $r->json('data.suggestions.0.id')) {
        $this->postJson("/api/v1/iaa/suggestions/{$id}/accept", [], $h)->assertStatus(409);
    }
    $this->postJson('/api/v1/iaa/iperc/suggest', ['position_id' => $position, 'context' => 'el trabajador tiene diabetes'], $h)->assertStatus(422);
});

it('guía la investigación y acepta las causas en el árbol', function () {
    $h = iaa();
    $site = collect($this->getJson('/api/v1/records/ACC/sst_events/options/site_id', $h)->json('data'))->first()['id'];
    $event = $this->postJson('/api/v1/records/ACC/sst_events', ['code' => 'ACC-IAA-1', 'event_type' => 'incident', 'site_id' => $site,
        'occurred_at' => now('America/Lima')->subDay()->toIso8601String(), 'description' => 'El operario metió la mano en la prensa para retirar una pieza atascada'], $h)
        ->assertCreated()->json('data.id');
    $inv = $this->postJson('/api/v1/records/INV/investigations', ['event_id' => $event, 'method' => 'five_whys'], $h)->assertCreated()->json('data.id');

    $g = $this->postJson('/api/v1/iaa/investigation/guide', ['investigation_id' => $inv, 'answers' => ['No había herramienta de extracción']], $h)->assertOk();
    expect($g->json('data.questions'))->toHaveCount(5)->and($g->json('data.suggestions'))->not->toBeEmpty();
    $s = $g->json('data.suggestions.0');

    $a = $this->postJson("/api/v1/iaa/suggestions/{$s['id']}/accept", [], $h)->assertOk();
    $cause = DB::selectOne('select investigation_id, cause_level, ai_suggestion_id from investigation_causes where id = ?', [$a->json('data.cause_id')]);
    expect($cause->investigation_id)->toBe($inv)->and($cause->ai_suggestion_id)->toBe($s['id'])->and($cause->cause_level)->toBe($s['payload']['cause_level']);
    expect(DB::selectOne('select ai_assisted from investigations where id = ?', [$inv])->ai_assisted)->toBeTrue();
});
