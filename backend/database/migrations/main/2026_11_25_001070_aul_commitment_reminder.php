<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-AUL §5 completion_commitments.target_date y reminder_opt_in, y §10 «Recordatorio por compromiso asumido»: la fecha en que el
 * participante se compromete a terminar queda en su compromiso (ya no reemplaza el vencimiento de la matrícula) y, si pidió
 * recordatorio, recibe el aviso según esa fecha.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table completion_commitments add column if not exists target_date date, add column if not exists reminder_opt_in boolean not null default false');
        DB::statement("comment on column completion_commitments.target_date is 'fecha en que el participante se compromete a terminar el curso (opcional)'");
        DB::statement("comment on column completion_commitments.reminder_opt_in is 'el participante pidió recordatorio según target_date (M-AUL §10)'");
    }

    public function down(): void
    {
        DB::statement('alter table completion_commitments drop column if exists target_date, drop column if exists reminder_opt_in');
    }
};
