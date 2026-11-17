<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PLA RN-PLA-01: el año del plan deja de ser único; lo único es (año, versión), porque una modificación del plan es una nueva
 * versión que también se aprueba. Que haya un solo plan aprobado por año lo controla la regla del registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('drop index if exists uq_annual_plans_1');
        DB::statement('create unique index if not exists uq_annual_plans_year_version on annual_plans (company_id, year, version_number) where deleted_at is null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists uq_annual_plans_year_version');
        DB::statement('create unique index if not exists uq_annual_plans_1 on annual_plans (company_id, year) where deleted_at is null');
    }
};
