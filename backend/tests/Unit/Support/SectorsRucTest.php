<?php

use App\Core\Support\Ruc;
use App\Core\Support\Sectors;

it('lista los sectores en el orden de la constante y arma la regla «in:»', function () {
    expect(Sectors::codes())->toBe(array_keys(Sectors::LABELS))
        ->and(Sectors::codes())->toContain('construction', 'health', 'other')
        ->and(Sectors::csv())->toBe(implode(',', array_keys(Sectors::LABELS)))
        ->and(Sectors::csv())->not->toContain(' ');
});

it('traduce el código del sector a su nombre y devuelve null sin código', function () {
    expect(Sectors::label('mining'))->toBe('Minería')
        ->and(Sectors::label('health'))->toBe('Salud')
        ->and(Sectors::label(null))->toBeNull()
        ->and(Sectors::label('aviacion'))->toBe('Aviacion');
});

it('acepta RUC con dígito verificador correcto', function (string $ruc) {
    expect(Ruc::isValid($ruc))->toBeTrue();
})->with(['20100070970', '20131312955']);

it('rechaza RUC con prefijo, largo o dígito verificador incorrectos', function (?string $ruc) {
    expect(Ruc::isValid($ruc))->toBeFalse();
})->with([null, '', '2010007097', '201000709701', '30100070970', '20100070971', '2010007097A']);
