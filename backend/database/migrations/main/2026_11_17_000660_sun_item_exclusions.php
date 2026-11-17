<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-SUN §5 y SCR-SUN-04: un ítem del expediente se excluye con motivo (y se mantiene al volver a armarlo); el orden se puede
 * cambiar a mano (manual_sort). RN-SUN-11: los excluidos se muestran en el índice con su motivo.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        $ck = DB::selectOne("select conname from pg_constraint where conrelid = 'dossier_items'::regclass and contype = 'c' and pg_get_constraintdef(oid) like '%not_applicable%'");
        if ($ck) {
            DB::statement("alter table dossier_items drop constraint {$ck->conname}");
        }
        DB::statement("alter table dossier_items add constraint ck_dossier_items_status check (status in ('included', 'missing', 'expired', 'not_applicable', 'excluded'))");
        DB::statement('alter table dossier_items add column if not exists exclusion_reason text, add column if not exists manual_sort boolean not null default false');
    }

    public function down(): void
    {
        DB::statement('alter table dossier_items drop column if exists exclusion_reason, drop column if exists manual_sort');
    }
};
