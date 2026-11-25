<?php

// M-SUN: generación del expediente en segundo plano (docs/08 §12: 202 y estado consultable), RN-SUN-07 (la constancia de
// aptitud entra solo con la aprobación del médico ocupacional) y RN-SUN-03 (obligación sin módulo evaluada con evidencias de DOC).

use App\Modules\SUN\BuildDossierJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->flags = DB::connection('platform')->select("select id, mode, source from feature_flags where company_id = ? and module_code = 'MRL'", [ANDINAS_ID]);
});

afterEach(function () {
    foreach ($this->flags as $f) {
        DB::connection('platform')->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
    }
    \App\Core\Auth\Permissions::flush();
});

function sunAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

function sunDoctor(): array
{
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [ESENCIAL_EMAIL])->id;
    sunAs(function () use ($uid) {
        $role = DB::selectOne("select id from roles where account_id is null and code = 'occupational_doctor'")->id;
        DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now()) on conflict do nothing", [ANDINAS_ID, $uid, $role]);
    });
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();

    return authHeaders(tokenFor(ESENCIAL_EMAIL), ANDINAS_ID);
}

it('responde 202 con el enlace de estado y arma el expediente en la cola', function () {
    Queue::fake();
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente en cola', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $this->h)->assertCreated()->json('data.id');
    $r = $this->postJson("/api/v1/sun/dossiers/{$d}/build", [], $this->h)->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.id', $d);
    expect($r->json('data.status_url'))->toEndWith("/api/v1/sun/dossiers/{$d}/status")
        ->and($r->headers->get('Location'))->toBe($r->json('data.status_url'));
    Queue::assertPushed(BuildDossierJob::class, fn ($j) => $j->dossierId === $d && $j->companyId === ANDINAS_ID);

    // Mientras la cola no lo procesa: «Generando…».
    $this->getJson("/api/v1/sun/dossiers/{$d}/status", $this->h)->assertOk()->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.pending', true)->assertJsonCount(0, 'data.items');

    // El trabajador de la cola lo arma fuera de la petición (empresa y usuario fijados por el propio trabajo) y avisa.
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    app()->forgetInstance(\App\Core\Tenancy\CompanyContext::class);
    (new BuildDossierJob($d, ANDINAS_ID, $uid))->handle();
    $s = $this->getJson("/api/v1/sun/dossiers/{$d}/status", $this->h)->assertOk()->assertJsonPath('data.pending', false);
    expect($s->json('data.status'))->toBeIn(['ready', 'ready_with_gaps'])
        ->and($s->json('data.total'))->toBeGreaterThan(10)
        ->and($s->json('data.built_at'))->not->toBeNull();
    $n = sunAs(fn () => DB::selectOne("select count(*) as n from notifications where event_code = 'sun.dossier_built' and subject_id = ? and recipient_user_id = ?", [$d, $uid])->n);
    expect((int) $n)->toBe(1);
});

it('un trabajador inválido se rechaza antes de encolar', function () {
    Queue::fake();
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente inválido', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $this->h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/sun/dossiers/{$d}/build", ['worker_ids' => ['01900000-0000-7000-8000-0000000fffff']], $this->h)->assertStatus(422);
    Queue::assertNothingPushed();
});

it('la constancia de aptitud entra al expediente solo con la aprobación del médico ocupacional', function () {
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '93939301', 'first_name' => 'Rosa', 'paternal_last_name' => 'Apta'], $this->h)->assertCreated()->json('data.id');
    sunAs(fn () => DB::insert("insert into fitness_status (company_id, worker_id, status, valid_from, valid_until, is_current) values (?, ?, 'fit', current_date, current_date + 365, true)", [ANDINAS_ID, $w]));
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente con aptitud', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $this->h)->assertCreated()->json('data.id');
    $w3 = collect(sunBuild($d, ['worker_ids' => [$w]], $this->h)->json('data.items'))->firstWhere('code', 'W3');
    expect($w3['status'])->toBe('pending_approval')->and($w3['gap'])->toContain('médico ocupacional');

    // El Jefe SST no la aprueba.
    $item = collect($this->getJson("/api/v1/sun/dossiers/{$d}/items", $this->h)->assertOk()->json('data'))->firstWhere('code', 'W3');
    expect($item['is_medical_certificate'])->toBeTrue();
    $this->postJson("/api/v1/sun/dossier-items/{$item['id']}/approve", [], $this->h)->assertStatus(403);

    // El médico ve la constancia por aprobar (solo aptitud y vigencia) y la aprueba.
    $doc = sunDoctor();
    $list = collect($this->getJson('/api/v1/sun/medical-approvals', $doc)->assertOk()->json('data'))->firstWhere('id', $item['id']);
    expect($list['worker_name'])->toBe('Rosa Apta')->and($list['fitness_label'])->toBe('Apto')->and($list)->not->toHaveKey('work_restrictions');
    $this->postJson("/api/v1/sun/dossier-items/{$item['id']}/approve", [], $doc)->assertOk()->assertJsonPath('data.status', 'included');
    $this->postJson("/api/v1/sun/dossier-items/{$item['id']}/approve", [], $doc)->assertStatus(409);

    // Al volver a armar se conserva la aprobación de la misma aptitud.
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $again = collect(sunBuild($d, [], $this->h)->json('data.items'))->firstWhere('code', 'W3');
    expect($again['status'])->toBe('included');
    $audit = sunAs(fn () => DB::selectOne("select count(*) as n from audit_logs where entity_type = 'dossier_items' and entity_id = ? and action = 'approve'", [$item['id']])->n);
    expect((int) $audit)->toBe(1);
});

it('el médico puede no aprobar la constancia y queda excluida con el motivo', function () {
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '93939302', 'first_name' => 'Hugo', 'paternal_last_name' => 'Revisado'], $this->h)->assertCreated()->json('data.id');
    sunAs(fn () => DB::insert("insert into fitness_status (company_id, worker_id, status, valid_from, valid_until, is_current) values (?, ?, 'fit', current_date, current_date + 365, true)", [ANDINAS_ID, $w]));
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente con aptitud rechazada', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $this->h)->assertCreated()->json('data.id');
    sunBuild($d, ['worker_ids' => [$w]], $this->h);
    $item = collect($this->getJson("/api/v1/sun/dossiers/{$d}/items", $this->h)->json('data'))->firstWhere('code', 'W3');
    $doc = sunDoctor();
    $this->postJson("/api/v1/sun/dossier-items/{$item['id']}/reject", [], $doc)->assertStatus(422);
    $this->postJson("/api/v1/sun/dossier-items/{$item['id']}/reject", ['reason' => 'Falta el examen de retiro'], $doc)->assertOk()->assertJsonPath('data.status', 'excluded');
    expect(collect($this->getJson('/api/v1/sun/medical-approvals', $doc)->json('data'))->pluck('id'))->not->toContain($item['id']);
});

it('una obligación sin el módulo contratado se evalúa con los documentos vinculados en DOC y nunca se da por cumplida', function () {
    DB::connection('platform')->update("update feature_flags set mode = 'locked' where company_id = ? and module_code = 'MRL'", [ANDINAS_ID]);
    \App\Core\Auth\Permissions::flush();
    $legal = fn () => collect($this->getJson('/api/v1/sun/diagnosis', $this->h)->assertOk()->json('data.areas'))->firstWhere('code', 'legal');
    $a = $legal();
    expect($a['no_module'])->toBeTrue()->and($a['pct'])->toBe(0)->and($a['result'])->toBe('fails')->and($a['evidence'])->toBe([])
        ->and($a['detail'])->toContain('Sin módulo');

    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    $doc = sunAs(function () use ($uid) {
        $cat = DB::selectOne("select id from document_categories where deleted_at is null and (company_id = ? or company_id is null) limit 1", [ANDINAS_ID])?->id
            ?? DB::selectOne("insert into document_categories (company_id, code, name) values (?, ?, 'Categoría SUN') returning id", [ANDINAS_ID, 'SUN-C-'.random_int(1000, 999999)])->id;
        $doc = DB::selectOne("insert into documents (company_id, category_id, code, title, status) values (?, ?, ?, 'Matriz legal en Excel', 'active') returning id",
            [ANDINAS_ID, $cat, 'SUN-DOC-'.random_int(1000, 999999)])->id;
        $ver = DB::selectOne("insert into document_versions (company_id, document_id, version_number, version_label, change_summary, status, approved_by, approved_at)
            values (?, ?, 1, 'v1', 'Primera versión', 'current', ?, now()) returning id", [ANDINAS_ID, $doc, $uid])->id;
        DB::update('update documents set current_version_id = ? where id = ?', [$ver, $doc]);

        return $doc;
    });
    $opts = collect($this->getJson('/api/v1/sun/diagnosis/evidence-options?q=Matriz legal', $this->h)->assertOk()->json('data'))->pluck('id');
    expect($opts)->toContain($doc);
    $this->postJson('/api/v1/sun/diagnosis/inexistente/evidence', ['document_id' => $doc], $this->h)->assertStatus(404);
    $this->postJson('/api/v1/sun/diagnosis/legal/evidence', ['document_id' => $doc], $this->h)->assertCreated();
    $this->postJson('/api/v1/sun/diagnosis/legal/evidence', ['document_id' => $doc], $this->h)->assertCreated();   // no duplica la etiqueta
    expect(sunAs(fn () => DB::selectOne('select tags from documents where id = ?', [$doc])->tags))->toBe('{sunafil:legal}');
    $a = $legal();
    expect($a['no_module'])->toBeTrue()->and($a['pct'])->toBe(100)->and($a['result'])->toBe('complies')
        ->and($a['evidence'][0]['id'])->toBe($doc)->and($a['evidence'][0]['state'])->toBe('current');

    $this->deleteJson("/api/v1/sun/diagnosis/legal/evidence/{$doc}", [], $this->h)->assertOk();
    expect($legal()['pct'])->toBe(0);

    // Con el módulo contratado, la obligación vuelve a evaluarse con sus datos.
    foreach ($this->flags as $f) {
        DB::connection('platform')->update('update feature_flags set mode = ? where id = ?', [$f->mode, $f->id]);
    }
    \App\Core\Auth\Permissions::flush();
    expect($legal()['no_module'])->toBe(! in_array($this->flags[0]->mode ?? 'locked', ['enabled', 'read_only'], true));
});
