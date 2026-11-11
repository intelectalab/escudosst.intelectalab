<?php

// Cese del trabajador → orden de EMO de retiro (M-TRB §7 «Cese» paso 3, M-EMO RN-EMO-08): desde la ficha y desde la
// carga masiva «Cesar»; sin duplicar; aviso al médico ocupacional / personal de salud; respeta `emo_on_termination`.

use App\Core\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function exitH(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

/** El usuario del plan Profesional como médico ocupacional de la empresa de prueba. */
function exitDoctor(): string
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    $role = DB::selectOne("select id from roles where account_id is null and code = 'occupational_doctor'")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);

    return $uid;
}

function exitWorker($t, array $h, string $doc): string
{
    return $t->postJson('/api/v1/records/TRB/workers', ['document_number' => $doc, 'first_name' => 'Iván', 'paternal_last_name' => 'Retiro', 'hire_on' => '2024-01-10'], $h)
        ->assertCreated()->json('data.id');
}

function exitOrders(string $worker): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);

    return DB::select("select id, exam_type, status, scheduled_at, requested_by from medical_exam_orders where worker_id = ? and exam_type = 'exit' and deleted_at is null", [$worker]);
}

it('genera la orden de EMO de retiro al cesar desde la ficha y avisa al área de salud', function () {
    $h = exitH();
    $doctor = exitDoctor();
    $w = exitWorker($this, $h, '61616161');
    expect(exitOrders($w))->toBe([]);

    $this->patchJson("/api/v1/records/TRB/workers/{$w}", ['status' => 'terminated', 'termination_on' => '2026-11-05', 'termination_reason' => 'Fin de contrato'], $h)->assertOk();
    $orders = exitOrders($w);
    expect($orders)->toHaveCount(1)
        ->and($orders[0]->status)->toBe('scheduled')
        ->and($orders[0]->scheduled_at)->toBeNull();

    $n = DB::selectOne("select recipient_user_id, title, body, link, dedupe_key from notifications where event_code = 'emo.exit_order_created' and subject_id = ?", [$orders[0]->id]);
    expect($n->recipient_user_id)->toBe($doctor)
        ->and($n->link)->toBe('/m/emo')
        ->and($n->body)->toContain('Iván Retiro')->toContain('05/11/2026')
        ->and($n->dedupe_key)->toBe("emo.exit_order.{$orders[0]->id}.{$doctor}");

    // Se ve en EMO (órdenes de examen) como examen de retiro.
    $list = collect($this->getJson('/api/v1/records/EMO/medical_exam_orders?per_page=100', $h)->assertOk()->json('data'));
    expect($list->firstWhere('id', $orders[0]->id)['exam_type'] ?? null)->toBe('exit');

    // Editar de nuevo la ficha del cesado no duplica la orden.
    $this->patchJson("/api/v1/records/TRB/workers/{$w}", ['termination_reason' => 'Fin de contrato a plazo fijo'], $h)->assertOk();
    expect(exitOrders($w))->toHaveCount(1);
});

it('genera la orden de retiro con la carga masiva «Cesar» y no la duplica si ya existe', function () {
    $h = exitH();
    $a = exitWorker($this, $h, '62626262');
    $b = exitWorker($this, $h, '63636363');
    // B ya tiene una orden de retiro abierta.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into medical_exam_orders (company_id, worker_id, exam_type, status) values (?, ?, 'exit', 'scheduled')", [HUAYLAS_ID, $b]);

    $head = ['Tipo de documento', 'Número de documento', 'Nombres', 'Apellido paterno', 'Apellido materno', 'Fecha de nacimiento', 'Sexo', 'Celular', 'Correo',
        'Fecha de ingreso', 'Tipo de vínculo', 'Sede', 'Área', 'Puesto', 'Código de empleado', 'Fecha de cese', 'Motivo de cese'];
    $rows = [
        ['DNI', '62626262', '', '', '', '', '', '', '', '', '', '', '', '', '', '05/11/2026', 'Renuncia'],
        ['DNI', '63636363', '', '', '', '', '', '', '', '', '', '', '', '', '', '05/11/2026', 'Renuncia'],
    ];
    $file = UploadedFile::fake()->createWithContent('trabajadores.xlsx',
        Xlsx::build('Trabajadores', [['EscudoSST · Plantilla de trabajadores · versión TRB-W1'], ['Instrucciones'], ['Sedes'], ['Puestos'], $head, ...$rows], 5));
    $this->post('/api/v1/trb/imports', ['file' => $file, 'mode' => 'terminate'], $h)->assertOk()->assertJsonPath('data.terminated', 2);

    expect(exitOrders($a))->toHaveCount(1)->and(exitOrders($b))->toHaveCount(1);
});

it('no genera la orden si la empresa desactivó emo_on_termination', function () {
    $h = exitH();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert(
        "insert into legal_parameters (company_id, key, value, value_type, description, source, valid_from, to_confirm)
         values (?, 'emo_on_termination', 'false', 'boolean', 'Prueba', 'test', date '2020-01-01', false)", [HUAYLAS_ID]);
    $w = exitWorker($this, $h, '64646464');
    $this->patchJson("/api/v1/records/TRB/workers/{$w}", ['status' => 'terminated', 'termination_on' => '2026-11-05'], $h)->assertOk();
    expect(exitOrders($w))->toBe([]);
});
