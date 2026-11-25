<?php

// M-INV RN-INV-04: la investigación fuera de plazo se muestra «Vencida» (femenino) en el registro.

use App\Core\Auth\Permissions;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

it('muestra el estado vencido de la investigación como «Vencida»', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $res = collect($this->getJson('/api/v1/records/INV', $h)->assertOk()->json('resources'))->firstWhere('table', 'investigations');
    $status = collect($res['fields'])->firstWhere('name', 'status');
    expect(collect($status['options'])->firstWhere('value', 'overdue')['label'])->toBe('Vencida');
});
