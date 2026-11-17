<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PAS RN-PAS-01: desde ahora solo cuentan las ejecuciones validadas. Las registradas antes de este cambio ya contaban en el avance,
 * así que quedan validadas por quien las registró (para no bajar el avance de los programas en curso). Las nuevas siguen la regla.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::connection('platform')->select('select id from companies') as $c) {
            $app = DB::connection('pgsql');
            $app->transaction(function () use ($app, $c) {
                $app->select("select set_config('app.company_id', ?, true)", [$c->id]);
                $ids = array_column($app->select('select distinct activity_id from activity_executions where verified_at is null and deleted_at is null'), 'activity_id');
                $app->update('update activity_executions set verified_at = created_at, verified_by = coalesce(recorded_by, created_by) where verified_at is null and deleted_at is null');
                foreach ($ids as $a) {
                    (new \App\Modules\REC\Rules\ProgramRules)->recalcActivity($a);
                }
            });
        }
    }

    public function down(): void {}
};
