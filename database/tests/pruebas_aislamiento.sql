-- =====================================================================================
-- EscudoSST · Pruebas de aislamiento multiempresa e inalterabilidad
-- Se ejecuta con psql sobre escudo_main (bloque principal) y, al final, se conecta a escudo_medical.
-- Requiere: esquemas y semillas cargados; usuario de conexión con membresía en escudo_app y escudo_platform
-- (lo crea tools/db/verificar_esquema.sh). Toda prueba que falla lanza una excepción (ON_ERROR_STOP).
-- Variables psql: :medical_db (nombre de la base médica).
-- =====================================================================================
\set ON_ERROR_STOP 1
\o /dev/null
SET client_min_messages = notice;

-- Ayudante: espera que una sentencia falle con el SQLSTATE indicado (o con cualquiera si p_state es NULL).
CREATE OR REPLACE FUNCTION pg_temp.expect_error(p_sql text, p_state text, p_label text) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
  BEGIN
    EXECUTE p_sql;
  EXCEPTION WHEN OTHERS THEN
    IF p_state IS NOT NULL AND SQLSTATE <> p_state THEN
      RAISE EXCEPTION 'FALLA %: error % (%) distinto del esperado %', p_label, SQLSTATE, SQLERRM, p_state;
    END IF;
    RAISE NOTICE 'OK  %  (rechazado: %)', p_label, SQLSTATE;
    RETURN;
  END;
  RAISE EXCEPTION 'FALLA %: la sentencia debía fallar y se ejecutó', p_label;
END $$;

CREATE OR REPLACE FUNCTION pg_temp.expect(p_cond boolean, p_label text) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
  IF p_cond IS NOT TRUE THEN RAISE EXCEPTION 'FALLA %', p_label; END IF;
  RAISE NOTICE 'OK  %', p_label;
END $$;

GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

-- -------------------------------------------------------------------------------------
-- 0. Configuración del rol de aplicación
-- -------------------------------------------------------------------------------------
SELECT pg_temp.expect(NOT rolbypassrls, 'escudo_app no tiene BYPASSRLS') FROM pg_roles WHERE rolname = 'escudo_app';
SELECT pg_temp.expect(NOT rolbypassrls, 'escudo_owner no tiene BYPASSRLS') FROM pg_roles WHERE rolname = 'escudo_owner';
SELECT pg_temp.expect(NOT rolsuper, 'escudo_app no es superusuario') FROM pg_roles WHERE rolname = 'escudo_app';
SELECT pg_temp.expect(count(*) = 0, 'toda tabla con company_id tiene RLS habilitado y forzado')
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition
   AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'company_id' AND NOT a.attisdropped)
   AND NOT (c.relrowsecurity AND c.relforcerowsecurity);
SELECT pg_temp.expect(count(*) = 0, 'ninguna tabla de empresa con RLS queda sin política')
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND c.relrowsecurity AND NOT c.relispartition
   AND NOT EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid);

-- -------------------------------------------------------------------------------------
-- 1. Alta de dos empresas (como dueño de la plataforma) y seed_company_defaults
-- -------------------------------------------------------------------------------------
SET ROLE escudo_platform;
INSERT INTO accounts (id, name, account_type) VALUES
  ('00000000-0000-7000-8000-00000000000a', 'Cuenta Alfa', 'direct'),
  ('00000000-0000-7000-8000-00000000000b', 'Cuenta Beta', 'direct');
INSERT INTO companies (id, account_id, ruc, legal_name, sst_model, headcount_declared) VALUES
  ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-00000000000a', '20100000001', 'ALFA INDUSTRIAL S.A.C.', 'committee', 80),
  ('00000000-0000-7000-8000-0000000000b1', '00000000-0000-7000-8000-00000000000b', '20200000002', 'BETA SERVICIOS S.A.C.', 'supervisor', 12);
INSERT INTO users (id, email, full_name, status) VALUES
  ('00000000-0000-7000-8000-0000000000f1', 'jefe.sst@alfa.pe', 'Jefe SST Alfa', 'active'),
  ('00000000-0000-7000-8000-0000000000f2', 'supervisor@beta.pe', 'Supervisor Beta', 'active');
INSERT INTO subscriptions (account_id, company_id, plan_id, status)
SELECT '00000000-0000-7000-8000-00000000000a', '00000000-0000-7000-8000-0000000000a1', id, 'active' FROM plans WHERE code = 'P';
INSERT INTO subscriptions (account_id, company_id, plan_id, status)
SELECT '00000000-0000-7000-8000-00000000000b', '00000000-0000-7000-8000-0000000000b1', id, 'active' FROM plans WHERE code = 'E';
SELECT seed_company_defaults('00000000-0000-7000-8000-0000000000a1');
SELECT seed_company_defaults('00000000-0000-7000-8000-0000000000b1');
SELECT seed_company_defaults('00000000-0000-7000-8000-0000000000a1');  -- idempotente
RESET ROLE;

-- -------------------------------------------------------------------------------------
-- 2. Datos de la empresa ALFA (rol de aplicación, contexto de ALFA)
-- -------------------------------------------------------------------------------------
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f1', true);
SELECT pg_temp.expect((SELECT count(*) FROM sites) = 1, 'ALFA: seed creó exactamente una sede principal');
SELECT pg_temp.expect((SELECT count(*) FROM feature_flags WHERE mode IN ('enabled', 'disabled_by_company')) = 39, 'ALFA (Profesional): 39 módulos incluidos en el plan');
SELECT pg_temp.expect((SELECT count(*) FROM feature_flags WHERE mode = 'enabled') = 38, 'ALFA: 38 módulos efectivos (VTA desactivado por defecto)');
SELECT pg_temp.expect((SELECT company_enabled FROM feature_flags WHERE module_code = 'AUL') AND app_module_enabled('AUL'), 'ALFA: AUL activo por defecto');
SELECT pg_temp.expect((SELECT mode = 'disabled_by_company' AND source = 'company_setting' AND NOT company_enabled FROM feature_flags WHERE module_code = 'VTA')
                      AND NOT app_module_enabled('VTA'), 'ALFA: VTA disabled_by_company por defecto (company_setting)');
SELECT pg_temp.expect((SELECT mode FROM feature_flags WHERE module_code = 'PTR') = 'locked', 'ALFA: PETAR (Corporativo) bloqueado');
SELECT pg_temp.expect((SELECT count(*) FROM risk_criteria WHERE company_id IS NOT NULL) = 1, 'ALFA: criterio de riesgo propio copiado');
SELECT pg_temp.expect((SELECT count(*) FROM credential_requirements) = 3, 'ALFA: requisitos base del fotocheck');
INSERT INTO positions (id, company_id, code, name) VALUES ('00000000-0000-7000-8000-0000000000a3', '00000000-0000-7000-8000-0000000000a1', 'OPE', 'Operario');
INSERT INTO workers (id, company_id, document_type, document_number, document_number_hash, first_name, paternal_last_name, current_position_id)
VALUES ('00000000-0000-7000-8000-0000000000a4', '00000000-0000-7000-8000-0000000000a1', 'DNI', 'cifrado:alfa', '\xaa01', 'Ana', 'Quispe', '00000000-0000-7000-8000-0000000000a3');
INSERT INTO fitness_status (company_id, worker_id, status, valid_from, valid_until)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000a4', 'fit', current_date, current_date + 365);
INSERT INTO credentials (company_id, credential_type_id, worker_id, credential_number, token_hash, status, expires_on)
SELECT '00000000-0000-7000-8000-0000000000a1', id, '00000000-0000-7000-8000-0000000000a4', 'ALFA-0001',
       sha256(convert_to('token-alfa-qr', 'UTF8')), 'active', current_date + 365 FROM credential_types;
INSERT INTO sst_events (company_id, code, event_type, occurred_at, site_id, description)
SELECT '00000000-0000-7000-8000-0000000000a1', 'EV-0001', 'incident', now() - interval '2 hours', id, 'Caída de herramienta desde andamio' FROM sites;
INSERT INTO report_points (company_id, code, name, site_id, token_hash)
SELECT '00000000-0000-7000-8000-0000000000a1', 'QR-01', 'Almacén', id, sha256(convert_to('qr-alfa', 'UTF8')) FROM sites;
INSERT INTO audit_logs (company_id, actor_user_id, action, module_code, entity_type, entity_id)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000f1', 'create', 'TRB', 'workers', '00000000-0000-7000-8000-0000000000a4');
INSERT INTO signatures (company_id, subject_type, subject_id, signer_worker_id, signer_name, method, document_hash, evidence_hash, signed_at)
VALUES ('00000000-0000-7000-8000-0000000000a1', 'ppe_deliveries', uuidv7(), '00000000-0000-7000-8000-0000000000a4', 'Ana Quispe', 'pin', '\x01', '\x02', now());
INSERT INTO iperc_matrices (company_id, code, title, risk_criteria_id)
SELECT '00000000-0000-7000-8000-0000000000a1', 'IPERC-01', 'IPERC Operario', id FROM risk_criteria WHERE company_id IS NOT NULL;
SELECT id AS alfa_crit FROM risk_criteria WHERE company_id IS NOT NULL \gset
COMMIT;

-- -------------------------------------------------------------------------------------
-- 3. Datos de BETA y pruebas de aislamiento (contexto de BETA)
-- -------------------------------------------------------------------------------------
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000b1', true);
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f2', true);
INSERT INTO workers (id, company_id, document_type, document_number, document_number_hash, first_name, paternal_last_name)
VALUES ('00000000-0000-7000-8000-0000000000b4', '00000000-0000-7000-8000-0000000000b1', 'DNI', 'cifrado:beta', '\xbb01', 'Luis', 'Mamani');

SELECT pg_temp.expect((SELECT count(*) FROM workers) = 1, 'BETA ve solo su trabajador');
SELECT pg_temp.expect(NOT EXISTS (SELECT 1 FROM workers WHERE id = '00000000-0000-7000-8000-0000000000a4'), 'BETA no ve el trabajador de ALFA por id');
SELECT pg_temp.expect((SELECT count(*) FROM fitness_status) = 0, 'BETA no ve aptitudes de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM credentials) = 0, 'BETA no ve fotochecks de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM sst_events) = 0, 'BETA no ve eventos de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM audit_logs) = 1, 'BETA ve solo su bitácora (evento de alta)');
SELECT pg_temp.expect((SELECT count(*) FROM signatures) = 0, 'BETA no ve firmas de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM companies) = 1, 'BETA ve solo su empresa');
SELECT pg_temp.expect((SELECT count(*) FROM sites) = 1, 'BETA ve solo su sede');
SELECT pg_temp.expect((SELECT count(*) FROM feature_flags WHERE mode = 'enabled') = 22, 'BETA (Esencial): 22 módulos habilitados');
SELECT pg_temp.expect((SELECT count(*) FROM hazard_catalog WHERE company_id IS NULL) > 50, 'BETA lee el catálogo global de peligros');
SELECT pg_temp.expect((SELECT count(*) FROM risk_criteria WHERE company_id IS NOT NULL) = 1, 'BETA ve solo su propio criterio de riesgo');

-- Escrituras cruzadas: actualizar o borrar filas de ALFA no afecta nada
WITH u AS (UPDATE workers SET first_name = 'X' WHERE id = '00000000-0000-7000-8000-0000000000a4' RETURNING 1)
SELECT pg_temp.expect((SELECT count(*) FROM u) = 0, 'BETA no puede actualizar trabajador de ALFA');
COMMIT;

BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000b1', true);
SELECT pg_temp.expect_error($$INSERT INTO workers (company_id, document_type, document_number, document_number_hash, first_name, paternal_last_name)
  VALUES ('00000000-0000-7000-8000-0000000000a1', 'DNI', 'x', '\xcc', 'Intruso', 'X')$$, '42501', 'BETA no puede insertar con company_id de ALFA (WITH CHECK)');
SELECT pg_temp.expect_error($$INSERT INTO fitness_status (company_id, worker_id, status)
  VALUES ('00000000-0000-7000-8000-0000000000b1', '00000000-0000-7000-8000-0000000000a4', 'fit')$$, '23503', 'FK compuesta impide enlazar trabajador de ALFA desde BETA');
SELECT pg_temp.expect_error(format($f$INSERT INTO iperc_matrices (company_id, code, title, risk_criteria_id)
  VALUES ('00000000-0000-7000-8000-0000000000b1', 'X', 'X', %L)$f$, :'alfa_crit'),
  '23503', 'BETA no puede usar el criterio de riesgo propio de ALFA (catálogo mixto)');
SELECT pg_temp.expect_error($$UPDATE subscriptions SET status = 'cancelled'$$, '42501', 'la app no modifica suscripciones sin app.account_id');
SELECT pg_temp.expect_error($$INSERT INTO hazard_catalog (company_id, code, hazard_family, hazard, risk) VALUES (NULL, 'GLB', 'other', 'x', 'y')$$,
  '42501', 'la app no escribe filas globales del catálogo de peligros');
SELECT pg_temp.expect_error($$INSERT INTO legal_norms (norm_type, number, title) VALUES ('law', '1', 'x')$$, '42501', 'la app no escribe catálogos globales');
COMMIT;

-- Sin contexto: no se ve nada
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((SELECT count(*) FROM workers) = 0, 'sin app.company_id no se ve ningún trabajador');
SELECT pg_temp.expect((SELECT count(*) FROM companies) = 0, 'sin contexto no se ve ninguna empresa');
SELECT pg_temp.expect((SELECT count(*) FROM audit_logs) = 0, 'sin contexto no se ve la bitácora');
COMMIT;

-- El dueño de los objetos tampoco salta RLS (FORCE)
BEGIN;
SET LOCAL ROLE escudo_owner;
SELECT pg_temp.expect((SELECT count(*) FROM workers) = 0, 'el dueño de las tablas sin contexto no ve trabajadores (FORCE RLS)');
COMMIT;

-- -------------------------------------------------------------------------------------
-- 4. Tablas inalterables: UPDATE y DELETE rechazados (incluso para el dueño)
-- -------------------------------------------------------------------------------------
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
SELECT pg_temp.expect_error($$UPDATE audit_logs SET action = 'x'$$, '42501', 'audit_logs rechaza UPDATE');
SELECT pg_temp.expect_error($$DELETE FROM audit_logs$$, '42501', 'audit_logs rechaza DELETE');
SELECT pg_temp.expect_error($$UPDATE signatures SET signer_name = 'x'$$, '42501', 'signatures rechaza UPDATE');
SELECT pg_temp.expect_error($$DELETE FROM signatures$$, '42501', 'signatures rechaza DELETE');
COMMIT;

-- Verificación pública de fotocheck (función SECURITY DEFINER acotada) y registro inalterable de la verificación
SELECT pg_temp.expect((public_verify_credential('token-alfa-qr') ->> 'result') = 'enabled', 'fotocheck válido: HABILITADO');
SELECT pg_temp.expect((public_verify_credential('token-falso') ->> 'result') = 'invalid', 'token inexistente: no válido');
SELECT pg_temp.expect(NOT (public_verify_credential('token-alfa-qr') ?| ARRAY['diagnoses', 'fitness_status', 'work_restrictions', 'document_number']),
                      'la verificación pública no expone datos médicos ni el documento');
SELECT pg_temp.expect((public_submit_report('qr-alfa', 'unsafe_condition', 'Piso mojado sin señalizar', true, NULL, NULL, 'idem-1') ->> 'replay') = 'false', 'reporte por QR registrado');
SELECT pg_temp.expect((public_submit_report('qr-alfa', 'unsafe_condition', 'Piso mojado sin señalizar', true, NULL, NULL, 'idem-1') ->> 'replay') = 'true', 'reporte por QR idempotente');

DO $$
DECLARE t text;
BEGIN
  -- Como superusuario (usuario de la prueba) el trigger también rechaza: la inalterabilidad no depende solo de GRANT.
  FOREACH t IN ARRAY ARRAY['audit_logs','sensitive_access_logs','signatures','credential_verifications','access_events',
                           'election_ballots','document_acknowledgements','notification_deliveries',
                           'certificate_verifications','payment_transactions'] LOOP
    PERFORM pg_temp.expect_error(format('UPDATE %I SET created_at = created_at', t), '42501', t || ' rechaza UPDATE (trigger)');
    PERFORM pg_temp.expect_error(format('DELETE FROM %I', t), '42501', t || ' rechaza DELETE (trigger)');
    PERFORM pg_temp.expect_error(format('TRUNCATE %I', t), NULL, t || ' rechaza TRUNCATE');
  END LOOP;
END $$;
SELECT pg_temp.expect((SELECT count(*) FROM credential_verifications) = 2, 'cada verificación de fotocheck válida queda registrada (2)');

-- Cadena de hash de audit_logs íntegra
SELECT pg_temp.expect(NOT EXISTS (SELECT 1 FROM audit_logs_verify_chain('00000000-0000-7000-8000-0000000000a1')), 'cadena de hash de ALFA íntegra');
SELECT pg_temp.expect((SELECT count(*) FROM audit_logs WHERE company_id = '00000000-0000-7000-8000-0000000000a1' AND prev_hash IS NOT NULL) >= 1,
                      'audit_logs encadena prev_hash');
-- Manipulación detectada: se desactiva el trigger (solo posible para el dueño/superusuario) y se altera una fila
ALTER TABLE audit_logs DISABLE TRIGGER trg_audit_logs_immutable;
ALTER TABLE audit_logs_default DISABLE TRIGGER USER;
UPDATE audit_logs SET action = 'manipulado' WHERE company_id = '00000000-0000-7000-8000-0000000000a1' AND chain_seq = 2;
SELECT pg_temp.expect(EXISTS (SELECT 1 FROM audit_logs_verify_chain('00000000-0000-7000-8000-0000000000a1')), 'la verificación detecta una fila manipulada');
UPDATE audit_logs SET action = 'create' WHERE company_id = '00000000-0000-7000-8000-0000000000a1' AND chain_seq = 2;
ALTER TABLE audit_logs ENABLE TRIGGER trg_audit_logs_immutable;
ALTER TABLE audit_logs_default ENABLE TRIGGER USER;

-- Offline: idempotency_key única por empresa y control de versión
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
INSERT INTO inspections (company_id, title, inspection_type, site_id, idempotency_key)
SELECT company_id, 'Inspección offline', 'planned', id, 'dev1-op-1' FROM sites;
SELECT pg_temp.expect_error($$INSERT INTO inspections (company_id, title, inspection_type, site_id, idempotency_key)
  SELECT company_id, 'Duplicada', 'planned', id, 'dev1-op-1' FROM sites$$, '23505', 'idempotency_key duplicada rechazada');
UPDATE inspections SET title = 'v2' WHERE idempotency_key = 'dev1-op-1';
SELECT pg_temp.expect((SELECT version FROM inspections WHERE idempotency_key = 'dev1-op-1') = 2, 'version se incrementa al actualizar');
SELECT pg_temp.expect_error($$UPDATE inspections SET title = 'v?', version = 7 WHERE idempotency_key = 'dev1-op-1'$$, '40001', 'versión desfasada = conflicto');
COMMIT;

-- Reglas de negocio en BD: IA asistida exige sugerencia; aprobador ≠ elaborador
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
INSERT INTO iperc_versions (company_id, iperc_matrix_id, version_number, prepared_by)
SELECT company_id, id, 1, '00000000-0000-7000-8000-0000000000f1' FROM iperc_matrices;
SELECT pg_temp.expect_error($$UPDATE iperc_versions SET status = 'approved', approved_by = prepared_by, approved_at = now()$$, '23514', 'IPERC: aprobador distinto del elaborador');
SELECT pg_temp.expect_error($$INSERT INTO iperc_rows (company_id, iperc_version_id, row_number, activity, task, hazard, risk, probability_value, severity_value, risk_level, ai_assisted)
  SELECT company_id, id, 1, 'a', 't', 'h', 'r', 6, 2, 'moderate', true FROM iperc_versions$$, '23514', 'IPERC: fila asistida por IA exige ai_suggestion_id');
COMMIT;

-- -------------------------------------------------------------------------------------
-- 4b. Activación por empresa de AUL y VTA (feature_flags.company_enabled, modo disabled_by_company)
-- -------------------------------------------------------------------------------------
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f1', true);
SELECT pg_temp.expect_error($$UPDATE feature_flags SET mode = 'enabled' WHERE module_code = 'PTR'$$, '42501',
                            'la app no cambia el modo de un flag (PETAR sigue bloqueado)');
SELECT pg_temp.expect_error($$UPDATE feature_flags SET company_enabled = false WHERE module_code = 'IPC'$$, '23514',
                            'solo AUL y VTA se pueden desactivar por empresa (CHECK)');
SELECT pg_temp.expect_error($$SELECT set_company_module_enabled('IPC', false)$$, '23514', 'set_company_module_enabled rechaza módulos no permitidos');
SELECT pg_temp.expect((set_company_module_enabled('VTA', true) ->> 'effective')::boolean, 'ALFA activa VTA dentro de su plan');
SELECT pg_temp.expect(app_module_enabled('VTA') AND (SELECT mode FROM feature_flags WHERE module_code = 'VTA') = 'enabled', 'ALFA: VTA efectivo (enabled) tras activarlo');
SELECT pg_temp.expect_error($$SELECT set_company_module_enabled('AUL', false)$$, '23514', 'no se desactiva AUL mientras VTA está activo');
COMMIT;
SET ROLE escudo_platform;
SELECT seed_company_defaults('00000000-0000-7000-8000-0000000000a1');
RESET ROLE;
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
SELECT pg_temp.expect((SELECT company_enabled AND mode = 'enabled' FROM feature_flags WHERE module_code = 'VTA'), 'recalcular flags respeta la activación de VTA elegida por la empresa');
COMMIT;
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000b1', true);
SELECT pg_temp.expect((SELECT mode FROM feature_flags WHERE module_code = 'AUL') = 'locked' AND NOT app_module_enabled('AUL'), 'BETA (Esencial): AUL bloqueado');
COMMIT;

-- -------------------------------------------------------------------------------------
-- 4c. CAP / AUL / VTA: datos de ALFA
-- -------------------------------------------------------------------------------------
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f1', true);
UPDATE companies SET catalog_slug = 'alfa-industrial' WHERE id = '00000000-0000-7000-8000-0000000000a1';
SELECT pg_temp.expect((SELECT count(*) FROM grading_scales WHERE company_id IS NULL) = 5, 'ALFA (AUL activo) lee las 5 escalas de calificación globales');
SELECT pg_temp.expect((SELECT count(*) FROM certificate_templates WHERE company_id IS NULL AND is_default) = 1, 'plantilla de certificado global por defecto');
INSERT INTO certificate_templates (id, company_id, code, name, body_template, code_prefix, is_default)
VALUES ('00000000-0000-7000-8000-0000000000c1', '00000000-0000-7000-8000-0000000000a1', 'ALFA-CERT', 'Certificado ALFA', 'Se certifica que {{holder_name}}…', 'ALFA', true);
INSERT INTO courses (id, company_id, code, title, modality, sequential, grading_scale_id, passing_score, max_attempts,
                     completion_commitment, commitment_text, hours, certificate_template_id, certificate_validity_months,
                     public_verification_enabled, status, published_at)
SELECT '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000a1', 'TA-01', 'Trabajo en altura', 'both', true,
       id, 14, 3, 'mandatory', 'Me comprometo a culminar el curso.', 8, '00000000-0000-7000-8000-0000000000c1', 12, true, 'published', now()
  FROM grading_scales WHERE company_id IS NULL AND code = 'NUM-0-20';
INSERT INTO course_sections (id, company_id, course_id, section_number, title)
VALUES ('00000000-0000-7000-8000-0000000000c3', '00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', 1, 'Unidad 1');
INSERT INTO course_lessons (company_id, course_id, section_id, lesson_number, title, lesson_type, video_provider, video_external_id, min_view_pct)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000c3', 1, 'Video introductorio', 'video', 'vimeo', '123456789', 90);
SELECT pg_temp.expect_error($$INSERT INTO course_lessons (company_id, course_id, lesson_number, title, lesson_type)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', 2, 'Video sin id', 'video')$$, '23514', 'lección de video exige proveedor Vimeo e id');
INSERT INTO question_banks (id, company_id, course_id, code, name)
VALUES ('00000000-0000-7000-8000-0000000000c4', '00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', 'BQ-TA', 'Banco altura');
INSERT INTO questions (company_id, question_bank_id, question_type, statement, options, points)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c4', 'true_false', '¿El arnés se inspecciona antes de cada uso?',
        '[{"key":"V","text":"Verdadero","is_correct":true},{"key":"F","text":"Falso","is_correct":false}]', 2);
SELECT pg_temp.expect_error($$INSERT INTO questions (company_id, question_bank_id, question_type, statement)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c4', 'short_answer', 'Sin respuestas aceptadas')$$, '23514', 'respuesta corta exige respuestas aceptadas');
INSERT INTO external_learners (id, company_id, document_type, document_number, document_number_hash, first_name, last_name, email,
                               data_consent_at, consent_text_version)
VALUES ('00000000-0000-7000-8000-0000000000c5', '00000000-0000-7000-8000-0000000000a1', 'DNI', 'cifrado:ext', '\xee01', 'Rosa', 'Huamán',
        'rosa@correo.pe', now(), 'v1');
INSERT INTO course_enrollments (id, company_id, course_id, worker_id, source)
VALUES ('00000000-0000-7000-8000-0000000000c6', '00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000a4', 'manual');
INSERT INTO course_enrollments (id, company_id, course_id, external_learner_id, source)
VALUES ('00000000-0000-7000-8000-0000000000c7', '00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000c5', 'manual');
SELECT pg_temp.expect_error($$INSERT INTO course_enrollments (company_id, course_id, worker_id, external_learner_id, source)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000a4', '00000000-0000-7000-8000-0000000000c5', 'manual')$$,
  '23514', 'matrícula: trabajador y participante externo a la vez rechazado (exactamente uno)');
SELECT pg_temp.expect_error($$INSERT INTO course_enrollments (company_id, course_id, source)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', 'manual')$$, '23514', 'matrícula sin participante rechazada');
SELECT pg_temp.expect_error($$INSERT INTO course_enrollments (company_id, course_id, worker_id, source)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000a4', 'manual')$$, '23505', 'una matrícula vigente por persona y curso');
SELECT pg_temp.expect_error($$INSERT INTO course_enrollments (company_id, course_id, external_learner_id, source)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000c5', 'purchase')$$, '23514', 'matrícula por compra exige la línea del pedido');
INSERT INTO learning_activity_sessions (company_id, enrollment_id, client_session_id, started_at, last_heartbeat_at, active_seconds, video_seconds, interactions_count, heartbeats_count, last_heartbeat_seq)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c6', 'tab-1', now() - interval '10 minutes', now(), 540, 300, 12, 20, 20);
SELECT pg_temp.expect_error($$INSERT INTO learning_activity_sessions (company_id, enrollment_id, client_session_id, started_at, last_heartbeat_at)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c6', 'tab-1', now(), now())$$, '23505', 'latido idempotente: una sesión por (matrícula, client_session_id)');
SELECT pg_temp.expect_error($$INSERT INTO learning_activity_sessions (company_id, enrollment_id, client_session_id, started_at, last_heartbeat_at, active_seconds)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c6', 'tab-2', now() - interval '1 minute', now(), 3600)$$, '23514', 'tiempo activo no puede superar el tiempo transcurrido');
INSERT INTO completion_commitments (company_id, enrollment_id, course_id, commitment_text_snapshot, accepted_by_user_id)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c6', '00000000-0000-7000-8000-0000000000c2', 'Me comprometo a culminar el curso.', '00000000-0000-7000-8000-0000000000f1');
INSERT INTO training_certificates (id, company_id, course_id, course_enrollment_id, worker_id, certificate_template_id, certificate_code, verification_code,
                                   holder_name, holder_document_masked, course_title, issued_on, expires_on, hours, score, public_verification_enabled,
                                   status, validated_by, validated_at)
VALUES ('00000000-0000-7000-8000-0000000000c8', '00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000c6',
        '00000000-0000-7000-8000-0000000000a4', '00000000-0000-7000-8000-0000000000c1', 'ALFA-2026-000001', 'K7Q29MZX4TPA',
        'Ana Quispe', '****0001', 'Trabajo en altura', current_date, current_date + 365, 8, 17, true, 'validated',
        '00000000-0000-7000-8000-0000000000f1', now());
SELECT pg_temp.expect_error($$UPDATE training_certificates SET status = 'voided' WHERE certificate_code = 'ALFA-2026-000001'$$, '23514', 'anular un certificado exige quién, cuándo y motivo');
SELECT pg_temp.expect_error($$INSERT INTO training_certificates (company_id, course_id, worker_id, certificate_code, verification_code, holder_name, course_title, issued_on)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000a4', 'cert 1', 'K7Q29MZX4TPB', 'X', 'X', current_date)$$, '23514', 'código de certificado con formato legible PREFIJO-AÑO-CORRELATIVO');
INSERT INTO position_training_requirements (company_id, position_id, course_id, requirement_level, renewal_months)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000a3', '00000000-0000-7000-8000-0000000000c2', 'mandatory', 12);
SELECT pg_temp.expect_error($$INSERT INTO position_training_requirements (company_id, position_id, requirement_level)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000a3', 'mandatory')$$, '23514', 'matriz por cargo exige capacitación o curso');
INSERT INTO lms_connections (company_id, name, base_url, credentials_secret_ref)
VALUES ('00000000-0000-7000-8000-0000000000a1', 'Moodle ALFA', 'https://aula.alfa.pe', 'vault://escudo/alfa/moodle');
SELECT pg_temp.expect_error($$INSERT INTO lms_connections (company_id, provider, name, base_url, credentials_secret_ref)
  VALUES ('00000000-0000-7000-8000-0000000000a1', 'yachay', 'Otro', 'https://x.pe', 'vault://x')$$, '23514', 'lms_connections solo admite Moodle');
INSERT INTO recommendation_rules (id, company_id, code, name, rule_type, audience, channels, is_active)
VALUES ('00000000-0000-7000-8000-0000000000d1', '00000000-0000-7000-8000-0000000000a1', 'GAP', 'Brechas de la matriz', 'matrix_gap', 'workers', '{in_app,email}', true);
INSERT INTO course_recommendations (company_id, course_id, worker_id, rule_id, source, reason_code, channel, dedupe_key)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000a4', '00000000-0000-7000-8000-0000000000d1', 'rule', 'matrix_gap', 'in_app', 'w:a4|c:c2|matrix_gap');
SELECT pg_temp.expect_error($$INSERT INTO course_recommendations (company_id, course_id, worker_id, rule_id, source, reason_code, dedupe_key)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000a4', '00000000-0000-7000-8000-0000000000d1', 'rule', 'matrix_gap', 'w:a4|c:c2|matrix_gap')$$,
  '23505', 'recomendación no se repite mientras haya una activa (dedupe_key)');
SELECT pg_temp.expect_error($$INSERT INTO course_recommendations (company_id, course_id, worker_id, buyer_company_ruc, source, recommended_by, dedupe_key)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000a4', '20300000003', 'manual', '00000000-0000-7000-8000-0000000000f1', 'x1')$$,
  '23514', 'recomendación: exactamente un destinatario');
SELECT pg_temp.expect_error($$INSERT INTO course_recommendations (company_id, course_id, external_learner_id, source, recommended_by, status, converted_at, dedupe_key)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000c5', 'manual', '00000000-0000-7000-8000-0000000000f1', 'converted', now(), 'x2')$$,
  '23514', 'recomendación convertida exige la matrícula o el pedido');
SELECT pg_temp.expect_error($$INSERT INTO recommendation_rules (company_id, code, name, rule_type, audience, channels)
  VALUES ('00000000-0000-7000-8000-0000000000a1', 'X', 'X', 'manual', 'workers', '{sms}')$$, '23514', 'reglas de recomendación: solo canales app, correo y WhatsApp');
-- VTA
INSERT INTO course_offers (id, company_id, course_id, slug, title, list_price, sale_price, access_days, is_public, status, published_at)
VALUES ('00000000-0000-7000-8000-0000000000e1', '00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', 'trabajo-en-altura',
        'Trabajo en altura (virtual)', 100.00, 90.00, 90, true, 'published', now());
SELECT pg_temp.expect_error($$INSERT INTO course_offers (company_id, course_id, slug, title, list_price, sale_price)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', 'otra', 'Otra', 50.00, 60.00)$$, '23514', 'precio de oferta no supera el de lista');
INSERT INTO payment_gateways (id, company_id, provider, name, mode, public_key, secret_key_ref, webhook_secret_ref, is_active, is_default)
VALUES ('00000000-0000-7000-8000-0000000000e2', '00000000-0000-7000-8000-0000000000a1', 'culqi', 'Culqi pruebas', 'test', 'pk_test_abc',
        'vault://escudo/alfa/culqi/secret', 'vault://escudo/alfa/culqi/webhook', true, true);
SELECT pg_temp.expect_error($$INSERT INTO payment_gateways (company_id, provider, name, secret_key_ref, webhook_secret_ref)
  VALUES ('00000000-0000-7000-8000-0000000000a1', 'niubiz', 'Niubiz', 'sk_live_123456', 'whsec_123')$$, '23514', 'llave de pasarela en claro rechazada: solo referencia al gestor de secretos');
INSERT INTO orders (id, company_id, order_number, buyer_type, external_learner_id, buyer_document_type, buyer_document_number, buyer_name, buyer_email,
                    payment_gateway_id, subtotal, igv_rate, igv_total, total, idempotency_key)
VALUES ('00000000-0000-7000-8000-0000000000e3', '00000000-0000-7000-8000-0000000000a1', 'PED-2026-000001', 'person', '00000000-0000-7000-8000-0000000000c5',
        'DNI', 'cifrado:ext', 'Rosa Huamán', 'rosa@correo.pe', '00000000-0000-7000-8000-0000000000e2', 76.27, 0.18, 13.73, 90.00, 'ord-idem-1');
SELECT pg_temp.expect_error($$INSERT INTO orders (company_id, order_number, buyer_type, external_learner_id, buyer_document_type, buyer_document_number, buyer_name, buyer_email, subtotal, igv_total, total, idempotency_key)
  VALUES ('00000000-0000-7000-8000-0000000000a1', 'PED-2026-000002', 'person', '00000000-0000-7000-8000-0000000000c5', 'DNI', 'x', 'x', 'x@x.pe', 76.27, 13.73, 90.00, 'ord-idem-1')$$,
  '23505', 'pedido idempotente: misma idempotency_key rechazada');
SELECT pg_temp.expect_error($$INSERT INTO orders (company_id, order_number, buyer_type, buyer_document_type, buyer_document_number, buyer_name, buyer_email, subtotal, igv_total, total, idempotency_key)
  VALUES ('00000000-0000-7000-8000-0000000000a1', 'PED-2026-000003', 'company', 'RUC', 'x', 'x', 'x@x.pe', 10, 1, 99, 'ord-idem-3')$$,
  '23514', 'pedido: total = base + IGV y empresa compradora con RUC');
INSERT INTO order_items (company_id, order_id, course_offer_id, course_id, description, unit_price, subtotal, igv_amount, total)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000e3', '00000000-0000-7000-8000-0000000000e1', '00000000-0000-7000-8000-0000000000c2',
        'Trabajo en altura (virtual)', 90.00, 76.27, 13.73, 90.00);
INSERT INTO payment_transactions (company_id, order_id, payment_gateway_id, provider, external_id, status, amount, method, card_last4, payload, payload_sha256, signature_verified)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000e3', '00000000-0000-7000-8000-0000000000e2', 'culqi', 'evt_001',
        'succeeded', 90.00, 'card', '4242', '{"object":"event","type":"charge.succeeded"}', sha256('evt_001'::bytea), true);
WITH ins AS (
  INSERT INTO payment_transactions (company_id, order_id, payment_gateway_id, provider, external_id, status, amount, payload_sha256, signature_verified)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000e3', '00000000-0000-7000-8000-0000000000e2', 'culqi', 'evt_001',
          'succeeded', 90.00, sha256('evt_001'::bytea), true)
  ON CONFLICT DO NOTHING RETURNING 1)
SELECT pg_temp.expect((SELECT count(*) FROM ins) = 0, 'webhook repetido (mismo id externo) no crea otra transacción');
SELECT pg_temp.expect_error($$INSERT INTO payment_transactions (company_id, order_id, payment_gateway_id, provider, external_id, status, amount, payload_sha256, signature_verified)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000e3', '00000000-0000-7000-8000-0000000000e2', 'culqi', 'evt_002', 'succeeded', 90.00, '\x01', false)$$,
  '23514', 'webhook sin firma verificada rechazado');
SELECT pg_temp.expect_error($$INSERT INTO payment_transactions (company_id, order_id, payment_gateway_id, provider, external_id, status, amount, payload, payload_sha256, signature_verified)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000e3', '00000000-0000-7000-8000-0000000000e2', 'culqi', 'evt_003', 'succeeded', 90.00,
          '{"card_number":"4111111111111111"}', '\x01', true)$$, '23514', 'nunca se guarda el número de tarjeta en el payload');
SELECT pg_temp.expect_error($$UPDATE payment_transactions SET status = 'failed'$$, '42501', 'payment_transactions rechaza UPDATE (app)');
SELECT pg_temp.expect_error($$DELETE FROM payment_transactions$$, '42501', 'payment_transactions rechaza DELETE (app)');
UPDATE orders SET status = 'paid', paid_at = now() WHERE id = '00000000-0000-7000-8000-0000000000e3';
INSERT INTO course_licenses (id, company_id, course_id, buyer_ruc, buyer_legal_name, license_code, seats_purchased)
VALUES ('00000000-0000-7000-8000-0000000000e4', '00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c2', '20300000003', 'GAMMA S.A.C.', 'LIC-0001', 2);
INSERT INTO license_assignments (company_id, course_license_id, external_learner_id)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000e4', '00000000-0000-7000-8000-0000000000c5');
SELECT pg_temp.expect_error($$UPDATE course_licenses SET seats_used = 3$$, '23514', 'licencia: no se usan más cupos que los comprados');
INSERT INTO sales_documents (company_id, order_id, document_type, series, number, issue_date, customer_document_type, customer_document_number, customer_name,
                             taxable_amount, igv_amount, total)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000e3', 'receipt', 'B001', 1, current_date, 'DNI', 'cifrado:ext', 'Rosa Huamán', 76.27, 13.73, 90.00);
SELECT pg_temp.expect_error($$INSERT INTO sales_documents (company_id, order_id, document_type, series, number, issue_date, customer_document_type, customer_document_number, customer_name)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000e3', 'invoice', 'F001', 1, current_date, 'DNI', 'x', 'x')$$, '23514', 'factura exige RUC del cliente');
COMMIT;

-- Público: verificación de certificado por código, catálogo de venta y contexto del webhook
SELECT pg_temp.expect((public_verify_certificate('k7q2-9mzx-4tpa') ->> 'result') = 'valid', 'certificado válido por código público (normaliza guiones y mayúsculas)');
SELECT pg_temp.expect((public_verify_certificate('ZZZZZZZZZZZZ') ->> 'result') = 'invalid', 'código inexistente: no válido');
SELECT pg_temp.expect((public_verify_certificate('1') ->> 'result') = 'invalid', 'código mal formado: no válido');
SELECT pg_temp.expect(NOT (public_verify_certificate('K7Q29MZX4TPA') ?| ARRAY['email', 'phone', 'document_number', 'worker_id', 'score']),
                      'la verificación pública no expone correo, teléfono, documento completo ni nota');
SELECT pg_temp.expect((SELECT count(*) FROM certificate_verifications) = 2, 'cada consulta válida de certificado queda registrada (2)');
SELECT pg_temp.expect_error($$UPDATE certificate_verifications SET result = 'voided'$$, '42501', 'certificate_verifications rechaza UPDATE (trigger)');
SELECT pg_temp.expect_error($$DELETE FROM certificate_verifications$$, '42501', 'certificate_verifications rechaza DELETE (trigger)');
SELECT pg_temp.expect_error($$UPDATE payment_transactions SET amount = 0$$, '42501', 'payment_transactions rechaza UPDATE incluso al superusuario');
SELECT pg_temp.expect((public_course_catalog('alfa-industrial') -> 'offers' -> 0 ->> 'slug') = 'trabajo-en-altura', 'catálogo público lista la oferta publicada de ALFA');
SELECT pg_temp.expect(NOT ((public_course_catalog('alfa-industrial') -> 'offers' -> 0) ?| ARRAY['course_id', 'company_id', 'seats_sold']), 'catálogo público sin ids internos');
SELECT pg_temp.expect((public_course_catalog('no-existe') ->> 'result') = 'not_found', 'catálogo de empresa inexistente: no encontrado');
SELECT pg_temp.expect((SELECT company_id FROM payment_gateway_webhook_context('00000000-0000-7000-8000-0000000000e2', 'culqi')) = '00000000-0000-7000-8000-0000000000a1',
                      'webhook: la pasarela resuelve la empresa sin exponer secretos');
SELECT pg_temp.expect(NOT EXISTS (SELECT 1 FROM payment_gateway_webhook_context('00000000-0000-7000-8000-0000000000e2', 'niubiz')), 'webhook: proveedor distinto no resuelve');

-- Aislamiento de las tablas nuevas (contexto BETA)
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000b1', true);
SELECT pg_temp.expect((SELECT count(*) FROM courses) = 0, 'BETA no ve cursos de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM course_sections) + (SELECT count(*) FROM course_lessons) = 0, 'BETA no ve secciones ni lecciones de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM course_enrollments) + (SELECT count(*) FROM learning_activity_sessions) + (SELECT count(*) FROM completion_commitments) = 0,
                      'BETA no ve matrículas, tiempo de permanencia ni compromisos de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM question_banks) + (SELECT count(*) FROM questions) = 0, 'BETA no ve bancos ni preguntas de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM training_certificates) + (SELECT count(*) FROM certificate_verifications) = 0, 'BETA no ve certificados ni verificaciones de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM certificate_templates) = 1, 'BETA ve solo la plantilla global (no la de ALFA)');
SELECT pg_temp.expect((SELECT count(*) FROM grading_scales) = 0, 'BETA (sin AUL) no ve las escalas globales del aula');
SELECT pg_temp.expect((SELECT count(*) FROM position_training_requirements) + (SELECT count(*) FROM lms_connections) = 0, 'BETA no ve matriz por cargo ni conexión Moodle de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM recommendation_rules) + (SELECT count(*) FROM course_recommendations) = 0, 'BETA no ve reglas ni recomendaciones de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM external_learners) + (SELECT count(*) FROM course_offers) + (SELECT count(*) FROM orders) + (SELECT count(*) FROM order_items) = 0,
                      'BETA no ve participantes, ofertas ni pedidos de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM payment_gateways) + (SELECT count(*) FROM payment_transactions) + (SELECT count(*) FROM sales_documents) = 0,
                      'BETA no ve pasarelas, transacciones ni comprobantes de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM course_licenses) + (SELECT count(*) FROM license_assignments) = 0, 'BETA no ve licencias de ALFA');
WITH u AS (UPDATE orders SET status = 'voided', void_reason = 'x' RETURNING 1)
SELECT pg_temp.expect((SELECT count(*) FROM u) = 0, 'BETA no puede anular pedidos de ALFA');
SELECT pg_temp.expect_error($$INSERT INTO course_enrollments (company_id, course_id, worker_id, source)
  VALUES ('00000000-0000-7000-8000-0000000000b1', '00000000-0000-7000-8000-0000000000c2', '00000000-0000-7000-8000-0000000000b4', 'manual')$$,
  '23503', 'BETA no puede matricular en un curso de ALFA (catálogo mixto)');
SELECT pg_temp.expect_error($$INSERT INTO license_assignments (company_id, course_license_id, external_learner_id)
  VALUES ('00000000-0000-7000-8000-0000000000b1', '00000000-0000-7000-8000-0000000000e4', '00000000-0000-7000-8000-0000000000c5')$$,
  '23503', 'FK compuesta impide usar la licencia y el participante de ALFA desde BETA');
SELECT pg_temp.expect_error($$INSERT INTO payment_transactions (company_id, order_id, payment_gateway_id, provider, external_id, status, amount, payload_sha256, signature_verified)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000e3', '00000000-0000-7000-8000-0000000000e2', 'culqi', 'evt_x', 'succeeded', 1, '\x01', true)$$,
  '42501', 'BETA no inserta transacciones con company_id de ALFA');
SELECT pg_temp.expect_error($$INSERT INTO training_certificates (company_id, course_id, worker_id, certificate_code, verification_code, holder_name, course_title, issued_on)
  SELECT '00000000-0000-7000-8000-0000000000b1', NULL, '00000000-0000-7000-8000-0000000000b4', 'BETA-2026-000001', 'BBBBBBBBBBBB', 'Luis', 'X', current_date$$,
  '23514', 'certificado sin capacitación ni curso rechazado');
SELECT pg_temp.expect_error($$INSERT INTO trainer_permissions (company_id, trainer_user_id, course_id)
  VALUES ('00000000-0000-7000-8000-0000000000b1', '00000000-0000-7000-8000-0000000000f2', '00000000-0000-7000-8000-0000000000c2')$$,
  '23503', 'BETA no puede dar permisos sobre un curso de ALFA');
COMMIT;

-- -------------------------------------------------------------------------------------
-- 4b. WEB · sitio público y CMS (tablas de plataforma sin company_id)
-- -------------------------------------------------------------------------------------
-- La app de empresa no lee ni escribe tablas WEB (ni siquiera borradores): todo pasa por funciones public_*.
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
SELECT pg_temp.expect_error($$SELECT * FROM site_pages$$, '42501', 'WEB: la app de empresa no lee site_pages (borradores)');
SELECT pg_temp.expect_error($$SELECT * FROM consumer_complaints$$, '42501', 'WEB: la app de empresa no lee el Libro de Reclamaciones');
SELECT pg_temp.expect_error($$INSERT INTO site_pages (slug, page_type, title) VALUES ('x', 'landing', 'X')$$, '42501', 'WEB: la app de empresa no escribe site_pages');
SELECT pg_temp.expect_error($$UPDATE site_settings SET legal_name = 'OTRA'$$, '42501', 'WEB: la app de empresa no escribe site_settings');
SELECT pg_temp.expect_error($$INSERT INTO legal_document_versions (legal_document_id, version_number, version_label, content) SELECT id, 9, '9', 'x' FROM legal_documents$$, '42501', 'WEB: la app de empresa no escribe documentos legales');
SELECT pg_temp.expect_error($$INSERT INTO demo_requests (full_name, company_name, email, privacy_consent) VALUES ('a', 'b', 'c@d.pe', true)$$, '42501', 'WEB: la app de empresa no inserta demos directo');
SELECT pg_temp.expect_error($$INSERT INTO site_testimonials (author_name, quote) VALUES ('A', 'B')$$, '42501', 'WEB: la app de empresa no escribe testimonios');
SELECT pg_temp.expect_error($$SELECT web_publish_page(uuidv7())$$, '42501', 'WEB: la app de empresa no ejecuta acciones del panel');
COMMIT;

-- Semillas WEB
SELECT pg_temp.expect((SELECT legal_name = 'GRUPO DE SOLUCIONES CREATIVAS SAC' AND ruc = '20607431117' AND primary_domain = 'escudosst.com' FROM site_settings),
                      'site_settings inicial: titular, RUC y dominio editable');
SELECT pg_temp.expect((SELECT count(*) FROM legal_documents) = 6 AND (SELECT count(*) FROM legal_documents WHERE current_version_id IS NOT NULL) = 0,
                      '6 documentos legales sin versión vigente');
SELECT pg_temp.expect((SELECT bool_and(content = 'Por redactar y validar con abogado' AND status = 'draft') FROM legal_document_versions),
                      'documentos legales sin texto inventado (borrador por redactar con abogado)');
SELECT pg_temp.expect((SELECT count(*) FROM legal_parameters WHERE key LIKE 'web.%' AND to_confirm) = 19,
                      'parámetros web.* (Libro de Reclamaciones, sitio y captación) sembrados con to_confirm');

-- Panel del Dueño de la plataforma: contenido publicado y borradores
SET ROLE escudo_platform;
INSERT INTO site_pages (id, slug, page_type, title, blocks, meta_title) VALUES
  ('00000000-0000-7000-8000-000000000a01', 'inicio', 'home', 'EscudoSST', '[{"type":"hero","title":"Cumple la Ley 29783"}]', 'EscudoSST'),
  ('00000000-0000-7000-8000-000000000a02', 'sectores/construccion', 'sector', 'Construcción', '[{"type":"text","body":"borrador"}]', NULL),
  ('00000000-0000-7000-8000-000000000a03', 'oculta', 'landing', 'Landing noindex', '[]', NULL);
UPDATE site_pages SET noindex = true WHERE id = '00000000-0000-7000-8000-000000000a03';
SELECT web_publish_page('00000000-0000-7000-8000-000000000a01');
SELECT web_publish_page('00000000-0000-7000-8000-000000000a03');
UPDATE site_pages SET blocks = '[{"type":"hero","title":"CAMBIO SIN PUBLICAR"}]' WHERE id = '00000000-0000-7000-8000-000000000a01';
SELECT pg_temp.expect_error($$UPDATE site_pages SET status = 'published' WHERE id = '00000000-0000-7000-8000-000000000a02'$$, '23514',
                            'página publicada exige copia publicada (published_snapshot)');
INSERT INTO site_faqs (topic, question, answer, status) VALUES ('plans', '¿Puedo cambiar de plan?', 'Sí.', 'published'), ('plans', 'Borrador', 'x', 'draft');
INSERT INTO site_menus (location, name, items) VALUES ('header', 'Cabecera',
  '[{"label":"Inicio","page_slug":"inicio"},{"label":"Construcción","page_slug":"sectores/construccion"},{"label":"Blog","url":"https://blog.example.org"}]');
INSERT INTO site_products (name, url, is_visible, sort_order) VALUES ('Producto visible', 'https://example.org', true, 1), ('Producto oculto', NULL, false, 2);
INSERT INTO site_testimonials (author_name, quote, publication_consent, status, approved_at) VALUES ('J. P.', 'Muy útil', true, 'approved', now());
INSERT INTO site_testimonials (author_name, quote, publication_consent, status) VALUES ('Pendiente', 'No sale', true, 'pending');
INSERT INTO site_testimonials (author_name, quote, is_example, status, approved_at) VALUES ('Ejemplo', 'Texto de demostración', true, 'approved', now());
SELECT pg_temp.expect_error($$INSERT INTO site_testimonials (author_name, quote, status, approved_at) VALUES ('Sin', 'consentimiento', 'approved', now())$$, '23514',
                            'testimonio aprobado exige consentimiento de publicación (o ser de ejemplo)');
INSERT INTO site_redirects (source_path, target_url, status_code) VALUES ('/precios', '/planes', 301);
SELECT pg_temp.expect_error($$INSERT INTO site_redirects (source_path, target_url, status_code) VALUES ('/a', '/b', 307)$$, '23514', 'redirección solo 301 o 302');
SELECT pg_temp.expect_error($$UPDATE site_settings SET primary_domain = 'no es dominio'$$, '23514', 'dominio de site_settings validado');
UPDATE site_settings SET whatsapp_number = '51999999999', whatsapp_default_message = 'Hola, quiero información de EscudoSST', interactive_demo_url = 'https://demo.example.org', blog_url = 'https://blog.example.org',
       show_latest_blog_posts = true;
RESET ROLE;

-- Lectura pública (como la API, rol escudo_app sin contexto de empresa): solo publicado
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((public_site_page(NULL) ->> 'result') = 'ok' AND (public_site_page('') -> 'blocks' -> 0 ->> 'title') = 'Cumple la Ley 29783',
                      'portada pública muestra la copia publicada, no el cambio sin publicar');
SELECT pg_temp.expect((public_site_page(NULL) ->> 'whatsapp_message') = 'Hola, quiero información de EscudoSST', 'mensaje de WhatsApp por página (general si la página no tiene)');
SELECT pg_temp.expect((public_site_page('sectores/construccion') ->> 'result') = 'not_found', 'página en borrador: no encontrada');
SELECT pg_temp.expect(NOT (public_site_page('inicio') ? 'blocks_draft') AND NOT (public_site_page('inicio') ? 'published_by'), 'página pública sin datos internos');
SELECT pg_temp.expect(jsonb_array_length(public_site_menu('header') -> 'items') = 2, 'menú público omite el ítem que apunta a una página en borrador');
SELECT pg_temp.expect(jsonb_array_length(public_site_faqs('plans') -> 'faqs') = 1, 'FAQ pública: solo publicadas');
SELECT pg_temp.expect(jsonb_array_length(public_site_products() -> 'products') = 8, 'productos públicos: solo visibles (7 sembrados + 1)');
SELECT pg_temp.expect(jsonb_array_length(public_site_testimonials() -> 'testimonials') = 2
                      AND (SELECT count(*) FROM jsonb_array_elements(public_site_testimonials() -> 'testimonials') x WHERE (x ->> 'is_example')::boolean) = 1,
                      'testimonios públicos: solo aprobados; los de ejemplo van rotulados');
SELECT pg_temp.expect((public_site_settings() ->> 'whatsapp_number') = '51999999999' AND (public_site_settings() ->> 'blog_url') = 'https://blog.example.org'
                      AND NOT (public_site_settings() ? 'complaints_officer_email'), 'configuración pública con WhatsApp y blog, sin correo del responsable');
SELECT pg_temp.expect((SELECT count(*) FROM public_sitemap() WHERE path = '/') = 1 AND (SELECT count(*) FROM public_sitemap() WHERE path IN ('/oculta', '/sectores/construccion')) = 0,
                      'sitemap: portada publicada, sin noindex ni borradores');
SELECT pg_temp.expect(public_noindex_paths() = ARRAY['/oculta'], 'rutas noindex para robots.txt');
SELECT pg_temp.expect((public_resolve_redirect('/precios') ->> 'status_code') = '301' AND (public_resolve_redirect('/nada') ->> 'result') = 'not_found', 'redirección 301 resuelta');
SELECT pg_temp.expect((SELECT bool_and(x ->> 'price' IS NULL AND x ->> 'price_label' = 'Solicitar cotización') FROM jsonb_array_elements(public_pricing() -> 'plans') x)
                      AND jsonb_array_length(public_pricing() -> 'plans') = 4, 'planes públicos sin precio publicado: «Solicitar cotización»');
SELECT pg_temp.expect(NOT EXISTS (SELECT 1 FROM jsonb_array_elements(public_pricing() -> 'comparison') x WHERE x ->> 'module_code' IN ('WEB', 'SUS'))
                      AND EXISTS (SELECT 1 FROM jsonb_array_elements(public_pricing() -> 'comparison') x WHERE x ->> 'module_code' = 'SUN' AND (x -> 'plans' ->> 'P')::boolean AND NOT (x -> 'plans' ->> 'E')::boolean),
                      'comparativa por módulo: SUN en Profesional y no en Esencial; sin módulos internos');
SELECT pg_temp.expect((public_legal_document('terms') ->> 'result') = 'not_published' AND NOT (public_legal_document('terms') ? 'content'),
                      'documento legal sin versión publicada: no expone el borrador');
COMMIT;

-- Documentos legales: publicar, no editar lo publicado, re-aceptación
SET ROLE escudo_platform;
SELECT pg_temp.expect_error($$SELECT web_publish_legal_version((SELECT v.id FROM legal_document_versions v JOIN legal_documents d ON d.id = v.legal_document_id WHERE d.doc_type = 'terms'))$$,
                            '23514', 'no se publica una versión legal sin aprobar');
UPDATE legal_document_versions v SET content = 'Texto validado por abogado (prueba)', status = 'approved', approved_by = '00000000-0000-7000-8000-0000000000f1', approved_at = now()
  FROM legal_documents d WHERE d.id = v.legal_document_id AND d.doc_type IN ('terms', 'cookies');
SELECT web_publish_legal_version(v.id) FROM legal_document_versions v JOIN legal_documents d ON d.id = v.legal_document_id WHERE d.doc_type IN ('terms', 'cookies');
SELECT pg_temp.expect_error($$UPDATE legal_document_versions SET content = 'cambio' WHERE status = 'published'$$, '42501', 'versión legal publicada no se edita');
SELECT pg_temp.expect_error($$UPDATE legal_document_versions SET status = 'draft' WHERE status = 'published'$$, '42501', 'versión legal publicada no vuelve a borrador');
RESET ROLE;
SELECT v.id AS privacy_draft_id FROM legal_document_versions v JOIN legal_documents d ON d.id = v.legal_document_id WHERE d.doc_type = 'privacy' \gset
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f1', true);
SELECT pg_temp.expect((public_legal_document('terms') ->> 'version_label') = '1.0', 'términos vigentes publicados');
SELECT pg_temp.expect((SELECT count(*) FROM app_pending_legal_acceptances() WHERE doc_type = 'terms') = 1
                      AND (SELECT count(*) FROM app_pending_legal_acceptances() WHERE doc_type = 'privacy') = 0, 'pendiente: aceptar términos (privacidad aún sin publicar)');
SELECT public_record_legal_acceptance((public_legal_document('terms') ->> 'version_id')::uuid, 'signup', '00000000-0000-7000-8000-0000000000f1',
                                      NULL, NULL, '00000000-0000-7000-8000-00000000000a', NULL, '\x01', 'test', 'acc-1');
SELECT pg_temp.expect((public_record_legal_acceptance((public_legal_document('terms') ->> 'version_id')::uuid, 'signup', '00000000-0000-7000-8000-0000000000f1',
                       NULL, NULL, NULL, NULL, NULL, NULL, 'acc-1') ->> 'replay')::boolean, 'aceptación idempotente');
SELECT pg_temp.expect((SELECT count(*) FROM app_pending_legal_acceptances()) = 0, 'sin pendientes tras aceptar');
SELECT pg_temp.expect_error(format($$SELECT public_record_legal_acceptance(%L::uuid, 'signup', '00000000-0000-7000-8000-0000000000f1')$$, :'privacy_draft_id'),
                            '23514', 'no se acepta una versión en borrador');
SELECT pg_temp.expect((public_record_cookie_consent('00000000-0000-7000-8000-00000000c001', '{"analytics": true, "necessary": false}', 'custom') -> 'categories' ->> 'necessary') = 'true',
                      'cookies: las necesarias siempre activas');
SELECT pg_temp.expect((public_record_cookie_consent('00000000-0000-7000-8000-00000000c001', '{"analytics": true}', 'reject_all') -> 'categories' ->> 'analytics') = 'false'
                      AND (public_record_cookie_consent('00000000-0000-7000-8000-00000000c002', '{}', 'accept_all') ->> 'policy_version') = '1.0',
                      'cookies: rechazar todo apaga analítica; se registra la versión de la política');
COMMIT;
SET ROLE escudo_platform;
INSERT INTO legal_document_versions (legal_document_id, version_number, version_label, content, status, requires_reacceptance, approved_by, approved_at)
SELECT id, 2, '2.0', 'Texto v2 validado (prueba)', 'approved', true, '00000000-0000-7000-8000-0000000000f1', now() FROM legal_documents WHERE doc_type = 'terms';
SELECT pg_temp.expect((web_publish_legal_version((SELECT v.id FROM legal_document_versions v JOIN legal_documents d ON d.id = v.legal_document_id
                        WHERE d.doc_type = 'terms' AND v.version_number = 2)) ->> 'requires_reacceptance')::boolean, 'publicar v2 de términos dispara re-aceptación');
SELECT pg_temp.expect((SELECT status FROM legal_document_versions v JOIN legal_documents d ON d.id = v.legal_document_id WHERE d.doc_type = 'terms' AND v.version_number = 1) = 'superseded',
                      'la versión anterior queda reemplazada (superseded)');
SELECT pg_temp.expect_error($$UPDATE legal_acceptances SET context = 'checkout'$$, '42501', 'legal_acceptances rechaza UPDATE');
SELECT pg_temp.expect_error($$DELETE FROM legal_acceptances$$, '42501', 'legal_acceptances rechaza DELETE');
SELECT pg_temp.expect_error($$UPDATE cookie_consents SET action = 'withdraw'$$, '42501', 'cookie_consents rechaza UPDATE');
SELECT pg_temp.expect_error($$DELETE FROM cookie_consents$$, '42501', 'cookie_consents rechaza DELETE');
RESET ROLE;
SELECT pg_temp.expect_error($$UPDATE legal_acceptances SET context = 'checkout'$$, '42501', 'legal_acceptances rechaza UPDATE incluso al superusuario');
SELECT pg_temp.expect_error($$TRUNCATE cookie_consents$$, '42501', 'cookie_consents rechaza TRUNCATE incluso al superusuario');
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f1', true);
SELECT pg_temp.expect((SELECT is_reacceptance FROM app_pending_legal_acceptances() WHERE doc_type = 'terms'), 'el usuario debe re-aceptar los términos v2');
COMMIT;

-- Libro de Reclamaciones
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((public_submit_complaint('{"consumer_full_name":"cifrado:nombre","consumer_document_type":"DNI","consumer_document_number":"cifrado:doc",
   "consumer_email":"cifrado:correo","item_type":"service","item_description":"Suscripción plan Profesional","amount_claimed":"100.00",
   "complaint_type":"claim","detail":"Detalle","consumer_request":"Pedido"}', 'clave-seguimiento-0001', '\x01', 'lr-1') ->> 'complaint_code') ~ '^LR-[0-9]{4}-000001$',
   'hoja registrada con código correlativo anual');
SELECT pg_temp.expect((public_submit_complaint('{}', 'otra-clave-cualquiera-1', NULL, 'lr-1') ->> 'replay')::boolean, 'registro de hoja idempotente');
SELECT pg_temp.expect((public_submit_complaint('{"consumer_full_name":"c:n","consumer_document_type":"DNI","consumer_document_number":"c:d","consumer_email":"c:e",
   "item_type":"service","item_description":"Soporte","complaint_type":"complaint","detail":"Atención","consumer_request":"Mejorar"}', 'clave-seguimiento-0002', NULL, 'lr-2') ->> 'complaint_code') ~ '-000002$',
   'segunda hoja: correlativo 2');
SELECT pg_temp.expect_error($$SELECT public_submit_complaint('{"consumer_full_name":"c","consumer_document_type":"DNI","consumer_document_number":"c","consumer_email":"c",
   "is_minor":true,"item_type":"service","item_description":"x","complaint_type":"claim","detail":"x","consumer_request":"x"}', 'clave-seguimiento-0003')$$, '23514',
   'menor de edad exige datos del apoderado');
SELECT pg_temp.expect((public_complaint_status((SELECT 'LR-' || extract(year FROM now() AT TIME ZONE 'America/Lima') || '-000001'), 'clave-seguimiento-0001') ->> 'status') = 'received'
                      AND NOT (public_complaint_status((SELECT 'LR-' || extract(year FROM now() AT TIME ZONE 'America/Lima') || '-000001'), 'clave-seguimiento-0001')
                               ?| ARRAY['consumer_full_name', 'consumer_email', 'consumer_document_number', 'detail', 'response_text']),
                      'consulta de estado por código y clave, sin datos personales');
SELECT pg_temp.expect((public_complaint_status((SELECT 'LR-' || extract(year FROM now() AT TIME ZONE 'America/Lima') || '-000001'), 'clave-equivocada-xx') ->> 'result') = 'invalid',
                      'clave de seguimiento equivocada: no válida');
SELECT pg_temp.expect_error($$SELECT public_create_demo_request('{"full_name":"Ana","company_name":"Gamma","email":"ana@gamma.pe","privacy_consent":false}')$$, '23514',
                            'demo sin consentimiento de privacidad rechazada');
SELECT pg_temp.expect(NOT (public_create_demo_request('{"full_name":"Ana","company_name":"Gamma","ruc":"20300000003","email":"Ana@Gamma.pe","headcount":45,
   "economic_sector":"manufacturing","privacy_consent":true,"utm":{"utm_source":"google"}}', NULL, 'demo-1') ->> 'replay')::boolean
   AND (public_create_demo_request('{}', NULL, 'demo-1') ->> 'replay')::boolean, 'solicitud de demo creada e idempotente');
COMMIT;
SET ROLE escudo_platform;
SELECT pg_temp.expect((SELECT response_due_on = add_business_days((filed_at AT TIME ZONE 'America/Lima')::date, 15) AND response_business_days = 15
                              AND retain_until = ((filed_at AT TIME ZONE 'America/Lima')::date + interval '5 years')::date AND provider_ruc = '20607431117'
                         FROM consumer_complaints WHERE sequence_number = 1), 'fecha límite (15 días hábiles), conservación (5 años) y proveedor copiado');
SELECT pg_temp.expect(add_business_days('2026-07-24', 3) = '2026-07-31', 'días hábiles saltan fin de semana y feriados (28 y 29 de julio)');
SELECT pg_temp.expect_error($$UPDATE consumer_complaints SET consumer_full_name = 'otro' WHERE sequence_number = 1$$, '42501', 'los datos de la hoja no se modifican');
SELECT pg_temp.expect_error($$DELETE FROM consumer_complaints$$, '42501', 'consumer_complaints no se borra (panel)');
SELECT pg_temp.expect_error($$SELECT web_complaint_action(id, 'extend', NULL, response_due_on + 5) FROM consumer_complaints WHERE sequence_number = 1$$, '23514', 'prórroga exige motivo');
SELECT pg_temp.expect_error($$SELECT web_complaint_action(id, 'extend', 'motivo', response_due_on + 90) FROM consumer_complaints WHERE sequence_number = 1$$, '23514', 'prórroga no excede el máximo del parámetro');
SELECT web_complaint_action(id, 'acknowledge') FROM consumer_complaints WHERE sequence_number = 1;
SELECT web_complaint_action(id, 'start') FROM consumer_complaints WHERE sequence_number = 1;
SELECT web_complaint_action(id, 'extend', 'Se requiere información del área de facturación', add_business_days(response_due_on, 5)) FROM consumer_complaints WHERE sequence_number = 1;
SELECT web_complaint_action(id, 'answer', 'Respuesta al consumidor', NULL, 'email') FROM consumer_complaints WHERE sequence_number = 1;
SELECT web_complaint_action(id, 'close') FROM consumer_complaints WHERE sequence_number = 1;
SELECT pg_temp.expect_error($$SELECT web_complaint_action(id, 'close') FROM consumer_complaints WHERE sequence_number = 2$$, '23514', 'transición inválida: recibido → cerrado');
SELECT pg_temp.expect_error($$UPDATE consumer_complaints SET assigned_to = NULL WHERE sequence_number = 1$$, '42501', 'hoja cerrada no cambia');
SELECT pg_temp.expect((SELECT array_agg(event_type::text ORDER BY occurred_at, event_type) FROM complaint_events e JOIN consumer_complaints c ON c.id = e.consumer_complaint_id WHERE c.sequence_number = 1)
                      @> ARRAY['registered', 'acknowledgment_sent', 'in_progress', 'extended', 'answered', 'closed'], 'historial completo de la hoja');
SELECT pg_temp.expect_error($$UPDATE complaint_events SET reason = 'x'$$, '42501', 'complaint_events rechaza UPDATE');
SELECT pg_temp.expect_error($$DELETE FROM complaint_events$$, '42501', 'complaint_events rechaza DELETE');
RESET ROLE;
SELECT pg_temp.expect_error($$DELETE FROM consumer_complaints$$, '42501', 'consumer_complaints no se borra incluso como superusuario');
SELECT pg_temp.expect_error($$TRUNCATE consumer_complaints CASCADE$$, '42501', 'consumer_complaints rechaza TRUNCATE');
SELECT pg_temp.expect_error($$DELETE FROM complaint_events$$, '42501', 'complaint_events rechaza DELETE incluso como superusuario');

-- -------------------------------------------------------------------------------------
-- 4c. WEB · herramientas de captación (spec/brief-web-extras.md): 20 tablas de plataforma
-- -------------------------------------------------------------------------------------
RESET ROLE;
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
SELECT pg_temp.expect_error(format('SELECT * FROM %I', t), '42501', 'WEB captación: la app de empresa no lee ' || t)
  FROM unnest(ARRAY['marketing_leads', 'fine_calculations', 'assessment_questionnaires', 'assessment_questions', 'assessment_submissions',
                    'resource_items', 'resource_downloads', 'success_stories', 'help_categories', 'help_articles', 'help_article_feedback',
                    'partner_applications', 'referral_codes', 'referrals', 'newsletter_subscribers', 'newsletter_issues', 'subprocessors',
                    'status_components', 'status_incidents', 'status_incident_updates']) t;
SELECT pg_temp.expect_error($$INSERT INTO marketing_leads (email, source) VALUES ('x@y.pe', 'contact')$$, '42501', 'WEB captación: la app no inserta leads directo');
SELECT pg_temp.expect_error($$UPDATE status_components SET current_status = 'major_outage'$$, '42501', 'WEB captación: la app no cambia el estado del servicio');
SELECT pg_temp.expect_error($$SELECT web_upsert_lead('{"email":"a@b.pe","privacy_consent":true}', 'contact')$$, '42501', 'WEB captación: la app no ejecuta el ayudante de leads');
SELECT pg_temp.expect_error($$SELECT web_review_partner(uuidv7(), true)$$, '42501', 'WEB captación: la app no aprueba partners');
SELECT pg_temp.expect_error($$SELECT web_send_newsletter_issue(uuidv7())$$, '42501', 'WEB captación: la app no envía boletines');
COMMIT;

-- Semillas de captación
SELECT pg_temp.expect((SELECT count(*) FROM site_products WHERE description_editable AND url IS NULL AND is_visible
                        AND name IN ('Plannio', 'GestionaHub', 'Edunova', 'Yachay Campus', 'Qhatu POS', 'Ayni HR', 'TaskFlow')) = 7,
                      '7 otros productos con «descripción editable» y sin URL inventada');
SELECT pg_temp.expect((SELECT string_agg(code, ',' ORDER BY sort_order) FROM status_components WHERE current_status = 'operational') = 'app,api,medical_db,email,whatsapp,payments',
                      'componentes de estado iniciales operativos');
SELECT pg_temp.expect((SELECT status = 'draft' AND to_validate FROM assessment_questionnaires WHERE version = 1)
                      AND (SELECT count(*) FROM assessment_questions) >= 5, 'autodiagnóstico en borrador, a validar, con preguntas de ejemplo');
SELECT pg_temp.expect((SELECT count(*) FROM subprocessors WHERE to_validate AND name IN ('Contabo', 'DigitalOcean', 'Backblaze', 'Elastic Email')) = 4,
                      'subencargados iniciales a validar');
SELECT pg_temp.expect((SELECT count(*) FROM legal_parameters WHERE key IN ('web.uit_value', 'web.fine_table_micro', 'web.fine_table_small', 'web.fine_table_non_mype',
                        'web.fine_correction_discount_pct', 'web.lead_retention_months') AND to_confirm AND company_id IS NULL) = 6,
                      'parámetros legales de captación sembrados con to_confirm');
SELECT pg_temp.expect((SELECT count(*) FROM permissions WHERE code IN ('web.marketing_leads.assign', 'web.marketing_leads.export', 'web.assessment_questionnaires.publish',
                        'web.success_stories.approve', 'web.help_articles.publish', 'web.partner_applications.approve', 'web.newsletter_issues.send',
                        'web.status_incidents.publish')) = 8, '8 permisos especiales de captación');
SELECT pg_temp.expect(NOT EXISTS (SELECT 1 FROM permissions p WHERE p.module_code = 'WEB' AND NOT EXISTS (
                        SELECT 1 FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE rp.permission_id = p.id AND r.code = 'platform_owner')),
                      'platform_owner tiene todos los permisos web.*');
SELECT pg_temp.expect((SELECT array_agg(p.code ORDER BY p.code) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                        WHERE r.code = 'platform_support' AND p.resource = 'marketing_leads') = ARRAY['web.marketing_leads.assign', 'web.marketing_leads.read', 'web.marketing_leads.update'],
                      'platform_support lee, atiende y asigna leads, pero no los exporta ni borra');

-- Panel: contenido publicado y borradores de cada herramienta
BEGIN;
SET LOCAL ROLE escudo_platform;
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f1', true);
INSERT INTO resource_items (slug, title, resource_type, file_id, is_gated, status, published_at) VALUES
  ('matriz-iperc', 'Matriz IPERC', 'template', '00000000-0000-7000-8000-00000000f101', true, 'published', now()),
  ('registro-accidentes', 'Registro de accidentes', 'format', '00000000-0000-7000-8000-00000000f102', false, 'published', now()),
  ('recurso-borrador', 'Borrador', 'guide', NULL, false, 'draft', NULL);
SELECT pg_temp.expect_error($$INSERT INTO resource_items (slug, title, resource_type, status, published_at) VALUES ('sin-archivo', 'X', 'guide', 'published', now())$$,
                            '23514', 'recurso publicado exige archivo');
INSERT INTO success_stories (id, slug, client_name, is_example) VALUES
  ('00000000-0000-7000-8000-00000000f201', 'caso-ejemplo', 'Constructora Ejemplo', true),
  ('00000000-0000-7000-8000-00000000f202', 'caso-sin-consentimiento', 'Cliente Real', false),
  ('00000000-0000-7000-8000-00000000f203', 'caso-aprobado', 'Cliente Aprobado', false);
UPDATE success_stories SET consent_file_id = '00000000-0000-7000-8000-00000000f204' WHERE slug = 'caso-aprobado';
SELECT web_success_story_action('00000000-0000-7000-8000-00000000f201', 'approve');
SELECT web_success_story_action('00000000-0000-7000-8000-00000000f201', 'publish');
SELECT web_success_story_action('00000000-0000-7000-8000-00000000f203', 'approve');
SELECT pg_temp.expect_error($$SELECT web_success_story_action('00000000-0000-7000-8000-00000000f202', 'approve')$$, '23514', 'caso real sin consentimiento escrito no se aprueba');
SELECT pg_temp.expect_error($$SELECT web_success_story_action('00000000-0000-7000-8000-00000000f202', 'publish')$$, '23514', 'caso sin aprobar no se publica');
INSERT INTO help_categories (id, slug, name, status) VALUES
  ('00000000-0000-7000-8000-00000000f301', 'primeros-pasos', 'Primeros pasos', 'active'),
  ('00000000-0000-7000-8000-00000000f302', 'oculta', 'Oculta', 'hidden');
INSERT INTO help_articles (id, category_id, slug, title, body, screen_code, related_article_ids) VALUES
  ('00000000-0000-7000-8000-00000000f311', '00000000-0000-7000-8000-00000000f301', 'crear-matriz-iperc', 'Cómo crear la matriz IPERC', 'Paso a paso para identificar peligros y evaluar riesgos.', 'iperc.matrix',
   ARRAY['00000000-0000-7000-8000-00000000f312', '00000000-0000-7000-8000-00000000f313']::uuid[]),
  ('00000000-0000-7000-8000-00000000f312', '00000000-0000-7000-8000-00000000f301', 'ayuda-borrador', 'Borrador de ayuda', 'No publicado.', NULL, '{}'),
  ('00000000-0000-7000-8000-00000000f313', '00000000-0000-7000-8000-00000000f301', 'invitar-usuarios', 'Invitar usuarios', 'Cómo invitar a tu equipo.', 'users.list', '{}'),
  ('00000000-0000-7000-8000-00000000f314', '00000000-0000-7000-8000-00000000f302', 'en-categoria-oculta', 'En categoría oculta', 'Texto.', NULL, '{}');
SELECT web_publish_help_article(id) FROM help_articles WHERE slug IN ('crear-matriz-iperc', 'invitar-usuarios', 'en-categoria-oculta');
INSERT INTO subprocessors (name, purpose, country, data_categories, status) VALUES ('Proveedor inactivo', 'Prueba', 'PE', 'Ninguno', 'inactive');
INSERT INTO status_incidents (id, title, impact, component_ids_json) VALUES
  ('00000000-0000-7000-8000-00000000f401', 'Lentitud en el envío de correos', 'minor', (SELECT jsonb_build_array(id::text) FROM status_components WHERE code = 'email')),
  ('00000000-0000-7000-8000-00000000f402', 'Borrador interno', 'major', '[]');
SELECT web_publish_incident('00000000-0000-7000-8000-00000000f401', 'Estamos investigando demoras en los correos.');
SELECT web_add_incident_update('00000000-0000-7000-8000-00000000f401', 'identified', 'El proveedor de correo confirmó la incidencia.');
UPDATE status_components SET current_status = 'degraded' WHERE code = 'email';
-- Autodiagnóstico: editable en borrador; al publicarse queda fijo
UPDATE assessment_questions SET weight = 1 WHERE sort_order = 1;
SELECT pg_temp.expect((web_publish_questionnaire((SELECT id FROM assessment_questionnaires WHERE version = 1)) ->> 'status') = 'published', 'cuestionario publicado');
SELECT pg_temp.expect_error($$UPDATE assessment_questions SET weight = 5 WHERE sort_order = 1$$, '42501', 'preguntas de un cuestionario publicado no se editan');
SELECT pg_temp.expect_error($$INSERT INTO assessment_questions (questionnaire_id, section, question_text) SELECT id, 'X', 'Nueva' FROM assessment_questionnaires WHERE version = 1$$,
                            '42501', 'no se agregan preguntas a un cuestionario publicado');
INSERT INTO assessment_questionnaires (title, version) VALUES ('Autodiagnóstico v2 sin preguntas', 2);
SELECT pg_temp.expect_error($$SELECT web_publish_questionnaire((SELECT id FROM assessment_questionnaires WHERE version = 2))$$, '23514', 'cuestionario sin preguntas no se publica');
COMMIT;

-- Público (lo que llama la API sin sesión): solo lo publicado
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect(jsonb_array_length(public_resources() -> 'resources') = 2
                      AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(public_resources() -> 'resources') x WHERE x ? 'file_id' OR x ->> 'slug' = 'recurso-borrador'),
                      'recursos públicos: solo publicados y sin file_id');
SELECT pg_temp.expect((public_download_resource('recurso-borrador') ->> 'result') = 'not_found', 'recurso en borrador no se descarga');
SELECT pg_temp.expect((public_download_resource('matriz-iperc') ->> 'result') = 'lead_required', 'recurso gated exige datos');
SELECT pg_temp.expect((public_download_resource('registro-accidentes', '{}', '\x01', 'dl-1') ->> 'file_id') = '00000000-0000-7000-8000-00000000f102'
                      AND (public_download_resource('registro-accidentes', '{}', '\x01', 'dl-1') ->> 'replay')::boolean, 'recurso libre: descarga registrada e idempotente');
SELECT pg_temp.expect_error($$SELECT public_download_resource('matriz-iperc', '{"email":"Luis@Delta.pe"}')$$, '23514', 'recurso gated: sin consentimiento de privacidad no hay lead');
SELECT pg_temp.expect((public_download_resource('matriz-iperc', '{"email":"Luis@Delta.pe","privacy_consent":true,"full_name":"Luis","utm":{"utm_source":"linkedin"}}', NULL, 'dl-2') ->> 'result') = 'ok',
                      'recurso gated: descarga con lead');
SELECT pg_temp.expect(jsonb_array_length(public_success_stories() -> 'stories') = 1
                      AND (public_success_stories() -> 'stories' -> 0 ->> 'is_example')::boolean
                      AND NOT (public_success_stories() -> 'stories' -> 0 ? 'consent_file_id'), 'casos: solo publicados (ejemplo rotulado), sin archivo de consentimiento');
SELECT pg_temp.expect(jsonb_array_length(public_help_categories() -> 'categories') = 1, 'ayuda: solo categorías activas');
SELECT pg_temp.expect((public_help_article('crear-matriz-iperc') -> 'related') = '[{"slug": "invitar-usuarios", "title": "Invitar usuarios"}]'::jsonb,
                      'ayuda: artículo publicado con relacionados publicados (sin borradores)');
SELECT pg_temp.expect((public_help_article('ayuda-borrador') ->> 'result') = 'not_found' AND (public_help_article('en-categoria-oculta') ->> 'result') = 'not_found',
                      'ayuda: borrador o categoría oculta no salen');
SELECT pg_temp.expect(jsonb_array_length(public_help_articles(NULL, 'peligros') -> 'articles') = 1
                      AND jsonb_array_length(public_help_articles(NULL, NULL, 'users.list') -> 'articles') = 1
                      AND jsonb_array_length(public_help_articles('primeros-pasos') -> 'articles') = 2, 'ayuda: búsqueda, ayuda contextual por pantalla y categoría');
SELECT pg_temp.expect((public_help_feedback('crear-matriz-iperc', true, 'Muy claro', NULL, NULL, '\x01', 'fb-1') ->> 'result') = 'ok'
                      AND (public_help_feedback('crear-matriz-iperc', true, NULL, NULL, NULL, NULL, 'fb-1') ->> 'replay')::boolean
                      AND (public_help_feedback('ayuda-borrador', false) ->> 'result') = 'not_found', '«¿Te sirvió?» idempotente y solo en publicados');
SELECT pg_temp.expect(jsonb_array_length(public_subprocessors() -> 'subprocessors') = 4, 'subencargados: solo activos');
SELECT pg_temp.expect((public_service_status() ->> 'overall_status') = 'degraded' AND jsonb_array_length(public_service_status() -> 'components') = 6
                      AND jsonb_array_length(public_service_status() -> 'incidents') = 1
                      AND jsonb_array_length(public_service_status() -> 'incidents' -> 0 -> 'updates') = 2
                      AND (public_service_status() -> 'incidents' -> 0 -> 'components') = '["email"]'::jsonb,
                      'estado: componentes, estado general e incidentes publicados (sin borradores) con actualizaciones');
-- Autodiagnóstico
SELECT pg_temp.expect((public_self_assessment() ->> 'result') = 'ok' AND (public_self_assessment() ->> 'to_validate')::boolean
                      AND NOT (public_self_assessment() -> 'sections' -> 0 -> 'questions' -> 0 ? 'weight'), 'autodiagnóstico publicado, rotulado «a validar»');
SELECT pg_temp.expect_error($$SELECT public_submit_self_assessment('{"answers":{}}')$$, '23502', 'autodiagnóstico exige correo para el informe');
SELECT pg_temp.expect_error($$SELECT public_submit_self_assessment('{"email":"e@f.pe","privacy_consent":true,"answers":{"00000000-0000-7000-8000-000000000000":"yes"}}')$$,
                            '23514', 'autodiagnóstico: pregunta ajena rechazada');
SELECT pg_temp.expect_error(format($$SELECT public_submit_self_assessment('{"email":"e@f.pe","privacy_consent":true,"answers":{"%s":"quizas"}}')$$,
                            (SELECT id FROM (SELECT (public_self_assessment() -> 'sections' -> 0 -> 'questions' -> 0 ->> 'id') AS id) z)), '23514', 'autodiagnóstico: respuesta inválida rechazada');
COMMIT;
RESET ROLE;
SELECT jsonb_object_agg(id::text, CASE WHEN sort_order <= 5 THEN 'yes' WHEN sort_order <= 7 THEN 'partial' ELSE 'no' END)::text AS answers9
  FROM assessment_questions WHERE questionnaire_id = (SELECT id FROM assessment_questionnaires WHERE version = 1) \gset
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((SELECT (r ->> 'score_total')::numeric = round(100 * 6.0 / (SELECT count(*) FROM jsonb_object_keys(:'answers9'::jsonb)), 2)
                              AND r ->> 'level' = 'medium' AND jsonb_typeof(r -> 'score_by_section') = 'object'
                         FROM (SELECT public_submit_self_assessment(jsonb_build_object('email', 'eva@epsilon.pe', 'privacy_consent', true, 'answers', :'answers9'::jsonb),
                                                                    NULL, 'sa-1') AS r) z), 'autodiagnóstico: puntaje (sí 100 %, parcial 50 %), por sección y nivel');
SELECT pg_temp.expect((public_submit_self_assessment('{}', NULL, 'sa-1') ->> 'replay')::boolean, 'autodiagnóstico idempotente');
-- Calculadora: sin tablas cargadas no inventa cifras
SELECT pg_temp.expect(NOT (public_fine_calculator_parameters() ->> 'available')::boolean AND (public_fine_calculator_parameters() ->> 'uit_value')::numeric = 5500
                      AND (public_fine_calculator_parameters() ->> 'to_confirm')::boolean, 'calculadora: UIT referencial a confirmar y tablas vacías (no disponible)');
SELECT pg_temp.expect((public_calculate_fine('{"company_size":"micro","affected_workers":3,"severity":"serious"}') ->> 'result') = 'parameters_pending',
                      'calculadora: sin tabla cargada responde «parámetros pendientes»');
SELECT pg_temp.expect_error($$SELECT public_calculate_fine('{"company_size":"micro","affected_workers":0,"severity":"serious"}')$$, '23514', 'calculadora: datos inválidos rechazados');
-- Partners y boletín
SELECT pg_temp.expect_error($$SELECT public_apply_partner('{"email":"socio@consult.pe","partner_type":"consultancy"}')$$, '23514', 'partner: exige consentimiento de privacidad');
SELECT pg_temp.expect(NOT (public_apply_partner('{"email":"Socio@Consult.pe","privacy_consent":true,"marketing_consent":true,"partner_type":"consultancy","company_name":"Consult SAC","ruc":"20400000004"}', NULL, 'pa-1') ->> 'replay')::boolean
                      AND (public_apply_partner('{"email":"socio@consult.pe","privacy_consent":true,"partner_type":"consultancy"}', NULL, 'pa-2') ->> 'replay')::boolean,
                      'partner: solicitud pendiente única por lead');
SELECT pg_temp.expect((public_newsletter_subscribe('{"email":"Socio@Consult.pe","privacy_consent":true,"topics":["regulations","sunafil"]}',
                       'token-confirmacion-0123456789abcdef0123', 'token-baja-0123456789abcdef0123456789') ->> 'result') = 'accepted', 'boletín: suscripción pendiente de confirmar');
SELECT pg_temp.expect_error($$SELECT public_newsletter_subscribe('{"email":"z@z.pe","privacy_consent":true,"topics":["deportes"]}', repeat('a', 40), repeat('b', 40))$$, '23514', 'boletín: tema inválido');
SELECT pg_temp.expect_error($$SELECT public_newsletter_subscribe('{"email":"z@z.pe","privacy_consent":true}', 'corto', 'corto')$$, '23514', 'boletín: token demasiado corto');
SELECT pg_temp.expect((public_newsletter_confirm('token-equivocado-0123456789abcdef0123') ->> 'result') = 'invalid', 'boletín: confirmación con token equivocado no válida');
SELECT pg_temp.expect((public_newsletter_unsubscribe('token-confirmacion-0123456789abcdef0123') ->> 'result') = 'invalid', 'boletín: el token de confirmación no sirve para la baja');
SELECT pg_temp.expect((public_newsletter_confirm('token-confirmacion-0123456789abcdef0123') ->> 'result') = 'confirmed'
                      AND (public_newsletter_confirm('token-confirmacion-0123456789abcdef0123') ->> 'result') = 'invalid', 'boletín: doble confirmación de un solo uso');
SELECT pg_temp.expect((public_newsletter_subscribe('{"email":"socio@consult.pe","privacy_consent":true,"topics":["sectors"]}', repeat('c', 40), repeat('d', 40)) ->> 'result') = 'accepted',
                      'boletín: correo ya suscrito responde igual (sin revelarlo)');
SELECT pg_temp.expect((public_status_subscribe('socio@consult.pe', repeat('e', 40), repeat('f', 40), true) ->> 'result') = 'accepted', 'estado: suscripción a avisos');
COMMIT;
SELECT pg_temp.expect((SELECT status = 'confirmed' AND confirm_token_hash IS NULL AND unsubscribe_token_hash = sha256(convert_to('token-baja-0123456789abcdef0123456789', 'UTF8'))
                              AND topics_json @> '["regulations", "sunafil", "sectors", "status"]'
                         FROM newsletter_subscribers WHERE email = 'socio@consult.pe'), 'boletín: en la base solo hashes; temas acumulados');
SELECT pg_temp.expect((SELECT count(*) FROM marketing_leads WHERE email = 'socio@consult.pe') = 1
                      AND (SELECT source = 'partner' AND last_source = 'newsletter' AND marketing_consent AND marketing_consent_at IS NOT NULL
                                  AND retain_until IS NOT NULL FROM marketing_leads WHERE email = 'socio@consult.pe'),
                      'lead único por correo: origen fijo, último origen, consentimiento comercial registrado y conservación por parámetro');
SELECT pg_temp.expect((SELECT NOT marketing_consent AND marketing_consent_at IS NULL AND utm_source = 'linkedin' FROM marketing_leads WHERE email = 'luis@delta.pe'),
                      'lead sin consentimiento comercial: no se presume');
SELECT pg_temp.expect((SELECT source = 'demo' AND demo_request_id IS NOT NULL FROM marketing_leads WHERE email = 'ana@gamma.pe'), 'la solicitud de demo entra a la bandeja de leads');
SELECT pg_temp.expect((SELECT download_count FROM resource_items WHERE slug = 'registro-accidentes') = 1 AND (SELECT count(*) FROM resource_downloads) = 2,
                      'descargas contadas una vez por clave');
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((public_newsletter_unsubscribe('token-baja-0123456789abcdef0123456789') ->> 'result') = 'unsubscribed'
                      AND (public_newsletter_unsubscribe('token-baja-0123456789abcdef0123456789') ->> 'result') = 'unsubscribed'
                      AND (public_newsletter_unsubscribe(repeat('x', 40)) ->> 'result') = 'invalid', 'boletín: baja con un clic solo con el hash de su token (idempotente)');
COMMIT;
SELECT pg_temp.expect((SELECT status = 'unsubscribed' AND unsubscribed_at IS NOT NULL FROM newsletter_subscribers WHERE email = 'socio@consult.pe'), 'boletín: baja registrada');

-- Calculadora con una tabla cargada (prueba en transacción revertida): rango en UIT y soles y copia de parámetros
BEGIN;
INSERT INTO legal_parameters (company_id, key, value, value_type, unit, description, valid_from, to_confirm)
VALUES (NULL, 'web.fine_table_micro', '{"unit":"UIT","rows":[{"workers_from":1,"workers_to":5,"serious":{"min":0.1,"max":0.5}},{"workers_from":6,"workers_to":null,"serious":{"min":0.5,"max":1}}]}',
        'json', 'UIT', 'Tabla de prueba', current_date, true);
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((SELECT r ->> 'result' = 'ok' AND (r ->> 'result_min_pen')::numeric = 550 AND (r ->> 'result_max_pen')::numeric = 2750
                              AND (r ->> 'to_confirm')::boolean AND (r ->> 'lead_registered')::boolean
                         FROM (SELECT public_calculate_fine('{"company_size":"micro","affected_workers":3,"severity":"serious","email":"rosa@zeta.pe","privacy_consent":true}', NULL, 'fc-1') AS r) z),
                      'calculadora: rango referencial en UIT y soles con la tabla vigente');
SELECT pg_temp.expect((public_calculate_fine('{"company_size":"micro","affected_workers":3,"severity":"serious"}', NULL, 'fc-1') ->> 'replay')::boolean, 'calculadora idempotente');
SELECT pg_temp.expect((public_calculate_fine('{"company_size":"micro","affected_workers":40,"severity":"serious"}') ->> 'result_max_uit')::numeric = 1, 'calculadora: tramo abierto');
RESET ROLE;
SELECT pg_temp.expect((SELECT (parameters_snapshot_json -> 'table' ->> 'valid_from')::date = current_date AND (parameters_snapshot_json -> 'uit' ->> 'value') = '5500'
                              AND (parameters_snapshot_json -> 'table' ->> 'to_confirm')::boolean AND uit_value = 5500 AND lead_id IS NOT NULL
                         FROM fine_calculations WHERE idempotency_key = 'fc-1'), 'calculadora: guarda copia de la UIT y la tabla vigentes con to_confirm');
ROLLBACK;

-- Panel: partners, referidos, boletín e inalterables
BEGIN;
SET LOCAL ROLE escudo_platform;
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f1', true);
SELECT pg_temp.expect_error($$INSERT INTO referral_codes (partner_application_id, code) SELECT id, 'PEND-0001' FROM partner_applications LIMIT 1$$, '23514',
                            'código de referido exige partner aprobado');
SELECT pg_temp.expect((web_review_partner((SELECT id FROM partner_applications LIMIT 1), true, 'acme-2026') ->> 'referral_code') = 'ACME-2026', 'partner aprobado con código de referido');
SELECT pg_temp.expect_error($$SELECT web_review_partner((SELECT id FROM partner_applications LIMIT 1), false, NULL, 'x')$$, '23514', 'partner ya revisado no se revisa de nuevo');
INSERT INTO marketing_leads (email, source, privacy_consent) VALUES ('otro@socio.pe', 'partner', true);
INSERT INTO partner_applications (lead_id, partner_type) SELECT id, 'independent' FROM marketing_leads WHERE email = 'otro@socio.pe';
SELECT pg_temp.expect_error($$SELECT web_review_partner((SELECT a.id FROM partner_applications a JOIN marketing_leads l ON l.id = a.lead_id WHERE l.email = 'otro@socio.pe'), true, 'Acme-2026')$$,
                            '23505', 'código de referido único');
SELECT pg_temp.expect_error($$SELECT web_review_partner((SELECT a.id FROM partner_applications a JOIN marketing_leads l ON l.id = a.lead_id WHERE l.email = 'otro@socio.pe'), false)$$,
                            '23514', 'rechazo de partner exige motivo');
SELECT pg_temp.expect((web_register_referral('acme-2026', (SELECT id FROM marketing_leads WHERE email = 'luis@delta.pe')) ->> 'result') = 'ok'
                      AND (web_register_referral('ACME-2026', (SELECT id FROM marketing_leads WHERE email = 'luis@delta.pe')) ->> 'result') = 'already_referred'
                      AND (web_register_referral('NO-EXISTE', NULL, NULL) ->> 'result') = 'invalid_code', 'referido registrado una sola vez por lead');
SELECT pg_temp.expect_error($$UPDATE referrals SET status = 'customer'$$, '23514', 'referido cliente exige cuenta y fecha de conversión');
SELECT pg_temp.expect_error($$INSERT INTO marketing_leads (email, source, privacy_consent) VALUES ('LUIS@delta.pe', 'contact', true)$$, '23514', 'correo del lead en minúsculas');
SELECT pg_temp.expect_error($$INSERT INTO marketing_leads (email, source, privacy_consent) VALUES ('luis@delta.pe', 'contact', true)$$, '23505', 'un lead por correo');
SELECT pg_temp.expect_error($$INSERT INTO newsletter_subscribers (email, unsubscribe_token_hash) VALUES ('socio@consult.pe', sha256('x'))$$, '23505', 'suscriptor único por correo');
SELECT pg_temp.expect_error($$INSERT INTO resource_items (slug, title, resource_type) VALUES ('matriz-iperc', 'Duplicado', 'template')$$, '23505', 'slug de recurso único');
INSERT INTO newsletter_subscribers (email, topics_json, status, confirmed_at, unsubscribe_token_hash) VALUES
  ('lector@uno.pe', '["regulations"]', 'confirmed', now(), sha256('u1')), ('lector@dos.pe', '["sectors"]', 'confirmed', now(), sha256('u2'));
INSERT INTO newsletter_issues (id, subject, slug, body, topics_json) VALUES ('00000000-0000-7000-8000-00000000f501', 'Novedades normativas', 'novedades-noviembre', 'Contenido', '["regulations"]');
SELECT pg_temp.expect((web_send_newsletter_issue('00000000-0000-7000-8000-00000000f501') ->> 'recipients_count')::integer = 1, 'boletín: destinatarios confirmados con el tema');
SELECT web_newsletter_issue_sent('00000000-0000-7000-8000-00000000f501', 'ee-123');
UPDATE newsletter_issues SET opens_count = 1 WHERE id = '00000000-0000-7000-8000-00000000f501';
SELECT pg_temp.expect_error($$UPDATE newsletter_issues SET subject = 'otro' WHERE id = '00000000-0000-7000-8000-00000000f501'$$, '42501', 'edición enviada no se edita (solo métricas)');
SELECT pg_temp.expect_error($$UPDATE resource_downloads SET lead_id = NULL$$, '42501', 'resource_downloads rechaza UPDATE');
SELECT pg_temp.expect_error($$DELETE FROM resource_downloads$$, '42501', 'resource_downloads rechaza DELETE');
SELECT pg_temp.expect_error($$UPDATE help_article_feedback SET was_helpful = false$$, '42501', 'help_article_feedback rechaza UPDATE');
SELECT pg_temp.expect_error($$DELETE FROM help_article_feedback$$, '42501', 'help_article_feedback rechaza DELETE');
SELECT pg_temp.expect_error($$UPDATE status_incident_updates SET message = 'x'$$, '42501', 'status_incident_updates rechaza UPDATE');
SELECT pg_temp.expect_error($$DELETE FROM status_incident_updates$$, '42501', 'status_incident_updates rechaza DELETE');
COMMIT;
SELECT pg_temp.expect_error($$UPDATE resource_downloads SET lead_id = NULL$$, '42501', 'resource_downloads rechaza UPDATE incluso al superusuario');
SELECT pg_temp.expect_error($$DELETE FROM help_article_feedback$$, '42501', 'help_article_feedback rechaza DELETE incluso al superusuario');
SELECT pg_temp.expect_error($$TRUNCATE status_incident_updates$$, '42501', 'status_incident_updates rechaza TRUNCATE incluso al superusuario');

-- -------------------------------------------------------------------------------------
-- 4e. WEB v1.5 · métricas, calendario de demos, chatbot, CRM, medios y portafolio (spec/brief-web-v15.md)
--     y v1.5-b · campañas, popups, integraciones, novedades, webinars, reseñas y calculadora de ahorro
-- -------------------------------------------------------------------------------------
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
SELECT pg_temp.expect_error(format('SELECT * FROM %I', t), '42501', 'WEB v1.5: la app de empresa no lee ' || t)
  FROM unnest(ARRAY['site_media', 'site_analytics_daily', 'site_events', 'demo_booking_settings', 'demo_hosts', 'demo_availability_rules',
                    'demo_blackouts', 'demo_meetings', 'chatbot_settings', 'chatbot_intents', 'chatbot_conversations', 'chatbot_messages',
                    'crm_connections', 'crm_sync_events', 'success_story_media', 'campaigns', 'site_popups', 'site_integrations',
                    'product_updates', 'webinars', 'webinar_registrations', 'review_sources', 'review_snapshots',
                    'savings_calculator_settings', 'savings_calculations']) t;
SELECT pg_temp.expect_error($$INSERT INTO site_events (event_type, session_hash) VALUES ('page_view', repeat('a', 64))$$, '42501', 'WEB v1.5: la app no inserta eventos directo');
SELECT pg_temp.expect_error($$SELECT * FROM web_demo_free_slots(current_date, current_date + 7)$$, '42501', 'WEB v1.5: la app no ve horarios por anfitrión');
SELECT pg_temp.expect_error($$SELECT web_site_metrics(current_date, current_date)$$, '42501', 'WEB v1.5: la app no lee métricas internas');
SELECT pg_temp.expect_error($$SELECT web_crm_retry_event(uuidv7())$$, '42501', 'WEB v1.5: la app no reintenta envíos al CRM');
SELECT pg_temp.expect_error($$SELECT web_demo_meeting_confirm(uuidv7(), 'https://meet.google.com/x')$$, '42501', 'WEB v1.5: la app no confirma reuniones');
COMMIT;

-- Semillas v1.5
SELECT pg_temp.expect((SELECT NOT public_booking_enabled FROM demo_booking_settings) AND (SELECT NOT enabled AND mode = 'guided' FROM chatbot_settings)
                      AND (SELECT count(*) FROM chatbot_intents WHERE status = 'draft') = 4 AND (SELECT count(*) FROM chatbot_intents WHERE status = 'published') = 0,
                      'semillas: agenda pública y chatbot desactivados (modo guiado), intenciones de ejemplo en borrador');
SELECT pg_temp.expect((SELECT status = 'paused' AND provider = 'kallpa' AND base_url IS NULL AND secret_ref IS NULL FROM crm_connections WHERE name = 'Kallpa CRM'),
                      'semillas: conexión «Kallpa CRM» en pausa sin URL ni secreto');
SELECT pg_temp.expect((SELECT status = 'draft' AND to_validate AND assumptions_json ->> 'hourly_cost_pen' IS NULL FROM savings_calculator_settings),
                      'semillas: calculadora de ahorro en borrador, a validar, sin costo hora');
SELECT pg_temp.expect((SELECT jsonb_array_length(content_blocked_terms) > 5 AND content_blocked_terms ? 'Cero multas' FROM site_settings),
                      'semillas: lineamientos de contenido con expresiones no veraces bloqueadas');
SELECT pg_temp.expect((SELECT count(*) FROM legal_parameters WHERE company_id IS NULL AND to_confirm AND key IN ('web.analytics_event_retention_months',
                        'web.chatbot_retention_months', 'web.demo_recording_requires_consent', 'web.review_cache_max_hours', 'web.popup_max_per_visit')) = 5,
                      'parámetros v1.5 sembrados con to_confirm');
SELECT pg_temp.expect((SELECT count(*) FROM permissions WHERE code IN ('web.demo_meetings.assign', 'web.demo_meetings.confirm', 'web.chatbot_intents.publish',
                        'web.crm_connections.manage', 'web.site_analytics.export', 'web.campaigns.publish', 'web.site_popups.publish',
                        'web.product_updates.publish', 'web.webinars.publish', 'web.review_sources.manage')) = 10, '10 permisos especiales v1.5');
SELECT pg_temp.expect(NOT EXISTS (SELECT 1 FROM permissions p WHERE p.module_code = 'WEB' AND NOT EXISTS (
                        SELECT 1 FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE rp.permission_id = p.id AND r.code = 'platform_owner')),
                      'platform_owner sigue con todos los permisos web.* (incluidos los de v1.5)');
SELECT pg_temp.expect((SELECT count(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                        WHERE p.code = 'web.site_analytics.export') = 1, 'exportar métricas: solo platform_owner');
SELECT pg_temp.expect((SELECT array_agg(p.code ORDER BY p.code) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                        WHERE r.code = 'platform_support' AND p.code IN ('web.demo_meetings.confirm', 'web.demo_meetings.assign', 'web.chatbot_intents.update',
                          'web.site_media.create', 'web.site_events.read', 'web.site_analytics_daily.read', 'web.crm_connections.manage', 'web.crm_connections.update',
                          'web.crm_sync_events.update', 'web.site_analytics.export', 'web.chatbot_intents.publish'))
                      = ARRAY['web.chatbot_intents.update', 'web.demo_meetings.assign', 'web.demo_meetings.confirm', 'web.site_analytics_daily.read',
                              'web.site_events.read', 'web.site_media.create'],
                      'platform_support atiende calendario, chatbot y medios y lee métricas; no gestiona el CRM ni exporta métricas');
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((public_demo_slots() ->> 'result') = 'disabled' AND (public_demo_book('{}', now(), repeat('t', 40)) ->> 'result') = 'disabled',
                      'agenda pública desactivada: sin horarios ni reservas');
SELECT pg_temp.expect((public_chatbot_config() ->> 'result') = 'disabled' AND (public_chatbot_start(repeat('a', 64)) ->> 'result') = 'disabled', 'chatbot desactivado');
COMMIT;

-- Calendario: anfitrión con disponibilidad 09:00–11:00 todos los días, bloqueo y feriados
CREATE TEMP TABLE t15 AS
SELECT d AS dday,
       (d + time '09:00') AT TIME ZONE 'America/Lima' AS s0900, (d + time '09:30') AT TIME ZONE 'America/Lima' AS s0930,
       (d + time '10:00') AT TIME ZONE 'America/Lima' AS s1000, (d + time '10:30') AT TIME ZONE 'America/Lima' AS s1030,
       (d + time '09:15') AT TIME ZONE 'America/Lima' AS s0915, (d + time '16:00') AT TIME ZONE 'America/Lima' AS s1600
  FROM (SELECT min(x)::date AS d FROM generate_series(current_date + 3, current_date + 20, interval '1 day') x
         WHERE NOT EXISTS (SELECT 1 FROM holidays h WHERE h.holiday_on = x::date AND h.is_non_working)) z;
GRANT SELECT ON t15 TO PUBLIC;
BEGIN;
SET LOCAL ROLE escudo_platform;
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f1', true);
UPDATE demo_booking_settings SET public_booking_enabled = true, duration_minutes = 30, buffer_minutes = 0, min_notice_hours = 0, max_days_ahead = 30;
INSERT INTO demo_hosts (id, user_id, display_name, email, color) VALUES
  ('00000000-0000-7000-8000-00000000d001', '00000000-0000-7000-8000-0000000000f1', 'Asesora comercial (ejemplo)', 'asesora@example.org', '#1F7FD1');
SELECT pg_temp.expect_error($$INSERT INTO demo_hosts (user_id, display_name, email, calendar_provider) VALUES ('00000000-0000-7000-8000-0000000000f2', 'X', 'x@example.org', 'google')$$,
                            '23514', 'calendario conectado exige la referencia al secreto OAuth');
SELECT pg_temp.expect_error($$INSERT INTO demo_hosts (user_id, display_name, email, calendar_provider, calendar_secret_ref) VALUES ('00000000-0000-7000-8000-0000000000f2', 'X', 'x@example.org', 'google', 'ya29.token-en-claro')$$,
                            '23514', 'token OAuth en claro rechazado (solo referencia)');
INSERT INTO demo_availability_rules (host_id, weekday, start_time, end_time)
SELECT '00000000-0000-7000-8000-00000000d001', d, '09:00', '11:00' FROM generate_series(1, 7) d;
INSERT INTO demo_blackouts (host_id, starts_at, ends_at, reason) SELECT '00000000-0000-7000-8000-00000000d001', s1000, s1030, 'Reunión interna' FROM t15;
SELECT pg_temp.expect_error($$INSERT INTO demo_booking_settings (singleton) VALUES (true)$$, '23505', 'demo_booking_settings es una sola fila');
SELECT pg_temp.expect_error($$INSERT INTO chatbot_settings (singleton) VALUES (true)$$, '23505', 'chatbot_settings es una sola fila');
COMMIT;

BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((SELECT array_agg((x ->> 'starts_at')::timestamptz ORDER BY (x ->> 'starts_at')::timestamptz)
                         FROM jsonb_array_elements(public_demo_slots((SELECT dday FROM t15), (SELECT dday FROM t15)) -> 'slots') x)
                      = (SELECT ARRAY[s0900, s0930, s1030] FROM t15), 'horarios libres según reglas, sin la hora bloqueada');
SELECT pg_temp.expect(NOT (public_demo_slots() -> 'slots' -> 0 ?| ARRAY['host_id', 'host_name', 'email']), 'horarios públicos sin datos del anfitrión');
SELECT pg_temp.expect((SELECT r ->> 'result' = 'ok' AND r ->> 'status' = 'proposed' AND NOT (r ->> 'replay')::boolean
                         FROM (SELECT public_demo_book('{"full_name":"Olga","company_name":"Omega Ejemplo","email":"Olga@Omega.pe","privacy_consent":true}',
                                                       (SELECT s0900 FROM t15), 'token-gestion-demo-0123456789abcdef-01', '\x01', 'bk-1') AS r) z),
                      'reserva pública: crea solicitud, lead y reunión propuesta');
SELECT pg_temp.expect((public_demo_book('{}', (SELECT s0900 FROM t15), 'token-gestion-demo-0123456789abcdef-01', NULL, 'bk-1') ->> 'replay')::boolean,
                      'reserva idempotente por Idempotency-Key');
SELECT pg_temp.expect((public_demo_book('{"full_name":"P","company_name":"Pi","email":"p@pi.pe","privacy_consent":true}', (SELECT s0900 FROM t15), repeat('u', 40), NULL, 'bk-2') ->> 'result') = 'slot_unavailable',
                      'una hora ya reservada no se vuelve a ofrecer');
SELECT pg_temp.expect((public_demo_book('{"full_name":"P","company_name":"Pi","email":"p@pi.pe","privacy_consent":true}', (SELECT s1000 FROM t15), repeat('u', 40), NULL, 'bk-3') ->> 'result') = 'slot_unavailable',
                      'una hora bloqueada no se reserva');
SELECT pg_temp.expect_error($$SELECT public_demo_book('{"full_name":"P","company_name":"Pi","email":"p@pi.pe","privacy_consent":false}', (SELECT s0930 FROM t15), repeat('u', 40), NULL, 'bk-4')$$,
                            '23514', 'reserva sin consentimiento de privacidad rechazada');
SELECT pg_temp.expect_error($$SELECT public_demo_book('{"full_name":"P","company_name":"Pi","email":"p@pi.pe","privacy_consent":true}', (SELECT s0930 FROM t15), 'corto', NULL, 'bk-5')$$,
                            '23514', 'token de gestión demasiado corto rechazado');
SELECT pg_temp.expect((SELECT array_agg((x ->> 'starts_at')::timestamptz ORDER BY 1) FROM jsonb_array_elements(public_demo_slots((SELECT dday FROM t15), (SELECT dday FROM t15)) -> 'slots') x)
                      = (SELECT ARRAY[s0930, s1030] FROM t15), 'la hora reservada sale de los horarios libres');
SELECT pg_temp.expect((public_demo_manage('token-gestion-demo-0123456789abcdef-01') ->> 'result') = 'ok'
                      AND NOT (public_demo_manage('token-gestion-demo-0123456789abcdef-01') ?| ARRAY['email', 'full_name', 'company_name', 'phone'])
                      AND (public_demo_manage(repeat('z', 40)) ->> 'result') = 'invalid', 'gestión de la cita solo con el token correcto y sin datos personales');
SELECT pg_temp.expect((public_demo_reschedule('token-gestion-demo-0123456789abcdef-01', (SELECT s1000 FROM t15)) ->> 'result') = 'slot_unavailable'
                      AND (public_demo_reschedule('token-gestion-demo-0123456789abcdef-01', (SELECT s1030 FROM t15)) ->> 'result') = 'ok',
                      'reprogramar solo a una hora libre');
COMMIT;
SELECT pg_temp.expect((SELECT manage_token_hash = sha256(convert_to('token-gestion-demo-0123456789abcdef-01', 'UTF8')) AND starts_at = (SELECT s1030 FROM t15)
                         FROM demo_meetings m JOIN demo_requests d ON d.id = m.demo_request_id WHERE d.idempotency_key = 'bk-1'),
                      'token de la cita guardado solo como sha256');
SELECT pg_temp.expect((SELECT d.status = 'demo_scheduled' AND l.demo_request_id = d.id AND l.source = 'demo'
                         FROM demo_requests d JOIN marketing_leads l ON l.email = 'olga@omega.pe' WHERE d.idempotency_key = 'bk-1'),
                      'la reserva deja la solicitud agendada y entra a la bandeja de leads');

-- Feriado nacional (prueba revertida): sin horarios ese día
BEGIN;
INSERT INTO holidays (holiday_on, name, scope, to_confirm) SELECT dday + 1, 'Feriado de prueba', 'national', true FROM t15 ON CONFLICT DO NOTHING;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect(jsonb_array_length(public_demo_slots((SELECT dday + 1 FROM t15), (SELECT dday + 1 FROM t15)) -> 'slots') = 0, 'feriado nacional sin horarios');
ROLLBACK;

-- Panel: confirmar, cruces y cierre
BEGIN;
SET LOCAL ROLE escudo_platform;
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f1', true);
SELECT pg_temp.expect_error($$SELECT web_demo_meeting_confirm((SELECT m.id FROM demo_meetings m JOIN demo_requests d ON d.id = m.demo_request_id WHERE d.idempotency_key = 'bk-1'))$$,
                            '23514', 'confirmar por Meet exige enlace de reunión');
SELECT pg_temp.expect((web_demo_meeting_confirm((SELECT m.id FROM demo_meetings m JOIN demo_requests d ON d.id = m.demo_request_id WHERE d.idempotency_key = 'bk-1'),
                                                'https://meet.google.com/abc-defg-hij') ->> 'status') = 'confirmed', 'reunión confirmada con enlace');
INSERT INTO demo_requests (id, full_name, company_name, email, privacy_consent) VALUES ('00000000-0000-7000-8000-00000000d101', 'Rho', 'Rho Ejemplo', 'rho@rho.pe', true);
SELECT pg_temp.expect_error($$INSERT INTO demo_meetings (demo_request_id, host_id, starts_at, ends_at, status, confirmed_at, meeting_url, manage_token_hash)
                              SELECT '00000000-0000-7000-8000-00000000d101', '00000000-0000-7000-8000-00000000d001', s1030 + interval '10 minutes', s1030 + interval '40 minutes',
                                     'confirmed', now(), 'https://meet.google.com/x', sha256('m2') FROM t15$$, '23P01', 'dos reuniones confirmadas del mismo anfitrión no se cruzan');
INSERT INTO demo_meetings (id, demo_request_id, host_id, starts_at, ends_at, status, manage_token_hash)
SELECT '00000000-0000-7000-8000-00000000d201', '00000000-0000-7000-8000-00000000d101', '00000000-0000-7000-8000-00000000d001', s1030 + interval '10 minutes', s1030 + interval '40 minutes',
       'proposed', sha256('m2') FROM t15;
SELECT pg_temp.expect_error($$SELECT web_demo_meeting_confirm('00000000-0000-7000-8000-00000000d201', 'https://meet.google.com/y')$$, '23P01',
                            'confirmar una propuesta que se cruza con otra confirmada se rechaza');
UPDATE demo_meetings SET starts_at = (SELECT s1600 FROM t15), ends_at = (SELECT s1600 FROM t15) + interval '30 minutes' WHERE id = '00000000-0000-7000-8000-00000000d201';
SELECT pg_temp.expect((web_demo_meeting_confirm('00000000-0000-7000-8000-00000000d201', NULL, 'phone') ->> 'status') = 'confirmed', 'reunión telefónica sin cruce confirmada');
SELECT pg_temp.expect_error($$SELECT web_demo_meeting_complete('00000000-0000-7000-8000-00000000d201')$$, '23514', 'completar exige resultado');
SELECT pg_temp.expect((web_demo_meeting_complete('00000000-0000-7000-8000-00000000d201', 'follow_up', 'Enviar propuesta') ->> 'status') = 'completed', 'demo completada con resultado');
SELECT pg_temp.expect_error($$INSERT INTO demo_meetings (demo_request_id, starts_at, ends_at, manage_token_hash) VALUES ('00000000-0000-7000-8000-00000000d101', now(), now() + interval '1 hour', 'texto-en-claro')$$,
                            '23514', 'token de la cita solo como hash de 32 bytes');
COMMIT;
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((public_demo_manage('token-gestion-demo-0123456789abcdef-01') ->> 'meeting_url') = 'https://meet.google.com/abc-defg-hij', 'cita confirmada muestra el enlace');
SELECT pg_temp.expect((public_demo_cancel('token-gestion-demo-0123456789abcdef-01', 'Cambio de agenda') ->> 'result') = 'cancelled'
                      AND (public_demo_cancel('token-gestion-demo-0123456789abcdef-01') ->> 'replay')::boolean, 'cancelar por enlace seguro (idempotente)');
SELECT pg_temp.expect((SELECT array_agg((x ->> 'starts_at')::timestamptz ORDER BY 1) FROM jsonb_array_elements(public_demo_slots((SELECT dday FROM t15), (SELECT dday FROM t15)) -> 'slots') x)
                      = (SELECT ARRAY[s0900, s0930, s1030] FROM t15), 'la hora de una cita cancelada vuelve a estar libre');
COMMIT;

-- Métricas: solo con consentimiento analítico, sin IP; inalterables
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT public_record_cookie_consent('00000000-0000-7000-8000-00000000e501', '{"analytics":true}', 'custom');
SELECT public_record_cookie_consent('00000000-0000-7000-8000-00000000e502', '{}', 'reject_all');
SELECT pg_temp.expect((public_record_event('00000000-0000-7000-8000-00000000e501', jsonb_build_object('event_type', 'page_view', 'path', '/planes',
                        'source', 'organic', 'device', 'mobile', 'utm_campaign', 'Lanzamiento', 'session_hash', repeat('ab', 32))) ->> 'result') = 'recorded',
                      'evento con consentimiento analítico registrado');
SELECT public_record_event('00000000-0000-7000-8000-00000000e501', jsonb_build_object('event_type', 'form_submit', 'path', '/planes', 'element_key', 'form_quote',
                           'utm_campaign', 'lanzamiento', 'session_hash', repeat('ab', 32)));
SELECT pg_temp.expect_error($$SELECT public_record_event('00000000-0000-7000-8000-00000000e502', '{"event_type":"page_view","path":"/","session_hash":"$$ || repeat('cd', 32) || $$"}')$$,
                            '42501', 'evento de visitante que rechazó cookies analíticas: rechazado');
SELECT pg_temp.expect_error($$SELECT public_record_event('00000000-0000-7000-8000-00000000e599', '{"event_type":"page_view","path":"/","session_hash":"$$ || repeat('cd', 32) || $$"}')$$,
                            '42501', 'evento sin consentimiento registrado: rechazado');
SELECT pg_temp.expect_error($$SELECT public_record_event('00000000-0000-7000-8000-00000000e501', '{"event_type":"page_view","path":"/","session_hash":"no-es-hash"}')$$,
                            '23514', 'session_hash con formato de hash rotado');
COMMIT;
BEGIN;
SET LOCAL ROLE escudo_platform;
SELECT pg_temp.expect(web_rollup_site_analytics((now() AT TIME ZONE 'America/Lima')::date) = 1 AND web_rollup_site_analytics((now() AT TIME ZONE 'America/Lima')::date) = 1,
                      'agregado diario idempotente (UNIQUE día, ruta, fuente, dispositivo)');
SELECT pg_temp.expect((SELECT (m ->> 'visits')::int = 1 AND (m -> 'conversions' ->> 'form_submit')::int = 1 AND (m -> 'by_device' ->> 'mobile')::int = 1
                              AND (m ->> 'conversion_rate')::numeric = 100 AND jsonb_array_length(m -> 'campaigns') = 1
                         FROM (SELECT web_site_metrics((now() AT TIME ZONE 'America/Lima')::date, (now() AT TIME ZONE 'America/Lima')::date) AS m) z),
                      'métricas agregadas internas: visitas, dispositivo, conversiones, tasa y campañas');
SELECT pg_temp.expect_error($$INSERT INTO site_analytics_daily (day, path, source, device, visits, unique_visitors)
                              VALUES ((now() AT TIME ZONE 'America/Lima')::date, '/planes', 'organic', 'mobile', 3, 1)$$, '23505',
                            'site_analytics_daily único por día, ruta, fuente y dispositivo');
SELECT pg_temp.expect_error($$UPDATE site_events SET path = '/x'$$, '42501', 'site_events rechaza UPDATE');
SELECT pg_temp.expect_error($$DELETE FROM site_events$$, '42501', 'site_events rechaza DELETE');
COMMIT;
SELECT pg_temp.expect((SELECT count(*) FROM site_events) = 2 AND (SELECT count(*) FROM site_events WHERE utm_campaign = 'lanzamiento') = 2,
                      'eventos guardados una vez, campaña normalizada');
SELECT pg_temp.expect_error($$UPDATE site_events SET path = '/x'$$, '42501', 'site_events rechaza UPDATE incluso al superusuario');
SELECT pg_temp.expect_error($$TRUNCATE site_events$$, '42501', 'site_events rechaza TRUNCATE incluso al superusuario');

-- Chatbot
BEGIN;
SET LOCAL ROLE escudo_platform;
UPDATE chatbot_settings SET enabled = true;
SELECT pg_temp.expect_error($$UPDATE chatbot_settings SET mode = 'ai'$$, '23514', 'modo IA exige la referencia al secreto del proveedor');
SELECT web_publish_chatbot_intent(id) FROM chatbot_intents WHERE name = 'Agendar demo';
INSERT INTO chatbot_intents (name, answer) VALUES ('Sin frases', 'x');
SELECT pg_temp.expect_error($$SELECT web_publish_chatbot_intent((SELECT id FROM chatbot_intents WHERE name = 'Sin frases'))$$, '23514', 'intención sin frases no se publica');
COMMIT;
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((public_chatbot_config() ->> 'result') = 'ok' AND jsonb_array_length(public_chatbot_config() -> 'intents') = 1
                      AND NOT (public_chatbot_config() ? 'ai_provider_secret_ref') AND (public_chatbot_config() ->> 'mode') = 'guided',
                      'configuración pública: solo intenciones publicadas, sin secretos');
SELECT (public_chatbot_start(repeat('ef', 32), '/planes') ->> 'conversation_id') AS conv \gset
SELECT pg_temp.expect((SELECT r ->> 'result' = 'ok' AND r ->> 'cta_type' = 'demo'
                         FROM (SELECT public_chatbot_message(:'conv', repeat('ef', 32), 'Quiero una DEMO') AS r) z), 'chatbot guiado responde con la intención publicada');
SELECT pg_temp.expect((public_chatbot_message(:'conv', repeat('ef', 32), '¿Cuál es el precio?') ->> 'result') = 'no_match', 'intención en borrador no responde');
SELECT pg_temp.expect((public_chatbot_message(:'conv', repeat('00', 32), 'hola') ->> 'result') = 'not_found', 'otra sesión no escribe en la conversación');
SELECT pg_temp.expect_error(format($$SELECT public_chatbot_lead(%L, repeat('ef', 32), '{"email":"chat@kappa.pe"}')$$, :'conv'), '23514', 'lead del chatbot exige privacidad');
SELECT pg_temp.expect((public_chatbot_lead(:'conv', repeat('ef', 32), '{"email":"chat@kappa.pe","privacy_consent":true}') ->> 'result') = 'ok', 'lead del chatbot con consentimiento');
COMMIT;
SELECT pg_temp.expect((SELECT message_count = 4 AND handoff = 'form' AND lead_id = (SELECT id FROM marketing_leads WHERE email = 'chat@kappa.pe')
                         FROM chatbot_conversations WHERE id = :'conv') AND (SELECT count(*) FROM chatbot_messages WHERE conversation_id = :'conv') = 4
                      AND (SELECT NOT marketing_consent FROM marketing_leads WHERE email = 'chat@kappa.pe'),
                      'conversación con mensajes, lead enlazado y consentimiento comercial no presumido');
SELECT pg_temp.expect_error($$UPDATE chatbot_messages SET body = 'x'$$, '42501', 'chatbot_messages rechaza UPDATE');
SELECT pg_temp.expect_error($$DELETE FROM chatbot_messages$$, '42501', 'chatbot_messages rechaza DELETE');

-- CRM: cola idempotente; el Libro de Reclamaciones nunca se envía
BEGIN;
SET LOCAL ROLE escudo_platform;
SELECT pg_temp.expect_error($$INSERT INTO crm_connections (provider, name, status) VALUES ('generic_webhook', 'Sin URL', 'active')$$, '23514', 'conexión activa exige URL y secreto');
SELECT pg_temp.expect_error($$INSERT INTO crm_connections (provider, name, entities_json) VALUES ('generic_webhook', 'Reclamos', '["consumer_complaint"]')$$, '23514',
                            'el Libro de Reclamaciones no es una entidad sincronizable');
INSERT INTO crm_connections (id, provider, name, base_url, secret_ref, status, entities_json) VALUES
  ('00000000-0000-7000-8000-00000000c001', 'generic_webhook', 'Webhook de prueba', 'https://crm.example.org/hook', 'vault://escudo/crm/hook', 'active', '["marketing_lead", "demo_request"]');
COMMIT;
SELECT count(*) AS crm_before FROM crm_sync_events \gset
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT public_submit_complaint('{"consumer_full_name":"c:n","consumer_document_type":"DNI","consumer_document_number":"c:d","consumer_email":"c:e",
   "item_type":"service","item_description":"Soporte","complaint_type":"complaint","detail":"Atención","consumer_request":"Mejorar"}', 'clave-seguimiento-crm1', NULL, 'lr-crm');
COMMIT;
SELECT pg_temp.expect((SELECT count(*) FROM crm_sync_events) = :crm_before, 'un reclamo no genera envío al CRM');
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT public_create_demo_request('{"full_name":"Sigma","company_name":"Sigma Ejemplo","email":"crm@sigma.pe","privacy_consent":true}', NULL, 'crm-1');
COMMIT;
SELECT pg_temp.expect((SELECT count(*) FROM crm_sync_events e JOIN demo_requests d ON d.id = e.entity_id WHERE d.idempotency_key = 'crm-1' AND e.action = 'create' AND e.status = 'pending') = 1
                      AND (SELECT count(*) FROM crm_sync_events e JOIN marketing_leads l ON l.id = e.entity_id WHERE l.email = 'crm@sigma.pe' AND e.entity_type = 'marketing_lead') >= 1
                      AND (SELECT count(*) FROM crm_sync_events e JOIN crm_connections c ON c.id = e.connection_id WHERE c.name = 'Kallpa CRM') = 0,
                      'lead y solicitud de demo encolados solo para la conexión activa (Kallpa en pausa no recibe)');
BEGIN;
SET LOCAL ROLE escudo_platform;
SELECT count(*) AS crm_mid FROM crm_sync_events \gset
UPDATE marketing_leads SET notes = 'primera' WHERE email = 'crm@sigma.pe';
UPDATE marketing_leads SET notes = 'segunda' WHERE email = 'crm@sigma.pe';
UPDATE marketing_leads SET updated_at = updated_at WHERE email = 'crm@sigma.pe';
SELECT pg_temp.expect((SELECT count(*) FROM crm_sync_events) = :crm_mid + 1, 'idempotencia: varias actualizaciones de la misma versión encolan un solo envío');
SELECT pg_temp.expect_error($$INSERT INTO crm_sync_events (connection_id, entity_type, entity_id, action, idempotency_key)
                              SELECT connection_id, entity_type, entity_id, action, idempotency_key FROM crm_sync_events LIMIT 1$$, '23505', 'idempotency_key de crm_sync_events única');
SELECT pg_temp.expect((web_crm_event_result((SELECT id FROM crm_sync_events WHERE action = 'create' AND entity_type = 'demo_request' LIMIT 1), false, 503, NULL, 'Servicio no disponible') ->> 'status') = 'failed'
                      AND (web_crm_retry_event((SELECT id FROM crm_sync_events WHERE action = 'create' AND entity_type = 'demo_request' LIMIT 1)) ->> 'status') = 'pending'
                      AND (web_crm_event_result((SELECT id FROM crm_sync_events WHERE action = 'create' AND entity_type = 'demo_request' LIMIT 1), true, 201, 'ext-1') ->> 'status') = 'sent',
                      'envío fallido, reintento y envío correcto');
SELECT pg_temp.expect_error($$SELECT web_crm_retry_event((SELECT id FROM crm_sync_events WHERE status = 'sent' LIMIT 1))$$, '23514', 'un envío ya enviado no se reintenta');
COMMIT;

-- Medios y portafolio
BEGIN;
SET LOCAL ROLE escudo_platform;
SELECT pg_temp.expect_error($$INSERT INTO site_media (file_id, kind, title) VALUES (uuidv7(), 'image', 'Sin alt')$$, '23514', 'imagen sin texto alternativo rechazada');
INSERT INTO site_media (id, file_id, kind, title, alt_text, is_example) VALUES
  ('00000000-0000-7000-8000-00000000a501', '00000000-0000-7000-8000-00000000a5f1', 'image', 'Obra de ejemplo', 'Trabajadores con casco en una obra (ilustración de ejemplo)', true);
UPDATE success_stories SET project_year = 2026, modules_json = '["IPR", "CAP"]' WHERE id = '00000000-0000-7000-8000-00000000f201';
INSERT INTO success_story_media (success_story_id, media_id, caption) VALUES ('00000000-0000-7000-8000-00000000f201', '00000000-0000-7000-8000-00000000a501', 'Obra');
SELECT pg_temp.expect((web_media_usages('00000000-0000-7000-8000-00000000a501') ->> 'usage_count')::int = 1, 'dónde se usa cada archivo');
UPDATE site_media SET file_id = '00000000-0000-7000-8000-00000000a5f2' WHERE id = '00000000-0000-7000-8000-00000000a501';
COMMIT;
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((public_success_stories() -> 'stories' -> 0 ->> 'project_year')::int = 2026
                      AND (public_success_stories() -> 'stories' -> 0 -> 'gallery' -> 0 ->> 'file_id') = '00000000-0000-7000-8000-00000000a5f2',
                      'portafolio con año, módulos y galería (reemplazar el archivo no rompe el enlace)');
COMMIT;

-- v1.5-b: campañas, ventanas emergentes, integraciones, novedades
BEGIN;
SET LOCAL ROLE escudo_platform;
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f1', true);
INSERT INTO site_pages (id, slug, page_type, title, blocks) VALUES ('00000000-0000-7000-8000-00000000b501', 'sst-construccion', 'campaign', 'SST para obras', '[{"type":"hero"}]');
SELECT pg_temp.expect((SELECT noindex FROM site_pages WHERE id = '00000000-0000-7000-8000-00000000b501'), 'landing de campaña noindex por defecto');
INSERT INTO campaigns (id, name, channel, utm_source, utm_medium, utm_campaign, landing_page_id, form_type, starts_on, ends_on) VALUES
  ('00000000-0000-7000-8000-00000000b511', 'Obras Q4', 'google_ads', 'google', 'cpc', 'obras-q4', '00000000-0000-7000-8000-00000000b501', 'demo', current_date - 1, current_date + 30);
SELECT pg_temp.expect_error($$SELECT web_campaign_action('00000000-0000-7000-8000-00000000b511', 'activate')$$, '23514', 'campaña sin landing publicada no se activa');
SELECT web_publish_page('00000000-0000-7000-8000-00000000b501');
SELECT web_campaign_action('00000000-0000-7000-8000-00000000b511', 'activate');
INSERT INTO site_pages (id, slug, page_type, title, blocks) VALUES ('00000000-0000-7000-8000-00000000b502', 'no-veraz', 'landing', 'Cero multas garantizado', '[]');
SELECT pg_temp.expect_error($$SELECT web_publish_page('00000000-0000-7000-8000-00000000b502')$$, '23514', 'página con expresión no veraz no se publica');
INSERT INTO site_popups (name, trigger_type, offer_type, title, status) VALUES ('Salida', 'exit_intent', 'fine_calculator', 'Calcula tu multa referencial', 'active');
SELECT pg_temp.expect_error($$INSERT INTO site_popups (name, trigger_type, trigger_value, offer_type, title) VALUES ('X', 'scroll_percent', 150, 'demo', 'X')$$, '23514', 'scroll entre 1 y 100 %');
INSERT INTO site_integrations (name, slug, category, availability, status) VALUES
  ('WhatsApp', 'whatsapp', 'messaging', 'beta', 'published'), ('Moodle', 'moodle', 'lms', 'available', 'published'), ('Borrador', 'borrador', 'ai', 'planned', 'draft');
INSERT INTO product_updates (id, title, slug, body, category, show_in_app) VALUES
  ('00000000-0000-7000-8000-00000000b521', 'Matriz IPERC por puesto', 'matriz-iperc-por-puesto', 'Texto', 'new', true),
  ('00000000-0000-7000-8000-00000000b522', 'Solo web', 'solo-web', 'Texto', 'fix', false),
  ('00000000-0000-7000-8000-00000000b523', 'Borrador', 'borrador', 'Texto', 'improvement', true);
SELECT web_publish_product_update(id) FROM product_updates WHERE slug IN ('matriz-iperc-por-puesto', 'solo-web');
COMMIT;
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((public_campaign_page('sst-construccion') ->> 'result') = 'ok' AND (public_campaign_page('sst-construccion') -> 'campaign' ->> 'utm_campaign') = 'obras-q4'
                      AND NOT (public_campaign_page('sst-construccion') -> 'campaign' ? 'goal') AND (public_site_page('sst-construccion') ->> 'result') = 'not_found'
                      AND '/l/sst-construccion' = ANY (public_noindex_paths()),
                      'landing de campaña publicada y vigente solo por /l/{slug}, noindex y sin datos internos');
SELECT pg_temp.expect(jsonb_array_length(public_site_popups('/planes', 'mobile') -> 'popups') = 1
                      AND jsonb_array_length(public_site_popups('/libro-de-reclamaciones', 'desktop') -> 'popups') = 0
                      AND jsonb_array_length(public_site_popups('/politica-de-privacidad', 'desktop') -> 'popups') = 0
                      AND (public_site_popups('/planes') ->> 'max_per_visit')::int = 1,
                      'ventanas emergentes activas, nunca en Libro de Reclamaciones ni políticas, con tope por visita');
SELECT pg_temp.expect(jsonb_array_length(public_site_integrations() -> 'integrations') = 2, 'integraciones: solo publicadas');
SELECT pg_temp.expect(jsonb_array_length(public_product_updates() -> 'updates') = 2 AND jsonb_array_length(public_product_updates(20, true) -> 'updates') = 1
                      AND (public_product_update('borrador') ->> 'result') = 'not_found', 'novedades: solo publicadas; en la app solo las marcadas');
COMMIT;
BEGIN;
SET LOCAL ROLE escudo_platform;
SELECT web_campaign_action('00000000-0000-7000-8000-00000000b511', 'pause');
COMMIT;
SELECT pg_temp.expect((public_campaign_page('sst-construccion') ->> 'result') = 'not_found', 'campaña en pausa: la landing no sale');

-- Webinars: cupo, inscripción idempotente, token como hash, grabación solo informada
BEGIN;
SET LOCAL ROLE escudo_platform;
INSERT INTO webinars (id, title, slug, speaker_name, starts_at, duration_minutes, meeting_url, capacity) VALUES
  ('00000000-0000-7000-8000-00000000b601', 'IPERC paso a paso', 'iperc-paso-a-paso', 'Ponente de ejemplo', now() + interval '10 days', 60, 'https://meet.google.com/web-inar-xyz', 1);
SELECT pg_temp.expect_error($$UPDATE webinars SET recording_public = true WHERE id = '00000000-0000-7000-8000-00000000b601'$$, '23514',
                            'grabación pública exige que ponente y asistentes hayan sido informados');
SELECT web_webinar_action('00000000-0000-7000-8000-00000000b601', 'publish');
COMMIT;
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect(NOT (public_webinar('iperc-paso-a-paso') ? 'meeting_url') AND (public_webinar('iperc-paso-a-paso') ->> 'seats_left')::int = 1,
                      'webinar público sin enlace de reunión, con cupos');
SELECT pg_temp.expect_error($$SELECT public_webinar_register('iperc-paso-a-paso', '{"email":"w1@lambda.pe"}', repeat('w', 40))$$, '23514', 'inscripción exige privacidad');
SELECT pg_temp.expect((public_webinar_register('iperc-paso-a-paso', '{"email":"w1@lambda.pe","privacy_consent":true}', 'token-webinar-uno-0123456789abcdef0') ->> 'result') = 'registered'
                      AND (public_webinar_register('iperc-paso-a-paso', '{"email":"W1@lambda.pe","privacy_consent":true}', repeat('v', 40)) ->> 'replay')::boolean,
                      'inscripción idempotente por persona y webinar');
SELECT pg_temp.expect((public_webinar_register('iperc-paso-a-paso', '{"email":"w2@lambda.pe","privacy_consent":true}', 'token-webinar-dos-0123456789abcdef0') ->> 'result') = 'full',
                      'sin cupo: inscripción rechazada');
SELECT pg_temp.expect((public_webinar_cancel('token-webinar-uno-0123456789abcdef0') ->> 'result') = 'cancelled' AND (public_webinar_cancel(repeat('q', 40)) ->> 'result') = 'invalid',
                      'cancelar inscripción solo con el token');
SELECT pg_temp.expect((public_webinar_register('iperc-paso-a-paso', '{"email":"w2@lambda.pe","privacy_consent":true}', 'token-webinar-dos-0123456789abcdef0') ->> 'result') = 'registered',
                      'el cupo liberado se puede usar');
COMMIT;
SELECT pg_temp.expect((SELECT count(*) FROM webinar_registrations WHERE manage_token_hash = sha256(convert_to('token-webinar-dos-0123456789abcdef0', 'UTF8'))) = 1
                      AND (SELECT source FROM marketing_leads WHERE email = 'w2@lambda.pe') = 'webinar', 'token de inscripción como sha256; lead con origen webinar');

-- Reseñas de Google: solo reales en caché vigente, sin elegir a mano
BEGIN;
SET LOCAL ROLE escudo_platform;
SELECT pg_temp.expect_error($$INSERT INTO review_sources (place_id, status) VALUES ('ChIJ-ejemplo', 'active')$$, '23514', 'fuente de reseñas activa exige la referencia a la llave');
INSERT INTO review_sources (id, place_id, api_secret_ref, min_rating, max_to_show, cache_ttl_hours, status) VALUES
  ('00000000-0000-7000-8000-00000000b701', 'ChIJ-ejemplo', 'vault://escudo/google/places', 4, 5, 2000, 'active');
SELECT pg_temp.expect((web_store_review_snapshots('00000000-0000-7000-8000-00000000b701', '[{"author_name":"Autor A","rating":5,"body":"Muy bueno","review_published_at":"2026-10-01T10:00:00Z"},
                         {"author_name":"Autor B","rating":2,"body":"Mejorable","review_published_at":"2026-10-02T10:00:00Z"}]') ->> 'expires_in_hours')::int = 720,
                      'caché de reseñas con el tope del parámetro del proveedor');
COMMIT;
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect(jsonb_array_length(public_reviews() -> 'reviews') = 1 AND (public_reviews() ->> 'attribution') = 'Reseñas de Google'
                      AND (public_reviews() -> 'reviews' -> 0 ->> 'body') = 'Muy bueno', 'reseñas: calificación mínima, texto sin editar y atribución');
COMMIT;
UPDATE review_snapshots SET fetched_at = now() - interval '2 days', expires_at = now() - interval '1 hour';
SELECT pg_temp.expect(jsonb_array_length(public_reviews() -> 'reviews') = 0, 'reseñas vencidas no se muestran');

-- Calculadora de ahorro: supuestos visibles, snapshot e idempotencia
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((public_savings_calculator() ->> 'result') = 'not_found', 'calculadora de ahorro en borrador no sale');
COMMIT;
UPDATE savings_calculator_settings SET status = 'published';
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect(NOT (public_savings_calculator() ->> 'available')::boolean
                      AND (public_calculate_savings('{"workers":50,"sites":2,"records_per_month":100,"hours_per_month":40}') ->> 'result') = 'assumptions_pending',
                      'sin supuestos definidos no calcula');
COMMIT;
UPDATE savings_calculator_settings SET assumptions_json = '{"minutes_saved_per_record": 6, "manual_hours_reduction_pct": 25, "hourly_cost_pen": null}';
BEGIN;
SET LOCAL ROLE escudo_app;
SELECT pg_temp.expect((SELECT (r ->> 'result_hours_month')::numeric = 20 AND r ->> 'result_cost_month' IS NULL AND (r ->> 'lead_registered')::boolean
                         FROM (SELECT public_calculate_savings('{"workers":50,"sites":2,"records_per_month":100,"hours_per_month":40,"email":"ahorro@mu.pe","privacy_consent":true}', NULL, 'sv-1') AS r) z),
                      'ahorro estimado: horas con supuestos visibles; costo vacío si no hay costo hora');
SELECT pg_temp.expect((public_calculate_savings('{}', NULL, 'sv-1') ->> 'replay')::boolean, 'calculadora de ahorro idempotente');
SELECT pg_temp.expect_error($$SELECT public_calculate_savings('{"workers":0,"records_per_month":10}')$$, '23514', 'calculadora de ahorro: datos inválidos rechazados');
COMMIT;
SELECT pg_temp.expect((SELECT assumptions_snapshot_json -> 'assumptions' ->> 'minutes_saved_per_record' = '6' AND lead_id IS NOT NULL FROM savings_calculations WHERE idempotency_key = 'sv-1')
                      AND (SELECT source FROM marketing_leads WHERE email = 'ahorro@mu.pe') = 'savings_calculator', 'copia de supuestos guardada; lead con origen savings_calculator');

\echo '== Base principal: todas las pruebas pasaron'

-- -------------------------------------------------------------------------------------
-- 5. Base médica
-- -------------------------------------------------------------------------------------
\connect :medical_db
\set ON_ERROR_STOP 1
\o /dev/null
CREATE OR REPLACE FUNCTION pg_temp.expect(p_cond boolean, p_label text) RETURNS void
LANGUAGE plpgsql AS $$ BEGIN IF p_cond IS NOT TRUE THEN RAISE EXCEPTION 'FALLA %', p_label; END IF; RAISE NOTICE 'OK  %', p_label; END $$;
CREATE OR REPLACE FUNCTION pg_temp.expect_error(p_sql text, p_state text, p_label text) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
  BEGIN EXECUTE p_sql;
  EXCEPTION WHEN OTHERS THEN
    IF p_state IS NOT NULL AND SQLSTATE <> p_state THEN RAISE EXCEPTION 'FALLA %: % (%)', p_label, SQLSTATE, SQLERRM; END IF;
    RAISE NOTICE 'OK  %  (rechazado: %)', p_label, SQLSTATE; RETURN;
  END;
  RAISE EXCEPTION 'FALLA %: debía fallar', p_label;
END $$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

SELECT pg_temp.expect(NOT rolbypassrls, 'escudo_med_app no tiene BYPASSRLS') FROM pg_roles WHERE rolname = 'escudo_med_app';
SELECT pg_temp.expect(count(*) = 0, 'base médica: sin FKs hacia otras bases (solo internas)')
  FROM pg_constraint WHERE contype = 'f' AND confrelid::regclass::text NOT IN (SELECT tablename FROM pg_tables WHERE schemaname = 'public');

BEGIN;
SET LOCAL ROLE escudo_med_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f1', true);
SELECT set_config('app.reauth_session_id', '00000000-0000-7000-8000-0000000000e1', true);
INSERT INTO medical_exams (company_id, worker_id, exam_type, exam_on, fitness_result, diagnoses)
VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000a4', 'periodic', current_date, 'fit', '"cifrado:..."');
SELECT med_log_access('medical_exam', NULL, 'create', '00000000-0000-7000-8000-0000000000a4', 'occupational_doctor', 'EMO periódico');
COMMIT;

BEGIN;
SET LOCAL ROLE escudo_med_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000b1', true);
SELECT set_config('app.user_id', '00000000-0000-7000-8000-0000000000f2', true);
SELECT set_config('app.reauth_session_id', '00000000-0000-7000-8000-0000000000e2', true);
SELECT pg_temp.expect((SELECT count(*) FROM medical_exams) = 0, 'base médica: BETA no ve EMOs de ALFA');
SELECT pg_temp.expect((SELECT count(*) FROM medical_access_logs) = 0, 'base médica: BETA no ve accesos de ALFA');
SELECT pg_temp.expect_error($$INSERT INTO medical_exams (company_id, worker_id, exam_type, exam_on)
  VALUES ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000a4', 'periodic', current_date)$$, '42501', 'base médica: BETA no inserta en ALFA');
COMMIT;

BEGIN;
SET LOCAL ROLE escudo_med_app;
SELECT set_config('app.company_id', '00000000-0000-7000-8000-0000000000a1', true);
SELECT pg_temp.expect((SELECT count(*) FROM medical_exams) = 0, 'base médica: sin re-autenticación no se leen EMOs ni de la propia empresa');
SELECT set_config('app.reauth_session_id', '00000000-0000-7000-8000-0000000000e1', true);
SELECT pg_temp.expect((SELECT count(*) FROM medical_exams) = 1, 'base médica: con re-autenticación ALFA ve su EMO');
SELECT pg_temp.expect_error($$UPDATE medical_access_logs SET purpose = 'x'$$, '42501', 'medical_access_logs rechaza UPDATE');
SELECT pg_temp.expect_error($$DELETE FROM medical_access_logs$$, '42501', 'medical_access_logs rechaza DELETE');
COMMIT;

BEGIN;
SET LOCAL ROLE escudo_med_app;
SELECT pg_temp.expect((SELECT count(*) FROM medical_exams) = 0, 'base médica: sin contexto no se ve nada');
COMMIT;
SELECT pg_temp.expect_error($$DELETE FROM medical_access_logs$$, '42501', 'medical_access_logs rechaza DELETE también al superusuario');

\echo '== Base médica: todas las pruebas pasaron'
