<?php

// M-AUL: recorrido secuencial y regla de apertura de cada sección (RN-AUL-05), tipos de regla de recomendación por hallazgo o
// evento del área (RN-AUL-24), atribución de la matrícula a la recomendación vista (RN-AUL-33) y límite global de envíos (RN-AUL-28).

use App\Core\Auth\Permissions;
use App\Modules\AUL\RecommendationEngine;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode from feature_flags where company_id = ? and module_code = 'AUL'", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'AUL'", [HUAYLAS_ID]);
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

afterEach(function () {
    foreach ($this->flags as $f) {
        DB::connection('platform')->update('update feature_flags set mode = ? where id = ?', [$f->mode, $f->id]);
    }
    app('auth')->forgetGuards();
    Permissions::flush();
});

function aurAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } catch (\Throwable $e) {
        fwrite(STDERR, 'AUR: '.substr($e->getMessage(), 0, 300)."
");
        throw $e;
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

function aurWorker($t, array $h, ?string $area = null): string
{
    return $t->postJson('/api/v1/records/TRB/workers', array_filter(['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Aula', 'paternal_last_name' => 'Prueba',
        'current_area_id' => $area]), $h)->assertCreated()->json('data.id');
}

it('bloquea las lecciones según el recorrido secuencial y la regla de cada sección', function () {
    $h = $this->h;
    $c = $this->postJson('/api/v1/aul/admin/courses', ['title' => 'Curso por secciones', 'hours' => 2, 'passing_score' => 70, 'sequential' => true], $h)->assertCreated()->json('data.id');
    $s1 = $this->postJson("/api/v1/aul/admin/courses/{$c}/sections", ['title' => 'Unidad 1'], $h)->assertCreated()->json('data.id');
    $s2 = $this->postJson("/api/v1/aul/admin/courses/{$c}/sections", ['title' => 'Unidad 2', 'unlock_rule' => 'after_previous'], $h)->assertCreated()->json('data.id');
    $s3 = $this->postJson("/api/v1/aul/admin/courses/{$c}/sections", ['title' => 'Unidad 3', 'unlock_rule' => 'from_date', 'unlock_on' => now()->addDays(10)->format('Y-m-d')], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/aul/admin/courses/{$c}/sections", ['title' => 'Sin fecha', 'unlock_rule' => 'from_date'], $h)->assertStatus(422);
    $mk = fn ($s, $t) => $this->postJson("/api/v1/aul/admin/courses/{$c}/lessons", ['section_id' => $s, 'lesson_type' => 'text', 'title' => $t, 'content_html' => '<p>x</p>'], $h)->assertCreated()->json('data.id');
    [$a, $b, $x, $z] = [$mk($s1, 'Lección A'), $mk($s1, 'Lección B'), $mk($s2, 'Lección X'), $mk($s3, 'Lección Z')];
    $this->postJson("/api/v1/aul/admin/courses/{$c}/publish", ['publish' => true], $h)->assertOk();
    $w = aurWorker($this, $h);
    $this->postJson("/api/v1/aul/admin/courses/{$c}/enroll", ['worker_ids' => [$w]], $h)->assertCreated();
    $e = aurAs(fn () => DB::selectOne('select id from course_enrollments where course_id = ? and worker_id = ?', [$c, $w])->id);

    $locks = fn () => collect($this->getJson("/api/v1/aul/enrollments/{$e}", $h)->assertOk()->json('data.lessons'))->pluck('locked', 'id')->all();
    expect($locks())->toMatchArray([$a => false, $b => true, $x => true, $z => true]);
    // B no se puede completar antes que A, aunque tenga permanencia.
    aurAs(function () use ($e, $a, $b) {
        foreach ([$a, $b] as $i => $l) {
            DB::insert("insert into learning_activity_sessions (company_id, enrollment_id, lesson_id, client_session_id, started_at, last_heartbeat_at, active_seconds) values (?, ?, ?, ?, now(), now(), 120)",
                [HUAYLAS_ID, $e, $l, 'cs-'.$i]);
        }
    });
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$b}/done", [], $h)->assertStatus(409)->assertJsonPath('type', 'locked');
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$a}/done", [], $h)->assertOk();
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$b}/done", [], $h)->assertOk();
    // Completa la unidad 1: se abre la 2; la 3 sigue cerrada por su fecha.
    expect($locks())->toMatchArray([$x => false, $z => true]);
});

it('recomienda los cursos de la regla a los trabajadores del área con un hallazgo reciente', function () {
    $h = $this->h;
    [$area, $course] = aurAs(function () {
        $site = DB::selectOne("select id from sites where deleted_at is null order by created_at limit 1")->id;
        $area = DB::selectOne("insert into areas (company_id, site_id, code, name) values (?, ?, ?, 'Taller de prueba') returning id", [HUAYLAS_ID, $site, 'TP'.random_int(1000, 99999)])->id;
        $course = DB::selectOne("insert into courses (company_id, code, title, status, hours, published_at) values (?, ?, 'Orden y limpieza', 'published', 1, now()) returning id", [HUAYLAS_ID, 'OL'.random_int(1000, 99999)])->id;
        DB::insert("insert into findings (company_id, source_type, finding_type, description, severity, site_id, area_id, status) values (?, 'inspection', 'unsafe_condition', 'Cables en el piso', 'high', ?, ?, 'open')",
            [HUAYLAS_ID, $site, $area]);

        return [$area, $course];
    });
    $w = aurWorker($this, $h, $area);
    $rule = aurAs(fn () => DB::selectOne("insert into recommendation_rules (company_id, code, name, rule_type, audience, course_ids, channels, max_sends_per_person, send_period_days, is_active, parameters)
                                          values (?, ?, 'Por hallazgo', 'inspection_finding', 'workers', ?, '{in_app}', 1, 30, true, '{\"lookback_days\": 30}') returning *", [HUAYLAS_ID, 'RH'.random_int(1000, 99999), '{'.$course.'}']));
    $res = app(AccountsService::class)->withCompany(HUAYLAS_ID, fn ($db) => app(RecommendationEngine::class)->runRule($db, HUAYLAS_ID, $rule, false));
    expect($res['supported'])->toBeTrue()->and($res['created'])->toBeGreaterThanOrEqual(1);
    $rec = aurAs(fn () => DB::selectOne('select message, reason_code from course_recommendations where worker_id = ? and course_id = ?', [$w, $course]));
    expect($rec->reason_code)->toBe('inspection_finding')->and($rec->message)->toContain('Taller de prueba')->not->toContain('Cables');
    // Al día siguiente no se repite.
    $again = app(AccountsService::class)->withCompany(HUAYLAS_ID, fn ($db) => app(RecommendationEngine::class)->runRule($db, HUAYLAS_ID, $rule, false));
    expect(aurAs(fn () => (int) DB::selectOne('select count(*) as n from course_recommendations where worker_id = ? and course_id = ?', [$w, $course])->n))->toBe(1);
});

it('atribuye la matrícula a la recomendación vista y respeta el límite global de envíos', function () {
    $h = $this->h;
    $w = aurWorker($this, $h);
    $course = aurAs(fn () => DB::selectOne("insert into courses (company_id, code, title, status, hours, published_at) values (?, ?, 'Primeros auxilios', 'published', 1, now()) returning id", [HUAYLAS_ID, 'PA'.random_int(1000, 99999)])->id);
    $me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    [$seen, $old] = aurAs(fn () => [
        DB::selectOne("insert into course_recommendations (company_id, course_id, worker_id, source, recommended_by, target_scope, reason_code, message, channel, status, sent_at, viewed_at, dedupe_key)
                       values (?, ?, ?, 'manual', ?, 'person', 'manual', 'Te lo recomiendo', 'in_app', 'viewed', now() - interval '2 days', now() - interval '1 day', ?) returning id", [HUAYLAS_ID, $course, $w, $me, 'k1-'.$w])->id,
        DB::selectOne("insert into course_recommendations (company_id, course_id, worker_id, source, recommended_by, target_scope, reason_code, message, channel, status, sent_at, dedupe_key)
                       values (?, ?, ?, 'manual', ?, 'person', 'manual', 'Sin abrir', 'in_app', 'sent', now() - interval '3 days', ?) returning id", [HUAYLAS_ID, $course, $w, $me, 'k2-'.$w])->id,
    ]);
    $e = aurAs(fn () => DB::selectOne("insert into course_enrollments (company_id, course_id, worker_id, source, status, enrolled_at) values (?, ?, ?, 'manual', 'enrolled', now()) returning id", [HUAYLAS_ID, $course, $w])->id);
    $r = aurAs(fn () => DB::selectOne('select status, converted_enrollment_id from course_recommendations where id = ?', [$seen]));
    expect($r->status)->toBe('converted')->and($r->converted_enrollment_id)->toBe($e)
        ->and(aurAs(fn () => DB::selectOne('select status from course_recommendations where id = ?', [$old])->status))->toBe('sent');

    // Límite global: con el parámetro en 0, los envíos por correo pasan a solo portal.
    aurAs(fn () => DB::insert("insert into legal_parameters (company_id, key, value, value_type, description, valid_from) values (?, 'recommendation_max_messages_per_period', '0', 'integer', 'Prueba del límite global', current_date)", [HUAYLAS_ID]));
    $c2 = aurAs(fn () => DB::selectOne("insert into courses (company_id, code, title, status, hours, published_at) values (?, ?, 'Curso por ruta', 'published', 1, now()) returning id", [HUAYLAS_ID, 'CR'.random_int(1000, 99999)])->id);
    aurAs(fn () => DB::update("update course_enrollments set status = 'completed', completed_at = now() where id = ?", [$e]));
    $rule = aurAs(fn () => DB::selectOne("insert into recommendation_rules (company_id, code, name, rule_type, audience, course_ids, channels, max_sends_per_person, send_period_days, is_active, parameters, requires_consent)
                                          values (?, ?, 'Ruta', 'learning_path_next', 'workers', ?, '{email}', 5, 30, true, ?, false) returning *",
        [HUAYLAS_ID, 'RR'.random_int(1000, 99999), '{'.$c2.'}', json_encode(['after_course_ids' => [$course]])]));
    $res = app(AccountsService::class)->withCompany(HUAYLAS_ID, fn ($db) => app(RecommendationEngine::class)->runRule($db, HUAYLAS_ID, $rule, false));
    expect($res['created'])->toBeGreaterThanOrEqual(1)->and($res['sent_external'])->toBe(0)->and($res['portal_only'])->toBeGreaterThanOrEqual(1);
});
