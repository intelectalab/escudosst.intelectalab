<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-AUI §5 audit_checklist_items.linked_records (jsonb) y RN-AUI-07: además del texto y el archivo de evidencia, cada ítem del
 * checklist vincula registros del sistema como evidencia: [{module, table, id, label}] (la etiqueta es la del registro al vincularlo).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("alter table audit_checklist_items add column if not exists linked_records jsonb not null default '[]'::jsonb");
        DB::statement('alter table audit_checklist_items drop constraint if exists ck_audit_checklist_items_linked_records');
        DB::statement("alter table audit_checklist_items add constraint ck_audit_checklist_items_linked_records check (jsonb_typeof(linked_records) = 'array')");
        DB::statement("comment on column audit_checklist_items.linked_records is 'registros del sistema vinculados como evidencia: [{module, table, id, label}] (RN-AUI-07)'");
    }

    public function down(): void
    {
        DB::statement('alter table audit_checklist_items drop constraint if exists ck_audit_checklist_items_linked_records');
        DB::statement('alter table audit_checklist_items drop column if exists linked_records');
    }
};
