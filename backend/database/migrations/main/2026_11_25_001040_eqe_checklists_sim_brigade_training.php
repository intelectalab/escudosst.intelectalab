<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * - M-EQE SCR-EQE-02 y §5 (equipment_checks.photo_file_id): la inspección por QR guarda una foto y usa el checklist del tipo de
 *   equipo. La ficha solo da los ítems «presión, precinto, señalización, acceso libre»: quedan como checklist por defecto de todos
 *   los tipos («*») y cada empresa lo ajusta por tipo (parámetro «equipment_checklists», por confirmar).
 * - M-SIM RN-SIM-03: capacitación de brigada exigida por tipo de brigada cuando el integrante no tiene una elegida
 *   (parámetro «brigade_required_trainings»; vacío = cualquier capacitación de tipo «Entrenamiento de simulacro»).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const PARAMS = [
        ['equipment_checklists', '{"*":["Presión","Precinto","Señalización","Acceso libre"]}', 'json', null,
            'Ítems del checklist de la inspección por QR por tipo de equipo de emergencia («*» = todos los tipos sin lista propia)', 'M-EQE SCR-EQE-02', 'M-EQE'],
        ['brigade_required_trainings', '{}', 'json', null,
            'Capacitaciones (código o id) exigidas a los brigadistas por tipo de brigada («*» = todos los tipos); vacío = cualquier «Entrenamiento de simulacro» vigente', 'M-SIM RN-SIM-03', 'M-SIM'],
    ];

    public function up(): void
    {
        DB::statement('alter table equipment_checks add column if not exists photo_file_id uuid references files(id)');
        DB::statement("comment on column equipment_checks.photo_file_id is 'foto de la inspección (SCR-EQE-02)'");
        foreach (self::PARAMS as [$key, $value, $type, $unit, $desc, $ref, $src]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, ?, date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $src, $key]);
        }
    }

    public function down(): void
    {
        DB::statement('alter table equipment_checks drop column if exists photo_file_id');
    }
};
