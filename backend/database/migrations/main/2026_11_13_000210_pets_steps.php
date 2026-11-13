<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PET §5 y SCR-PET-02: la versión del PETS guarda la tabla paso–riesgo–control (steps), las capacitaciones requeridas
 * (required_training_ids) y las filas del IPERC vinculadas (iperc_row_ids) para RN-PET-03/04/05.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table procedure_versions add column if not exists steps jsonb not null default '[]'::jsonb");
        DB::statement('alter table procedure_versions add column if not exists required_training_ids uuid[]');
        DB::statement('alter table procedure_versions add column if not exists iperc_row_ids uuid[]');
    }

    public function down(): void
    {
        DB::statement('alter table procedure_versions drop column if exists steps');
        DB::statement('alter table procedure_versions drop column if exists required_training_ids');
        DB::statement('alter table procedure_versions drop column if exists iperc_row_ids');
    }
};
