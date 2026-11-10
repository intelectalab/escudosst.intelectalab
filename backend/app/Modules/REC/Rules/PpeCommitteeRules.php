<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\DB;

/**
 * EPP (docs/modulos/M-EPP.md §8) y COM · Comité SST (M-COM.md §8).
 * EPP:
 * - RN-EPP-01: vence (reponer) = fecha de entrega + vida útil del ítem.
 * - RN-EPP-03: la entrega descuenta stock de la sede; no se permite stock negativo.
 * - RN-EPP-07: una entrega no se edita; la devolución se registra como movimiento nuevo.
 * - RN-EPP-05: el EPP no tiene costo para el trabajador (no se registran descuentos).
 * COM:
 * - RN-COM-01: para activar el comité, titulares en número par y mitad por cada lado.
 * - RN-COM-08: sin quórum la sesión no adopta acuerdos.
 * - RN-COM-09: el acta firmada no se modifica; las sesiones se numeran correlativamente.
 */
class PpeCommitteeRules extends RecordRules
{
    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'ppe_deliveries' => $this->delivery($op, $values, $current, $extra),
            'ppe_stock_movements' => $this->movement($op, $values, $current),
            'committees' => $this->committee($values, $current),
            'committee_meetings' => $this->meeting($op, $values, $current),
            'meeting_agreements' => $this->agreement($op, $values, $current),
            default => null,
        };
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] !== 'ppe_deliveries') {
            return;
        }
        $d = DB::selectOne('select * from ppe_deliveries where id = ?', [$id]);
        if ($op === 'create') {
            DB::insert(
                "insert into ppe_stock_movements (company_id, ppe_item_id, site_id, movement_type, quantity, size, moved_at, reference, ppe_delivery_id, created_by, updated_by)
                 values (?, ?, ?, 'out', ?, ?, coalesce(?, now()), 'Entrega al trabajador', ?, ?, ?)",
                [$d->company_id, $d->ppe_item_id, $d->site_id, $d->quantity, $d->size, $d->delivered_at, $id, (string) auth()->id(), (string) auth()->id()]
            );
        } elseif (($values['status'] ?? null) === 'returned' && $current->status !== 'returned') {
            DB::insert(
                "insert into ppe_stock_movements (company_id, ppe_item_id, site_id, movement_type, quantity, size, moved_at, reference, ppe_delivery_id, notes, created_by, updated_by)
                 values (?, ?, ?, 'return', ?, ?, now(), 'Devolución del trabajador', ?, ?, ?, ?)",
                [$d->company_id, $d->ppe_item_id, $d->site_id, $d->quantity, $d->size, $id, $d->return_condition, (string) auth()->id(), (string) auth()->id()]
            );
        }
    }

    /** Stock de un ítem en una sede: entradas, devoluciones y ajustes menos salidas, bajas y traslados. */
    public static function stock(string $item, ?string $site): float
    {
        return (float) DB::selectOne(
            "select coalesce(sum(case when movement_type in ('in','return','adjustment') then quantity else -quantity end), 0) as s
               from ppe_stock_movements where ppe_item_id = ? and deleted_at is null and site_id is not distinct from ?",
            [$item, $site]
        )->s;
    }

    private function delivery(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'update') {
            $allowed = ['status', 'returned_at', 'return_condition'];
            if (array_diff(array_keys($values), $allowed) !== []) {
                $this->conflict('Una entrega de EPP no se edita. Si hubo un error, regístrala como devuelta y haz una nueva entrega.');
            }
            if (($values['status'] ?? null) === 'returned') {
                $values['returned_at'] ??= now()->toIso8601String();
            }

            return;
        }
        $item = DB::selectOne('select name, useful_life_days, is_active from ppe_items where id = ?', [$values['ppe_item_id']]);
        if (! $item->is_active) {
            $this->fail('ppe_item_id', 'El EPP elegido está inactivo.');
        }
        $worker = DB::selectOne('select status, current_site_id from workers where id = ?', [$values['worker_id']]);
        if ($worker && $worker->status === 'terminated') {
            $this->fail('worker_id', 'El trabajador está cesado.');
        }
        $values['site_id'] ??= $worker?->current_site_id;
        $values['delivered_at'] ??= now()->toIso8601String();
        $values['quantity'] ??= 1;
        if (! isset($values['expires_on']) && $item->useful_life_days) {
            $values['expires_on'] = date('Y-m-d', strtotime(substr($values['delivered_at'], 0, 10).' +'.(int) $item->useful_life_days.' days'));
        }
        $values['status'] = 'delivered';
        $extra['delivered_by'] = ['?', [(string) auth()->id()]];
        $stock = self::stock($values['ppe_item_id'], $values['site_id']);
        if ($stock < (float) $values['quantity']) {
            $this->fail('quantity', "No hay stock suficiente de «{$item->name}» en la sede (quedan {$stock}). Registra primero el ingreso en «Movimientos de stock».");
        }
    }

    private function movement(string $op, array &$values, ?object $current): void
    {
        if ($op === 'update') {
            $this->conflict('Los movimientos de stock no se editan: registra un ajuste.');
        }
        if (($values['quantity'] ?? 0) <= 0) {
            $this->fail('quantity', 'La cantidad debe ser mayor que cero.');
        }
        $values['moved_at'] ??= now()->toIso8601String();
        if (in_array($values['movement_type'] ?? '', ['out', 'disposal', 'transfer'], true)
            && self::stock($values['ppe_item_id'], $values['site_id'] ?? null) < (float) $values['quantity']) {
            $this->fail('quantity', 'No hay stock suficiente para esa salida.');
        }
        unset($values['unit_cost_charged']);
    }

    private function committee(array &$values, ?object $current): void
    {
        if (($values['status'] ?? null) !== 'active' || ! $current || $current->status === 'active') {
            return;
        }
        $rows = DB::select("select representation, count(*) as n from committee_members where committee_id = ? and member_type = 'titular' and status = 'active' and deleted_at is null group by representation", [$current->id]);
        $by = array_column(array_map(fn ($r) => (array) $r, $rows), 'n', 'representation');
        $type = $this->value($values, $current, 'committee_type');
        if ($type === 'supervisor') {
            if (($by['supervisor'] ?? 0) + ($by['workers'] ?? 0) < 1) {
                $this->fail('status', 'Registra al Supervisor SST elegido antes de activarlo.');
            }

            return;
        }
        $emp = (int) ($by['employer'] ?? 0);
        $wrk = (int) ($by['workers'] ?? 0);
        if ($emp + $wrk === 0 || ($emp + $wrk) % 2 !== 0 || $emp !== $wrk) {
            $this->fail('status', "Para activar el comité, los titulares deben ser pares y mitad por cada lado (hoy: {$emp} del empleador y {$wrk} de los trabajadores).");
        }
        $values['installation_on'] ??= date('Y-m-d');
    }

    private function meeting(string $op, array &$values, ?object $current): void
    {
        if ($op === 'create' && ! isset($values['number'])) {
            $values['number'] = (int) DB::selectOne('select coalesce(max(number), 0) + 1 as n from committee_meetings where committee_id = ?', [$values['committee_id']])->n;
        }
        if ($current && $current->status === 'minutes_signed' && $values !== []) {
            $this->conflict('El acta está firmada: la sesión no se modifica.');
        }
        $to = $values['status'] ?? null;
        if ($current && $to && $to !== $current->status) {
            $flow = ['scheduled' => ['held', 'cancelled'], 'held' => ['minutes_draft', 'minutes_signed'], 'minutes_draft' => ['minutes_signed', 'held'], 'cancelled' => []];
            if (! in_array($to, $flow[$current->status] ?? [], true)) {
                $this->fail('status', $current->status === 'scheduled' ? 'Primero marca la sesión como realizada.' : 'Ese cambio de estado no está permitido.');
            }
            if ($to === 'minutes_signed' && ! $this->value($values, $current, 'minutes_html') && ! $this->value($values, $current, 'minutes_file_id')) {
                $this->fail('minutes_html', 'Escribe el acta o adjunta el acta firmada antes de marcarla como firmada.');
            }
        }
        if (in_array($values['status'] ?? null, ['held', 'minutes_draft', 'minutes_signed'], true)) {
            $values['held_at'] = $this->value($values, $current, 'held_at') ?? now()->toIso8601String();
        }
    }

    private function agreement(string $op, array &$values, ?object $current): void
    {
        $m = DB::selectOne('select status, quorum_reached from committee_meetings where id = ?', [$this->value($values, $current, 'meeting_id')]);
        if ($op === 'create') {
            if (! in_array($m?->status, ['held', 'minutes_draft'], true)) {
                $this->conflict('Los acuerdos se registran en una sesión realizada cuya acta aún no está firmada.');
            }
            if (! $m->quorum_reached) {
                $this->fail('meeting_id', 'La sesión no tuvo quórum: no se adoptan acuerdos (RN-COM-08).');
            }
            $values['number'] ??= (int) DB::selectOne('select coalesce(max(number), 0) + 1 as n from meeting_agreements where meeting_id = ?', [$values['meeting_id']])->n;
        }
        if (($values['status'] ?? null) === 'fulfilled') {
            $values['fulfilled_at'] = $this->value($values, $current, 'fulfilled_at') ?? now()->toIso8601String();
        }
    }
}
