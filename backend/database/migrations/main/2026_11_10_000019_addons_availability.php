<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Complementos disponibles según lo que ya funciona (10/11/2026): Medio ambiente, Homologación, Asistente IA (con modo
 * de prueba hasta configurar la IA), Sistema integrado ISO, Gestión del cambio y Paquetes sectoriales. La app móvil
 * offline queda «Próximamente» (no se vende hasta tenerla).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update("update addons set available_in_v1 = true where code in ('AMB', 'HOM', 'IAA', 'ISO', 'MOC', 'PSE')");
        DB::update("update addons set available_in_v1 = false where code = 'OFF'");
    }

    public function down(): void {}
};
