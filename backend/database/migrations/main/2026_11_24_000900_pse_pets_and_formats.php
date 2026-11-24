<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PSE §5: el paquete también trae «PETS modelo» (pets_model: se instala como PETS en borrador con su versión 1) y «formato de
 * registro» (record_format: se instala como documento en borrador de la gestión documental), editables por la empresa.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table sector_pack_items drop constraint if exists ck_sector_pack_items_type');
        DB::statement("alter table sector_pack_items add constraint ck_sector_pack_items_type check (item_type in ('hazard', 'inspection_template', 'permit_type', 'training_topic', 'document_template',
                       'legal_requirement', 'ppe_item', 'kpi', 'credential_requirement', 'risk_criteria', 'legal_parameter', 'pets_model', 'record_format'))");
    }

    public function down(): void
    {
    }
};
