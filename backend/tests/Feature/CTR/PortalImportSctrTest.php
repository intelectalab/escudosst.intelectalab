<?php

// Portal del contratista (M-CTR SCR-CTR-04): carga de trabajadores desde Excel, SCTR del contratista (M-SCT RN-SCT-03/06/08)
// y avisos de documentos por vencer (RN-CTR-03).

use App\Core\Support\Xlsx;
use App\Modules\CTR\ExpiryAlerts;
use App\Modules\USR\Mail\AccountInvitationMail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $GLOBALS['ctrx_emails'] = [];
    Mail::fake();
});

afterEach(function () {
    DB::rollBack();
    $platform = DB::connection('pgsql_owner');
    foreach ($GLOBALS['ctrx_emails'] as $email) {
        $u = $platform->selectOne('select id from users where lower(email) = ?', [$email]);
        if ($u) {
            $platform->delete('delete from personal_access_tokens where tokenable_id = ?', [$u->id]);
            $platform->delete('delete from users where id = ?', [$u->id]);
        }
    }
});

function ctrxH(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

/** Invita a un contratista, acepta la invitación y devuelve [contractor_id, fn () => encabezados del administrador]. */
function ctrxPortal(string $ruc = '20100070970'): array
{
    $email = 'ctrx.'.Str::lower(Str::random(8)).'@escudo.test';
    $GLOBALS['ctrx_emails'][] = $email;
    $res = test()->postJson('/api/v1/ctr/invitations', ['ruc' => $ruc, 'legal_name' => 'Montajes Andinos SAC', 'admin_email' => $email, 'admin_name' => 'Rosa Admin', 'risk_level' => 'high'], ctrxH())->assertCreated();
    $url = null;
    Mail::assertSent(AccountInvitationMail::class, function ($m) use (&$url) {
        $url = $m->url;

        return true;
    });
    test()->postJson('/api/v1/auth/invitations/'.basename((string) $url).'/accept', ['password' => DEMO_PASSWORD, 'password_confirmation' => DEMO_PASSWORD])->assertOk();

    return [$res->json('data.contractor_id'), fn () => authHeaders(tokenFor($email), HUAYLAS_ID)];
}

function ctrxSheet(array $rows, string $version = 'CTR-W1'): UploadedFile
{
    $head = ['Tipo de documento', 'Número de documento', 'Nombres', 'Apellido paterno', 'Apellido materno', 'Puesto u oficio', 'Celular'];
    $bytes = Xlsx::build('Trabajadores', [["EscudoSST · Plantilla de trabajadores del contratista · versión {$version}"], ['Instrucciones'], ['Contratista'], $head, ...$rows], 4);

    return UploadedFile::fake()->createWithContent('trabajadores.xlsx', $bytes);
}

it('carga trabajadores del contratista desde Excel con validación por fila', function () {
    [$cid, $ch] = ctrxPortal();
    $res = $this->get('/api/v1/ctr/portal/workers/template', $ch())->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('CTR-W1');
    // La empresa principal no usa el portal.
    $this->get('/api/v1/ctr/portal/workers/template', ctrxH() + ['Accept' => 'application/json'])->assertStatus(403);

    $this->post('/api/v1/ctr/portal/workers/preview', ['file' => ctrxSheet([['DNI', '71112222', 'A', 'B']], 'CTR-W0')], $ch() + ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('file');

    $rows = [
        ['DNI', '71112222', 'Rosa', 'Quispe', 'Mamani', 'Electricista', '987654321'],
        ['DNI', '7111222', 'Corto', 'Digitos', '', '', ''],
        ['', '71112222', 'Repetida', 'Fila', '', '', ''],
        ['CE', 'X12345', 'Ana', 'Extranjera', '', 'Soldadora', ''],
        ['DNI', '73334444', '', '', '', '', '12'],
    ];
    $p = $this->post('/api/v1/ctr/portal/workers/preview', ['file' => ctrxSheet($rows)], $ch())->assertOk();
    expect($p->json('data.total'))->toBe(5)->and($p->json('data.valid'))->toBe(2);
    $byRow = collect($p->json('data.rows'))->keyBy('row');
    expect($byRow[6]['errors'])->toContain('DNI debe tener 8 dígitos.')
        ->and($byRow[7]['errors'][0])->toContain('duplicado en el archivo (fila 5)')
        ->and(implode(' ', $byRow[9]['errors']))->toContain('Faltan los nombres')->toContain('Celular no válido')
        ->and($byRow[5]['document'])->toBe('DNI ****2222');
    expect($this->getJson('/api/v1/ctr/portal', $ch())->json('data.workers'))->toBe([]);

    $r = $this->post('/api/v1/ctr/portal/workers/import', ['file' => ctrxSheet($rows)], $ch())->assertOk();
    expect($r->json('data.created'))->toBe(2)->and($r->json('data.failed'))->toBe(3);
    $workers = collect($this->getJson('/api/v1/ctr/portal', $ch())->json('data.workers'));
    expect($workers)->toHaveCount(2)->and($workers->pluck('position_text')->all())->toContain('Electricista');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select count(*) as n from contractor_workers where contractor_id = ? and deleted_at is null', [$cid])->n)->toBe(2);

    // Una segunda carga del mismo trabajador no lo duplica.
    $again = $this->post('/api/v1/ctr/portal/workers/preview', ['file' => ctrxSheet([$rows[0]])], $ch())->assertOk();
    expect($again->json('data.rows.0.errors.0'))->toContain('ya está registrado');
});

it('registra la póliza SCTR y la cobertura por trabajador desde el portal', function () {
    [$cid, $ch] = ctrxPortal();
    $w1 = $this->postJson('/api/v1/ctr/portal/workers', ['document_number' => '71717171', 'first_name' => 'Luis', 'paternal_last_name' => 'Quispe'], $ch())->assertCreated()->json('data.id');
    $w2 = $this->postJson('/api/v1/ctr/portal/workers', ['document_number' => '72727272', 'first_name' => 'Ana', 'paternal_last_name' => 'Ríos'], $ch())->assertCreated()->json('data.id');
    $file = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->create('poliza.pdf', 20, 'application/pdf'), 'module' => 'CTR'], $ch() + ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');

    $policy = ['coverage_kind' => 'health', 'insurer_type' => 'eps', 'insurer_name' => 'Pacífico EPS', 'policy_number' => 'SAL-001',
        'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(6)->toDateString()];
    $this->postJson('/api/v1/ctr/portal/sctr/policies', $policy, $ch())->assertStatus(422)->assertJsonValidationErrors('file_id');
    $health = $this->postJson('/api/v1/ctr/portal/sctr/policies', $policy + ['file_id' => $file], $ch())->assertCreated()->json('data');
    expect($health['contractor_id'])->toBe($cid)->and($health['status'])->toBe('active');
    $pension = $this->postJson('/api/v1/ctr/portal/sctr/policies', ['coverage_kind' => 'pension', 'policy_number' => 'PEN-001'] + $policy + ['file_id' => $file], $ch())->assertCreated()->json('data.id');

    // RN-SCT-03: la cobertura no excede la póliza; los trabajadores deben ser del contratista.
    $this->postJson('/api/v1/ctr/portal/sctr/coverages', ['sctr_policy_id' => $health['id'], 'contractor_worker_ids' => [$w1], 'covered_from' => now()->toDateString(), 'covered_until' => now()->addYear()->toDateString()], $ch())
        ->assertStatus(422)->assertJsonValidationErrors('covered_until');
    $this->postJson('/api/v1/ctr/portal/sctr/coverages', ['sctr_policy_id' => $health['id'], 'contractor_worker_ids' => [Str::uuid7()->toString()], 'covered_from' => now()->toDateString(), 'covered_until' => now()->addMonth()->toDateString()], $ch())
        ->assertStatus(422)->assertJsonValidationErrors('contractor_worker_ids');

    $period = ['covered_from' => now()->startOfMonth()->toDateString(), 'covered_until' => now()->addMonths(2)->toDateString()];
    $this->postJson('/api/v1/ctr/portal/sctr/coverages', ['sctr_policy_id' => $health['id'], 'contractor_worker_ids' => [$w1, $w2]] + $period, $ch())->assertCreated()->assertJsonPath('data.created', 2);
    $this->postJson('/api/v1/ctr/portal/sctr/coverages', ['sctr_policy_id' => $pension, 'contractor_worker_ids' => [$w1]] + $period, $ch())->assertCreated();
    // Misma constancia otra vez: reemplaza, no duplica (RN-SCT-08).
    $this->postJson('/api/v1/ctr/portal/sctr/coverages', ['sctr_policy_id' => $pension, 'contractor_worker_ids' => [$w1], 'constancy_number' => 'C-2'] + $period, $ch())->assertCreated();

    $s = $this->getJson('/api/v1/ctr/portal/sctr', $ch())->assertOk();
    $byId = collect($s->json('data.workers'))->keyBy('id');
    expect($byId[$w1]['has_sctr'])->toBeTrue()
        ->and($byId[$w2]['has_sctr'])->toBeFalse()->and($byId[$w2]['pension']['state'])->toBe('missing')
        ->and($s->json('data.kpis.workers_without'))->toBe(1)->and($s->json('data.policies'))->toHaveCount(2);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select count(*) as n from sctr_coverages where contractor_worker_id = ? and sctr_policy_id = ? and deleted_at is null', [$w1, $pension])->n)->toBe(1);

    // La empresa principal no entra al portal SCTR.
    $this->getJson('/api/v1/ctr/portal/sctr', ctrxH())->assertStatus(403);
});

it('avisa los documentos por vencer y marca los vencidos', function () {
    $req = $this->postJson('/api/v1/records/CTR/contractor_requirements', ['applies_to' => 'worker', 'requirement_code' => 'IND', 'name' => 'Constancia de inducción', 'is_mandatory' => true], ctrxH())
        ->assertCreated()->json('data.id');
    [$cid, $ch] = ctrxPortal();
    $w = $this->postJson('/api/v1/ctr/portal/workers', ['document_number' => '71717171', 'first_name' => 'Luis', 'paternal_last_name' => 'Quispe'], $ch())->assertCreated()->json('data.id');
    $file = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->create('ind.pdf', 20, 'application/pdf'), 'module' => 'CTR'], $ch() + ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $doc = $this->postJson('/api/v1/ctr/portal/documents', ['requirement_id' => $req, 'file_id' => $file, 'contractor_worker_id' => $w, 'expires_on' => now()->addDays(5)->toDateString()], $ch())->assertCreated()->json('data.id');
    $this->postJson("/api/v1/ctr/review/{$doc}", ['decision' => 'approve'], ctrxH())->assertOk();

    $db = DB::connection('pgsql');
    $db->select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $db->select("select set_config('app.contractor_id', '', false)");
    $alerts = app(ExpiryAlerts::class);
    $n = $alerts->forCompany($db, HUAYLAS_ID);
    $admin = DB::connection('platform')->selectOne('select id from users where lower(email) = ?', [$GLOBALS['ctrx_emails'][0]])->id;
    $manager = DB::connection('platform')->selectOne('select id from users where lower(email) = ?', [strtolower(CORPORATIVO_EMAIL)])->id;
    $count = fn (string $u, string $e) => (int) $db->selectOne('select count(*) as n from notifications where recipient_user_id = ? and event_code = ? and subject_id = ?', [$u, $e, $doc])->n;
    expect($n)->toBeGreaterThanOrEqual(2)->and($count($admin, 'ctr.document_expiring'))->toBe(1)->and($count($manager, 'ctr.document_expiring'))->toBe(1);
    // No se repite al correr otra vez.
    $alerts->forCompany($db, HUAYLAS_ID);
    expect($count($admin, 'ctr.document_expiring'))->toBe(1);

    // Vence: pasa a «vencido», el trabajador queda no habilitado y se avisa.
    $db->update('update contractor_documents set expires_on = current_date - 1 where id = ?', [$doc]);
    $db->update('update contractor_workers set access_enabled = true where id = ?', [$w]);
    $alerts->forCompany($db, HUAYLAS_ID);
    expect($db->selectOne('select status from contractor_documents where id = ?', [$doc])->status)->toBe('expired')
        ->and($db->selectOne('select access_enabled from contractor_workers where id = ?', [$w])->access_enabled)->toBeFalse()
        ->and($count($admin, 'ctr.document_expired'))->toBe(1);
});
