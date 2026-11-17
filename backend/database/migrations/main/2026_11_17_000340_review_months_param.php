<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** M-RVD RN-RVD-04: cada cuántos meses corresponde la revisión por la dirección (management_review_months, a confirmar). */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'management_review_months', '12', 'integer', 'meses', 'Cada cuántos meses corresponde la revisión del sistema de gestión por la dirección',
                    'D.S. 005-2012-TR, art. 88 · M-RVD RN-RVD-04', 'M-RVD', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'management_review_months')");
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'management_review_months'");
    }
};
