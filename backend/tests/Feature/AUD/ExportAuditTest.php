<?php

// M-AUD RN-AUD-05: toda exportación (Excel, PDF, imprimible, expediente) queda en audit_logs con su ruta, filtros y filas,
// una sola vez por descarga, desde un único middleware del grupo «company».

use App\Core\Http\Middleware\AuditExports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
});

function exportAuditRows(string $route): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $rows = DB::select("select action, module_code, entity_type, entity_id, changes from audit_logs where company_id = ? and changes->>'route' = ? and occurred_at > now() - interval '2 minutes'",
        [HUAYLAS_ID, $route]);
    DB::select("select set_config('app.company_id', '', false)");

    return $rows;
}

it('anota la descarga de un Excel con sus filtros y número de filas, una sola vez', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $before = count(exportAuditRows('reports/workers.xlsx'));
    $this->get('/api/v1/reports/workers.xlsx?status=active', $h)->assertOk();
    $rows = exportAuditRows('reports/workers.xlsx');
    expect($rows)->toHaveCount($before + 1);
    $last = end($rows);
    $c = json_decode($last->changes, true);
    expect($last->action)->toBe('export')->and($last->module_code)->toBe('REC')->and($last->entity_type)->toBe('workers')
        ->and($c['filters'])->toBe(['status' => 'active'])->and($c['rows'])->toBeInt();
});

it('anota un imprimible (legajo) como «print» con el registro', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 49999999), 'first_name' => 'Legajo', 'paternal_last_name' => 'Auditado'], $h)
        ->assertCreated()->json('data.id');
    $this->getJson("/api/v1/trb/workers/{$w}/legajo", $h)->assertOk();
    $rows = array_values(array_filter(exportAuditRows('trb/workers/{id}/legajo'), fn ($r) => $r->entity_id === $w));
    expect($rows)->toHaveCount(1)->and($rows[0]->action)->toBe('print')->and($rows[0]->module_code)->toBe('TRB')->and($rows[0]->entity_type)->toBe('workers/legajo');
});

it('no anota una descarga que falla ni una consulta que no es exportación', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->getJson('/api/v1/trb/workers/'.\Illuminate\Support\Str::uuid().'/legajo', $h)->assertNotFound();
    expect(exportAuditRows('trb/workers/{id}/legajo'))->each(fn ($r) => $r->entity_id->not->toBeNull());
    expect(AuditExports::kind('api/v1/me/worker/reports'))->toBeNull()->and(AuditExports::kind('api/v1/records/{module}/{table}'))->toBeNull();
});

it('cubre todas las exportaciones de la empresa: cada Excel, PDF e imprimible pasa por el grupo «company»', function () {
    $covered = [];
    $missing = [];
    foreach (Route::getRoutes() as $r) {
        $uri = $r->uri();
        if (! in_array('GET', $r->methods(), true) || ! AuditExports::kind($uri) || str_starts_with($uri, 'api/v1/public/') || str_starts_with($uri, 'api/v1/internal/')
            || str_starts_with($uri, 'api/v1/platform/')) {
            continue;
        }
        in_array('company', $r->gatherMiddleware(), true) ? $covered[] = $uri : $missing[] = $uri;
    }
    expect($missing)->toBe([])->and(count($covered))->toBeGreaterThan(40);
});
