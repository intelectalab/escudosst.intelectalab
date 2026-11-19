<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PCA §5 (target_positions, array) y SCR-PCA-02 «puestos objetivo»: un tema del programa puede ir dirigido a varios puestos.
 * Los temas que ya tenían un puesto lo pasan a la lista; position_id queda solo como dato anterior (ya no se edita).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table training_program_items add column if not exists target_positions uuid[] not null default '{}'");
        DB::statement("update training_program_items set target_positions = array[position_id] where position_id is not null and cardinality(target_positions) = 0");
        DB::statement('create index if not exists idx_training_program_items_target_positions on training_program_items using gin (target_positions)');
    }

    public function down(): void
    {
    }
};
