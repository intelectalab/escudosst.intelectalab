<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-VTA: pago manual con medio, referencia y evidencia (RN-VTA-14), pedidos vencidos que liberan el aforo (RN-VTA-12),
 * reembolso total o parcial (RN-VTA-18) y cupones validados (RN-VTA-11).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private const PARAMS = [
        ['order_payment_ttl_minutes', '4320', 'integer', 'minutos', 'Tiempo que un pedido pendiente reserva el aforo antes de vencer', 'M-VTA RN-VTA-12'],
    ];

    public function up(): void
    {
        DB::statement('alter table orders add column if not exists manual_payment_method text, add column if not exists manual_payment_reference text,
                       add column if not exists manual_payment_evidence_file_id uuid references files(id), add column if not exists refunded_total numeric(12,2) not null default 0,
                       add column if not exists refund_reason text');
        DB::statement('alter table order_items add column if not exists refunded_quantity integer not null default 0');
        $ck = DB::selectOne("select conname from pg_constraint where conrelid = 'orders'::regclass and contype = 'c' and pg_get_constraintdef(oid) like '%''voided''%' and pg_get_constraintdef(oid) like '%status%'");
        if ($ck) {
            DB::statement("alter table orders drop constraint {$ck->conname}");
        }
        DB::statement("alter table orders add constraint ck_orders_status check (status in ('pending', 'paid', 'failed', 'voided', 'expired', 'partially_refunded', 'refunded'))");
        DB::statement("alter table orders drop constraint if exists ck_orders_manual_method");
        DB::statement("alter table orders add constraint ck_orders_manual_method check (manual_payment_method is null or manual_payment_method in ('yape', 'plin', 'transfer', 'deposit', 'cash', 'other'))");
        DB::statement('alter table orders drop constraint if exists ck_orders_refunded_total');
        DB::statement('alter table orders add constraint ck_orders_refunded_total check (refunded_total >= 0 and refunded_total <= total)');
        foreach (self::PARAMS as [$key, $value, $type, $unit, $desc, $ref]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, 'M-VTA', date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $key]);
        }
    }

    public function down(): void
    {
        DB::statement('alter table orders drop constraint if exists ck_orders_manual_method, drop constraint if exists ck_orders_refunded_total');
        DB::statement('alter table orders drop column if exists manual_payment_method, drop column if exists manual_payment_reference,
                       drop column if exists manual_payment_evidence_file_id, drop column if exists refunded_total, drop column if exists refund_reason');
        DB::statement('alter table order_items drop column if exists refunded_quantity');
        DB::connection('platform')->delete('delete from legal_parameters where company_id is null and key = any(?::text[])', ['{'.implode(',', array_column(self::PARAMS, 0)).'}']);
    }
};
