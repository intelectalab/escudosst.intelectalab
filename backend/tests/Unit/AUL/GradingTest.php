<?php

use App\Modules\AUL\Grading;

$vigesimal = (object) ['scale_type' => 'numeric', 'min_value' => 0, 'max_value' => 20, 'decimals' => 0];
$decimal = (object) ['scale_type' => 'numeric', 'min_value' => 0, 'max_value' => 10, 'decimals' => 1];
$letters = (object) ['scale_type' => 'letter', 'passing_label' => 'B', 'bands' => json_encode([
    ['label' => 'C', 'from' => 0], ['label' => 'A', 'from' => 90], ['label' => 'B', 'from' => 70],
])];

it('sin escala muestra el porcentaje', function () {
    expect(Grading::label(85, null))->toBe('85 %')
        ->and(Grading::label(72.5, null))->toBe('72.5 %')
        ->and(Grading::label(0, null))->toBe('0 %');
});

it('convierte el porcentaje a la escala numérica en los bordes (RN-AUL-11)', function () use ($vigesimal, $decimal) {
    expect(Grading::label(0, $vigesimal))->toBe('0')
        ->and(Grading::label(100, $vigesimal))->toBe('20')
        ->and(Grading::label(70, $vigesimal))->toBe('14')
        ->and(Grading::label(72, $vigesimal))->toBe('14')
        ->and(Grading::label(73, $vigesimal))->toBe('15')
        ->and(Grading::label(85, $decimal))->toBe('8.5')
        ->and(Grading::label(80, $decimal))->toBe('8')
        ->and(Grading::label(100, $decimal))->toBe('10')
        ->and(Grading::label(50, $vigesimal))->toBe('10');
});

it('asigna la letra por el inicio de cada rango', function () use ($letters) {
    expect(Grading::label(100, $letters))->toBe('A')
        ->and(Grading::label(90, $letters))->toBe('A')
        ->and(Grading::label(89.99, $letters))->toBe('B')
        ->and(Grading::label(70, $letters))->toBe('B')
        ->and(Grading::label(69.99, $letters))->toBe('C')
        ->and(Grading::label(0, $letters))->toBe('C')
        ->and(array_column(Grading::bands($letters), 'label'))->toBe(['A', 'B', 'C']);
});

it('mínimo para aprobar: evaluación, luego letra de aprobación, luego curso, por defecto 70', function () use ($letters, $vigesimal) {
    expect(Grading::passingPct(60, 80, $letters))->toBe(60.0)
        ->and(Grading::passingPct(null, 80, $letters))->toBe(70.0)
        ->and(Grading::passingPct(null, 80, $vigesimal))->toBe(80.0)
        ->and(Grading::passingPct(null, null, null))->toBe(70.0);
});
