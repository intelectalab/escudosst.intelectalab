<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-POL §5 y RN-POL-06: el objetivo se liga a un indicador (indicator_kpi_id) y el valor actual de sus metas se toma del
 * último valor guardado del indicador (no se edita a mano).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table sst_objectives add column if not exists indicator_kpi_id uuid references kpi_definitions(id)');
    }

    public function down(): void
    {
        DB::statement('alter table sst_objectives drop column if exists indicator_kpi_id');
    }
};
