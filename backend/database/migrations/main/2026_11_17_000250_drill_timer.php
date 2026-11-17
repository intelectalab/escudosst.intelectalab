<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-SIM SCR-SIM-02 y RN-SIM-01: tiempo de evacuación = evacuation_complete_time − alarm_time, comparado con la meta de la sede.
 * La ficha no nombra la columna de la meta por sede: se guarda en sites.evacuation_target_seconds (vacía = sin meta definida).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table drills add column if not exists alarm_time timestamptz');
        DB::statement('alter table drills add column if not exists evacuation_complete_time timestamptz');
        DB::statement('alter table sites add column if not exists evacuation_target_seconds integer check (evacuation_target_seconds is null or evacuation_target_seconds > 0)');
    }

    public function down(): void
    {
        DB::statement('alter table drills drop column if exists alarm_time');
        DB::statement('alter table drills drop column if exists evacuation_complete_time');
        DB::statement('alter table sites drop column if exists evacuation_target_seconds');
    }
};
