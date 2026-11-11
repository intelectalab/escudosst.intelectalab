<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Matriz de requisitos legales (M-MRL SCR-MRL-01/03): el catálogo de normas tiene normas globales de EscudoSST
 * (company_id NULL, las empresas no las editan: RN-MRL-01) y normas propias de cada empresa (company_id = la empresa,
 * p. ej. una ordenanza municipal o una norma del cliente). Cada norma lleva sectores y temas para el asistente
 * «Agregar desde el catálogo», y el catálogo global de requisitos por norma (legal_norm_requirements) que se previsualiza
 * y se copia a legal_requirements. El texto de los requisitos es referencial: a confirmar por especialista SST/legal.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE legal_norms ADD COLUMN IF NOT EXISTS company_id uuid REFERENCES companies (id)');
        DB::statement('ALTER TABLE legal_norms ADD COLUMN IF NOT EXISTS sectors text[]');
        DB::statement('ALTER TABLE legal_norms ADD COLUMN IF NOT EXISTS topics text[]');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_legal_norms_company ON legal_norms (company_id) WHERE company_id IS NOT NULL');
        DB::statement("COMMENT ON COLUMN legal_norms.company_id IS 'NULL = norma del catálogo global de EscudoSST; con valor = norma propia de la empresa'");

        DB::statement('ALTER TABLE legal_norms ENABLE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS rls_legal_norms_read ON legal_norms');
        DB::statement("CREATE POLICY rls_legal_norms_read ON legal_norms FOR SELECT TO escudo_app
            USING (company_id IS NULL OR company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)");
        DB::statement('DROP POLICY IF EXISTS rls_legal_norms_company_insert ON legal_norms');
        DB::statement("CREATE POLICY rls_legal_norms_company_insert ON legal_norms FOR INSERT TO escudo_app
            WITH CHECK (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)");
        DB::statement('DROP POLICY IF EXISTS rls_legal_norms_company_update ON legal_norms');
        DB::statement("CREATE POLICY rls_legal_norms_company_update ON legal_norms FOR UPDATE TO escudo_app
            USING (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)
            WITH CHECK (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)");
        DB::statement('DROP POLICY IF EXISTS rls_legal_norms_platform ON legal_norms');
        DB::statement('CREATE POLICY rls_legal_norms_platform ON legal_norms TO escudo_platform USING (company_id IS NULL) WITH CHECK (company_id IS NULL)');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON legal_norms TO escudo_app');

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS legal_norm_requirements (
              id uuid NOT NULL DEFAULT uuidv7() PRIMARY KEY,
              legal_norm_id uuid NOT NULL REFERENCES legal_norms (id),
              article text,
              requirement text NOT NULL,
              topic text,
              evidence_expected text,
              frequency text,
              sort_order integer NOT NULL DEFAULT 0,
              created_at timestamptz NOT NULL DEFAULT now(),
              created_by uuid,
              updated_at timestamptz NOT NULL DEFAULT now(),
              updated_by uuid,
              deleted_at timestamptz
            )
            SQL);
        DB::statement("COMMENT ON TABLE legal_norm_requirements IS '[MRL] Catálogo global de requisitos por norma (texto referencial, a confirmar por especialista)'");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_legal_norm_requirements ON legal_norm_requirements (legal_norm_id, coalesce(article, ''), requirement) WHERE deleted_at IS NULL");
        DB::statement('GRANT SELECT ON legal_norm_requirements TO escudo_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON legal_norm_requirements TO escudo_platform');

        // Sectores y temas del catálogo inicial (a confirmar por especialista).
        $tags = [
            '29783' => [['general'], ['gestion', 'comite', 'capacitacion', 'salud_ocupacional', 'eventos']],
            '30222' => [['general'], ['gestion', 'salud_ocupacional']],
            '005-2012-TR' => [['general'], ['gestion', 'registros', 'comite', 'capacitacion', 'salud_ocupacional']],
            '006-2014-TR' => [['general'], ['gestion', 'salud_ocupacional']],
            '014-2013-TR' => [['general'], ['auditoria']],
            '050-2013-TR' => [['general'], ['registros']],
            '019-2006-TR' => [['general'], ['inspeccion']],
            '375-2008-TR' => [['general'], ['ergonomia']],
            '312-2011-MINSA' => [['general'], ['salud_ocupacional']],
            '015-2005-SA' => [['general', 'industria', 'mineria'], ['agentes']],
            '003-98-SA' => [['general', 'construccion', 'mineria', 'industria'], ['sctr']],
            '011-2019-TR' => [['construccion'], ['gestion', 'construccion']],
            '024-2016-EM' => [['mineria'], ['gestion', 'mineria']],
            'NTP 399.010-1' => [['general'], ['senalizacion']],
            '29733' => [['general'], ['datos_personales']],
            '016-2024-JUS' => [['general'], ['datos_personales']],
            '29571' => [['comercio'], ['consumidor']],
            '011-2011-PCM' => [['comercio'], ['consumidor']],
        ];
        foreach ($tags as $number => [$sectors, $topics]) {
            DB::update('update legal_norms set sectors = coalesce(sectors, ?::text[]), topics = coalesce(topics, ?::text[]) where number = ? and company_id is null',
                ['{'.implode(',', $sectors).'}', '{'.implode(',', $topics).'}', $number]);
        }

        // Requisitos referenciales por norma: [número, artículo, requisito, tema, evidencia esperada, frecuencia].
        $reqs = [
            ['29783', 'Art. 17', 'Implementar el Sistema de Gestión de la Seguridad y Salud en el Trabajo según los instrumentos y directrices vigentes.', 'gestion', 'Documentos del SG-SST aprobados', 'Anual'],
            ['29783', 'Art. 22', 'Contar con una Política de SST por escrito, firmada por la alta dirección, difundida y revisada periódicamente.', 'gestion', 'Política firmada y constancia de difusión', 'Anual'],
            ['29783', 'Art. 29', 'Constituir el Comité de SST (o designar Supervisor de SST según el número de trabajadores) con participación paritaria.', 'comite', 'Acta de instalación y libro de actas', 'Cada mandato'],
            ['29783', 'Art. 34', 'Elaborar el Reglamento Interno de SST y entregarlo a cada trabajador.', 'gestion', 'RISST aprobado y cargos de entrega', 'Al actualizarse'],
            ['29783', 'Art. 35', 'Capacitar a los trabajadores en SST al contratarlos, durante el desempeño y ante cambios en sus funciones o tecnologías.', 'capacitacion', 'Registros de capacitación', 'Según programa anual'],
            ['29783', 'Art. 49', 'Practicar exámenes médicos ocupacionales antes, durante y al término de la relación laboral.', 'salud_ocupacional', 'Constancias de aptitud', 'Según protocolo'],
            ['29783', 'Art. 57', 'Identificar peligros y evaluar riesgos (IPERC), actualizándolos al menos una vez al año o ante cambios.', 'gestion', 'Matrices IPERC vigentes', 'Anual'],
            ['29783', 'Art. 82', 'Notificar los accidentes mortales e incidentes peligrosos a la Autoridad Administrativa de Trabajo en los plazos establecidos.', 'eventos', 'Constancias de notificación', 'Ante cada evento'],
            ['005-2012-TR', 'Art. 32', 'Elaborar y mantener la documentación del SG-SST (política, RISST, IPERC, mapa de riesgos, programa anual).', 'gestion', 'Documentos del SG-SST', 'Anual'],
            ['005-2012-TR', 'Art. 33', 'Llevar los registros obligatorios del SG-SST (accidentes, EMO, monitoreo, inspecciones, estadísticas, equipos de emergencia, inducción y capacitación, auditorías).', 'registros', 'Registros actualizados', 'Permanente'],
            ['005-2012-TR', 'Art. 35', 'Conservar los registros de enfermedades ocupacionales por 20 años y los demás registros por 10 años.', 'registros', 'Archivo de registros', 'Permanente'],
            ['005-2012-TR', 'Art. 77', 'Realizar la evaluación inicial o línea base del SG-SST como diagnóstico del estado de la SST.', 'gestion', 'Informe de línea base', 'Al implementar'],
            ['005-2012-TR', 'Art. 101', 'Realizar los exámenes médicos ocupacionales periódicos con la periodicidad aplicable.', 'salud_ocupacional', 'Programa de EMO y constancias', 'Según periodicidad'],
            ['050-2013-TR', null, 'Usar los formatos referenciales con la información mínima de los registros obligatorios del SG-SST.', 'registros', 'Registros en formato aprobado', 'Permanente'],
            ['375-2008-TR', null, 'Evaluar los riesgos disergonómicos de los puestos de trabajo y adoptar las medidas correctivas.', 'ergonomia', 'Evaluación ergonómica por puesto', 'Anual'],
            ['375-2008-TR', null, 'Capacitar a los trabajadores en la prevención de riesgos disergonómicos.', 'ergonomia', 'Registros de capacitación', 'Anual'],
            ['312-2011-MINSA', null, 'Aplicar los protocolos de exámenes médico ocupacionales según la actividad y los riesgos del puesto.', 'salud_ocupacional', 'Protocolos por puesto', 'Según protocolo'],
            ['015-2005-SA', null, 'Monitorear los agentes químicos del ambiente de trabajo frente a los valores límite permisibles.', 'agentes', 'Informes de monitoreo', 'Según plan de monitoreo'],
            ['003-98-SA', null, 'Contratar el SCTR (salud y pensión) para los trabajadores que realizan actividades de riesgo.', 'sctr', 'Pólizas y constancias vigentes', 'Mensual'],
            ['003-98-SA', null, 'Verificar que los contratistas y subcontratistas cuenten con SCTR vigente para su personal.', 'sctr', 'Constancias SCTR de contratistas', 'Mensual'],
            ['011-2019-TR', null, 'Elaborar el Plan de SST de la obra antes del inicio de los trabajos.', 'construccion', 'Plan de SST de obra', 'Por obra'],
            ['011-2019-TR', null, 'Contar con análisis de trabajo seguro y permisos para trabajos de alto riesgo en obra.', 'construccion', 'ATS y permisos firmados', 'Diario'],
            ['024-2016-EM', null, 'Contar con el Programa Anual de Seguridad y Salud Ocupacional de la unidad minera.', 'mineria', 'Programa anual aprobado', 'Anual'],
            ['024-2016-EM', null, 'Emitir PETAR para trabajos de alto riesgo y contar con PETS de las tareas.', 'mineria', 'PETAR y PETS', 'Por tarea'],
            ['NTP 399.010-1', null, 'Señalizar las áreas de trabajo con colores, símbolos y formas normalizados.', 'senalizacion', 'Inspección de señalización', 'Semestral'],
            ['29733', null, 'Tratar los datos personales de los trabajadores con consentimiento y medidas de seguridad, en especial los datos de salud.', 'datos_personales', 'Consentimientos y medidas de seguridad', 'Anual'],
        ];
        foreach ($reqs as $i => [$number, $article, $text, $topic, $evidence, $freq]) {
            DB::insert(
                "insert into legal_norm_requirements (legal_norm_id, article, requirement, topic, evidence_expected, frequency, sort_order)
                 select id, ?, ?, ?, ?, ?, ? from legal_norms where number = ? and company_id is null and deleted_at is null
                 on conflict do nothing",
                [$article, $text, $topic, $evidence, $freq, $i, $number]
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS legal_norm_requirements');
        DB::statement('DROP POLICY IF EXISTS rls_legal_norms_read ON legal_norms');
        DB::statement('DROP POLICY IF EXISTS rls_legal_norms_company_insert ON legal_norms');
        DB::statement('DROP POLICY IF EXISTS rls_legal_norms_company_update ON legal_norms');
        DB::statement('DROP POLICY IF EXISTS rls_legal_norms_platform ON legal_norms');
        DB::statement('ALTER TABLE legal_norms DISABLE ROW LEVEL SECURITY');
        DB::statement('REVOKE INSERT, UPDATE ON legal_norms FROM escudo_app');
        DB::statement('DELETE FROM legal_norms WHERE company_id IS NOT NULL');
        DB::statement('ALTER TABLE legal_norms DROP COLUMN IF EXISTS company_id, DROP COLUMN IF EXISTS sectors, DROP COLUMN IF EXISTS topics');
    }
};
