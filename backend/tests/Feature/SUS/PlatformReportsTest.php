<?php

// M-SUS §11: reportes del panel de la plataforma en Excel: cuentas por plan y complemento con altas y bajas, y comprobantes
// emitidos por periodo con su estado SUNAT. Solo el equipo de la plataforma; cada descarga queda en la bitácora.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

afterEach(function () {
    DB::connection('platform')->rollBack(0);
});

function platformSheet(string $bytes): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive;
    $zip->open($tmp);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($tmp);

    return html_entity_decode(strip_tags(str_replace('</c>', ' | ', $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

it('exporta las cuentas por plan con altas y bajas del periodo', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $from = now('America/Lima')->subYears(5)->toDateString();
    $to = now('America/Lima')->toDateString();
    $r = $this->get("/api/v1/internal/reports/accounts.xlsx?from={$from}&to={$to}", $h)->assertOk();
    expect($r->headers->get('Content-Type'))->toContain('spreadsheetml');
    $text = platformSheet($r->getContent());
    $plan = DB::connection('platform')->selectOne("select name from plans where code = 'P'")->name;
    expect($text)->toContain('Cuentas por plan y complemento; altas y bajas')->toContain('Altas del periodo:')->toContain('Baja en el periodo')->toContain($plan);
    expect(DB::connection('platform')->selectOne("select count(*) as n from audit_logs where company_id is null and action = 'export' and entity_type = 'subscriptions' and occurred_at > now() - interval '1 minute'")->n)
        ->toBeGreaterThan(0);
});

it('exporta los comprobantes del periodo con su estado SUNAT y valida las fechas', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    DB::connection('platform')->beginTransaction();
    $num = random_int(100000, 999999);
    $acc = DB::connection('platform')->selectOne('select id from accounts where deleted_at is null limit 1')->id;
    DB::connection('platform')->insert("insert into invoices (account_id, document_type, series, number, issue_on, customer_name, customer_tax_id, subtotal, tax, total, status, sunat_status, lines)
        values (?, 'invoice', 'F901', ?, current_date, 'Cliente Reporte SAC', '20123456789', 100, 18, 118, 'issued', 'pending', '[{\"description\": \"Plan Profesional\"}]'::jsonb)", [$acc, $num]);
    $r = $this->get('/api/v1/internal/reports/invoices.xlsx', $h)->assertOk();
    $text = platformSheet($r->getContent());
    expect($text)->toContain('Comprobantes emitidos por periodo')->toContain('F901-'.$num)->toContain('Cliente Reporte SAC')->toContain('Pendiente')->toContain('Estado SUNAT');
    $this->getJson('/api/v1/internal/reports/invoices.xlsx?from=2026-13-01', $h)->assertStatus(422);
    $this->getJson('/api/v1/internal/reports/invoices.xlsx?from=2026-02-01&to=2026-01-01', $h)->assertStatus(422);
});

it('una empresa cliente no descarga los reportes de la plataforma', function () {
    $this->getJson('/api/v1/internal/reports/accounts.xlsx', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertStatus(403);
    $this->getJson('/api/v1/internal/reports/invoices.xlsx', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertStatus(403);
});
