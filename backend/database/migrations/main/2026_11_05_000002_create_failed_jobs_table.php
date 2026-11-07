<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trabajos de la cola que fallaron (Laravel, config/queue.php «failed»).
 * Tabla técnica sin company_id ni datos de negocio: el payload solo referencia ids.
 * La consulta el panel del dueño en «Salud del sistema» (docs/03 §11).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });

        DB::statement('GRANT SELECT, INSERT, DELETE ON failed_jobs TO escudo_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE failed_jobs_id_seq TO escudo_app');
        DB::statement('GRANT SELECT ON failed_jobs TO escudo_platform');
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
