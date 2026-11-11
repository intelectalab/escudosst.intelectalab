<?php

// Recorrido completo de una empresa por la API, como lo haría un cliente real:
// se registra sola («Prueba gratis»), su administrador entra con doble factor, arma la empresa, carga a su personal
// desde Excel, invita a su equipo (con un rol propio) y trabaja el SG-SST de punta a punta: línea base, política,
// IPERC, plan y programas, inspección con hallazgos y acciones verificadas por otra persona, reporte por QR,
// capacitación con certificado verificable, EMO y aptitud, fotocheck, accidente con notificación e investigación,
// horas-hombre y cierre del mes, registros en Excel, Modo SUNAFIL con enlace público, consulta al equipo y bitácora.
//
// La cuenta, la empresa y los usuarios se crean con la conexión de la plataforma (fuera de la transacción de la
// prueba): al final se dan de baja o se borran. Los registros SST quedan en la transacción y se revierten.

use App\Core\Support\Xlsx;
use App\Modules\USR\Mail\AccountInvitationMail;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    Storage::fake('local');
    // Al fallar un código de estado, el mensaje muestra la respuesta (errores de validación incluidos).
    TestResponse::macro('qaStatus', function (int $status) {
        expect($this->status())->toBe($status, mb_substr((string) $this->getContent(), 0, 800));

        return $this;
    });
    // Varias personas se turnan en la misma prueba: tras cada pedido se olvida la sesión resuelta,
    // así cada uno usa solo el token de sus encabezados.
    Event::listen(RequestHandled::class, fn () => app('auth')->forgetGuards());
    DB::connection('medical')->beginTransaction();
    qajCleanup();
});

afterEach(function () {
    DB::connection('medical')->rollBack();
    DB::connection('pgsql')->rollBack(0);
    qajCleanup();
});

/**
 * La cuenta que se registra sola, su empresa y las personas del recorrido se crean con la conexión de la plataforma:
 * baja lógica (libera el RUC), como en SUS/AccountsTest. También limpia lo que haya dejado una corrida interrumpida.
 */
function qajCleanup(): void
{
    DB::connection('pgsql_owner')->delete("delete from support_tickets where subject like 'QA RECORRIDO %'");
    $db = DB::connection('platform');
    $db->transaction(function () use ($db) {
        $companies = "select id from companies where legal_name = 'Metalmecánica Recorrido S.A.C.' and deleted_at is null";
        $db->update("update subscription_addons set deleted_at = now() where deleted_at is null and subscription_id in (select id from subscriptions where company_id in ({$companies}))");
        $db->update("update subscriptions set deleted_at = now() where deleted_at is null and company_id in ({$companies})");
        $db->update("update accounts set deleted_at = now() where deleted_at is null and id in (select account_id from companies where id in ({$companies}))");
        $db->update("update companies set deleted_at = now() where id in ({$companies})");
        // El administrador y las personas invitadas (todas con correo qa.recorrido.*).
        $db->update("update users set deleted_at = now() where email like 'qa.recorrido.%@prueba.test' and deleted_at is null");
    });
}

/** RUC 20xxxxxxxxx con dígito verificador correcto. */
function qajRuc(): string
{
    $base = '20'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $sum = 0;
    foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $w) {
        $sum += (int) $base[$i] * $w;
    }
    $check = 11 - ($sum % 11);

    return $base.($check === 10 ? 0 : ($check === 11 ? 1 : $check));
}

/** Token del correo de invitación enviado a $email. */
function qajInvitationToken(string $email): string
{
    $url = null;
    Mail::assertSent(AccountInvitationMail::class, function ($m) use (&$url, $email) {
        if ($m->hasTo($email) && $m->url) {
            $url = $m->url;
        }

        return true;
    });
    expect($url)->not->toBeNull("No llegó la invitación a {$email}");

    return basename((string) $url);
}

/** Invita a alguien de la empresa con un rol, acepta la invitación y devuelve [encabezados, id de usuario]. */
function qajInvite(array $admin, string $companyId, string $who, string $roleCode, string $fullName): array
{
    $email = "qa.recorrido.{$who}.".Str::lower(Str::random(6)).'@prueba.test';
    test()->postJson('/api/v1/company/users', ['email' => $email, 'full_name' => $fullName, 'role_code' => $roleCode], $admin)
        ->qaStatus(201)->assertJsonPath('data.status', 'invited');
    test()->postJson('/api/v1/auth/invitations/'.qajInvitationToken($email).'/accept', ['password' => DEMO_PASSWORD, 'password_confirmation' => DEMO_PASSWORD])->qaStatus(200);
    $h = authHeaders(tokenFor($email), $companyId);

    $me = test()->getJson('/api/v1/me', $h)->qaStatus(200);

    return [$h, $me->json('data.id') ?? $me->json('id')];
}

/** KPI del tablero por código. */
function qajKpi(array $h, string $code): array
{
    return collect(test()->getJson('/api/v1/dsh/overview?year='.now('America/Lima')->year, $h)->qaStatus(200)->json('data.kpis'))->firstWhere('code', $code);
}

function qajSheet(array $rows): UploadedFile
{
    $head = ['Tipo de documento', 'Número de documento', 'Nombres', 'Apellido paterno', 'Apellido materno', 'Fecha de nacimiento', 'Sexo', 'Celular', 'Correo',
        'Fecha de ingreso', 'Tipo de vínculo', 'Sede', 'Área', 'Puesto', 'Código de empleado'];

    return UploadedFile::fake()->createWithContent('trabajadores.xlsx',
        Xlsx::build('Trabajadores', [['EscudoSST · Plantilla de trabajadores · versión TRB-W1'], ['Instrucciones'], ['Sedes'], ['Puestos'], $head, ...$rows], 5));
}

it('una empresa nueva recorre todo el sistema de gestión SST por la API', function () {
    $today = now('America/Lima')->toDateString();
    $year = (int) now('America/Lima')->year;
    $month = (int) now('America/Lima')->month;

    // ------------------------------------------------------------ 1. Se registra sola desde el sitio
    $ruc = qajRuc();
    $adminEmail = 'qa.recorrido.admin.'.Str::lower(Str::random(6)).'@prueba.test';
    $this->postJson('/api/v1/public/trial', ['ruc' => $ruc, 'legal_name' => 'Metalmecánica Recorrido S.A.C.', 'headcount' => 12,
        'full_name' => 'Elena Recorrido Paz', 'email' => $adminEmail, 'accept_legal' => true])->qaStatus(201)->assertJsonPath('data.email', $adminEmail);
    $company = DB::connection('platform')->selectOne('select id, sst_model from companies where ruc = ? and deleted_at is null', [$ruc]);
    expect($company)->not->toBeNull()->and($company->sst_model)->toBe('supervisor');
    $cid = $company->id;

    // ------------------------------------------------------------ 2. Primer ingreso del administrador (doble factor)
    $token = qajInvitationToken($adminEmail);
    $this->getJson("/api/v1/auth/invitations/{$token}")->qaStatus(200)->assertJsonPath('data.company_name', 'Metalmecánica Recorrido S.A.C.');
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => DEMO_PASSWORD, 'password_confirmation' => DEMO_PASSWORD])->qaStatus(200);
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertStatus(410);   // un solo uso
    $login = $this->postJson('/api/v1/auth/login', ['email' => $adminEmail, 'password' => DEMO_PASSWORD])->qaStatus(200);
    expect($login->json('mfa_required'))->toBeTrue()->and($login->json('mfa_setup_required'))->toBeTrue();
    app('auth')->forgetGuards();
    $adminToken = tokenFor($adminEmail);   // configura el doble factor con el código TOTP
    expect(mfaSecrets()[$adminEmail] ?? null)->not->toBeNull();
    $mine = $this->getJson('/api/v1/me/companies', authHeaders($adminToken))->qaStatus(200)->json('data');
    expect($mine[0]['company_id'])->toBe($cid)->and($mine[0]['role']['code'])->toBe('account_admin');
    $admin = authHeaders($adminToken, $cid);
    $this->getJson('/api/v1/me/subscription', $admin)->qaStatus(200);

    // ------------------------------------------------------------ 3. Datos de la empresa, sedes, áreas, puestos y turnos
    $onb = $this->getJson('/api/v1/ten/onboarding', $admin)->qaStatus(200);
    expect(collect($onb->json('data.steps'))->firstWhere('code', 'company')['done'])->toBeFalse();
    $sitesBefore = $onb->json('data.counts.sites');   // el alta crea la sede principal
    $this->patchJson('/api/v1/ten/company', ['trade_name' => 'Metalmecánica Recorrido', 'economic_sector' => 'manufacturing', 'risk_level' => 'high',
        'fiscal_address' => 'Av. Industrial 123, Lima', 'ciiu_code' => '2511', 'headcount_declared' => 12], $admin)->qaStatus(200);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'PLT', 'name' => 'Planta Ate'], $admin)->qaStatus(201)->json('data.id');
    $site2 = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ALM', 'name' => 'Almacén Callao'], $admin)->qaStatus(201)->json('data.id');
    $area = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'SOL', 'name' => 'Soldadura'], $admin)->qaStatus(201)->json('data.id');
    $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site2, 'code' => 'DES', 'name' => 'Despacho'], $admin)->qaStatus(201);
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'SOLD', 'name' => 'Soldador', 'area_id' => $area], $admin)->qaStatus(201)->json('data.id');
    $this->postJson('/api/v1/records/TEN/positions', ['code' => 'ALMC', 'name' => 'Almacenero'], $admin)->qaStatus(201);
    $this->postJson('/api/v1/records/TEN/work_shifts', ['code' => 'T1', 'name' => 'Turno mañana', 'start_time' => '07:00', 'end_time' => '15:00'], $admin)->qaStatus(201);
    $onb = $this->getJson('/api/v1/ten/onboarding', $admin)->qaStatus(200);
    $steps = collect($onb->json('data.steps'))->keyBy('code');
    expect($steps['company']['done'])->toBeTrue()->and($steps['sites']['done'])->toBeTrue()->and($steps['positions']['done'])->toBeTrue()
        ->and($onb->json('data.counts.sites'))->toBe($sitesBefore + 2)->and($onb->json('data.counts.areas'))->toBeGreaterThanOrEqual(2);

    // ------------------------------------------------------------ 4. Equipo: Jefe SST, segundo responsable, médico y un rol propio
    [$a, $aId] = qajInvite($admin, $cid, 'jefe', 'sst_manager', 'Andrés Jefe Rojas');
    [$b, $bId] = qajInvite($admin, $cid, 'super', 'sst_manager', 'Beatriz Segunda Vega');
    [$doc, $docId] = qajInvite($admin, $cid, 'medico', 'occupational_doctor', 'Dra. Carmen Médica Ruiz');
    $manage = $this->getJson('/api/v1/company/roles/manage', $admin)->qaStatus(200)->json('data');
    $supPerms = collect($manage['roles'])->firstWhere('code', 'sst_supervisor')['permissions'];
    $inspPerms = array_values(array_filter($supPerms, fn ($c) => str_starts_with($c, 'ins.') || str_starts_with($c, 'act.')));
    $role = $this->postJson('/api/v1/company/roles', ['base_role_code' => 'sst_supervisor', 'name' => 'Inspector de planta', 'permissions' => $inspPerms], $admin)
        ->qaStatus(201)->json('data.code');
    [$insp] = qajInvite($admin, $cid, 'inspector', $role, 'Iván Inspector Soto');
    $p = $this->getJson('/api/v1/me/permissions', $insp)->qaStatus(200);
    expect($p->json('role_code'))->toBe('sst_supervisor')->and($p->json('custom_role_code'))->toBe($role)
        ->and($p->json('data'))->toEqualCanonicalizing($inspPerms);
    $this->getJson('/api/v1/records/IPC/iperc_matrices', $insp)->assertForbidden();   // solo lo de su rol
    $this->getJson('/api/v1/records/INS/inspections', $insp)->qaStatus(200);
    expect(collect($this->getJson('/api/v1/company/users', $admin)->qaStatus(200)->json('data'))->count())->toBeGreaterThanOrEqual(5);

    $kpiBaselineBefore = qajKpi($a, 'baseline_score');
    expect($kpiBaselineBefore['value'])->toBeNull();

    // ------------------------------------------------------------ 5. Personal desde Excel
    $rows = [
        ['DNI', '70101010', 'Rosa', 'Quispe', 'Mamani', '15/03/1990', 'F', '987654321', 'rosa@correo.test', '01/02/2024', 'Propio', 'PLT', 'Soldadura', 'SOLD', 'E-1'],
        ['DNI', '70202020', 'Jorge', 'Huamán', 'Ccori', '20/07/1985', 'M', '987000111', '', '01/03/2023', 'Propio', 'PLT', 'Soldadura', 'SOLD', 'E-2'],
        ['DNI', '70303030', 'Lucía', 'Torres', 'Díaz', '02/11/1995', 'F', '', '', '15/06/2025', 'Propio', 'ALM', '', 'ALMC', 'E-3'],
        ['DNI', '7030303', 'Corto', 'Error', '', '', '', '', '', '', '', 'PLT', '', 'SOLD', ''],
    ];
    $preview = $this->post('/api/v1/trb/imports/preview', ['file' => qajSheet($rows), 'mode' => 'create'], $a)->qaStatus(200);
    expect($preview->json('data.total'))->toBe(4)->and($preview->json('data.valid'))->toBe(3)->and($preview->json('data.with_errors'))->toBe(1);
    $imp = $this->post('/api/v1/trb/imports', ['file' => qajSheet($rows), 'mode' => 'create'], $a)->qaStatus(200);
    expect($imp->json('data.created'))->toBe(3)->and($imp->json('data.failed'))->toBe(1);
    $byRow = collect($imp->json('data.rows'))->keyBy('row');
    [$rosa, $jorge, $lucia] = [$byRow[6]['worker_id'], $byRow[7]['worker_id'], $byRow[8]['worker_id']];
    expect($this->getJson('/api/v1/records/TRB/workers', $a)->json('meta.total'))->toBe(3);
    $importId = $this->getJson('/api/v1/trb/imports', $a)->qaStatus(200)->json('data.0.id');
    expect($importId)->not->toBeNull();

    // ------------------------------------------------------------ 6. Línea base → brechas → acción correctiva
    $lba = $this->postJson('/api/v1/records/LBA/baseline_assessments', ['title' => 'Línea base inicial', 'assessment_on' => $today, 'is_initial' => true], $a)
        ->qaStatus(201)->assertJsonPath('data.status', 'draft')->json('data.id');
    $this->postJson("/api/v1/lba/assessments/{$lba}/load", [], $a)->qaStatus(200);
    $items = $this->getJson("/api/v1/records/LBA/baseline_items?f[assessment_id]={$lba}&per_page=100", $a)->qaStatus(200)->json('data');
    expect(count($items))->toBeGreaterThan(2);
    $this->patchJson("/api/v1/records/LBA/baseline_assessments/{$lba}", ['status' => 'approved'], $a)->assertStatus(422);   // sin respuestas
    foreach ($items as $i => $item) {
        $result = $i === 0 ? 'non_compliant' : ($i === 1 ? 'partial' : 'compliant');
        $this->patchJson("/api/v1/records/LBA/baseline_items/{$item['id']}", ['result' => $result, 'evidence_notes' => 'Revisado en planta'], $a)->qaStatus(200);
    }
    $this->patchJson("/api/v1/records/LBA/baseline_assessments/{$lba}", ['status' => 'approved'], $a)->qaStatus(200);
    $assess = $this->getJson("/api/v1/records/LBA/baseline_assessments/{$lba}", $a)->qaStatus(200);
    expect((float) $assess->json('data.score_pct'))->toBeGreaterThan(0)->toBeLessThan(100);
    $gaps = $this->getJson("/api/v1/records/LBA/baseline_gaps?f[assessment_id]={$lba}", $a)->qaStatus(200)->json('data');
    expect($gaps)->toHaveCount(2);
    expect(collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $a)->qaStatus(200)->json('data'))->pluck('id')->all())->toContain($aId, $bId);
    $gapAction = $this->postJson("/api/v1/acr/from/baseline_gaps/{$gaps[0]['id']}", ['title' => 'Cerrar brecha de línea base', 'responsible_user_id' => $bId, 'due_on' => now()->addDays(30)->toDateString()], $a)
        ->qaStatus(201)->json('data.id');
    expect(collect($this->getJson("/api/v1/records/ACR/corrective_actions/{$gapAction}/trace", $a)->qaStatus(200)->json('data.parents'))->pluck('table'))->toContain('baseline_gaps');
    expect(qajKpi($a, 'baseline_score')['value'])->not->toBeNull();

    // ------------------------------------------------------------ 7. Política: la elabora uno y la aprueba otro
    $pol = $this->postJson('/api/v1/records/POL/sst_policies', ['title' => 'Política SST 2026', 'content_html' => '<p>Nos comprometemos a proteger la vida y la salud.</p>'], $a)
        ->qaStatus(201)->json('data.id');
    $this->patchJson("/api/v1/records/POL/sst_policies/{$pol}", ['status' => 'approved'], $a)->assertStatus(422)
        ->assertJsonPath('errors.status.0', fn ($m) => str_starts_with($m, 'Quien elaboró la versión no puede aprobarla'));
    $this->patchJson("/api/v1/records/POL/sst_policies/{$pol}", ['status' => 'approved'], $b)->qaStatus(200);
    $this->patchJson("/api/v1/records/POL/sst_policies/{$pol}", ['status' => 'current'], $b)->qaStatus(200)->assertJsonPath('data.status', 'current');

    // ------------------------------------------------------------ 8. Matriz IPERC con filas, controles y aprobación
    $crit = $this->getJson('/api/v1/records/IPC/iperc_matrices/options/risk_criteria_id?q=R.M.', $a)->qaStatus(200)->json('data.0.id');
    $matrix = $this->postJson('/api/v1/records/IPC/iperc_matrices', ['code' => 'IPERC-SOL', 'title' => 'IPERC Soldadura', 'risk_criteria_id' => $crit], $a)->qaStatus(201)->json('data.id');
    $version = $this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", $a)->qaStatus(200)->json('data.version_id');
    $row = $this->postJson('/api/v1/records/IPC/iperc_rows', ['iperc_version_id' => $version, 'activity' => 'Soldadura', 'task' => 'Soldar estructuras', 'hazard' => 'Humos metálicos',
        'risk' => 'Intoxicación', 'probability_detail' => ['exposed_people' => 2, 'procedures' => 2, 'training' => 2, 'exposure' => 3], 'severity_value' => 2, 'position_id' => $pos], $a)
        ->qaStatus(201);
    expect($row->json('data.risk_score'))->toBe(18)->and($row->json('data.risk_level'))->toBe('important');
    $this->postJson('/api/v1/records/IPC/iperc_controls', ['iperc_row_id' => $row->json('data.id'), 'hierarchy' => 'engineering', 'description' => 'Extracción localizada de humos',
        'responsible_user_id' => $bId, 'due_on' => now()->addDays(45)->toDateString()], $a)->qaStatus(201);
    $this->postJson("/api/v1/ipc/versions/{$version}/submit", ['participants' => ['Rosa Quispe', 'Jorge Huamán']], $a)->assertNoContent();
    $this->postJson("/api/v1/ipc/versions/{$version}/approve", [], $a)->assertStatus(409);   // aprueba otra persona
    $approved = $this->postJson("/api/v1/ipc/versions/{$version}/approve", [], $b)->qaStatus(200);
    expect($approved->json('data.corrective_actions'))->toBeGreaterThanOrEqual(1);
    expect(collect($this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", $a)->json('data.versions'))->firstWhere('id', $version)['status'])->toBe('current');
    $this->get("/api/v1/ipc/versions/{$version}/export.xlsx", $a)->qaStatus(200);

    // ------------------------------------------------------------ 9. Plan anual, programa anual y programa de capacitación
    $plan = $this->postJson('/api/v1/records/PLA/annual_plans', ['year' => $year, 'title' => "Plan anual SST {$year}"], $a)->qaStatus(201)->json('data.id');
    $this->postJson('/api/v1/records/PLA/annual_plan_items', ['annual_plan_id' => $plan, 'code' => 'P-1', 'description' => 'Reducir accidentes'], $a)->qaStatus(201);
    $this->patchJson("/api/v1/records/PLA/annual_plans/{$plan}", ['status' => 'approved'], $a)->qaStatus(200);
    $prog = $this->postJson('/api/v1/records/PAS/annual_programs', ['year' => $year, 'title' => "PASST {$year}"], $a)->qaStatus(201)->json('data.id');
    $act = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'A-1', 'title' => 'Inspecciones de orden y limpieza',
        'activity_type' => 'inspection', 'goal_quantity' => 4, 'planned_months' => [1, 4, 7, 10]], $a)->qaStatus(201)->json('data.id');
    $this->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $a)->qaStatus(200);
    $this->postJson('/api/v1/records/PAS/activity_executions', ['activity_id' => $act, 'executed_on' => $today, 'quantity' => 1, 'description' => 'Primera inspección'], $a)->qaStatus(201);
    expect($this->getJson("/api/v1/records/PAS/annual_programs/{$prog}", $a)->json('data.status'))->toBe('in_execution');
    $pca = $this->postJson('/api/v1/records/PCA/training_programs', ['year' => $year, 'title' => "Capacitaciones {$year}"], $a)->qaStatus(201)->json('data.id');
    $pcaItems = [];
    foreach (['Inducción SST', 'Trabajo en caliente', 'Uso de EPP', 'Primeros auxilios'] as $k => $topic) {
        $pcaItems[] = $this->postJson('/api/v1/records/PCA/training_program_items', ['training_program_id' => $pca, 'topic' => $topic, 'training_type' => 'specific',
            'planned_month' => $k + 1, 'target_audience' => 'Todo el personal'], $a)->qaStatus(201)->json('data.id');
    }
    $this->patchJson("/api/v1/records/PCA/training_programs/{$pca}", ['status' => 'approved'], $a)->qaStatus(200);
    $this->getJson('/api/v1/pas/schedule', $a)->qaStatus(200);

    // ------------------------------------------------------------ 10. Inspección con hallazgos → acción con evidencia verificada por otro
    $tpl = $this->postJson('/api/v1/records/INS/inspection_templates', ['code' => 'OYL', 'name' => 'Orden y limpieza', 'inspection_type' => 'housekeeping',
        'scoring_method' => 'weighted', 'status' => 'active'], $a)->qaStatus(201)->json('data.id');
    $tItems = [];
    foreach (['¿Pasillos despejados?', '¿Extintores señalizados?', '¿Cables ordenados?'] as $k => $q) {
        $tItems[] = $this->postJson('/api/v1/records/INS/inspection_template_items', ['template_id' => $tpl, 'item_code' => 'I'.($k + 1), 'question' => $q,
            'answer_type' => 'yes_no_na', 'weight' => 1, 'is_critical' => false, 'sort_order' => $k + 1], $a)->qaStatus(201)->json('data.id');
    }
    $ins = $this->postJson('/api/v1/records/INS/inspections', ['title' => 'Inspección planta', 'inspection_type' => 'housekeeping', 'template_id' => $tpl,
        'site_id' => $site, 'scheduled_on' => $today], $insp)->qaStatus(201);   // la hace el inspector con su rol propio
    expect($ins->json('data.code'))->toStartWith('INS-');
    $ins = $ins->json('data.id');
    $this->postJson("/api/v1/ins/inspections/{$ins}/answers", ['answers' => [
        ['item_id' => $tItems[0], 'answer' => 'yes'], ['item_id' => $tItems[1], 'answer' => 'no', 'comment' => 'Extintor sin señal'], ['item_id' => $tItems[2], 'answer' => 'yes'],
    ]], $insp)->qaStatus(200)->assertJsonPath('data.inspection.status', 'in_progress');
    $fin = $this->postJson("/api/v1/ins/inspections/{$ins}/finish", ['summary' => 'Falta señalización'], $insp)->qaStatus(200);
    expect($fin->json('data.findings_created'))->toBe(1);
    $finding = $this->getJson("/api/v1/ins/inspections/{$ins}/checklist", $a)->qaStatus(200)->json('data.findings.0.id');
    $action = $this->postJson("/api/v1/ins/findings/{$finding}/action", ['title' => 'Señalizar extintor', 'responsible_user_id' => $bId, 'due_on' => $today], $a)
        ->qaStatus(201)->json('data.id');
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$action}", ['status' => 'pending_verification'], $b)->assertStatus(422);   // sin evidencia
    $file = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->image('senal.jpg')], $b)->qaStatus(201)->json('data.id');
    $this->postJson('/api/v1/records/ACR/action_evidences', ['corrective_action_id' => $action, 'evidence_type' => 'photo', 'description' => 'Señal instalada', 'file_id' => $file], $b)->qaStatus(201);
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$action}", ['status' => 'pending_verification'], $b)->qaStatus(200);
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$action}", ['status' => 'verified', 'effectiveness' => 'effective'], $b)->assertStatus(422);   // no verifica el responsable
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$action}", ['status' => 'verified', 'effectiveness' => 'effective'], $a)->qaStatus(200);
    expect($this->getJson("/api/v1/records/INS/findings/{$finding}", $a)->json('data.status'))->toBe('resolved')
        ->and($this->getJson("/api/v1/records/INS/inspections/{$ins}", $a)->json('data.status'))->toBe('closed');
    expect(qajKpi($a, 'corrective_actions_closed')['numerator'])->toBeGreaterThanOrEqual(1);

    // ------------------------------------------------------------ 11. Reporte de acto o condición por QR público → revisión → acción
    $point = $this->postJson('/api/v1/records/ACT/report_points', ['code' => 'QR-1', 'name' => 'Ingreso de planta', 'site_id' => $site], $a)->qaStatus(201)->json('data.id');
    [$pc, $pt] = array_slice(explode('/', (string) $this->getJson("/api/v1/act/report-points/{$point}/link", $a)->qaStatus(200)->json('data.url')), -2);
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/public/report/{$pc}/{$pt}")->qaStatus(200)->assertJsonPath('data.point', fn ($v) => $v !== null);
    $code = $this->postJson("/api/v1/public/report/{$pc}/{$pt}", ['report_type' => 'unsafe_condition', 'description' => 'Hay aceite derramado en el pasillo principal de la planta', 'is_anonymous' => true])
        ->qaStatus(201)->json('data.code');
    $rep = $this->getJson("/api/v1/records/ACT/substandard_reports?f[report_point_id]={$point}", $a)->qaStatus(200)->json('data.0');
    expect($rep['code'])->toBe($code)->and($rep['origin'])->toBe('qr')->and($rep['status'])->toBe('received');
    $this->patchJson("/api/v1/records/ACT/substandard_reports/{$rep['id']}", ['status' => 'triaged', 'risk_level' => 'high'], $a)->qaStatus(200);
    $this->postJson("/api/v1/acr/from/substandard_reports/{$rep['id']}", ['title' => 'Limpiar y contener derrames', 'responsible_user_id' => $bId, 'due_on' => now()->addDays(3)->toDateString()], $a)->qaStatus(201);
    expect($this->getJson("/api/v1/records/ACT/substandard_reports/{$rep['id']}", $a)->json('data.status'))->toBe('action_assigned');

    // ------------------------------------------------------------ 12. Capacitación: sesión, asistencia, certificado y verificación pública
    $tr = $this->postJson('/api/v1/records/CAP/trainings', ['title' => 'Inducción SST', 'training_type' => 'induction', 'modality' => 'in_person', 'duration_minutes' => 120,
        'certificate_validity_months' => 12, 'training_program_item_id' => $pcaItems[0]], $a)->qaStatus(201);
    expect($tr->json('data.code'))->toStartWith('CAP-');
    $tr = $tr->json('data.id');
    $sess = $this->postJson('/api/v1/records/CAP/training_sessions', ['training_id' => $tr, 'session_number' => 1, 'starts_at' => now()->subHours(3)->toIso8601String(),
        'ends_at' => now()->subHour()->toIso8601String(), 'site_id' => $site], $a)->qaStatus(201)->json('data.id');
    $this->postJson("/api/v1/cap/trainings/{$tr}/certificates", [], $a)->assertStatus(409);   // aún no se dictó
    foreach ([$rosa, $jorge] as $w) {
        $this->postJson('/api/v1/records/CAP/training_attendances', ['session_id' => $sess, 'worker_id' => $w, 'hours' => 2], $a)->qaStatus(201);
    }
    $this->patchJson("/api/v1/records/CAP/training_sessions/{$sess}", ['status' => 'held'], $a)->qaStatus(200);
    expect($this->getJson("/api/v1/records/PCA/training_program_items/{$pcaItems[0]}", $a)->json('data.status'))->toBe('done');
    expect($this->postJson("/api/v1/cap/trainings/{$tr}/certificates", [], $a)->qaStatus(200)->json('data.issued'))->toBe(2);
    $cert = $this->getJson("/api/v1/records/CAP/training_certificates?f[training_id]={$tr}", $a)->qaStatus(200)->json('data.0');
    expect($cert['certificate_code'])->toMatch('/^CERT-\d{4}-\d{6}$/');
    $vcode = $this->getJson("/api/v1/cap/certificates/{$cert['id']}/print", $a)->qaStatus(200)->json('data.verification_code');
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/public/verify/certificate/{$vcode}")->qaStatus(200)->assertJsonPath('data.result', 'not_public');
    $this->patchJson("/api/v1/records/CAP/training_certificates/{$cert['id']}", ['public_verification_enabled' => true], $a)->qaStatus(200);
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/public/verify/certificate/{$vcode}")->qaStatus(200)->assertJsonPath('data.result', 'valid');

    // ------------------------------------------------------------ 13. EMO: orden y aptitud por la médica
    $order = $this->postJson('/api/v1/records/EMO/medical_exam_orders', ['worker_id' => $rosa, 'exam_type' => 'periodic', 'clinic_name' => 'Clínica Ocupacional Lima',
        'scheduled_at' => now()->addHour()->toIso8601String()], $doc)->qaStatus(201)->json('data.id');
    $this->patchJson("/api/v1/records/EMO/medical_exam_orders/{$order}", ['status' => 'attended'], $doc)->qaStatus(200);
    $this->getJson("/api/v1/emo/orders/{$order}/clinical", $a)->assertForbidden();   // el Jefe SST no ve datos clínicos
    $reauth = $this->postJson('/api/v1/emo/reauth', ['password' => DEMO_PASSWORD], $doc)->qaStatus(200)->json('data.reauth_id');
    $med = $doc + ['X-Medical-Reauth' => $reauth];
    $this->getJson("/api/v1/emo/orders/{$order}/clinical", $med)->qaStatus(200)->assertJsonPath('data.can_issue_fitness', true);
    $this->putJson("/api/v1/emo/orders/{$order}/clinical", ['exam_on' => $today, 'examining_doctor_name' => 'Dra. Carmen Médica Ruiz', 'examining_doctor_cmp' => '54321',
        'results' => [['exam' => 'Espirometría', 'result' => 'Normal']], 'diagnoses' => [['code' => 'Z00.0', 'description' => 'Examen médico general']],
        'recommendations' => 'Continuar con EPP respiratorio', 'fitness_result' => 'fit', 'fitness_valid_until' => now()->addYear()->toDateString()], $med)
        ->qaStatus(201)->assertJsonPath('data.fitness_published', true);
    expect($this->getJson("/api/v1/records/EMO/medical_exam_orders/{$order}", $doc)->json('data.status'))->toBe('fitness_issued');
    expect($this->getJson("/api/v1/records/EMO/fitness_status?f[worker_id]={$rosa}", $a)->qaStatus(200)->json('meta.total'))->toBe(1);

    // ------------------------------------------------------------ 14. Fotocheck: evaluación de requisitos
    $ctype = $this->postJson('/api/v1/records/FOT/credential_types', ['code' => 'FC', 'name' => 'Fotocheck SST', 'applies_to' => 'workers', 'validity_months' => 12], $a)->qaStatus(201)->json('data.id');
    $this->postJson('/api/v1/records/FOT/credential_requirements', ['credential_type_id' => $ctype, 'requirement_type' => 'emo_fitness', 'description' => 'EMO vigente', 'is_blocking' => true], $a)->qaStatus(201);
    $evRosa = $this->getJson("/api/v1/fot/evaluate?worker_id={$rosa}&credential_type_id={$ctype}", $a)->qaStatus(200);
    $evLucia = $this->getJson("/api/v1/fot/evaluate?worker_id={$lucia}&credential_type_id={$ctype}", $a)->qaStatus(200);
    expect($evLucia->json('data.overall'))->toBe('bad')->and($evRosa->json('data.overall'))->not->toBe('bad');
    $this->getJson('/api/v1/fot/summary', $a)->qaStatus(200);

    // ------------------------------------------------------------ 15. Accidente: registro, notificación, investigación con causas y acciones
    $ev = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'disabling_accident', 'occurred_at' => now()->subDay()->toIso8601String(), 'site_id' => $site,
        'description' => 'Corte en la mano al manipular plancha metálica'], $a)->qaStatus(201);
    expect($ev->json('data.code'))->toStartWith('ACC-')->and($ev->json('data.notification_deadline_at'))->not->toBeNull();
    $ev = $ev->json('data.id');
    $this->postJson('/api/v1/records/ACC/event_people', ['event_id' => $ev, 'worker_id' => $jorge, 'person_role' => 'injured', 'lost_days' => 5, 'injury_type' => 'Corte', 'body_part' => 'Mano'], $a)->qaStatus(201);
    expect($this->getJson("/api/v1/records/ACC/sst_events/{$ev}", $a)->json('data.lost_days'))->toBe(5);
    $notif = $this->getJson("/api/v1/records/ACC/event_notifications?f[event_id]={$ev}", $a)->qaStatus(200)->json('data.0.id');
    $this->patchJson("/api/v1/records/ACC/event_notifications/{$notif}", ['status' => 'sent', 'notified_at' => now()->toIso8601String(), 'notification_number' => 'SAT-2026-001',
        'channel' => 'sat_online'], $a)->qaStatus(200)->assertJsonPath('data.notification_overdue', false);
    $inv = $this->getJson("/api/v1/records/INV/investigations?f[event_id]={$ev}", $a)->qaStatus(200)->json('data.0.id');
    expect($inv)->not->toBeNull();
    $this->postJson('/api/v1/records/INV/investigation_causes', ['investigation_id' => $inv, 'cause_level' => 'immediate', 'cause_category' => 'unsafe_act', 'description' => 'No usó guantes anticorte'], $a)->qaStatus(201);
    $basic = $this->postJson('/api/v1/records/INV/investigation_causes', ['investigation_id' => $inv, 'cause_level' => 'basic', 'cause_category' => 'personal_factor', 'description' => 'Falta de capacitación en manipulación'], $a)
        ->qaStatus(201)->json('data.id');
    $this->postJson('/api/v1/records/INV/investigation_team', ['investigation_id' => $inv, 'worker_id' => $rosa, 'role' => 'leader'], $a)->qaStatus(201);
    $this->postJson("/api/v1/acr/from/investigation_causes/{$basic}", ['title' => 'Capacitar en manipulación de planchas', 'responsible_user_id' => $bId, 'due_on' => now()->addDays(15)->toDateString()], $a)->qaStatus(201);
    $this->postJson("/api/v1/acr/from/investigation_causes/{$basic}", ['title' => 'Repetida', 'responsible_user_id' => $bId, 'due_on' => now()->addDays(15)->toDateString()], $a)->assertStatus(409);
    $this->patchJson("/api/v1/records/INV/investigations/{$inv}", ['status' => 'approved', 'conclusions' => 'Se requiere capacitación y guantes'], $a)->qaStatus(200);
    $this->getJson("/api/v1/inv/investigations/{$inv}/report", $a)->qaStatus(200);

    // ------------------------------------------------------------ 16. Horas-hombre y cierre del mes (IF, IG, IA)
    // Toda sede activa declara sus horas (incluida la principal que creó el alta) antes de cerrar el mes.
    $allSites = collect($this->getJson('/api/v1/records/TEN/sites?per_page=100', $a)->qaStatus(200)->json('data'))->pluck('id');
    $hoursBySite = $allSites->mapWithKeys(fn ($s) => [$s => [$s, 1, 200]])->all();
    $hoursBySite[$site] = [$site, 9, 1800];
    $hoursBySite[$site2] = [$site2, 3, 600];
    foreach ($hoursBySite as [$s, $n, $hours]) {
        $this->postJson('/api/v1/records/EST/man_hours', ['period_year' => $year, 'period_month' => $month, 'site_id' => $s, 'worker_count' => $n, 'hours_worked' => $hours], $a)->qaStatus(201);
    }
    $stats = collect($this->postJson('/api/v1/est/months', ['year' => $year, 'month' => $month], $a)->qaStatus(200)->json('data'))->keyBy('site_id');
    expect((float) $stats[$site]['frequency_index'])->toBe(round(1 * 1000000 / 1800, 2))
        ->and((float) $stats[$site]['severity_index'])->toBeGreaterThan(0)
        ->and((float) $stats[$site]['accident_rate_index'])->toBeGreaterThan(0)
        ->and((float) $stats[$site2]['frequency_index'])->toBe(0.0);
    // El cierre es del mes de la empresa (todas sus sedes a la vez) y no se repite.
    $ms = $this->getJson("/api/v1/records/EST/monthly_statistics?f[site_id]={$site}&f[period_month]={$month}", $a)->qaStatus(200)->json('data.0.id');
    $this->postJson("/api/v1/est/months/{$ms}/close", [], $a)->qaStatus(200)->assertJsonPath('data.closed', true);
    foreach ($allSites as $s) {
        $other = $this->getJson("/api/v1/records/EST/monthly_statistics?f[site_id]={$s}&f[period_month]={$month}", $a)->qaStatus(200)->json('data.0.id');
        $this->postJson("/api/v1/est/months/{$other}/close", [], $a)->assertStatus(409);
    }
    expect((float) $this->getJson("/api/v1/est/summary?year={$year}", $a)->qaStatus(200)->json('data.months.'.($month - 1).'.hours'))->toBe(2400.0 + 200 * ($allSites->count() - 2));
    expect(qajKpi($a, 'frequency_index')['value'])->not->toBeNull();

    // ------------------------------------------------------------ 17. Registros obligatorios en Excel
    $types = collect($this->getJson('/api/v1/reg/types', $a)->qaStatus(200)->json('data'))->pluck('type');
    expect($types)->not->toBeEmpty();
    foreach ($types as $type) {
        $x = $this->get("/api/v1/reg/{$type}.xlsx?from={$year}-01-01&to={$year}-12-31", $a);
        expect($x->status())->toBe(200, "registro {$type}")->and(substr((string) ($x->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $x->streamedContent() : $x->getContent()), 0, 2))->toBe('PK');
    }

    // ------------------------------------------------------------ 18. Modo SUNAFIL: autodiagnóstico, expediente y enlace público
    $diag = $this->getJson('/api/v1/sun/diagnosis', $a)->qaStatus(200)->json('data.areas');
    expect($diag)->not->toBeEmpty();
    // El autodiagnóstico se calcula con los datos de los módulos (la tabla «self_diagnostics» hoy no admite el alta manual: su campo «items» es obligatorio y de solo lectura).
    expect(collect($diag)->pluck('pct')->filter(fn ($v) => $v !== null))->not->toBeEmpty();
    $dossier = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente inspección', 'period_from' => "{$year}-01-01", 'period_to' => $today], $a)->qaStatus(201)->json('data.id');
    $this->postJson("/api/v1/sun/dossiers/{$dossier}/share", ['days' => 3], $a)->assertStatus(409);   // primero se arma
    $built = $this->postJson("/api/v1/sun/dossiers/{$dossier}/build", [], $a)->qaStatus(200);
    expect($built->json('data.total'))->toBeGreaterThan(0);
    $link = $this->postJson("/api/v1/sun/dossiers/{$dossier}/share", ['days' => 3, 'max_views' => 5, 'recipient_name' => 'Inspector SUNAFIL'], $a)->qaStatus(201)
        ->assertJsonPath('data.status', 'active')->json('data.url');
    [$sc, $st] = array_slice(explode('/', (string) $link), -2);
    app('auth')->forgetGuards();
    $pub = $this->getJson("/api/v1/public/sun/{$sc}/{$st}")->qaStatus(200);
    expect($pub->json('data.dossier.title'))->toBe('Expediente inspección')->and($pub->json('data.views_left'))->toBe(4);
    expect(collect($this->getJson("/api/v1/sun/dossiers/{$dossier}/share-links", $a)->qaStatus(200)->json('data'))->first()['view_count'])->toBe(1);

    // ------------------------------------------------------------ 19. «Escribir al equipo»: consulta respondida por la plataforma
    $ticket = $this->postJson('/api/v1/me/support-tickets', ['topic' => 'question', 'subject' => 'QA RECORRIDO ¿Cómo cierro el mes?', 'message' => 'Quiero saber cómo cerrar las estadísticas.'], $admin)
        ->qaStatus(201)->assertJsonPath('data.status', 'open')->json('data');
    expect($ticket['code'])->toStartWith('CON-');
    $platform = authHeaders(tokenFor(ADMIN_EMAIL));
    expect(collect($this->getJson('/api/v1/internal/support-tickets?status=open', $platform)->qaStatus(200)->json('data'))->pluck('id'))->toContain($ticket['id']);
    $this->postJson("/api/v1/internal/support-tickets/{$ticket['id']}/reply", ['reply' => 'En Estadísticas › Cerrar mes.'], $platform)->qaStatus(200)->assertJsonPath('data.status', 'answered');
    $back = collect($this->getJson('/api/v1/me/support-tickets', authHeaders($adminToken, $cid))->qaStatus(200)->json('data'))->firstWhere('id', $ticket['id']);
    expect($back['status'])->toBe('answered')->and($back['reply'])->toBe('En Estadísticas › Cerrar mes.');

    // ------------------------------------------------------------ Pantallas de detalle con los registros del recorrido
    // (los GET con identificador que ModuleEndpointsTest omite cuando la empresa de prueba no tiene registros): ninguno da 500.
    $details = [
        [$a, "trb/imports/{$importId}"], [$a, "trb/workers/{$rosa}/app-access"], [$a, "act/report-points/{$point}/link"],
        [$a, "ipc/matrices/{$matrix}/workspace"], [$a, "ipc/versions/{$version}/export.pdf"], [$a, "ipc/versions/{$version}/export.xlsx"],
        [$a, "fir/subject/iperc_versions/{$version}"], [$a, "fir/verify/iperc_versions/{$version}"],
        [$a, "ins/inspections/{$ins}/checklist"], [$a, "cap/certificates/{$cert['id']}/print"],
        [$a, "inv/investigations/{$inv}/report"], [$a, "inv/investigations/{$inv}/scat"], [$a, "inv/investigations/{$inv}/tree"], [$a, "inv/investigations/{$inv}/signatures"],
        [$a, "sun/dossiers/{$dossier}/print"], [$a, "sun/dossiers/{$dossier}/zip"], [$a, "sun/dossiers/{$dossier}/share-links"],
        [$med, "emo/orders/{$order}/clinical"], [$med, "emo/workers/{$rosa}/history"], [$a, "files/{$file}"],
    ];
    foreach ($details as [$who, $path]) {
        $r = $this->get("/api/v1/{$path}", $who + ['Accept' => 'application/json']);
        $code = $r->baseResponse->getStatusCode();
        expect($code)->toBeLessThan(500, "GET {$path} → {$code}: ".mb_substr((string) $r->baseResponse->getContent(), 0, 400))->and($code)->not->toBe(401);
    }

    // ------------------------------------------------------------ 20. Tablero y bitácora con el rastro de todo lo hecho
    $overview = $this->getJson("/api/v1/dsh/overview?year={$year}", $a)->qaStatus(200);
    expect($overview->json('data.counts.workers'))->toBe(3)->and($overview->json('data.counts.sites'))->toBe($sitesBefore + 2);
    $kpis = collect($overview->json('data.kpis'))->keyBy('code');
    expect($kpis['baseline_score']['value'])->not->toBeNull()
        ->and($kpis['substandard_reports']['value'])->not->toBeNull()
        ->and($kpis['passt_progress']['value'])->not->toBeNull();

    $audit = $this->getJson('/api/v1/company/audit?per_page=100', authHeaders($adminToken, $cid))->qaStatus(200)->json('data');
    expect(count($audit))->toBeGreaterThan(20);
    $byEntity = fn (string $id) => collect($this->getJson("/api/v1/company/audit?entity_id={$id}", authHeaders($adminToken, $cid))->qaStatus(200)->json('data'))->pluck('action');
    expect($byEntity($pol))->toContain('create')->toContain('update')
        ->and($byEntity($action))->not->toBeEmpty()
        ->and($byEntity($site))->toContain('create');
})->group('journey');
