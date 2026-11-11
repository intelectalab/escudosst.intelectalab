<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * App del trabajador (docs/07 §5.2, docs/10 §5): credencial del dispositivo para ingresar con DNI + PIN.
 * Al activar la app se guarda en el dispositivo un secreto aleatorio; aquí solo queda su huella SHA-256. El PIN se
 * guarda con hash en workers.pin_hash (ya existía). Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE devices ADD COLUMN IF NOT EXISTS credential_hash bytea');
        DB::statement('ALTER TABLE devices ADD COLUMN IF NOT EXISTS last_login_at timestamptz');
        DB::statement("COMMENT ON COLUMN devices.credential_hash IS 'SHA-256 del secreto del dispositivo (app del trabajador: ingreso con DNI + PIN)'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE devices DROP COLUMN IF EXISTS last_login_at');
        DB::statement('ALTER TABLE devices DROP COLUMN IF EXISTS credential_hash');
    }
};
