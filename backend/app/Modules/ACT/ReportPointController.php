<?php

namespace App\Modules\ACT;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\DbContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reporte de actos y condiciones subestándar por QR, sin app ni contraseña (M-ACT).
 *  GET  /act/report-points/{id}/link             enlace del punto para imprimir su QR (con sesión)
 *  GET  /public/report/{company}/{token}          datos del punto (empresa, sede, lugar) para el formulario público
 *  POST /public/report/{company}/{token}          registrar el reporte: {report_type, description, location_text?, is_anonymous, reporter_name?, reporter_phone?}
 *
 * El token del QR sale de la empresa y del punto (HMAC con la clave de la app); en la base solo queda su hash.
 * El reporte llega como «recibido» al área SST, con origen «qr» y el punto de reporte; la IP se guarda solo como hash.
 */
class ReportPointController extends Controller
{
    public static function token(string $companyId, string $pointId): string
    {
        return strtoupper(substr(hash_hmac('sha256', 'report|'.$companyId.'|'.$pointId, (string) config('app.key')), 0, 32));
    }

    public static function url(string $companyId, string $pointId): string
    {
        return rtrim((string) config('escudo.frontend_url'), '/').'/reportar/'.$companyId.'/'.self::token($companyId, $pointId);
    }

    public function link(string $id): JsonResponse
    {
        if (! in_array('act.report_points.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden();
        }
        $p = Str::isUuid($id) ? DB::selectOne('select id, name, is_active, revoked_at from report_points where id = ? and deleted_at is null', [$id]) : null;
        if (! $p) {
            throw ApiProblem::notFound('El punto de reporte no existe o es de otra empresa.');
        }

        return response()->json(['data' => ['url' => self::url(app(CompanyContext::class)->companyId, $p->id), 'active' => $p->is_active && ! $p->revoked_at]]);
    }

    public function show(string $company, string $token): JsonResponse
    {
        return $this->inPoint($company, $token, function (object $p) {
            $c = DB::selectOne('select coalesce(trade_name, legal_name) as name from companies where id = ?', [$p->company_id]);

            return response()->json(['data' => [
                'company' => $c?->name, 'point' => $p->name, 'site' => $p->site_name, 'location' => $p->location_description, 'allow_anonymous' => (bool) $p->allow_anonymous,
            ]]);
        });
    }

    public function store(Request $request, string $company, string $token): JsonResponse
    {
        $d = $request->validate([
            'report_type' => ['required', 'in:unsafe_act,unsafe_condition,near_miss,suggestion'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'location_text' => ['nullable', 'string', 'max:200'],
            'is_anonymous' => ['boolean'],
            'reporter_name' => ['nullable', 'string', 'max:120'],
            'reporter_phone' => ['nullable', 'string', 'max:30'],
            'website' => ['prohibited'],   // trampa para robots
        ], [
            'report_type.required' => 'Elige qué quieres reportar.',
            'description.required' => 'Cuéntanos qué pasó.',
            'description.min' => 'Cuéntanos un poco más (al menos 10 letras).',
        ]);

        return $this->inPoint($company, $token, function (object $p) use ($d, $request) {
            $anonymous = (bool) ($d['is_anonymous'] ?? false);
            if ($anonymous && ! $p->allow_anonymous) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'En este punto los reportes llevan nombre.', ['errors' => ['is_anonymous' => ['En este punto los reportes llevan nombre.']]]);
            }
            if (! $anonymous && trim((string) ($d['reporter_name'] ?? '')) === '') {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Escribe tu nombre o marca «Reportar sin mi nombre».', ['errors' => ['reporter_name' => ['Escribe tu nombre o marca «Reportar sin mi nombre».']]]);
            }
            $n = (int) DB::selectOne('select count(*) + 1 as n from substandard_reports where extract(year from created_at) = extract(year from now())')->n;
            do {
                $code = sprintf('REP-%s-%04d', date('Y'), $n++);
            } while (DB::selectOne('select 1 as ok from substandard_reports where code = ? and deleted_at is null', [$code]));
            $contact = $anonymous ? null : trim(($d['reporter_name'] ?? '').(! empty($d['reporter_phone']) ? ' · '.$d['reporter_phone'] : ''));
            DB::insert(
                "insert into substandard_reports (company_id, code, report_type, origin, report_point_id, site_id, area_id, description, location_text, reported_at,
                    is_anonymous, reporter_contact, ip_hash, status)
                 values (?, ?, ?, 'qr', ?, ?, ?, ?, ?, now(), ?, ?, decode(?, 'hex'), 'received')",
                [$p->company_id, $code, $d['report_type'], $p->id, $p->site_id, $p->area_id, $d['description'], $d['location_text'] ?? $p->location_description,
                    $anonymous ? 'true' : 'false', $contact, hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'))]
            );
            DB::update('update report_points set report_count = report_count + 1, last_used_at = now() where id = ?', [$p->id]);

            return response()->json(['data' => ['code' => $code]], 201);
        });
    }

    /** Valida el token del punto y ejecuta con el contexto RLS de esa empresa. */
    private function inPoint(string $company, string $token, callable $fn): JsonResponse
    {
        if (! Str::isUuid($company) || ! preg_match('/^[A-F0-9]{32}$/', strtoupper($token))) {
            throw ApiProblem::notFound('Este código QR no es válido.');
        }

        return DB::transaction(function () use ($company, $token, $fn) {
            DbContext::setCompany($company);
            $p = DB::selectOne("select p.*, s.name as site_name from report_points p left join sites s on s.id = p.site_id
                                 where p.token_hash = decode(?, 'hex') and p.deleted_at is null", [hash('sha256', strtoupper($token))]);
            if (! $p || ! hash_equals(self::token($company, $p->id), strtoupper($token))) {
                throw ApiProblem::notFound('Este código QR no es válido.');
            }
            if (! $p->is_active || $p->revoked_at) {
                throw new ApiProblem(410, 'gone', 'Punto desactivado', 'Este punto de reporte ya no está activo. Avisa al área de Seguridad y Salud en el Trabajo.');
            }

            return $fn($p);
        });
    }
}
