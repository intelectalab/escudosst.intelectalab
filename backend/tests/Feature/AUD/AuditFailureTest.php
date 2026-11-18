<?php

// M-AUD: el cambio y su registro en la bitácora van en la misma transacción: si la bitácora no se puede escribir, el cambio no se guarda.

use Illuminate\Support\Facades\DB;

it('si la bitácora falla, el cambio no se guarda', function () {
    $GLOBALS['sus_tokens'] = [];
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $owner = DB::connection('pgsql_owner');
    $owner->statement("create or replace function test_fail_audit() returns trigger language plpgsql as \$\$
        begin if new.changes::text like '%FALLA-BITACORA%' then raise exception 'bitácora no disponible'; end if; return new; end \$\$");
    $owner->statement('drop trigger if exists trg_test_fail_audit on audit_logs');
    $owner->statement('create trigger trg_test_fail_audit before insert on audit_logs for each row execute function test_fail_audit()');
    try {
        $this->withoutExceptionHandling();
        expect(fn () => $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'suggestion', 'description' => 'FALLA-BITACORA prueba'], $h))
            ->toThrow(\Illuminate\Database\QueryException::class);
    } finally {
        $owner->statement('drop trigger if exists trg_test_fail_audit on audit_logs');
        $owner->statement('drop function if exists test_fail_audit()');
    }
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne("select count(*) as n from substandard_reports where description = 'FALLA-BITACORA prueba'")->n)->toBe(0);
});

it('archiva cifrados los meses vencidos de la bitácora sin borrar registros', function () {
    $before = DB::connection('platform')->selectOne('select count(*) as n from companies')->n;
    $cut = date('Y-m-01', strtotime('first day of next month'));
    $n = app(\App\Modules\AUD\AuditArchive::class)->run($cut);
    expect($n)->toBeGreaterThan(0);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $a = DB::selectOne('select * from audit_archives where company_id = ? order by period desc limit 1', [HUAYLAS_ID]);
    expect($a)->not->toBeNull();
    $sealed = \Illuminate\Support\Facades\Storage::disk('backups')->get($a->local_key);
    expect(hash('sha256', $sealed))->toBe($a->sha256);
    $lines = explode("\n", \App\Modules\AUD\AuditArchive::open($sealed));
    expect(count($lines))->toBe((int) $a->rows_count)->and(json_decode($lines[0], true)['company_id'])->toBe(HUAYLAS_ID);
    // No borra: los registros siguen en la base y no se archiva dos veces.
    expect(DB::selectOne("select count(*) as n from audit_logs where company_id = ? and date_trunc('month', occurred_at)::date = ?", [HUAYLAS_ID, $a->period])->n)->toBeGreaterThanOrEqual((int) $a->rows_count);
    expect(app(\App\Modules\AUD\AuditArchive::class)->run($cut))->toBe(0);
    foreach (DB::select('select local_key from audit_archives') as $r) {
        \Illuminate\Support\Facades\Storage::disk('backups')->delete($r->local_key);
    }
    expect($before)->toBeGreaterThan(0);
});
