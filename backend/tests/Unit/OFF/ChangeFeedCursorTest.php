<?php

use App\Core\Http\ApiProblem;
use App\Modules\OFF\Services\ChangeFeed;

function feedCall(string $method, mixed ...$args): mixed
{
    $feed = (new ReflectionClass(ChangeFeed::class))->newInstanceWithoutConstructor();

    return (new ReflectionMethod(ChangeFeed::class, $method))->invoke($feed, ...$args);
}

const FEED_ID = '01900000-0000-7000-8000-0000000000aa';

it('la marca es opaca y vuelve a leerse igual (base64 url sin relleno)', function () {
    $mark = feedCall('encode', '2026-11-20T10:00:00.123456-05:00', 'inspections', FEED_ID);

    expect($mark)->not->toMatch('/[+\/=]/')
        ->and(feedCall('cursor', $mark))->toBe(['t' => '2026-11-20T10:00:00.123456-05:00', 'e' => 'inspections', 'i' => FEED_ID]);
});

it('sin marca entrega todo lo vigente y acepta un instante ISO 8601', function () {
    expect(feedCall('cursor', null))->toBeNull()
        ->and(feedCall('cursor', '  '))->toBeNull()
        ->and(feedCall('cursor', '2026-11-20T10:00:00Z'))->toBe(['t' => '2026-11-20T10:00:00Z', 'e' => '', 'i' => '']);
});

it('descarta un id que no es UUID dentro de la marca', function () {
    $mark = rtrim(strtr(base64_encode(json_encode(['t' => '2026-11-20T10:00:00Z', 'e' => 'inspections', 'i' => "1' or 1=1"])), '+/', '-_'), '=');

    expect(feedCall('cursor', $mark)['i'])->toBe('');
});

it('rechaza marcas inválidas con 422', function (string $since) {
    feedCall('cursor', $since);
})->with(['no-es-marca', 'ZXN0byBubyBlcyBqc29u', '20-11-2026'])->throws(ApiProblem::class, 'Revisa los datos');

it('desempata por entidad e id cuando varios cambios tienen el mismo instante', function () {
    $c = ['t' => '2026-11-20T10:00:00Z', 'e' => 'ppe_deliveries', 'i' => FEED_ID];

    expect(feedCall('after', 'inspections', null))->toBe(['not x.dead', []])
        ->and(feedCall('after', 'inspections', $c))->toBe(['x.ts::timestamptz > ?::timestamptz', [$c['t']]])
        ->and(feedCall('after', 'sst_events', $c))->toBe(['x.ts::timestamptz >= ?::timestamptz', [$c['t']]])
        ->and(feedCall('after', 'ppe_deliveries', $c)[1])->toBe([$c['t'], $c['t'], FEED_ID])
        ->and(feedCall('after', 'sst_events', ['e' => ''] + $c)[0])->toBe('x.ts::timestamptz > ?::timestamptz');
});

it('ordena instantes con microsegundos entre zonas horarias', function () {
    expect(feedCall('micro', '2026-11-20T10:00:00.000001-05:00'))->toBe(feedCall('micro', '2026-11-20T15:00:00.000001Z'))
        ->and(feedCall('micro', '2026-11-20T10:00:00.000002Z'))->toBeGreaterThan(feedCall('micro', '2026-11-20T10:00:00.000001Z'));
});
