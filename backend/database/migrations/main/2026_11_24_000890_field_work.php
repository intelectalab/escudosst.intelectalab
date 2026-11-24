<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trabajo en campo:
 *  - M-ATS SCR-ATS-02: foto del lugar del ATS.
 *  - M-ATS SCR-ATS-04 / flujo §7.4: cierre con hora de término, observaciones e incidentes ocurridos; si ocurrió un evento,
 *    se enlaza al registrado en Accidentes e incidentes (ACC).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table ats_forms
            add column if not exists site_photo_file_id uuid references files(id),
            add column if not exists closing_notes text,
            add column if not exists incident_occurred boolean not null default false,
            add column if not exists incident_event_id uuid references sst_events(id)');
    }

    public function down(): void
    {
    }
};
