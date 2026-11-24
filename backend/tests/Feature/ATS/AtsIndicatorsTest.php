<?php

// M-ATS RN-ATS-02 (indicador de firmas tardías y de ATS firmados antes de iniciar, en DSH), RN-ATS-03 (ats_block_untrained también
// para la firma cargada desde la ficha) y SCR-ATS-02 (foto del lugar en el ATS impreso).

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'ATS' and deleted_at is null", [HUAYLAS_ID]);
    $this->site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null order by is_main desc limit 1', [HUAYLAS_ID])->id;
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
});

function aiWorker(string $dni): string
{
    return DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status)
                           values (?, ?, decode(md5(?), 'hex'), 'Ejecutor', 'ATS', 'active') returning id", [HUAYLAS_ID, $dni, $dni])->id;
}

function aiAts(string $site, string $status, ?string $procedure = null): string
{
    return DB::selectOne("insert into ats_forms (company_id, code, task, site_id, performed_on, started_at, status, procedure_id)
                           values (?, ?, 'Tarea de prueba', ?, '2032-03-10', '2032-03-10 08:00-05', ?, ?) returning id",
        [HUAYLAS_ID, 'ATS-KPI-'.random_int(1000, 9999999), $site, $status, $procedure])->id;
}

it('los indicadores cuentan los ATS firmados antes de iniciar y las firmas tardías', function () {
    $ok = aiAts($this->site, 'closed');
    $late = aiAts($this->site, 'closed');
    DB::insert("insert into ats_participants (company_id, ats_form_id, worker_id, role, signed_at, late_signature) values (?, ?, ?, 'executor', '2032-03-10 07:30-05', false)", [HUAYLAS_ID, $ok, aiWorker('81810001')]);
    DB::insert("insert into ats_participants (company_id, ats_form_id, worker_id, role, signed_at, late_signature) values (?, ?, ?, 'executor', '2032-03-10 07:30-05', false)", [HUAYLAS_ID, $late, aiWorker('81810002')]);
    DB::insert("insert into ats_participants (company_id, ats_form_id, worker_id, role, signed_at, late_signature) values (?, ?, ?, 'executor', '2032-03-10 09:00-05', true)", [HUAYLAS_ID, $late, aiWorker('81810003')]);

    $kpis = collect((new \App\Modules\DSH\Services\KpiService)->overview(2032, null, '2032-12-31'));
    $before = $kpis->firstWhere('code', 'ats_signed_before_start');
    $count = $kpis->firstWhere('code', 'ats_late_signatures');
    expect($before)->not->toBeNull()->and($before['value'])->toEqual(50.0)->and($before['numerator'])->toBe(1)->and($before['denominator'])->toBe(2)
        ->and($count['value'])->toBe(1)->and($count['denominator'])->toBe(3);
});

it('con ats_block_untrained activo la firma cargada desde la ficha se rechaza si falta la capacitación', function () {
    $t = DB::selectOne("insert into trainings (company_id, title, training_type) values (?, 'Trabajo en altura', 'specific') returning id", [HUAYLAS_ID])->id;
    $proc = DB::selectOne("insert into procedures (company_id, code, title) values (?, ?, 'PETS de prueba') returning id", [HUAYLAS_ID, 'PETS-'.random_int(1000, 999999)])->id;
    $v = DB::selectOne('insert into procedure_versions (company_id, procedure_id, version_number, required_training_ids) values (?, ?, 1, ?::uuid[]) returning id', [HUAYLAS_ID, $proc, '{'.$t.'}'])->id;
    DB::update('update procedures set current_version_id = ? where id = ?', [$v, $proc]);
    $ats = aiAts($this->site, 'draft', $proc);
    $w = aiWorker('81810004');
    $p = DB::selectOne("insert into ats_participants (company_id, ats_form_id, worker_id, role) values (?, ?, ?, 'executor') returning id", [HUAYLAS_ID, $ats, $w])->id;

    // Sin el bloqueo: firma con advertencia (se registra).
    $free = aiWorker('81810005');
    $p2 = DB::selectOne("insert into ats_participants (company_id, ats_form_id, worker_id, role) values (?, ?, ?, 'executor') returning id", [HUAYLAS_ID, $ats, $free])->id;
    $this->patchJson("/api/v1/records/ATS/ats_participants/{$p2}", ['signed_at' => now()->toIso8601String()], $this->h)->assertOk();

    DB::insert("insert into legal_parameters (company_id, key, value, value_type, description, valid_from, to_confirm) values (?, 'ats_block_untrained', 'true', 'boolean', 'Bloqueo de prueba', '2000-01-01', false)", [HUAYLAS_ID]);
    $this->patchJson("/api/v1/records/ATS/ats_participants/{$p}", ['signed_at' => now()->toIso8601String()], $this->h)
        ->assertStatus(422)->assertJsonPath('errors.signed_at.0', 'Este trabajador no puede firmar el ATS: le falta la capacitación «Trabajo en altura».');
    expect(DB::selectOne('select signed_at from ats_participants where id = ?', [$p])->signed_at)->toBeNull();
});

it('el ATS impreso trae la foto del lugar', function () {
    $photo = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->image('lugar.jpg', 40, 30), 'module' => 'ATS'], $this->h + ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $ats = aiAts($this->site, 'draft');
    DB::update('update ats_forms set site_photo_file_id = ? where id = ?', [$photo, $ats]);
    $r = $this->getJson("/api/v1/reports/ats_forms/{$ats}", $this->h)->assertOk();
    expect((string) $r->json('data.ats.site_photo'))->toStartWith('data:image/');
});
