<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de sesión de Laravel Sanctum (docs/08-api.md §2).
 *
 * Tabla técnica de autenticación, sin company_id: se consulta antes de saber
 * quién es el usuario, por eso no lleva RLS. Solo guarda el hash SHA-256 del token.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON personal_access_tokens TO escudo_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
