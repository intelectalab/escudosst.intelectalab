<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /ats/forms/{id}/training-check — M-ATS RN-ATS-03: para cada participante, las capacitaciones exigidas que no tiene vigentes:
 * las del PETS vinculado a la tarea (versión vigente) y los temas obligatorios de su puesto en el programa de capacitación aprobado.
 * Con ats_block_untrained activo, quien no las tiene no puede firmar (lo aplica la firma en la app del trabajador).
 */
class AtsTrainingController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        if (! in_array('ats.ats_forms.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve los ATS.');
        }
        if (! Str::isUuid($id) || ! DB::selectOne('select 1 as ok from ats_forms where id = ? and deleted_at is null', [$id])) {
            throw ApiProblem::notFound('El ATS no existe o es de otra empresa.');
        }
        $out = [];
        foreach (DB::select("select p.id, p.worker_id, p.role, w.full_name from ats_participants p join workers w on w.id = p.worker_id where p.ats_form_id = ? and p.deleted_at is null and p.worker_id is not null", [$id]) as $p) {
            $out[] = ['participant_id' => $p->id, 'worker_id' => $p->worker_id, 'name' => $p->full_name, 'role' => $p->role, 'missing' => self::missing($id, $p->worker_id)];
        }

        return response()->json(['data' => ['block' => LegalParams::flag('ats_block_untrained', false), 'participants' => $out]]);
    }

    /** @return list<string> títulos de las capacitaciones exigidas que el trabajador no tiene vigentes */
    public static function missing(string $atsId, string $workerId): array
    {
        $required = DB::select(
            "select distinct t.id, t.title from trainings t
              where t.deleted_at is null and (
                    t.id = any(coalesce((select v.required_training_ids from ats_forms a join procedures r on r.id = a.procedure_id
                                          join procedure_versions v on v.id = r.current_version_id where a.id = ?), '{}'::uuid[]))
                 or t.id in (select i.training_id from training_program_items i join training_programs g on g.id = i.training_program_id
                              where g.deleted_at is null and g.status in ('approved', 'in_execution') and i.deleted_at is null and i.status <> 'cancelled'
                                and i.is_mandatory_legal and i.training_id is not null
                                and (select current_position_id from workers where id = ?) = any(i.target_positions)))", [$atsId, $workerId]);
        $miss = [];
        foreach ($required as $t) {
            $ok = DB::selectOne("select 1 as ok from training_certificates where worker_id = ? and training_id = ? and status = 'validated' and deleted_at is null
                                   and (expires_on is null or expires_on >= current_date)", [$workerId, $t->id]);
            if (! $ok) {
                $miss[] = $t->title;
            }
        }

        return $miss;
    }
}
