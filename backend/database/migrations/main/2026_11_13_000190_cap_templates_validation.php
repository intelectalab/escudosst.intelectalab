<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-CAP RN-CAP-07/08, SCR-CAP-03/11: la capacitación indica su plantilla de certificado; si la plantilla exige validación,
 * el certificado nace «por validar» y el Jefe SST lo valida (uno por uno o en lote) antes de descargarse o verificarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table certificate_templates add column if not exists requires_validation boolean not null default false');
        DB::statement('alter table trainings add column if not exists certificate_template_id uuid references certificate_templates(id)');
    }

    public function down(): void
    {
        DB::statement('alter table trainings drop column if exists certificate_template_id');
        DB::statement('alter table certificate_templates drop column if exists requires_validation');
    }
};
