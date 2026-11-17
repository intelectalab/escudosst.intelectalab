<?php

// M-AUD: toda exportación de una lista queda en la bitácora; bitácora completa en CSV con huella; «Mis accesos» del trabajador.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
});

it('anota cualquier exportación de lista y exporta la bitácora con su huella', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->getJson('/api/v1/records/TEN/sites?export=1&per_page=500', $h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from audit_logs where action = 'export' and entity_type = 'sites' and occurred_at > now() - interval '1 minute'")->n)->toBeGreaterThan(0);
    DB::select("select set_config('app.company_id', '', false)");
    $csv = $this->get('/api/v1/company/audit/export?from='.now()->subDay()->toDateString(), $h)->assertOk()->streamedContent();
    expect($csv)->toContain('Huella de la fila')->toContain('Huella SHA-256 del contenido anterior');
    // La huella final corresponde al contenido.
    $body = substr($csv, 3);
    $pos = strrpos($body, '"Huella SHA-256');
    preg_match('/"([a-f0-9]{64})"/', substr($body, $pos), $m);
    expect(hash('sha256', substr($body, 0, $pos)))->toBe($m[1]);
});
