<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de personas y salud según sus fichas:
 *  - M-DES RN-DES-06: la prórroga de un descanso se registra como ausencia encadenada a la anterior.
 *  - M-CAP RN-CAP-11: la reemisión de un certificado apunta al que reemplaza.
 *  - M-CAP RN-CAP-01: porcentaje mínimo de horas para que cuente una asistencia; RN-CAP-02: intentos de evaluación.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table absences add column if not exists previous_absence_id uuid references absences(id)');
        DB::statement('alter table training_certificates add column if not exists replaces_certificate_id uuid references training_certificates(id)');
        foreach ([
            ['min_attendance_pct', '80', 'integer', '%', 'Porcentaje mínimo de las horas de la sesión que debe cumplir una asistencia presencial para contar', 'M-CAP RN-CAP-01'],
            ['training_max_attempts', '3', 'integer', null, 'Intentos permitidos para la evaluación final de una capacitación', 'M-CAP RN-CAP-02'],
        ] as [$key, $value, $type, $unit, $desc, $ref]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, 'M-CAP', date '2012-04-25', true
                  where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $key]
            );
        }
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('min_attendance_pct', 'training_max_attempts')");
    }
};
