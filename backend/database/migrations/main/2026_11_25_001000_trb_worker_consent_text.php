<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-TRB SCR-TRB-03 «consentimiento de datos» (Ley 29733): texto de la cláusula que se muestra al registrar el consentimiento
 * del trabajador. El texto legal lo debe aprobar un abogado: se deja un texto neutro «por confirmar» (to_confirm) que la
 * plataforma o cada empresa reemplaza en Parámetros legales. La versión que se guarda en worker_consents es clave@vigencia.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'worker_data_consent_text', 'Texto de consentimiento pendiente de aprobación', 'text', null,
                    'Texto de la cláusula de consentimiento de datos del trabajador (pendiente de aprobación legal)', 'Ley 29733 · M-TRB SCR-TRB-03', 'M-TRB', current_date, true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'worker_data_consent_text')");
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'worker_data_consent_text'");
    }
};
