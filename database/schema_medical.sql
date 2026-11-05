-- =====================================================================================
-- EscudoSST · esquema de la base MÉDICA `escudo_medical`
-- GENERADO por tools/db/generar.py desde tools/db/modelo_medical.txt — no editar a mano.
-- PostgreSQL 16 y 17. Requiere un rol con permiso para crear roles (la primera vez) y extensión citext no requerida.
-- Solo tablas `db: med` del catálogo. Sin FK hacia escudo_main: worker_id, company_id y *_ref se validan en la aplicación.
-- =====================================================================================

SET client_min_messages = warning;
SET search_path = public;

-- ---------- Roles ----------------------------------------------------------------
-- owner: dueño de los objetos (migraciones). NO tiene BYPASSRLS: FORCE ROW LEVEL SECURITY también lo alcanza.
-- app:   rol de la aplicación (las credenciales LOGIN se crean en despliegue con IN ROLE). Sin BYPASSRLS.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'escudo_med_owner') THEN CREATE ROLE escudo_med_owner NOLOGIN NOBYPASSRLS; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'escudo_med_app') THEN CREATE ROLE escudo_med_app NOLOGIN NOBYPASSRLS; END IF;
END $$;
ALTER ROLE escudo_med_owner NOLOGIN NOBYPASSRLS;
ALTER ROLE escudo_med_app NOLOGIN NOBYPASSRLS;
GRANT USAGE ON SCHEMA public TO escudo_med_app;
GRANT CREATE ON SCHEMA public TO escudo_med_owner;

-- ---------- Funciones base ---------------------------------------------------------
-- UUID v7 (RFC 9562): 48 bits de milisegundos Unix + aleatorio. En PostgreSQL 18 existe pg_catalog.uuidv7();
-- esta versión en public mantiene compatibilidad con 16 y 17.
CREATE OR REPLACE FUNCTION public.uuidv7() RETURNS uuid
LANGUAGE plpgsql VOLATILE PARALLEL SAFE AS $$
DECLARE
  ms bigint := floor(extract(epoch FROM clock_timestamp()) * 1000);
  b bytea := uuid_send(gen_random_uuid());
BEGIN
  b := overlay(b PLACING substring(int8send(ms) FROM 3 FOR 6) FROM 1 FOR 6);
  b := set_byte(b, 6, (get_byte(b, 6) & 15) | 112);   -- versión 7
  b := set_byte(b, 8, (get_byte(b, 8) & 63) | 128);   -- variante RFC
  RETURN encode(b, 'hex')::uuid;
END $$;

-- Contexto de la petición (fijado con SET LOCAL por el middleware SetTenantContext).
-- Si no está fijado devuelve NULL y ninguna política deja pasar filas (no hay valor por defecto permisivo).
CREATE OR REPLACE FUNCTION public.app_company_id() RETURNS uuid
LANGUAGE sql STABLE PARALLEL SAFE AS $$ SELECT NULLIF(current_setting('app.company_id', true), '')::uuid $$;
CREATE OR REPLACE FUNCTION public.app_account_id() RETURNS uuid
LANGUAGE sql STABLE PARALLEL SAFE AS $$ SELECT NULLIF(current_setting('app.account_id', true), '')::uuid $$;
CREATE OR REPLACE FUNCTION public.app_user_id() RETURNS uuid
LANGUAGE sql STABLE PARALLEL SAFE AS $$ SELECT NULLIF(current_setting('app.user_id', true), '')::uuid $$;
CREATE OR REPLACE FUNCTION public.app_contractor_id() RETURNS uuid
LANGUAGE sql STABLE PARALLEL SAFE AS $$ SELECT NULLIF(current_setting('app.contractor_id', true), '')::uuid $$;

CREATE OR REPLACE FUNCTION public.trg_set_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.trg_bump_version() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.version IS NOT DISTINCT FROM OLD.version THEN
    NEW.version := OLD.version + 1;
  ELSIF NEW.version <> OLD.version + 1 THEN
    RAISE EXCEPTION 'conflicto de versión en %.%: esperado %, recibido %', TG_TABLE_NAME, OLD.id, OLD.version + 1, NEW.version
      USING ERRCODE = '40001';
  END IF;
  RETURN NEW;
END $$;

-- Tablas inalterables: solo INSERT. Rechaza UPDATE, DELETE y TRUNCATE para cualquier rol (incluido el dueño).
CREATE OR REPLACE FUNCTION public.trg_forbid_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'La tabla % es inalterable: % no permitido', TG_TABLE_NAME, TG_OP
    USING ERRCODE = 'insufficient_privilege', HINT = 'Registre una nueva fila (corrección o anulación) en lugar de modificar.';
END $$;


-- ---------- Tablas -----------------------------------------------------------------
-- EMO · medical_exams (company)
CREATE TABLE IF NOT EXISTS medical_exams (
  id uuid NOT NULL DEFAULT uuidv7(),
  company_id uuid NOT NULL,
  worker_id uuid NOT NULL,
  medical_exam_order_ref uuid,
  exam_type text NOT NULL CHECK (exam_type IN ('pre_employment', 'periodic', 'exit', 'return_to_work', 'position_change', 'other')),
  exam_on date NOT NULL,
  clinic_name text,
  clinic_ruc char(11),
  examining_doctor_name text,
  examining_doctor_cmp text,
  protocol_code text,
  position_text text,
  exams_performed text[],
  results_summary text,
  diagnoses jsonb,
  recommendations text,
  fitness_result text NOT NULL DEFAULT 'pending' CHECK (fitness_result IN ('pending', 'fit', 'fit_with_restrictions', 'unfit')),
  work_restrictions text,
  fitness_valid_until date,
  reviewed_by_user_id uuid,
  reviewed_at timestamptz,
  fitness_status_synced_at timestamptz,
  status text NOT NULL DEFAULT 'received' CHECK (status IN ('received', 'under_review', 'validated', 'fitness_issued', 'annulled')),
  annulled_reason text,
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by uuid,
  updated_at timestamptz NOT NULL DEFAULT now(),
  updated_by uuid,
  deleted_at timestamptz,
  PRIMARY KEY (id),
  CONSTRAINT uq_medical_exams_company_id_id UNIQUE (company_id, id),
  CONSTRAINT ck_medical_exams_1 CHECK (fitness_result <> 'fit_with_restrictions' OR work_restrictions IS NOT NULL),
  CONSTRAINT ck_medical_exams_2 CHECK (status <> 'annulled' OR annulled_reason IS NOT NULL)
);
COMMENT ON TABLE medical_exams IS '[EMO] Resultado de EMO (preocupacional, periódico, de retiro…) con aptitud y restricciones; acceso solo médico';
COMMENT ON COLUMN medical_exams.worker_id IS 'workers.id de escudo_main (sin FK entre bases)';
COMMENT ON COLUMN medical_exams.medical_exam_order_ref IS 'medical_exam_orders.id de escudo_main';
COMMENT ON COLUMN medical_exams.examining_doctor_cmp IS 'colegiatura CMP';
COMMENT ON COLUMN medical_exams.results_summary IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN medical_exams.diagnoses IS '[CIFRADO EN APLICACIÓN — llave médica] CIE-10 y descripción';
COMMENT ON COLUMN medical_exams.recommendations IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN medical_exams.work_restrictions IS 'en términos laborales; es lo único que se copia a fitness_status';
COMMENT ON COLUMN medical_exams.reviewed_by_user_id IS 'médico ocupacional (users.id de escudo_main)';

-- EMO · medical_exam_files (company)
CREATE TABLE IF NOT EXISTS medical_exam_files (
  id uuid NOT NULL DEFAULT uuidv7(),
  company_id uuid NOT NULL,
  medical_exam_id uuid NOT NULL,
  worker_id uuid NOT NULL,
  file_kind text NOT NULL CHECK (file_kind IN ('full_report', 'lab_result', 'imaging', 'audiometry', 'spirometry', 'certificate', 'consent', 'other')),
  bucket text NOT NULL,
  object_key text NOT NULL,
  original_name text,
  mime_type text NOT NULL,
  size_bytes bigint NOT NULL,
  sha256 bytea,
  status text NOT NULL DEFAULT 'pending_upload' CHECK (status IN ('pending_upload', 'uploaded', 'scanning', 'available', 'quarantined', 'deleted')),
  uploaded_by_user_id uuid,
  retention_until date,
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by uuid,
  updated_at timestamptz NOT NULL DEFAULT now(),
  updated_by uuid,
  deleted_at timestamptz,
  PRIMARY KEY (id),
  CONSTRAINT uq_medical_exam_files_company_id_id UNIQUE (company_id, id),
  CONSTRAINT ck_medical_exam_files_1 CHECK (size_bytes >= 0)
);
COMMENT ON TABLE medical_exam_files IS '[EMO] Archivo del EMO (informe, resultados, certificado) en el bucket médico de B2';
COMMENT ON COLUMN medical_exam_files.original_name IS '[CIFRADO EN APLICACIÓN — llave médica]';

-- VMD · surveillance_programs (company)
CREATE TABLE IF NOT EXISTS surveillance_programs (
  id uuid NOT NULL DEFAULT uuidv7(),
  company_id uuid NOT NULL,
  code text NOT NULL,
  name text NOT NULL,
  target_risk text NOT NULL CHECK (target_risk IN ('noise', 'respiratory', 'musculoskeletal', 'psychosocial', 'chemical', 'biological', 'radiation', 'cardiovascular', 'visual', 'other')),
  agent_type_code text,
  description text,
  protocol text,
  target_positions uuid[],
  responsible_user_id uuid,
  starts_on date,
  ends_on date,
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('draft', 'active', 'closed')),
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by uuid,
  updated_at timestamptz NOT NULL DEFAULT now(),
  updated_by uuid,
  deleted_at timestamptz,
  PRIMARY KEY (id),
  CONSTRAINT uq_surveillance_programs_company_id_id UNIQUE (company_id, id)
);
COMMENT ON TABLE surveillance_programs IS '[VMD] Programa de vigilancia médica ocupacional (por agente o riesgo)';
COMMENT ON COLUMN surveillance_programs.agent_type_code IS 'agent_types.code de escudo_main';
COMMENT ON COLUMN surveillance_programs.target_positions IS 'positions.id de escudo_main';

-- VMD · surveillance_cases (company)
CREATE TABLE IF NOT EXISTS surveillance_cases (
  id uuid NOT NULL DEFAULT uuidv7(),
  company_id uuid NOT NULL,
  program_id uuid NOT NULL,
  worker_id uuid NOT NULL,
  opened_on date NOT NULL,
  reason text,
  risk_classification text CHECK (risk_classification IN ('low', 'medium', 'high')),
  next_control_on date,
  closed_on date,
  closure_reason text,
  assigned_doctor_user_id uuid,
  delegated_to_user_ids uuid[],
  status text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_follow_up', 'closed')),
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by uuid,
  updated_at timestamptz NOT NULL DEFAULT now(),
  updated_by uuid,
  deleted_at timestamptz,
  PRIMARY KEY (id),
  CONSTRAINT uq_surveillance_cases_company_id_id UNIQUE (company_id, id)
);
COMMENT ON TABLE surveillance_cases IS '[VMD] Caso en vigilancia médica de un trabajador';
COMMENT ON COLUMN surveillance_cases.reason IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN surveillance_cases.closure_reason IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN surveillance_cases.delegated_to_user_ids IS 'personal de salud con delegación por caso';

-- VMD · clinical_notes (company)
CREATE TABLE IF NOT EXISTS clinical_notes (
  id uuid NOT NULL DEFAULT uuidv7(),
  company_id uuid NOT NULL,
  surveillance_case_id uuid,
  worker_id uuid NOT NULL,
  note_type text NOT NULL CHECK (note_type IN ('consultation', 'follow_up', 'evaluation', 'referral', 'other')),
  noted_at timestamptz NOT NULL,
  author_user_id uuid NOT NULL,
  subjective text,
  objective text,
  assessment text,
  plan text,
  diagnoses jsonb,
  is_confidential boolean NOT NULL DEFAULT true,
  amended_note_id uuid,
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by uuid,
  updated_at timestamptz NOT NULL DEFAULT now(),
  updated_by uuid,
  deleted_at timestamptz,
  PRIMARY KEY (id),
  CONSTRAINT uq_clinical_notes_company_id_id UNIQUE (company_id, id)
);
COMMENT ON TABLE clinical_notes IS '[VMD] Nota clínica ocupacional (cifrada con llave médica); solo médico o delegado del caso';
COMMENT ON COLUMN clinical_notes.subjective IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN clinical_notes.objective IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN clinical_notes.assessment IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN clinical_notes.plan IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN clinical_notes.diagnoses IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN clinical_notes.amended_note_id IS 'las correcciones crean una nota nueva';

-- DES · medical_leave_details (company)
CREATE TABLE IF NOT EXISTS medical_leave_details (
  id uuid NOT NULL DEFAULT uuidv7(),
  company_id uuid NOT NULL,
  absence_ref uuid NOT NULL,
  worker_id uuid NOT NULL,
  citt_number text,
  issuing_entity text CHECK (issuing_entity IN ('essalud', 'eps', 'minsa', 'private_clinic', 'other')),
  issuing_doctor_name text,
  issuing_doctor_cmp text,
  diagnosis_code text,
  diagnosis_description text,
  is_work_related boolean NOT NULL DEFAULT false,
  starts_on date NOT NULL,
  ends_on date NOT NULL,
  validated_by_user_id uuid,
  validated_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by uuid,
  updated_at timestamptz NOT NULL DEFAULT now(),
  updated_by uuid,
  deleted_at timestamptz,
  PRIMARY KEY (id),
  CONSTRAINT uq_medical_leave_details_company_id_id UNIQUE (company_id, id),
  CONSTRAINT ck_medical_leave_details_1 CHECK (ends_on >= starts_on)
);
COMMENT ON TABLE medical_leave_details IS '[DES] Detalle médico del descanso (diagnóstico, CITT); la base principal solo guarda fechas en absences';
COMMENT ON COLUMN medical_leave_details.absence_ref IS 'absences.id de escudo_main';
COMMENT ON COLUMN medical_leave_details.citt_number IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN medical_leave_details.issuing_doctor_name IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN medical_leave_details.diagnosis_code IS '[CIFRADO EN APLICACIÓN — llave médica] CIE-10';
COMMENT ON COLUMN medical_leave_details.diagnosis_description IS '[CIFRADO EN APLICACIÓN — llave médica]';

-- VUL · vulnerability_records (company)
CREATE TABLE IF NOT EXISTS vulnerability_records (
  id uuid NOT NULL DEFAULT uuidv7(),
  company_id uuid NOT NULL,
  worker_id uuid NOT NULL,
  vulnerability_type text NOT NULL CHECK (vulnerability_type IN ('pregnancy', 'lactation', 'minor', 'disability', 'chronic_condition', 'elderly', 'other')),
  details text,
  conadis_registration text,
  starts_on date NOT NULL,
  expected_end_on date,
  ends_on date,
  risk_assessment text,
  recommended_measures text,
  work_adjustment_ref uuid,
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'ended')),
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by uuid,
  updated_at timestamptz NOT NULL DEFAULT now(),
  updated_by uuid,
  deleted_at timestamptz,
  PRIMARY KEY (id),
  CONSTRAINT uq_vulnerability_records_company_id_id UNIQUE (company_id, id),
  CONSTRAINT ck_vulnerability_records_1 CHECK (ends_on IS NULL OR ends_on >= starts_on)
);
COMMENT ON TABLE vulnerability_records IS '[VUL] Condición de vulnerabilidad (gestación, menor de edad, discapacidad…) con medidas; acceso solo médico';
COMMENT ON COLUMN vulnerability_records.details IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN vulnerability_records.conadis_registration IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN vulnerability_records.risk_assessment IS '[CIFRADO EN APLICACIÓN — llave médica]';
COMMENT ON COLUMN vulnerability_records.recommended_measures IS 'en términos laborales; origen de work_adjustments en la base principal';
COMMENT ON COLUMN vulnerability_records.work_adjustment_ref IS 'work_adjustments.id de escudo_main';

-- AUD · medical_access_logs (imm, added)
CREATE TABLE IF NOT EXISTS medical_access_logs (
  id uuid NOT NULL DEFAULT uuidv7(),
  company_id uuid NOT NULL,
  user_id uuid NOT NULL,
  role_code text NOT NULL,
  reauth_session_id uuid NOT NULL,
  worker_id uuid,
  resource text NOT NULL CHECK (resource IN ('medical_exam', 'medical_file', 'clinical_note', 'leave_detail', 'vulnerability_record', 'surveillance_case', 'surveillance_program', 'medical_export')),
  resource_id uuid,
  action text NOT NULL CHECK (action IN ('read', 'download', 'export', 'create', 'update', 'print')),
  purpose text,
  ip inet,
  occurred_at timestamptz NOT NULL DEFAULT now(),
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by uuid,
  PRIMARY KEY (id),
  CONSTRAINT uq_medical_access_logs_company_id_id UNIQUE (company_id, id)
);
COMMENT ON TABLE medical_access_logs IS '[AUD] Registro de accesos de la propia base médica (inalterable), espejo de sensitive_access_logs · Tabla de soporte agregada (fuera del catálogo). · INALTERABLE: solo INSERT.';
COMMENT ON COLUMN medical_access_logs.reauth_session_id IS 'sessions_mfa.id de escudo_main';

-- ---------- Unicidades e índices ---------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_medical_exams_1 ON medical_exams (company_id, worker_id, exam_on);
CREATE INDEX IF NOT EXISTS idx_medical_exams_worker_id ON medical_exams (company_id, worker_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_medical_exam_files_1 ON medical_exam_files (object_key);
CREATE UNIQUE INDEX IF NOT EXISTS uq_surveillance_programs_1 ON surveillance_programs (company_id, code) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_surveillance_cases_1 ON surveillance_cases (company_id, program_id, worker_id) WHERE (status <> 'closed') AND deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_surveillance_cases_next_control_on ON surveillance_cases (company_id, next_control_on);
CREATE UNIQUE INDEX IF NOT EXISTS uq_medical_leave_details_1 ON medical_leave_details (company_id, absence_ref) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_medical_access_logs_1 ON medical_access_logs (company_id, occurred_at);
CREATE INDEX IF NOT EXISTS idx_medical_access_logs_2 ON medical_access_logs (company_id, worker_id);

-- ---------- Claves foráneas internas de la base médica (compuestas) ---------------
ALTER TABLE medical_exam_files ADD CONSTRAINT fk_medical_exam_files_medical_exam_id FOREIGN KEY (company_id, medical_exam_id) REFERENCES medical_exams (company_id, id);
CREATE INDEX IF NOT EXISTS idx_medical_exam_files_fk_medical_exam_id ON medical_exam_files (company_id, medical_exam_id);
ALTER TABLE surveillance_cases ADD CONSTRAINT fk_surveillance_cases_program_id FOREIGN KEY (company_id, program_id) REFERENCES surveillance_programs (company_id, id);
CREATE INDEX IF NOT EXISTS idx_surveillance_cases_fk_program_id ON surveillance_cases (company_id, program_id);
ALTER TABLE clinical_notes ADD CONSTRAINT fk_clinical_notes_surveillance_case_id FOREIGN KEY (company_id, surveillance_case_id) REFERENCES surveillance_cases (company_id, id);
CREATE INDEX IF NOT EXISTS idx_clinical_notes_fk_surveillance_case_id ON clinical_notes (company_id, surveillance_case_id);
ALTER TABLE clinical_notes ADD CONSTRAINT fk_clinical_notes_amended_note_id FOREIGN KEY (company_id, amended_note_id) REFERENCES clinical_notes (company_id, id);
CREATE INDEX IF NOT EXISTS idx_clinical_notes_fk_amended_note_id ON clinical_notes (company_id, amended_note_id);

-- ---------- Triggers estándar ------------------------------------------------------
CREATE TRIGGER trg_medical_exams_updated_at BEFORE UPDATE ON medical_exams FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
CREATE TRIGGER trg_medical_exam_files_updated_at BEFORE UPDATE ON medical_exam_files FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
CREATE TRIGGER trg_surveillance_programs_updated_at BEFORE UPDATE ON surveillance_programs FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
CREATE TRIGGER trg_surveillance_cases_updated_at BEFORE UPDATE ON surveillance_cases FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
CREATE TRIGGER trg_clinical_notes_updated_at BEFORE UPDATE ON clinical_notes FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
CREATE TRIGGER trg_medical_leave_details_updated_at BEFORE UPDATE ON medical_leave_details FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
CREATE TRIGGER trg_vulnerability_records_updated_at BEFORE UPDATE ON vulnerability_records FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
CREATE TRIGGER trg_medical_access_logs_immutable BEFORE UPDATE OR DELETE ON medical_access_logs FOR EACH STATEMENT EXECUTE FUNCTION trg_forbid_mutation();
CREATE TRIGGER trg_medical_access_logs_no_truncate BEFORE TRUNCATE ON medical_access_logs FOR EACH STATEMENT EXECUTE FUNCTION trg_forbid_mutation();

-- ---------- Funciones y políticas adicionales de escudo_medical (escritas a mano; las incluye tools/db/generar.py) ----------

-- Defensa en profundidad: toda consulta a tablas clínicas exige que la API haya fijado la sesión de re-autenticación
-- (SET LOCAL app.reauth_session_id) además de app.company_id. La API la fija solo con token médico vigente.
CREATE OR REPLACE FUNCTION public.app_reauth_session_id() RETURNS uuid
LANGUAGE sql STABLE PARALLEL SAFE AS $$ SELECT NULLIF(current_setting('app.reauth_session_id', true), '')::uuid $$;

-- Registra un acceso en medical_access_logs con el contexto actual (la API lo llama en cada lectura/escritura médica).
CREATE OR REPLACE FUNCTION public.med_log_access(p_resource text, p_resource_id uuid, p_action text, p_worker_id uuid,
                                                 p_role_code text, p_purpose text DEFAULT NULL, p_ip inet DEFAULT NULL)
RETURNS uuid
LANGUAGE plpgsql AS $$
DECLARE v_id uuid;
BEGIN
  IF app_reauth_session_id() IS NULL OR app_user_id() IS NULL THEN
    RAISE EXCEPTION 'acceso médico sin re-autenticación' USING ERRCODE = 'insufficient_privilege';
  END IF;
  INSERT INTO medical_access_logs (company_id, user_id, role_code, reauth_session_id, worker_id, resource, resource_id, action, purpose, ip)
  VALUES (app_company_id(), app_user_id(), p_role_code, app_reauth_session_id(), p_worker_id, p_resource, p_resource_id, p_action, p_purpose, p_ip)
  RETURNING id INTO v_id;
  RETURN v_id;
END $$;

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['medical_exams','medical_exam_files','surveillance_programs','surveillance_cases',
                           'clinical_notes','medical_leave_details','vulnerability_records'] LOOP
    EXECUTE format('CREATE POLICY rls_%1$s_reauth ON %1$I AS RESTRICTIVE TO escudo_med_app USING (app_reauth_session_id() IS NOT NULL) WITH CHECK (app_reauth_session_id() IS NOT NULL)', t);
  END LOOP;
END $$;

-- ---------- Seguridad a nivel de fila (RLS forzado) y privilegios ------------------
-- Política tipo: rls_<tabla>_company USING/WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid).
-- Sin app.company_id fijado la expresión es NULL y no pasa ninguna fila (no hay valor por defecto permisivo).
ALTER TABLE medical_exams ENABLE ROW LEVEL SECURITY;
ALTER TABLE medical_exams FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_medical_exams_company ON medical_exams TO escudo_med_app USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid) WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
GRANT SELECT, INSERT, UPDATE ON medical_exams TO escudo_med_app;

ALTER TABLE medical_exam_files ENABLE ROW LEVEL SECURITY;
ALTER TABLE medical_exam_files FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_medical_exam_files_company ON medical_exam_files TO escudo_med_app USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid) WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
GRANT SELECT, INSERT, UPDATE ON medical_exam_files TO escudo_med_app;

ALTER TABLE surveillance_programs ENABLE ROW LEVEL SECURITY;
ALTER TABLE surveillance_programs FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_surveillance_programs_company ON surveillance_programs TO escudo_med_app USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid) WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
GRANT SELECT, INSERT, UPDATE ON surveillance_programs TO escudo_med_app;

ALTER TABLE surveillance_cases ENABLE ROW LEVEL SECURITY;
ALTER TABLE surveillance_cases FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_surveillance_cases_company ON surveillance_cases TO escudo_med_app USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid) WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
GRANT SELECT, INSERT, UPDATE ON surveillance_cases TO escudo_med_app;

ALTER TABLE clinical_notes ENABLE ROW LEVEL SECURITY;
ALTER TABLE clinical_notes FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_clinical_notes_company ON clinical_notes TO escudo_med_app USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid) WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
GRANT SELECT, INSERT, UPDATE ON clinical_notes TO escudo_med_app;

ALTER TABLE medical_leave_details ENABLE ROW LEVEL SECURITY;
ALTER TABLE medical_leave_details FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_medical_leave_details_company ON medical_leave_details TO escudo_med_app USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid) WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
GRANT SELECT, INSERT, UPDATE ON medical_leave_details TO escudo_med_app;

ALTER TABLE vulnerability_records ENABLE ROW LEVEL SECURITY;
ALTER TABLE vulnerability_records FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_vulnerability_records_company ON vulnerability_records TO escudo_med_app USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid) WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
GRANT SELECT, INSERT, UPDATE ON vulnerability_records TO escudo_med_app;

ALTER TABLE medical_access_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE medical_access_logs FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_medical_access_logs_company ON medical_access_logs FOR SELECT TO escudo_med_app USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
CREATE POLICY rls_medical_access_logs_company_insert ON medical_access_logs FOR INSERT TO escudo_med_app WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
GRANT SELECT, INSERT ON medical_access_logs TO escudo_med_app;
REVOKE UPDATE, DELETE, TRUNCATE ON medical_access_logs FROM escudo_med_app;

-- ---------- Propiedad de los objetos -------------------------------------------
DO $$
DECLARE r record;
BEGIN
  FOR r IN SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
           WHERE n.nspname = 'public' AND c.relkind IN ('r','p') LOOP
    EXECUTE format('ALTER TABLE %I OWNER TO escudo_med_owner', r.relname);
  END LOOP;
END $$;
-- Fin del esquema.
