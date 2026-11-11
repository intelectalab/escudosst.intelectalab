<?php

namespace App\Modules\FOT;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fotocheck (M-FOT, SCR-FOT-02/03):
 *  GET /fot/summary                                   indicadores (vigentes, por vencer, no habilitados, verificaciones del mes) y tipos activos
 *  GET /fot/evaluate?worker_id=&credential_type_id=   requisitos del tipo para un trabajador con semáforo (cumple / por vencer / no cumple)
 *
 * Usa los mismos criterios que la emisión (PeopleRules::credentialReasons) y agrega «por vencer» (30 días).
 * Nunca devuelve datos médicos: de la aptitud solo si está vigente y hasta cuándo.
 */
class CredentialCheckController extends Controller
{
    private const SOON_DAYS = 30;

    private const NAMES = ['emo_fitness' => 'Aptitud médica vigente', 'training' => 'Capacitación vigente', 'induction' => 'Inducción SST',
        'sctr' => 'SCTR salud y pensión', 'accreditation' => 'Contratista acreditado', 'document' => 'Documento', 'ppe_delivery' => 'Entrega de EPP'];

    private string $today;

    private string $soon;

    public function summary(): JsonResponse
    {
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $k = DB::selectOne(
            "select count(*) filter (where status = 'active' and (expires_on is null or expires_on >= ?::date)) as active,
                    count(*) filter (where status = 'active' and expires_on between ?::date and ?::date + 30) as expiring,
                    count(*) filter (where status in ('blocked','expired') or (status = 'active' and expires_on < ?::date)) as not_enabled
               from credentials where deleted_at is null", [$today, $today, $today, $today]);
        $ver = (int) DB::selectOne(
            "select count(*) as n from credential_verifications
              where date_trunc('month', verified_at at time zone 'America/Lima') = date_trunc('month', ?::date)", [$today])->n;
        $types = DB::select('select id, name, applies_to, validity_months from credential_types where deleted_at is null and coalesce(is_active, true) order by name');

        return response()->json(['data' => [
            'kpis' => ['active' => (int) $k->active, 'expiring' => (int) $k->expiring, 'not_enabled' => (int) $k->not_enabled, 'verifications_month' => $ver],
            'types' => $types,
        ]]);
    }

    public function evaluate(Request $request): JsonResponse
    {
        $workerId = (string) $request->query('worker_id', '');
        $w = Str::isUuid($workerId) ? DB::selectOne(
            'select w.id, w.full_name, w.document_last4, w.status, p.id as position_id, p.name as position, c.trade_name, c.legal_name
               from workers w left join positions p on p.id = w.current_position_id left join companies c on c.id = w.company_id
              where w.id = ? and w.deleted_at is null', [$workerId]) : null;
        if (! $w) {
            throw ApiProblem::notFound('El trabajador no existe en esta empresa.');
        }
        $typeId = (string) $request->query('credential_type_id', '');
        $type = Str::isUuid($typeId)
            ? DB::selectOne('select id, name, validity_months from credential_types where id = ? and deleted_at is null', [$typeId])
            : DB::selectOne("select id, name, validity_months from credential_types where deleted_at is null and coalesce(is_active, true) and applies_to in ('workers','both') order by name limit 1");
        if (! $type) {
            throw ApiProblem::notFound('No hay tipos de fotocheck configurados.');
        }
        $this->today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $this->soon = date('Y-m-d', strtotime($this->today.' +'.self::SOON_DAYS.' days'));

        $items = [];
        $reqs = DB::select(
            'select r.*, t.title as training_title from credential_requirements r left join trainings t on t.id = r.training_id
              where r.credential_type_id = ? and r.deleted_at is null and (r.position_id is null or r.position_id is not distinct from ?)
              order by r.is_blocking desc, r.requirement_type', [$type->id, $w->position_id]);
        foreach ($reqs as $r) {
            [$status, $detail] = match ($r->requirement_type) {
                'emo_fitness' => $this->fitness($w->id),
                'training', 'induction' => $this->training($w->id, $r),
                'sctr' => $this->sctr($w->id),
                'accreditation' => ['ok', 'No aplica a trabajadores propios'],
                default => ['ok', 'Se revisa al emitir'],
            };
            $items[] = [
                'type' => $r->requirement_type,
                'description' => $r->description ?: (self::NAMES[$r->requirement_type] ?? $r->requirement_type),
                'blocking' => (bool) $r->is_blocking,
                'status' => $status,
                'detail' => $detail,
            ];
        }
        $overall = collect($items)->contains(fn ($i) => $i['status'] === 'bad' && $i['blocking']) ? 'bad'
            : (collect($items)->contains(fn ($i) => $i['status'] !== 'ok') ? 'warn' : 'ok');
        $cred = DB::selectOne(
            "select id, credential_number, status, valid_from, expires_on from credentials where worker_id = ? and credential_type_id = ? and deleted_at is null
              order by (status = 'revoked'), issued_at desc nulls last limit 1", [$w->id, $type->id]);

        return response()->json(['data' => [
            'worker' => ['id' => $w->id, 'name' => $w->full_name, 'position' => $w->position, 'document_last4' => $w->document_last4, 'status' => $w->status],
            'company' => $w->trade_name ?: $w->legal_name,
            'type' => ['id' => $type->id, 'name' => $type->name, 'validity_months' => $type->validity_months === null ? null : (int) $type->validity_months],
            'requirements' => $items,
            'overall' => $overall,
            'credential' => $cred,
        ]]);
    }

    /** Vigente sin fecha o más allá de 30 días; por vencer dentro de 30 días; vencido. */
    private function byDate(?string $until): string
    {
        return $until === null || $until > $this->soon ? 'ok' : ($until >= $this->today ? 'warn' : 'bad');
    }

    private function fmt(?string $d): string
    {
        return $d ? date('d/m/Y', strtotime($d)) : '';
    }

    /** @return array{0: string, 1: string} */
    private function fitness(string $worker): array
    {
        $f = DB::selectOne("select valid_until from fitness_status where worker_id = ? and is_current and status in ('fit','fit_with_restrictions') and deleted_at is null
                             order by valid_until desc nulls first limit 1", [$worker]);
        if (! $f) {
            return ['bad', 'Examen médico pendiente'];
        }
        $s = $this->byDate($f->valid_until);

        return [$s, $s === 'bad' ? 'Aptitud vencida el '.$this->fmt($f->valid_until) : ($f->valid_until ? 'Apto, vigente hasta el '.$this->fmt($f->valid_until) : 'Apto')];
    }

    /** @return array{0: string, 1: string} */
    private function training(string $worker, object $r): array
    {
        if (! $r->training_id) {
            return ['bad', 'El requisito no tiene capacitación asignada'];
        }
        $c = DB::selectOne("select certificate_code, expires_on from training_certificates where worker_id = ? and training_id = ? and status = 'validated' and deleted_at is null
                             order by expires_on desc nulls first limit 1", [$worker, $r->training_id]);
        if (! $c) {
            return ['bad', 'Sin certificado validado de «'.$r->training_title.'»'];
        }
        $s = $this->byDate($c->expires_on);

        return [$s, $s === 'bad' ? 'Certificado vencido el '.$this->fmt($c->expires_on)
            : 'Certificado '.$c->certificate_code.($c->expires_on ? ', vence el '.$this->fmt($c->expires_on) : '')];
    }

    /** @return array{0: string, 1: string} */
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
            return ['bad', $kinds ? 'Falta la cobertura de '.(in_array('health', $kinds, true) ? 'pensión' : 'salud') : 'Sin SCTR salud ni pensión vigente'];
        }
        $until = array_filter(array_column($c, 'until'));
        $min = $until ? min($until) : null;

        return [$this->byDate($min), 'Salud y pensión vigentes'.($min ? ' hasta el '.$this->fmt($min) : '')];
    }
}
