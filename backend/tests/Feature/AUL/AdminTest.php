<?php

// Aula virtual (M-AUL): administración del curso — nuevo curso, secciones y lecciones (texto, video, enlace,
// evaluación), orden, publicación con validaciones, matrícula por trabajadores o puesto, libro de notas,
// bancos, disponibilidad del capacitador y recomendaciones manuales.

use App\Core\Auth\Permissions;
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

function aulAdmin(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

function aulWorker($t, array $h, string $name): string
{
    $doc = (string) random_int(40000000, 79999999);

    return $t->postJson('/api/v1/records/TRB/workers', ['document_number' => $doc, 'first_name' => $name, 'paternal_last_name' => 'Prueba'], $h)->assertCreated()->json('data.id');
}

it('crea un curso, arma su contenido, lo publica y matricula', function () {
    $h = aulAdmin();
    $c = $this->postJson('/api/v1/aul/admin/courses', ['title' => 'Trabajos en caliente', 'category' => 'Alto riesgo', 'hours' => 4, 'passing_score' => 70, 'max_attempts' => 3, 'sequential' => true], $h)
        ->assertCreated()->json('data');
    expect($c['code'])->toStartWith('CUR-');
    $id = $c['id'];
    $this->postJson('/api/v1/aul/admin/courses', ['title' => 'Sin compromiso', 'hours' => 2, 'completion_commitment' => 'mandatory'], $h)->assertStatus(422);

    $s1 = $this->postJson("/api/v1/aul/admin/courses/{$id}/sections", ['title' => 'Antes de empezar'], $h)->assertCreated()->json('data.id');
    $s2 = $this->postJson("/api/v1/aul/admin/courses/{$id}/sections", ['title' => 'Evaluación'], $h)->assertCreated()->json('data.id');
    $t = $this->postJson("/api/v1/aul/admin/courses/{$id}/lessons", ['section_id' => $s1, 'lesson_type' => 'text', 'title' => 'Bienvenida', 'content_html' => '<p>Hola</p>'], $h)->assertCreated()->json('data.id');
    $v = $this->postJson("/api/v1/aul/admin/courses/{$id}/lessons", ['section_id' => $s1, 'lesson_type' => 'video', 'title' => 'Qué es', 'video_url' => 'https://vimeo.com/123456789'], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/aul/admin/courses/{$id}/lessons", ['section_id' => $s1, 'lesson_type' => 'video', 'title' => 'Malo', 'video_url' => 'https://youtube.com/x'], $h)->assertStatus(422);
    $this->postJson("/api/v1/aul/admin/courses/{$id}/lessons", ['section_id' => $s1, 'lesson_type' => 'link', 'title' => 'Hoja', 'external_url' => 'http://inseguro'], $h)->assertStatus(422);
    $e = $this->postJson("/api/v1/aul/admin/courses/{$id}/lessons", ['section_id' => $s2, 'lesson_type' => 'exam', 'title' => 'Evaluación final', 'num_questions' => 1], $h)->assertCreated()->json('data');
    expect(DB::selectOne('select video_external_id from course_lessons where id = ?', [$v])->video_external_id)->toBe('123456789')
        ->and($e['question_bank_id'])->not->toBeNull();

    // Orden: el video antes que el texto.
    $this->putJson("/api/v1/aul/admin/courses/{$id}/order", ['sections' => [$s1, $s2], 'lessons' => [['id' => $v, 'section_id' => $s1], ['id' => $t, 'section_id' => $s1], ['id' => $e['id'], 'section_id' => $s2]]], $h)->assertOk();
    $detail = $this->getJson("/api/v1/aul/admin/courses/{$id}", $h)->assertOk()->json('data');
    expect($detail['lessons'][0]['id'])->toBe($v)->and($detail['sections'])->toHaveCount(2)->and(collect($detail['banks'])->pluck('id'))->toContain($e['question_bank_id']);

    // No publica: la evaluación no tiene preguntas.
    $this->postJson("/api/v1/aul/admin/courses/{$id}/publish", ['publish' => true], $h)->assertStatus(422);
    $q = $this->postJson('/api/v1/records/AUL/questions', ['question_bank_id' => $e['question_bank_id'], 'code' => 'P1', 'question_type' => 'single_choice', 'statement' => 'Quién vigila', 'points' => 1, 'status' => 'active'], $h)
        ->assertCreated()->json('data.id');
    $this->putJson("/api/v1/aul/questions/{$q}/options", ['options' => [['text' => 'El vigía', 'correct' => true], ['text' => 'Nadie', 'correct' => false]]], $h)->assertOk();
    $this->postJson("/api/v1/aul/admin/courses/{$id}/enroll", ['worker_ids' => [aulWorker($this, $h, 'Ana')]], $h)->assertStatus(409);
    $this->postJson("/api/v1/aul/admin/courses/{$id}/publish", ['publish' => true], $h)->assertOk()->assertJsonPath('data.status', 'published');

    $w1 = aulWorker($this, $h, 'Beto');
    $w2 = aulWorker($this, $h, 'Carla');
    $dry = $this->postJson("/api/v1/aul/admin/courses/{$id}/enroll", ['worker_ids' => [$w1, $w2], 'dry_run' => true], $h)->assertOk()->json('data');
    expect($dry['to_enroll'])->toBe(2);
    $this->postJson("/api/v1/aul/admin/courses/{$id}/enroll", ['worker_ids' => [$w1, $w2], 'due_on' => now()->addDays(10)->toDateString()], $h)->assertCreated()->assertJsonPath('data.enrolled', 2);
    $this->postJson("/api/v1/aul/admin/courses/{$id}/enroll", ['worker_ids' => [$w1]], $h)->assertCreated()->assertJsonPath('data.enrolled', 0);
    $parts = $this->getJson("/api/v1/aul/admin/courses/{$id}/participants", $h)->assertOk()->json('data');
    expect($parts)->toHaveCount(2);

    // Con matriculados no vuelve a borrador.
    $this->postJson("/api/v1/aul/admin/courses/{$id}/publish", ['publish' => false], $h)->assertStatus(409);

    // El participante rinde y aparece en el libro de notas.
    $enr = collect($parts)->firstWhere('worker_id', $w1)['id'];
    $this->postJson("/api/v1/aul/enrollments/{$enr}/lessons/{$e['id']}/exam", ['answers' => [$q => '0']], $h)->assertOk()->assertJsonPath('data.passed', true);
    $g = $this->getJson("/api/v1/aul/admin/courses/{$id}/grades", $h)->assertOk()->json('data');
    expect($g['exams'])->toHaveCount(1)->and(collect($g['rows'])->firstWhere('enrollment_id', $enr)['exams'][$e['id']]['best_pct'])->toEqual(100)->and($g['attempts'])->toHaveCount(1);

    $ov = $this->getJson('/api/v1/aul/admin/overview', $h)->assertOk()->json('data');
    $card = collect($ov['courses'])->firstWhere('id', $id);
    expect($card['enrolled'])->toBe(2)->and($ov['kpis']['published'])->toBeGreaterThanOrEqual(1);

    $banks = $this->getJson('/api/v1/aul/admin/banks', $h)->assertOk()->json('data');
    expect(collect($banks)->firstWhere('id', $e['question_bank_id'])['questions'])->toBe(1);

    // Recomendación manual: el matriculado no se recomienda; el resto sí, sin duplicar.
    $w3 = aulWorker($this, $h, 'Dora');
    $r = $this->postJson('/api/v1/aul/admin/recommendations', ['course_id' => $id, 'target_scope' => 'person', 'worker_id' => $w1], $h)->assertCreated()->json('data');
    expect($r['created'])->toBe(0)->and($r['enrolled'])->toBe(1);
    $this->postJson('/api/v1/aul/admin/recommendations', ['course_id' => $id, 'target_scope' => 'person', 'worker_id' => $w3, 'message' => 'Te servirá'], $h)->assertCreated()->assertJsonPath('data.created', 1);
    $this->postJson('/api/v1/aul/admin/recommendations', ['course_id' => $id, 'target_scope' => 'person', 'worker_id' => $w3], $h)->assertCreated()->assertJsonPath('data.duplicated', 1);
    $rec = $this->getJson('/api/v1/aul/admin/recommendations', $h)->assertOk()->json('data');
    expect($rec['funnel']['sent'])->toBeGreaterThanOrEqual(1);
    $rid = collect($rec['items'])->firstWhere('recipient', 'Dora Prueba')['id'];
    $this->postJson("/api/v1/aul/admin/recommendations/{$rid}/approve", [], $h)->assertStatus(409);
    $this->postJson("/api/v1/aul/admin/recommendations/{$rid}/dismiss", ['reason' => 'Ya lo llevó afuera'], $h)->assertOk()->assertJsonPath('data.status', 'dismissed');
});

it('registra la disponibilidad del capacitador sin superponer franjas', function () {
    $h = aulAdmin();
    $me = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    $this->postJson('/api/v1/aul/admin/trainers/availability', ['trainer_user_id' => $me, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'modality' => 'virtual'], $h)->assertCreated();
    $this->postJson('/api/v1/aul/admin/trainers/availability', ['trainer_user_id' => $me, 'weekday' => 1, 'start_time' => '11:00', 'end_time' => '13:00', 'modality' => 'virtual'], $h)->assertStatus(422);
    $this->postJson('/api/v1/aul/admin/trainers/availability', ['trainer_user_id' => $me, 'weekday' => 1, 'start_time' => '12:00', 'end_time' => '13:00', 'modality' => 'in_person'], $h)->assertCreated();
    $list = $this->getJson('/api/v1/aul/admin/trainers', $h)->assertOk()->json('data');
    expect(collect($list)->firstWhere('id', $me)['availability'])->toHaveCount(2);
});
