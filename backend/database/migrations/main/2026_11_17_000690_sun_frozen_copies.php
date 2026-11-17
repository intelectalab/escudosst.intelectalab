<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-SUN RN-SUN-05: al armar el expediente cada ítem se congela como copia con huella (hash); los cambios posteriores en
 * el registro de origen no alteran el expediente generado.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table dossier_items add column if not exists snapshot jsonb, add column if not exists snapshot_hash text, add column if not exists frozen_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('alter table dossier_items drop column if exists snapshot, drop column if exists snapshot_hash, drop column if exists frozen_at');
    }
};
