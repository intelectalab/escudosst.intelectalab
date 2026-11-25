<?php

use App\Core\Http\ApiProblem;
use App\Modules\IAA\MinutesController;
use App\Modules\IAA\Redactor;
use Illuminate\Support\Facades\DB;

function workersInDb(array $rows): void
{
    DB::shouldReceive('select')->andReturn(array_map(fn ($r) => (object) $r, $rows));
}

it('reemplaza correos, DNI, RUC de persona natural y celulares por marcadores (RN-IAA-03)', function () {
    workersInDb([]);
    [$text, $found] = Redactor::redact('Escribir a ana.perez@obra.pe, DNI 45678912, RUC 10456789123, celular 987 654 321.');

    expect($text)->toBe('Escribir a [CORREO], DNI [DNI], RUC [RUC], celular [TELÉFONO].')
        ->and($found)->toEqualCanonicalizing(['CORREO', 'DNI', 'RUC', 'TELÉFONO']);
});

it('reconoce el celular con prefijo +51 y no toca números de otra longitud', function () {
    workersInDb([]);
    [$text, $found] = Redactor::redact('Llamar al +51 912-345-678; la orden 1234567 y el lote 123456789012 quedan igual.');

    expect($text)->toContain('[TELÉFONO]')
        ->and($text)->toContain('1234567')
        ->and($text)->toContain('123456789012')
        ->and($found)->toBe(['TELÉFONO']);
});

it('reemplaza los nombres de los trabajadores de la empresa, sin importar mayúsculas', function () {
    workersInDb([['full_name' => 'Juan Carlos Quispe Mamani', 'first_name' => 'Juan Carlos', 'paternal_last_name' => 'Quispe']]);
    [$text, $found] = Redactor::redact('El trabajador JUAN CARLOS QUISPE MAMANI resbaló; Juan Carlos Quispe fue atendido.');

    expect($text)->toBe('El trabajador [PERSONA] resbaló; [PERSONA] fue atendido.')
        ->and($found)->toBe(['PERSONA']);
});

it('deja el texto igual cuando no hay datos personales', function () {
    workersInDb([]);
    expect(Redactor::redact('Caída al mismo nivel en el almacén 3.'))->toBe(['Caída al mismo nivel en el almacén 3.', []]);
});

it('rechaza textos del acta con datos de salud y deja pasar los demás', function () {
    $noHealth = (new ReflectionMethod(MinutesController::class, 'noHealth'))->getClosure();

    expect(fn () => $noHealth('El trabajador tiene diabetes'))->toThrow(ApiProblem::class)
        ->and(fn () => $noHealth('Presentó descanso médico de 3 días'))->toThrow(ApiProblem::class)
        ->and(fn () => $noHealth('Se revisó el diagnóstico de línea base'))->toThrow(ApiProblem::class);

    $noHealth('Se acordó comprar arneses y señalizar la zona de carga.');
    expect(true)->toBeTrue();
});
