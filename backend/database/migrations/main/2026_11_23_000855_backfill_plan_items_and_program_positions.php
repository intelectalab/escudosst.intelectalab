<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Relleno de las migraciones 000840 (M-PLA: tipo y referencia de los componentes del plan) y 000850 (M-PCA: puestos objetivo del
 * tema): con RLS por empresa, el relleno se hace empresa por empresa con la conexión de la aplicación.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    // Fuera de una transacción: el relleno por empresa usa otra conexión y esperaría el bloqueo del alter table.
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (DB::connection('platform')->select('select id from companies') as $c) {
            $app = DB::connection('pgsql');
            $app->transaction(function () use ($app, $c) {
                $app->select("select set_config('app.company_id', ?, true)", [$c->id]);
                $app->update("update annual_plan_items set item_type = 'objective', reference_type = 'sst_objectives', reference_id = objective_id
                               where objective_id is not null and item_type is null");
                $app->update('update training_program_items set target_positions = array[position_id] where position_id is not null and cardinality(target_positions) = 0');
            });
        }
    }

    public function down(): void {}
};
