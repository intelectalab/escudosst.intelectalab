<?php

namespace App\Modules\TRB;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * El trabajador vinculado a la membresía activa (memberships.worker_id). Todo lo de /me/worker/* parte de aquí y
 * filtra por ese id: nunca se recibe el id del trabajador desde la petición.
 */
final class WorkerSelf
{
    public static function id(): string
    {
        $ctx = app(CompanyContext::class);
        $id = DB::selectOne('select worker_id from memberships where id = ?', [$ctx->membershipId])?->worker_id;
        if (! $id) {
            throw ApiProblem::notFound('Tu usuario no está vinculado a una ficha de trabajador. Consulta con el área de SST o de Recursos Humanos.');
        }

        return $id;
    }

    public static function row(): object
    {
        $w = DB::selectOne(
            'select w.*, p.name as position, s.name as site, a.name as area
               from workers w left join positions p on p.id = w.current_position_id left join sites s on s.id = w.current_site_id
               left join areas a on a.id = w.current_area_id
              where w.id = ? and w.deleted_at is null', [self::id()]);

        return $w ?? throw ApiProblem::notFound('Tu ficha de trabajador no está disponible.');
    }

    /** Módulo usable por el trabajador: contratado (o en solo lectura) y con algún permiso del rol. */
    public static function usable(string $code): bool
    {
        $attrs = request()->attributes;
        if (! $attrs->has('worker_self_flags')) {
            $attrs->set('worker_self_flags', array_column(DB::select('select module_code, mode from feature_flags where deleted_at is null'), 'mode', 'module_code'));
        }
        $mode = $attrs->get('worker_self_flags')[$code] ?? 'locked';
        $prefix = strtolower($code).'.';

        return in_array($mode, ['enabled', 'read_only'], true)
            && (bool) array_filter(Permissions::effective(), fn ($p) => str_starts_with($p, $prefix));
    }

    public static function today(): string
    {
        return DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
    }
}
