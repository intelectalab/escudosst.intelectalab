<?php

// M-PLA §9 / RN-PLA-08: el plan vigente pasa solo a «cerrado» al terminar su año (corrida diaria de alertas SST) y se
// genera su informe de cumplimiento; el plan del año en curso sigue vigente.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $this->year = (int) now('America/Lima')->year;
    DB::update('update annual_plans set deleted_at = now() where year in (?, ?) and deleted_at is null', [$this->year - 1, $this->year]);
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
});

it('cierra el plan vigente del año terminado con su informe de cumplimiento y deja el del año en curso', function () {
    $old = DB::selectOne("insert into annual_plans (company_id, year, title, status, approved_at) values (?, ?, ?, 'current', now() - interval '1 year') returning id",
        [ANDINAS_ID, $this->year - 1, 'Plan Anual SST '.($this->year - 1)])->id;
    $now = DB::selectOne("insert into annual_plans (company_id, year, title, status, approved_at) values (?, ?, ?, 'current', now()) returning id",
        [ANDINAS_ID, $this->year, 'Plan Anual SST '.$this->year])->id;

    app(\App\Modules\NOT\SstAlerts::class)->refreshStatuses(DB::connection());

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $p = DB::selectOne('select status, closed_at, closed_by, closure_report_file_id from annual_plans where id = ?', [$old]);
    expect($p->status)->toBe('closed')->and($p->closed_at)->not->toBeNull()->and($p->closed_by)->toBeNull()->and($p->closure_report_file_id)->not->toBeNull();
    $f = DB::selectOne('select mime_type, entity_id, original_name from files where id = ?', [$p->closure_report_file_id]);
    expect($f->mime_type)->toBe('application/pdf')->and($f->entity_id)->toBe($old)->and($f->original_name)->toContain('informe-de-cumplimiento');
    expect(DB::selectOne('select status from annual_plans where id = ?', [$now])->status)->toBe('current');

    // Una segunda corrida no vuelve a generar el informe.
    app(\App\Modules\NOT\SstAlerts::class)->refreshStatuses(DB::connection());
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne('select closure_report_file_id from annual_plans where id = ?', [$old])->closure_report_file_id)->toBe($p->closure_report_file_id);
});
