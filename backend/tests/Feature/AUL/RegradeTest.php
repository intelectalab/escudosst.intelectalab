<?php

// Aula virtual (M-AUL): «Modificar nota» de un intento ya calificado (motivo obligatorio, bitácora e historial con la
// nota anterior; recalcula el avance y anula el certificado si el curso deja de estar aprobado) y aviso al capacitador
// cuando un participante envía una evaluación práctica o con preguntas abiertas (M-AUL §10).

use App\Core\Auth\Permissions;
use App\Core\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

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

function aulrgH(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

/** Curso publicado con evaluación práctica de 2 preguntas (la nota la pone quien califica) y un trabajador matriculado. */
function aulrgCourse($t, array $h, array $courseExtra = []): array
{
    $c = $t->postJson('/api/v1/aul/admin/courses', ['title' => 'Curso nota '.uniqid(), 'hours' => 1, 'passing_score' => 70, 'max_attempts' => 3] + $courseExtra, $h)->assertCreated()->json('data.id');
    $exam = $t->postJson("/api/v1/aul/admin/courses/{$c}/lessons", ['lesson_type' => 'exam', 'title' => 'Práctica de bloqueo', 'num_questions' => 2], $h)->assertCreated()->json('data');
    $head = ['Código', 'Tipo', 'Enunciado', 'Alternativa A', 'Alternativa B', 'Alternativa C', 'Alternativa D', 'Alternativa E', 'Alternativa F', 'Correcta', 'Dificultad', 'Puntos',
        'Retroalimentación si acierta', 'Retroalimentación si falla'];
    $rows = array_map(fn ($i) => ["RG-{$i}", 'Opción única', "Paso {$i}", 'Bien', 'Mal', '', '', '', '', 'A', 'Media', 1, '', ''], [1, 2]);
    $file = UploadedFile::fake()->createWithContent('preguntas.xlsx', Xlsx::build('Preguntas', [['EscudoSST · Plantilla de preguntas del aula · versión AUL-Q1'], ['i'], ['i'], ['Banco'], $head, ...$rows], 5));
    $t->post("/api/v1/aul/admin/banks/{$exam['question_bank_id']}/import", ['file' => $file], $h)->assertOk();
    $t->putJson("/api/v1/aul/admin/lessons/{$exam['id']}/exam", ['question_bank_id' => $exam['question_bank_id'], 'num_questions' => 2, 'manual_grading' => true, 'max_attempts' => 3], $h)->assertOk();
    $t->postJson("/api/v1/aul/admin/courses/{$c}/publish", ['publish' => true], $h)->assertOk();
    $w = $t->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Rosa', 'paternal_last_name' => 'Nota'], $h)->assertCreated()->json('data.id');
    $t->postJson("/api/v1/aul/admin/courses/{$c}/enroll", ['worker_ids' => [$w]], $h)->assertCreated();
    $enr = DB::selectOne('select id from course_enrollments where course_id = ? and worker_id = ?', [$c, $w])->id;

    return ['course' => $c, 'exam' => $exam['id'], 'enrollment' => $enr];
}

/** El participante rinde y envía la evaluación práctica: queda por calificar. */
function aulrgSubmit($t, array $h, array $x): string
{
    $a = $t->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/attempts", [], $h)->assertSuccessful()->json('data');
    $t->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/exam", ['attempt_id' => $a['id'], 'answers' => []], $h)->assertOk()->assertJsonPath('data.pending', true);

    return $a['id'];
}

/** Un segundo usuario (el del plan Profesional) como capacitador de la empresa de prueba. */
function aulrgTrainer(?string $course, bool $canGrade): string
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    $role = DB::selectOne("select id from roles where account_id is null and code = 'trainer'")->id;
    if (! DB::selectOne('select 1 as ok from memberships where company_id = ? and user_id = ? and deleted_at is null', [HUAYLAS_ID, $uid])) {
        DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);
    }
    if ($course) {
        DB::insert('insert into trainer_permissions (company_id, trainer_user_id, course_id, can_grade, can_edit_content) values (?, ?, ?, ?, true)', [HUAYLAS_ID, $uid, $course, $canGrade ? 'true' : 'false']);
    }
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();

    return $uid;
}

it('avisa al capacitador del curso cuando un participante envía una evaluación por calificar', function () {
    $h = aulrgH();
    $x = aulrgCourse($this, $h);
    $owner = DB::selectOne('select created_by from courses where id = ?', [$x['course']])->created_by;

    // Sin capacitador asignado: avisa a quien publicó o creó el curso.
    $a1 = aulrgSubmit($this, $h, $x);
    $n = DB::select("select recipient_user_id, link, title, body from notifications where event_code = 'aul.attempt_pending_grading' and subject_id = ?", [$a1]);
    expect($n)->toHaveCount(1)
        ->and($n[0]->recipient_user_id)->toBe($owner)
        ->and($n[0]->link)->toBe('/m/aul/calificar')
        ->and($n[0]->title)->toBe('Evaluación práctica por calificar')
        ->and($n[0]->body)->toContain('Rosa Nota');

    // Con capacitador del curso que puede calificar: el aviso es para él (uno por intento y destinatario).
    $this->postJson("/api/v1/aul/admin/attempts/{$a1}/grade", ['score_pct' => 30], $h)->assertOk();
    $trainer = aulrgTrainer($x['course'], true);
    $a2 = aulrgSubmit($this, aulrgH(), $x);
    $to = array_column(DB::select("select recipient_user_id from notifications where event_code = 'aul.attempt_pending_grading' and subject_id = ?", [$a2]), 'recipient_user_id');
    expect($to)->toBe([$trainer]);
    expect(DB::selectOne('select dedupe_key from notifications where subject_id = ?', [$a2])->dedupe_key)->toBe("aul.grading.{$a2}.{$trainer}");
});

it('modifica la nota de un intento calificado con motivo, historial y bitácora, y recalcula el avance', function () {
    $h = aulrgH();
    $x = aulrgCourse($this, $h, ['certificate_enabled' => true]);
    $a = aulrgSubmit($this, $h, $x);

    // Aún sin nota: no se «modifica», se califica.
    $this->postJson("/api/v1/aul/admin/attempts/{$a}/regrade", ['score_pct' => 90, 'reason' => 'Corrección'], $h)->assertStatus(409);
    $this->postJson("/api/v1/aul/admin/attempts/{$a}/grade", ['score_pct' => 50], $h)->assertOk()->assertJsonPath('data.passed', false);
    $this->postJson("/api/v1/aul/admin/attempts/{$a}/grade", ['score_pct' => 90], $h)->assertStatus(409);

    // Aparece entre los calificados.
    $graded = $this->getJson('/api/v1/aul/admin/grading?status=graded', $h)->assertOk()->json('data.items');
    $row = collect($graded)->firstWhere('id', $a);
    expect($row)->not->toBeNull()->and((float) $row['score_pct'])->toEqual(50)->and($row['changes'])->toBe(0);
    expect(collect($this->getJson('/api/v1/aul/admin/grading', $h)->json('data.items'))->pluck('id'))->not->toContain($a);

    // El motivo es obligatorio.
    $this->postJson("/api/v1/aul/admin/attempts/{$a}/regrade", ['score_pct' => 85], $h)->assertStatus(422)->assertJsonValidationErrors('reason');

    $r = $this->postJson("/api/v1/aul/admin/attempts/{$a}/regrade", ['score_pct' => 85, 'reason' => 'Se revisó el video de la práctica: el bloqueo fue correcto.'], $h)->assertOk()->json('data');
    expect($r['passed'])->toBeTrue()->and($r['previous_pct'])->toEqual(50)->and($r['score_pct'])->toEqual(85)->and($r['history'])->toHaveCount(1);
    expect($r['history'][0])->toMatchArray(['previous_pct' => 50, 'previous_passed' => false, 'new_pct' => 85, 'new_passed' => true]);

    $q = DB::selectOne('select score, passed, grade_label, answers from quiz_attempts where id = ?', [$a]);
    expect((float) $q->score)->toEqual(1.7)->and($q->passed)->toBeTrue()->and(json_decode($q->answers, true)['history'][0]['reason'])->toContain('bloqueo');
    $log = DB::selectOne("select changes from audit_logs where action = 'quiz_attempts.regrade' and entity_id = ?", [$a]);
    expect(json_decode($log->changes, true))->toMatchArray(['before' => ['score_pct' => 50, 'label' => '50 %', 'passed' => false], 'after' => ['score_pct' => 85, 'label' => '85 %', 'passed' => true]]);
    $e = DB::selectOne('select status, certificate_id from course_enrollments where id = ?', [$x['enrollment']]);
    expect($e->status)->toBe('completed')->and($e->certificate_id)->not->toBeNull();

    // El detalle muestra la nota vigente y el historial.
    $d = $this->getJson("/api/v1/aul/admin/attempts/{$a}", $h)->assertOk()->json('data');
    expect($d['status'])->toBe('graded')->and((float) $d['score_pct'])->toEqual(85)->and($d['history'])->toHaveCount(1);

    // Bajar la nota por debajo del mínimo: el curso deja de estar aprobado y el certificado se anula con el motivo.
    $this->postJson("/api/v1/aul/admin/attempts/{$a}/regrade", ['score_pct' => 40, 'reason' => 'Error de digitación en la nota anterior'], $h)->assertOk()->assertJsonPath('data.passed', false);
    $e2 = DB::selectOne('select status, certificate_id, completed_at from course_enrollments where id = ?', [$x['enrollment']]);
    expect($e2->status)->toBe('in_progress')->and($e2->certificate_id)->toBeNull()->and($e2->completed_at)->toBeNull();
    $cert = DB::selectOne('select status, void_reason from training_certificates where id = ?', [$e->certificate_id]);
    expect($cert->status)->toBe('voided')->and($cert->void_reason)->toContain('Error de digitación');
    expect(json_decode(DB::selectOne('select answers from quiz_attempts where id = ?', [$a])->answers, true)['history'])->toHaveCount(2);
});

it('solo modifica la nota quien puede calificar en ese curso', function () {
    $h = aulrgH();
    $x = aulrgCourse($this, $h);
    $a = aulrgSubmit($this, $h, $x);
    $this->postJson("/api/v1/aul/admin/attempts/{$a}/grade", ['score_pct' => 60], $h)->assertOk();

    aulrgTrainer($x['course'], false);
    $th = authHeaders(tokenFor(PROFESIONAL_EMAIL), HUAYLAS_ID);
    $this->postJson("/api/v1/aul/admin/attempts/{$a}/regrade", ['score_pct' => 90, 'reason' => 'Revisión del capacitador'], $th)->assertForbidden();
    expect(collect($this->getJson('/api/v1/aul/admin/grading?status=graded', $th)->assertOk()->json('data.items'))->pluck('id'))->not->toContain($a);

    DB::update('update trainer_permissions set can_grade = true where course_id = ?', [$x['course']]);
    $this->postJson("/api/v1/aul/admin/attempts/{$a}/regrade", ['score_pct' => 90, 'reason' => 'Revisión del capacitador'], $th)->assertOk()->assertJsonPath('data.passed', true);
});
