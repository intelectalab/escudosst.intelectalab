<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-IPC RN-IPC-07: la versión del IPERC se aprueba con el acuerdo de una sesión del comité (committee_meeting_id) o, sin comité, con la
 * firma del supervisor; al aprobarse su PDF queda en la gestión documental (file_id de la versión y versión del documento).
 * M-MAP RN-MAP-02: el mapa no se aprueba con riesgos significativos del IPERC de la sede sin marcador, salvo justificación por fila
 * (unmarked_justifications: {fila del IPERC: motivo}).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table iperc_versions add column if not exists committee_meeting_id uuid references committee_meetings(id)');
        DB::statement('alter table iperc_matrices add column if not exists document_id uuid references documents(id)');
        DB::statement("alter table risk_maps add column if not exists unmarked_justifications jsonb not null default '{}'::jsonb");
    }

    public function down(): void
    {
        DB::statement('alter table iperc_versions drop column if exists committee_meeting_id');
        DB::statement('alter table iperc_matrices drop column if exists document_id');
        DB::statement('alter table risk_maps drop column if exists unmarked_justifications');
    }
};
