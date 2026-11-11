<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige documentos que quedaron en «borrador» aunque ya tienen versión vigente: pasan a «vigente».
 * Desde ahora lo hace la regla al poner vigente una versión (DocsFieldRules).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        // RLS por empresa: se recorre cada empresa fijando app.company_id.
        foreach (DB::connection('platform')->select('select id from companies') as $c) {
            // Con la conexión de la aplicación (la política RLS de documents es para escudo_app).
            $app = DB::connection('pgsql');
            $app->transaction(function () use ($app, $c) {
                $app->select("select set_config('app.company_id', ?, true)", [$c->id]);
                $app->update("update documents set status = 'active' where status = 'draft' and current_version_id is not null and deleted_at is null");
            });
        }
    }

    public function down(): void {}
};
