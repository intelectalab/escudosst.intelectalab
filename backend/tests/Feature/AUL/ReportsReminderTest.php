<?php

// M-AUL §10 «Recordatorio por compromiso asumido» (target_date y reminder_opt_in del compromiso) y §11: avance y aprobados por
// área y cargo, y embudo de conversión de recomendaciones por fuente, regla, curso, canal, área y empresa compradora.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode, source, company_enabled from feature_flags where company_id = ? and module_code in ('AUL', 'VTA')", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'AUL'", [HUAYLAS_ID]);
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
    $db = DB::connection('platform');
    foreach ($this->flags as $f) {
        $db->update('update feature_flags set mode = ?, source = ?, company_enabled = ? where id = ?', [$f->mode, $f->source, $f->company_enabled ? 'true' : 'false', $f->id]);
    }
    app('auth')->forgetGuards();
    Permissions::flush();
});

function aulrq(string $sql, array $b = []): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $r = DB::select($sql, $b);
    DB::select("select set_config('app.company_id', '', false)");

    return $r;
}

function aulrArea($test, string $name): string
{
    $site = $test->postJson('/api/v1/records/TEN/sites', ['code' => 'AULS-'.random_int(1000, 9999), 'name' => 'Sede '.$name], $test->h)->assertCreated()->json('data.id');

    return $test->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'AULA-'.random_int(1000, 9999), 'name' => $name], $test->h)->assertCreated()->json('data.id');
}

/** Curso publicado con una lección y los trabajadores dados matriculados; devuelve [curso, [trabajador => matrícula]]. */
function aulrCourse($test, array $workers, string $commitment = 'none'): array
{
    $c = $test->postJson('/api/v1/aul/admin/courses', array_filter(['title' => 'Curso reporte '.random_int(1000, 9999), 'hours' => 2, 'passing_score' => 70, 'completion_commitment' => $commitment,
        'commitment_text' => $commitment === 'none' ? null : 'Me comprometo a terminar el curso.']), $test->h)->assertCreated()->json('data.id');
    $test->postJson("/api/v1/aul/admin/courses/{$c}/lessons", ['lesson_type' => 'text', 'title' => 'Lectura', 'content_html' => '<p>Contenido</p>'], $test->h)->assertCreated();
    $test->postJson("/api/v1/aul/admin/courses/{$c}/publish", ['publish' => true], $test->h)->assertOk();
    $test->postJson("/api/v1/aul/admin/courses/{$c}/enroll", ['worker_ids' => $workers], $test->h)->assertCreated();
    $e = [];
    foreach (aulrq('select id, worker_id from course_enrollments where course_id = ?', [$c]) as $r) {
        $e[$r->worker_id] = $r->id;
    }

    return [$c, $e];
}

it('guarda la fecha comprometida y el recordatorio sin cambiar el vencimiento, y avisa solo a quien lo pidió', function () {
    $w1 = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Rita', 'paternal_last_name' => 'Recuerda'], $this->h)->json('data.id');
    $w2 = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Nico', 'paternal_last_name' => 'Nolorecuerda'], $this->h)->json('data.id');
    [$c, $e] = aulrCourse($this, [$w1, $w2], 'mandatory');
    $target = now('America/Lima')->addDays(3)->toDateString();

    $this->postJson("/api/v1/aul/enrollments/{$e[$w1]}/commitment", ['reminder_opt_in' => true], $this->h)->assertStatus(422)->assertJsonValidationErrors('target_date');
    $this->postJson("/api/v1/aul/enrollments/{$e[$w1]}/commitment", ['target_date' => now('America/Lima')->subDay()->toDateString()], $this->h)->assertStatus(422)->assertJsonValidationErrors('target_date');
    $this->postJson("/api/v1/aul/enrollments/{$e[$w1]}/commitment", ['target_date' => $target, 'reminder_opt_in' => true], $this->h)->assertCreated();
    $this->postJson("/api/v1/aul/enrollments/{$e[$w2]}/commitment", ['target_date' => $target], $this->h)->assertCreated();

    $cc = aulrq('select cc.target_date, cc.reminder_opt_in, e.due_on from completion_commitments cc join course_enrollments e on e.id = cc.enrollment_id where cc.enrollment_id = ?', [$e[$w1]])[0];
    expect($cc->target_date)->toBe($target)->and($cc->reminder_opt_in)->toBeTrue()->and($cc->due_on)->toBeNull();

    $db = DB::connection('pgsql');
    $db->select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    app(\App\Modules\AUL\AulAlerts::class)->forCompany($db, HUAYLAS_ID, false);
    app(\App\Modules\AUL\AulAlerts::class)->forCompany($db, HUAYLAS_ID, false);   // no se repite
    $notes = aulrq("select subject_id, body from notifications where event_code = 'aul.commitment_reminder' and subject_id in (?, ?)", [$e[$w1], $e[$w2]]);
    expect($notes)->toHaveCount(1)->and($notes[0]->subject_id)->toBe($e[$w1])
        ->and($notes[0]->body)->toContain(implode('/', array_reverse(explode('-', $target))));
});

it('reporta avance y aprobados por área y por cargo, en JSON y Excel', function () {
    $area = aulrArea($this, 'Área reporte aula');
    $w1 = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Ana', 'paternal_last_name' => 'Aprueba', 'current_area_id' => $area], $this->h)->json('data.id');
    $w2 = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Beto', 'paternal_last_name' => 'Desaprueba', 'current_area_id' => $area], $this->h)->json('data.id');
    $w3 = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Cris', 'paternal_last_name' => 'Sinarea'], $this->h)->json('data.id');
    [$c, $e] = aulrCourse($this, [$w1, $w2, $w3]);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update course_enrollments set status = 'completed', passed = true, progress_pct = 100, completed_at = now() where id = ?", [$e[$w1]]);
    DB::update("update course_enrollments set status = 'failed', passed = false, progress_pct = 100 where id = ?", [$e[$w2]]);
    DB::select("select set_config('app.company_id', '', false)");

    $rows = collect($this->getJson("/api/v1/aul/admin/reports/progress?by=area&course_id={$c}", $this->h)->assertOk()->json('data.rows'));
    $r = $rows->firstWhere("group", "Área reporte aula");
    expect($r['enrolled'])->toBe(2)->and($r['passed'])->toBe(1)->and($r['failed'])->toBe(1)->and($r['passed_pct'])->toEqual(50)->and($r['avg_progress_pct'])->toEqual(100)
        ->and($rows->firstWhere('group', 'Sin área')['not_started'])->toBe(1);
    expect(collect($this->getJson("/api/v1/aul/admin/reports/progress?by=position&course_id={$c}", $this->h)->assertOk()->json('data.rows'))->sum('enrolled'))->toBe(3);
    $this->getJson('/api/v1/aul/admin/reports/progress?by=sede', $this->h)->assertStatus(422)->assertJsonValidationErrors('by');
    $this->get("/api/v1/aul/admin/reports/progress.xlsx?by=position&course_id={$c}", $this->h)->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="aula-avance-por-cargo.xlsx"');
});

it('desglosa el embudo de recomendaciones por fuente, curso, canal, área y empresa compradora', function () {
    $area = aulrArea($this, 'Área embudo');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Eva', 'paternal_last_name' => 'Embudo', 'current_area_id' => $area], $this->h)->json('data.id');
    [$c, $e] = aulrCourse($this, [$w]);
    $me = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    $ruc = '2059'.random_int(1000000, 9999999);
    aulrq("insert into course_recommendations (company_id, course_id, worker_id, source, recommended_by, target_scope, reason_code, channel, status, sent_at, viewed_at, converted_at, converted_enrollment_id, dedupe_key)
           values (?, ?, ?, 'manual', ?, 'person', 'manual', 'email', 'converted', now(), now(), now(), ?, ?)", [HUAYLAS_ID, $c, $w, $me, $e[$w], 'aulf:'.$c]);
    aulrq("insert into course_recommendations (company_id, course_id, buyer_company_ruc, buyer_company_name, source, recommended_by, target_scope, reason_code, channel, status, sent_at, dismissed_at, dedupe_key)
           values (?, ?, ?, 'Embudo SAC', 'manual', ?, 'buyer_company', 'manual', 'whatsapp', 'dismissed', now(), now(), ?)", [HUAYLAS_ID, $c, $ruc, $me, 'aulf-b:'.$c]);

    $b = $this->getJson('/api/v1/aul/admin/recommendations', $this->h)->assertOk()->json('data.breakdown');
    expect(array_keys($b))->toBe(['source', 'rule', 'course', 'channel', 'area', 'buyer_company']);
    $course = collect($b['course'])->firstWhere('key', $c);
    expect($course['total'])->toBe(2)->and($course['sent'])->toBe(2)->and($course['converted'])->toBe(1)->and($course['enrolled'])->toBe(1)
        ->and($course['dismissed'])->toBe(1)->and($course['conversion_pct'])->toEqual(50);
    expect(collect($b['area'])->firstWhere('key', $area)['converted'])->toBe(1)
        ->and(collect($b['buyer_company'])->firstWhere('key', $ruc)['label'])->toBe('Embudo SAC')
        ->and(collect($b['channel'])->firstWhere('key', 'whatsapp')['label'])->toBe('WhatsApp')
        ->and(collect($b['source'])->firstWhere('key', 'manual')['label'])->toBe('Manual');
    $this->get('/api/v1/aul/admin/reports/conversion.xlsx', $this->h)->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="aula-conversion-recomendaciones.xlsx"');
});
