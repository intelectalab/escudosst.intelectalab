<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EMO de retiro al cese (M-EMO RN-EMO-08, M-TRB §7): parámetro `emo_on_termination` con vigencia y «a confirmar» por
 * especialista SST/legal. Activo por defecto: se genera la orden (el examen de retiro es facultativo para el trabajador
 * según Ley 30222; su realización depende de la normativa vigente). Cada empresa puede fijar su propio valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'emo_on_termination', 'true', 'boolean', null, 'Generar la orden de EMO de retiro al registrarse el cese del trabajador',
                    'Ley 29783, art. 49 d) mod. Ley 30222; M-EMO RN-EMO-08', 'M-EMO', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'emo_on_termination')"
        );
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'emo_on_termination'");
    }
};
