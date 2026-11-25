<?php

use App\Core\Support\Logging\StructuredJsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

function logLine(string $message, array $context = []): array
{
    $record = new LogRecord(new DateTimeImmutable('2026-01-05 15:00:00', new DateTimeZone('UTC')), 'app', Level::Warning, $message, $context);

    return json_decode((new StructuredJsonFormatter)->format($record), true);
}

it('enmascara DNI y celulares dejando los 4 primeros dígitos', function () {
    expect(StructuredJsonFormatter::mask('DNI 45678912 y celular 987654321'))->toBe('DNI 4567**** y celular 9876*****')
        ->and(StructuredJsonFormatter::mask('pedido 1234567 y RUC 20100070970'))->toBe('pedido 1234567 y RUC 20100070970')
        ->and(StructuredJsonFormatter::mask('fecha 2026-01-05'))->toBe('fecha 2026-01-05');
});

it('escribe una línea JSON con los campos de docs/02 §12 y la hora de Lima', function () {
    $line = logLine('Falló el envío al 987654321', ['trace_id' => 'ABCDEF1234', 'company_id' => 'c1', 'duration_ms' => 12]);

    expect($line)->toHaveKeys(['ts', 'level', 'trace_id', 'company_id', 'user_id', 'route', 'job', 'duration_ms', 'message'])
        ->and($line['level'])->toBe('warning')
        ->and($line['trace_id'])->toBe('ABCDEF1234')
        ->and($line['company_id'])->toBe('c1')
        ->and($line['duration_ms'])->toBe(12)
        ->and($line['ts'])->toStartWith('2026-01-05T10:00:00')
        ->and($line['message'])->toBe('Falló el envío al 9876*****')
        ->and($line)->not->toHaveKey('context');
});

it('oculta contraseñas, tokens y datos clínicos del contexto, también anidados', function () {
    $line = logLine('x', ['password' => 'secreta', 'Authorization' => 'Bearer abc', 'datos' => ['api_key' => 'k', 'diagnosis' => 'algo', 'dni' => '45678912', 'n' => 3]]);

    expect($line['context']['password'])->toBe('[oculto]')
        ->and($line['context']['Authorization'])->toBe('[oculto]')
        ->and($line['context']['datos']['api_key'])->toBe('[oculto]')
        ->and($line['context']['datos']['diagnosis'])->toBe('[oculto]')
        ->and($line['context']['datos']['dni'])->toBe('4567****')
        ->and($line['context']['datos']['n'])->toBe(3);
});

it('resume la excepción sin la ruta completa y con el mensaje enmascarado', function () {
    $line = logLine('error', ['exception' => new RuntimeException('Sin respuesta del 987654321')]);

    expect($line['exception']['class'])->toBe(RuntimeException::class)
        ->and($line['exception']['message'])->toBe('Sin respuesta del 9876*****')
        ->and($line['exception']['at'])->toStartWith('StructuredJsonFormatterTest.php:');
});
