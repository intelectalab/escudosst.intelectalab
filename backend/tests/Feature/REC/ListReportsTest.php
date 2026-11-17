<?php

// Reportes de listas (§11 de las fichas): padrón de trabajadores, usuarios × roles × alcances, lista maestra de documentos,
// acciones con % de cierre en plazo y antigüedad, Pareto de causas y firmas pendientes.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('TRB', 'USR', 'DOC', 'ACR', 'INV', 'FIR') and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

function lrSheet(string $bytes): string
{
    $file = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($file, $bytes);
    $zip = new ZipArchive;
    $zip->open($file);
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml').$zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    unlink($file);

    return (string) $xml;
}

it('genera en Excel el padrón, la matriz de usuarios, la lista maestra, las acciones y las firmas pendientes', function () {
    $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(68000000, 68999999), 'first_name' => 'Ana', 'paternal_last_name' => 'Padrón'], $this->h)->assertCreated();
    $cases = [
        'workers.xlsx' => ['PADRÓN DE TRABAJADORES ACTIVOS', 'Padrón'],
        'users.xlsx' => ['MATRIZ DE USUARIOS, ROLES Y ALCANCES', 'corporativo@escudo.test'],
        'documents.xlsx' => ['LISTA MAESTRA DE DOCUMENTOS'],
        'corrective_actions.xlsx' => ['ACCIONES CORRECTIVAS Y PREVENTIVAS'],
        'signature_requests.xlsx' => ['FIRMAS PENDIENTES POR MÓDULO Y RESPONSABLE'],
    ];
    foreach ($cases as $path => $texts) {
        $res = $this->get("/api/v1/reports/{$path}", $this->h);
        expect($res->status())->toBe(200, $path)->and(substr($res->getContent(), 0, 2))->toBe('PK');
        $xml = lrSheet($res->getContent());
        foreach ($texts as $t) {
            expect($xml)->toContain(htmlspecialchars($t, ENT_XML1));
        }
    }
});

it('calcula el cierre en plazo y la antigüedad de las acciones, y el Pareto de causas', function () {
    $u = '01900000-0000-7000-8000-000000000303';
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $tag = 'LR-'.random_int(1000, 999999);
    DB::insert("insert into corrective_actions (company_id, code, source_type, title, responsible_user_id, due_on, status, completed_at, created_at)
        values (?, ?, 'other', 'Acción a tiempo', ?, current_date, 'in_progress', now() - interval '1 day', now() - interval '20 days')", [HUAYLAS_ID, $tag.'-A', $u]);
    DB::insert("insert into corrective_actions (company_id, code, source_type, title, responsible_user_id, due_on, status, created_at)
        values (?, ?, 'other', 'Acción vieja', ?, current_date + 5, 'open', now() - interval '400 days')", [HUAYLAS_ID, $tag.'-B', $u]);
    DB::select("select set_config('app.company_id', '', false)");

    $d = $this->getJson('/api/v1/reports/corrective_actions', $this->h)->assertOk()->json('data');
    expect($d['done'])->toBeGreaterThanOrEqual(1)->and($d['on_time_pct'])->toBeInt()
        ->and($d['open_max_days'])->toBeGreaterThanOrEqual(400)
        ->and($d['oldest'][0]['code'])->toBe($tag.'-B')
        ->and(collect($d['by_source'])->pluck('key'))->not->toBeEmpty();

    $p = $this->getJson('/api/v1/reports/investigation_causes', $this->h)->assertOk()->json('data');
    expect($p)->toHaveKeys(['total', 'categories']);
    if ($p['total'] > 0) {
        expect(end($p['categories'])['cumulative_pct'])->toEqual(100);
    }
});
