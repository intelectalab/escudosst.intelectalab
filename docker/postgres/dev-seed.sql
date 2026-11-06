-- Datos ficticios SOLO para desarrollo local (docs/15 §7: «Seeders ficticios»).
-- Empresas tomadas del prototipo (prototipo/README.md). Idempotente: se puede correr varias veces.
--
--   admin@escudo.test         Dueño de la plataforma (superadmin, panel interno)
--
-- Un usuario por plan, cada uno en su propia empresa, con rol Jefe SST (sst_manager).
-- Contraseña de todos: EscudoDemo2026
--
--   esencial@escudo.test      AgroExport Valle de Chincha   plan Esencial    (22 módulos)
--   profesional@escudo.test   Industrias Andinas del Sur    plan Profesional (39 módulos)
--   corporativo@escudo.test   Constructora Huaylas          plan Corporativo (51 módulos)

BEGIN;

-- Cuenta suscriptora (la que paga): un grupo empresarial con sus 3 empresas, cada una con su propio plan
INSERT INTO accounts (id, name, account_type, tax_id_type, tax_id, legal_name, status)
VALUES ('01900000-0000-7000-8000-000000000001', 'Grupo Andinas (demo)', 'group', 'RUC', '20518472632',
        'Industrias Andinas del Sur S.A.C.', 'active')
ON CONFLICT (id) DO NOTHING;

-- Empresas (RUC inventados)
INSERT INTO companies (id, account_id, ruc, legal_name, trade_name, economic_sector, risk_level, headcount_declared,
                       sst_model, fiscal_address, district, province, department)
VALUES ('01900000-0000-7000-8000-000000000103', '01900000-0000-7000-8000-000000000001', '20605478914',
        'AgroExport Valle de Chincha S.A.C.', 'AgroExport Valle de Chincha', 'agroindustry', 'medium', 18, 'supervisor',
        'Carretera Panamericana Sur km 198', 'Chincha Alta', 'Chincha', 'Ica'),
       ('01900000-0000-7000-8000-000000000101', '01900000-0000-7000-8000-000000000001', '20518472632',
        'Industrias Andinas del Sur S.A.C.', 'Industrias Andinas', 'manufacturing', 'high', 140, 'committee',
        'Av. Nicolás Ayllón 1234', 'Ate', 'Lima', 'Lima'),
       ('01900000-0000-7000-8000-000000000102', '01900000-0000-7000-8000-000000000001', '20604875120',
        'Constructora Huaylas S.A.C.', 'Constructora Huaylas', 'construction', 'very_high', 85, 'committee',
        'Jr. Huaraz 456', 'Independencia', 'Huaraz', 'Áncash')
ON CONFLICT (id) DO NOTHING;

-- Una suscripción activa por empresa, cada una con su plan
INSERT INTO subscriptions (id, account_id, company_id, plan_id, status, current_period_start)
SELECT v.id::uuid, '01900000-0000-7000-8000-000000000001', v.company_id::uuid, p.id, 'active', now()
  FROM (VALUES ('01900000-0000-7000-8000-000000000203', '01900000-0000-7000-8000-000000000103', 'E'),
               ('01900000-0000-7000-8000-000000000201', '01900000-0000-7000-8000-000000000101', 'P'),
               ('01900000-0000-7000-8000-000000000202', '01900000-0000-7000-8000-000000000102', 'C')) v(id, company_id, plan)
  JOIN plans p ON p.code = v.plan
ON CONFLICT (id) DO NOTHING;

-- Sede principal, área General, criterios, etc. y feature flags según el plan
SELECT set_config('app.company_id', '01900000-0000-7000-8000-000000000103', true);
SELECT seed_company_defaults('01900000-0000-7000-8000-000000000103');
SELECT set_config('app.company_id', '01900000-0000-7000-8000-000000000101', true);
SELECT seed_company_defaults('01900000-0000-7000-8000-000000000101');
SELECT set_config('app.company_id', '01900000-0000-7000-8000-000000000102', true);
SELECT seed_company_defaults('01900000-0000-7000-8000-000000000102');

-- Usuarios (bcrypt de EscudoDemo2026)
INSERT INTO users (id, email, email_verified_at, full_name, password_hash, status)
VALUES ('01900000-0000-7000-8000-000000000301', 'esencial@escudo.test', now(), 'Lucía Esencial Torres',
        '$2y$12$kbOcrxqHvSMu430p6uK/8eTqrNpgScQ4s3VpsWqOfppFYFogMA8I.', 'active'),
       ('01900000-0000-7000-8000-000000000302', 'profesional@escudo.test', now(), 'Rocío Profesional Villanueva',
        '$2y$12$kbOcrxqHvSMu430p6uK/8eTqrNpgScQ4s3VpsWqOfppFYFogMA8I.', 'active'),
       ('01900000-0000-7000-8000-000000000303', 'corporativo@escudo.test', now(), 'Carlos Corporativo Huamán',
        '$2y$12$kbOcrxqHvSMu430p6uK/8eTqrNpgScQ4s3VpsWqOfppFYFogMA8I.', 'active')
ON CONFLICT (id) DO UPDATE SET email = EXCLUDED.email, full_name = EXCLUDED.full_name,
  password_hash = EXCLUDED.password_hash, status = 'active', failed_login_count = 0, locked_until = NULL,
  document_type = NULL, document_number = NULL;

-- Dueño de la plataforma (superadmin). No pertenece a ninguna empresa: is_platform_staff = true.
-- MFA obligatorio (RN-USR-05). Contraseña: EscudoDemo2026
INSERT INTO users (id, email, email_verified_at, full_name, password_hash, status, is_platform_staff)
VALUES ('01900000-0000-7000-8000-000000000300', 'admin@escudo.test', now(), 'Equipo EscudoSST',
        '$2y$12$kbOcrxqHvSMu430p6uK/8eTqrNpgScQ4s3VpsWqOfppFYFogMA8I.', 'active', true)
ON CONFLICT (id) DO UPDATE SET email = EXCLUDED.email, is_platform_staff = true, status = 'active';

-- Membresías: cada usuario solo en la empresa de su plan
DELETE FROM memberships WHERE id IN ('01900000-0000-7000-8000-000000000401', '01900000-0000-7000-8000-000000000402',
                                     '01900000-0000-7000-8000-000000000403');
INSERT INTO memberships (id, company_id, user_id, role_id, status, accepted_at)
SELECT v.id::uuid, v.company_id::uuid, v.user_id::uuid, r.id, 'active', now()
  FROM (VALUES ('01900000-0000-7000-8000-000000000401', '01900000-0000-7000-8000-000000000103', '01900000-0000-7000-8000-000000000301'),
               ('01900000-0000-7000-8000-000000000402', '01900000-0000-7000-8000-000000000101', '01900000-0000-7000-8000-000000000302'),
               ('01900000-0000-7000-8000-000000000403', '01900000-0000-7000-8000-000000000102', '01900000-0000-7000-8000-000000000303'))
       v(id, company_id, user_id)
  JOIN roles r ON r.code = 'sst_manager' AND r.account_id IS NULL;

COMMIT;
