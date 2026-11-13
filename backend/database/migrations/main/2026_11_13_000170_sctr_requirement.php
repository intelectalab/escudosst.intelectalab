<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-SCT RN-SCT-01: un trabajador requiere SCTR si su puesto o su sede está marcado como actividad de riesgo (requires_sctr).
 * Con eso la conciliación (RN-SCT-04) avisa a los trabajadores activos sin cobertura.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table positions add column if not exists requires_sctr boolean not null default false');
        DB::statement('alter table sites add column if not exists requires_sctr boolean not null default false');
    }

    public function down(): void
    {
        DB::statement('alter table positions drop column if exists requires_sctr');
        DB::statement('alter table sites drop column if exists requires_sctr');
    }
};
