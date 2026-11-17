<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-AUL RN-AUL-17: versiones del curso (version_number, previous_course_id); las matrículas en curso terminan en su versión.
 * SCR-AUL-21: «Matricularme» desde una recomendación solo si el curso permite automatrícula (allow_self_enrollment).
 * RN-AUL-04: tiempo mínimo de permanencia de un texto o documento (aul_min_active_seconds) y % mínimo de video (aul_min_video_pct)
 * cuando la lección no fija el suyo; aviso del compromiso pendiente (aul_commitment_reminder_days). Valores a confirmar.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private const PARAMS = [
        ['aul_min_active_seconds', '30', 'integer', 'segundos', 'Permanencia mínima en un texto o documento para darlo por visto', 'M-AUL RN-AUL-04'],
        ['aul_min_video_pct', '90', 'integer', '%', 'Porcentaje mínimo del video reproducido para darlo por visto (si la lección no fija otro)', 'M-AUL RN-AUL-04'],
        ['aul_commitment_reminder_days', '2', 'integer', 'días', 'Días tras la matrícula para recordar el compromiso de culminación pendiente', 'M-AUL §10'],
    ];

    public function up(): void
    {
        DB::statement('alter table courses add column if not exists version_number integer not null default 1');
        DB::statement('alter table courses add column if not exists previous_course_id uuid references courses(id)');
        DB::statement('alter table courses add column if not exists allow_self_enrollment boolean not null default false');
        foreach (self::PARAMS as [$key, $value, $type, $unit, $desc, $ref]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, 'M-AUL', date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $key]);
        }
    }

    public function down(): void
    {
        foreach (['version_number', 'previous_course_id', 'allow_self_enrollment'] as $c) {
            DB::statement("alter table courses drop column if exists {$c}");
        }
        DB::connection('platform')->delete('delete from legal_parameters where company_id is null and key = any(?::text[])', ['{'.implode(',', array_column(self::PARAMS, 0)).'}']);
    }
};
