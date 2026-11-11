<?php

// Todos los GET propios de los módulos de empresa (fuera del motor de registros): con un usuario autorizado
// (Jefe SST de la empresa Corporativo, con los complementos activos) ninguno responde 500.
// Las rutas con parámetros usan el primer registro de la empresa; si no hay uno, la ruta se omite con el motivo.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

/**
 * GET de api/v1 con empresa activa (middleware «company»), leídos del router de la aplicación.
 * Se arma al recolectar las pruebas (un caso por ruta), con una instancia propia de la aplicación.
 */
function qaEndpointRoutes(): array
{
    static $routes;
    if ($routes !== null) {
        return $routes;
    }
    $app = require __DIR__.'/../../../bootstrap/app.php';
    // Sin el manejador de errores de Laravel (lo instala la propia prueba después): solo configuración y proveedores.
    $app->bootstrapWith([\Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class, \Illuminate\Foundation\Bootstrap\LoadConfiguration::class, \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
        \Illuminate\Foundation\Bootstrap\RegisterProviders::class, \Illuminate\Foundation\Bootstrap\BootProviders::class]);
    $routes = [];
    foreach ($app['router']->getRoutes() as $route) {
        $uri = $route->uri();
        if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/v1/') || str_starts_with($uri, 'api/v1/records/')) {
            continue;
        }
        if (! in_array('company', $route->gatherMiddleware(), true)) {
            continue;
        }
        $routes[substr($uri, 7)] = [substr($uri, 7)];
    }
    ksort($routes);
    $app->flush();
    \Illuminate\Support\Facades\Facade::clearResolvedInstances();

    return $routes;
}

/**
 * De dónde sale cada parámetro: [prefijo de la ruta, parámetro] => tabla del motor de registros
 * o [url de la API, ruta JSON del valor].
 */
function qaEndpointResolvers(): array
{
    return [
        ['act/report-points/', 'id', 'report_points'],
        ['aul/admin/attempts/', 'id', 'quiz_attempts'],
        ['aul/admin/banks/', 'id', 'question_banks'],
        ['aul/admin/courses/', 'id', 'courses'],
        ['aul/admin/courses/', 'lesson', 'course_lessons'],
        ['aul/enrollments/', 'id', 'course_enrollments'],
        ['cap/certificates/', 'id', 'training_certificates'],
        ['com/elections/', 'id', 'elections'],
        ['com/meetings/', 'id', 'committee_meetings'],
        ['des/absences/', 'id', 'absences'],
        ['doc/documents/', 'id', 'documents'],
        ['emo/orders/', 'order', 'medical_exam_orders'],
        ['emo/workers/', 'worker', 'workers'],
        ['fot/credentials/', 'id', 'credentials'],
        ['iaa/requests/', 'id', 'ai_requests'],
        ['ins/inspections/', 'inspection', 'inspections'],
        ['inv/investigations/', 'id', 'investigations'],
        ['ipc/matrices/', 'matrix', 'iperc_matrices'],
        ['ipc/versions/', 'version', 'iperc_versions'],
        ['map/maps/', 'id', 'risk_maps'],
        ['psi/surveys/', 'id', 'psychosocial_surveys'],
        ['sun/dossiers/', 'dossier', 'inspection_dossiers'],
        ['trb/imports/', 'id', ['/api/v1/trb/imports', 'data.0.id']],
        ['trb/workers/', 'id', 'workers'],
        ['vmd/cases/', 'id', ['/api/v1/vmd/cases', 'data.0.id']],
        ['reg/', 'type', ['/api/v1/reg/types', 'data.0.type']],
        ['fir/subject/', 'table', ['=', 'iperc_versions']],
        ['fir/subject/', 'id', 'iperc_versions'],
        ['fir/verify/', 'table', ['=', 'iperc_versions']],
        ['fir/verify/', 'id', 'iperc_versions'],
    ];
}

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->qaEpFlags = $db->select("select id, mode, source from feature_flags where company_id = ? and mode <> 'enabled' and deleted_at is null", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and mode <> 'enabled' and deleted_at is null", [HUAYLAS_ID]);
    Permissions::flush();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    foreach ($this->qaEpFlags as $f) {
        $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
    }
    Permissions::flush();
});

/** Valor de un parámetro de la ruta, o null si no hay de dónde sacarlo. */
function qaEndpointParam(string $uri, string $param, array $h): ?string
{
    foreach (qaEndpointResolvers() as [$prefix, $name, $source]) {
        if ($name !== $param || ! str_starts_with($uri, $prefix)) {
            continue;
        }
        if (is_array($source)) {
            if ($source[0] === '=') {
                return $source[1];
            }
            $r = test()->getJson($source[0], $h);

            return $r->status() === 200 ? $r->json($source[1]) : null;
        }
        $module = json_decode((string) file_get_contents(__DIR__.'/../../../resources/registry/registry.json'), true)['resources'][$source]['module'];
        $r = test()->getJson("/api/v1/records/{$module}/{$source}?per_page=5", $h);

        return $r->status() === 200 ? $r->json('data.0.id') : null;
    }

    return null;
}

it('responde sin error 500 a un usuario autorizado', function (string $uri) {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);

    preg_match_all('/\{(\w+)\??\}/', $uri, $m);
    $url = $uri;
    foreach ($m[1] as $param) {
        $value = qaEndpointParam($uri, $param, $h);
        if ($value === null) {
            $this->markTestSkipped("{{$param}}: no hay de dónde resolverlo (sin registros en la empresa de prueba o identificador de archivo)");
        }
        $url = preg_replace('/\{'.$param.'\??\}/', $value, $url);
    }

    $r = $this->get('/api/v1/'.$url, $h + ['Accept' => 'application/json']);
    expect($r->status())->toBeLessThan(500, "GET {$url} → {$r->status()}: ".mb_substr((string) $r->getContent(), 0, 600))
        ->and($r->status())->not->toBe(401);
})->with(qaEndpointRoutes());
