<?php

namespace App\Modules\REC\Rules;

use App\Modules\VUL\MinorGuard;
use Illuminate\Support\Facades\DB;

/**
 * TRB · Ficha del trabajador y su historial de asignaciones (docs/modulos/M-TRB.md).
 * - El documento se guarda cifrado; para buscar y evitar duplicados se usa un HMAC y los 4 últimos dígitos.
 * - DNI de 8 dígitos; CE/PTP/pasaporte alfanuméricos.
 * - Un cese necesita fecha y no puede ser anterior al ingreso.
 * - La asignación principal vigente actualiza la sede, área y puesto actuales del trabajador
 *   y cierra la asignación anterior el día previo.
 */
class WorkerRules extends RecordRules
{
    public static function documentHash(string $type, string $number): string
    {
        return hash_hmac('sha256', 'worker-doc|'.$type.'|'.self::normalize($number), (string) config('app.key'));
    }

    private static function normalize(string $number): string
    {
        return strtoupper(preg_replace('/[\s.\-]/', '', $number));
    }

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        $res['table'] === 'workers' ? $this->worker($values, $current, $extra) : $this->assignment($values, $current);
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] === 'workers') {
            $this->syncAssignment($id, $values, $current);
            if ($current && ($values['current_position_id'] ?? null) && $values['current_position_id'] !== $current->current_position_id) {
                self::recheckAdjustments($id, $values['current_position_id']);
            }
            // Cese (M-TRB §7 paso 3, RN-EMO-08): orden de EMO de retiro, desde la ficha o desde la carga masiva «Cesar».
            if ($current && $current->status !== 'terminated' && ($values['status'] ?? null) === 'terminated') {
                // Cese desde la ficha o desde la carga «Cesar»: cierra la asignación vigente, suspende sus accesos
                // al sistema y revoca su fotocheck (M-TRB §7).
                $on = $values['termination_on'] ?? $current->termination_on ?? now('America/Lima')->toDateString();
                DB::update('update worker_assignments set ends_on = greatest(starts_on, ?::date) where worker_id = ? and ends_on is null and deleted_at is null', [$on, $id]);
                DB::update("update memberships set status = 'suspended' where worker_id = ? and status = 'active' and deleted_at is null", [$id]);
                DB::update("update credentials set status = 'revoked', revoked_at = now(), revoked_reason = ? where worker_id = ? and status in ('draft', 'issued', 'active', 'blocked') and deleted_at is null",
                    ['Cese del trabajador', $id]);
                // M-SIM RN-SIM-04: al cese sale de las brigadas (si era líder, la brigada queda sin líder hasta designar otro).
                DB::update("update brigade_members set status = 'inactive', ends_on = greatest(starts_on, ?::date) where worker_id = ? and status = 'active' and deleted_at is null", [$on, $id]);
                DB::update('update brigades set leader_worker_id = null where leader_worker_id = ?', [$id]);
                \App\Modules\EMO\ExitExam::onTermination($id);
            }

            return;
        }
        $a = DB::selectOne('select * from worker_assignments where id = ?', [$id]);
        if (! $a->is_primary || ($a->ends_on !== null && $a->ends_on < now('America/Lima')->toDateString())) {
            return;
        }
        // Cierra la asignación principal anterior que seguía abierta.
        DB::update(
            "update worker_assignments set ends_on = greatest(starts_on, ?::date - 1)
              where worker_id = ? and id <> ? and is_primary and ends_on is null and deleted_at is null and starts_on <= ?::date",
            [$a->starts_on, $a->worker_id, $id, $a->starts_on]
        );
        $before = DB::selectOne('select current_position_id from workers where id = ?', [$a->worker_id])?->current_position_id;
        DB::update('update workers set current_site_id = ?, current_area_id = ?, current_position_id = ? where id = ?',
            [$a->site_id, $a->area_id, $a->position_id, $a->worker_id]);
        if ($a->position_id && $a->position_id !== $before) {
            self::recheckAdjustments($a->worker_id, $a->position_id);
        }
    }

    /**
     * M-VUL RN-VUL-02: al cambiar de puesto se vuelven a cruzar sus adecuaciones vigentes con el IPERC del nuevo puesto;
     * si hay peligros de familias restringidas se avisa al Jefe SST para reubicar o justificar.
     */
    public static function recheckAdjustments(string $worker, string $position): void
    {
        $adj = DB::select("select id, restricted_hazard_families from work_adjustments where worker_id = ? and status in ('proposed', 'active')
                             and deleted_at is null and coalesce(array_length(restricted_hazard_families, 1), 0) > 0", [$worker]);
        if ($adj === []) {
            return;
        }
        $vul = app(\App\Modules\VUL\VulController::class);
        $name = DB::selectOne('select full_name from workers where id = ?', [$worker])?->full_name;
        $team = array_column(DB::select("select m.user_id from memberships m join roles r on r.id = m.role_id
                                          where m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = 'sst_manager'"), 'user_id');
        foreach ($adj as $a) {
            $families = array_filter(explode(',', trim((string) $a->restricted_hazard_families, '{}')));
            $conflicts = $vul->conflicts($position, array_map(fn ($f) => trim($f, '"'), $families));
            if (! $conflicts) {
                continue;
            }
            DB::update('update work_adjustments set compatibility_justification = null, updated_at = now() where id = ?', [$a->id]);
            foreach (array_unique($team) as $u) {
                DB::insert("insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                            values (?, 'vul.incompatible', 'VUL', ?, ?, 'Nuevo puesto incompatible con una adecuación', ?, ?, 'high', ?) on conflict do nothing",
                    [app(\App\Core\Tenancy\CompanyContext::class)->companyId, $a->id, $u,
                        mb_substr("El nuevo puesto de {$name} tiene ".count($conflicts).' peligro(s) que su adecuación restringe: reubícalo o justifica por qué se mantiene.', 0, 500),
                        '/m/vul?t=work_adjustments&open='.$a->id, 'vul.incompatible:'.$a->id.':'.$u.':'.date('Y-m-d')]);
            }
        }
    }

    private function worker(array &$values, ?object $current, array &$extra): void
    {
        $type = $this->value($values, $current, 'document_type') ?? 'DNI';
        if (array_key_exists('document_number', $values) || array_key_exists('document_type', $values)) {
            $number = array_key_exists('document_number', $values) ? $values['document_number'] : null;
            if ($number === null && $current) {
                $number = $this->currentDocument($current);
            }
            if ($number === null) {
                $this->fail('document_number', 'Completa el número de documento.');
            }
            $number = self::normalize((string) $number);
            if ($type === 'DNI' && ! preg_match('/^\d{8}$/', $number)) {
                $this->fail('document_number', 'El DNI tiene 8 dígitos.');
            }
            if ($type !== 'DNI' && ! preg_match('/^[A-Z0-9]{5,20}$/', $number)) {
                $this->fail('document_number', 'El documento admite de 5 a 20 letras o números.');
            }
            $hash = self::documentHash($type, $number);
            $dup = DB::selectOne("select full_name from workers where document_number_hash = decode(?, 'hex') and deleted_at is null".($current ? ' and id <> ?' : ''),
                $current ? [$hash, $current->id] : [$hash]);
            if ($dup) {
                $this->fail('document_number', "Ya hay un trabajador con ese documento: {$dup->full_name}.");
            }
            $values['document_number'] = $number;
            $extra['document_number_hash'] = ["decode(?, 'hex')", [$hash]];
            $extra['document_last4'] = ['?', [substr($number, -4)]];
        }

        $status = $this->value($values, $current, 'status');
        $end = $this->value($values, $current, 'termination_on');
        $hire = $this->value($values, $current, 'hire_on');
        if ($status === 'terminated' && ! $end) {
            $this->fail('termination_on', 'Para dar de baja al trabajador indica la fecha de cese.');
        }
        if ($end && $hire && $end < $hire) {
            $this->fail('termination_on', 'La fecha de cese no puede ser anterior a la de ingreso.');
        }
        if ($end && $status !== 'terminated' && array_key_exists('termination_on', $values) && ! array_key_exists('status', $values)) {
            $values['status'] = 'terminated';
        }
        // RN-VUL-03: un menor de edad no se asigna a un puesto con peligros prohibidos para menores.
        if (array_key_exists('current_position_id', $values) || array_key_exists('birth_on', $values)) {
            MinorGuard::assertAllowed($this->value($values, $current, 'birth_on'), $this->value($values, $current, 'current_position_id'), 'current_position_id');
        }
        if (array_key_exists('whatsapp_opt_in', $values) && $values['whatsapp_opt_in'] && ! ($current?->whatsapp_opt_in)) {
            $extra['whatsapp_opt_in_at'] = ['now()', []];
        }
    }

    private function assignment(array &$values, ?object $current): void
    {
        $site = $this->value($values, $current, 'site_id');
        $area = $this->value($values, $current, 'area_id');
        if ($area && $site && ! DB::selectOne('select 1 as ok from areas where id = ? and site_id = ?', [$area, $site])) {
            $this->fail('area_id', 'El área elegida no pertenece a esa sede.');
        }
        $starts = $this->value($values, $current, 'starts_on');
        $ends = $this->value($values, $current, 'ends_on');
        if ($starts && $ends && $ends < $starts) {
            $this->fail('ends_on', 'La fecha de fin no puede ser anterior a la de inicio.');
        }
        $worker = $this->value($values, $current, 'worker_id');
        $w = $worker ? DB::selectOne('select status, birth_on from workers where id = ?', [$worker]) : null;
        if ($w && $w->status === 'terminated' && $current === null) {
            $this->fail('worker_id', 'El trabajador está cesado: no se le puede asignar un puesto.');
        }
        if ($w && (array_key_exists('position_id', $values) || $current === null)) {
            MinorGuard::assertAllowed($w->birth_on, $this->value($values, $current, 'position_id'), 'position_id');
        }
    }

    /**
     * Si en la ficha se elige sede y puesto (al crear o al cambiarlos), queda también en el historial de asignaciones.
     */
    private function syncAssignment(string $id, array $values, ?object $current): void
    {
        $keys = ['current_site_id', 'current_area_id', 'current_position_id'];
        $changed = array_filter($keys, fn ($k) => array_key_exists($k, $values) && $values[$k] !== ($current?->{$k} ?? null));
        if ($changed === []) {
            return;
        }
        $w = DB::selectOne('select company_id, current_site_id, current_area_id, current_position_id, hire_on from workers where id = ?', [$id]);
        if (! $w->current_site_id || ! $w->current_position_id) {
            return;
        }
        $today = now('America/Lima')->toDateString();
        $starts = $current === null && $w->hire_on ? $w->hire_on : $today;
        DB::update("update worker_assignments set ends_on = greatest(starts_on, ?::date - 1)
                     where worker_id = ? and is_primary and ends_on is null and deleted_at is null", [$starts, $id]);
        DB::insert(
            'insert into worker_assignments (company_id, worker_id, site_id, area_id, position_id, starts_on, is_primary, change_reason, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?, true, ?, ?, ?)',
            [$w->company_id, $id, $w->current_site_id, $w->current_area_id, $w->current_position_id, $starts,
                $current === null ? 'Ingreso' : 'Cambio desde la ficha', (string) auth()->id(), (string) auth()->id()]
        );
    }

    private function currentDocument(object $current): ?string
    {
        try {
            return $current->document_number ? \Illuminate\Support\Facades\Crypt::decryptString($current->document_number) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
