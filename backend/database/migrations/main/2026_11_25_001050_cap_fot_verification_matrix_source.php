<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * - M-CAP SCR-CAP-13: la página pública de verificación de la empresa tiene su texto (parámetro «certificate_verification_page_text»,
 *   vacío por defecto) y su límite de consultas (la empresa fija su propio «certificate_verify_rate_limit»).
 * - M-FOT RN-FOT-06: límite por IP de la verificación pública del fotocheck (parámetro «credential_verify_rate_limit»). La ficha nombra
 *   el parámetro sin valor: nace «por confirmar» con 30 (el límite que ya tenía la ruta).
 * - M-CAP §5 position_training_requirements.source (manual, PCA, IPERC, paquete sectorial): origen de cada fila de la matriz por cargo,
 *   para las propuestas desde el IPERC y el PCA (SCR-CAP-07).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const PARAMS = [
        ['certificate_verification_page_text', '', 'text', null, 'Texto de la página pública de verificación de certificados de la empresa', 'M-CAP SCR-CAP-13', 'M-CAP'],
        ['credential_verify_rate_limit', '30', 'integer', 'consultas por minuto', 'Consultas de verificación pública de fotochecks por IP y minuto', 'M-FOT RN-FOT-06', 'M-FOT'],
    ];

    public function up(): void
    {
        DB::statement("alter table position_training_requirements add column if not exists source text not null default 'manual'");
        DB::statement('alter table position_training_requirements drop constraint if exists ck_position_training_requirements_source');
        DB::statement("alter table position_training_requirements add constraint ck_position_training_requirements_source
                       check (source in ('manual', 'pca', 'iperc', 'sector_package'))");
        DB::statement("comment on column position_training_requirements.source is 'origen de la fila: manual, PCA, IPERC o paquete sectorial (M-CAP §5)'");
        foreach (self::PARAMS as [$key, $value, $type, $unit, $desc, $ref, $src]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, ?, date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $src, $key]);
        }
    }

    public function down(): void
    {
        DB::statement('alter table position_training_requirements drop constraint if exists ck_position_training_requirements_source');
        DB::statement('alter table position_training_requirements drop column if exists source');
    }
};
