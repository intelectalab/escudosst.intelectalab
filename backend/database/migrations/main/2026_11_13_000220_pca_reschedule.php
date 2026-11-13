<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** M-PCA RN-PCA-08: un tema no ejecutado de un mes cerrado se reprograma dentro del año con el motivo registrado. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table training_program_items add column if not exists reschedule_reason text');
        DB::statement('alter table training_program_items add column if not exists original_month smallint');
    }

    public function down(): void
    {
        DB::statement('alter table training_program_items drop column if exists reschedule_reason');
        DB::statement('alter table training_program_items drop column if exists original_month');
    }
};
