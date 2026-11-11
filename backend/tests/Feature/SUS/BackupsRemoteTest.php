<?php

// Copia externa de respaldos (docs/15 §9): sube el archivo cifrado a un almacenamiento S3/B2 firmado con V4, borra las
// copias externas vencidas, sin configurar se omite con aviso, y un fallo de subida no invalida la copia local.

use App\Modules\SUS\Services\BackupService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Storage::fake('backups');
    DB::connection('platform')->beginTransaction();
    config(['escudo.backups.remote' => [
        'endpoint' => 'https://s3.us-west-004.backblazeb2.com',
        'bucket' => 'escudosst-test-backups',
        'key' => 'KEYID123',
        'secret' => 'secreto-s3-xyz',
        'region' => 'us-west-004',
        'retention_days' => 30,
    ]]);
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

it('sube la copia cifrada al almacenamiento externo con firma V4', function () {
    Http::fake(['s3.us-west-004.backblazeb2.com/*' => Http::response('', 200)]);
    $svc = app(BackupService::class);
    $id = $svc->run('main', 'manual');
    $run = DB::connection('platform')->selectOne('select * from backup_runs where id = ?', [$id]);
    expect($run->status)->toBe('ok')
        ->and($run->remote_status)->toBe('ok')
        ->and($run->remote_key)->toBe($run->path)
        ->and($run->remote_uploaded_at)->not->toBeNull();

    Http::assertSent(function (Request $r) use ($run) {
        $auth = $r->header('Authorization')[0] ?? '';

        return $r->method() === 'PUT'
            && $r->url() === 'https://s3.us-west-004.backblazeb2.com/escudosst-test-backups/'.$run->path
            && str_starts_with($auth, 'AWS4-HMAC-SHA256 Credential=KEYID123/'.gmdate('Ymd').'/us-west-004/s3/aws4_request')
            && str_contains($auth, 'SignedHeaders=host;x-amz-content-sha256;x-amz-date')
            && ($r->header('x-amz-content-sha256')[0] ?? '') === $run->sha256   // lo subido es el archivo cifrado
            && ! str_contains($auth, 'secreto-s3-xyz');
    });

    $remote = $svc->status()['remote'];
    expect($remote['configured'])->toBeTrue()
        ->and($remote['destination'])->toBe('s3.us-west-004.backblazeb2.com / escudosst-test-backups')
        ->and($remote['last_status'])->toBe('ok')
        ->and($remote['last_upload_at'])->not->toBeNull();
});

it('si la subida falla la copia local sigue buena y el error queda sin la clave', function () {
    Http::fake(['*' => Http::response('<Error><Code>AccessDenied</Code></Error>', 403)]);
    $id = app(BackupService::class)->run('main', 'manual');
    $run = DB::connection('platform')->selectOne('select status, remote_status, remote_error from backup_runs where id = ?', [$id]);
    expect($run->status)->toBe('ok')
        ->and($run->remote_status)->toBe('failed')
        ->and($run->remote_error)->toContain('AccessDenied')
        ->and($run->remote_error)->not->toContain('secreto-s3-xyz');
    expect(app(BackupService::class)->status()['remote']['last_status'])->toBe('failed');
});

it('sin configurar se omite en silencio y el panel lo indica', function () {
    config(['escudo.backups.remote.endpoint' => '', 'escudo.backups.remote.bucket' => '']);
    Http::fake();
    $id = app(BackupService::class)->run('main', 'manual');
    $run = DB::connection('platform')->selectOne('select status, remote_status from backup_runs where id = ?', [$id]);
    expect($run->status)->toBe('ok')->and($run->remote_status)->toBe('skipped');
    Http::assertNothingSent();

    $data = $this->getJson('/api/v1/internal/backups', authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk()->json('data.remote');
    expect($data['configured'])->toBeFalse()->and($data['message'])->toContain('sin configurar');
});

it('borra del almacenamiento externo solo las copias más viejas que la retención', function () {
    $old = gmdate('Y-m-d\TH:i:s.000\Z', time() - 40 * 86400);
    $new = gmdate('Y-m-d\TH:i:s.000\Z', time() - 2 * 86400);
    $list = fn (string $t) => '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        ."<IsTruncated>false</IsTruncated><Contents><Key>$t/2026/09/escudo-$t-vieja.dump.enc</Key><LastModified>$old</LastModified><Size>10</Size></Contents>"
        ."<Contents><Key>$t/2026/11/escudo-$t-nueva.dump.enc</Key><LastModified>$new</LastModified><Size>10</Size></Contents></ListBucketResult>";
    Http::fake(function (Request $r) use ($list) {
        if ($r->method() === 'GET') {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return Http::response($list(rtrim($q['prefix'], '/')), 200);
        }

        return Http::response('', 204);
    });
    DB::connection('platform')->insert(
        "insert into backup_runs (target, trigger, status, remote_status, remote_key) values ('main', 'schedule', 'ok', 'ok', 'main/2026/09/escudo-main-vieja.dump.enc')"
    );

    expect(app(BackupService::class)->pruneRemote())->toBe(2);

    $deletes = Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->map(fn ($p) => $p[0]->url())->values()->all();
    expect($deletes)->toBe([
        'https://s3.us-west-004.backblazeb2.com/escudosst-test-backups/main/2026/09/escudo-main-vieja.dump.enc',
        'https://s3.us-west-004.backblazeb2.com/escudosst-test-backups/medical/2026/09/escudo-medical-vieja.dump.enc',
    ]);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'list-type=2&prefix=main%2F'));
    $marked = DB::connection('platform')->selectOne("select remote_deleted_at from backup_runs where remote_key = 'main/2026/09/escudo-main-vieja.dump.enc'");
    expect($marked->remote_deleted_at)->not->toBeNull();
});
