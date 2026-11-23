<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-CAP:
 * - RN-CAP-06: la matriz por cargo se versiona con effective_from/effective_to; un cambio guarda la versión anterior (archivada,
 *   con su fin de vigencia) y el cumplimiento se puede consultar a una fecha.
 * - RN-CAP-17: una sesión fuera del horario de trabajo queda marcada (out_of_hours) y se registra con advertencia.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    // Fuera de una transacción: el relleno por empresa usa otra conexión y esperaría el bloqueo del alter table.
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table position_training_requirements add column if not exists effective_from date, add column if not exists effective_to date');
        DB::statement("alter table position_training_requirements alter column effective_from set default ((now() at time zone 'America/Lima')::date)");
        DB::statement('alter table position_training_requirements drop constraint if exists position_training_requirements_effective_check');
        DB::statement('alter table position_training_requirements add constraint position_training_requirements_effective_check check (effective_to is null or effective_from is null or effective_to >= effective_from - 1)');
        DB::statement('alter table training_sessions add column if not exists out_of_hours boolean not null default false, add column if not exists out_of_hours_reason text');

        // Al final, después de los cambios de estructura: el relleno por empresa usa la conexión de la aplicación (RLS por empresa).
        // Las filas que queden sin fecha rigen desde su creación (las consultas usan coalesce(effective_from, created_at)).
        foreach (DB::connection('platform')->select('select id from companies') as $c) {
            $app = DB::connection('pgsql');
            $app->transaction(function () use ($app, $c) {
                $app->select("select set_config('app.company_id', ?, true)", [$c->id]);
                $app->update("update position_training_requirements set effective_from = (created_at at time zone 'America/Lima')::date where effective_from is null");
            });
        }
    }

    public function down(): void
    {
    }
};
