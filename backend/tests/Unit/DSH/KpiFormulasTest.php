<?php

use App\Modules\DSH\Services\KpiService;
use Illuminate\Support\Facades\DB;

function kpiService(array $props = []): KpiService
{
    $svc = (new ReflectionClass(KpiService::class))->newInstanceWithoutConstructor();
    foreach ($props + ['from' => '2026-01-01', 'to' => '2026-12-31', 'today' => '2026-11-24', 'site' => null] as $k => $v) {
        (new ReflectionProperty(KpiService::class, $k))->setValue($svc, $v);
    }

    return $svc;
}

function kpiCall(KpiService $svc, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod(KpiService::class, $method))->invoke($svc, ...$args);
}

function kpiDef(array $d): object
{
    return (object) ($d + ['code' => 'x', 'unit' => '%', 'direction' => 'higher_better', 'green_threshold' => null, 'amber_threshold' => null]);
}

it('semáforo con umbrales propios en ambos sentidos', function () {
    $up = kpiDef(['green_threshold' => 95, 'amber_threshold' => 80]);
    $down = kpiDef(['direction' => 'lower_better', 'unit' => '', 'green_threshold' => 2, 'amber_threshold' => 5]);

    expect(kpiCall(kpiService(), 'color', $up, ['value' => 95]))->toBe('green')
        ->and(kpiCall(kpiService(), 'color', $up, ['value' => 80]))->toBe('amber')
        ->and(kpiCall(kpiService(), 'color', $up, ['value' => 79.9]))->toBe('red')
        ->and(kpiCall(kpiService(), 'color', $down, ['value' => 2]))->toBe('green')
        ->and(kpiCall(kpiService(), 'color', $down, ['value' => 5]))->toBe('amber')
        ->and(kpiCall(kpiService(), 'color', $down, ['value' => 6]))->toBe('red');
});

it('semáforo por defecto: % a favor verde ≥ 90 y ámbar ≥ 70; sin valor ni meta no hay color', function () {
    expect(kpiCall(kpiService(), 'color', kpiDef([]), ['value' => 90]))->toBe('green')
        ->and(kpiCall(kpiService(), 'color', kpiDef([]), ['value' => 70]))->toBe('amber')
        ->and(kpiCall(kpiService(), 'color', kpiDef([]), ['value' => 69]))->toBe('red')
        ->and(kpiCall(kpiService(), 'color', kpiDef([]), ['value' => null]))->toBeNull()
        ->and(kpiCall(kpiService(), 'color', kpiDef(['direction' => 'target', 'green_threshold' => 1]), ['value' => 3]))->toBeNull()
        ->and(kpiCall(kpiService(), 'color', kpiDef(['unit' => 'n', 'direction' => 'lower_better']), ['value' => 3]))->toBeNull()
        ->and(kpiCall(kpiService(), 'color', kpiDef(['code' => 'corrective_actions_overdue', 'unit' => 'n', 'direction' => 'lower_better']), ['value' => 3]))->toBe('amber');
});

it('índices de frecuencia, gravedad y accidentabilidad con los factores por defecto', function () {
    DB::shouldReceive('selectOne')->andReturnUsing(function (string $sql) {
        if (str_contains($sql, 'from sst_events')) {
            return (object) ['accidents' => 2, 'days' => 30];
        }
        if (str_contains($sql, 'from man_hours')) {
            return (object) ['h' => 400000];
        }

        return null;   // legal_parameters: sin parámetro, valor de respaldo
    });

    $fi = kpiCall(kpiService(), 'index', 'frequency_index');
    $si = kpiCall(kpiService(), 'index', 'severity_index');
    $ai = kpiCall(kpiService(), 'index', 'accident_rate');

    expect($fi['value'])->toBe(5.0)->and($fi['numerator'])->toBe(2)->and($fi['denominator'])->toEqual(400000)
        ->and($si['value'])->toBe(75.0)->and($si['numerator'])->toBe(30)
        ->and($ai['value'])->toBe(0.38)->and($ai['basis'])->toBe('IF × IG / 1.000');
});

it('sin horas-hombre el índice no aplica', function () {
    DB::shouldReceive('selectOne')->andReturnUsing(fn (string $sql) => str_contains($sql, 'from sst_events') ? (object) ['accidents' => 1, 'days' => 3] : (object) ['h' => 0]);

    $r = kpiCall(kpiService(), 'index', 'frequency_index');
    expect($r['value'])->toBeNull()->and($r['label'])->toBe('No aplica')->and($r['numerator'])->toBe(1);
});

it('acota por sede y por el alcance de la membresía, ignorando valores que no son UUID', function () {
    $site = '01900000-0000-7000-8000-000000000201';
    $scoped = kpiService(['site' => $site])->forSites([$site, "x'; drop table users; --"]);

    expect(kpiCall(kpiService(), 'siteSql', 'site_id'))->toBe('')
        ->and(kpiCall($scoped, 'siteSql', 'site_id'))->toBe(" and site_id = '{$site}'::uuid and site_id = any('{{$site}}'::uuid[])")
        ->and(kpiCall(kpiService()->forSites([$site]), 'siteSql', 'e.site_id', true))->toBe(" and (e.site_id = any('{{$site}}'::uuid[]) or e.site_id is null)")
        ->and(kpiCall(kpiService()->forAreas(['no-uuid']), 'areaSql', 'area_id'))->toBe('');
});

it('formatea números al estilo peruano sin ceros sobrantes', function () {
    expect(kpiCall(kpiService(), 'fmt', 1000000.0))->toBe('1.000.000')
        ->and(kpiCall(kpiService(), 'fmt', 2.5))->toBe('2,5')
        ->and(kpiCall(kpiService(), 'fmt', 0.38))->toBe('0,38');
});
