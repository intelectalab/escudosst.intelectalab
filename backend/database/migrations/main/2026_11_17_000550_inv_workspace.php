<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-INV §5 y SCR-INV-02: cronología, entrevistas y evidencias de la investigación (RN-INV-07: las entrevistas no registran datos de salud).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table investigations add column if not exists chronology jsonb not null default '[]'::jsonb,
                       add column if not exists interviews jsonb not null default '[]'::jsonb, add column if not exists evidence_file_ids jsonb not null default '[]'::jsonb");
    }

    public function down(): void
    {
        DB::statement('alter table investigations drop column if exists chronology, drop column if exists interviews, drop column if exists evidence_file_ids');
    }
};
