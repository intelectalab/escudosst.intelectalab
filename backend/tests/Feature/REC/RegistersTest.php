<?php

// Registros obligatorios en Excel: los 10 tipos se generan con datos reales y quedan en register_exports.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('genera los 10 registros obligatorios en Excel y deja constancia de cada descarga', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'REG-S', 'name' => 'Sede registros'], $h)->json('data.id');
    $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'minor_accident', 'occurred_at' => '2026-03-10T10:00:00-05:00', 'site_id' => $site,
        'description' => 'Golpe leve en la mano'], $h)->assertCreated();

    $types = collect($this->getJson('/api/v1/reg/types', $h)->assertOk()->json('data'))->pluck('type');
    expect($types)->toHaveCount(10);
    foreach ($types as $type) {
        $res = $this->get("/api/v1/reg/{$type}.xlsx?from=2026-01-01&to=2026-12-31", $h);
        expect($res->status())->toBe(200, $type)->and(substr($res->getContent(), 0, 2))->toBe('PK');
    }
    $acc = $this->get('/api/v1/reg/accidents.xlsx?from=2026-01-01&to=2026-12-31', $h);
    $file = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($file, $acc->getContent());
    $zip = new ZipArchive;
    $zip->open($file);
    expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('Golpe leve en la mano')->toContain('REGISTRO DE ACCIDENTES DE TRABAJO');
    $zip->close();
    unlink($file);

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::table('register_exports')->where('register_type', 'accidents')->where('rows_count', '>=', 1)->exists())->toBeTrue();
    $this->get('/api/v1/reg/inventado.xlsx', $h)->assertNotFound();
});
