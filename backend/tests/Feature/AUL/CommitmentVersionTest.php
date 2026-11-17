<?php

// M-AUL: compromiso obligatorio antes de ver el contenido (RN-AUL-16), lección vista validada en el servidor (RN-AUL-04/09),
// curso publicado con matriculados que no cambia y nueva versión (RN-AUL-17) y activación del aula y la venta (RN-AUL-21).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode, source, company_enabled from feature_flags where company_id = ? and module_code in ('AUL', 'VTA')", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'AUL'", [HUAYLAS_ID]);
    Permissions::flush();
});

afterEach(function () {
    $db = DB::connection('platform');
    foreach ($this->flags as $f) {
        $db->update('update feature_flags set mode = ?, source = ?, company_enabled = ? where id = ?', [$f->mode, $f->source, $f->company_enabled ? 'true' : 'false', $f->id]);
    }
    app('auth')->forgetGuards();
    Permissions::flush();
});

it('pide el compromiso antes del contenido, valida que la lección se vio y versiona el curso publicado', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $c = $this->postJson('/api/v1/aul/admin/courses', ['title' => 'Curso con compromiso', 'hours' => 2, 'passing_score' => 70, 'completion_commitment' => 'mandatory',
        'commitment_text' => 'Me comprometo a terminar el curso.'], $h)->assertCreated()->json('data.id');
    $t = $this->postJson("/api/v1/aul/admin/courses/{$c}/lessons", ['lesson_type' => 'text', 'title' => 'Lectura', 'content_html' => '<p>Contenido</p>'], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/aul/admin/courses/{$c}/publish", ['publish' => true], $h)->assertOk();
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Comp', 'paternal_last_name' => 'Prueba'], $h)->json('data.id');
    $this->postJson("/api/v1/aul/admin/courses/{$c}/enroll", ['worker_ids' => [$w]], $h)->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $e = DB::selectOne('select id from course_enrollments where course_id = ? and worker_id = ?', [$c, $w])->id;
    DB::select("select set_config('app.company_id', '', false)");

    $show = $this->getJson("/api/v1/aul/enrollments/{$e}", $h)->assertOk()->json('data');
    expect($show['lessons'])->toBe([])->and($show['commitment']['required'])->toBeTrue();
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$t}/done", [], $h)->assertStatus(409)->assertJsonPath('type', 'commitment_required');
    $this->postJson("/api/v1/aul/enrollments/{$e}/commitment", [], $h)->assertCreated();
    $this->postJson("/api/v1/aul/enrollments/{$e}/commitment", [], $h)->assertStatus(409);
    expect($this->getJson("/api/v1/aul/enrollments/{$e}", $h)->json('data.lessons'))->toHaveCount(1);

    // Sin permanencia suficiente no se da por vista.
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$t}/done", [], $h)->assertStatus(409)->assertJsonPath('type', 'not_seen');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into learning_activity_sessions (company_id, enrollment_id, lesson_id, client_session_id, started_at, last_heartbeat_at, active_seconds) values (?, ?, ?, 'cs-1', now(), now(), 120)", [HUAYLAS_ID, $e, $t]);
    DB::select("select set_config('app.company_id', '', false)");
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$t}/done", [], $h)->assertOk();

    // Publicado con matriculados: la nota mínima no cambia; se crea una versión nueva.
    $this->patchJson("/api/v1/records/AUL/courses/{$c}", ['passing_score' => 80], $h)->assertStatus(409);
    $v2 = $this->postJson("/api/v1/aul/admin/courses/{$c}/new-version", [], $h)->assertCreated()->json('data');
    expect($v2['version_number'])->toBe(2);
    $this->postJson("/api/v1/aul/admin/courses/{$c}/new-version", [], $h)->assertStatus(409);
    $this->patchJson("/api/v1/records/AUL/courses/{$v2['id']}", ['passing_score' => 80], $h)->assertOk();
    $this->postJson("/api/v1/aul/admin/courses/{$v2['id']}/publish", ['publish' => true], $h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select status from courses where id = ?', [$c])->status)->toBe('archived')
        ->and(DB::selectOne('select count(*) as n from course_lessons where course_id = ?', [$v2['id']])->n)->toBe(1);
    DB::select("select set_config('app.company_id', '', false)");
});

it('la venta de cursos exige el aula activa y el aula no se apaga con la venta activa', function () {
    // La venta incluida en el plan pero apagada por la empresa.
    DB::connection('platform')->update("update feature_flags set mode = 'disabled_by_company', company_enabled = false where company_id = ? and module_code = 'VTA'", [HUAYLAS_ID]);
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->getJson('/api/v1/aul/settings', $h)->assertOk()->assertJsonPath('data.vta.in_plan', true);
    $this->putJson('/api/v1/aul/settings', ['aul' => true, 'vta' => true], $h)->assertOk()->assertJsonPath('data.vta.company_enabled', true);
    $this->putJson('/api/v1/aul/settings', ['aul' => false], $h)->assertStatus(409);
    $this->putJson('/api/v1/aul/settings', ['vta' => false], $h)->assertOk();
    $this->putJson('/api/v1/aul/settings', ['aul' => false], $h)->assertOk()->assertJsonPath('data.aul.company_enabled', false);
});
