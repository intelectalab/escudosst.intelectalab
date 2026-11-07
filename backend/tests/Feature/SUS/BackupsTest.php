<?php

// Copias de seguridad (docs/15 §9): volcado real cifrado, descifrado con su llave, falla con otra llave, registro del
// error sin la contraseña, aviso en la campana y solo el Dueño puede pedir una copia.

use App\Modules\SUS\Services\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Storage::fake('backups');
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

it('hace una copia cifrada que solo se abre con su llave', function () {
    $svc = app(BackupService::class);
    $id = $svc->run('main', 'manual');
    $run = DB::connection('platform')->selectOne('select * from backup_runs where id = ?', [$id]);
    expect($run->status)->toBe('ok')->and($run->size_bytes)->toBeGreaterThan(1000);

    $enc = tempnam(sys_get_temp_dir(), 't');
    file_put_contents($enc, Storage::disk('backups')->get($run->path));
    expect(hash_file('sha256', $enc))->toBe($run->sha256)
        ->and(file_get_contents($enc, false, null, 0, 9))->toBe('ESCUDOBK1');

    $out = tempnam(sys_get_temp_dir(), 'o');
    $svc->decrypt($enc, $out, 'main');
    expect(file_get_contents($out, false, null, 0, 5))->toBe('PGDMP');   // formato de pg_dump
    expect(fn () => $svc->decrypt($enc, $out, 'medical'))->toThrow(RuntimeException::class);   // otra llave
    @unlink($enc);
    @unlink($out);

    expect(collect($svc->status()['targets'])->firstWhere('target', 'main')['overdue'])->toBeFalse();
});

it('registra el fallo sin mostrar la contraseña y avisa en la campana', function () {
    config(['escudo.backups.db_password' => 'clave-incorrecta-123']);
    $id = app(BackupService::class)->run('main', 'manual');
    $run = DB::connection('platform')->selectOne('select status, error from backup_runs where id = ?', [$id]);
    expect($run->status)->toBe('failed')->and($run->error)->not->toContain('clave-incorrecta-123');

    $keys = collect($this->getJson('/api/v1/internal/notifications', authHeaders(tokenFor(ADMIN_EMAIL)))->json('data'))->pluck('key');
    expect($keys)->toContain('backup_main');
});

it('solo el Dueño pide una copia; el panel ve el estado', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->getJson('/api/v1/internal/backups', $h)->assertOk()->assertJsonStructure(['data' => ['targets', 'runs', 'config']]);
    $this->getJson('/api/v1/internal/backups', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertForbidden();
});
