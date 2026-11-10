<?php

// Complementos en modo de prueba: proveedores y claves (panel), asistente IA sin proveedor (biblioteca de EscudoSST)
// y homologación de proveedores con criterios ponderados.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode, source from feature_flags where company_id = ? and module_code in ('IAA', 'HOM', 'ISO', 'PSE', 'VTA', 'AUL', 'API')", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('IAA', 'HOM', 'ISO', 'PSE', 'VTA', 'AUL', 'API')", [HUAYLAS_ID]);
    \App\Core\Auth\Permissions::flush();   // los permisos se guardan por sesión: se recalculan con los complementos activos
    $this->providers = $db->select('select * from platform_providers');
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    foreach ($this->flags as $f) {
        $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
    }
    \App\Core\Auth\Permissions::flush();
    foreach ($this->providers as $p) {
        $db->update('update platform_providers set provider = ?, mode = ?, settings = ?::jsonb, secret_enc = ?, secret_hint = ? where code = ?',
            [$p->provider, $p->mode, $p->settings, $p->secret_enc, $p->secret_hint, $p->code]);
    }
});

it('el panel guarda proveedores sin devolver las claves y exige claves para pasar a «en vivo»', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $list = $this->getJson('/api/v1/internal/providers', $h)->assertOk()->json('data');
    expect(collect($list)->pluck('code')->all())->toBe(['payments', 'einvoice', 'whatsapp', 'esign']);

    $this->putJson('/api/v1/internal/providers/payments', ['provider' => 'culqi', 'mode' => 'live', 'settings' => [], 'secrets' => []], $h)
        ->assertStatus(422)->assertJsonValidationErrors('mode');
    $this->putJson('/api/v1/internal/providers/payments', ['provider' => 'culqi', 'mode' => 'test', 'settings' => ['public_key' => 'pk_test_1'], 'secrets' => ['secret_key' => 'sk_test_ABCD1234']], $h)
        ->assertOk()->assertJsonPath('data.live', false);
    $p = collect($this->getJson('/api/v1/internal/providers', $h)->json('data'))->firstWhere('code', 'payments');
    expect($p['secrets_set']['secret_key'])->toBe('••••1234')->and(json_encode($p))->not->toContain('sk_test_ABCD1234');

    // La app de la empresa solo ve si está en prueba.
    $s = $this->getJson('/api/v1/providers/status', authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID))->assertOk()->json('data');
    expect($s['payments']['live'])->toBeFalse()->and($s['payments'])->toHaveKey('test_note');
});

it('el asistente IA responde con la biblioteca que entrena el equipo, anota lo que no sabe y no acepta datos de salud', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $r = $this->postJson('/api/v1/iaa/suggest', ['feature' => 'controls', 'text' => 'Trabajo en altura sobre andamio'], $h)->assertOk();
    expect($r->json('data.topic'))->toBe('Trabajo en altura')->and($r->json('data.items'))->not->toBeEmpty()
        ->and(collect($r->json('data.items'))->pluck('group')->implode(' '))->toContain('Ingeniería');
    $this->postJson('/api/v1/iaa/suggest', ['feature' => 'causes', 'text' => 'El trabajador tiene diabetes y se cayó'], $h)->assertStatus(422);
    $this->postJson('/api/v1/iaa/suggest', ['feature' => 'talk', 'text' => 'Orden y limpieza'], $h)->assertOk()->assertJsonCount(4, 'data.items');

    // Sin tema: guía general y la pregunta queda para enseñarle; el equipo agrega el tema y ya responde.
    $this->postJson('/api/v1/iaa/suggest', ['feature' => 'controls', 'text' => 'Soldadura en taller'], $h)->assertOk()->assertJsonPath('data.topic', null);
    expect(\Illuminate\Support\Facades\DB::selectOne("select times from assistant_misses where feature = 'controls' and question = 'soldadura en taller'"))->not->toBeNull();

    // El equipo le enseña desde el panel (se prueba con «Probar»; la transacción se revierte).
    $db = \Illuminate\Support\Facades\DB::connection('platform');
    $db->beginTransaction();
    try {
        $admin = authHeaders(tokenFor(ADMIN_EMAIL));
        $this->postJson('/api/v1/internal/assistant/topics', ['feature' => 'controls', 'title' => 'Soldadura', 'keywords' => ['soldadura', 'soldar'],
            'items' => [['group' => 'Ingeniería', 'title' => 'Extracción de humos', 'detail' => 'Extractor localizado']]], $admin)->assertCreated();
        $this->postJson('/api/v1/internal/assistant/try', ['feature' => 'controls', 'text' => 'Soldadura en taller'], $admin)->assertOk()->assertJsonPath('data.topic', 'Soldadura');
        expect(collect($this->getJson('/api/v1/internal/assistant', $admin)->json('data.topics'))->pluck('title')->all())->toContain('Soldadura', 'Trabajo en altura');
    } finally {
        $db->rollBack();
    }
});

it('homologación: criterios que suman 100 y puntaje ponderado con resultado', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $t = $this->postJson('/api/v1/records/HOM/vendor_evaluation_templates', ['code' => 'HOM-RF', 'name' => 'Proveedores críticos', 'passing_score' => 70], $h)->assertCreated()->json('data.id');
    $this->putJson("/api/v1/hom/templates/{$t}/criteria", ['criteria' => [['name' => 'SST', 'weight' => 60], ['name' => 'Calidad', 'weight' => 30]]], $h)->assertStatus(422);
    $this->putJson("/api/v1/hom/templates/{$t}/criteria", ['criteria' => [['name' => 'SST', 'weight' => 60], ['name' => 'Calidad', 'weight' => 40]]], $h)->assertOk();
    $e = $this->postJson('/api/v1/records/HOM/vendor_evaluations', ['template_id' => $t, 'vendor_name' => 'Andamios RF SAC', 'evaluated_on' => now()->toDateString()], $h)
        ->assertCreated()->json('data.id');
    $this->putJson("/api/v1/hom/evaluations/{$e}/scores", ['scores' => ['SST' => 5]], $h)->assertStatus(422);
    $this->putJson("/api/v1/hom/evaluations/{$e}/scores", ['scores' => ['SST' => 4, 'Calidad' => 3]], $h)->assertOk()
        ->assertJsonPath('data.total_score', 72)->assertJsonPath('data.rating', 'approved');
});

it('ISO evalúa las cláusulas con los datos de los módulos y PSE aplica el paquete del sector una sola vez', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $r = $this->postJson('/api/v1/iso/assess', ['standard' => 'ISO45001'], $h)->assertOk();
    expect($r->json('data.total'))->toBeGreaterThan(0)->and($r->json('data.clauses.0'))->toHaveKeys(['clause', 'status', 'evidence']);
    expect($this->getJson('/api/v1/records/ISO/iso_evidences', $h)->json('meta.total'))->toBe($r->json('data.total'));

    $pack = collect($this->getJson('/api/v1/pse/packs', $h)->assertOk()->json('data'))->firstWhere('code', 'PSE-CONST');
    $a = $this->postJson("/api/v1/pse/packs/{$pack['id']}/apply", [], $h)->assertOk()->json('data.applied');
    expect(collect($a)->pluck('type')->all())->toContain('Capacitación');
    $this->postJson("/api/v1/pse/packs/{$pack['id']}/apply", [], $h)->assertStatus(409);
});

it('venta de cursos en modo de prueba: pedido, pago simulado, matrícula de la persona y licencia de la empresa', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $course = $this->postJson('/api/v1/records/AUL/courses', ['code' => 'RF-ALT', 'title' => 'Trabajo en altura (virtual)'], $h)->assertCreated()->json('data.id');
    $offer = $this->postJson('/api/v1/records/VTA/course_offers', ['course_id' => $course, 'slug' => 'altura-rf', 'title' => 'Trabajo en altura', 'list_price' => 118, 'status' => 'published'], $h)
        ->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/VTA/course_offers/{$offer}", ['status' => 'published'], $h);

    $o = $this->postJson('/api/v1/vta/sell', ['course_offer_id' => $offer, 'buyer_type' => 'person', 'document_type' => 'DNI', 'document_number' => '70707070',
        'first_name' => 'Rosa', 'last_name' => 'Vargas', 'email' => 'rosa.rf@example.com'], $h)->assertCreated()->json('data');
    expect((float) $o['total'])->toBe(118.0)->and((float) $o['igv'])->toBe(18.0);
    $this->postJson("/api/v1/vta/orders/{$o['id']}/pay", [], $h)->assertOk()->assertJsonPath('data.test_mode', true)->assertJsonPath('data.delivered.0.type', 'enrollment');
    $this->postJson("/api/v1/vta/orders/{$o['id']}/pay", [], $h)->assertStatus(409);

    $c = $this->postJson('/api/v1/vta/sell', ['course_offer_id' => $offer, 'buyer_type' => 'company', 'document_type' => 'RUC', 'document_number' => '20512345678',
        'legal_name' => 'Constructora Cliente SAC', 'ruc' => '20512345678', 'email' => 'compras@example.com', 'seats' => 5], $h)->assertCreated()->json('data');
    $this->postJson("/api/v1/vta/orders/{$c['id']}/pay", [], $h)->assertOk()->assertJsonPath('data.delivered.0.type', 'license')->assertJsonPath('data.delivered.0.seats', 5);
});

it('API: lectura con clave, secreto y empresa; webhook encolado al registrar un evento', function () {
    $key = 'ck_rf_'.\Illuminate\Support\Str::random(10);
    // El cliente se crea directo (el formulario lo crea el administrador de la cuenta).
    \Illuminate\Support\Facades\DB::transaction(function () use ($key) {
        \App\Core\Tenancy\DbContext::setCompany(HUAYLAS_ID);
        \Illuminate\Support\Facades\DB::insert("insert into api_clients (company_id, name, client_key, secret_hash, scopes) values (?, 'RF', ?, ?, '{read}')", [HUAYLAS_ID, $key, hash('sha256', 'sk_rf_secreto')]);
        \Illuminate\Support\Facades\DB::insert("insert into webhooks (company_id, name, target_url, event_codes, secret) values (?, 'RF', 'https://example.com/hook', '{report.received}', 'whsec_rf')", [HUAYLAS_ID]);
    });

    $this->getJson('/api/v1/ext/v1/workers')->assertStatus(401);
    $this->getJson('/api/v1/ext/v1/workers', ['X-Company-Id' => HUAYLAS_ID, 'X-Api-Key' => $key, 'X-Api-Secret' => 'malo'])->assertStatus(401);
    $this->getJson('/api/v1/ext/v1/workers', ['X-Company-Id' => HUAYLAS_ID, 'X-Api-Key' => $key, 'X-Api-Secret' => 'sk_rf_secreto'])->assertOk()->assertJsonStructure(['data', 'meta' => ['page', 'has_more']]);
    $this->getJson('/api/v1/ext/v1/salud', ['X-Company-Id' => HUAYLAS_ID, 'X-Api-Key' => $key, 'X-Api-Secret' => 'sk_rf_secreto'])->assertNotFound();

    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $rep = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'unsafe_act', 'description' => 'RF evento para webhook'], $h)->assertCreated()->json('data.id');
    $this->getJson("/api/v1/records/API/webhook_deliveries?f[event_id]={$rep}", $h)->assertOk()->assertJsonPath('meta.total', 1);
});

it('aula virtual: lección vista, examen con nota y curso completado', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $course = $this->postJson('/api/v1/records/AUL/courses', ['code' => 'RF-AUL', 'title' => 'Inducción virtual', 'passing_score' => 60], $h)->assertCreated()->json('data.id');
    $l1 = $this->postJson('/api/v1/records/AUL/course_lessons', ['course_id' => $course, 'lesson_number' => 1, 'title' => 'Bienvenida', 'lesson_type' => 'text', 'content_html' => '<p>Hola</p>'], $h)
        ->assertCreated()->json('data.id');
    $bank = $this->postJson('/api/v1/records/AUL/question_banks', ['code' => 'RF-QB', 'name' => 'Preguntas inducción', 'course_id' => $course], $h)->assertCreated()->json('data.id');
    $q = $this->postJson('/api/v1/records/AUL/questions', ['question_bank_id' => $bank, 'question_type' => 'single_choice', 'statement' => '¿Qué haces si ves un cable pelado?'], $h)
        ->assertCreated()->json('data.id');
    $this->putJson("/api/v1/aul/questions/{$q}/options", ['options' => [['text' => 'Nada', 'correct' => false], ['text' => 'Lo reporto', 'correct' => false]]], $h)->assertStatus(422);
    $this->putJson("/api/v1/aul/questions/{$q}/options", ['options' => [['text' => 'Nada', 'correct' => false], ['text' => 'Lo reporto', 'correct' => true]]], $h)->assertOk();
    $l2 = $this->postJson('/api/v1/records/AUL/course_lessons', ['course_id' => $course, 'lesson_number' => 2, 'title' => 'Examen', 'lesson_type' => 'exam', 'question_bank_id' => $bank], $h)
        ->assertCreated()->json('data.id');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '76767676', 'first_name' => 'Iris', 'paternal_last_name' => 'Aula'], $h)->json('data.id');
    $e = $this->postJson('/api/v1/records/AUL/course_enrollments', ['course_id' => $course, 'worker_id' => $w], $h)->assertCreated()->json('data.id');

    $view = $this->getJson("/api/v1/aul/enrollments/{$e}", $h)->assertOk()->json('data');
    expect($view['lessons'])->toHaveCount(2)->and(json_encode($view))->not->toContain('"correct"');
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$l1}/done", [], $h)->assertOk()->assertJsonPath('data.progress_pct', 50);
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$l2}/exam", ['answers' => [$q => '0']], $h)->assertOk()->assertJsonPath('data.passed', false);
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$l2}/exam", ['answers' => [$q => '1']], $h)->assertOk()
        ->assertJsonPath('data.passed', true)->assertJsonPath('data.enrollment.status', 'completed')
        ->assertJsonPath('data.enrollment.certificate.code', fn ($c) => str_starts_with($c, 'CERT-'));
    // El certificado queda en Capacitaciones con su código verificable.
    expect($this->getJson('/api/v1/records/CAP/training_certificates?f[worker_id]='.$w, $h)->json('meta.total'))->toBe(1);
});
