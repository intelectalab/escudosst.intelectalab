<?php

use App\Core\Http\ApiProblem;
use App\Modules\HOM\VendorScoring;
use Illuminate\Support\Facades\DB;

$criteria = [['name' => 'Documentos', 'weight' => 40.0], ['name' => 'Seguridad', 'weight' => 60.0]];

it('total ponderado de 0 a 100: Σ (puntaje / 5) × peso (RN-HOM-01)', function () use ($criteria) {
    expect(VendorScoring::total(['Documentos' => ['score' => 5], 'Seguridad' => ['score' => 5]], $criteria))->toBe(100.0)
        ->and(VendorScoring::total(['Documentos' => 0, 'Seguridad' => 0], $criteria))->toBe(0.0)
        ->and(VendorScoring::total(['Documentos' => ['score' => 2.5], 'Seguridad' => ['score' => 4]], $criteria))->toBe(68.0)
        ->and(VendorScoring::total(['Documentos' => 5], $criteria))->toBe(40.0);
});

it('normaliza puntajes, conserva comentario y evidencia anteriores y anota quién calificó', function () use ($criteria) {
    $out = VendorScoring::normalize(
        ['Documentos' => 4, 'Seguridad' => ['score' => '3', 'comment' => '  Falta el plan  ']],
        $criteria,
        ['Documentos' => ['score' => 2, 'comment' => 'Antes', 'evidence_file_id' => 'f1']],
        'u1',
    );

    expect($out['Documentos'])->toBe(['score' => 4.0, 'comment' => 'Antes', 'evidence_file_id' => 'f1', 'by' => 'u1'])
        ->and($out['Seguridad'])->toBe(['score' => 3.0, 'comment' => 'Falta el plan', 'by' => 'u1']);
});

it('exige calificar todos los criterios dentro de 0 a 5', function (array $input, string $detail) use ($criteria) {
    try {
        VendorScoring::normalize($input, $criteria, [], 'u1');
        $this->fail('Debió rechazar los puntajes');
    } catch (ApiProblem $e) {
        expect($e->status)->toBe(422)->and($e->detail)->toContain($detail);
    }
})->with([
    'falta uno' => [['Documentos' => 3], 'Seguridad'],
    'mayor a 5' => [['Documentos' => 6, 'Seguridad' => 3], 'entre 0 y 5'],
    'negativo' => [['Documentos' => -1, 'Seguridad' => 3], 'entre 0 y 5'],
    'no numérico' => [['Documentos' => 'alto', 'Seguridad' => 3], 'entre 0 y 5'],
]);

it('una plantilla sin criterios no se puede calificar', function () {
    VendorScoring::normalize([], [], [], 'u1');
})->throws(ApiProblem::class, 'Plantilla sin criterios');

it('califica A, B o C según el mínimo y el umbral de A (sin parámetros: 85)', function () {
    DB::shouldReceive('selectOne')->andReturn(null);

    expect(VendorScoring::rating(90, 70))->toBe(VendorScoring::A)
        ->and(VendorScoring::rating(85, 70))->toBe(VendorScoring::A)
        ->and(VendorScoring::rating(84.99, 70))->toBe(VendorScoring::B)
        ->and(VendorScoring::rating(70, 70))->toBe(VendorScoring::B)
        ->and(VendorScoring::rating(69.99, 70))->toBe(VendorScoring::C);
});

it('usa el umbral de A del parámetro vigente', function () {
    DB::shouldReceive('selectOne')->andReturn((object) ['value' => '95']);

    expect(VendorScoring::rating(90, 70))->toBe(VendorScoring::B)
        ->and(VendorScoring::rating(95, 70))->toBe(VendorScoring::A);
});
