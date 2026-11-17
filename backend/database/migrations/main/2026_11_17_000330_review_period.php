<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** M-RVD §5: periodo revisado (period_start, period_end) de la revisión por la dirección. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table management_reviews add column if not exists period_start date');
        DB::statement('alter table management_reviews add column if not exists period_end date');
    }

    public function down(): void
    {
        DB::statement('alter table management_reviews drop column if exists period_start');
        DB::statement('alter table management_reviews drop column if exists period_end');
    }
};
