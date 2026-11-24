<?php

// Límites del plan (docs/03 §5 y §8, docs/08 §7): al llegar al tope no se crea ni se reactiva más (403 plan_limit_reached);
// lo existente no se bloquea ni se borra; límite vacío = sin límite.
//
// Los planes están en la conexión 'platform', que no entra en la transacción de la prueba: se guardan sus límites antes
// de cada prueba y se restauran después.

use App\Core\Auth\Permissions;
use App\Core\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const PL_COLS = ['max_workers', 'max_sites', 'max_management_users', 'storage_gb', 'qr_report_points'];

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    $this->plansSnapshot = DB::connection('platform')->select('select id, '.implode(', ', PL_COLS).' from plans');
    $this->apiFlag = DB::connection('platform')->selectOne("select id, mode from feature_flags where company_id = ? and module_code = 'API' and deleted_at is null", [HUAYLAS_ID]);
});

afterEach(function () {
    $db = DB::connection('platform');
    foreach ($this->plansSnapshot as $p) {
        $row = (array) $p;
        unset($row['id']);
        $db->table('plans')->where('id', $p->id)->update($row);
    }
    if ($this->apiFlag) {
        $db->update('update feature_flags set mode = ? where id = ?', [$this->apiFlag->mode, $this->apiFlag->id]);
    }
    Permissions::flush();
});

function plSet(string $plan, string $col, ?int $value): void
{
    DB::connection('platform')->update("update plans set {$col} = ? where code = ?", [$value, $plan]);
}

function plUsed(string $companyId, string $kind): int
{
    DB::select("select set_config('app.company_id', ?, false)", [$companyId]);

    return (int) match ($kind) {
        'workers' => DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active', 'on_leave')")->n,
        'sites' => DB::selectOne("select count(*) as n from sites where deleted_at is null and status = 'active'")->n,
        'management_users' => DB::selectOne("select count(*) as n from memberships m join roles r on r.id = m.role_id
                                               where m.company_id = ? and m.deleted_at is null and not m.is_support_session and m.status in ('invited', 'active')
                                                 and (m.expires_at is null or m.expires_at > now())
                                                 and coalesce(r.base_role_code, r.code) not in ('worker', 'external_learner', 'contractor_admin', 'external_auditor', 'consultant', 'platform_owner', 'platform_support')", [$companyId])->n,
        'qr_points' => DB::selectOne('select count(*) as n from report_points where deleted_at is null and is_active')->n,
        'storage' => DB::selectOne("select coalesce(sum(size_bytes), 0) as n from files where deleted_at is null and status = 'available'")->n,
    };
}

function plH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

function plWorker(string $doc, array $extra = []): TestResponse
{
    return test()->postJson('/api/v1/records/TRB/workers', ['document_number' => $doc, 'first_name' => 'Rosa', 'paternal_last_name' => 'Límite'] + $extra, plH());
}

function plDni(): string
{
    return (string) random_int(40000000, 49999999);
}

it('trabajadores: al llegar al límite no se crean más e invita a mejorar el plan', function () {
    $used = plUsed(ANDINAS_ID, 'workers');
    plSet('P', 'max_workers', $used + 2);

    plWorker(plDni())->assertCreated();
    plWorker(plDni())->assertCreated();
    $res = plWorker(plDni())->assertForbidden()
        ->assertJsonPath('type', 'plan_limit_reached')
        ->assertJsonPath('kind', 'workers')
        ->assertJsonPath('limit', $used + 2)
        ->assertJsonPath('used', $used + 2)
        ->assertJsonPath('upgrade_url', '/cuenta/suscripcion');
    expect($res->json('detail'))->toBe('Llegaste al límite de tu plan: '.($used + 2).' trabajadores. Mejora tu plan para agregar más.');
    expect(plUsed(ANDINAS_ID, 'workers'))->toBe($used + 2);

    // Un cesado no ocupa cupo: registrarlo no está bloqueado.
    plWorker(plDni(), ['status' => 'terminated', 'termination_on' => '2026-01-31'])->assertCreated();

    // Sin límite (vacío) se crean sin tope.
    plSet('P', 'max_workers', null);
    plWorker(plDni())->assertCreated();
});

it('trabajadores: lo existente por encima del límite no se bloquea, pero reactivar un cesado cuenta como alta', function () {
    $any = plWorker(plDni())->assertCreated()->json('data.id');
    plWorker(plDni())->assertCreated();
    $id = plWorker(plDni())->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/TRB/workers/{$id}", ['status' => 'terminated', 'termination_on' => '2026-11-05', 'termination_reason' => 'Fin de contrato'], plH())->assertOk();
    $before = plUsed(ANDINAS_ID, 'workers');
    expect($before)->toBeGreaterThan(1);

    // Plan con tope por debajo de lo que ya hay: nada se borra ni se bloquea para editar.
    plSet('P', 'max_workers', 1);
    $this->patchJson("/api/v1/records/TRB/workers/{$any}", ['mobile_phone' => '987654321'], plH())->assertOk();
    $this->getJson('/api/v1/records/TRB/workers', plH())->assertOk();
    expect(plUsed(ANDINAS_ID, 'workers'))->toBe($before);

    $this->patchJson("/api/v1/records/TRB/workers/{$id}", ['status' => 'active'], plH())->assertForbidden()->assertJsonPath('type', 'plan_limit_reached');
    plSet('P', 'max_workers', null);
    $this->patchJson("/api/v1/records/TRB/workers/{$id}", ['status' => 'active'], plH())->assertOk();
});

it('trabajadores: la carga por Excel crea hasta el límite y marca el resto con el aviso', function () {
    $h = plH();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'PL-S', 'name' => 'Planta Límite'], $h)->assertCreated()->json('data.id');
    $area = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'PL-A', 'name' => 'Área Límite'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/TEN/positions', ['code' => 'PL-P', 'name' => 'Operario límite', 'area_id' => $area], $h)->assertCreated();
    plSet('P', 'max_workers', plUsed(ANDINAS_ID, 'workers') + 1);

    $head = ['Tipo de documento', 'Número de documento', 'Nombres', 'Apellido paterno', 'Apellido materno', 'Fecha de nacimiento', 'Sexo', 'Celular', 'Correo',
        'Fecha de ingreso', 'Tipo de vínculo', 'Sede', 'Área', 'Puesto', 'Código de empleado'];
    $rows = [['DNI', plDni(), 'Uno', 'Carga', '', '', '', '', '', '', '', 'PL-S', '', 'PL-P', ''], ['DNI', plDni(), 'Dos', 'Carga', '', '', '', '', '', '', '', 'PL-S', '', 'PL-P', '']];
    $file = UploadedFile::fake()->createWithContent('trabajadores.xlsx', Xlsx::build('Trabajadores', [['EscudoSST · Plantilla de trabajadores · versión TRB-W1'], ['Instrucciones'], ['Sedes'], ['Puestos'], $head, ...$rows], 5));

    $r = $this->post('/api/v1/trb/imports', ['file' => $file, 'mode' => 'create'], $h)->assertOk();
    expect($r->json('data.created'))->toBe(1)->and($r->json('data.failed'))->toBe(1);
    expect(collect($r->json('data.rows'))->firstWhere('result', 'error')['errors'][0])->toContain('Llegaste al límite de tu plan');
});

it('trabajadores: la API externa también respeta el límite', function () {
    DB::connection('platform')->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'API'", [HUAYLAS_ID]);
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update memberships set role_id = (select id from roles where code = 'account_admin' and account_id is null limit 1)
                 where company_id = ? and user_id = (select id from app_auth_lookup_user(?))", [HUAYLAS_ID, CORPORATIVO_EMAIL]);
    Permissions::flush();
    $c = $this->postJson('/api/v1/records/API/api_clients', ['name' => 'Planillas', 'scopes' => ['workers.read', 'workers.write']], $h)->assertCreated();
    $t = $this->postJson('/api/v1/ext/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->json('data.client_key'), 'client_secret' => $c->json('data.secret')],
        ['X-Company-Id' => HUAYLAS_ID])->assertOk()->json('access_token');
    $bearer = ['X-Company-Id' => HUAYLAS_ID, 'Authorization' => 'Bearer '.$t];
    $this->app['auth']->forgetGuards();

    plSet('C', 'max_workers', plUsed(HUAYLAS_ID, 'workers'));
    $this->postJson('/api/v1/ext/v1/workers', ['document_number' => plDni(), 'first_name' => 'Ana', 'paternal_last_name' => 'Planilla'], $bearer + ['Idempotency-Key' => 'pl-'.Str::random(10)])
        ->assertForbidden()->assertJsonPath('type', 'plan_limit_reached')->assertJsonPath('kind', 'workers');
    plSet('C', 'max_workers', null);
    $this->postJson('/api/v1/ext/v1/workers', ['document_number' => plDni(), 'first_name' => 'Ana', 'paternal_last_name' => 'Planilla'], $bearer + ['Idempotency-Key' => 'pl-'.Str::random(10)])
        ->assertCreated();
});

it('sedes: al llegar al límite no se crean ni se reactivan más', function () {
    $h = plH();
    $inactive = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'PL-IN', 'name' => 'Sede inactiva', 'status' => 'inactive'], $h)->assertCreated()->json('data.id');
    plSet('P', 'max_sites', plUsed(ANDINAS_ID, 'sites'));

    $res = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'PL-X', 'name' => 'Sede extra'], $h)->assertForbidden()->assertJsonPath('kind', 'sites');
    expect($res->json('detail'))->toContain('Llegaste al límite de tu plan')->toContain('Mejora tu plan para agregar más.');
    $this->patchJson("/api/v1/records/TEN/sites/{$inactive}", ['status' => 'active'], $h)->assertForbidden();
    // Una sede inactiva no ocupa cupo.
    $this->postJson('/api/v1/records/TEN/sites', ['code' => 'PL-IN2', 'name' => 'Otra inactiva', 'status' => 'inactive'], $h)->assertCreated();

    plSet('P', 'max_sites', plUsed(ANDINAS_ID, 'sites') + 1);
    $this->postJson('/api/v1/records/TEN/sites', ['code' => 'PL-OK', 'name' => 'Sede dentro del límite'], $h)->assertCreated();
});

it('usuarios de gestión: al llegar al límite no se invita a más; los trabajadores no cuentan', function () {
    plSet('P', 'max_management_users', plUsed(ANDINAS_ID, 'management_users'));
    $email = fn () => 'limite.'.Str::lower(Str::random(8)).'@escudo.test';

    $res = $this->postJson('/api/v1/company/users', ['email' => $email(), 'full_name' => 'Sara Supervisora', 'role_code' => 'sst_supervisor'], plH())
        ->assertForbidden()->assertJsonPath('type', 'plan_limit_reached')->assertJsonPath('kind', 'management_users');
    expect($res->json('detail'))->toContain('usuario')->toContain('de gestión');

    // Invitación masiva: cada trabajador queda con el motivo.
    $w = plWorker(plDni(), ['personal_email' => $email()])->assertCreated()->json('data.id');
    $bulk = $this->postJson('/api/v1/company/users/bulk', ['worker_ids' => [$w], 'role_code' => 'sst_supervisor'], plH())->assertCreated();
    expect($bulk->json('invited'))->toBe(0)->and($bulk->json('data.0.reason'))->toContain('Llegaste al límite de tu plan');

    // Pasar a un rol de gestión a alguien que no lo era también ocupa un cupo.
    plSet('P', 'max_management_users', null);
    $m = $this->postJson('/api/v1/company/users', ['email' => $email(), 'full_name' => 'Auditor Externo', 'role_code' => 'external_auditor', 'expires_on' => now()->addMonth()->toDateString()], plH())
        ->assertCreated()->json('data.id');
    plSet('P', 'max_management_users', plUsed(ANDINAS_ID, 'management_users'));
    $this->patchJson("/api/v1/company/users/{$m}", ['role_code' => 'sst_supervisor'], plH())->assertForbidden()->assertJsonPath('type', 'plan_limit_reached');

    plSet('P', 'max_management_users', plUsed(ANDINAS_ID, 'management_users') + 1);
    $this->postJson('/api/v1/company/users', ['email' => $email(), 'full_name' => 'Sara Supervisora', 'role_code' => 'sst_supervisor'], plH())->assertCreated();
});

it('puntos de reporte QR: al llegar al límite no se crean más activos', function () {
    $h = plH();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'PL-QR', 'name' => 'Sede QR'], $h)->assertCreated()->json('data.id');
    plSet('P', 'qr_report_points', plUsed(ANDINAS_ID, 'qr_points'));

    $this->postJson('/api/v1/records/ACT/report_points', ['code' => 'QR-L1', 'name' => 'Ingreso', 'site_id' => $site], $h)
        ->assertForbidden()->assertJsonPath('kind', 'qr_points');
    plSet('P', 'qr_report_points', plUsed(ANDINAS_ID, 'qr_points') + 1);
    $this->postJson('/api/v1/records/ACT/report_points', ['code' => 'QR-L2', 'name' => 'Ingreso', 'site_id' => $site], $h)->assertCreated();
    $this->postJson('/api/v1/records/ACT/report_points', ['code' => 'QR-L3', 'name' => 'Comedor', 'site_id' => $site], $h)->assertForbidden();
});

it('almacenamiento: con el espacio del plan lleno no se suben más archivos', function () {
    $before = plUsed(ANDINAS_ID, 'storage');
    $gb = max(1, (int) ceil($before / 1073741824));
    // Un archivo grande ya subido deja el uso a 1 KB del tope: el siguiente (20 KB) ya no entra.
    DB::insert("insert into files (id, company_id, bucket, object_key, original_name, mime_type, size_bytes, sha256, status, exif_stripped)
                values (?, ?, 'local', 'company/limite/grande.pdf', 'grande.pdf', 'application/pdf', ?, decode(repeat('00', 32), 'hex'), 'available', false)",
        [(string) Str::uuid7(), ANDINAS_ID, $gb * 1073741824 - $before - 1024]);
    plSet('P', 'storage_gb', $gb);

    $file = fn () => ['file' => UploadedFile::fake()->create('evidencia.pdf', 20, 'application/pdf'), 'module' => 'PAS'];
    $res = $this->post('/api/v1/files', $file(), plH() + ['Accept' => 'application/json'])->assertForbidden()
        ->assertJsonPath('type', 'plan_limit_reached')->assertJsonPath('kind', 'storage')->assertJsonPath('unit', 'GB');
    expect($res->json('detail'))->toBe("Llegaste al límite de almacenamiento de tu plan: {$gb} GB. Mejora tu plan para subir más archivos.");

    plSet('P', 'storage_gb', $gb + 1);
    $this->post('/api/v1/files', $file(), plH() + ['Accept' => 'application/json'])->assertCreated();
    plSet('P', 'storage_gb', null);
    $this->post('/api/v1/files', $file(), plH() + ['Accept' => 'application/json'])->assertCreated();
});
