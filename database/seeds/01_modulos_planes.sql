-- GENERADO por tools/db/generar_semillas.py — no editar a mano.
-- Ejecutar con un usuario miembro de escudo_platform (los catálogos globales solo los escribe ese rol).
-- Idempotente: ON CONFLICT DO NOTHING / DO UPDATE.
SET client_min_messages = warning;
SET ROLE escudo_platform;
BEGIN;

-- Catálogo de módulos (61)
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('TEN', '1.1', 'Empresas, sedes y áreas', 1, 'E', 0, 'Plataforma', 'Estructura de cada empresa cliente', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('USR', '1.2', 'Usuarios, roles y permisos', 1, 'E', 0, 'Plataforma', 'Acceso por rol, sede y módulo', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('TRB', '1.3', 'Trabajadores y legajo', 1, 'E', 0, 'Mercado', 'Ficha única del trabajador, carga masiva desde Excel', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('DOC', '1.4', 'Gestión documental', 1, 'E', 0, 'Ley', 'Versiones, vigencias y cargos de entrega', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('NOT', '1.5', 'Alertas y notificaciones', 1, 'E', 0, 'Mercado', 'Vencimientos por correo, WhatsApp y app', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('DSH', '1.6', 'Dashboard e indicadores', 1, 'E', 0, 'Mercado', 'Cumplimiento en tiempo real por sede', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('FIR', '1.7', 'Firma electrónica de registros', 1, 'P', 0, 'Mercado', 'Firma de trabajadores y responsables', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('AUD', '1.8', 'Bitácora de auditoría', 1, 'E', 0, 'Plataforma', 'Quién hizo qué y cuándo', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('SUS', '1.9', 'Suscripciones y facturación', 1, 'I', 0, 'Plataforma', 'Planes, cobros y comprobantes (panel del dueño del SaaS)', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('WEB', '1.10', 'Sitio web público y CMS', 1, 'I', 1, 'Plataforma', 'Web de venta de EscudoSST administrable por el dueño: páginas, videos, planes y precios editables, demo, WhatsApp, otros productos, testimonios, SEO, políticas, cookies, Libro de Reclamaciones, enlace al blog en WordPress y herramientas de captación (calculadora de multas, autodiagnóstico, recursos, casos de éxito, centro de ayuda, partners y referidos, boletín, centro de confianza y estado del servicio); métricas básicas, calendario de demos, chatbot, conexión con CRM, biblioteca de medios, portafolio, landings de campaña, ventanas emergentes, integraciones, novedades, webinars, reseñas de Google y calculadora de ahorro', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('LBA', '2.1', 'Diagnóstico de línea base', 2, 'E', 1, 'Ley', 'Brechas iniciales y su cierre', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('POL', '2.2', 'Política y objetivos SST', 2, 'E', 1, 'Ley', 'Versionada, aprobada y difundida', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('MRL', '2.3', 'Matriz de requisitos legales', 2, 'P', 2, 'Ley / ISO', 'Normas aplicables y su cumplimiento', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('PLA', '2.4', 'Plan Anual SST', 2, 'E', 1, 'Ley', 'Objetivos, metas y recursos', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('PAS', '2.5', 'Programa Anual SST (PASST)', 2, 'E', 1, 'Ley', 'Actividades, responsables y % de avance', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('PCA', '2.6', 'Programa anual de capacitación', 2, 'E', 1, 'Ley', 'Plan aprobado por comité', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('RIS', '2.7', 'RISST', 2, 'P', 2, 'Ley (20+ trabajadores)', 'Versiones y cargo de entrega al trabajador', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('COM', '2.8', 'Comité o Supervisor SST', 2, 'E', 1, 'Ley', 'Elección electrónica, actas y acuerdos', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('PRE', '2.9', 'Presupuesto SST', 2, 'C', 4, 'Mercado', 'Costo por actividad y ejecución', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('IPC', '3.1', 'Matriz IPERC', 3, 'E', 1, 'Ley', 'IPERC por puesto y tarea, con versiones', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('MAP', '3.2', 'Mapa de riesgos', 3, 'P', 2, 'Ley', 'Plano con pictogramas vinculado al IPERC', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('MAG', '3.3', 'Monitoreo de agentes', 3, 'P', 2, 'Ley', 'Mediciones frente a límites permisibles', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('PSI', '3.4', 'Riesgos ergonómicos y psicosociales', 3, 'C', 4, 'Ley', 'Evaluaciones y encuestas', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('PET', '3.5', 'PETS y procedimientos', 3, 'C', 4, 'Mercado (campo)', 'Procedimientos escritos por tarea crítica', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('MOC', '3.6', 'Gestión del cambio', 3, '+', 5, 'ISO 45001', 'Evaluar riesgos antes de cambios', true)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('INS', '4.1', 'Inspecciones', 4, 'E', 1, 'Ley', 'Checklists, fotos y ubicación', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('ACT', '4.2', 'Actos y condiciones subestándar', 4, 'E', 1, 'Mercado', 'Reporte por QR o WhatsApp', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('CH5', '4.3', 'Charlas de 5 minutos', 4, 'P', 2, 'Mercado', 'Asistencia con firma', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('ATS', '4.4', 'ATS', 4, 'P', 2, 'Mercado', 'Análisis de trabajo seguro antes de la tarea', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('PTR', '4.5', 'PETAR y permisos de alto riesgo', 4, 'C', 4, 'Mercado (campo)', 'Altura, caliente, espacio confinado, eléctrico', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('OBS', '4.6', 'Observación de conducta segura', 4, 'C', 4, 'Mercado', 'Seguridad basada en el comportamiento', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('EPP', '4.7', 'EPP', 4, 'E', 1, 'Ley', 'Entrega con firma, stock y vencimientos', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('EQE', '4.8', 'Equipos de emergencia y botiquines', 4, 'P', 2, 'Ley', 'Inventario y mantenimiento', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('SIM', '4.9', 'Simulacros y brigadas', 4, 'P', 2, 'Ley', 'Programación y participación', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('CAP', '5.1', 'Capacitaciones e inducciones', 5, 'E', 1, 'Ley', 'Asistencia, evaluaciones y constancias', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('AUL', '5.2', 'Aula virtual SST', 5, 'P', 2, 'Mercado', 'Portal propio de cursos: contenidos, bancos de preguntas, evaluaciones, capacitadores, tiempo de permanencia y recomendación de cursos; Moodle por API opcional', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('FOT', '5.3', 'Fotocheck SST con QR', 5, 'P', 2, 'Diferenciador', 'Credencial verificable en campo', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('EMO', '5.4', 'EMOs y aptitud', 5, 'P', 2, 'Ley', 'Programación, vigencias y restricciones', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('VMD', '5.5', 'Vigilancia médica', 5, 'C', 4, 'Ley', 'Historia ocupacional con acceso restringido (Ley 29733)', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('DES', '5.6', 'Descansos médicos y ausentismo', 5, 'C', 4, 'Mercado', 'Control e indicadores', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('VUL', '5.7', 'Grupos vulnerables', 5, 'C', 4, 'Ley', 'Gestantes, menores y personas con discapacidad', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('VTA', '5.8', 'Venta de cursos y pagos', 5, 'P', 2, 'Mercado', 'Catálogo de venta, pasarela de pagos, cupos para empresas y matrícula automática', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('ACC', '6.1', 'Accidentes, incidentes y enfermedades', 6, 'E', 1, 'Ley', 'Registro y notificación en plazo', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('INV', '6.2', 'Investigación de causas', 6, 'P', 2, 'Ley', 'Árbol de causas o SCAT', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('ACR', '6.3', 'Acciones correctivas', 6, 'E', 1, 'Ley', 'Responsables, plazos y evidencia de cierre', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('AUI', '6.4', 'Auditorías', 6, 'P', 2, 'Ley', 'Internas y externas, con hallazgos', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('EST', '6.5', 'Estadísticas SST', 6, 'E', 1, 'Ley', 'Índices de frecuencia, gravedad y accidentabilidad', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('RVD', '6.6', 'Revisión por la dirección', 6, 'C', 4, 'Ley / ISO', 'Informe anual a gerencia', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('REG', '6.7', 'Registros obligatorios', 6, 'E', 1, 'Ley', 'Los 9 registros en formato R.M. 050-2013-TR', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('CTR', '7.1', 'Acreditación de contratistas', 7, 'C', 4, 'Mercado', 'Documentos, vigencias y aprobación', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('SCT', '7.2', 'Control de SCTR', 7, 'P', 2, 'Ley', 'Pólizas y vigencias por trabajador', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('ING', '7.3', 'Ingreso de terceros por QR', 7, 'C', 4, 'Mercado', 'Habilitado o no habilitado en portería', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('HOM', '7.4', 'Homologación de proveedores', 7, '+', 5, 'Mercado', 'Evaluación y calificación de proveedores', true)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('SUN', '8.1', 'Modo Inspección SUNAFIL', 8, 'P', 2, 'Diferenciador', 'Expediente por trabajador y periodo en minutos', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('OFF', '8.2', 'App móvil offline', 8, '+', 3, 'Diferenciador', 'Registro sin señal y sincronización', true)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('IAA', '8.3', 'Asistente IA', 8, '+', 3, 'Diferenciador', 'IPERC sugerido, investigación guiada, lectura de documentos', true)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('CON', '8.4', 'Modo consultora y marca blanca', 8, 'K', 3, 'Diferenciador', 'Panel multi-cliente con marca propia', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('PSE', '8.5', 'Paquetes sectoriales', 8, '+', 3, 'Diferenciador', 'Plantillas y formatos por sector', true)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('ISO', '8.6', 'Sistema integrado ISO', 8, '+', 5, 'Mercado', 'ISO 45001, 9001 y 14001 en una sola gestión', true)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('AMB', '8.7', 'Medio ambiente (SSOMA)', 8, '+', 5, 'Mercado', 'Aspectos ambientales y residuos', true)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;
INSERT INTO modules (code, number, name, group_number, plan_code, phase, origin, solves, is_addon) VALUES ('API', '8.8', 'API e integraciones', 8, 'C', 4, 'Mercado', 'Planillas, ERP y aulas virtuales', false)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, number = EXCLUDED.number, group_number = EXCLUDED.group_number, plan_code = EXCLUDED.plan_code, phase = EXCLUDED.phase, origin = EXCLUDED.origin, solves = EXCLUDED.solves, is_addon = EXCLUDED.is_addon;

-- Planes (precio_reference NULL = «Por definir»; límites NULL = por definir)
INSERT INTO plans (code, name, description, sort_order, includes_plan_codes, price_reference, is_public) VALUES ('E', 'Esencial', 'Empresas con Supervisor SST (menos de 20 trabajadores): núcleo legal de la Ley 29783.', 1, ARRAY['E']::text[], NULL, true)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, includes_plan_codes = EXCLUDED.includes_plan_codes;
INSERT INTO plans (code, name, description, sort_order, includes_plan_codes, price_reference, is_public) VALUES ('P', 'Profesional', 'Empresas de 20 a 200 trabajadores con Comité SST. Incluye Esencial.', 2, ARRAY['E', 'P']::text[], NULL, true)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, includes_plan_codes = EXCLUDED.includes_plan_codes;
INSERT INTO plans (code, name, description, sort_order, includes_plan_codes, price_reference, is_public) VALUES ('C', 'Corporativo', 'Empresas grandes, varias sedes y contratistas. Incluye Profesional.', 3, ARRAY['E', 'P', 'C']::text[], NULL, true)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, includes_plan_codes = EXCLUDED.includes_plan_codes;
INSERT INTO plans (code, name, description, sort_order, includes_plan_codes, price_reference, is_public) VALUES ('K', 'Consultora', 'Panel multi-cliente y marca blanca; cada empresa cliente mantiene su propio plan. Precio por empresa gestionada (por definir).', 4, '{}'::text[], NULL, true)
  ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, includes_plan_codes = EXCLUDED.includes_plan_codes;

-- Módulos por plan (acumulativo: native = propio del plan, inherited = heredado del plan inferior)
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'TEN', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'USR', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'TRB', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'DOC', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'NOT', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'DSH', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'AUD', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'LBA', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'POL', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PLA', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PAS', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PCA', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'COM', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'IPC', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'INS', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ACT', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'EPP', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'CAP', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ACC', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ACR', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'EST', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'REG', 'native' FROM plans WHERE code = 'E' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'TEN', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'USR', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'TRB', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'DOC', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'NOT', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'DSH', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'FIR', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'AUD', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'LBA', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'POL', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'MRL', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PLA', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PAS', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PCA', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'RIS', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'COM', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'IPC', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'MAP', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'MAG', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'INS', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ACT', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'CH5', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ATS', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'EPP', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'EQE', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'SIM', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'CAP', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'AUL', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'FOT', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'EMO', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'VTA', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ACC', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'INV', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ACR', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'AUI', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'EST', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'REG', 'inherited' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'SCT', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'SUN', 'native' FROM plans WHERE code = 'P' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'TEN', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'USR', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'TRB', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'DOC', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'NOT', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'DSH', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'FIR', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'AUD', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'LBA', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'POL', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'MRL', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PLA', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PAS', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PCA', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'RIS', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'COM', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PRE', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'IPC', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'MAP', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'MAG', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PSI', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PET', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'INS', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ACT', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'CH5', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ATS', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'PTR', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'OBS', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'EPP', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'EQE', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'SIM', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'CAP', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'AUL', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'FOT', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'EMO', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'VMD', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'DES', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'VUL', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'VTA', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ACC', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'INV', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ACR', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'AUI', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'EST', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'RVD', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'REG', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'CTR', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'SCT', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'ING', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'SUN', 'inherited' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'API', 'native' FROM plans WHERE code = 'C' ON CONFLICT DO NOTHING;
INSERT INTO plan_modules (plan_id, module_code, inclusion) SELECT id, 'CON', 'native' FROM plans WHERE code = 'K' ON CONFLICT DO NOTHING;

-- Complementos (contratables con cualquier plan; PSE por paquete sectorial)
INSERT INTO addons (code, name, description, module_code, is_per_sector, available_in_v1, price_reference) VALUES ('MOC', 'Gestión del cambio', 'Evaluar riesgos antes de cambios', 'MOC', false, false, NULL) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, available_in_v1 = EXCLUDED.available_in_v1;
INSERT INTO addons (code, name, description, module_code, is_per_sector, available_in_v1, price_reference) VALUES ('HOM', 'Homologación de proveedores', 'Evaluación y calificación de proveedores', 'HOM', false, false, NULL) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, available_in_v1 = EXCLUDED.available_in_v1;
INSERT INTO addons (code, name, description, module_code, is_per_sector, available_in_v1, price_reference) VALUES ('OFF', 'App móvil offline', 'Registro sin señal y sincronización', 'OFF', false, true, NULL) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, available_in_v1 = EXCLUDED.available_in_v1;
INSERT INTO addons (code, name, description, module_code, is_per_sector, available_in_v1, price_reference) VALUES ('IAA', 'Asistente IA', 'IPERC sugerido, investigación guiada, lectura de documentos', 'IAA', false, true, NULL) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, available_in_v1 = EXCLUDED.available_in_v1;
INSERT INTO addons (code, name, description, module_code, is_per_sector, available_in_v1, price_reference) VALUES ('PSE', 'Paquetes sectoriales', 'Plantillas y formatos por sector', 'PSE', true, true, NULL) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, available_in_v1 = EXCLUDED.available_in_v1;
INSERT INTO addons (code, name, description, module_code, is_per_sector, available_in_v1, price_reference) VALUES ('ISO', 'Sistema integrado ISO', 'ISO 45001, 9001 y 14001 en una sola gestión', 'ISO', false, false, NULL) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, available_in_v1 = EXCLUDED.available_in_v1;
INSERT INTO addons (code, name, description, module_code, is_per_sector, available_in_v1, price_reference) VALUES ('AMB', 'Medio ambiente (SSOMA)', 'Aspectos ambientales y residuos', 'AMB', false, false, NULL) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, available_in_v1 = EXCLUDED.available_in_v1;
COMMIT;
RESET ROLE;
