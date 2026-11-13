<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Revisión forzada del IPERC y del mapa de riesgos (docs/06 §2, M-IPC RN-IPC-08/09, M-ACC RN-ACC-08, M-MAP RN-MAP-03):
 *  - iperc_matrices: active ↔ review_required (accidente o incidente peligroso vinculado a una fila, cambio aprobado) y
 *    active ↔ review_overdue (pasó next_review_on); con el motivo y la fecha.
 *  - sst_events.iperc_row_id: fila del IPERC ligada al evento (M-ACC §5).
 *  - risk_maps: current ↔ review_required cuando se aprueba una nueva versión del IPERC de la sede.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table iperc_matrices drop constraint if exists iperc_matrices_status_check');
        DB::statement("alter table iperc_matrices add constraint iperc_matrices_status_check check (status in ('active', 'review_required', 'review_overdue', 'archived'))");
        DB::statement('alter table iperc_matrices add column if not exists review_reason text');
        DB::statement('alter table iperc_matrices add column if not exists review_flagged_at timestamptz');
        DB::statement('alter table sst_events add column if not exists iperc_row_id uuid references iperc_rows(id)');
        DB::statement('alter table risk_maps drop constraint if exists risk_maps_status_check');
        DB::statement("alter table risk_maps add constraint risk_maps_status_check check (status in ('draft', 'in_review', 'approved', 'current', 'review_required', 'obsolete'))");
        DB::statement('alter table risk_maps add column if not exists review_reason text');
    }

    public function down(): void
    {
        DB::update("update iperc_matrices set status = 'active' where status in ('review_required', 'review_overdue')");
        DB::statement('alter table iperc_matrices drop constraint if exists iperc_matrices_status_check');
        DB::statement("alter table iperc_matrices add constraint iperc_matrices_status_check check (status in ('active', 'archived'))");
        DB::update("update risk_maps set status = 'current' where status = 'review_required'");
        DB::statement('alter table risk_maps drop constraint if exists risk_maps_status_check');
        DB::statement("alter table risk_maps add constraint risk_maps_status_check check (status in ('draft', 'in_review', 'approved', 'current', 'obsolete'))");
    }
};
