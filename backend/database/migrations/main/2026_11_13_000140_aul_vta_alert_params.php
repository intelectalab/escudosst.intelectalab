<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Parámetros de los avisos §10 del aula virtual (M-AUL) y de la venta de cursos (M-VTA). Valores a confirmar por la
 * empresa o el especialista.
 */
return new class extends Migration
{
    private const PARAMS = [
        ['course_due_notice_days', '7', 'días', 'Días antes de la fecha límite o del fin del acceso en que se avisa un curso sin completar', 'M-AUL §10', 'M-AUL'],
        ['aul_inactive_learner_days', '7', 'días', 'Días sin actividad en un curso tras los cuales se avisa que el participante está rezagado', 'M-AUL §10', 'M-AUL'],
        ['order_expiry_notice_minutes', '1440', 'minutos', 'Minutos antes del vencimiento de un pedido sin pago en que se avisa al comprador', 'M-VTA §10', 'M-VTA'],
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
