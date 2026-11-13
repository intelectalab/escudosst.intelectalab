<?php

// Avisos §10 del aula virtual (M-AUL) y de la venta de cursos (M-VTA): matrícula, curso por vencer, participante
// rezagado, curso completado, recomendaciones por aprobar, pedido por vencer, pedido pagado, cupos disponibles y
// pago sin matrícula.

use App\Core\Auth\Permissions;
use App\Modules\AUL\AulAlerts;
use App\Modules\AUL\LearnerMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode, source from feature_flags where company_id = ? and module_code in ('VTA', 'AUL')", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('VTA', 'AUL')", [HUAYLAS_ID]);
    app('auth')->forgetGuards();
    Permissions::flush();
    Mail::fake();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->me = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    foreach ($this->flags as $f) {
        $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
    }
    Permissions::flush();
});

function aulvtaRun(): int
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $n = app(AulAlerts::class)->forCompany(DB::connection(), HUAYLAS_ID, true);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);

    return $n;
}

function aulvtaCount(string $event, string $subject, ?string $user = null, ?string $worker = null): int
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);

    return (int) DB::selectOne('select count(*) as n from notifications where event_code = ? and subject_id = ?'
        .($user ? ' and recipient_user_id = ?' : '').($worker ? ' and recipient_worker_id = ?' : ''), array_values(array_filter([$event, $subject, $user, $worker])))->n;
}

/** Usuario «profesional» como capacitador en Huaylas. */
function aulvtaTrainer(): string
{
    $u = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    if (! DB::selectOne('select 1 as ok from memberships where company_id = ? and user_id = ? and deleted_at is null', [HUAYLAS_ID, $u])) {
        DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, (select id from roles where account_id is null and code = 'trainer'), 'active', now())",
            [HUAYLAS_ID, $u]);
    }

    return $u;
}

it('avisa la matrícula, el curso por vencer, al rezagado y el curso completado', function () {
    $h = $this->h;
    $course = $this->postJson('/api/v1/aul/admin/courses', ['title' => 'Orden y limpieza AV', 'hours' => 1], $h)->assertCreated()->json('data.id');
    $sec = $this->postJson("/api/v1/aul/admin/courses/{$course}/sections", ['title' => 'Única'], $h)->assertCreated()->json('data.id');
    $lesson = $this->postJson("/api/v1/aul/admin/courses/{$course}/lessons", ['section_id' => $sec, 'lesson_type' => 'text', 'title' => 'Las 5S', 'content_html' => '<p>Clasificar</p>'], $h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/aul/admin/courses/{$course}/publish", ['publish' => true], $h)->assertOk();

    $trainer = aulvtaTrainer();
    DB::insert('insert into trainer_permissions (company_id, trainer_user_id, course_id, can_grade) values (?, ?, ?, true)', [HUAYLAS_ID, $trainer, $course]);
    // Jefa del área con cuenta (el usuario «profesional») y un trabajador de esa área.
    $worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Rosa', 'paternal_last_name' => 'Prueba'], $h)
        ->assertCreated()->json('data.id');
    $boss = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 79999999), 'first_name' => 'Jefa', 'paternal_last_name' => 'Prueba'], $h)
        ->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $area = DB::selectOne('select id from areas where company_id = ? and deleted_at is null limit 1', [HUAYLAS_ID])->id;
    DB::update('update workers set user_id = ? where id = ?', [$trainer, $boss]);
    DB::update('update areas set manager_worker_id = ? where id = ?', [$boss, $area]);
    DB::update('update workers set current_area_id = ? where id = ?', [$area, $worker]);

    // Matrícula → participante (campana del trabajador).
    $this->postJson("/api/v1/aul/admin/courses/{$course}/enroll", ['worker_ids' => [$worker], 'due_on' => now()->addDays(3)->toDateString()], $h)->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $enr = DB::selectOne('select id from course_enrollments where course_id = ? and worker_id = ?', [$course, $worker])->id;
    expect(aulvtaCount('aul.enrolled', $enr, null, $worker))->toBe(1);

    // Revisión: curso por vencer (vence en 3 días, aviso 7 días antes) → participante y jefa del área; no se repite.
    aulvtaRun();
    expect(aulvtaCount('aul.course_due', $enr, null, $worker))->toBe(1)
        ->and(aulvtaCount('aul.course_due', $enr, $trainer))->toBe(1)
        ->and(aulvtaCount('aul.enrolled', $enr))->toBe(1);
    aulvtaRun();
    expect(aulvtaCount('aul.course_due', $enr))->toBe(2);

    // Sin actividad hace 10 días → capacitador y Jefe SST.
    DB::update("update course_enrollments set last_activity_at = now() - interval '10 days' where id = ?", [$enr]);
    aulvtaRun();
    expect(aulvtaCount('aul.inactive_learner', $enr, $trainer))->toBe(1)->and(aulvtaCount('aul.inactive_learner', $enr, $this->me))->toBe(1);
    $body = DB::selectOne("select body from notifications where event_code = 'aul.inactive_learner' and subject_id = ? limit 1", [$enr])->body;
    expect($body)->toContain('Rosa')->toContain('10 días');

    // Completa la única lección → participante y capacitador.
    $this->postJson("/api/v1/aul/enrollments/{$enr}/lessons/{$lesson}/done", [], $h)->assertOk()->assertJsonPath('data.status', 'completed');
    expect(aulvtaCount('aul.course_completed', $enr, null, $worker))->toBe(1)->and(aulvtaCount('aul.course_completed', $enr, $trainer))->toBe(1);
    aulvtaRun();
    expect(aulvtaCount('aul.course_completed', $enr))->toBe(2);
});

it('resume una vez al día las recomendaciones por aprobar al Jefe SST', function () {
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $course = DB::selectOne("insert into courses (company_id, code, title, hours, status, published_at) values (?, 'AV-REC', 'Uso de extintores AV', 1, 'published', now()) returning id", [HUAYLAS_ID])->id;
    $worker = DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status) values (?, ?, decode(md5(random()::text), 'hex'), 'Reco', 'Prueba', 'active') returning id",
        [HUAYLAS_ID, (string) random_int(50000000, 59999999)])->id;
    DB::insert("insert into course_recommendations (company_id, course_id, worker_id, source, target_scope, recommended_by, channel, status, dedupe_key)
                values (?, ?, ?, 'manual', 'person', ?, 'in_app', 'suggested', ?)", [HUAYLAS_ID, $course, $worker, $this->me, 'av-rec-'.$worker]);

    aulvtaRun();
    aulvtaRun();
    $n = DB::select("select body from notifications where event_code = 'aul.recommendations_pending' and recipient_user_id = ? and dedupe_key like ?",
        [$this->me, 'aul.recommendations_pending:'.now('America/Lima')->format('Y-m-d').':%']);
    expect($n)->toHaveCount(1)->and($n[0]->body)->toContain('esperando tu aprobación');
});

it('avisa por correo el pedido por vencer, el pedido pagado, los cupos y el pago sin matrícula', function () {
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $course = DB::selectOne("insert into courses (company_id, code, title, hours, status, published_at) values (?, 'AV-VTA', 'Primeros auxilios AV', 4, 'published', now()) returning id", [HUAYLAS_ID])->id;
    DB::insert("insert into course_offers (company_id, course_id, slug, title, list_price, is_public, status, allows_company_purchase, access_days)
                values (?, ?, 'auxilios-av', 'Primeros auxilios AV', 100, true, 'published', true, 30)", [HUAYLAS_ID, $course]);
    $url = '/api/v1/public/courses/'.HUAYLAS_ID.'/auxilios-av/order';
    $person = $this->postJson($url, ['buyer_type' => 'person', 'document_type' => 'DNI', 'document_number' => '72727272', 'first_name' => 'Luz', 'last_name' => 'Quispe',
        'email' => 'luz.av@example.com', 'privacy_consent' => true])->assertCreated()->json('data.order_number');
    $company = $this->postJson($url, ['buyer_type' => 'company', 'legal_name' => 'Cliente AV SAC', 'ruc' => '20512345671', 'email' => 'compras.av@example.com', 'seats' => 3,
        'privacy_consent' => true])->assertCreated()->json('data.order_number');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $pid = DB::selectOne('select id from orders where order_number = ?', [$person])->id;
    $cid = DB::selectOne('select id from orders where order_number = ?', [$company])->id;

    // Pedido que vence en 2 horas (aviso 1440 minutos antes) → correo al comprador, una sola vez.
    DB::update("update orders set expires_at = now() + interval '2 hours' where id = ?", [$pid]);
    aulvtaRun();
    aulvtaRun();
    Mail::assertSent(LearnerMail::class, fn ($m) => $m->hasTo('luz.av@example.com') && $m->title === 'Tu pedido vence pronto' && str_contains($m->body, $person));
    expect(Mail::sent(LearnerMail::class, fn ($m) => $m->title === 'Tu pedido vence pronto')->count())->toBe(1);

    // Pago registrado: persona → pedido pagado con acceso; empresa → cupos disponibles. Sin correo aparte de «matrícula».
    $this->postJson("/api/v1/vta/orders/{$pid}/pay", ['method' => 'yape', 'reference' => 'AV-0001'], $this->h)->assertOk();
    $this->postJson("/api/v1/vta/orders/{$cid}/pay", ['method' => 'transfer', 'reference' => 'AV-0002'], $this->h)->assertOk();
    Mail::assertSent(LearnerMail::class, fn ($m) => $m->hasTo('luz.av@example.com') && $m->title === 'Pedido pagado: ya tienes acceso al curso' && str_contains($m->body, 'Primeros auxilios AV'));
    Mail::assertSent(LearnerMail::class, fn ($m) => $m->hasTo('compras.av@example.com') && $m->title === 'Cupos disponibles para tu empresa' && str_contains($m->body, '3 cupo(s)'));
    Mail::assertNotSent(LearnerMail::class, fn ($m) => $m->title === 'Te matricularon en un curso');

    // La revisión no vuelve a avisar la matrícula por correo; si la línea pagada se queda sin matrícula, avisa al Jefe SST.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $item = DB::selectOne('select id from order_items where order_id = ?', [$pid])->id;
    DB::update('update course_enrollments set deleted_at = now() where order_item_id = ?', [$item]);
    aulvtaRun();
    expect(aulvtaCount('vta.paid_without_enrollment', $item, $this->me))->toBe(1);
    expect(Mail::sent(LearnerMail::class, fn ($m) => $m->hasTo('luz.av@example.com'))->count())->toBe(2);
});
