<?php

use App\Core\Http\ApiProblem;
use App\Core\Support\UploadGuard;
use Illuminate\Http\UploadedFile;

/** Archivo real en disco con el contenido dado y el nombre que «manda el navegador». */
function uploaded(string $name, string $bytes, string $clientMime = 'application/octet-stream'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'ug');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, $clientMime, null, true);
}

function xlsxBytes(bool $withWorkbook = true): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
    if ($withWorkbook) {
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook/>');
    }
    $zip->close();

    return (string) file_get_contents($path);
}

const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

it('lee el tipo real del contenido y no de la extensión ni de la cabecera', function () {
    expect(UploadGuard::realMime(uploaded('foto.pdf', base64_decode(PNG_1PX), 'application/pdf')))->toBe('image/png')
        ->and(UploadGuard::realMime(uploaded('nota.png', "hola\n", 'image/png')))->toBe('text/plain');
});

it('acepta un tipo de la lista blanca, también con comodín', function () {
    $png = uploaded('foto.png', base64_decode(PNG_1PX), 'image/png');

    expect(UploadGuard::assertType($png, ['image/png']))->toBe('image/png')
        ->and(UploadGuard::assertType($png, ['application/pdf', 'image/*']))->toBe('image/png');
});

it('rechaza un HTML disfrazado de imagen', function () {
    UploadGuard::assertType(uploaded('foto.png', '<!doctype html><html><body><script>alert(1)</script></body></html>', 'image/png'), ['image/*']);
})->throws(ApiProblem::class, 'Revisa el archivo');

it('acepta planillas CSV y Excel cuyo contenido coincide con la extensión', function () {
    UploadGuard::spreadsheet(uploaded('trabajadores.csv', "dni,nombre\n45678912,Ana\n", 'text/csv'));
    UploadGuard::spreadsheet(uploaded('trabajadores.xlsx', xlsxBytes()));
    expect(true)->toBeTrue();
});

it('rechaza planillas con extensión no permitida o contenido que no corresponde', function (string $name, string $bytes, string $detail) {
    try {
        UploadGuard::spreadsheet(uploaded($name, $bytes));
        $this->fail('Debió rechazar el archivo');
    } catch (ApiProblem $e) {
        expect($e->status)->toBe(422)->and($e->detail)->toContain($detail)->and($e->extra['errors'])->toHaveKey('file');
    }
})->with([
    'extensión' => ['trabajadores.xls', 'x', 'Sube el archivo en Excel'],
    'imagen con nombre .csv' => ['trabajadores.csv', base64_decode(PNG_1PX), 'no es realmente un CSV'],
    'texto con nombre .xlsx' => ['trabajadores.xlsx', "dni,nombre\n", 'no es realmente un Excel'],
    'zip sin hoja de cálculo' => ['trabajadores.xlsx', xlsxBytes(false), 'no es realmente un Excel'],
]);
