<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Parámetros de los avisos §10 de M-WEB (valores a confirmar por el dueño de la plataforma).
 */
return new class extends Migration
{
    private const PARAMS = [
        ['web.legal_notice_days', '30', 'días', 'Días antes de la vigencia de una versión nueva de un documento legal que pide re-aceptación en que se avisa por correo a los usuarios', 'M-WEB §10', 'M-WEB'],
        ['web.lead_retention_notice_days', '30', 'días', 'Días antes de cumplirse web.lead_retention_months en que el panel avisa los interesados próximos a anonimizar', 'M-WEB §10', 'M-WEB'],
        ['web.campaign_end_notice_days', '3', 'días', 'Días antes de la fecha de fin de una campaña activa en que el panel avisa que está por terminar', 'M-WEB §10', 'M-WEB'],
    ];

    public function up(): void
    {
        foreach (self::PARAMS as [$key, $value, $unit, $desc, $ref, $source]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, 'integer', ?, ?, ?, ?, date '2012-04-25', true
                  where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $unit, $desc, $ref, $source, $key]
            );
        }
    }

    public function down(): void
    {
        $keys = array_column(self::PARAMS, 0);
        DB::connection('platform')->delete('delete from legal_parameters where company_id is null and key in ('.implode(', ', array_fill(0, count($keys), '?')).')', $keys);
    }
};
