<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PAS RN-PAS-01: la cantidad ejecutada de una actividad admite decimales, porque una ejecución parcial cuenta con el peso
 * partial_execution_weight (por ejemplo 0,5).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table program_activities alter column executed_quantity type numeric(10,2)');
    }

    public function down(): void
    {
        DB::statement('alter table program_activities alter column executed_quantity type integer using round(executed_quantity)::integer');
    }
};
