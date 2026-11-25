<?php

// docs/08 §6-8 y docs/02 §11-12: Idempotency-Key en los POST (respuesta original al repetir, 409 con otro cuerpo), código
// estable invalid_transition para transiciones no permitidas, logs en JSON con campos fijos y sin secretos ni DNI completo.
// docs/10 §6: tipo real del archivo (magic bytes) en las cargas de planillas.

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Logging\StructuredJsonFormatter;
use App\Core\Support\UploadGuard;
use App\Core\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    DB::connection('platform')->delete("delete from idempotency_keys where idempotency_key like 'pest-%'");
    Permissions::flush();
});

it('repite la misma respuesta con la misma Idempotency-Key y no vuelve a crear el registro', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $key = 'pest-'.Str::uuid7();
    $body = ['requester_name' => 'Idempotente', 'request_type' => 'opposition', 'channel' => 'email', 'description' => 'Se opone al envío de publicidad.'];

    $first = $this->postJson('/api/v1/records/TRB/arco_requests', $body, $h + ['Idempotency-Key' => $key])->assertCreated();
    $again = $this->postJson('/api/v1/records/TRB/arco_requests', $body, $h + ['Idempotency-Key' => $key])->assertCreated();
    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and($again->headers->get('Idempotent-Replay'))->toBe('true')
        ->and($first->headers->get('Idempotent-Replay'))->toBeNull();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect((int) DB::selectOne("select count(*) as n from arco_requests where requester_name = 'Idempotente'")->n)->toBe(1);

    // La misma clave con otro cuerpo: 409 conflict; sin clave, la petición sigue como siempre.
    $this->postJson('/api/v1/records/TRB/arco_requests', ['description' => 'Otro'] + $body, $h + ['Idempotency-Key' => $key])
        ->assertStatus(409)->assertJsonPath('type', 'conflict');
    $this->postJson('/api/v1/records/TRB/arco_requests', $body, $h)->assertCreated();

    // La clave es de la sesión: otra persona con la misma clave no recibe la respuesta guardada.
    $other = $this->postJson('/api/v1/records/TRB/arco_requests', $body, authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID) + ['Idempotency-Key' => $key]);
    expect($other->headers->get('Idempotent-Replay'))->toBeNull();
});

it('no guarda los errores: la misma clave se puede reintentar después de corregir los datos', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $key = 'pest-'.Str::uuid7();
    $this->postJson('/api/v1/records/TRB/arco_requests', ['request_type' => 'access'], $h + ['Idempotency-Key' => $key])->assertStatus(422);
    expect(DB::connection('platform')->selectOne('select count(*) as n from idempotency_keys where idempotency_key = ?', [$key])->n)->toBe(0);
    $this->postJson('/api/v1/records/TRB/arco_requests', ['request_type' => 'access'], $h + ['Idempotency-Key' => str_repeat('x', 101)])->assertStatus(400);
});

it('responde 409 invalid_transition cuando el estado no permite el cambio', function () {
    $p = ApiProblem::invalidTransition('La acción ya está cerrada.');
    expect($p->status)->toBe(409)->and($p->type)->toBe('invalid_transition');

    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $id = $this->postJson('/api/v1/records/TRB/arco_requests', ['requester_name' => 'Flujo', 'request_type' => 'access', 'channel' => 'email', 'description' => 'x'], $h)
        ->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/TRB/arco_requests/{$id}", ['status' => 'withdrawn'], $h)->assertOk();
    $this->patchJson("/api/v1/records/TRB/arco_requests/{$id}", ['status' => 'in_review'], $h)
        ->assertStatus(409)->assertJsonPath('type', 'invalid_transition')->assertHeader('Content-Type', 'application/problem+json');
});

it('escribe los logs en JSON con los campos de docs/02 §12, sin secretos y con el DNI enmascarado', function () {
    $stream = fopen('php://memory', 'w+');
    $handler = new StreamHandler($stream);
    $handler->setFormatter(new StructuredJsonFormatter);
    $log = new Logger('test', [$handler]);
    $log->warning('No se pudo enviar el aviso al 45678912', ['password' => 'secreto', 'token' => 'abc', 'worker' => ['dni' => '12345678'], 'trace_id' => 'T-1']);
    rewind($stream);
    $line = json_decode(trim((string) stream_get_contents($stream)), true);

    expect(array_keys($line))->toContain('ts', 'level', 'trace_id', 'company_id', 'user_id', 'route', 'job', 'duration_ms', 'message')
        ->and($line['level'])->toBe('warning')
        ->and($line['trace_id'])->toBe('T-1')
        ->and($line['message'])->toBe('No se pudo enviar el aviso al 4567****')
        ->and($line['context']['password'])->toBe('[oculto]')
        ->and($line['context']['token'])->toBe('[oculto]')
        ->and($line['context']['worker']['dni'])->toBe('1234****');
    expect(config('logging.channels.json.formatter'))->toBe(StructuredJsonFormatter::class);
});

it('rechaza una planilla cuyo contenido no es Excel ni CSV aunque su nombre lo diga', function () {
    $fake = UploadedFile::fake()->createWithContent('trabajadores.xlsx', '<html><script>alert(1)</script></html>');
    expect(fn () => UploadGuard::spreadsheet($fake))->toThrow(ApiProblem::class);
    $zipNoSheet = UploadedFile::fake()->createWithContent('trabajadores.xlsx', (function () {
        $p = tempnam(sys_get_temp_dir(), 'z');
        $z = new ZipArchive;
        $z->open($p, ZipArchive::OVERWRITE);
        $z->addFromString('hola.txt', 'no es una hoja');
        $z->close();

        return (string) file_get_contents($p);
    })());
    expect(fn () => UploadGuard::spreadsheet($zipNoSheet))->toThrow(ApiProblem::class);
    $real = UploadedFile::fake()->createWithContent('trabajadores.xlsx', Xlsx::build('Hoja', [['a', 'b'], [1, 2]], 1));
    UploadGuard::spreadsheet($real);
    UploadGuard::spreadsheet(UploadedFile::fake()->createWithContent('lista.csv', "dni;nombre\n12345678;Ana\n"));

    // En la carga masiva de trabajadores: el «Excel» que en realidad es HTML se rechaza con 422.
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->post('/api/v1/trb/imports/preview', ['file' => UploadedFile::fake()->createWithContent('plantilla.xlsx', '<html>hola</html>')], $h + ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'no es realmente'));
});
