<?php

// EMO clínico (M-EMO SCR-EMO-03/05): el médico registra el resultado en la base médica con contraseña confirmada;
// a la base principal solo pasa la aptitud; todo acceso queda registrado; otros roles no ven datos clínicos.

use App\Core\Auth\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Storage::fake('local');
    DB::connection('medical')->beginTransaction();
    // El usuario Esencial atiende también como médico ocupacional de Huaylas (solo durante la prueba).
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [ESENCIAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $role = DB::selectOne("select id from roles where account_id is null and code = 'occupational_doctor'")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);
    DB::select("select set_config('app.company_id', '', false)");
});

afterEach(function () {
    DB::connection('medical')->rollBack();
});

function emoOrder(string $workerId): string
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $id = DB::selectOne("insert into medical_exam_orders (company_id, worker_id, exam_type, clinic_name, scheduled_at, status) values (?, ?, 'periodic', 'Clínica Andina', now(), 'attended') returning id",
        [HUAYLAS_ID, $workerId])->id;
    DB::select("select set_config('app.company_id', '', false)");

    return $id;
}

it('registra el resultado clínico con re-autenticación, publica solo la aptitud y deja historia y registros', function () {
    $sst = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '63636363', 'first_name' => 'Rosa', 'paternal_last_name' => 'Audio'], $sst)->json('data.id');
    $order = emoOrder($w);

    $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    $this->getJson("/api/v1/emo/orders/{$order}/clinical", $doc)->assertStatus(403)->assertJsonPath('type', 'reauth_required');
    $this->postJson('/api/v1/emo/reauth', ['password' => 'otra'], $doc)->assertStatus(422);
    $r = $this->postJson('/api/v1/emo/reauth', ['password' => DEMO_PASSWORD], $doc)->assertOk()->json('data.reauth_id');
    $m = $doc + ['X-Medical-Reauth' => $r];

    $this->getJson("/api/v1/emo/orders/{$order}/clinical", $m)->assertOk()->assertJsonPath('data.exam', null)
        ->assertJsonPath('data.order.worker', 'Rosa Audio')->assertJsonPath('data.can_issue_fitness', true);

    $body = ['exam_on' => now()->toDateString(), 'examining_doctor_name' => 'Dra. Salud', 'examining_doctor_cmp' => '12345',
        'results' => [['exam' => 'Audiometría', 'result' => 'Hipoacusia leve bilateral'], ['exam' => 'Espirometría', 'result' => 'Normal']],
        'observations' => 'Exposición a ruido', 'diagnoses' => [['code' => 'H83.3', 'description' => 'Efectos del ruido sobre el oído interno']],
        'recommendations' => 'Protección auditiva doble', 'fitness_result' => 'fit_with_restrictions'];
    // Validaciones: CIE-10, restricciones y vigencia.
    $this->putJson("/api/v1/emo/orders/{$order}/clinical", ['diagnoses' => [['code' => 'XX']]] + $body, $m)->assertStatus(422);
    $this->putJson("/api/v1/emo/orders/{$order}/clinical", $body, $m)->assertStatus(422)->assertJsonValidationErrors('work_restrictions');
    $body += ['work_restrictions' => 'No trabajar en zonas con ruido > 85 dB sin protección doble'];
    // RN-EMO-05: la vigencia no puede pasar la periodicidad del protocolo (el médico puede fijar una menor).
    $this->putJson("/api/v1/emo/orders/{$order}/clinical", $body + ['fitness_valid_until' => now()->addYears(10)->toDateString()], $m)->assertStatus(422)->assertJsonValidationErrors('fitness_valid_until');
    $body['fitness_valid_until'] = now()->addYear()->toDateString();
    $exam = $this->putJson("/api/v1/emo/orders/{$order}/clinical", $body, $m)->assertCreated()->assertJsonPath('data.fitness_published', true)->json('data.id');

    $pdf = UploadedFile::fake()->createWithContent('audiometria.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    $file = $this->post("/api/v1/emo/exams/{$exam}/files", ['file_kind' => 'audiometry', 'file' => $pdf], $m)->assertCreated()->json('data.id');
    // El archivo queda cifrado en el disco médico; la descarga lo devuelve legible.
    $stored = Storage::disk('local')->allFiles('medical');
    expect($stored)->toHaveCount(1)->and(Storage::disk('local')->get($stored[0]))->not->toContain('%PDF');
    expect($this->get("/api/v1/emo/files/{$file}", $m)->assertOk()->getContent())->toStartWith('%PDF');

    $show = $this->getJson("/api/v1/emo/orders/{$order}/clinical", $m)->assertOk();
    expect($show->json('data.exam.diagnoses.0.code'))->toBe('H83.3')
        ->and($show->json('data.exam.results.0.exam'))->toBe('Audiometría')
        ->and($show->json('data.exam.files'))->toHaveCount(1)
        ->and($show->json('data.order.status'))->toBe('fitness_issued');

    $hist = $this->getJson("/api/v1/emo/workers/{$w}/history", $m)->assertOk();
    expect($hist->json('data.exams'))->toHaveCount(1)->and($hist->json('data.fitness.status'))->toBe('fit_with_restrictions');

    // Base médica: datos clínicos cifrados y accesos registrados con la clave de re-autenticación.
    $med = DB::connection('medical');
    $med->select("select set_config('app.company_id', ?, false), set_config('app.reauth_session_id', ?, false)", [HUAYLAS_ID, $r]);
    $row = $med->selectOne('select results_summary, diagnoses::text as dx, recommendations from medical_exams where id = ?', [$exam]);
    expect($row->results_summary)->not->toContain('Hipoacusia')->and($row->dx)->not->toContain('H83')->and($row->recommendations)->not->toContain('auditiva');
    expect($med->table('medical_access_logs')->where('reauth_session_id', $r)->count())->toBeGreaterThanOrEqual(6);
    $med->select("select set_config('app.company_id', '', false), set_config('app.reauth_session_id', '', false)");

    // Base principal: solo aptitud y restricción laboral; los accesos quedan en sensitive_access_logs.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $fit = DB::selectOne('select status, work_restrictions, valid_until from fitness_status where worker_id = ? and is_current', [$w]);
    expect($fit->status)->toBe('fit_with_restrictions')->and($fit->work_restrictions)->toContain('ruido');
    expect(DB::selectOne('select medical_exam_ref from medical_exam_orders where id = ?', [$order])->medical_exam_ref)->toBe($exam);
    expect(DB::selectOne("select count(*) as n from sensitive_access_logs where worker_id = ? and result = 'granted' and reauth_session_id = ?", [$w, $r])->n)->toBeGreaterThanOrEqual(5);
    DB::select("select set_config('app.company_id', '', false)");

    // Corregir sin cambiar la aptitud no publica otra aptitud.
    $this->putJson("/api/v1/emo/orders/{$order}/clinical", ['observations' => 'Control en 6 meses'] + $body, $m)->assertOk()->assertJsonPath('data.fitness_published', false);

    // El Jefe SST (sin rol médico) no ve resultados clínicos ni con una clave ajena.
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->getJson("/api/v1/emo/orders/{$order}/clinical", $sst + ['X-Medical-Reauth' => $r])->assertStatus(403);
    $this->getJson("/api/v1/emo/workers/{$w}/history", $sst + ['X-Medical-Reauth' => $r])->assertStatus(403);
    $this->get("/api/v1/emo/files/{$file}", $sst + ['X-Medical-Reauth' => $r])->assertStatus(403);
});

it('no registra resultados en una orden anulada', function () {
    $sst = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '64646464', 'first_name' => 'Luis', 'paternal_last_name' => 'Anulado'], $sst)->json('data.id');
    $order = emoOrder($w);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update medical_exam_orders set status = 'cancelled', cancel_reason = 'Duplicada' where id = ?", [$order]);
    DB::select("select set_config('app.company_id', '', false)");

    $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    $r = $this->postJson('/api/v1/emo/reauth', ['password' => DEMO_PASSWORD], $doc)->json('data.reauth_id');
    $this->putJson("/api/v1/emo/orders/{$order}/clinical", ['exam_on' => now()->toDateString(), 'fitness_result' => 'pending'], $doc + ['X-Medical-Reauth' => $r])->assertStatus(409);
});

it('la versión completa del registro de EMO solo la descarga el médico con su contraseña confirmada', function () {
    $sst = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '63636399', 'first_name' => 'Raúl', 'paternal_last_name' => 'Registro'], $sst)->json('data.id');
    $order = emoOrder($w);
    $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    $r = $this->postJson('/api/v1/emo/reauth', ['password' => DEMO_PASSWORD], $doc)->json('data.reauth_id');
    $m = $doc + ['X-Medical-Reauth' => $r];
    $this->putJson("/api/v1/emo/orders/{$order}/clinical", ['exam_on' => now()->toDateString(), 'examining_doctor_name' => 'Dra. Salud', 'examining_doctor_cmp' => '12345',
        'diagnoses' => [['code' => 'H83.3', 'description' => 'Ruido']], 'fitness_result' => 'fit', 'fitness_valid_until' => now()->addYear()->toDateString()], $m)->assertCreated();
    $q = '?from='.now()->startOfYear()->toDateString().'&to='.now()->toDateString();

    // El Jefe SST no la genera; el médico sin contraseña confirmada tampoco.
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->get('/api/v1/reg/emo/medical.xlsx'.$q, $sst + ['Accept' => 'application/json'])->assertStatus(403);
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->get('/api/v1/reg/emo/medical.xlsx'.$q, $doc + ['Accept' => 'application/json'])->assertStatus(403)->assertJsonPath('type', 'reauth_required');
    $res = $this->get('/api/v1/reg/emo/medical.xlsx'.$q, $m + ['Accept' => 'application/json'])->assertOk();
    expect($res->headers->get('content-type'))->toContain('spreadsheetml');
    $zip = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($zip, $res->getContent());
    $z = new ZipArchive();
    $z->open($zip);
    expect($z->getFromName('xl/worksheets/sheet1.xml').$z->getFromName('xl/sharedStrings.xml'))->toContain('H83.3');
    expect(DB::selectOne("select count(*) as n from sensitive_access_logs where resource = 'medical_export' and action = 'export'")->n)->toBeGreaterThan(0);
});

it('un resultado que cumple el criterio del parámetro abre caso en el programa de vigilancia del puesto (M-VMD §7)', function () {
    $sst = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(65000000, 65999999), 'first_name' => 'Iván', 'paternal_last_name' => 'Ruido'], $sst)->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $pos = DB::selectOne("insert into positions (company_id, code, name) values (?, ?, 'Operador de molino') returning id", [HUAYLAS_ID, 'MOL-'.random_int(1000, 999999)])->id;
    DB::update('update workers set current_position_id = ? where id = ?', [$pos, $w]);
    DB::select("select set_config('app.company_id', '', false)");
    companyParamForTests(HUAYLAS_ID, 'vmd_auto_case_fitness', '["fit_with_restrictions"]', 'json');
    $order = emoOrder($w);

    $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    $m = $doc + ['X-Medical-Reauth' => $this->postJson('/api/v1/emo/reauth', ['password' => DEMO_PASSWORD], $doc)->assertOk()->json('data.reauth_id')];
    $vm = $doc + ['X-Medical-Reauth' => $this->postJson('/api/v1/vmd/reauth', ['password' => DEMO_PASSWORD], $doc)->assertOk()->json('data.reauth_id')];
    $p = $this->postJson('/api/v1/vmd/programs', ['code' => 'VMD-AUTO', 'name' => 'Conservación auditiva molino', 'target_risk' => 'noise', 'target_positions' => [$pos], 'status' => 'active'], $vm)
        ->assertCreated()->json('data.id');
    $db = DB::connection('medical');
    $db->update("update surveillance_programs set status = 'active', target_positions = ?::uuid[] where id = ?", ['{'.$pos.'}', $p]);

    $this->putJson("/api/v1/emo/orders/{$order}/clinical", ['exam_on' => now()->toDateString(), 'fitness_result' => 'fit_with_restrictions',
        'work_restrictions' => 'Sin ruido mayor a 85 dB', 'fitness_valid_until' => now()->addMonths(6)->toDateString()], $m)->assertCreated();
    expect((int) $db->selectOne("select count(*) as n from surveillance_cases where program_id = ? and worker_id = ? and status = 'open'", [$p, $w])->n)->toBe(1);
});
