<?php

namespace App\Modules\TRB;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /trb/workers/requirements?ids=a,b,c — semáforo de requisitos SST de uno o varios trabajadores (máx. 50):
 * inducción, aptitud EMO vigente (solo el estado, nunca datos clínicos: RN-PTR-02), SCTR salud y pensión vigentes,
 * capacitaciones obligatorias de su puesto y EPP obligatorio de su puesto entregado y no vencido.
 * «Habilitado» = ningún requisito en rojo. Lo usan la ficha 360° (SCR-TRB-02), el PETAR (RN-PTR-01) y el registro de eventos.
 * Mismos criterios que el fotocheck (FOT CredentialCheckController): «por vencer» dentro de 30 días.
 */
class WorkerRequirements extends Controller
{
    private const SOON_DAYS = 30;

    private string $today;

    private string $soon;

    public function __invoke(Request $request): JsonResponse
    {
        $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $request->query('ids', ''))), fn ($i) => Str::isUuid($i))));
        if ($ids === []) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Indica al menos un trabajador.', ['errors' => ['ids' => ['Indica al menos un trabajador.']]]);
        }
        if (count($ids) > 50) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Revisa hasta 50 trabajadores a la vez.', ['errors' => ['ids' => ['Revisa hasta 50 trabajadores a la vez.']]]);
        }
        $this->today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $this->soon = date('Y-m-d', strtotime($this->today.' +'.self::SOON_DAYS.' days'));

        $in = implode(',', array_fill(0, count($ids), '?'));
        $workers = DB::select(
            "select w.id, w.full_name, w.document_last4, w.status, w.current_position_id, p.name as position, s.name as site, a.name as area
               from workers w left join positions p on p.id = w.current_position_id left join sites s on s.id = w.current_site_id
               left join areas a on a.id = w.current_area_id
              where w.id in ({$in}) and w.deleted_at is null", $ids);

        $out = [];
        foreach ($workers as $w) {
            $checks = [
                $this->induction($w->id),
                $this->fitness($w->id),
                $this->sctr($w->id),
                $this->trainings($w->id, $w->current_position_id),
                $this->ppe($w->id, $w->current_position_id),
            ];
            $bad = $w->status === 'terminated' || collect($checks)->contains(fn ($c) => $c['status'] === 'bad');
            $out[] = [
                'worker_id' => $w->id,
                'name' => $w->full_name,
                'document_last4' => $w->document_last4,
                'position' => $w->position,
                'site' => $w->site,
                'area' => $w->area,
                'worker_status' => $w->status,
                'enabled' => ! $bad,
                'checks' => $checks,
            ];
        }
        if ($out === []) {
            throw ApiProblem::notFound('Los trabajadores no existen en esta empresa.');
        }

        return response()->json(['data' => $out]);
    }

    private function byDate(?string $until): string
    {
        return $until === null || $until > $this->soon ? 'ok' : ($until >= $this->today ? 'warn' : 'bad');
    }

    private function fmt(?string $d): string
    {
        return $d ? date('d/m/Y', strtotime($d)) : '';
    }

    private function check(string $code, string $label, string $status, string $detail): array
    {
        return compact('code', 'label', 'status', 'detail');
    }

    private function induction(string $worker): array
    {
        $c = DB::selectOne(
            "select c.expires_on from training_certificates c join trainings t on t.id = c.training_id
              where c.worker_id = ? and t.training_type = 'induction' and c.status = 'validated' and c.deleted_at is null
              order by c.expires_on desc nulls first limit 1", [$worker]);
        if ($c) {
            $s = $this->byDate($c->expires_on);

            return $this->check('induction', 'Inducción', $s, $s === 'bad' ? 'Vencida el '.$this->fmt($c->expires_on) : 'Inducción con constancia');
        }
        $a = DB::selectOne(
            "select 1 as ok from training_attendances a join training_sessions s on s.id = a.session_id join trainings t on t.id = s.training_id
              where a.worker_id = ? and a.attended and a.deleted_at is null and s.deleted_at is null and t.training_type = 'induction'
                and s.status <> 'cancelled' limit 1", [$worker]);

        return $a ? $this->check('induction', 'Inducción', 'ok', 'Asistió a la inducción') : $this->check('induction', 'Inducción', 'bad', 'Sin inducción registrada');
    }

    private function fitness(string $worker): array
    {
        $f = DB::selectOne(
            'select status, valid_until from fitness_status where worker_id = ? and is_current and deleted_at is null order by valid_until desc nulls first limit 1', [$worker]);
        if (! $f || ! in_array($f->status, ['fit', 'fit_with_restrictions'], true)) {
            return $this->check('emo', 'EMO apto vigente', 'bad', ! $f || $f->status === 'pending' ? 'Examen médico pendiente' : ($f->status === 'expired' ? 'Aptitud vencida' : 'No apto'));
        }
        $s = $this->byDate($f->valid_until);
        $label = $f->status === 'fit_with_restrictions' ? 'Apto con restricciones' : 'Apto';

        return $this->check('emo', 'EMO apto vigente', $s, $s === 'bad' ? 'Aptitud vencida el '.$this->fmt($f->valid_until) : $label.($f->valid_until ? ' hasta el '.$this->fmt($f->valid_until) : ''));
    }

    private function sctr(string $worker): array
    {
        $t = $this->today;
        $c = DB::select(
            "select p.coverage_kind, least(c.covered_until, p.ends_on) as until from sctr_coverages c join sctr_policies p on p.id = c.sctr_policy_id
              where c.worker_id = ? and c.status = 'covered' and c.deleted_at is null and p.deleted_at is null and p.status in ('active','expiring')
                and (c.covered_from is null or c.covered_from <= ?) and (c.covered_until is null or c.covered_until >= ?) and (p.ends_on is null or p.ends_on >= ?)
                and p.coverage_kind in ('health','pension')", [$worker, $t, $t, $t]);
        $kinds = array_values(array_unique(array_column($c, 'coverage_kind')));
        if (count($kinds) < 2) {
            return $this->check('sctr', 'SCTR vigente', 'bad', $kinds ? 'Falta la cobertura de '.(in_array('health', $kinds, true) ? 'pensión' : 'salud') : 'Sin SCTR salud ni pensión vigente');
        }
        $until = array_filter(array_column($c, 'until'));
        $min = $until ? min($until) : null;

        return $this->check('sctr', 'SCTR vigente', $this->byDate($min), 'Salud y pensión'.($min ? ' hasta el '.$this->fmt($min) : ''));
    }

    private function trainings(string $worker, ?string $position): array
    {
        $reqs = $position ? DB::select(
            "select r.training_id, r.course_id, coalesce(t.title, c.title) as title from position_training_requirements r
               left join trainings t on t.id = r.training_id left join courses c on c.id = r.course_id
              where r.position_id = ? and r.requirement_level = 'mandatory' and r.is_active and r.deleted_at is null", [$position]) : [];
        if ($reqs === []) {
            return $this->check('training', 'Capacitación', 'na', 'Su puesto no tiene capacitaciones obligatorias');
        }
        $missing = [];
        $min = null;
        foreach ($reqs as $r) {
            $c = DB::selectOne(
                "select expires_on from training_certificates where worker_id = ? and status = 'validated' and deleted_at is null
                    and ((?::uuid is not null and training_id = ?::uuid) or (?::uuid is not null and course_id = ?::uuid))
                    and (expires_on is null or expires_on >= ?) order by expires_on desc nulls first limit 1",
                [$worker, $r->training_id, $r->training_id, $r->course_id, $r->course_id, $this->today]);
            if (! $c) {
                $missing[] = $r->title ?? 'Capacitación';
            } elseif ($c->expires_on && ($min === null || $c->expires_on < $min)) {
                $min = $c->expires_on;
            }
        }
        if ($missing) {
            return $this->check('training', 'Capacitación', 'bad', 'Falta: '.implode(', ', array_slice($missing, 0, 3)).(count($missing) > 3 ? '…' : ''));
        }

        return $this->check('training', 'Capacitación', $this->byDate($min), count($reqs).' de '.count($reqs).' vigentes');
    }

    private function ppe(string $worker, ?string $position): array
    {
        $reqs = $position ? DB::select(
            'select r.ppe_item_id, i.name from ppe_position_requirements r join ppe_items i on i.id = r.ppe_item_id
              where r.position_id = ? and r.is_mandatory and r.deleted_at is null and i.deleted_at is null', [$position]) : [];
        if ($reqs === []) {
            return $this->check('ppe', 'EPP', 'na', 'Su puesto no tiene EPP obligatorio');
        }
        $missing = [];
        $min = null;
        foreach ($reqs as $r) {
            $d = DB::selectOne(
                "select expires_on from ppe_deliveries where worker_id = ? and ppe_item_id = ? and status = 'delivered' and deleted_at is null
                    and (expires_on is null or expires_on >= ?) order by expires_on desc nulls first limit 1", [$worker, $r->ppe_item_id, $this->today]);
            if (! $d) {
                $missing[] = $r->name;
            } elseif ($d->expires_on && ($min === null || $d->expires_on < $min)) {
                $min = $d->expires_on;
            }
        }
        if ($missing) {
            return $this->check('ppe', 'EPP', 'bad', 'Sin entregar: '.implode(', ', array_slice($missing, 0, 3)).(count($missing) > 3 ? '…' : ''));
        }

        return $this->check('ppe', 'EPP', $this->byDate($min), 'Entregado y vigente');
    }
}
