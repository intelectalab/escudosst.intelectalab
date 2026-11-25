<?php

use App\Modules\REC\Rules\EventRules;
use Illuminate\Support\Facades\DB;

function addBusinessDays(string $from, int $days): string
{
    $rules = (new ReflectionClass(EventRules::class))->newInstanceWithoutConstructor();

    return (new ReflectionMethod(EventRules::class, 'businessDays'))->invoke($rules, new DateTimeImmutable($from), $days)->format('Y-m-d');
}

/** Feriados nacionales no laborables simulados (tabla holidays). */
function holidays(array $dates): void
{
    DB::shouldReceive('selectOne')->andReturnUsing(fn (string $sql, array $b) => in_array($b[0], $dates, true) ? (object) ['ok' => 1] : null);
}

it('cuenta días hábiles saltando sábados y domingos', function () {
    holidays([]);

    expect(addBusinessDays('2026-11-20', 1))->toBe('2026-11-23')     // viernes → lunes
        ->and(addBusinessDays('2026-11-21', 1))->toBe('2026-11-23')  // sábado → lunes
        ->and(addBusinessDays('2026-11-23', 5))->toBe('2026-11-30')
        ->and(addBusinessDays('2026-11-23', 0))->toBe('2026-11-23');
});

it('salta los feriados nacionales no laborables', function () {
    holidays(['2026-12-08', '2026-12-25']);

    expect(addBusinessDays('2026-12-07', 1))->toBe('2026-12-09')
        ->and(addBusinessDays('2026-12-24', 1))->toBe('2026-12-28');
});
