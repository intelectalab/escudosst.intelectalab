<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-VTA §10 «Cupos sin asignar por vencer»: aviso al representante license_assign_notice_days antes de que venzan los cupos.
 * La ficha nombra el parámetro sin valor: nace «por confirmar» con 7 días.
 * M-NOT §9: el envío de un aviso cuyo registro se resolvió antes de enviarse queda «cancelado» (cancelled).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table notification_deliveries drop constraint if exists notification_deliveries_status_check');
        DB::statement("alter table notification_deliveries add constraint notification_deliveries_status_check
                       check (status in ('queued', 'sent', 'delivered', 'read', 'failed', 'bounced', 'suppressed', 'cancelled'))");
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'license_assign_notice_days', '7', 'days', 'días', 'Días antes del vencimiento de los cupos corporativos en que se avisa al representante que aún tiene cupos sin asignar', 'M-VTA §10', 'M-VTA', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'license_assign_notice_days')");
    }

    public function down(): void
    {
    }
};
