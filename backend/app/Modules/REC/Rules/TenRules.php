<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\DB;

/**
 * TEN · Sedes, áreas, puestos y turnos (docs/modulos/M-TEN.md §8).
 * - RN-TEN-02: la empresa siempre conserva al menos una sede activa.
 * - Una sola sede principal.
 * - RN-TEN-03: el árbol de áreas no admite ciclos; el área superior es de la misma sede; el nivel se calcula.
 * - Una sede temporal (obra/proyecto) necesita fecha de inicio.
 * - No se archiva una sede o un área con áreas o trabajadores activos.
 * - Turnos: las horas por día se calculan del horario si no se indican.
 */
class TenRules extends RecordRules
{
    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'sites' => $this->site($values, $current),
            'areas' => $this->area($values, $current, $extra),
            'work_shifts' => $this->shift($values, $current),
            default => null,
        };
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] === 'sites' && ($values['is_main'] ?? false) === true) {
            DB::update('update sites set is_main = false where id <> ? and is_main and deleted_at is null', [$id]);
        }
        if ($res['table'] === 'areas' && $current && array_key_exists('parent_area_id', $values)) {
            $this->refreshDepths($id);
        }
    }

    public function prepareDelete(array $res, object $current): void
    {
        if ($res['table'] === 'sites') {
            if ($current->status === 'active' && $this->otherActiveSites($current->id) === 0) {
                $this->conflict('Es la única sede activa de la empresa. Crea o activa otra sede antes de archivar esta.');
            }
            if (DB::selectOne("select 1 as ok from areas where site_id = ? and deleted_at is null and status = 'active' limit 1", [$current->id])) {
                $this->conflict('La sede tiene áreas activas. Muévelas o desactívalas primero.');
            }
            $this->noActiveWorkers('current_site_id', $current->id, 'La sede');
        }
        if ($res['table'] === 'areas') {
            if (DB::selectOne('select 1 as ok from areas where parent_area_id = ? and deleted_at is null limit 1', [$current->id])) {
                $this->conflict('El área tiene sub-áreas. Muévelas o archívalas primero.');
            }
            $this->noActiveWorkers('current_area_id', $current->id, 'El área');
        }
        if ($res['table'] === 'positions') {
            $this->noActiveWorkers('current_position_id', $current->id, 'El puesto');
        }
    }

    private function site(array &$values, ?object $current): void
    {
        $status = $this->value($values, $current, 'status') ?? 'active';
        if ($current && $current->status === 'active' && $status !== 'active' && $this->otherActiveSites($current->id) === 0) {
            $this->fail('status', 'Es la única sede activa de la empresa: no puede quedar inactiva.');
        }
        if ($this->value($values, $current, 'is_temporary') && ! $this->value($values, $current, 'starts_on')) {
            $this->fail('starts_on', 'Una sede temporal (obra o proyecto) necesita fecha de inicio.');
        }
        $starts = $this->value($values, $current, 'starts_on');
        $ends = $this->value($values, $current, 'ends_on');
        if ($starts && $ends && $ends < $starts) {
            $this->fail('ends_on', 'La fecha de fin no puede ser anterior a la de inicio.');
        }
        if ($this->value($values, $current, 'is_main') && $status !== 'active') {
            $this->fail('is_main', 'La sede principal debe estar activa.');
        }
    }

    private function area(array &$values, ?object $current, array &$extra): void
    {
        $parent = $this->value($values, $current, 'parent_area_id');
        $site = $this->value($values, $current, 'site_id');
        $depth = 0;
        if ($parent) {
            if ($current && $parent === $current->id) {
                $this->fail('parent_area_id', 'Un área no puede depender de sí misma.');
            }
            $p = DB::selectOne('select site_id, depth from areas where id = ? and deleted_at is null', [$parent]);
            if ($p->site_id !== $site) {
                $this->fail('parent_area_id', 'El área superior debe ser de la misma sede.');
            }
            if ($current) {
                // ¿El nuevo padre cuelga del área que se edita? Eso cerraría un ciclo.
                $cycle = DB::selectOne(
                    'with recursive up as (select id, parent_area_id from areas where id = ?
                       union all select a.id, a.parent_area_id from areas a join up on a.id = up.parent_area_id)
                     select 1 as ok from up where id = ? limit 1',
                    [$parent, $current->id]
                );
                if ($cycle) {
                    $this->fail('parent_area_id', 'Esa área ya depende de la que estás editando: se formaría un ciclo.');
                }
            }
            $depth = (int) $p->depth + 1;
        }
        if ($current && array_key_exists('site_id', $values) && $values['site_id'] !== $current->site_id
            && DB::selectOne('select 1 as ok from areas where parent_area_id = ? and deleted_at is null limit 1', [$current->id])) {
            $this->fail('site_id', 'El área tiene sub-áreas: no se puede mover a otra sede.');
        }
        $extra['depth'] = ['?', [$depth]];
    }

    private function shift(array &$values, ?object $current): void
    {
        $start = $this->value($values, $current, 'start_time');
        $end = $this->value($values, $current, 'end_time');
        if (! array_key_exists('hours_per_day', $values) || $values['hours_per_day'] === null) {
            if ($start && $end && ($current === null || array_key_exists('start_time', $values) || array_key_exists('end_time', $values))) {
                $mins = (strtotime("1970-01-01 {$end}") - strtotime("1970-01-01 {$start}")) / 60;
                if ($mins <= 0) {
                    $mins += 24 * 60; // turno que pasa la medianoche
                }
                $values['hours_per_day'] = round($mins / 60, 2);
            }
        }
        if (isset($values['hours_per_day']) && ($values['hours_per_day'] <= 0 || $values['hours_per_day'] > 24)) {
            $this->fail('hours_per_day', 'Las horas por día deben estar entre 0 y 24.');
        }
        if ($start && $end && ! array_key_exists('is_night', $values) && $current === null) {
            $values['is_night'] = $end < $start || $start >= '22:00' || $end <= '06:00';
        }
    }

    private function otherActiveSites(string $id): int
    {
        return (int) DB::selectOne("select count(*) as n from sites where id <> ? and status = 'active' and deleted_at is null", [$id])->n;
    }

    private function noActiveWorkers(string $column, string $id, string $what): void
    {
        $n = (int) DB::selectOne("select count(*) as n from workers where {$column} = ? and status in ('active', 'on_leave') and deleted_at is null", [$id])->n;
        if ($n > 0) {
            $this->conflict("{$what} tiene {$n} trabajador(es) activo(s). Reasígnalos primero.");
        }
    }

    private function refreshDepths(string $id): void
    {
        DB::statement(
            'with recursive tree as (
               select a.id, coalesce((select p.depth + 1 from areas p where p.id = a.parent_area_id), 0) as depth from areas a where a.id = ?
               union all select c.id, tree.depth + 1 from areas c join tree on c.parent_area_id = tree.id where c.deleted_at is null)
             update areas set depth = tree.depth from tree where areas.id = tree.id and areas.depth <> tree.depth',
            [$id]
        );
    }
}
