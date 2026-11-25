<?php

// M-API y docs/08: aptitud por la API solo con «fitness.read» aprobado (RN-API-01, nunca datos médicos), paginación por cursor,
// If-Match / ETag en las ediciones (API externa y fichas de registros) y el contrato OpenAPI de la API pública (api/openapi.yaml).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->flag = DB::connection('platform')->selectOne("select id, mode from feature_flags where company_id = ? and module_code = 'API' and deleted_at is null", [HUAYLAS_ID]);
    DB::connection('platform')->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'API'", [HUAYLAS_ID]);
    Permissions::flush();
});

afterEach(function () {
    if ($this->flag) {
        DB::connection('platform')->update('update feature_flags set mode = ? where id = ?', [$this->flag->mode, $this->flag->id]);
    }
    Permissions::flush();
});

function acfAdmin(string $role = 'account_admin'): array
{
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update memberships set role_id = (select id from roles where code = ? and account_id is null limit 1)
                 where company_id = ? and user_id = (select id from app_auth_lookup_user(?))", [$role, HUAYLAS_ID, CORPORATIVO_EMAIL]);
    Permissions::flush();

    return $h;
}

/** Trabajador de prueba en Huaylas (dentro de la transacción de la prueba). */
function acfWorker(string $name = 'Prueba'): string
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);

    return DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status)
                          values (?, ?, decode(md5(random()::text), 'hex'), ?, 'Api', 'active') returning id", [HUAYLAS_ID, (string) random_int(10000000, 99999999), $name])->id;
}

function acfExt(object|array $c): array
{
    $c = (array) $c;

    return ['X-Company-Id' => HUAYLAS_ID, 'X-Api-Key' => $c['client_key'], 'X-Api-Secret' => $c['secret']];
}

it('entrega solo la aptitud con fitness.read aprobado por la empresa, nunca con read o *', function () {
    $h = acfAdmin();
    $w = acfWorker('Aptitud');
    DB::update('update fitness_status set is_current = false where worker_id = ?', [$w]);
    DB::insert("insert into fitness_status (company_id, worker_id, status, valid_from, valid_until, work_restrictions, is_current)
                values (?, ?, 'fit_with_restrictions', current_date - 10, current_date + 355, 'No levantar más de 15 kg', true)", [HUAYLAS_ID, $w]);

    $all = $this->postJson('/api/v1/records/API/api_clients', ['name' => 'Todo', 'scopes' => ['*', 'read']], $h)->assertCreated()->json('data');
    $this->getJson('/api/v1/ext/v1/fitness_status', acfExt($all))->assertForbidden()->assertJsonPath('detail', fn ($d) => str_contains($d, 'fitness.read'));
    $this->getJson('/api/v1/ext/v1/medical_exams', acfExt($all))->assertForbidden();

    $fit = $this->postJson('/api/v1/records/API/api_clients', ['name' => 'Control de acceso', 'scopes' => ['fitness.read']], $h)->assertCreated()->json('data');
    // Sin aprobación todavía.
    $this->getJson('/api/v1/ext/v1/fitness_status', acfExt($fit))->assertForbidden()->assertJsonPath('title', 'Falta la aprobación');
    // Un cliente sin el alcance no se puede aprobar.
    $this->postJson("/api/v1/api-clients/{$all['id']}/fitness-approval", [], $h)->assertStatus(409);
    $this->postJson("/api/v1/api-clients/{$fit['id']}/fitness-approval", [], $h)->assertOk()->assertJsonPath('data.id', $fit['id']);

    $r = $this->getJson('/api/v1/ext/v1/fitness_status?limit=200', acfExt($fit))->assertOk();
    $row = collect($r->json('data'))->firstWhere('worker_id', $w);
    expect($row['status'])->toBe('fit_with_restrictions')->and($row['work_restrictions'])->toBe('No levantar más de 15 kg')
        ->and(array_keys($row))->toEqualCanonicalizing(['id', 'worker_id', 'status', 'work_restrictions', 'valid_from', 'valid_until', 'updated_at']);
    // El mismo cliente no lee otros recursos.
    $this->getJson('/api/v1/ext/v1/workers', acfExt($fit))->assertForbidden();

    // Quitar «fitness.read» de los permisos borra la aprobación; retirarla también corta el acceso.
    $this->patchJson("/api/v1/records/API/api_clients/{$fit['id']}", ['scopes' => ['workers.read']], $h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select fitness_approved_at from api_clients where id = ?', [$fit['id']])->fitness_approved_at)->toBeNull();
    $this->patchJson("/api/v1/records/API/api_clients/{$fit['id']}", ['scopes' => ['fitness.read']], $h)->assertOk();
    $this->getJson('/api/v1/ext/v1/fitness_status', acfExt($fit))->assertForbidden();
    $this->postJson("/api/v1/api-clients/{$fit['id']}/fitness-approval", [], $h)->assertOk();
    $this->getJson('/api/v1/ext/v1/fitness_status', acfExt($fit))->assertOk();
    $this->deleteJson("/api/v1/api-clients/{$fit['id']}/fitness-approval", [], $h)->assertOk();
    $this->getJson('/api/v1/ext/v1/fitness_status', acfExt($fit))->assertForbidden();

    // La aprueba el administrador de la cuenta o el médico ocupacional; el Jefe SST no.
    acfAdmin('sst_manager');
    $this->postJson("/api/v1/api-clients/{$fit['id']}/fitness-approval", [], $h)->assertForbidden();
});

it('pagina por cursor sin repetir ni saltar registros y mantiene page por compatibilidad', function () {
    $h = acfAdmin();
    $c = $this->postJson('/api/v1/records/API/api_clients', ['name' => 'ERP', 'scopes' => ['workers.read']], $h)->assertCreated()->json('data');
    $ext = acfExt($c);
    foreach (['Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco'] as $n) {
        acfWorker($n);
    }
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $total = (int) DB::selectOne('select count(*) as n from workers where company_id = ? and deleted_at is null', [HUAYLAS_ID])->n;
    expect($total)->toBeGreaterThan(2);

    $seen = [];
    $cursor = null;
    for ($i = 0; $i < 50; $i++) {
        $r = $this->getJson('/api/v1/ext/v1/workers?limit=2'.($cursor ? '&cursor='.$cursor : ''), $ext)->assertOk();
        expect($r->json('meta.limit'))->toBe(2)->and(count($r->json('data')))->toBeLessThanOrEqual(2);
        $seen = [...$seen, ...array_column($r->json('data'), 'id')];
        $cursor = $r->json('meta.next_cursor');
        if (! $r->json('meta.has_more')) {
            expect($cursor)->toBeNull();
            break;
        }
    }
    expect(count($seen))->toBe($total)->and(array_unique($seen))->toHaveCount($total);

    $this->getJson('/api/v1/ext/v1/workers?page=1', $ext)->assertOk()->assertJsonPath('meta.page', 1)->assertJsonStructure(['meta' => ['page', 'has_more', 'next_cursor', 'limit']]);
    $this->getJson('/api/v1/ext/v1/workers?cursor=basura', $ext)->assertStatus(400);
    $this->getJson('/api/v1/ext/v1/workers?limit=500', $ext)->assertStatus(400);
});

it('rechaza con 409 la edición con una versión vieja (If-Match) en la API externa y en las fichas', function () {
    $h = acfAdmin();
    $c = $this->postJson('/api/v1/records/API/api_clients', ['name' => 'Planillas', 'scopes' => ['workers.read', 'workers.write']], $h)->assertCreated()->json('data');
    $ext = acfExt($c);
    $w = $this->postJson('/api/v1/ext/v1/workers', ['document_number' => (string) random_int(50000000, 59999999), 'first_name' => 'Etag', 'paternal_last_name' => 'Concurrente'], $ext)
        ->assertCreated()->json('data.id');
    $list = collect($this->getJson('/api/v1/ext/v1/workers?limit=200', $ext)->json('data'))->firstWhere('id', $w)
        ?? collect($this->getJson('/api/v1/ext/v1/workers?limit=200&page=2', $ext)->json('data'))->firstWhere('id', $w);
    $v1 = $list['row_version'];
    expect($v1)->toBeString();

    $ok = $this->patchJson("/api/v1/ext/v1/workers/{$w}", ['employee_code' => 'E-1'], $ext + ['If-Match' => '"'.$v1.'"'])->assertOk();
    $v2 = trim((string) $ok->headers->get('ETag'), '"');
    expect($v2)->not->toBe($v1)->and($ok->json('data.row_version'))->toBe($v2);
    // Con la versión vieja: 409 y no se escribe.
    $this->patchJson("/api/v1/ext/v1/workers/{$w}", ['employee_code' => 'E-2'], $ext + ['If-Match' => $v1])->assertStatus(409)->assertJsonPath('type', 'conflict');
    $this->patchJson("/api/v1/ext/v1/workers/{$w}", ['employee_code' => 'E-3'], $ext + ['If-Match' => $v2])->assertOk();
    // Sin If-Match se guarda como siempre.
    $this->patchJson("/api/v1/ext/v1/workers/{$w}", ['employee_code' => 'E-4'], $ext)->assertOk();

    // Fichas de registros: el ETag de la ficha (o row_version en el cuerpo) protege la edición.
    acfAdmin('sst_manager');
    $show = $this->getJson("/api/v1/records/TRB/workers/{$w}", $h)->assertOk();
    $etag = (string) $show->headers->get('ETag');
    expect(trim($etag, '"'))->toBe($show->json('data.row_version'));
    $this->patchJson("/api/v1/records/TRB/workers/{$w}", ['employee_code' => 'F-1', 'row_version' => $show->json('data.row_version')], $h)->assertOk();
    $this->patchJson("/api/v1/records/TRB/workers/{$w}", ['employee_code' => 'F-2'], $h + ['If-Match' => $etag])->assertStatus(409);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select employee_code from workers where id = ?', [$w])->employee_code)->toBe('F-1');
    $this->patchJson("/api/v1/records/TRB/workers/{$w}", ['employee_code' => 'F-3'], $h + ['If-Match' => '*'])->assertOk();
});

it('el contrato OpenAPI describe todas las rutas públicas ext/ con sus métodos', function () {
    $file = base_path('../api/openapi.yaml');
    expect(is_file($file))->toBeTrue('No se encontró api/openapi.yaml (en Docker se monta en /var/www/api, ver compose.dev.yml).');
    $doc = Yaml::parseFile($file);
    expect($doc['openapi'])->toStartWith('3.')->and($doc['servers'][0]['url'])->toBe('/api/v1');
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/ext/'));
    expect($routes)->not->toBeEmpty();
    foreach ($routes as $r) {
        $path = '/'.substr($r->uri(), strlen('api/v1/'));
        expect(array_key_exists($path, $doc['paths']))->toBeTrue("La ruta {$path} no está en api/openapi.yaml");
        foreach (array_diff($r->methods(), ['HEAD']) as $m) {
            expect(array_key_exists(strtolower($m), $doc['paths'][$path]))->toBeTrue(strtoupper($m)." {$path} no está en api/openapi.yaml");
        }
    }
    // Lo que el YAML promete existe: recursos, alcances y eventos de webhook.
    $resources = collect($doc['paths']['/ext/v1/{resource}']['get']['parameters'])->firstWhere('name', 'resource')['schema']['enum'];
    foreach ($resources as $res) {
        expect($this->getJson("/api/v1/ext/v1/{$res}")->status())->toBe(401, "El recurso {$res} del contrato no existe");
    }
    expect(array_keys($doc['components']['securitySchemes']['oauth2']['flows']['clientCredentials']['scopes']))->toEqualCanonicalizing(\App\Modules\REC\Rules\ApiRules::SCOPES);
    expect(array_keys($doc['x-webhooks']['events']))->toEqualCanonicalizing([...\App\Modules\API\Webhooks::codes(), \App\Modules\API\Webhooks::TEST_EVENT]);
});
