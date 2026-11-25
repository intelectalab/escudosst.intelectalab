<?php

use App\Modules\IAA\PromptTemplates;

it('lista los prompts de casos de uso sin el del sistema', function () {
    $codes = PromptTemplates::codes();

    expect($codes)->not->toBeEmpty()
        ->and($codes)->not->toContain('system')
        ->and($codes)->toBe(collect($codes)->sort()->values()->all());
});

it('cada prompt trae su cabecera con código y versión numérica', function () {
    foreach ([...PromptTemplates::codes(), 'system'] as $code) {
        $p = PromptTemplates::load($code);
        expect($p['code'])->toBe($code)
            ->and($p['version'])->toMatch('/^\d+$/')
            ->and($p['body'])->not->toBe('')
            ->and($p['body'])->not->toStartWith('---');
    }
    expect(PromptTemplates::system())->toContain('[PERSONA]');
});

it('rechaza códigos inexistentes o con caracteres no permitidos', function (string $code) {
    PromptTemplates::load($code);
})->with(['no_existe', '../system', 'Mayus', 'x'])->throws(RuntimeException::class);

it('reemplaza los marcadores y falla si falta un dato', function () {
    $code = PromptTemplates::codes()[0];
    $body = PromptTemplates::load($code)['body'];
    preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $body, $m);
    $names = array_unique($m[1]);
    $vars = array_combine($names, array_map(fn ($n) => "<<{$n}>>", $names)) ?: [];

    $out = PromptTemplates::render($code, $vars);
    expect($out)->not->toMatch('/\{\{\s*[a-z_]+\s*\}\}/');
    foreach ($names as $n) {
        expect($out)->toContain("<<{$n}>>");
    }

    if ($names !== []) {
        expect(fn () => PromptTemplates::render($code, array_slice($vars, 1, null, true)))->toThrow(RuntimeException::class);
    }
});
