<?php

// Moodle opcional (M-AUL SCR-AUL-19, docs/09 §11): la empresa guarda la dirección y el token (cifrado, nunca vuelve),
// prueba la conexión, enlaza un curso con su id en Moodle, envía matrículas (busca o crea a la persona por correo)
// sin duplicar y trae las notas. Tras varias fallas seguidas la conexión pasa a «error» y se avisa. Http::fake: nada sale.

use App\Core\Auth\Permissions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode, source from feature_flags where company_id = ? and module_code = 'AUL'", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'AUL'", [HUAYLAS_ID]);
    Permissions::flush();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    foreach ($this->flags as $f) {
        $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
    }
    Permissions::flush();
});

function moodleHdrs(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

/** Respuestas de Moodle según la función pedida. */
function moodleFake(array $overrides = []): void
{
    Http::fake(['moodle.example.com/*' => function (Request $r) use ($overrides) {
        $fn = $r['wsfunction'] ?? '';
        if (isset($overrides[$fn])) {
            return Http::response($overrides[$fn], 200);
        }

        return Http::response(match ($fn) {
            'core_webservice_get_site_info' => ['sitename' => 'Moodle Huaylas', 'release' => '4.4', 'functions' => [['name' => 'core_user_get_users_by_field'],
                ['name' => 'core_user_create_users'], ['name' => 'enrol_manual_enrol_users'], ['name' => 'gradereport_user_get_grade_items']]],
            'core_user_get_users_by_field' => [],
            'core_user_create_users' => [['id' => 77, 'username' => 'x']],
            'enrol_manual_enrol_users' => null,
            'gradereport_user_get_grade_items' => ['usergrades' => [['gradeitems' => [['itemtype' => 'mod', 'graderaw' => 5], ['itemtype' => 'course', 'graderaw' => 16, 'grademin' => 0, 'grademax' => 20, 'gradeformatted' => '16,00']]]]],
            default => ['exception' => 'moodle_exception', 'errorcode' => 'invalidfunction', 'message' => 'Función no válida'],
        }, 200);
    }]);
}

it('guarda la conexión sin devolver el token y la prueba con core_webservice_get_site_info', function () {
    moodleFake();
    $h = moodleHdrs();
    $this->getJson('/api/v1/aul/admin/moodle', $h)->assertOk()->assertJsonPath('data.connection', null);
    $this->putJson('/api/v1/aul/admin/moodle', ['base_url' => 'http://moodle.example.com', 'token' => 'abc'], $h)->assertStatus(422)->assertJsonValidationErrors('base_url');
    $this->putJson('/api/v1/aul/admin/moodle', ['base_url' => 'https://moodle.example.com/'], $h)->assertStatus(422)->assertJsonValidationErrors('token');
    $r = $this->putJson('/api/v1/aul/admin/moodle', ['base_url' => 'https://moodle.example.com/', 'token' => 'tokenmoodle-secreto-4321', 'sync_enrollments' => true, 'sync_grades' => true], $h)->assertOk();
    expect($r->json('data.connection.token_set'))->toBe('configurado · termina en 4321')->and(json_encode($r->json()))->not->toContain('tokenmoodle-secreto')
        ->and($r->json('data.connection.base_url'))->toBe('https://moodle.example.com');
    $row = DB::selectOne('select token_enc, credentials_secret_ref from lms_connections where company_id = ?', [HUAYLAS_ID]);
    expect(Crypt::decryptString($row->token_enc))->toBe('tokenmoodle-secreto-4321')->and($row->credentials_secret_ref)->toStartWith('app-encrypted://');

    $t = $this->postJson('/api/v1/aul/admin/moodle/test', [], $h)->assertOk()->json('data');
    expect($t['ok'])->toBeTrue()->and($t['message'])->toContain('Moodle Huaylas')->and($t['missing'])->toBe([]);
    Http::assertSent(fn (Request $q) => $q->url() === 'https://moodle.example.com/webservice/rest/server.php' && $q['wsfunction'] === 'core_webservice_get_site_info'
        && $q['wstoken'] === 'tokenmoodle-secreto-4321' && $q['moodlewsrestformat'] === 'json');
    $this->getJson('/api/v1/aul/admin/moodle', $h)->assertJsonPath('data.connection.status', 'active')->assertJsonPath('data.connection.site_name', 'Moodle Huaylas');

    // Un token vacío conserva el anterior.
    $this->putJson('/api/v1/aul/admin/moodle', ['base_url' => 'https://moodle.example.com', 'token' => '', 'sync_grades' => false], $h)->assertOk()
        ->assertJsonPath('data.connection.token_set', 'configurado · termina en 4321')->assertJsonPath('data.connection.sync_grades', false);
});

it('enlaza un curso, envía las matrículas sin duplicar y trae la nota final en porcentaje', function () {
    moodleFake();
    $h = moodleHdrs();
    $this->putJson('/api/v1/aul/admin/moodle', ['base_url' => 'https://moodle.example.com', 'token' => 'tok-1111'], $h)->assertOk();
    $course = $this->postJson('/api/v1/aul/admin/courses', ['title' => 'Altura en Moodle', 'hours' => 2], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/aul/admin/moodle/courses/{$course}/push", [], $h)->assertStatus(422)->assertJsonValidationErrors('external_course_id');
    $this->putJson("/api/v1/aul/admin/moodle/courses/{$course}", ['external_course_id' => 'abc'], $h)->assertStatus(422);
    $this->putJson("/api/v1/aul/admin/moodle/courses/{$course}", ['external_course_id' => '12'], $h)->assertOk()->assertJsonPath('data.external_course_id', '12');

    $doc = (string) random_int(40000000, 79999999);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $doc, 'first_name' => 'Rosa', 'paternal_last_name' => 'Moodle', 'personal_email' => 'rosa.moodle@example.com'], $h)
        ->assertCreated()->json('data.id');
    $sin = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) ((int) $doc + 1), 'first_name' => 'Sin', 'paternal_last_name' => 'Correo'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    foreach ([$w, $sin] as $x) {
        DB::insert("insert into course_enrollments (company_id, course_id, worker_id, source, status) values (?, ?, ?, 'manual', 'enrolled')", [HUAYLAS_ID, $course, $x]);
    }

    $p = $this->postJson("/api/v1/aul/admin/moodle/courses/{$course}/push", [], $h)->assertOk()->json('data');
    expect($p)->toBe(['sent' => 1, 'skipped_without_email' => 1, 'errors' => []]);
    Http::assertSent(fn (Request $q) => $q['wsfunction'] === 'core_user_create_users' && $q['users'][0]['email'] === 'rosa.moodle@example.com' && $q['users'][0]['firstname'] === 'Rosa');
    Http::assertSent(fn (Request $q) => $q['wsfunction'] === 'enrol_manual_enrol_users' && (int) $q['enrolments'][0]['userid'] === 77 && (int) $q['enrolments'][0]['courseid'] === 12
        && (int) $q['enrolments'][0]['roleid'] === 5);
    expect(DB::selectOne('select external_enrollment_id from course_enrollments where worker_id = ?', [$w])->external_enrollment_id)->toBe('77:12');
    // Solo nombre y correo: nunca el documento.
    Http::assertNotSent(fn (Request $q) => str_contains($q->body(), $doc));

    $this->postJson("/api/v1/aul/admin/moodle/courses/{$course}/push", [], $h)->assertOk()->assertJsonPath('data.sent', 0);   // no duplica

    $g = $this->postJson("/api/v1/aul/admin/moodle/courses/{$course}/pull", [], $h)->assertOk()->json('data');
    expect($g['updated'])->toBe(1);
    $e = DB::selectOne('select final_score, final_score_label from course_enrollments where worker_id = ?', [$w]);
    expect((float) $e->final_score)->toBe(80.0)->and($e->final_score_label)->toBe('16,00');
    $m = $this->getJson('/api/v1/aul/admin/moodle', $h)->json('data');
    expect(collect($m['courses'])->firstWhere('id', $course)['synced'])->toBe(1)->and($m['connection']['last_sync_status'])->toBe('ok');
});

it('tras fallas seguidas la conexión queda en error y avisa al administrador', function () {
    moodleFake(['core_user_get_users_by_field' => ['exception' => 'webservice_access_exception', 'errorcode' => 'accessexception', 'message' => 'Access control exception']]);
    $h = moodleHdrs();
    $this->putJson('/api/v1/aul/admin/moodle', ['base_url' => 'https://moodle.example.com', 'token' => 'tok-2222'], $h)->assertOk();
    $course = $this->postJson('/api/v1/aul/admin/courses', ['title' => 'Falla Moodle', 'hours' => 1], $h)->assertCreated()->json('data.id');
    $this->putJson("/api/v1/aul/admin/moodle/courses/{$course}", ['external_course_id' => '9'], $h)->assertOk();
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Tito', 'paternal_last_name' => 'Falla',
        'personal_email' => 'tito.falla@example.com'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into course_enrollments (company_id, course_id, worker_id, source, status) values (?, ?, ?, 'manual', 'enrolled')", [HUAYLAS_ID, $course, $w]);

    foreach ([1, 2, 3] as $i) {
        $r = $this->postJson("/api/v1/aul/admin/moodle/courses/{$course}/push", [], $h)->assertOk()->json('data');
        expect($r['errors'][0])->toContain('Access control exception')->and($r['errors'][0])->not->toContain('tok-2222');
    }
    $this->getJson('/api/v1/aul/admin/moodle', $h)->assertJsonPath('data.connection.status', 'error')->assertJsonPath('data.connection.last_sync_status', 'failed');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where event_code = 'aul.lms_sync_failed'")->n)->toBeGreaterThan(0);
});

it('sin el aula en el plan no se puede configurar', function () {
    $h = authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID);
    $code = $this->putJson('/api/v1/aul/admin/moodle', ['base_url' => 'https://moodle.example.com', 'token' => 'x'], $h)->status();
    expect($code)->toBeIn([403, 404]);
});
