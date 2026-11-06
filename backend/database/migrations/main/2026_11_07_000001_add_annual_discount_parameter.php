<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Descuento por pago anual (DA-01). El precio de los planes es mensual; el anual es 12 × mensual menos este %.
 * Empieza en 0 (sin descuento) porque aún no está decidido; el dueño lo cambia en Catálogos › Parámetros.
 * Mientras sea 0, el sitio no anuncia ningún descuento (M-WEB, corrección 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, source, valid_from, to_confirm)
             select null, 'sus.annual_discount_pct', '0', 'decimal', '%',
                    'Descuento por pago anual sobre 12 meses del precio mensual (parámetro de plataforma, no legal; 0 = sin descuento)',
                    'DA-01', current_date, true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'sus.annual_discount_pct')"
        );
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'sus.annual_discount_pct'");
    }
};
