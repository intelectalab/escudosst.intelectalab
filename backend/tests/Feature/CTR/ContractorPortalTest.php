<?php

// Contratistas (M-CTR): invitar al portal (usuario contractor_admin acotado a su contratista), portal del
// contratista (requisitos, documentos, trabajadores, acreditación) y bandeja de revisión de la empresa principal.

use App\Modules\USR\Mail\AccountInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $GLOBALS['ctr_emails'] = [];
    Mail::fake();
});

// Los usuarios se crean con la conexión de la plataforma (fuera de la transacción de la prueba): se borran al final.
afterEach(function () {
    DB::rollBack();
    $platform = DB::connection('pgsql_owner');
    foreach ($GLOBALS['ctr_emails'] as $email) {
        $u = $platform->selectOne('select id from users where lower(email) = ?', [$email]);
        if ($u) {
            $platform->delete("delete from personal_access_tokens where tokenable_id = ?", [$u->id]);
            $platform->delete('delete from users where id = ?', [$u->id]);
        }
    }
});

function ctrH(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

/** Invita a un contratista y acepta la invitación; devuelve [contractor_id, encabezados del administrador]. */
function ctrInviteAndLogin(string $ruc = '20100070970', string $name = 'Montajes Andinos SAC'): array
{
    $email = 'ctr.'.Str::lower(Str::random(8)).'@escudo.test';
    $GLOBALS['ctr_emails'][] = $email;
    $res = test()->postJson('/api/v1/ctr/invitations', ['ruc' => $ruc, 'legal_name' => $name, 'admin_email' => $email, 'admin_name' => 'Rosa Admin',
        'services_description' => 'Mantenimiento eléctrico', 'risk_level' => 'high'], ctrH())->assertCreated()->assertJsonPath('data.status', 'invited');
    $url = null;
    Mail::assertSent(AccountInvitationMail::class, function ($m) use (&$url) {
        $url = $m->url;

        return true;
    });
    $token = basename((string) $url);
    test()->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => DEMO_PASSWORD, 'password_confirmation' => DEMO_PASSWORD])->assertOk();

    return [$res->json('data.contractor_id'), fn () => authHeaders(tokenFor($email), HUAYLAS_ID)];
}

it('valida la invitación y no deja invitar con un RUC inválido', function () {
    $this->postJson('/api/v1/ctr/invitations', ['ruc' => '123', 'legal_name' => 'X', 'admin_email' => 'no-correo'], ctrH())
        ->assertStatus(422)->assertJsonStructure(['errors' => ['ruc', 'legal_name', 'admin_email']]);
    // Un correo que ya tiene acceso a la empresa con otro rol no se reutiliza.
    $this->postJson('/api/v1/ctr/invitations', ['ruc' => '20100070970', 'legal_name' => 'Montajes Andinos SAC', 'admin_email' => CORPORATIVO_EMAIL], ctrH())
        ->assertStatus(409);
});

it('invita al contratista, crea su administrador acotado y lo lista con la invitación', function () {
    [$cid, $ch] = ctrInviteAndLogin();
    $row = collect($this->getJson('/api/v1/ctr/invitations', ctrH())->assertOk()->json('data'))->firstWhere('id', $cid);
    expect($row['ruc'])->toBe('20100070970')->and($row['admins'][0]['status'])->toBe('active');

    // El administrador del contratista entra con rol contractor_admin y ve solo su portal.
    $companies = collect($this->getJson('/api/v1/me/companies', $ch())->json('data'));
    expect($companies->firstWhere('company_id', HUAYLAS_ID)['role']['code'])->toBe('contractor_admin');
    $this->getJson('/api/v1/ctr/portal', $ch())->assertOk()->assertJsonPath('data.contractor.id', $cid)->assertJsonPath('data.contractor.legal_name', 'Montajes Andinos SAC');
    // La bandeja de revisión y las invitaciones son de la empresa principal.
    $this->getJson('/api/v1/ctr/review', $ch())->assertStatus(403);
    $this->postJson('/api/v1/ctr/invitations', ['ruc' => '20100070970', 'legal_name' => 'X SAC', 'admin_email' => 'otro@escudo.test'], $ch())->assertStatus(403);
    // El Jefe SST no tiene portal de contratista.
    $this->getJson('/api/v1/ctr/portal', ctrH())->assertStatus(403);
});

it('el contratista carga documentos y trabajadores, y la empresa principal los aprueba u observa', function () {
    $req = $this->postJson('/api/v1/records/CTR/contractor_requirements', ['applies_to' => 'company', 'requirement_code' => 'PLAN', 'name' => 'Plan de seguridad del servicio', 'is_mandatory' => true], ctrH())
        ->assertCreated()->json('data.id');
    $wreq = $this->postJson('/api/v1/records/CTR/contractor_requirements', ['applies_to' => 'worker', 'requirement_code' => 'IND', 'name' => 'Constancia de inducción', 'is_mandatory' => true], ctrH())
        ->assertCreated()->json('data.id');
    [$cid, $ch] = ctrInviteAndLogin();

    $portal = $this->getJson('/api/v1/ctr/portal', $ch())->assertOk();
    $item = collect($portal->json('data.company_requirements'))->firstWhere('id', $req);
    expect($item['state'])->toBe('pending');

    // Alta de un trabajador uno por uno (queda en su contratista aunque mande otro contractor_id).
    $w = $this->postJson('/api/v1/ctr/portal/workers', ['document_number' => '71717171', 'first_name' => 'Luis', 'paternal_last_name' => 'Quispe', 'contractor_id' => Str::uuid7()->toString()], $ch())
        ->assertCreated()->json('data');
    expect($w['contractor_id'])->toBe($cid)->and($w['document_last4'])->toBe('7171');

    // Carga del documento con un archivo propio.
    $file = $this->post('/api/v1/files', ['file' => \Illuminate\Http\UploadedFile::fake()->create('plan.pdf', 20, 'application/pdf'), 'module' => 'CTR'], $ch() + ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/ctr/portal/documents', ['requirement_id' => $req, 'file_id' => $file, 'expires_on' => '2020-01-01'], $ch())->assertStatus(422);
    $doc = $this->postJson('/api/v1/ctr/portal/documents', ['requirement_id' => $req, 'file_id' => $file, 'expires_on' => now()->addYear()->toDateString()], $ch())
        ->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.id');
    // Documento de trabajador: exige elegir a uno de sus trabajadores.
    $this->postJson('/api/v1/ctr/portal/documents', ['requirement_id' => $wreq, 'file_id' => $file], $ch())->assertStatus(422)->assertJsonStructure(['errors' => ['contractor_worker_id']]);
    $this->postJson('/api/v1/ctr/portal/documents', ['requirement_id' => $wreq, 'file_id' => $file, 'contractor_worker_id' => $w['id']], $ch())->assertCreated();

    // Bandeja de la empresa principal.
    $inbox = $this->getJson('/api/v1/ctr/review', ctrH())->assertOk();
    expect(collect($inbox->json('data'))->pluck('id'))->toContain($doc)->and($inbox->json('can_review'))->toBeTrue();
    $this->postJson("/api/v1/ctr/review/{$doc}", ['decision' => 'observe'], ctrH())->assertStatus(422)->assertJsonStructure(['errors' => ['comment']]);
    $this->postJson("/api/v1/ctr/review/{$doc}", ['decision' => 'observe', 'comment' => 'Falta la firma del gerente'], ctrH())->assertOk()->assertJsonPath('data.status', 'observed');

    $item = collect($this->getJson('/api/v1/ctr/portal', $ch())->json('data.company_requirements'))->firstWhere('id', $req);
    expect($item['state'])->toBe('observed')->and($item['document']['review_comments'])->toBe('Falta la firma del gerente');
    // Se avisa al administrador del contratista.
    $uid = DB::connection('platform')->selectOne('select id from users where lower(email) = ?', [$GLOBALS['ctr_emails'][0]])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where recipient_user_id = ? and event_code = 'ctr.document_observed'", [$uid])->n)->toBe(1);

    // Reemplazo y aprobación; un aprobado no se vuelve a revisar.
    $doc2 = $this->postJson('/api/v1/ctr/portal/documents', ['requirement_id' => $req, 'file_id' => $file, 'expires_on' => now()->addYear()->toDateString()], $ch())->assertCreated()->json('data.id');
    $this->postJson("/api/v1/ctr/review/{$doc2}", ['decision' => 'approve'], ctrH())->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/ctr/review/{$doc2}", ['decision' => 'observe', 'comment' => 'otra vez'], ctrH())->assertStatus(409);
    $portal = $this->getJson('/api/v1/ctr/portal', $ch())->assertOk();
    expect(collect($portal->json('data.company_requirements'))->firstWhere('id', $req)['state'])->toBe('approved')
        ->and($portal->json('data.workers.0.requirements.0.state'))->toBe('submitted')
        ->and($portal->json('data.workers.0.complete'))->toBeFalse();
});

it('solicita la acreditación desde el portal sin ver datos de la empresa principal', function () {
    [$cid, $ch] = ctrInviteAndLogin();
    // Otro contratista de la empresa principal: el portal no lo ve.
    $other = $this->postJson('/api/v1/records/CTR/contractors', ['ruc' => '20131312955', 'legal_name' => 'Otra Contrata SAC'], ctrH())->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/CTR/contractor_workers', ['contractor_id' => $other, 'document_number' => '72727272', 'first_name' => 'Ana', 'paternal_last_name' => 'Ríos'], ctrH())->assertCreated();
    $portal = $this->getJson('/api/v1/ctr/portal', $ch())->assertOk();
    expect($portal->json('data.workers'))->toBe([])->and($portal->json('data.contractor.id'))->toBe($cid);

    $this->postJson('/api/v1/ctr/portal/accreditations', ['service_description' => 'x'], $ch())->assertStatus(422);
    $acc = $this->postJson('/api/v1/ctr/portal/accreditations', ['service_description' => 'Mantenimiento de tableros', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonths(3)->toDateString()], $ch())
        ->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data');
    expect($acc['contractor_id'])->toBe($cid);
    $this->postJson('/api/v1/ctr/portal/accreditations', ['service_description' => 'Otro servicio', 'starts_on' => now()->toDateString()], $ch())->assertStatus(409);

    $this->patchJson('/api/v1/ctr/portal/company', ['contact_phone' => '+51 999 111 222', 'legal_name' => 'Cambiado'], $ch())->assertOk()
        ->assertJsonPath('data.contractor.contact_phone', '+51 999 111 222')->assertJsonPath('data.contractor.legal_name', 'Montajes Andinos SAC');
});

it('el contratista solo ve y registra lo de su propia empresa en el motor de registros', function () {
    // Otro contratista de la misma empresa principal.
    $other = $this->postJson('/api/v1/records/CTR/contractors', ['ruc' => '20555666771', 'legal_name' => 'Otra Contratista SAC'], ctrH())->assertCreated()->json('data.id');
    [$cid, $ch] = ctrInviteAndLogin();
    app('auth')->forgetGuards();

    $ids = collect($this->getJson('/api/v1/records/CTR/contractors', $ch())->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$cid]);
    $this->getJson("/api/v1/records/CTR/contractors/{$other}", $ch())->assertNotFound();
    // No crea contratistas ni registra trabajadores para otro contratista.
    $this->postJson('/api/v1/records/CTR/contractors', ['ruc' => '20555666772', 'legal_name' => 'Nueva SAC'], $ch())->assertStatus(403);
});
