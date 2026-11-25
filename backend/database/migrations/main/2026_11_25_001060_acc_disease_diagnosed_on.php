<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-ACC D.S. 005-2012-TR art. 110 y RN-ACC-01: el plazo de notificación de la enfermedad ocupacional se cuenta desde el
 * diagnóstico, no desde la ocurrencia. sst_events.diagnosed_on guarda solo la fecha del diagnóstico (el diagnóstico en sí
 * queda en la base médica, RN-ACC-10); es obligatoria en los eventos de enfermedad ocupacional.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table sst_events add column if not exists diagnosed_on date');
        DB::statement("comment on column sst_events.diagnosed_on is 'fecha del diagnóstico de la enfermedad ocupacional: inicio del plazo de notificación (el diagnóstico queda en la base médica)'");
    }

    public function down(): void
    {
        DB::statement('alter table sst_events drop column if exists diagnosed_on');
    }
};
