<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grupos vulnerables (M-VUL):
 *  - §4: el Jefe SST define y actualiza las adecuaciones («vul.work_adjustments.create/update»).
 *  - §10 y RN-VUL-04: aviso de adecuación por vencer «adjustment_notice_days» días antes.
 *  - RN-VUL-03: edad y familias de peligro prohibidas para menores (propuesta según el Código de los Niños y Adolescentes y
 *    la relación de trabajos peligrosos para adolescentes; a confirmar por especialista SST/legal; la empresa puede fijar la suya).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private const PARAMS = [
        ['adjustment_notice_days', '15', 'days', 'días', 'Días de anticipación del aviso de adecuación de puesto por vencer', 'M-VUL §10'],
        ['minor_age_years', '18', 'years', 'años', 'Edad hasta la cual el trabajador se considera menor de edad', 'Código de los Niños y Adolescentes'],
        ['minor_forbidden_hazard_families', '["chemical","biological","electrical","fire_explosion"]', 'json', null,
            'Familias de peligro del IPERC prohibidas para menores de edad (un menor no se asigna a un puesto con ellas)',
            'Código de los Niños y Adolescentes; relación de trabajos peligrosos para adolescentes (a confirmar)'],
    ];

    public function up(): void
    {
        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO role_permissions (account_id, role_id, permission_id)
            SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('vul.work_adjustments.create', 'vul.work_adjustments.update')
             WHERE r.account_id IS NULL AND r.code = 'sst_manager'
            ON CONFLICT DO NOTHING
        SQL);
        foreach (self::PARAMS as [$key, $value, $type, $unit, $description, $reference]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, 'M-VUL', date '2011-08-20', true
                  where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $description, $reference, $key]
            );
        }
    }

    public function down(): void
    {
        DB::connection('platform')->statement(<<<'SQL'
            DELETE FROM role_permissions rp USING roles r, permissions p
             WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.code IN ('vul.work_adjustments.create', 'vul.work_adjustments.update')
               AND r.account_id IS NULL AND r.code = 'sst_manager'
        SQL);
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('adjustment_notice_days', 'minor_age_years', 'minor_forbidden_hazard_families')");
    }
};
