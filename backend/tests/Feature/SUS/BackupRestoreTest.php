<?php

// Prueba de restauración (docs/15 §9 «Restauración probada»): la última copia buena se restaura en una base temporal, se
// revisa (tablas, RLS, migraciones, filas de tablas clave), se borra la base y el resultado queda registrado y en el panel.

use App\Modules\SUS\Services\BackupRestoreTestService;
use App\Modules\SUS\Services\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Storage::fake('backups');
    // Usuario que puede crear bases en el servidor de desarrollo (compose.dev.yml).
    config(['escudo.backups.restore.password' => env('BACKUP_RESTORE_DB_PASSWORD') ?: 'postgres_dev']);
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

it('restaura la última copia en una base temporal, la revisa, la borra y registra el resultado', function () {
    $backup = app(BackupService::class)->run('main', 'manual');
    $id = app(BackupRestoreTestService::class)->run('main', 'manual');

    $t = DB::connection('platform')->selectOne('select * from backup_restore_tests where id = ?', [$id]);
    expect($t->status)->toBe('ok', (string) $t->error)
        ->and($t->backup_run_id)->toBe($backup)
        ->and($t->tables_count)->toBeGreaterThan(100)
        ->and($t->rls_tables_count)->toBeGreaterThan(50)
        ->and($t->migrations_count)->toBeGreaterThan(100)
        ->and($t->duration_ms)->toBeGreaterThan(0);
    $counts = json_decode($t->row_counts, true);
    expect($counts)->toHaveKeys(['companies', 'users', 'workers'])
        ->and($counts['companies'])->toBeGreaterThan(0);
    expect(collect(json_decode($t->checks, true))->every(fn ($c) => $c['ok']))->toBeTrue();

    // La base temporal ya no existe.
    $left = DB::connection('platform')->selectOne('select count(*) as n from pg_database where datname = ?', [$t->temp_database]);
    expect($t->temp_database)->toStartWith('escudo_restore_test_')->and((int) $left->n)->toBe(0);

    $status = app(BackupRestoreTestService::class)->status();
    $main = collect($status['targets'])->firstWhere('target', 'main');
    expect($main['last_status'])->toBe('ok')->and($main['overdue'])->toBeFalse()
        ->and($status['tests'][0]->id)->toBe($id);
});

it('falla si la copia fue alterada (la huella no coincide) y no deja base temporal', function () {
    $backup = app(BackupService::class)->run('main', 'manual');
    DB::connection('platform')->update("update backup_runs set sha256 = repeat('0', 64) where id = ?", [$backup]);

    $id = app(BackupRestoreTestService::class)->run('main', 'manual');
    $t = DB::connection('platform')->selectOne('select status, error, temp_database from backup_restore_tests where id = ?', [$id]);
    expect($t->status)->toBe('failed')->and($t->error)->toContain('huella')->and($t->temp_database)->toBeNull();
});

it('sin copia buena falla, el comando sale con error y la campana avisa', function () {
    DB::connection('platform')->update("update backup_runs set deleted_at = now() where target = 'medical' and deleted_at is null");

    $this->artisan('escudo:backup:restore-test', ['--target' => 'medical'])->assertExitCode(1);
    $t = DB::connection('platform')->selectOne("select status, error from backup_restore_tests where target = 'medical' order by started_at desc limit 1");
    expect($t->status)->toBe('failed')->and($t->error)->toContain('No hay una copia buena');

    $keys = collect($this->getJson('/api/v1/internal/notifications', authHeaders(tokenFor(ADMIN_EMAIL)))->json('data'))->pluck('key');
    expect($keys)->toContain('restore_test_medical');
});

it('el panel de copias muestra las pruebas; solo la plataforma puede pedir una', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->getJson('/api/v1/internal/backups', $h)->assertOk()
        ->assertJsonStructure(['data' => ['targets', 'runs', 'restore' => ['targets', 'tests', 'config' => ['weekly', 'max_age_days', 'ready']]]]);
    $this->postJson('/api/v1/internal/backups/restore-test', ['target' => 'otra'], $h)->assertStatus(422);
    $this->postJson('/api/v1/internal/backups/restore-test', [], authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertForbidden();
});

it('el comando de copia sale con error si la copia falla (deploy/escudo.sh update se detiene)', function () {
    config(['escudo.backups.db_password' => 'clave-incorrecta-123']);
    $this->artisan('escudo:backup', ['--target' => 'main'])->assertExitCode(1);
});
