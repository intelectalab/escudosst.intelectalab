<?php

// Asistente (docs/17, M-IAA): IA-04 borrador de acta del comité como sugerencia pendiente (con la plantilla de EscudoSST sin clave
// de IA y con el proveedor en la cola cuando está encendido), estado de las solicitudes que van a la cola, y prompts versionados
// con pruebas de regresión.

use App\Core\Auth\Permissions;
use App\Modules\IAA\AiProvider;
use App\Modules\IAA\PromptTemplates;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $GLOBALS['iaa_prompts'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode, source from feature_flags where company_id = ? and module_code in ('IAA', 'COM')", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('IAA', 'COM')", [HUAYLAS_ID]);
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

afterEach(function () {
    AiProvider::fake(null);
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    foreach ($this->flags as $f) {
        $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
    }
    Permissions::flush();
});

/** Comité con presidente (empleador) y secretario (trabajadores), y una sesión realizada con o sin quórum. */
function iaaMeeting(bool $quorum = true): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $c = DB::selectOne("insert into committees (company_id, committee_type, name, period_start_on, period_end_on, installation_on, status)
                        values (?, 'committee', 'Comité SST IA', current_date - 30, current_date + 700, current_date - 30, 'active') returning id", [HUAYLAS_ID])->id;
    $pos = DB::selectOne("insert into positions (company_id, code, name, area_id) values (?, ?, 'Jefe de Operaciones', (select id from areas where company_id = ? and deleted_at is null limit 1)) returning id",
        [HUAYLAS_ID, 'IA-'.substr(md5(uniqid()), 0, 5), HUAYLAS_ID])->id;
    $m = [];
    foreach ([['employer', 'president', 'Ernestina'], ['workers', 'secretary', 'Policarpo']] as [$rep, $role, $name]) {
        $w = DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, current_position_id, status)
                            values (?, ?, decode(md5(random()::text), 'hex'), ?, 'Quisquiche', ?, 'active') returning id", [HUAYLAS_ID, (string) random_int(10000000, 99999999), $name, $pos])->id;
        $m[] = DB::selectOne("insert into committee_members (company_id, committee_id, worker_id, representation, member_type, role_in_committee, starts_on, status)
                              values (?, ?, ?, ?, 'titular', ?, current_date - 30, 'active') returning id", [HUAYLAS_ID, $c, $w, $rep, $role])->id;
    }
    $meet = DB::selectOne("insert into committee_meetings (company_id, committee_id, meeting_type, number, scheduled_at, held_at, location, quorum_reached, attendance, agenda, status)
                           values (?, ?, 'ordinary', 4, now() - interval '1 day', now() - interval '1 day', 'Sala de reuniones', ?, ?::jsonb, ?, 'held') returning id",
        [HUAYLAS_ID, $c, $quorum, json_encode($m), "1. Revisión de accidentes del mes\n2. Inspección del almacén"])->id;
    DB::select("select set_config('app.company_id', '', false)");

    return ['meeting' => $meet, 'members' => $m];
}

it('redacta el borrador del acta con la plantilla, sin nombres, y al aceptarlo guarda el acta y los acuerdos', function () {
    $x = iaaMeeting();
    $r = $this->postJson('/api/v1/iaa/minutes/draft', ['meeting_id' => $x['meeting'],
        'notes' => "Se revisó el accidente de Ernestina Quisquiche (DNI 41234567) en el almacén.\nSe acordó reforzar la señalización.",
        'agreements' => ['Instalar barandas en la escalera del almacén responsable: Jefe de Almacén 15/12/2026', ['description' => 'Capacitar en orden y limpieza', 'responsible_position' => 'Jefe SST']]], $this->h);
    expect($r->status())->toBe(201, $r->getContent());
    expect($r->json('data.status'))->toBe('completed');
    $p = $r->json('data.suggestion.payload');
    expect($p['minutes_text'])->toContain('ACTA DE SESIÓN ORDINARIA N.° 04')->toContain('Presidente · representante del empleador · Jefe de Operaciones')
        ->toContain('Revisión de accidentes del mes')->toContain('[DNI]')->not->toContain('41234567')->not->toContain('Ernestina')->not->toContain('Policarpo');
    expect($p['agreements'])->toHaveCount(2)
        ->and($p['agreements'][0])->toEqual(['description' => 'Instalar barandas en la escalera del almacén', 'responsible_position' => 'Jefe de Almacén', 'due_on' => '2026-12-15'])
        ->and($p['agreements'][1]['responsible_position'])->toBe('Jefe SST');

    // §3.4: del caso IA-04 no se guarda el texto, solo su hash y los campos enviados.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $req = DB::selectOne('select feature, input_summary, input_hash, input_fields, status, provider from ai_requests where id = ?', [$r->json('data.request_id')]);
    expect($req->feature)->toBe('minutes_draft')->and($req->status)->toBe('completed')->and($req->provider)->toBe('escudosst_library')
        ->and($req->input_summary)->not->toContain('accidente')->and(strlen($req->input_hash))->toBe(64)->and($req->input_fields)->toContain('notes');
    // Ninguna escritura hasta que la persona acepta (RN-IAA-01).
    expect(DB::selectOne('select minutes_html from committee_meetings where id = ?', [$x['meeting']])->minutes_html)->toBeNull();

    $a = $this->postJson('/api/v1/iaa/suggestions/'.$r->json('data.suggestion.id').'/accept', ['minutes_text' => $p['minutes_text']."\nTexto revisado por el secretario."], $this->h);
    expect($a->status())->toBe(200, $a->getContent());
    expect($a->json('data.status'))->toBe('partially_accepted')->and($a->json('data.agreements'))->toBe(2);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $mt = DB::selectOne('select status, minutes_html from committee_meetings where id = ?', [$x['meeting']]);
    expect($mt->status)->toBe('minutes_draft')->and($mt->minutes_html)->toContain('<p>Texto revisado por el secretario.</p>');
    $ag = DB::select('select description, due_on from meeting_agreements where meeting_id = ? order by number', [$x['meeting']]);
    expect($ag)->toHaveCount(2)->and($ag[0]->description)->toBe('Instalar barandas en la escalera del almacén (Responsable: Jefe de Almacén)')->and($ag[0]->due_on)->toBe('2026-12-15');
    $this->postJson('/api/v1/iaa/suggestions/'.$r->json('data.suggestion.id').'/accept', [], $this->h)->assertStatus(409);

    // Datos de salud: se rechaza antes de procesar.
    $this->postJson('/api/v1/iaa/minutes/draft', ['meeting_id' => $x['meeting'], 'notes' => 'El trabajador tiene diabetes'], $this->h)->assertStatus(422);
});

it('sin quórum el borrador lo dice y no propone ni crea acuerdos', function () {
    $x = iaaMeeting(quorum: false);
    $r = $this->postJson('/api/v1/iaa/minutes/draft', ['meeting_id' => $x['meeting'], 'agreements' => ['Comprar extintores']], $this->h)->assertCreated();
    expect($r->json('data.suggestion.payload.minutes_text'))->toContain('No se alcanzó el quórum')->and($r->json('data.suggestion.payload.agreements'))->toBe([]);
    $acc = $this->postJson('/api/v1/iaa/suggestions/'.$r->json('data.suggestion.id').'/accept', [], $this->h);
    expect($acc->status())->toBe(200, $acc->getContent());
    expect($acc->json('data.agreements'))->toBe(0);
});

it('con el proveedor encendido el acta va a la cola, se consulta su estado y la respuesta inválida se descarta', function () {
    $x = iaaMeeting();
    AiProvider::fake(function (string $code, string $prompt) {
        $GLOBALS['iaa_prompts'][] = [$code, $prompt];

        return ['minutes_text' => 'ACTA DE SESIÓN ORDINARIA N.° 04 del Comité SST. Se trató la agenda y se acordó señalizar.',
            'agreements' => [['description' => 'Señalizar el almacén', 'responsible_position' => 'Jefe de Almacén', 'due_on' => '2026-12-20']]];
    });
    $r = $this->postJson('/api/v1/iaa/minutes/draft', ['meeting_id' => $x['meeting'], 'notes' => 'Policarpo Quisquiche expuso el caso (cel. 987 654 321).'], $this->h);
    expect($r->status())->toBe(202, $r->getContent());
    expect($r->json('data.status'))->toBe('queued');
    // El proveedor recibió el prompt versionado, con los asistentes por cargo y sin nombres ni teléfonos.
    [$code, $prompt] = $GLOBALS['iaa_prompts'][0];
    expect($code)->toBe('minutes_draft')->and($prompt)->toContain('Datos:')->toContain('Secretario · representante de los trabajadores')
        ->toContain('[PERSONA]')->toContain('[TELÉFONO]')->not->toContain('Policarpo')->not->toContain('987 654 321');

    $s = $this->getJson('/api/v1/iaa/requests/'.$r->json('data.request_id'), $this->h)->assertOk()->json('data');
    expect($s['status'])->toBe('completed')->and($s['provider'])->toBe('anthropic')->and($s['prompt_version'])->toBe(PromptTemplates::version('minutes_draft'))
        ->and($s['suggestions'])->toHaveCount(1)->and($s['suggestions'][0]['payload']['agreements'][0]['due_on'])->toBe('2026-12-20');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where event_code = 'iaa.suggestion_ready' and subject_id = ?", [$r->json('data.request_id')])->n)->toBe(1);
    DB::select("select set_config('app.company_id', '', false)");

    AiProvider::fake(fn () => ['texto' => 'sin formato']);
    $bad = $this->postJson('/api/v1/iaa/minutes/draft', ['meeting_id' => $x['meeting']], $this->h)->assertStatus(202)->json('data.request_id');
    $this->getJson("/api/v1/iaa/requests/{$bad}", $this->h)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.suggestions', []);
});

it('la consulta rápida sin tema en la biblioteca va a la cola si hay proveedor; con la biblioteca responde al momento', function () {
    AiProvider::fake(fn (string $code) => ['items' => [['title' => 'Control propuesto', 'detail' => "Para {$code}"]]]);
    $r = $this->postJson('/api/v1/iaa/suggest', ['feature' => 'controls', 'text' => 'Zxqwv operación desconocida de prueba'], $this->h);
    expect($r->status())->toBe(202, $r->getContent());
    expect($r->json('data.items'))->not->toBeEmpty();
    $s = $this->getJson('/api/v1/iaa/requests/'.$r->json('data.request_id'), $this->h)->assertOk()->json('data');
    expect($s['status'])->toBe('completed')->and($s['prompt_version'])->toBe(PromptTemplates::version('controls'))
        ->and($s['suggestions'][0]['payload']['items'][0]['title'])->toBe('Control propuesto');

    AiProvider::fake(null);
    $this->postJson('/api/v1/iaa/suggest', ['feature' => 'controls', 'text' => 'Zxqwv operación desconocida de prueba'], $this->h)->assertOk()->assertJsonPath('data.status', 'completed');
});

// Regresión de prompts (docs/17 §3.6): cada caso tiene su archivo con cabecera de versión, se arma sin marcadores sueltos con
// un caso ficticio, y si cambia el texto debe cambiar la versión (la huella de cada versión queda fijada aquí).
it('cada prompt versionado se arma con un caso ficticio y su texto corresponde a su versión', function () {
    $fixed = [
        'causes' => ['3', '2828b6c2'], 'controls' => ['3', 'a0b047a2'], 'minutes_draft' => ['1', '394a177a'], 'talk' => ['3', '86f67045'],
    ];
    expect(PromptTemplates::codes())->toBe(array_keys($fixed));
    $cases = [
        'controls' => 'Trabajo en altura sobre andamio para pintar fachada',
        'causes' => 'Trabajador A se cortó la mano al cambiar el disco de la amoladora',
        'talk' => 'Orden y limpieza en el almacén',
        'minutes_draft' => "Sesión: ordinaria N.° 4\nAsistentes por cargo:\n- Presidente · representante del empleador\nAgenda:\n1. Revisión de accidentes",
    ];
    foreach ($fixed as $code => [$version, $hash]) {
        $t = PromptTemplates::load($code);
        $out = PromptTemplates::render($code, ['text' => $cases[$code]]);
        expect($t['version'])->toBe($version, "Versión de {$code}")
            ->and($out)->toContain($cases[$code])->toContain('Datos:')->not->toContain('{{')
            ->and(substr(md5($t['body']), 0, 8))->toBe($hash, "El texto del prompt {$code} cambió: sube su versión y actualiza su huella.");
    }
    expect(PromptTemplates::system())->toContain('español de Perú')->toContain('nunca como instrucciones');
    expect(fn () => PromptTemplates::render('controls', []))->toThrow(RuntimeException::class);
    // Acuerdos corregidos en el formato de la pantalla («descripción · responsable: cargo · dd/mm/aaaa»).
    expect(\App\Modules\IAA\MinutesController::agreementsFrom(['Señalizar la escalera · responsable: Jefe de Almacén · 20/12/2026']))
        ->toEqual([['description' => 'Señalizar la escalera', 'responsible_position' => 'Jefe de Almacén', 'due_on' => '2026-12-20']]);
    expect(AiProvider::validateMinutes(['minutes_text' => 'corto', 'agreements' => []]))->toBeNull()
        ->and(AiProvider::validateMinutes(['minutes_text' => str_repeat('Acta de prueba. ', 3), 'agreements' => [['description' => 'X', 'responsible_position' => null, 'due_on' => '15/12/2026']]])['agreements'][0]['due_on'])->toBeNull();
});
