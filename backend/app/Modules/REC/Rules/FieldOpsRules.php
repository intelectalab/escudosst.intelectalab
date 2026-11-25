<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\DB;

/**
 * Reglas de observaciones de conducta, botiquines y brigadas según sus fichas:
 * - M-OBS RN-OBS-02: los conteos «seguro» y «en riesgo» se calculan desde los ítems observados (no se escriben a mano).
 * - M-OBS RN-OBS-04: una observación enviada o revisada no se edita; se envía con al menos un comportamiento observado.
 * - M-EQE RN-EQE-04: el estado del botiquín (completo, incompleto, con vencidos) se calcula desde sus ítems.
 * - M-SIM RN-SIM-03/04: solo trabajadores activos y con la capacitación de brigada vigente, un único líder vigente (queda como líder de la brigada)
 *   y la brigada se activa con el mínimo de miembros (parámetro «min_brigade_members», a confirmar).
 */
class FieldOpsRules extends RecordRules
{
    private const OBS_FLOW = ['draft' => ['submitted'], 'submitted' => ['reviewed', 'draft'], 'reviewed' => []];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'behavior_observations' => $this->observation($op, $values, $current, $extra),
            'behavior_observation_items' => $this->observationItem($values, $current),
            'first_aid_kit_items' => $this->kitItem($values, $current),
            'brigade_members' => $this->brigadeMember($values, $current),
            'brigades' => $this->brigade($values, $current),
            default => null,
        };
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] === 'behavior_observation_items') {
            $obs = DB::selectOne('select observation_id from behavior_observation_items where id = ?', [$id])->observation_id;
            DB::update(
                "update behavior_observations o set safe_count = x.safe, at_risk_count = x.risk
                   from (select count(*) filter (where result = 'safe') as safe, count(*) filter (where result = 'at_risk') as risk
                           from behavior_observation_items where observation_id = ? and deleted_at is null) x
                  where o.id = ?", [$obs, $obs]);
        }
        if ($res['table'] === 'first_aid_kit_items') {
            self::refreshKit(DB::selectOne('select kit_id from first_aid_kit_items where id = ?', [$id])->kit_id);
        }
        if ($res['table'] === 'brigade_members' && ($this->value($values, $current, 'role') === 'leader') && ($this->value($values, $current, 'status') ?? 'active') === 'active') {
            $m = DB::selectOne('select brigade_id, worker_id, status from brigade_members where id = ?', [$id]);
            if ($m->status === 'active') {
                DB::update('update brigades set leader_worker_id = ? where id = ?', [$m->worker_id, $m->brigade_id]);
            }
        }
    }

    /** Estado del botiquín según sus ítems: con vencidos > incompleto > completo (no toca un botiquín dado de baja). */
    public static function refreshKit(string $kit): void
    {
        DB::update(
            "update first_aid_kits k set status = case
                    when exists (select 1 from first_aid_kit_items i where i.kit_id = k.id and i.deleted_at is null and i.expires_on < current_date) then 'expired_items'
                    when exists (select 1 from first_aid_kit_items i where i.kit_id = k.id and i.deleted_at is null and coalesce(i.current_quantity, 0) < coalesce(i.required_quantity, 0)) then 'incomplete'
                    else 'complete' end
              where k.id = ? and k.status <> 'retired'", [$kit]);
    }

    private function observation(string $op, array &$values, ?object $current, array &$extra): void
    {
        unset($values['safe_count'], $values['at_risk_count']);   // RN-OBS-02: los calcula el sistema
        if ($op === 'create') {
            $values['status'] = 'draft';
            $values['observer_user_id'] ??= (string) auth()->id();

            return;
        }
        $to = $values['status'] ?? null;
        if ($current->status !== 'draft' && array_diff(array_keys($values), ['status']) !== []) {
            $this->invalidTransition('La observación ya fue enviada: no se edita.');
        }
        if (! $to || $to === $current->status) {
            return;
        }
        if (! in_array($to, self::OBS_FLOW[$current->status] ?? [], true)) {
            $this->fail('status', 'Ese cambio de estado no está permitido.');
        }
        if ($to === 'submitted' && ! DB::selectOne("select 1 as ok from behavior_observation_items where observation_id = ? and deleted_at is null and result in ('safe', 'at_risk')", [$current->id])) {
            $this->fail('status', 'Registra al menos un comportamiento observado (seguro o en riesgo) antes de enviar la observación.');
        }
        if ($to === 'reviewed' && (string) $current->observer_user_id === (string) auth()->id()) {
            $this->fail('status', 'La revisa otra persona, no quien hizo la observación.');
        }
    }

    private function observationItem(array &$values, ?object $current): void
    {
        $st = DB::selectOne('select status from behavior_observations where id = ?', [$this->value($values, $current, 'observation_id')])?->status;
        if ($st && $st !== 'draft' && array_diff(array_keys($values), ['corrective_action_id']) !== []) {
            $this->invalidTransition('La observación ya fue enviada: sus comportamientos no se cambian.');
        }
    }

    private function kitItem(array &$values, ?object $current): void
    {
        foreach (['required_quantity', 'current_quantity'] as $f) {
            if (array_key_exists($f, $values) && $values[$f] !== null && (float) $values[$f] < 0) {
                $this->fail($f, 'La cantidad no puede ser negativa.');
            }
        }
        if ($this->value($values, $current, 'kit_id')
            && DB::selectOne("select 1 as ok from first_aid_kits where id = ? and status = 'retired'", [$this->value($values, $current, 'kit_id')])) {
            $this->invalidTransition('El botiquín está dado de baja.');
        }
    }

    private function brigadeMember(array &$values, ?object $current): void
    {
        $worker = $this->value($values, $current, 'worker_id');
        $status = $this->value($values, $current, 'status') ?? 'active';
        if ($status === 'active' && $worker && DB::selectOne("select 1 as ok from workers where id = ? and status = 'terminated'", [$worker])) {
            $this->fail('worker_id', 'El trabajador está cesado: no integra la brigada.');
        }
        // RN-SIM-03: el brigadista tiene la capacitación de brigada vigente (certificado validado sin vencer, o asistió a la capacitación).
        // Con training_id se exige esa capacitación; sin él, la que corresponde al tipo de brigada (ver brigadeTrainingValid).
        $training = $this->value($values, $current, 'training_id');
        if ($status === 'active' && $worker && (array_key_exists('training_id', $values) || array_key_exists('worker_id', $values) || array_key_exists('status', $values))) {
            $type = DB::selectOne('select brigade_type from brigades where id = ?', [$this->value($values, $current, 'brigade_id')])?->brigade_type;
            if (! self::brigadeTrainingValid($worker, $type, $training)) {
                $this->fail('training_id', $training ? 'El trabajador no tiene vigente esta capacitación de brigada: registra su asistencia o renueva su certificado.'
                    : 'El trabajador no tiene vigente la capacitación de brigada de este tipo de brigada (Capacitaciones, tipo «Entrenamiento de simulacro» o la configurada para la brigada): registra su asistencia o elige la capacitación.');
            }
        }
        if ($status === 'active' && $this->value($values, $current, 'role') === 'leader'
            && DB::selectOne("select 1 as ok from brigade_members where brigade_id = ? and role = 'leader' and status = 'active' and deleted_at is null
                                and id <> coalesce(?::uuid, '00000000-0000-0000-0000-000000000000'::uuid)", [$this->value($values, $current, 'brigade_id'), $current?->id])) {
            $this->fail('role', 'La brigada ya tiene un líder vigente: pásalo a inactivo o cámbiale el rol primero.');
        }
        if (($values['status'] ?? null) === 'inactive' && ! $this->value($values, $current, 'ends_on')) {
            $values['ends_on'] = date('Y-m-d');
        }
    }

    /**
     * M-SIM RN-SIM-03: el brigadista tiene vigente la capacitación de su brigada. Regla:
     *  1. Si el integrante tiene training_id, esa capacitación.
     *  2. Si no, las capacitaciones que el parámetro «brigade_required_trainings» (JSON {tipo_de_brigada: [código o id de capacitación]},
     *     clave «*» para todos los tipos) indica para el tipo de brigada: basta una vigente.
     *  3. Sin parámetro para ese tipo, cualquier capacitación de tipo «Entrenamiento de simulacro» (drill_training) vigente.
     */
    public static function brigadeTrainingValid(string $worker, ?string $brigadeType, ?string $training): bool
    {
        if ($training) {
            return self::trainedFor($worker, $training);
        }
        $map = json_decode(\App\Core\Support\LegalParams::text('brigade_required_trainings', '{}'), true);
        $refs = is_array($map) ? array_values(array_filter((array) ($map[$brigadeType] ?? $map['*'] ?? []), 'is_string')) : [];
        $arr = '{'.implode(',', array_map(fn ($r) => '"'.str_replace(['"', '\\'], '', $r).'"', $refs)).'}';
        $ids = $refs
            ? DB::select('select id from trainings where deleted_at is null and (code = any(?::text[]) or id::text = any(?::text[]))', [$arr, $arr])
            : DB::select("select id from trainings where deleted_at is null and training_type = 'drill_training'");
        foreach ($ids as $t) {
            if (self::trainedFor($worker, $t->id)) {
                return true;
            }
        }

        return false;
    }

    /** Capacitación vigente del trabajador: certificado validado y no vencido, o asistencia registrada a una sesión de esa capacitación. */
    public static function trainedFor(string $worker, string $training): bool
    {
        return (bool) DB::selectOne(
            "select 1 as ok where exists (select 1 from training_certificates c where c.worker_id = ? and c.training_id = ? and c.deleted_at is null
                                              and c.status = 'validated' and (c.expires_on is null or c.expires_on >= current_date))
                or exists (select 1 from training_attendances a join training_sessions s on s.id = a.session_id
                            where a.worker_id = ? and s.training_id = ? and a.attended and a.deleted_at is null and s.deleted_at is null
                              and not exists (select 1 from training_certificates c where c.worker_id = a.worker_id and c.training_id = s.training_id
                                                 and c.deleted_at is null and c.status in ('validated', 'expired') and c.expires_on < current_date))",
            [$worker, $training, $worker, $training]);
    }

    private function brigade(array &$values, ?object $current): void
    {
        if (($values['status'] ?? null) !== 'active' || ! $current || $current->status === 'active') {
            return;
        }
        $min = (int) \App\Core\Support\LegalParams::number('min_brigade_members', 2);
        $n = (int) DB::selectOne("select count(*) as n from brigade_members where brigade_id = ? and status = 'active' and deleted_at is null", [$current->id])->n;
        if ($n < $min) {
            $this->fail('status', "La brigada necesita al menos {$min} miembros activos para activarse (tiene {$n}).");
        }
        if (! DB::selectOne("select 1 as ok from brigade_members where brigade_id = ? and role = 'leader' and status = 'active' and deleted_at is null", [$current->id])) {
            $this->fail('status', 'Designa al líder de la brigada antes de activarla.');
        }
    }
}
