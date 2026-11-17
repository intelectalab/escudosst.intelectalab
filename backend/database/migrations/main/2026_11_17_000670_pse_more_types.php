<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PSE: el paquete también trae criterios IPERC del sector (risk_criteria, se instalan como alternativos) y valores legales
 * del sector (legal_parameter, se instalan como parámetros de la empresa «por confirmar»).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        $ck = DB::selectOne("select conname from pg_constraint where conrelid = 'sector_pack_items'::regclass and contype = 'c' and pg_get_constraintdef(oid) like '%credential_requirement%'");
        if ($ck) {
            DB::statement("alter table sector_pack_items drop constraint {$ck->conname}");
        }
        DB::statement("alter table sector_pack_items add constraint ck_sector_pack_items_type check (item_type in ('hazard', 'inspection_template', 'permit_type', 'training_topic', 'document_template',
                       'legal_requirement', 'ppe_item', 'kpi', 'credential_requirement', 'risk_criteria', 'legal_parameter'))");
    }

    public function down(): void
    {
    }
};
