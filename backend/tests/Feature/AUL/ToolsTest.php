<?php

// Aula virtual (M-AUL): sorteo de preguntas por intento con tiempo límite y límite de intentos (RN-AUL-13/15),
// importación de preguntas desde Excel (RN-AUL-14), estadística por pregunta, escalas de calificación (SCR-AUL-10),
// permanencia por latidos y reporte (SCR-AUL-14, RN-AUL-07/08), vista como participante, extender acceso y reiniciar
// intentos con bitácora, y calificación manual («Por calificar»).

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

function aultH(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

function aultSheet(array $rows, string $version = 'AUL-Q1'): UploadedFile
{
    $head = ['Código', 'Tipo', 'Enunciado', 'Alternativa A', 'Alternativa B', 'Alternativa C', 'Alternativa D', 'Alternativa E', 'Alternativa F', 'Correcta', 'Dificultad', 'Puntos',
        'Retroalimentación si acierta', 'Retroalimentación si falla'];

    return UploadedFile::fake()->createWithContent('preguntas.xlsx', Xlsx::build('Preguntas', [["EscudoSST · Plantilla de preguntas del aula · versión {$version}"], ['i'], ['i'], ['Banco'], $head, ...$rows], 5));
}

/** n preguntas de opción única (la A es la correcta), con dificultades alternadas. */
function aultQuestions(int $n, string $prefix = 'Q'): array
{
    $d = ['Baja', 'Media', 'Alta'];

    return array_map(fn ($i) => ["{$prefix}-{$i}", 'Opción única', "Pregunta {$i}", 'Correcta', 'Incorrecta', 'Otra', '', '', '', 'A', $d[$i % 3], 1, '', 'Repasa'], range(1, $n));
}

/** Curso publicado con una evaluación de $draw preguntas al azar de un banco con $bankSize; un trabajador matriculado. */
function aultCourse($t, array $h, int $bankSize, int $draw, array $examExtra = [], array $courseExtra = []): array
{
    $c = $t->postJson('/api/v1/aul/admin/courses', ['title' => 'Curso de prueba '.uniqid(), 'hours' => 1, 'passing_score' => 70, 'max_attempts' => 2] + $courseExtra, $h)->assertCreated()->json('data.id');
    $exam = $t->postJson("/api/v1/aul/admin/courses/{$c}/lessons", ['lesson_type' => 'exam', 'title' => 'Evaluación', 'num_questions' => $draw] + $examExtra, $h)->assertCreated()->json('data');
    if ($bankSize) {
        $t->post("/api/v1/aul/admin/banks/{$exam['question_bank_id']}/import", ['file' => aultSheet(aultQuestions($bankSize))], $h)->assertOk()->assertJsonPath('data.created', $bankSize);
    }
    $t->postJson("/api/v1/aul/admin/courses/{$c}/publish", ['publish' => true], $h)->assertOk();
    $doc = (string) random_int(40000000, 79999999);
    $w = $t->postJson('/api/v1/records/TRB/workers', ['document_number' => $doc, 'first_name' => 'Ana', 'paternal_last_name' => 'Prueba'], $h)->assertCreated()->json('data.id');
    $t->postJson("/api/v1/aul/admin/courses/{$c}/enroll", ['worker_ids' => [$w]], $h)->assertCreated();
    $enr = DB::selectOne('select id from course_enrollments where course_id = ? and worker_id = ?', [$c, $w])->id;

    return ['course' => $c, 'exam' => $exam['id'], 'bank' => $exam['question_bank_id'], 'enrollment' => $enr, 'worker' => $w];
}

it('sortea las preguntas de cada intento, guarda el sorteo y califica solo esas', function () {
    $h = aultH();
    $x = aultCourse($this, $h, 40, 10, ['max_attempts' => 2, 'time_limit_minutes' => 15]);
    // Las preguntas no se entregan en el reproductor: se sortean al iniciar.
    $player = $this->getJson("/api/v1/aul/enrollments/{$x['enrollment']}", $h)->assertOk()->json('data');
    $exam = collect($player['lessons'])->firstWhere('id', $x['exam']);
    expect($exam)->not->toHaveKey('questions')->and($exam['num_questions'])->toBe(10)->and($exam['max_attempts'])->toBe(2);

    $a1 = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/attempts", [], $h)->assertCreated()->json('data');
    expect($a1['questions'])->toHaveCount(10)->and($a1['expires_at'])->not->toBeNull();
    // Retomar devuelve el mismo intento.
    expect($this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/attempts", [], $h)->json('data.id'))->toBe($a1['id']);
    $set = json_decode(DB::selectOne('select question_set from quiz_attempts where id = ?', [$a1['id']])->question_set, true);
    expect(array_column($set, 'id'))->toEqual(array_column($a1['questions'], 'id'));

    // Autoguardado y envío: solo cuentan las preguntas del sorteo; las ajenas se ignoran.
    $other = DB::selectOne('select id from questions where question_bank_id = ? and id not in ('.implode(',', array_fill(0, 10, '?')).') limit 1', [$x['bank'], ...array_column($set, 'id')])->id;
    $answers = [];
    foreach (array_slice($a1['questions'], 0, 6) as $q) {
        $answers[$q['id']] = '0';
    }
    $this->putJson("/api/v1/aul/enrollments/{$x['enrollment']}/attempts/{$a1['id']}", ['answers' => $answers + [$other => '0']], $h)->assertOk();
    $r = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/exam", ['attempt_id' => $a1['id'], 'answers' => $answers + [$other => '0']], $h)->assertOk()->json('data');
    expect($r['score_pct'])->toEqual(60)->and($r['passed'])->toBeFalse()->and($r['attempt'])->toBe(1);
    // Un intento enviado no cambia.
    $this->putJson("/api/v1/aul/enrollments/{$x['enrollment']}/attempts/{$a1['id']}", ['answers' => []], $h)->assertStatus(409);

    // Segundo intento: otro sorteo (con 10 de 40 es prácticamente imposible que coincida en el mismo orden).
    $a2 = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/attempts", [], $h)->assertCreated()->json('data');
    expect($a2['attempt_number'])->toBe(2)->and(array_column($a2['questions'], 'id'))->not->toEqual(array_column($a1['questions'], 'id'));
    // Vence el tiempo: se envía con lo guardado (nada) aunque traiga respuestas nuevas.
    DB::update("update quiz_attempts set started_at = now() - interval '20 minutes', expires_at = now() - interval '5 minutes' where id = ?", [$a2['id']]);
    $all = array_fill_keys(array_column($a2['questions'], 'id'), '0');
    $r2 = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/exam", ['attempt_id' => $a2['id'], 'answers' => $all], $h)->assertOk()->json('data');
    expect($r2['score_pct'])->toEqual(0)->and($r2['enrollment']['status'])->toBe('failed');
    // Sin intentos.
    $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/attempts", [], $h)->assertStatus(409);
});

it('respeta la mezcla por dificultad y mezcla las alternativas', function () {
    $h = aultH();
    $x = aultCourse($this, $h, 12, 6);
    $cfg = ['question_bank_id' => $x['bank'], 'num_questions' => 6, 'shuffle_questions' => true, 'shuffle_options' => true, 'difficulty_mix' => ['hard' => 4, 'easy' => 2], 'weight' => 1];
    $this->putJson("/api/v1/aul/admin/lessons/{$x['exam']}/exam", ['difficulty_mix' => ['hard' => 7]] + $cfg, $h)->assertStatus(422);
    $this->putJson("/api/v1/aul/admin/lessons/{$x['exam']}/exam", $cfg, $h)->assertOk()->assertJsonPath('data.available_questions', 12);
    $a = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/attempts", [], $h)->assertCreated()->json('data');
    $diff = collect(DB::select('select difficulty from questions where id in ('.implode(',', array_fill(0, 6, '?')).')', array_column($a['questions'], 'id')))->countBy('difficulty');
    expect($diff['hard'] ?? 0)->toBe(4)->and($diff['easy'] ?? 0)->toBe(2);
    // Las claves de las alternativas son las originales (la correcta sigue siendo «0» aunque cambie el orden).
    foreach ($a['questions'] as $q) {
        expect(collect($q['options'])->pluck('key')->sort()->values()->all())->toBe(['0', '1', '2']);
    }
    $r = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/exam", ['attempt_id' => $a['id'], 'answers' => array_fill_keys(array_column($a['questions'], 'id'), '0')], $h)
        ->assertOk()->json('data');
    expect($r['passed'])->toBeTrue()->and($r['enrollment']['status'])->toBe('completed');
});

it('importa preguntas desde Excel: previsualiza errores por fila e importa solo las válidas', function () {
    $h = aultH();
    $bank = $this->postJson('/api/v1/records/AUL/question_banks', ['code' => 'BG-IMP-'.random_int(100, 999), 'name' => 'Banco importado', 'status' => 'active'], $h)->assertCreated()->json('data.id');
    $tpl = $this->get("/api/v1/aul/admin/banks/{$bank}/import/template", $h)->assertOk();
    expect($tpl->headers->get('content-disposition'))->toContain('AUL-Q1');
    $rows = [
        ['I-1', 'Opción única', '¿Qué EPP protege los oídos?', 'Tapones', 'Casco', '', '', '', '', 'A', 'Baja', 2, 'Bien', 'No'],
        ['I-2', 'Opción múltiple', 'Marca los EPP', 'Casco', 'Lentes', 'Celular', '', '', '', 'A,B', 'Media', '', '', ''],
        ['I-3', 'Verdadero o falso', 'El arnés se revisa antes de usarlo', '', '', '', '', '', '', 'V', '', '', '', ''],
        ['I-4', 'Respuesta corta', 'Sigla del permiso de trabajo', '', '', '', '', '', '', 'PETAR|petar', 'Alta', '', '', ''],
        ['I-5', 'Opción única', 'Sin correcta', 'Uno', 'Dos', '', '', '', '', '', '', '', '', ''],
        ['I-6', 'Selección', 'Tipo malo', 'Uno', 'Dos', '', '', '', '', 'A', '', '', '', ''],
        ['I-1', 'Opción única', '', 'Uno', '', '', '', '', '', 'C', 'Extrema', '-1', '', ''],
    ];
    $p = $this->post("/api/v1/aul/admin/banks/{$bank}/import/preview", ['file' => aultSheet($rows)], $h)->assertOk()->json('data');
    expect($p['total'])->toBe(7)->and($p['valid'])->toBe(4)->and($p['with_errors'])->toBe(3);
    $by = collect($p['rows'])->keyBy('row');
    expect(implode(' ', $by[10]['errors']))->toContain('Indica la letra')
        ->and(implode(' ', $by[11]['errors']))->toContain('Tipo «Selección» no válido')
        ->and(implode(' ', $by[12]['errors']))->toContain('Código duplicado en el archivo (fila 6)')->toContain('Falta el enunciado')->toContain('Dificultad')->toContain('puntos');
    expect(DB::selectOne('select count(*) as n from questions where question_bank_id = ?', [$bank])->n)->toBe(0);

    $this->post("/api/v1/aul/admin/banks/{$bank}/import", ['file' => aultSheet($rows, 'AUL-Q0')], $h)->assertStatus(422);
    $r = $this->post("/api/v1/aul/admin/banks/{$bank}/import", ['file' => aultSheet($rows)], $h)->assertOk()->json('data');
    expect($r['created'])->toBe(4)->and($r['failed'])->toBe(3);
    $q = collect(DB::select('select code, question_type, options, accepted_answers, points, difficulty from questions where question_bank_id = ? order by code', [$bank]))->keyBy('code');
    expect(json_decode($q['I-2']->options, true))->toBe([['text' => 'Casco', 'correct' => true], ['text' => 'Lentes', 'correct' => true], ['text' => 'Celular', 'correct' => false]])
        ->and((float) $q['I-1']->points)->toEqual(2)->and($q['I-3']->question_type)->toBe('true_false')
        ->and(json_decode($q['I-4']->accepted_answers, true))->toBe(['PETAR', 'petar'])->and($q['I-4']->difficulty)->toBe('hard');
    // Volver a importar: los códigos ya existen en el banco.
    $again = $this->post("/api/v1/aul/admin/banks/{$bank}/import/preview", ['file' => aultSheet([$rows[0]])], $h)->assertOk()->json('data');
    expect($again['rows'][0]['errors'][0])->toContain('ya existe');
    expect(DB::selectOne("select count(*) as n from audit_logs where action = 'questions.import' and entity_id = ?", [$bank])->n)->toBe(1);
});

it('muestra la estadística por pregunta con la alternativa incorrecta más elegida', function () {
    $h = aultH();
    $x = aultCourse($this, $h, 3, 3, ['max_attempts' => 3]);
    $a = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/attempts", [], $h)->json('data');
    $ids = array_column($a['questions'], 'id');
    $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/exam", ['attempt_id' => $a['id'], 'answers' => [$ids[0] => '0', $ids[1] => '1', $ids[2] => '2']], $h)->assertOk();
    $b = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/attempts", [], $h)->json('data');
    $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/exam", ['attempt_id' => $b['id'], 'answers' => [$ids[0] => '0', $ids[1] => '1']], $h)->assertOk();
    $s = $this->getJson("/api/v1/aul/admin/courses/{$x['course']}/exam-stats", $h)->assertOk()->json('data.0');
    expect($s['attempts'])->toBe(2);
    $by = collect($s['questions'])->keyBy('question_id');
    expect($by[$ids[0]]['correct_pct'])->toEqual(100)->and($by[$ids[1]]['correct_pct'])->toEqual(0)
        ->and($by[$ids[1]]['top_wrong'])->toMatchArray(['text' => 'Incorrecta', 'count' => 2])
        ->and($by[$ids[2]]['blank'])->toBe(1)->and($s['questions'][0]['correct_pct'])->toEqual(0);
});

it('administra escalas de calificación y las usa en la nota del curso', function () {
    $h = aultH();
    $list = $this->getJson('/api/v1/aul/admin/scales', $h)->assertOk()->json('data');
    expect(collect($list['items'])->where('system', true)->pluck('code'))->toContain('NUM-0-20')->toContain('LET-AD-C')->and($list['can_edit'])->toBeTrue();
    $sys = collect($list['items'])->firstWhere('code', 'NUM-0-20')['id'];
    $this->putJson("/api/v1/aul/admin/scales/{$sys}", ['code' => 'X', 'name' => 'X', 'scale_type' => 'numeric', 'min_value' => 0, 'max_value' => 20, 'passing_value' => 11], $h)->assertForbidden();

    $this->postJson('/api/v1/aul/admin/scales', ['code' => 'V20', 'name' => 'Vigesimal', 'scale_type' => 'numeric', 'min_value' => 0, 'max_value' => 20, 'passing_value' => 25], $h)->assertStatus(422);
    $num = $this->postJson('/api/v1/aul/admin/scales', ['code' => 'v20', 'name' => 'Vigesimal propia', 'scale_type' => 'numeric', 'min_value' => 0, 'max_value' => 20, 'passing_value' => 14, 'decimals' => 1, 'is_default' => true], $h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/aul/admin/scales', ['code' => 'V20', 'name' => 'Otra', 'scale_type' => 'numeric', 'min_value' => 0, 'max_value' => 20, 'passing_value' => 14], $h)->assertStatus(422);
    $bad = ['code' => 'LET', 'name' => 'Letras', 'scale_type' => 'letter', 'passing_label' => 'A', 'bands' => [['label' => 'A', 'from' => 70], ['label' => 'B', 'from' => 30]]];
    $this->postJson('/api/v1/aul/admin/scales', $bad, $h)->assertStatus(422);   // la más baja no empieza en 0
    $let = $this->postJson('/api/v1/aul/admin/scales', ['code' => 'LET', 'name' => 'Letras AD', 'scale_type' => 'letter', 'passing_label' => 'A',
        'bands' => [['label' => 'C', 'from' => 0], ['label' => 'AD', 'from' => 90], ['label' => 'A', 'from' => 70], ['label' => 'B', 'from' => 50]]], $h)->assertCreated()->json('data.id');
    $row = DB::selectOne('select bands, is_default from grading_scales where id = ?', [$let]);
    expect(json_decode($row->bands, true)[0])->toMatchArray(['label' => 'AD', 'from' => 90, 'to' => 100]);

    // Un curso nuevo sin escala toma la de la empresa por defecto.
    $x = aultCourse($this, $h, 4, 4, ['max_attempts' => 3]);
    expect(DB::selectOne('select grading_scale_id from courses where id = ?', [$x['course']])->grading_scale_id)->toBe($num);
    $a = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/attempts", [], $h)->json('data');
    $ids = array_column($a['questions'], 'id');
    $r = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/exam", ['attempt_id' => $a['id'], 'answers' => [$ids[0] => '0', $ids[1] => '0', $ids[2] => '0']], $h)->json('data');
    expect($r['label'])->toBe('15');   // 75 % en 0-20
    // Con calificaciones, la escala ya no cambia sus valores (solo el nombre) y no se archiva.
    $this->putJson("/api/v1/aul/admin/scales/{$num}", ['code' => 'V20', 'name' => 'Vigesimal', 'scale_type' => 'numeric', 'min_value' => 0, 'max_value' => 20, 'passing_value' => 11], $h)->assertStatus(409);
    $this->putJson("/api/v1/aul/admin/scales/{$num}", ['code' => 'V20', 'name' => 'Vigesimal renombrada', 'scale_type' => 'numeric', 'min_value' => 0, 'max_value' => 20, 'passing_value' => 14, 'decimals' => 1, 'is_default' => true], $h)->assertOk();
    $this->deleteJson("/api/v1/aul/admin/scales/{$num}", [], $h)->assertStatus(409);
    $this->deleteJson("/api/v1/aul/admin/scales/{$let}", [], $h)->assertOk()->assertJsonPath('data.status', 'archived');

    // Escala por letras con mínimo A: un 60 % (rango B) no aprueba (criterio 4).
    $y = aultCourse($this, $h, 5, 5, ['max_attempts' => 3], []);
    $let2 = $this->postJson('/api/v1/aul/admin/scales', ['code' => 'LET2', 'name' => 'Letras', 'scale_type' => 'letter', 'passing_label' => 'A',
        'bands' => [['label' => 'AD', 'from' => 90], ['label' => 'A', 'from' => 70], ['label' => 'B', 'from' => 50], ['label' => 'C', 'from' => 0]]], $h)->assertCreated()->json('data.id');
    DB::update('update courses set grading_scale_id = ?, passing_score = null where id = ?', [$let2, $y['course']]);
    $b = $this->postJson("/api/v1/aul/enrollments/{$y['enrollment']}/lessons/{$y['exam']}/attempts", [], $h)->json('data');
    $bi = array_column($b['questions'], 'id');
    $r2 = $this->postJson("/api/v1/aul/enrollments/{$y['enrollment']}/lessons/{$y['exam']}/exam", ['attempt_id' => $b['id'], 'answers' => [$bi[0] => '0', $bi[1] => '0', $bi[2] => '0']], $h)->json('data');
    expect($r2['label'])->toBe('B')->and($r2['passed'])->toBeFalse()->and($r2['passing'])->toEqual(70);
});

it('mide la permanencia por latidos y la reporta frente al mínimo del curso', function () {
    $h = aultH();
    $x = aultCourse($this, $h, 1, 1, [], ['min_active_time_pct' => 50]);   // 1 h al 50 % = 30 min
    $e = $x['enrollment'];
    $this->postJson("/api/v1/aul/enrollments/{$e}/heartbeat", ['client_session_id' => 'tab-1', 'seq' => 1, 'interactions' => 2], $h)->assertOk()->assertJsonPath('data.credited_seconds', 0);
    $sid = DB::selectOne('select id from learning_activity_sessions where enrollment_id = ?', [$e])->id;
    DB::update("update learning_activity_sessions set started_at = now() - interval '25 seconds', last_heartbeat_at = now() - interval '25 seconds' where id = ?", [$sid]);
    $b = $this->postJson("/api/v1/aul/enrollments/{$e}/heartbeat", ['client_session_id' => 'tab-1', 'seq' => 2, 'interactions' => 3, 'video_seconds' => 20], $h)->assertOk()->json('data');
    expect($b['credited_seconds'])->toBeGreaterThanOrEqual(24)->toBeLessThanOrEqual(30);
    // Fuera de secuencia: no suma y marca la sesión.
    $this->postJson("/api/v1/aul/enrollments/{$e}/heartbeat", ['client_session_id' => 'tab-1', 'seq' => 2, 'interactions' => 3], $h)->assertOk()->assertJsonPath('data.accepted', false);
    // Sin interacción no suma; otra pestaña reemplaza la sesión (RN-AUL-08).
    DB::update("update learning_activity_sessions set last_heartbeat_at = now() - interval '20 seconds' where id = ?", [$sid]);
    $this->postJson("/api/v1/aul/enrollments/{$e}/heartbeat", ['client_session_id' => 'tab-1', 'seq' => 3, 'interactions' => 0], $h)->assertOk()->assertJsonPath('data.credited_seconds', 0);
    $this->postJson("/api/v1/aul/enrollments/{$e}/heartbeat", ['client_session_id' => 'tab-2', 'seq' => 1, 'interactions' => 1], $h)->assertOk();
    expect(DB::selectOne('select end_reason, is_flagged from learning_activity_sessions where id = ?', [$sid]))->toMatchArray(['end_reason' => 'superseded', 'is_flagged' => true]);
    // Inactividad: la sesión vieja se cierra por inactividad y ese tiempo no suma.
    DB::update("update learning_activity_sessions set started_at = now() - interval '1 hour', last_heartbeat_at = now() - interval '30 minutes' where ended_at is null and enrollment_id = ?", [$e]);
    $this->postJson("/api/v1/aul/enrollments/{$e}/heartbeat", ['client_session_id' => 'tab-2', 'seq' => 2, 'interactions' => 5], $h)->assertOk()->assertJsonPath('data.credited_seconds', 0);
    expect(DB::selectOne("select count(*) as n from learning_activity_sessions where enrollment_id = ? and end_reason = 'idle_timeout'", [$e])->n)->toBe(1);

    $rep = $this->getJson("/api/v1/aul/admin/courses/{$x['course']}/time", $h)->assertOk()->json('data');
    $row = $rep['rows'][0];
    expect($rep['required_seconds'])->toBe(1800)->and($row['active_seconds'])->toBe($b['active_seconds_total'])->and($row['complies'])->toBeFalse()
        ->and($row['sessions'])->toBe(3)->and($row['idle_closed'])->toBe(1)->and($row['video_seconds'])->toBe(20)->and($rep['summary']['not_complying'])->toBe(1);
    $csv = $this->get("/api/v1/aul/admin/courses/{$x['course']}/time?format=csv", $h)->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')->and($csv->getContent())->toContain('Tiempo activo (h:mm)')->toContain('Ana Prueba');
});

it('permite ver el curso como participante sin matricularse', function () {
    $h = aultH();
    $x = aultCourse($this, $h, 5, 3);
    $this->postJson("/api/v1/aul/admin/courses/{$x['course']}/lessons", ['lesson_type' => 'text', 'title' => 'Lectura', 'content_html' => '<p>Hola</p>'], $h)->assertCreated();
    $before = DB::selectOne('select count(*) as n from course_enrollments where course_id = ?', [$x['course']])->n;
    $p = $this->getJson("/api/v1/aul/admin/courses/{$x['course']}/preview", $h)->assertOk()->json('data');
    expect($p['preview'])->toBeTrue()->and($p['enrollment'])->toBeNull()->and($p['lessons'])->toHaveCount(2);
    $q = $this->getJson("/api/v1/aul/admin/courses/{$x['course']}/preview/lessons/{$x['exam']}", $h)->assertOk()->json('data.questions');
    expect($q)->toHaveCount(3)->and($q[0]['options'][0])->not->toHaveKey('correct');
    expect(DB::selectOne('select count(*) as n from course_enrollments where course_id = ?', [$x['course']])->n)->toBe($before)
        ->and(DB::selectOne('select count(*) as n from quiz_attempts q join course_lessons l on l.id = q.lesson_id where l.course_id = ?', [$x['course']])->n)->toBe(0);
});

it('extiende el acceso y reinicia intentos con motivo en la bitácora', function () {
    $h = aultH();
    $x = aultCourse($this, $h, 2, 2, ['max_attempts' => 1]);
    $e = $x['enrollment'];
    $a = $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$x['exam']}/attempts", [], $h)->json('data');
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$x['exam']}/exam", ['attempt_id' => $a['id'], 'answers' => []], $h)->assertOk()->assertJsonPath('data.enrollment.status', 'failed');
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$x['exam']}/attempts", [], $h)->assertStatus(409);

    $this->postJson("/api/v1/aul/admin/enrollments/{$e}/reset-attempts", ['reason' => ''], $h)->assertStatus(422);
    $this->postJson("/api/v1/aul/admin/enrollments/{$e}/reset-attempts", ['lesson_id' => $x['exam'], 'reason' => 'Se cortó la luz en la sede'], $h)->assertOk()
        ->assertJsonPath('data.reset', 1)->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/v1/aul/enrollments/{$e}/lessons/{$x['exam']}/attempts", [], $h)->assertCreated()->assertJsonPath('data.attempt_number', 1);

    DB::update("update course_enrollments set access_until = now() - interval '1 day', status = 'expired' where id = ?", [$e]);
    $this->getJson("/api/v1/aul/enrollments/{$e}", $h)->assertStatus(409);
    $this->postJson("/api/v1/aul/admin/enrollments/{$e}/extend", ['access_until' => now()->subDay()->toDateString(), 'reason' => 'Licencia médica'], $h)->assertStatus(422);
    $until = now()->addDays(15)->toDateString();
    $this->postJson("/api/v1/aul/admin/enrollments/{$e}/extend", ['access_until' => $until, 'reason' => 'Estuvo de vacaciones'], $h)->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->getJson("/api/v1/aul/enrollments/{$e}", $h)->assertOk();
    $logs = DB::select("select action, changes from audit_logs where entity_id = ? and action in ('course_enrollments.extend_access', 'quiz_attempts.reset') order by created_at", [$e]);
    expect(array_column($logs, 'action'))->toBe(['quiz_attempts.reset', 'course_enrollments.extend_access'])
        ->and(json_decode($logs[1]->changes, true)['reason'])->toBe('Estuvo de vacaciones');
});

it('deja por calificar las preguntas abiertas y las evaluaciones prácticas', function () {
    $h = aultH();
    $x = aultCourse($this, $h, 2, 2, ['max_attempts' => 2]);
    $this->post("/api/v1/aul/admin/banks/{$x['bank']}/import", ['file' => aultSheet([['O-1', 'Respuesta corta', 'Describe cómo llenas el PETAR', '', '', '', '', '', '', '', 'Media', 2, '', '']])], $h)->assertOk();
    $this->putJson("/api/v1/aul/admin/lessons/{$x['exam']}/exam", ['question_bank_id' => $x['bank'], 'num_questions' => 3, 'max_attempts' => 2], $h)->assertOk();
    $a = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/attempts", [], $h)->json('data');
    $open = collect($a['questions'])->firstWhere('type', 'short_answer')['id'];
    $answers = collect($a['questions'])->mapWithKeys(fn ($q) => [$q['id'] => $q['type'] === 'short_answer' ? 'Reviso el área y firmo' : '0'])->all();
    $r = $this->postJson("/api/v1/aul/enrollments/{$x['enrollment']}/lessons/{$x['exam']}/exam", ['attempt_id' => $a['id'], 'answers' => $answers], $h)->assertOk()->json('data');
    expect($r['pending'])->toBeTrue()->and($r['passed'])->toBeNull();

    $queue = $this->getJson('/api/v1/aul/admin/grading', $h)->assertOk()->json('data');
    expect(collect($queue['items'])->pluck('id'))->toContain($a['id']);
    $d = $this->getJson("/api/v1/aul/admin/attempts/{$a['id']}", $h)->assertOk()->json('data');
    $oq = collect($d['questions'])->firstWhere('id', $open);
    expect($oq['manual'])->toBeTrue()->and($oq['answer'])->toBe('Reviso el área y firmo')->and($d['max_score'])->toEqual(4);
    $this->postJson("/api/v1/aul/admin/attempts/{$a['id']}/grade", ['feedback' => 'Falta'], $h)->assertStatus(422);
    $g = $this->postJson("/api/v1/aul/admin/attempts/{$a['id']}/grade", ['question_points' => [$open => 1], 'feedback' => 'Menciona la verificación de gases.'], $h)->assertOk()->json('data');
    expect($g['score_pct'])->toEqual(75)->and($g['passed'])->toBeTrue()->and($g['label'])->toBe('75 %');
    expect(DB::selectOne('select status, feedback, graded_by from quiz_attempts where id = ?', [$a['id']]))->toMatchArray(['status' => 'graded', 'feedback' => 'Menciona la verificación de gases.']);
    $this->postJson("/api/v1/aul/admin/attempts/{$a['id']}/grade", ['score_pct' => 10], $h)->assertStatus(409);
    expect(DB::selectOne('select status from course_enrollments where id = ?', [$x['enrollment']])->status)->toBe('completed');

    // Evaluación práctica: la nota la pone el capacitador.
    $y = aultCourse($this, $h, 2, 2, ['max_attempts' => 2]);
    $this->putJson("/api/v1/aul/admin/lessons/{$y['exam']}/exam", ['question_bank_id' => $y['bank'], 'num_questions' => 2, 'manual_grading' => true, 'max_attempts' => 2], $h)->assertOk();
    $b = $this->postJson("/api/v1/aul/enrollments/{$y['enrollment']}/lessons/{$y['exam']}/attempts", [], $h)->json('data');
    $this->postJson("/api/v1/aul/enrollments/{$y['enrollment']}/lessons/{$y['exam']}/exam", ['attempt_id' => $b['id'], 'answers' => []], $h)->assertOk()->assertJsonPath('data.pending', true);
    $this->postJson("/api/v1/aul/admin/attempts/{$b['id']}/grade", ['score_pct' => 50], $h)->assertOk()->assertJsonPath('data.passed', false);
});

/** Corre el job de recomendaciones para la empresa de prueba. */
function aultRunJob(): int
{
    $db = DB::connection('pgsql');
    $db->select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);

    return app(\App\Modules\AUL\RecommendationEngine::class)->forCompany($db, HUAYLAS_ID);
}

function aultCert(string $course, string $worker, string $expires): void
{
    DB::insert("insert into training_certificates (company_id, course_id, worker_id, certificate_code, verification_code, holder_name, course_title, issued_on, expires_on, status, validated_at, validated_by)
                values (?, ?, ?, ?, ?, 'Ana Prueba', 'Curso', current_date - 300, ?::date, 'validated', now(), (select created_by from courses where id = ?))",
        [HUAYLAS_ID, $course, $worker, 'CERTT-2026-'.random_int(100000, 999999), strtoupper(bin2hex(random_bytes(16))), $expires, $course]);
}

it('recomienda la recapacitación de certificados por vencer sin repetir y respeta el límite de envíos', function () {
    $h = aultH();
    $x = aultCourse($this, $h, 1, 1);
    $y = aultCourse($this, $h, 1, 1);
    // Quien está matriculado en el curso no recibe la recomendación; se cancela la matrícula para que la reciba.
    DB::update("update course_enrollments set status = 'cancelled' where id in (?, ?)", [$x['enrollment'], $y['enrollment']]);
    aultCert($x['course'], $x['worker'], now()->addDays(10)->toDateString());
    aultCert($x['course'], $y['worker'], now()->addDays(200)->toDateString());   // fuera de la anticipación

    $this->postJson('/api/v1/aul/admin/rules', ['code' => 'R-1', 'name' => 'Recapacitación', 'rule_type' => 'certificate_expiring', 'audience' => 'workers', 'channels' => ['in_app', 'email']], $h)->assertStatus(201);
    $this->postJson('/api/v1/aul/admin/rules', ['code' => 'r-1', 'name' => 'Otra', 'rule_type' => 'certificate_expiring', 'audience' => 'workers', 'channels' => ['in_app']], $h)->assertStatus(422);
    $rule = DB::selectOne("select id from recommendation_rules where code = 'R-1'")->id;
    $this->putJson("/api/v1/aul/admin/rules/{$rule}", ['code' => 'R-1', 'name' => 'Recapacitación', 'rule_type' => 'certificate_expiring', 'audience' => 'workers', 'channels' => ['in_app', 'email'],
        'max_sends_per_person' => 1, 'send_period_days' => 30, 'parameters' => ['lead_days' => 30], 'is_active' => true], $h)->assertOk();
    expect(json_decode(DB::selectOne('select parameters from recommendation_rules where id = ?', [$rule])->parameters, true)['lead_days'])->toBe(30);

    // Vista previa: no guarda nada.
    $pre = $this->postJson("/api/v1/aul/admin/rules/{$rule}/run", ['dry_run' => true], $h)->assertOk()->json('data');
    expect($pre['created'])->toBe(1)->and($pre['preview'][0]['reason'])->toContain('vence');
    expect(DB::selectOne('select count(*) as n from course_recommendations where rule_id = ?', [$rule])->n)->toBe(0);

    expect(aultRunJob())->toBe(1);
    $rec = DB::selectOne('select * from course_recommendations where rule_id = ?', [$rule]);
    expect($rec->worker_id)->toBe($x['worker'])->and($rec->status)->toBe('sent')->and($rec->channel)->toBe('email')->and($rec->message)->toContain('vence');
    expect(DB::selectOne('select count(*) as n from notifications where subject_id = ? and recipient_worker_id = ?', [$rec->id, $x['worker']])->n)->toBe(1);
    // Al día siguiente no se repite (criterio 11).
    expect(aultRunJob())->toBe(0);
    expect(DB::selectOne('select last_run_status, last_run_count from recommendation_rules where id = ?', [$rule]))->toMatchArray(['last_run_status' => 'ok', 'last_run_count' => 0]);

    // Otro certificado por vencer de la misma persona: ya alcanzó el límite de envíos, solo en el portal (criterio 14).
    aultCert($y['course'], $x['worker'], now()->addDays(5)->toDateString());
    expect(aultRunJob())->toBe(1);
    expect(DB::selectOne('select channel from course_recommendations where rule_id = ? and course_id = ?', [$rule, $y['course']])->channel)->toBe('in_app');
});

it('recomienda por brecha del puesto con aprobación y a participantes externos sin publicidad solo en el portal', function () {
    $h = aultH();
    $x = aultCourse($this, $h, 1, 1);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'AUL-S'.random_int(10, 99), 'name' => 'Planta aula'], $h)->assertCreated()->json('data.id');
    $area = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'AUL-A'.random_int(10, 99), 'name' => 'Área aula'], $h)->assertCreated()->json('data.id');
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'AUL-P'.random_int(10, 99), 'name' => 'Soldador del aula', 'area_id' => $area], $h)->assertCreated()->json('data.id');
    $doc = (string) random_int(40000000, 79999999);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $doc, 'first_name' => 'Beto', 'paternal_last_name' => 'Brecha'], $h)->assertCreated()->json('data.id');
    DB::update('update workers set current_position_id = ? where id = ?', [$pos, $w]);
    DB::insert("insert into position_training_requirements (company_id, position_id, course_id, requirement_level, is_active) values (?, ?, ?, 'mandatory', true)", [HUAYLAS_ID, $pos, $x['course']]);
    $this->postJson('/api/v1/aul/admin/rules', ['code' => 'R-GAP', 'name' => 'Brecha', 'rule_type' => 'matrix_gap', 'audience' => 'workers', 'channels' => ['in_app'], 'is_active' => true,
        'parameters' => ['requires_approval' => true]], $h)->assertCreated();
    aultRunJob();
    $rec = DB::selectOne("select r.* from course_recommendations r join recommendation_rules rr on rr.id = r.rule_id where rr.code = 'R-GAP'");
    expect($rec->worker_id)->toBe($w)->and($rec->status)->toBe('suggested')->and($rec->message)->toContain('Soldador del aula');
    expect(DB::selectOne('select count(*) as n from notifications where subject_id = ?', [$rec->id])->n)->toBe(0);   // espera la bandeja

    // Externos: completó el curso A; la regla complementaria recomienda el curso B; sin consentimiento, solo portal (criterio 13).
    $y = aultCourse($this, $h, 1, 1);
    $xl = DB::selectOne("insert into external_learners (company_id, document_type, document_number, document_number_hash, document_last4, first_name, last_name, email, data_consent_at, consent_text_version, marketing_consent, source, status)
                         values (?, 'DNI', '00000000', decode(md5(random()::text), 'hex'), '0000', 'Eva', 'Externa', 'eva@externa.test', now(), 'v1', false, 'manual', 'active') returning id", [HUAYLAS_ID])->id;
    DB::insert("insert into course_enrollments (company_id, course_id, external_learner_id, source, status, completed_at, progress_pct) values (?, ?, ?, 'manual', 'completed', now(), 100)", [HUAYLAS_ID, $x['course'], $xl]);
    $this->postJson('/api/v1/aul/admin/rules', ['code' => 'R-CMP', 'name' => 'Complementario', 'rule_type' => 'complementary', 'audience' => 'external_learners', 'channels' => ['email'], 'is_active' => true], $h)->assertStatus(422);
    $this->postJson('/api/v1/aul/admin/rules', ['code' => 'R-CMP', 'name' => 'Complementario', 'rule_type' => 'complementary', 'audience' => 'external_learners', 'channels' => ['email'], 'is_active' => true,
        'course_ids' => [$y['course']], 'parameters' => ['after_course_ids' => [$x['course']]]], $h)->assertCreated();
    aultRunJob();
    $ext = DB::selectOne("select r.* from course_recommendations r join recommendation_rules rr on rr.id = r.rule_id where rr.code = 'R-CMP'");
    expect($ext->external_learner_id)->toBe($xl)->and($ext->course_id)->toBe($y['course'])->and($ext->channel)->toBe('in_app')->and($ext->status)->toBe('sent');
});
