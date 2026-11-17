<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-POL: sst_policies.principles_checklist (docs/modulos/M-POL §5) con los principios del art. 23 de la Ley 29783 marcados
 * (RN-POL-01). La lista de principios es un parámetro con vigencia, a confirmar por el especialista (pol_policy_principles).
 * RN-POL-07: umbral ámbar de las metas objective_amber_pct (antes fijo en 70).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private const PRINCIPLES = [
        'Proteger la seguridad y salud de todos los miembros de la organización mediante la prevención de lesiones, dolencias, enfermedades e incidentes relacionados con el trabajo',
        'Cumplir la normativa legal vigente en seguridad y salud en el trabajo, los programas voluntarios y otros requisitos que la organización suscriba',
        'Garantizar que los trabajadores y sus representantes sean consultados y participen activamente en todos los elementos del sistema de gestión',
        'La mejora continua del desempeño del sistema de gestión de la seguridad y salud en el trabajo',
        'Integrar el sistema de gestión de la seguridad y salud en el trabajo con otros sistemas de gestión de la organización, o que sea compatible con ellos',
    ];

    public function up(): void
    {
        DB::statement("alter table sst_policies add column if not exists principles_checklist jsonb not null default '[]'::jsonb");
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'pol_policy_principles', ?, 'json', null, 'Principios que la política SST debe incluir (lista; la política no se envía a revisión sin todos marcados)',
                    'Ley 29783, art. 23 · M-POL RN-POL-01', 'M-POL', date '2011-08-20', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'pol_policy_principles')",
            [json_encode(self::PRINCIPLES, JSON_UNESCAPED_UNICODE)]);
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'objective_amber_pct', '70', 'integer', '%', 'Porcentaje de avance de una meta desde el que se muestra en ámbar (por debajo, en rojo)',
                    'M-POL RN-POL-07', 'M-POL', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'objective_amber_pct')");
    }

    public function down(): void
    {
        DB::statement('alter table sst_policies drop column if exists principles_checklist');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('pol_policy_principles', 'objective_amber_pct')");
    }
};
