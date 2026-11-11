<?php

namespace App\Modules\AUL;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administración del aula virtual (M-AUL SCR-AUL-01..19). Lo que escribe pasa por el motor de registros (mismas reglas,
 * permisos, bitácora y webhooks); las ediciones sueltas (renombrar, borrar, configurar) se hacen con /records/AUL/...
 *  GET  /aul/admin/overview                        indicadores y tarjetas de cursos
 *  POST /aul/admin/courses                         nuevo curso (código automático) → se abre el curso
 *  GET  /aul/admin/courses/{id}                    curso: indicadores, secciones y lecciones, evaluaciones, bancos, capacitadores
 *  POST /aul/admin/courses/{id}/publish            {publish: bool} publica (con validaciones) o vuelve a borrador (sin matrículas)
 *  POST /aul/admin/courses/{id}/sections           {title} nueva sección al final
 *  POST /aul/admin/courses/{id}/lessons            nueva lección: texto, video (Vimeo), documento, enlace o evaluación
 *  PUT  /aul/admin/courses/{id}/order              {sections: [id], lessons: [{id, section_id}]} orden de secciones y lecciones
 *  GET  /aul/admin/courses/{id}/participants       matriculados con avance, nota, permanencia, compromiso y estado
 *  POST /aul/admin/courses/{id}/enroll             {worker_ids[] | position_id | area_id, due_on?, dry_run?} matricular
 *  GET  /aul/admin/courses/{id}/grades             libro de notas: participantes × evaluaciones e intentos
 *  GET  /aul/admin/banks                           bancos generales y por curso con preguntas por tipo y dificultad
 *  GET  /aul/admin/trainers                        capacitadores con permisos por curso y disponibilidad semanal
 *  POST /aul/admin/trainers/availability           franja de disponibilidad (sin superponerse, RN-AUL-19)
 *  GET  /aul/admin/recommendations                 embudo, reglas y recomendaciones enviadas o por aprobar
 *  POST /aul/admin/recommendations                 recomendación manual a persona, cargo o área (una por persona alcanzada)
 *  POST /aul/admin/recommendations/{id}/approve    aprobar una sugerida (pasa a enviada)
 *  POST /aul/admin/recommendations/{id}/dismiss    {reason?} descartar
 * Quien aprende (trabajador, participante externo) no usa estas rutas: tiene «Mis cursos» (/aul/my).
 */
class AulAdminController extends Controller
{
    private const LEARNERS = ['worker', 'external_learner'];

    public function __construct(private RecordsService $records) {}

    public function overview(): JsonResponse
    {
        $this->staff();
        $trainer = $this->trainerFilter('c.id');
        $courses = DB::select(
            "select c.id, c.code, c.title, c.category, c.hours, c.status, c.sequential, c.public_verification_enabled, c.certificate_enabled, c.passing_score,
                    c.max_attempts, c.completion_commitment, g.name as scale_name,
                    (select count(*) from course_enrollments e where e.course_id = c.id and e.deleted_at is null and e.status <> 'cancelled') as enrolled,
                    (select count(*) from course_enrollments e where e.course_id = c.id and e.deleted_at is null and e.status = 'completed') as passed,
                    (select count(*) from course_enrollments e where e.course_id = c.id and e.deleted_at is null and e.status in ('enrolled', 'in_progress')) as active,
                    (select string_agg(distinct u.full_name, ', ') from trainer_permissions t join users u on u.id = t.trainer_user_id
                      where t.course_id = c.id and t.deleted_at is null) as trainers
               from courses c left join grading_scales g on g.id = c.grading_scale_id
              where c.deleted_at is null and c.company_id is not null and c.status <> 'archived' {$trainer[0]}
              order by c.status desc, c.code", $trainer[1]);
        $ids = array_column($courses, 'id');
        $hours = $ids ? (float) DB::selectOne(
            "select coalesce(sum(c.hours), 0) as h from course_enrollments e join courses c on c.id = e.course_id
              where e.deleted_at is null and e.status = 'completed' and e.completed_at >= date_trunc('month', now()) and e.course_id in (".implode(',', array_fill(0, count($ids), '?')).')', $ids)->h : 0;
        foreach ($courses as $c) {
            $c->hours = $c->hours === null ? null : (float) $c->hours;
            $c->passing_score = $c->passing_score === null ? null : (float) $c->passing_score;
        }
        $pub = array_filter($courses, fn ($c) => $c->status === 'published');

        return response()->json(['data' => [
            'kpis' => [
                'published' => count($pub), 'drafts' => count($courses) - count($pub),
                'active_enrollments' => array_sum(array_map(fn ($c) => (int) $c->active, $courses)),
                'passed' => array_sum(array_map(fn ($c) => (int) $c->passed, $courses)),
                'hours_month' => $hours,
            ],
            'courses' => $courses,
            'can' => $this->can(),
        ]]);
    }

    public function createCourse(Request $request): JsonResponse
    {
        $this->staff();
        if (app(CompanyContext::class)->roleCode === 'trainer') {
            throw ApiProblem::forbidden('Crear cursos es del administrador del aula (RN-AUL-01).');
        }
        $d = $this->courseRules($request, true);
        // Sin escala elegida, la escala por defecto de la empresa (SCR-AUL-10).
        $d['grading_scale_id'] ??= DB::selectOne("select id from grading_scales where company_id is not null and is_default and status = 'active' and deleted_at is null limit 1")?->id;
        if ($d['grading_scale_id'] === null) {
            unset($d['grading_scale_id']);
        }
        $n = (int) DB::selectOne('select count(*) + 1 as n from courses where company_id is not null')->n;
        do {
            $code = sprintf('CUR-%03d', $n++);
        } while (DB::selectOne('select 1 as ok from courses where code = ? and company_id is not null', [$code]));
        $row = $this->records->create(Registry::resource('AUL', 'courses'), ['code' => $code, 'source' => 'internal', 'status' => 'draft'] + $d);

        return response()->json(['data' => ['id' => $row['id'], 'code' => $code]], 201);
    }

    public function course(string $id): JsonResponse
    {
        $c = $this->find($id);
        $sections = DB::select('select id, section_number, title, description, sort_order, is_visible from course_sections where course_id = ? and deleted_at is null order by sort_order, section_number', [$id]);
        $lessons = DB::select(
            "select l.id, l.section_id, l.lesson_number, l.title, l.lesson_type, l.content_html, l.file_id, l.video_external_id, l.external_url, l.duration_minutes,
                    l.is_required, l.min_view_pct, l.question_bank_id, l.exam_config, l.passing_score, l.max_attempts, l.time_limit_minutes, l.sort_order, b.name as bank_name,
                    (select count(*) from questions q where q.question_bank_id = l.question_bank_id and q.deleted_at is null and q.status = 'active') as bank_questions
               from course_lessons l left join question_banks b on b.id = l.question_bank_id
              where l.course_id = ? and l.deleted_at is null order by l.sort_order, l.lesson_number", [$id]);
        foreach ($lessons as $l) {
            $l->exam_config = $l->exam_config ? json_decode($l->exam_config, true) : null;
        }
        $k = DB::selectOne(
            "select count(*) filter (where status <> 'cancelled') as enrolled, coalesce(avg(progress_pct) filter (where status <> 'cancelled'), 0) as avg_progress,
                    count(*) filter (where status = 'completed') as passed, coalesce(avg(active_seconds_total) filter (where status <> 'cancelled'), 0) as avg_active_seconds
               from course_enrollments where course_id = ? and deleted_at is null", [$id]);

        return response()->json(['data' => [
            'course' => $c,
            'kpis' => ['enrolled' => (int) $k->enrolled, 'avg_progress' => round((float) $k->avg_progress), 'passed' => (int) $k->passed, 'avg_active_seconds' => (int) round((float) $k->avg_active_seconds)],
            'sections' => $sections,
            'lessons' => $lessons,
            'banks' => $this->bankRows($id),
            'trainers' => DB::select(
                'select t.id, t.trainer_user_id, u.full_name as name, t.can_upload_materials, t.can_edit_content, t.can_edit_assessments, t.can_manage_question_banks,
                        t.can_grade, t.can_enroll, t.can_view_reports from trainer_permissions t join users u on u.id = t.trainer_user_id
                  where t.course_id = ? and t.deleted_at is null order by u.full_name', [$id]),
            'can' => $this->can(),
        ]]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $c = $this->find($id);
        $res = Registry::resource('AUL', 'courses');
        if (! $request->boolean('publish', true)) {
            if (DB::selectOne("select 1 as ok from course_enrollments where course_id = ? and deleted_at is null and status <> 'cancelled'", [$id])) {
                throw new ApiProblem(409, 'conflict', 'Curso con matriculados', 'El curso tiene matriculados: no vuelve a borrador. Crea una nueva versión para cambiarlo.');
            }

            return response()->json(['data' => $this->records->update($res, $id, ['status' => 'draft'])]);
        }
        $errors = [];
        if (! DB::selectOne('select 1 as ok from course_lessons where course_id = ? and deleted_at is null and is_required', [$id])) {
            $errors[] = 'Agrega al menos una actividad obligatoria.';
        }
        foreach (DB::select("select l.title, (select count(*) from questions q where (q.question_bank_id = l.question_bank_id
                                        or q.question_bank_id::text in (select jsonb_array_elements_text(coalesce(l.exam_config->'bank_ids', '[]'::jsonb))))
                                        and q.deleted_at is null and q.status = 'active') as n,
                                    coalesce((l.exam_config->>'num_questions')::int, 1) as need
                               from course_lessons l where l.course_id = ? and l.deleted_at is null and l.lesson_type = 'exam'", [$id]) as $e) {
            if ((int) $e->n < max(1, (int) $e->need)) {
                $errors[] = "La evaluación «{$e->title}» necesita {$e->need} pregunta(s) activas en su banco y tiene {$e->n}.";
            }
        }
        if ($c->hours === null) {
            $errors[] = 'Indica las horas que acredita el curso.';
        }
        if ($errors) {
            throw new ApiProblem(422, 'validation_failed', 'Aún no se puede publicar', implode(' ', $errors), ['errors' => ['status' => $errors]]);
        }

        return response()->json(['data' => $this->records->update($res, $id, ['status' => 'published', 'published_at' => now()->toIso8601String(), 'published_by' => (string) auth()->id()])]);
    }

    public function addSection(Request $request, string $id): JsonResponse
    {
        $this->find($id);
        $d = $request->validate(['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000']], ['title.required' => 'Escribe el nombre de la sección.']);
        $n = DB::selectOne('select coalesce(max(section_number), 0) + 1 as n, coalesce(max(sort_order), 0) + 1 as o from course_sections where course_id = ?', [$id]);
        $row = $this->records->create(Registry::resource('AUL', 'course_sections'), $d + ['course_id' => $id, 'section_number' => (int) $n->n, 'sort_order' => (int) $n->o, 'is_visible' => true]);

        return response()->json(['data' => $row], 201);
    }

    public function addLesson(Request $request, string $id): JsonResponse
    {
        $c = $this->find($id);
        $d = $request->validate([
            'section_id' => ['nullable', 'uuid'],
            'lesson_type' => ['required', 'in:text,video,document,link,exam'],
            'title' => ['required', 'string', 'max:200'],
            'is_required' => ['boolean'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'content_html' => ['required_if:lesson_type,text', 'nullable', 'string', 'max:100000'],
            'video_url' => ['required_if:lesson_type,video', 'nullable', 'string', 'max:300'],
            'min_view_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'file_id' => ['required_if:lesson_type,document', 'nullable', 'uuid'],
            'external_url' => ['required_if:lesson_type,link', 'nullable', 'string', 'max:500', 'starts_with:https://'],
            'question_bank_id' => ['nullable', 'uuid'],
            'passing_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'num_questions' => ['nullable', 'integer', 'min:1', 'max:200'],
        ], [
            'title.required' => 'Escribe el título.',
            'content_html.required_if' => 'Escribe el texto que leerá el participante.',
            'video_url.required_if' => 'Pega el enlace o el número del video en Vimeo.',
            'file_id.required_if' => 'Adjunta el documento.',
            'external_url.required_if' => 'Escribe la dirección del enlace.',
            'external_url.starts_with' => 'El enlace debe empezar con https://',
        ]);
        if (! empty($d['section_id']) && ! DB::selectOne('select 1 as ok from course_sections where id = ? and course_id = ? and deleted_at is null', [$d['section_id'], $id])) {
            $this->fail('section_id', 'La sección no es de este curso.');
        }
        $values = ['course_id' => $id, 'section_id' => $d['section_id'] ?? null, 'lesson_type' => $d['lesson_type'], 'title' => $d['title'],
            'is_required' => $d['is_required'] ?? true, 'duration_minutes' => $d['duration_minutes'] ?? null];
        switch ($d['lesson_type']) {
            case 'text':
                $values['content_html'] = $d['content_html'];
                break;
            case 'video':
                if (! preg_match('~^(?:https://(?:www\.|player\.)?vimeo\.com/(?:video/)?)?(\d{5,12})(?:[/?#].*)?$~', trim($d['video_url']), $m)) {
                    $this->fail('video_url', 'No reconocemos ese video de Vimeo: pega el enlace (https://vimeo.com/123456789) o su número.');
                }
                $values += ['video_provider' => 'vimeo', 'video_external_id' => $m[1], 'min_view_pct' => $d['min_view_pct'] ?? 90];
                break;
            case 'document':
                $values['file_id'] = $d['file_id'];
                break;
            case 'link':
                $values['external_url'] = $d['external_url'];
                break;
            case 'exam':
                $bank = $d['question_bank_id'] ?? null;
                if ($bank && ! DB::selectOne('select 1 as ok from question_banks where id = ? and deleted_at is null and (course_id is null or course_id = ?)', [$bank, $id])) {
                    $this->fail('question_bank_id', 'Elige un banco general o de este curso.');
                }
                if (! $bank) {   // Sin banco elegido: se usa (o se crea) el banco del curso.
                    $bank = DB::selectOne("select id from question_banks where course_id = ? and deleted_at is null and status = 'active' order by created_at limit 1", [$id])?->id
                        ?? $this->records->create(Registry::resource('AUL', 'question_banks'), ['course_id' => $id, 'code' => $this->bankCode($c->code), 'name' => 'Banco de '.$c->title, 'status' => 'active'])['id'];
                }
                $values += ['question_bank_id' => $bank, 'passing_score' => $d['passing_score'] ?? null, 'max_attempts' => $d['max_attempts'] ?? $c->max_attempts,
                    'time_limit_minutes' => $d['time_limit_minutes'] ?? null,
                    'exam_config' => ['num_questions' => $d['num_questions'] ?? 10, 'shuffle_questions' => true, 'shuffle_options' => (bool) $c->randomize_options, 'show_feedback' => 'on_finish', 'weight' => 1]];
                break;
        }
        $n = DB::selectOne('select coalesce(max(lesson_number), 0) + 1 as n, coalesce(max(sort_order), 0) + 1 as o from course_lessons where course_id = ?', [$id]);
        $config = $values['exam_config'] ?? null;   // campo de solo lectura en el motor de registros: se guarda aparte
        unset($values['exam_config']);
        $row = $this->records->create(Registry::resource('AUL', 'course_lessons'), array_filter($values, fn ($v) => $v !== null) + ['lesson_number' => (int) $n->n, 'sort_order' => (int) $n->o]);
        if ($config !== null) {
            DB::update('update course_lessons set exam_config = ?::jsonb where id = ?', [json_encode($config), $row['id']]);
            $row['exam_config'] = $config;
        }

        return response()->json(['data' => $row], 201);
    }

    public function order(Request $request, string $id): JsonResponse
    {
        $this->find($id);
        $d = $request->validate(['sections' => ['present', 'array'], 'sections.*' => ['uuid'], 'lessons' => ['present', 'array'], 'lessons.*.id' => ['required', 'uuid'], 'lessons.*.section_id' => ['nullable', 'uuid']]);
        $secs = array_column(DB::select('select id from course_sections where course_id = ? and deleted_at is null', [$id]), 'id');
        $less = array_column(DB::select('select id from course_lessons where course_id = ? and deleted_at is null', [$id]), 'id');
        if (array_diff($d['sections'], $secs) || array_diff(array_column($d['lessons'], 'id'), $less)
            || array_diff(array_filter(array_column($d['lessons'], 'section_id')), $secs)) {
            $this->fail('lessons', 'El orden incluye secciones o lecciones de otro curso.');
        }
        $me = (string) auth()->id();
        DB::transaction(function () use ($d, $me) {
            foreach (array_values($d['sections']) as $i => $s) {
                DB::update('update course_sections set sort_order = ?, updated_by = ? where id = ?', [$i + 1, $me, $s]);
            }
            foreach (array_values($d['lessons']) as $i => $l) {
                DB::update('update course_lessons set sort_order = ?, section_id = ?, updated_by = ? where id = ?', [$i + 1, $l['section_id'] ?? null, $me, $l['id']]);
            }
        });

        return response()->json(['data' => ['ok' => true]]);
    }

    public function participants(string $id): JsonResponse
    {
        $this->find($id);

        return response()->json(['data' => $this->participantRows($id)]);
    }

    private function participantRows(string $id): array
    {
        return DB::select(
            "select e.id, e.status, e.source, e.progress_pct, e.final_score, e.final_score_label, e.passed, e.active_seconds_total, e.last_activity_at, e.enrolled_at,
                    e.due_on, e.access_until, e.commitment_accepted_at, e.certificate_id, e.worker_id,
                    coalesce(w.full_name, cw.first_name || ' ' || cw.paternal_last_name, xl.first_name || ' ' || xl.last_name, u.full_name, 'Participante') as name,
                    p.name as position, a.name as area,
                    (select count(*) from quiz_attempts q where q.enrollment_id = e.id and q.deleted_at is null) as attempts
               from course_enrollments e left join workers w on w.id = e.worker_id left join contractor_workers cw on cw.id = e.contractor_worker_id
               left join external_learners xl on xl.id = e.external_learner_id left join users u on u.id = e.user_id
               left join positions p on p.id = w.current_position_id left join areas a on a.id = w.current_area_id
              where e.course_id = ? and e.deleted_at is null order by e.status = 'cancelled', name", [$id]);
    }

    public function enroll(Request $request, string $id): JsonResponse
    {
        $c = $this->find($id);
        $d = $request->validate([
            'worker_ids' => ['nullable', 'array', 'max:500'], 'worker_ids.*' => ['uuid'],
            'position_id' => ['nullable', 'uuid'], 'area_id' => ['nullable', 'uuid'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'], 'dry_run' => ['boolean'],
        ], ['due_on.after_or_equal' => 'La fecha límite no puede ser pasada.']);
        if (empty($d['worker_ids']) && empty($d['position_id']) && empty($d['area_id'])) {
            $this->fail('worker_ids', 'Elige a los trabajadores, un puesto o un área.');
        }
        if ($c->status !== 'published' && empty($d['dry_run'])) {
            throw new ApiProblem(409, 'conflict', 'Curso en borrador', 'Publica el curso antes de matricular.');
        }
        $where = ["w.deleted_at is null", "w.status = 'active'"];
        $bind = [];
        if (! empty($d['worker_ids'])) {
            $where[] = 'w.id in ('.implode(',', array_fill(0, count($d['worker_ids']), '?')).')';
            array_push($bind, ...$d['worker_ids']);
        }
        if (! empty($d['position_id'])) {
            $where[] = 'w.current_position_id = ?';
            $bind[] = $d['position_id'];
        }
        if (! empty($d['area_id'])) {
            $where[] = 'w.current_area_id = ?';
            $bind[] = $d['area_id'];
        }
        $matched = array_column(DB::select('select w.id from workers w where '.implode(' and ', $where), $bind), 'id');
        $already = array_column(DB::select('select worker_id from course_enrollments where course_id = ? and deleted_at is null and worker_id is not null', [$id]), 'worker_id');
        $todo = array_values(array_diff($matched, $already));
        if (! empty($d['dry_run'])) {
            return response()->json(['data' => ['matched' => count($matched), 'already' => count($matched) - count($todo), 'to_enroll' => count($todo)]]);
        }
        $res = Registry::resource('AUL', 'course_enrollments');
        $me = (string) auth()->id();
        DB::transaction(function () use ($todo, $res, $id, $d, $me) {
            foreach ($todo as $w) {
                $this->records->create($res, array_filter(['course_id' => $id, 'worker_id' => $w, 'source' => 'manual', 'enrolled_by' => $me, 'due_on' => $d['due_on'] ?? null, 'status' => 'enrolled'], fn ($v) => $v !== null));
            }
        });

        return response()->json(['data' => ['matched' => count($matched), 'already' => count($matched) - count($todo), 'enrolled' => count($todo)]], 201);
    }

    public function grades(string $id): JsonResponse
    {
        $c = $this->find($id);
        $exams = DB::select("select id, title, passing_score, max_attempts, coalesce((exam_config->>'weight')::numeric, 1) as weight from course_lessons
                              where course_id = ? and deleted_at is null and lesson_type = 'exam' order by sort_order, lesson_number", [$id]);
        $agg = DB::select(
            'select q.enrollment_id, q.lesson_id, count(*) as attempts, max(case when q.max_score > 0 then round(q.score * 100 / q.max_score, 2) end) as best_pct, bool_or(coalesce(q.passed, false)) as passed
               from quiz_attempts q join course_enrollments e on e.id = q.enrollment_id
              where e.course_id = ? and q.deleted_at is null group by q.enrollment_id, q.lesson_id', [$id]);
        $by = [];
        foreach ($agg as $a) {
            $by[$a->enrollment_id][$a->lesson_id] = ['attempts' => (int) $a->attempts, 'best_pct' => $a->best_pct === null ? null : (float) $a->best_pct, 'passed' => (bool) $a->passed];
        }
        $rows = array_map(fn ($p) => ['enrollment_id' => $p->id, 'name' => $p->name, 'position' => $p->position, 'status' => $p->status, 'final_score' => $p->final_score === null ? null : (float) $p->final_score,
            'passed' => $p->passed, 'exams' => (object) ($by[$p->id] ?? [])], $this->participantRows($id));
        $recent = DB::select(
            "select q.id, q.attempt_number, q.score, q.max_score, q.passed, q.submitted_at, q.status, l.title as lesson,
                    coalesce(w.full_name, xl.first_name || ' ' || xl.last_name, 'Participante') as name
               from quiz_attempts q join course_enrollments e on e.id = q.enrollment_id left join course_lessons l on l.id = q.lesson_id
               left join workers w on w.id = e.worker_id left join external_learners xl on xl.id = e.external_learner_id
              where e.course_id = ? and q.deleted_at is null order by q.submitted_at desc nulls last limit 100", [$id]);

        return response()->json(['data' => ['passing_score' => $c->passing_score === null ? null : (float) $c->passing_score, 'exams' => $exams, 'rows' => $rows, 'attempts' => $recent]]);
    }

    public function banks(Request $request): JsonResponse
    {
        $this->staff();
        $course = $request->query('course_id');

        return response()->json(['data' => $this->bankRows(is_string($course) && Str::isUuid($course) ? $course : null, ! $course)]);
    }

    public function trainers(): JsonResponse
    {
        $this->staff();
        $ctx = app(CompanyContext::class);
        $people = DB::select(
            "select distinct u.id, u.full_name as name, u.email from memberships m join roles r on r.id = m.role_id join users u on u.id = m.user_id
              where m.company_id = ? and m.status = 'active' and coalesce(r.base_role_code, r.code) = 'trainer' order by 2", [$ctx->companyId]);
        $perms = DB::select(
            'select t.id, t.trainer_user_id, t.course_id, c.code as course_code, c.title as course_title, t.can_upload_materials, t.can_edit_content, t.can_edit_assessments,
                    t.can_manage_question_banks, t.can_grade, t.can_enroll, t.can_view_reports, t.valid_from, t.valid_until
               from trainer_permissions t left join courses c on c.id = t.course_id where t.deleted_at is null order by c.code nulls first');
        $slots = DB::select(
            "select a.id, a.trainer_user_id, a.kind, a.weekday, to_char(a.start_time, 'HH24:MI') as start_time, to_char(a.end_time, 'HH24:MI') as end_time,
                    a.date_from, a.date_to, a.modality, s.name as site, a.reason
               from trainer_availability a left join sites s on s.id = a.site_id where a.deleted_at is null order by a.weekday, a.start_time");
        // Quien tiene permisos o franjas aunque su rol haya cambiado también se lista.
        $known = array_column($people, 'id');
        $extra = array_values(array_diff(array_unique(array_merge(array_column($perms, 'trainer_user_id'), array_column($slots, 'trainer_user_id'))), $known));
        if ($extra) {
            array_push($people, ...DB::select('select id, full_name as name, email from users where id in ('.implode(',', array_fill(0, count($extra), '?')).')', $extra));
        }

        return response()->json(['data' => array_map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'email' => $p->email,
            'permissions' => array_values(array_filter($perms, fn ($x) => $x->trainer_user_id === $p->id)),
            'availability' => array_values(array_filter($slots, fn ($x) => $x->trainer_user_id === $p->id)),
        ], $people)]);
    }

    public function addAvailability(Request $request): JsonResponse
    {
        $this->staff();
        $d = $request->validate([
            'trainer_user_id' => ['required', 'uuid'], 'weekday' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'modality' => ['required', 'in:in_person,virtual,both'], 'site_id' => ['nullable', 'uuid'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], ['end_time.after' => 'La hora de fin debe ser posterior a la de inicio.']);
        $me = (string) auth()->id();
        if ($d['trainer_user_id'] !== $me && ! in_array('aul.trainer_permissions.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Solo registras tu propia disponibilidad.');
        }
        if (DB::selectOne("select 1 as ok from trainer_availability where trainer_user_id = ? and kind = 'weekly' and weekday = ? and deleted_at is null
                             and start_time < ?::time and end_time > ?::time", [$d['trainer_user_id'], $d['weekday'], $d['end_time'], $d['start_time']])) {
            $this->fail('start_time', 'Esa franja se superpone con otra del mismo capacitador.');
        }
        $row = $this->records->create(Registry::resource('AUL', 'trainer_availability'), array_filter($d, fn ($v) => $v !== null) + ['kind' => 'weekly']);

        return response()->json(['data' => $row], 201);
    }

    public function recommendations(): JsonResponse
    {
        $this->staff();
        $funnel = DB::selectOne(
            "select count(*) as total, count(*) filter (where status = 'suggested') as suggested, count(*) filter (where sent_at is not null) as sent,
                    count(*) filter (where viewed_at is not null) as viewed, count(*) filter (where clicked_at is not null) as clicked,
                    count(*) filter (where status = 'converted') as converted, count(*) filter (where status = 'dismissed') as dismissed
               from course_recommendations where deleted_at is null");
        $items = DB::select(
            "select r.id, r.status, r.source, r.target_scope, r.reason_code, r.message, r.channel, r.sent_at, r.viewed_at, r.converted_at, r.dismissed_at, r.dismiss_reason, r.created_at,
                    c.code as course_code, c.title as course_title, coalesce(w.full_name, xl.first_name || ' ' || xl.last_name, r.buyer_company_name) as recipient,
                    p.name as position, a.name as area, rr.name as rule_name, u.full_name as recommended_by
               from course_recommendations r join courses c on c.id = r.course_id left join workers w on w.id = r.worker_id
               left join external_learners xl on xl.id = r.external_learner_id left join positions p on p.id = r.target_position_id left join areas a on a.id = r.target_area_id
               left join recommendation_rules rr on rr.id = r.rule_id left join users u on u.id = r.recommended_by
              where r.deleted_at is null order by r.status <> 'suggested', r.created_at desc limit 200");
        $rules = DB::select(
            "select r.id, r.code, r.name, r.rule_type, r.audience, r.channels, r.is_active, r.max_sends_per_person, r.send_period_days, r.last_run_at, r.last_run_count,
                    r.last_run_status, r.parameters, r.course_ids, r.requires_consent,
                    (select count(*) from course_recommendations x where x.rule_id = r.id and x.deleted_at is null) as sent_total
               from recommendation_rules r where r.deleted_at is null order by r.is_active desc, r.priority, r.name");
        foreach ($rules as $r) {
            $r->channels = is_string($r->channels) ? array_values(array_filter(explode(',', trim($r->channels, '{}')))) : $r->channels;
            $r->course_ids = is_string($r->course_ids) ? array_values(array_filter(explode(',', trim($r->course_ids, '{}')))) : ($r->course_ids ?? []);
            $r->parameters = json_decode($r->parameters ?? '{}', true) ?: (object) [];
        }

        return response()->json(['data' => ['funnel' => $funnel, 'items' => $items, 'rules' => $rules]]);
    }

    public function recommend(Request $request): JsonResponse
    {
        $this->staff();
        $d = $request->validate([
            'course_id' => ['required', 'uuid'], 'target_scope' => ['required', 'in:person,position,area'],
            'worker_id' => ['required_if:target_scope,person', 'nullable', 'uuid'], 'position_id' => ['required_if:target_scope,position', 'nullable', 'uuid'],
            'area_id' => ['required_if:target_scope,area', 'nullable', 'uuid'], 'message' => ['nullable', 'string', 'max:500'], 'channel' => ['nullable', 'in:in_app,email,whatsapp'],
        ], ['worker_id.required_if' => 'Elige a la persona.', 'position_id.required_if' => 'Elige el puesto.', 'area_id.required_if' => 'Elige el área.']);
        $c = $this->find($d['course_id']);
        if ($c->status !== 'published') {
            $this->fail('course_id', 'Solo se recomiendan cursos publicados.');
        }
        [$col, $val] = match ($d['target_scope']) { 'person' => ['w.id', $d['worker_id']], 'position' => ['w.current_position_id', $d['position_id']], default => ['w.current_area_id', $d['area_id']] };
        $workers = array_column(DB::select("select w.id from workers w where w.deleted_at is null and w.status = 'active' and {$col} = ?", [$val]), 'id');
        $enrolled = array_column(DB::select("select worker_id from course_enrollments where course_id = ? and deleted_at is null and status <> 'cancelled' and worker_id is not null", [$c->id]), 'worker_id');
        $approved = array_column(DB::select("select worker_id from training_certificates where course_id = ? and deleted_at is null and status = 'validated' and worker_id is not null
                                              and (expires_on is null or expires_on > current_date)", [$c->id]), 'worker_id');
        $res = Registry::resource('AUL', 'course_recommendations');
        $me = (string) auth()->id();
        $out = ['created' => 0, 'enrolled' => 0, 'approved' => 0, 'duplicated' => 0];
        DB::transaction(function () use ($workers, $enrolled, $approved, $c, $d, $res, $me, &$out) {
            foreach ($workers as $w) {
                if (in_array($w, $approved, true)) {
                    $out['approved']++;

                    continue;
                }
                if (in_array($w, $enrolled, true)) {
                    $out['enrolled']++;

                    continue;
                }
                $key = "worker:{$w}:course:{$c->id}:manual";
                if (DB::selectOne("select 1 as ok from course_recommendations where dedupe_key = ? and deleted_at is null and status not in ('expired', 'converted')", [$key])) {
                    $out['duplicated']++;

                    continue;
                }
                $this->records->create($res, array_filter([
                    'course_id' => $c->id, 'worker_id' => $w, 'source' => 'manual', 'recommended_by' => $me, 'target_scope' => $d['target_scope'],
                    'target_position_id' => $d['target_scope'] === 'position' ? $d['position_id'] : null, 'target_area_id' => $d['target_scope'] === 'area' ? $d['area_id'] : null,
                    'reason_code' => 'manual', 'message' => $d['message'] ?? null, 'channel' => $d['channel'] ?? 'in_app', 'status' => 'sent', 'sent_at' => now()->toIso8601String(), 'dedupe_key' => $key,
                ], fn ($v) => $v !== null));
                $out['created']++;
            }
        });

        return response()->json(['data' => $out + ['reached' => count($workers)]], 201);
    }

    public function approve(string $id): JsonResponse
    {
        $this->staff();
        $r = $this->recommendation($id);
        if ($r->status !== 'suggested') {
            throw new ApiProblem(409, 'conflict', 'Ya revisada', 'Esta recomendación ya se aprobó o se descartó.');
        }
        $row = $this->records->update(Registry::resource('AUL', 'course_recommendations'), $id, ['status' => 'sent', 'sent_at' => now()->toIso8601String(), 'recommended_by' => $r->recommended_by ?? (string) auth()->id()]);

        return response()->json(['data' => $row]);
    }

    public function dismiss(Request $request, string $id): JsonResponse
    {
        $this->staff();
        $r = $this->recommendation($id);
        if (in_array($r->status, ['converted', 'dismissed', 'expired'], true)) {
            throw new ApiProblem(409, 'conflict', 'Recomendación cerrada', 'Esta recomendación ya se cerró.');
        }
        $row = $this->records->update(Registry::resource('AUL', 'course_recommendations'), $id,
            array_filter(['status' => 'dismissed', 'dismissed_at' => now()->toIso8601String(), 'dismiss_reason' => mb_substr(trim((string) $request->input('reason')), 0, 500) ?: null], fn ($v) => $v !== null));

        return response()->json(['data' => $row]);
    }

    // ── apoyo ───────────────────────────────────────────────────────────────

    private function courseRules(Request $request, bool $creating): array
    {
        $d = $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:80'],
            'hours' => ['required', 'numeric', 'gt:0', 'max:500'],
            'grading_scale_id' => ['nullable', 'uuid'],
            'passing_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'sequential' => ['boolean'],
            'completion_commitment' => ['nullable', 'in:none,optional,mandatory'],
            'commitment_text' => ['nullable', 'string', 'max:2000'],
            'min_active_time_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'certificate_enabled' => ['boolean'],
            'certificate_validity_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'public_verification_enabled' => ['boolean'],
        ], [
            'title.required' => 'Escribe el nombre del curso.',
            'hours.required' => 'Indica las horas que acredita el curso.',
            'hours.gt' => 'Las horas deben ser mayores que cero.',
        ]);
        if (in_array($d['completion_commitment'] ?? 'none', ['optional', 'mandatory'], true) && trim((string) ($d['commitment_text'] ?? '')) === '') {
            $this->fail('commitment_text', 'Escribe el texto del compromiso de culminación.');
        }

        return array_filter($d, fn ($v) => $v !== null) + ['passing_score' => 70, 'completion_commitment' => 'none'];
    }

    private function bankRows(?string $course, bool $all = false): array
    {
        $banks = DB::select(
            'select b.id, b.code, b.name, b.topic, b.description, b.status, b.course_id, c.code as course_code, c.title as course_title
               from question_banks b left join courses c on c.id = b.course_id
              where b.deleted_at is null'.($all ? '' : ' and (b.course_id is null or b.course_id = ?)').' order by b.course_id nulls first, b.code', $all ? [] : [$course]);
        $counts = DB::select("select question_bank_id, question_type, coalesce(difficulty, 'none') as difficulty, count(*) as n from questions
                               where deleted_at is null and status <> 'archived' group by 1, 2, 3");
        foreach ($banks as $b) {
            $b->questions = 0;
            $b->by_type = [];
            $b->by_difficulty = [];
            foreach ($counts as $c) {
                if ($c->question_bank_id === $b->id) {
                    $b->questions += (int) $c->n;
                    $b->by_type[$c->question_type] = ($b->by_type[$c->question_type] ?? 0) + (int) $c->n;
                    $b->by_difficulty[$c->difficulty] = ($b->by_difficulty[$c->difficulty] ?? 0) + (int) $c->n;
                }
            }
            $b->by_type = (object) $b->by_type;
            $b->by_difficulty = (object) $b->by_difficulty;
            $b->used_in = (int) DB::selectOne('select count(*) as n from course_lessons where question_bank_id = ? and deleted_at is null', [$b->id])->n;
        }

        return $banks;
    }

    private function bankCode(string $courseCode): string
    {
        $base = 'BC-'.preg_replace('/^CUR-/', '', $courseCode);
        $code = $base;
        for ($i = 2; DB::selectOne('select 1 as ok from question_banks where code = ? and deleted_at is null', [$code]); $i++) {
            $code = "{$base}-{$i}";
        }

        return $code;
    }

    private function can(): array
    {
        $p = Permissions::effective();
        $has = fn (string $x) => in_array($x, $p, true);
        $trainer = app(CompanyContext::class)->roleCode === 'trainer';

        return [
            'create_course' => $has('aul.courses.create') && ! $trainer, 'update_course' => $has('aul.courses.update'), 'publish' => $has('aul.courses.publish'),
            'edit_content' => $has('aul.course_lessons.create') || $has('aul.course_lessons.update'), 'sections' => $has('aul.course_sections.create'),
            'enroll' => $has('aul.course_enrollments.create'), 'grade' => $has('aul.quiz_attempts.update'),
            'banks' => $has('aul.question_banks.create'), 'questions' => $has('aul.questions.create'),
            'trainers' => $has('aul.trainer_permissions.update') || $has('aul.trainer_permissions.create'), 'availability' => $has('aul.trainer_availability.create'),
            'recommend' => $has('aul.course_recommendations.create'), 'approve' => $has('aul.course_recommendations.update'), 'rules' => $has('aul.recommendation_rules.create'),
            'extend' => $has('aul.course_enrollments.update') && TrainerAccess::can(null, 'can_enroll'), 'reset' => $has('aul.quiz_attempts.update'),
            'import' => $has('aul.questions.import'), 'scales' => $has('aul.grading_scales.update') && ! $trainer, 'time' => $has('aul.learning_activity_sessions.read'),
            'run_rules' => $has('aul.recommendation_rules.generate'),
        ];
    }

    /** El capacitador solo ve los cursos que tiene asignados (RN-AUL-03). */
    private function trainerFilter(string $col): array
    {
        if (app(CompanyContext::class)->roleCode !== 'trainer') {
            return ['', []];
        }

        return ["and exists (select 1 from trainer_permissions tp where tp.course_id = {$col} and tp.trainer_user_id = ? and tp.deleted_at is null)", [(string) auth()->id()]];
    }

    private function staff(): void
    {
        if (in_array(app(CompanyContext::class)->roleCode, self::LEARNERS, true)) {
            throw ApiProblem::forbidden('Tus cursos están en «Mis cursos».');
        }
    }

    private function find(string $id): object
    {
        $this->staff();
        $f = $this->trainerFilter('c.id');
        $c = Str::isUuid($id) ? DB::selectOne(
            "select c.*, g.name as scale_name, g.scale_type, g.min_value as scale_min, g.max_value as scale_max, g.passing_value as scale_passing
               from courses c left join grading_scales g on g.id = c.grading_scale_id
              where c.id = ? and c.deleted_at is null and c.company_id is not null {$f[0]}", [$id, ...$f[1]]) : null;

        return $c ?? throw ApiProblem::notFound('El curso no existe, es de otra empresa o no lo tienes asignado.');
    }

    private function recommendation(string $id): object
    {
        $r = Str::isUuid($id) ? DB::selectOne('select * from course_recommendations where id = ? and deleted_at is null', [$id]) : null;

        return $r ?? throw ApiProblem::notFound('La recomendación no existe o es de otra empresa.');
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
