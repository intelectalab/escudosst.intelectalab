<?php

// App del trabajador (docs/07 §5.2, docs/10 §5): activación con DNI + código de un solo uso, PIN ligado al equipo con
// bloqueo por intentos, datos solo propios en /me/worker/* y resultados médicos con re-autenticación.

use App\Modules\EMO\MedicalCipher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

/** Trabajador nuevo de Huaylas creado por el Jefe SST. */
function wappWorker(string $dni, string $first): string
{
    return test()->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => $first, 'paternal_last_name' => 'Prueba'],
        authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID))->assertCreated()->json('data.id');
}

/** Genera la activación (código y enlace) desde la ficha del trabajador. */
function wappIssue(string $workerId): array
{
    return test()->postJson("/api/v1/trb/workers/{$workerId}/app-access", [], authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID))->assertCreated()->json('data');
}

/** Activa la app con DNI + código y devuelve la respuesta (sesión + equipo). */
function wappActivate(string $dni, string $code, string $pin = '482613', ?string $device = null): array
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/worker/activate', ['dni' => $dni, 'code' => $code, 'pin' => $pin, 'pin_confirmation' => $pin,
        'device_uid' => $device ?? 'wdev-'.uniqid()])->assertCreated()->json();
}

/** Inserta con la empresa fijada (solo para preparar datos de la prueba). */
function wappAs(string $company, callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [$company]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

it('activa con DNI + código de un solo uso y rechaza DNI ajeno, código usado, reemplazado o vencido', function () {
    $w = wappWorker('71717171', 'Lucía');
    $a = wappIssue($w);
    expect($a['code'])->toMatch('/^\d{8}$/')->and($a['link'])->toContain('/mi/activar?t=');

    // DNI que no es el del trabajador → mismo error que un código inválido.
    $this->postJson('/api/v1/auth/worker/activate', ['dni' => '72727272', 'code' => $a['code'], 'pin' => '482613', 'pin_confirmation' => '482613', 'device_uid' => 'wdev-x'])
        ->assertStatus(410)->assertJsonPath('type', 'activation_invalid');
    // PIN débil.
    $this->postJson('/api/v1/auth/worker/activate', ['dni' => '71717171', 'code' => $a['code'], 'pin' => '123456', 'pin_confirmation' => '123456', 'device_uid' => 'wdev-x'])
        ->assertStatus(422)->assertJsonValidationErrors('pin');

    $s = wappActivate('71717171', $a['code']);
    expect($s['token'])->not->toBeEmpty()->and($s['company_id'])->toBe(HUAYLAS_ID)->and($s['device']['secret'])->toHaveLength(48);
    // Cuenta con rol trabajador ligada a la ficha.
    $m = wappAs(HUAYLAS_ID, fn () => DB::selectOne('select r.code, m.status from memberships m join roles r on r.id = m.role_id where m.worker_id = ?', [$w]));
    expect($m->code)->toBe('worker')->and($m->status)->toBe('active');
    $this->getJson('/api/v1/me/worker', authHeaders($s['token'], HUAYLAS_ID))->assertOk()->assertJsonPath('data.worker.id', $w);

    // Un solo uso.
    $this->postJson('/api/v1/auth/worker/activate', ['dni' => '71717171', 'code' => $a['code'], 'pin' => '482613', 'pin_confirmation' => '482613', 'device_uid' => 'wdev-y'])
        ->assertStatus(410);

    // Una activación nueva anula la anterior; el enlace también sirve.
    $old = wappIssue($w);
    $new = wappIssue($w);
    $this->postJson('/api/v1/auth/worker/activate', ['dni' => '71717171', 'code' => $old['code'], 'pin' => '482613', 'pin_confirmation' => '482613', 'device_uid' => 'wdev-z'])
        ->assertStatus(410);
    $t = substr($new['link'], strpos($new['link'], '?t=') + 3);
    $this->getJson('/api/v1/auth/worker/activation?t='.$t)->assertOk()->assertJsonPath('data.first_name', 'Lucía');

    // Vencida.
    $this->travel((int) config('escudo.worker_app.activation_hours') + 1)->hours();
    $this->postJson('/api/v1/auth/worker/activate', ['t' => $t, 'pin' => '482613', 'pin_confirmation' => '482613', 'device_uid' => 'wdev-z'])->assertStatus(410);
});

it('ingresa con DNI + PIN solo desde el equipo activado y bloquea tras varios PIN equivocados', function () {
    wappWorker('73737373', 'Mario');
    $w = wappAs(HUAYLAS_ID, fn () => DB::selectOne("select id from workers where first_name = 'Mario' and paternal_last_name = 'Prueba' order by created_at desc limit 1")->id);
    $s = wappActivate('73737373', wappIssue($w)['code'], '582917');
    $body = ['company_id' => HUAYLAS_ID, 'device_id' => $s['device']['id'], 'device_secret' => $s['device']['secret'], 'dni' => '73737373', 'pin' => '582917'];

    app('auth')->forgetGuards();
    $ok = $this->postJson('/api/v1/auth/worker/pin-login', $body)->assertOk()->assertJsonPath('company_id', HUAYLAS_ID)->json('token');
    $this->getJson('/api/v1/me/worker', authHeaders($ok, HUAYLAS_ID))->assertOk();

    // Otro equipo (secreto distinto) → no activado.
    $this->postJson('/api/v1/auth/worker/pin', ['device_secret' => str_repeat('x', 48)] + $body)->assertStatus(401)->assertJsonPath('type', 'device_unknown');
    // DNI ajeno → mismo error que un PIN equivocado.
    $this->postJson('/api/v1/auth/worker/pin', ['dni' => '70000000'] + $body)->assertStatus(401)->assertJsonPath('type', 'invalid_credentials');

    $max = (int) config('escudo.worker_app.pin_max_attempts');
    for ($i = 1; $i < $max - 1; $i++) {
        $this->postJson('/api/v1/auth/worker/pin', ['pin' => '000000'] + $body)->assertStatus(401);
    }
    // El último intento fallido bloquea; luego ni el PIN correcto entra.
    $this->postJson('/api/v1/auth/worker/pin', ['pin' => '000000'] + $body)->assertStatus(423)->assertJsonPath('type', 'pin_locked');
    $this->postJson('/api/v1/auth/worker/pin', $body)->assertStatus(423);

    $this->travel((int) config('escudo.worker_app.pin_lock_minutes') + 1)->minutes();
    $this->postJson('/api/v1/auth/worker/pin', $body)->assertOk();

    // El PIN queda con hash, nunca en claro.
    $hash = wappAs(HUAYLAS_ID, fn () => DB::selectOne('select pin_hash from workers where id = ?', [$w])->pin_hash);
    expect($hash)->not->toContain('582917')->and(password_verify('582917', $hash))->toBeTrue();
});

it('devuelve solo los datos propios: el id de otro trabajador responde 404', function () {
    $a = wappWorker('74747474', 'Ana');
    $b = wappWorker('75757575', 'Beto');
    $sa = wappActivate('74747474', wappIssue($a)['code'], '397154');
    [$mine, $theirs] = wappAs(HUAYLAS_ID, function () use ($a, $b) {
        $item = DB::selectOne("insert into ppe_items (company_id, code, name, category) values (?, 'EPP-WAPP', 'Guantes anticorte', 'hand') returning id", [HUAYLAS_ID])->id;
        $ins = fn ($w) => DB::selectOne('insert into ppe_deliveries (company_id, worker_id, ppe_item_id, quantity, delivered_at, expires_on) values (?, ?, ?, 1, now(), current_date + 90) returning id',
            [HUAYLAS_ID, $w, $item])->id;

        return [$ins($a), $ins($b)];
    });
    $h = authHeaders($sa['token'], HUAYLAS_ID);

    $pending = collect($this->getJson('/api/v1/me/worker/pending', $h)->assertOk()->json('data'));
    expect($pending->where('kind', 'ppe')->pluck('id')->all())->toBe([$mine]);
    expect(collect($this->getJson('/api/v1/me/worker/ppe', $h)->assertOk()->json('data'))->pluck('id')->all())->toBe([$mine]);

    $this->getJson("/api/v1/me/worker/items/ppe/{$theirs}", $h)->assertNotFound();
    $this->postJson('/api/v1/me/worker/sign', ['kind' => 'ppe', 'id' => $theirs, 'pin' => '397154'], $h)->assertNotFound();

    $this->getJson("/api/v1/me/worker/items/ppe/{$mine}", $h)->assertOk()->assertJsonPath('data.signed', false);
    $this->postJson('/api/v1/me/worker/sign', ['kind' => 'ppe', 'id' => $mine, 'pin' => '000000'], $h)->assertStatus(422)->assertJsonValidationErrors('pin');
    $this->postJson('/api/v1/me/worker/sign', ['kind' => 'ppe', 'id' => $mine, 'pin' => '397154'], $h)->assertOk()->assertJsonPath('data.status', 'signed');
    $this->postJson('/api/v1/me/worker/sign', ['kind' => 'ppe', 'id' => $mine, 'pin' => '397154'], $h)->assertStatus(409);
    $sig = wappAs(HUAYLAS_ID, fn () => DB::selectOne('select s.method, s.signer_worker_id from ppe_deliveries d join signatures s on s.id = d.signature_id where d.id = ?', [$mine]));
    expect($sig->method)->toBe('pin')->and($sig->signer_worker_id)->toBe($a);

    // Los registros del trabajador por el motor común también se limitan a los suyos.
    $ids = collect($this->getJson('/api/v1/records/EPP/ppe_deliveries', $h)->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toContain($mine)->not->toContain($theirs);

    // Archivos: solo los suyos o los documentos que la empresa publica; el de otra ficha responde 403.
    [$ownFile, $otherFile] = wappAs(HUAYLAS_ID, function () use ($a, $b) {
        $ins = fn ($w) => \Illuminate\Support\Facades\Storage::disk('local')->put("wapp/{$w}.pdf", '%PDF-1.4') ? DB::selectOne("insert into files (company_id, bucket, object_key, original_name, mime_type, size_bytes, sha256, module_code, entity_type, entity_id, status, exif_stripped)
            values (?, 'local', 'wapp/'||?||'.pdf', 'x.pdf', 'application/pdf', 8, decode('00', 'hex'), 'TRB', 'workers', ?, 'available', false) returning id", [HUAYLAS_ID, $w, $w])->id : null;

        return [$ins($a), $ins($b)];
    });
    $this->get("/api/v1/files/{$ownFile}", $h)->assertOk();
    $this->get("/api/v1/files/{$otherFile}", $h)->assertForbidden();
});

it('muestra los resultados médicos propios solo con re-autenticación por PIN', function () {
    DB::connection('medical')->beginTransaction();
    try {
        $a = wappWorker('76767676', 'Rosa');
        $b = wappWorker('77777777', 'Pedro');
        $sa = wappActivate('76767676', wappIssue($a)['code'], '614285');
        $med = DB::connection('medical');
        $med->select("select set_config('app.company_id', ?, false), set_config('app.reauth_session_id', ?, false)", [HUAYLAS_ID, (string) Str::uuid()]);
        foreach ([[$a, 'Audiometría normal'], [$b, 'Espirometría con hallazgo']] as [$w, $res]) {
            $med->insert("insert into medical_exams (company_id, worker_id, exam_type, exam_on, results_summary, fitness_result, status) values (?, ?, 'periodic', current_date, ?, 'fit', 'validated')",
                [HUAYLAS_ID, $w, MedicalCipher::encrypt(json_encode(['results' => [['exam' => 'Examen', 'result' => $res]]]))]);
        }
        $med->select("select set_config('app.company_id', '', false), set_config('app.reauth_session_id', '', false)");
        $h = authHeaders($sa['token'], HUAYLAS_ID);

        // Sin re-autenticación: solo la aptitud (sin datos clínicos) y los resultados piden el PIN.
        $this->getJson('/api/v1/me/worker/health', $h)->assertOk();
        $this->getJson('/api/v1/me/worker/health/results', $h)->assertStatus(403)->assertJsonPath('type', 'reauth_required');
        $this->postJson('/api/v1/me/worker/health/reauth', ['pin' => '111199'], $h)->assertStatus(422);
        $r = $this->postJson('/api/v1/me/worker/health/reauth', ['pin' => '614285'], $h)->assertOk()->json('data.reauth_id');

        $exams = $this->getJson('/api/v1/me/worker/health/results', $h + ['X-Medical-Reauth' => $r])->assertOk()->json('data.exams');
        expect($exams)->toHaveCount(1)->and($exams[0]['results'][0]['result'])->toBe('Audiometría normal');
        $log = wappAs(HUAYLAS_ID, fn () => DB::selectOne('select role_code, worker_id from sensitive_access_logs where reauth_session_id = ? order by created_at desc limit 1', [$r]));
        expect($log->role_code)->toBe('worker')->and($log->worker_id)->toBe($a);

        // La clave médica de otra persona no sirve.
        $this->getJson('/api/v1/me/worker/health/results', $h + ['X-Medical-Reauth' => (string) Str::uuid()])->assertStatus(403);
    } finally {
        DB::connection('medical')->rollBack();
    }
});

it('solo quien gestiona trabajadores genera la activación', function () {
    $a = wappWorker('78787878', 'Iris');
    $s = wappActivate('78787878', wappIssue($a)['code'], '805371');
    $this->postJson("/api/v1/trb/workers/{$a}/app-access", [], authHeaders($s['token'], HUAYLAS_ID))->assertForbidden();
    $this->getJson("/api/v1/trb/workers/{$a}/app-access", authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID))->assertOk()
        ->assertJsonPath('data.devices', 1)->assertJsonPath('data.pending_activation', false);
});

it('liga el reporte del trabajador a su ficha y lo muestra en Mis reportes (anónimo no)', function () {
    $a = wappWorker('79797979', 'Nora');
    $s = wappActivate('79797979', wappIssue($a)['code'], '295846');
    $h = authHeaders($s['token'], HUAYLAS_ID);
    $id = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'unsafe_condition', 'description' => 'Cable pelado en la línea 2'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'unsafe_act', 'description' => 'Sin arnés en altura', 'is_anonymous' => true], $h)->assertCreated();
    $mine = collect($this->getJson('/api/v1/me/worker/reports', $h)->assertOk()->json('data'));
    expect($mine->pluck('id')->all())->toBe([$id]);
    expect(wappAs(HUAYLAS_ID, fn () => DB::selectOne('select reporter_worker_id from substandard_reports where id = ?', [$id])->reporter_worker_id))->toBe($a);
});
