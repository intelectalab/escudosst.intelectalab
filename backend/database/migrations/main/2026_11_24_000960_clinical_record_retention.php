<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/11 P-24: la historia clínica ocupacional se conserva 40 años (R.M. 312-2011-MINSA). El valor de referencia global
 * estaba en 20; solo se corrige si nadie lo cambió. Sigue «a confirmar».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::connection('platform')->update("update legal_parameters set value = '40', legal_reference = 'R.M. 312-2011-MINSA; normas de historia clínica', updated_at = now()
                     where company_id is null and key = 'medical_records_retention_years' and value = '20' and deleted_at is null");
    }

    public function down(): void
    {
    }
};
