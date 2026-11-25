<?php

// M-WEB §10 y §11, RN-WEB-10 y RN-WEB-65: aviso por lecturas fallidas del blog, enlaces rotos (404 registrados por la aplicación)
// y redirecciones con más uso, consentimientos de cookies por categoría, reporte de la biblioteca de medios, Core Web Vitals
// medidos en el navegador frente a los umbrales parametrizados y conservación de métricas y consentimientos (docs/10 §9).

use App\Core\Support\RetentionPurge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function repApi(string $method, string $uri, array $data = [])
{
    return test()->json($method, '/api/v1/internal/site/'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

it('avisa al Dueño cuando la lectura del blog falla seguido y deja de avisar al leerse bien', function () {
    $p = DB::connection('platform');
    $p->update("update site_settings set blog_url = 'https://blog.ejemplo.test', show_latest_blog_posts = true, latest_blog_posts_count = 3, blog_feed_failures = 0");
    $threshold = (int) $p->selectOne("select value from legal_parameters where key = 'web.blog_feed_failure_threshold' and company_id is null and deleted_at is null")->value;
    $up = false;
    Http::fake(['blog.ejemplo.test/*' => function () use (&$up) {
        return $up ? Http::response([['title' => ['rendered' => 'Nota'], 'link' => 'https://blog.ejemplo.test/nota', 'date' => '2026-11-01', 'excerpt' => ['rendered' => '<p>Hola</p>']]], 200)
            : Http::response('caído', 503);
    }]);
    for ($i = 0; $i < $threshold; $i++) {
        cache()->flush();
        test()->getJson('/api/v1/public/blog-posts')->assertOk()->assertJsonPath('data', []);
    }
    expect((int) $p->selectOne('select blog_feed_failures as n from site_settings limit 1')->n)->toBe($threshold);
    $bell = collect(test()->getJson('/api/v1/internal/notifications', authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk()->json('data'))->keyBy('key');
    expect($bell->has('blog_feed_failing'))->toBeTrue();

    $up = true;
    cache()->flush();
    test()->getJson('/api/v1/public/blog-posts')->assertOk()->assertJsonCount(1, 'data');
    expect((int) $p->selectOne('select blog_feed_failures as n from site_settings limit 1')->n)->toBe(0);
});

it('registra los 404 del sitio agregados por ruta, sin datos personales, y los resuelve al crear la redirección', function () {
    $path = '/pagina-vieja-'.Str::lower(Str::random(6));
    test()->postJson('/api/v1/public/not-found', ['path' => $path.'?utm_source=x&correo=ana@x.pe', 'referrer' => 'https://www.google.com/search?q=hola'])->assertNoContent();
    test()->postJson('/api/v1/public/not-found', ['path' => strtoupper($path).'/', 'referrer' => 'http://localhost/planes'])->assertNoContent();
    test()->postJson('/api/v1/public/not-found', ['path' => '/plataforma/algo'])->assertNoContent();   // panel: no se registra

    $row = DB::connection('platform')->selectOne('select * from site_not_found_paths where path = ?', [$path]);
    expect($row)->not->toBeNull()->and((int) $row->hit_count)->toBe(2)->and((bool) $row->internal_referrer)->toBeTrue()
        ->and(DB::connection('platform')->selectOne("select count(*) as n from site_not_found_paths where path like '/plataforma%'")->n)->toBe(0);
    expect(array_keys((array) $row))->not->toContain('ip_hash')->not->toContain('user_agent');

    $report = repApi('GET', 'reports/links')->assertOk()->json('data');
    expect(collect($report['broken'])->firstWhere('path', $path)['hit_count'])->toBe(2);

    repApi('POST', 'redirects', ['source_path' => $path, 'target_url' => '/planes', 'status_code' => 301, 'is_active' => true])->assertCreated();
    expect(collect(repApi('GET', 'reports/links')->json('data.broken'))->firstWhere('path', $path))->toBeNull();
});

it('resume los consentimientos de cookies por categoría con la última elección de cada visitante', function () {
    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    // La API las guarda con public_record_cookie_consent(); aquí se insertan con la conexión del panel para leerlas en la prueba.
    $ins = fn (string $v, string $action, array $c, int $minutesAgo) => DB::connection('platform')->insert(
        "insert into cookie_consents (visitor_id, categories, action, policy_version_label, consented_at) values (?, ?::jsonb, ?, 'v1', now() - make_interval(mins => ?))",
        [$v, json_encode(['necessary' => true] + $c), $action, $minutesAgo]);
    $ins($a, 'accept_all', ['preferences' => true, 'analytics' => true, 'marketing' => true], 5);
    $ins($a, 'custom', ['preferences' => true, 'analytics' => true, 'marketing' => false], 1);
    $ins($b, 'reject_all', ['preferences' => false, 'analytics' => false, 'marketing' => false], 1);
    test()->postJson('/api/v1/public/cookie-consent', ['visitor_id' => $b, 'action' => 'reject_all', 'categories' => ['preferences' => false, 'analytics' => false, 'marketing' => false]])->assertCreated();

    $before = repApi('GET', 'reports/cookie-consents?days=7')->assertOk()->json('data');
    $base = DB::connection('platform')->selectOne("select count(distinct visitor_id) as n from cookie_consents where consented_at >= now() - interval '7 days'")->n;
    expect($before['totals']['visitors'])->toBe((int) $base)
        ->and($before['choices'])->toBeGreaterThanOrEqual(3)
        ->and($before['totals']['custom'])->toBeGreaterThanOrEqual(1)
        ->and($before['totals']['marketing'])->toBeLessThan($before['totals']['visitors'])
        ->and(json_encode($before))->not->toContain($a)->not->toContain($b);
});

it('reporta la biblioteca de medios: archivos sin uso, imágenes sin texto alternativo y tamaño total', function () {
    $r = repApi('GET', 'reports/media')->assertOk()->json('data');
    expect($r)->toHaveKeys(['files', 'size_bytes', 'by_kind', 'unused', 'unused_size_bytes', 'missing_alt'])
        ->and($r['size_bytes'])->toBe(array_sum(array_column($r['by_kind'], 'size_bytes')));
});

it('guarda Core Web Vitals sin datos de la persona y compara el percentil 75 con los umbrales parametrizados', function () {
    $mobile = ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148'];
    foreach ([1800, 2000, 2200, 4000] as $lcp) {
        test()->postJson('/api/v1/public/web-vitals', ['path' => '/planes?x=1', 'metrics' => [['name' => 'LCP', 'value' => $lcp], ['name' => 'CLS', 'value' => 0.02], ['name' => 'INP', 'value' => 120]]], $mobile)->assertNoContent();
    }
    test()->postJson('/api/v1/public/web-vitals', ['path' => '/demo', 'metrics' => [['name' => 'LCP', 'value' => 6000]]], $mobile)->assertNoContent();
    test()->postJson('/api/v1/public/web-vitals', ['path' => '/demo', 'metrics' => [['name' => 'FID', 'value' => 1]]], $mobile)->assertStatus(422);
    test()->postJson('/api/v1/public/web-vitals', ['path' => '/demo', 'metrics' => [['name' => 'LCP', 'value' => 1]]], ['User-Agent' => 'Googlebot/2.1'])->assertNoContent();

    $r = repApi('GET', 'reports/web-vitals?days=7&device=mobile')->assertOk()->json('data');
    $target = (float) DB::connection('platform')->selectOne("select value from legal_parameters where key = 'web.cwv_lcp_target' and company_id is null and deleted_at is null")->value;
    expect($r['targets']['LCP'])->toEqual($target);
    $planes = collect($r['pages'])->firstWhere('path', '/planes');
    $demo = collect($r['pages'])->firstWhere('path', '/demo');
    expect($planes['metrics']['LCP']['samples'])->toBe(4)
        ->and($planes['metrics']['LCP']['p75'])->toEqual(2650.0)
        ->and($demo['passes'])->toBeFalse()
        ->and($demo['metrics']['LCP']['samples'])->toBe(1);
    expect(array_keys((array) DB::connection('platform')->selectOne('select * from site_web_vitals limit 1')))->toBe(['id', 'occurred_at', 'path', 'device', 'metric', 'value']);
});

it('la tarea de conservación crea las particiones del mes por adelantado y deja constancia de lo borrado sin el contenido', function () {
    DB::connection('platform')->rollBack(0);   // la tarea usa sus propias transacciones (rol dueño)
    $out = app(RetentionPurge::class)->run();
    expect($out)->toHaveKeys(['site_events', 'chatbot_messages', 'chatbot_conversations', 'site_web_vitals', 'cookie_consents', 'idempotency_keys'])
        ->and($out['site_events']['partitions'])->toBeGreaterThanOrEqual(3);
    $month = 'site_events_'.now('America/Lima')->format('Ym');
    expect(DB::connection('pgsql_owner')->selectOne('select to_regclass(?) as t', [$month])->t)->toBe($month);
    $log = DB::connection('platform')->selectOne("select changes from audit_logs where action = 'retention_purged' order by occurred_at desc limit 1");
    expect($log)->not->toBeNull();
    DB::connection('platform')->beginTransaction();
});

it('el consentimiento de cookies solo se borra en la tarea de conservación: fuera de ella sigue inalterable', function () {
    expect(fn () => DB::connection('platform')->delete('delete from cookie_consents where false'))->toThrow(\Illuminate\Database\QueryException::class);
});
