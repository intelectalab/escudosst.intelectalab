/* Datos de demostración. Todo es inventado: personas, DNI, RUC y cifras. */
const HOY = new Date(2026, 9, 30, 9, 40); // 30/10/2026, America/Lima
const fmtF = (d) => { if (typeof d === 'string') return d; return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear(); };
const masDias = (n) => { const d = new Date(HOY); d.setDate(d.getDate() + n); return fmtF(d); };

const PLANES = { E: 'Esencial', P: 'Profesional', C: 'Corporativo' };
const COMPLEMENTOS = [
  ['OFF', 'App móvil offline', 'Registro sin señal y sincronización'],
  ['IAA', 'Asistente IA', 'IPERC sugerido e investigación guiada'],
  ['PSE', 'Paquetes sectoriales', 'Plantillas y formatos por sector'],
  ['MOC', 'Gestión del cambio', 'Evaluar riesgos antes de cambios'],
  ['ISO', 'Sistema integrado ISO', 'ISO 45001, 9001 y 14001'],
  ['AMB', 'Medio ambiente (SSOMA)', 'Aspectos ambientales y residuos'],
  ['HOM', 'Homologación de proveedores', 'Evaluación de proveedores'],
];

const EMPRESAS = [
  { id: 'andinas', nombre: 'Industrias Andinas del Sur S.A.C.', corto: 'Industrias Andinas', ruc: '20518472632', sector: 'Manufactura (envases metálicos)', trab: 140, plan: 'P', addons: ['OFF', 'IAA', 'PSE'], cumpl: 78, alertas: 9, sedes: 3, figura: 'Comité SST', resp: 'Rocío Villanueva Tapia' },
  { id: 'huaylas', nombre: 'Constructora Huaylas Ingenieros S.A.C.', corto: 'Constructora Huaylas', ruc: '20609182742', sector: 'Construcción', trab: 86, plan: 'C', addons: ['PSE', 'OFF'], cumpl: 64, alertas: 17, sedes: 4, figura: 'Comité SST', resp: 'Óscar Lizárraga Poma' },
  { id: 'chincha', nombre: 'AgroExport Valle de Chincha S.A.', corto: 'AgroExport Chincha', ruc: '20482371567', sector: 'Agroindustria', trab: 18, plan: 'E', addons: [], cumpl: 52, alertas: 6, sedes: 1, figura: 'Supervisor SST', resp: 'Milagros Chumpitaz Avalos' },
  { id: 'santarosa', nombre: 'Clínica Santa Rosa de Lima S.A.C.', corto: 'Clínica Santa Rosa', ruc: '20559013481', sector: 'Salud (clínica)', trab: 64, plan: 'P', addons: ['IAA'], cumpl: 88, alertas: 3, sedes: 2, figura: 'Comité SST', resp: 'Diana Paucar Reátegui' },
  // Empresa demo independiente (no es cliente de la consultora): instituto que además vende cursos
  { id: 'qhaliy', nombre: 'Instituto Qhaliy de Seguridad Ocupacional S.A.C.', corto: 'Instituto Qhaliy', ruc: '20611748290', sector: 'Capacitación en SST', trab: 22, plan: 'P', addons: [], cumpl: 84, alertas: 2, sedes: 1, figura: 'Comité SST', resp: 'Maribel Sotomayor Quiroz', propia: true },
];
const EMPRESAS_CONSULTORA = ['andinas', 'huaylas', 'chincha', 'santarosa'];
const EMPRESAS_DEMO = ['andinas', 'qhaliy'];
// Activación por empresa dentro del plan (AUL y VTA). VTA viene desactivado por defecto.
const FEAT_INICIAL = { andinas: { AUL: true, VTA: false }, qhaliy: { AUL: true, VTA: true }, huaylas: { AUL: true, VTA: false }, chincha: { AUL: false, VTA: false }, santarosa: { AUL: false, VTA: false } };
const FEAT_MODS = ['AUL', 'VTA'];
const CONSULTORA = { nombre: 'Prevencia Consultores SST', ruc: '20602114571', color: 'verde azulado' };

const SEDES = [
  { id: 'ate', nombre: 'Planta Ate', dir: 'Av. Nicolás Ayllón 4820, Ate, Lima', trab: 98, tipo: 'Planta de producción', resp: 'Jorge Quispe Huamán' },
  { id: 'callao', nombre: 'Almacén Callao', dir: 'Av. Néstor Gambetta 1265, Callao', trab: 27, tipo: 'Almacén y despacho', resp: 'Wilmer Ccahuana Rojas' },
  { id: 'sanisidro', nombre: 'Oficina San Isidro', dir: 'Calle Las Begonias 441, of. 702, San Isidro', trab: 15, tipo: 'Oficina administrativa', resp: 'Patricia Salazar Loayza' },
];
const AREAS = [
  ['Producción', 'ate', 54], ['Mantenimiento', 'ate', 16], ['Calidad', 'ate', 9], ['Logística y almacén', 'callao', 22], ['Despacho', 'callao', 5],
  ['Seguridad y Salud en el Trabajo', 'ate', 4], ['Recursos Humanos', 'sanisidro', 5], ['Administración y finanzas', 'sanisidro', 7], ['Comercial', 'sanisidro', 3], ['Servicios generales', 'ate', 15],
];
const PUESTOS = [
  ['Operador de prensa troqueladora', 'Producción', 22, 'Alto'], ['Soldador', 'Producción', 10, 'Alto'], ['Operador de línea de barnizado', 'Producción', 12, 'Medio'],
  ['Técnico electricista', 'Mantenimiento', 6, 'Alto'], ['Mecánico de mantenimiento', 'Mantenimiento', 8, 'Alto'], ['Operador de montacargas', 'Logística y almacén', 9, 'Alto'],
  ['Almacenero', 'Logística y almacén', 13, 'Medio'], ['Inspector de calidad', 'Calidad', 9, 'Bajo'], ['Asistente administrativo', 'Administración y finanzas', 7, 'Bajo'], ['Supervisor de producción', 'Producción', 5, 'Medio'],
];

// [id, nombres, apellidos, dni, sede, área, puesto, ingreso, inducción, emo: [aptitud, vence], sctr vence (dias), capacitación (ok|warn|bad), epp (ok|warn|bad), teléfono]
const TRABAJADORES = [
  [1, 'Rocío', 'Villanueva Tapia', '41827365', 'ate', 'Seguridad y Salud en el Trabajo', 'Jefa de SST', '12/03/2019', 'ok', ['Apto', 210], 150, 'ok', 'ok'],
  [2, 'Jorge Luis', 'Quispe Huamán', '43918274', 'ate', 'Producción', 'Supervisor de producción', '05/06/2017', 'ok', ['Apto', 95], 150, 'ok', 'ok'],
  [3, 'Carmen Rosa', 'Mamani Condori', '45120983', 'ate', 'Producción', 'Operador de prensa troqueladora', '18/01/2021', 'ok', ['Apto con restricciones', 40], 150, 'warn', 'ok'],
  [4, 'Luis Alberto', 'Huanca Flores', '46239187', 'ate', 'Producción', 'Soldador', '02/09/2020', 'ok', ['Apto', 12], 150, 'ok', 'warn'],
  [5, 'Edwin', 'Chávez Ramos', '70123498', 'ate', 'Mantenimiento', 'Técnico electricista', '11/11/2022', 'ok', ['Apto', 180], 150, 'ok', 'ok'],
  [6, 'Yesenia', 'Torres Vílchez', '72398412', 'ate', 'Calidad', 'Inspector de calidad', '20/02/2023', 'ok', ['Apto', 260], 150, 'ok', 'ok'],
  [7, 'Wilmer', 'Ccahuana Rojas', '42871903', 'callao', 'Logística y almacén', 'Jefe de almacén', '03/04/2018', 'ok', ['Apto', 75], 150, 'ok', 'ok'],
  [8, 'Kevin Alexander', 'Sánchez Paredes', '74561238', 'callao', 'Logística y almacén', 'Operador de montacargas', '15/08/2024', 'ok', ['Apto', -6], 150, 'warn', 'ok'],
  [9, 'Patricia', 'Salazar Loayza', '40192837', 'sanisidro', 'Recursos Humanos', 'Jefa de Recursos Humanos', '01/02/2016', 'ok', ['Apto', 300], 150, 'ok', 'ok'],
  [10, 'Martín', 'Gutiérrez Aliaga', '09876152', 'sanisidro', 'Administración y finanzas', 'Gerente general', '10/10/2012', 'ok', ['Apto', 120], 150, 'ok', 'ok'],
  [11, 'Juana', 'Apaza Choque', '44782301', 'ate', 'Producción', 'Operador de línea de barnizado', '07/05/2019', 'ok', ['Apto', 22], 150, 'ok', 'ok'],
  [12, 'Ricardo', 'Espinoza Lazo', '47012389', 'ate', 'Mantenimiento', 'Mecánico de mantenimiento', '14/07/2021', 'ok', ['No apto', 30], 150, 'bad', 'warn'],
  [13, 'Milagros', 'Pacheco Ugarte', '73029184', 'ate', 'Producción', 'Operador de prensa troqueladora', '01/03/2025', 'pend', ['Pendiente', 0], 150, 'bad', 'ok'],
  [14, 'Félix', 'Ramírez Ticona', '41092385', 'callao', 'Despacho', 'Almacenero', '19/09/2018', 'ok', ['Apto', 160], 150, 'ok', 'ok'],
  [15, 'Sandra', 'Medina Cárdenas', '45981230', 'ate', 'Seguridad y Salud en el Trabajo', 'Médica ocupacional', '06/01/2022', 'ok', ['Apto', 230], 150, 'ok', 'ok'],
  [16, 'Héctor', 'Llanos Bustamante', '42019384', 'ate', 'Producción', 'Soldador', '25/11/2019', 'ok', ['Apto', 64], 150, 'ok', 'ok'],
  [17, 'Gladys', 'Ccori Mendoza', '46820193', 'ate', 'Servicios generales', 'Auxiliar de limpieza', '08/08/2022', 'ok', ['Apto', 140], 150, 'warn', 'ok'],
  [18, 'Christian', 'Valdivia Neyra', '71230984', 'ate', 'Producción', 'Operador de prensa troqueladora', '13/02/2024', 'ok', ['Apto', 8], 150, 'ok', 'bad'],
  [19, 'Rosa Elvira', 'Huamaní Quispe', '43120987', 'ate', 'Calidad', 'Inspector de calidad', '30/06/2020', 'ok', ['Apto', 110], 150, 'ok', 'ok'],
  [20, 'Daniel', 'Arévalo Saavedra', '70981236', 'callao', 'Logística y almacén', 'Operador de montacargas', '21/03/2023', 'ok', ['Apto', 50], 150, 'ok', 'ok'],
  [21, 'Lucía', 'Fernández Olaya', '72190834', 'sanisidro', 'Comercial', 'Ejecutiva comercial', '02/05/2023', 'ok', ['Apto', 190], 150, 'ok', 'ok'],
  [22, 'Alejandro', 'Zevallos Tello', '44019823', 'ate', 'Mantenimiento', 'Técnico electricista', '17/10/2017', 'ok', ['Apto', 4], 150, 'warn', 'ok'],
  [23, 'Nelly', 'Quispe Mamani', '45091283', 'ate', 'Producción', 'Operador de línea de barnizado', '09/09/2021', 'ok', ['Apto', 170], 150, 'ok', 'ok'],
  [24, 'Percy', 'Rojas Inga', '40981273', 'ate', 'Servicios generales', 'Vigilante', '04/04/2016', 'ok', ['Apto', 85], 150, 'ok', 'ok'],
  [25, 'Karina', 'Lozano Huerta', '71982341', 'sanisidro', 'Administración y finanzas', 'Asistente administrativo', '28/01/2024', 'ok', ['Apto', 240], 150, 'ok', 'ok'],
  [26, 'Teodoro', 'Cusi Achahui', '42918370', 'ate', 'Producción', 'Soldador', '12/12/2018', 'ok', ['Apto con restricciones', 130], 150, 'ok', 'ok'],
  [27, 'Brenda', 'Ayala Peña', '74102938', 'callao', 'Logística y almacén', 'Almacenero', '10/06/2025', 'ok', ['Apto', 220], -3, 'ok', 'ok'],
  [28, 'Roberto', 'Gamarra Vela', '43820197', 'ate', 'Mantenimiento', 'Mecánico de mantenimiento', '22/07/2019', 'ok', ['Apto', 58], 150, 'ok', 'ok'],
  [29, 'Ana María', 'Castillo Rengifo', '46102938', 'ate', 'Calidad', 'Jefa de calidad', '15/03/2018', 'ok', ['Apto', 200], 150, 'ok', 'ok'],
  [30, 'Elmer', 'Puma Sullca', '47829130', 'ate', 'Producción', 'Operador de prensa troqueladora', '01/09/2022', 'ok', ['Apto', 36], 150, 'ok', 'ok'],
  [31, 'Silvia', 'Ochoa Cáceres', '41092837', 'sanisidro', 'Recursos Humanos', 'Analista de planillas', '05/05/2020', 'ok', ['Apto', 150], 150, 'ok', 'ok'],
  [32, 'Fernando', 'Benites Carhuas', '70293817', 'callao', 'Despacho', 'Chofer repartidor', '17/02/2023', 'ok', ['Apto', 18], 25, 'ok', 'ok'],
  [33, 'Mariela', 'Rivas Contreras', '72839104', 'ate', 'Producción', 'Operador de línea de barnizado', '03/10/2025', 'pend', ['Apto', 330], 150, 'warn', 'ok'],
  [34, 'Julio César', 'Tupac Yupanqui Ríos', '44829103', 'ate', 'Producción', 'Supervisor de producción', '19/04/2017', 'ok', ['Apto', 102], 150, 'ok', 'ok'],
  [35, 'Verónica', 'Palomino Sotelo', '45910283', 'ate', 'Seguridad y Salud en el Trabajo', 'Enfermera ocupacional', '11/08/2021', 'ok', ['Apto', 175], 150, 'ok', 'ok'],
  [36, 'Hugo', 'Calderón Meza', '40829173', 'callao', 'Logística y almacén', 'Almacenero', '08/03/2019', 'ok', ['Apto', 66], 150, 'ok', 'warn'],
  [37, 'Liliana', 'Vargas Huamán', '73918204', 'ate', 'Producción', 'Operador de prensa troqueladora', '24/06/2024', 'ok', ['Apto', 205], 150, 'ok', 'ok'],
  [38, 'Wálter', 'Inga Chuquimango', '42091837', 'ate', 'Mantenimiento', 'Técnico electricista', '30/11/2020', 'ok', ['Apto', 90], 150, 'ok', 'ok'],
  [39, 'Gabriela', 'Montoya Díaz', '71029384', 'ate', 'Seguridad y Salud en el Trabajo', 'Supervisora SST de campo', '14/01/2023', 'ok', ['Apto', 250], 150, 'ok', 'ok'],
  [40, 'Segundo', 'Huamán Villena', '41982730', 'ate', 'Producción', 'Soldador', '06/02/2016', 'ok', ['Apto', 15], 150, 'ok', 'ok'],
];
const W = TRABAJADORES.map((t) => ({ id: t[0], nombres: t[1], apellidos: t[2], nombre: t[1] + ' ' + t[2], dni: t[3], sede: t[4], area: t[5], puesto: t[6], ingreso: t[7], induccion: t[8], aptitud: t[9][0], emoDias: t[9][1], sctrDias: t[10], cap: t[11], epp: t[12] }));
const wById = (id) => W.find((w) => w.id === id);
const sedeNom = (id) => (SEDES.find((s) => s.id === id) || {}).nombre || id;
const iniciales = (n) => n.split(' ').filter(Boolean).slice(0, 2).map((x) => x[0]).join('');

const COMITE = [
  [10, 'Presidente', 'Empleador', '01/03/2025'], [9, 'Miembro titular', 'Empleador', '01/03/2025'], [29, 'Miembro titular', 'Empleador', '01/03/2025'],
  [2, 'Secretario', 'Trabajadores', '01/03/2025'], [11, 'Miembro titular', 'Trabajadores', '01/03/2025'], [7, 'Miembro titular', 'Trabajadores', '01/03/2025'],
];

// Usuario mostrado por rol
const USUARIOS = {
  platform_owner: ['Equipo EscudoSST', 'Dueño de la plataforma', 'AM'],
  platform_support: ['Daniela Soto Ruiz', 'Soporte de la plataforma', 'DS'],
  account_admin: ['Martín Gutiérrez Aliaga', 'Administrador de cuenta', null],
  consultant: ['Renzo Cárdenas Ibarra', 'Consultor SST · Prevencia', 'RC'],
  sst_manager: ['Rocío Villanueva Tapia', 'Jefa SST', null],
  sst_supervisor: ['Gabriela Montoya Díaz', 'Supervisora SST de campo', null],
  committee_member: ['Juana Apaza Choque', 'Miembro del Comité SST', null],
  management: ['Martín Gutiérrez Aliaga', 'Gerente general', null],
  area_lead: ['Jorge Luis Quispe Huamán', 'Jefe de Producción', null],
  occupational_doctor: ['Dra. Sandra Medina Cárdenas', 'Médica ocupacional · CMP 058213', null],
  health_staff: ['Verónica Palomino Sotelo', 'Enfermera ocupacional', null],
  hr: ['Patricia Salazar Loayza', 'Jefa de Recursos Humanos', null],
  trainer: ['Raúl Paredes Ugaz', 'Capacitador SST', 'RP'],
  external_learner: ['Luz Marina Quispe Ortiz', 'Participante externo · Instituto Qhaliy', 'LQ'],
  worker: ['Carmen Rosa Mamani Condori', 'Operadora de prensa', null],
  contractor_admin: ['Eduardo Ponce Retamozo', 'Montajes Eléctricos Rímac E.I.R.L.', 'EP'],
  external_auditor: ['Mónica Herrera Bazán', 'Auditora externa (acceso hasta 15/11/2026)', 'MH'],
};

// Módulos visibles por rol ('*' = todos los módulos de empresa)
const ROL_MODULOS = {
  platform_owner: ['SUS', 'AUD'],
  platform_support: ['SUS', 'AUD', 'USR', 'TEN'],
  account_admin: '*',
  consultant: '*',
  sst_manager: '*',
  sst_supervisor: ['DSH', 'NOT', 'DOC', 'TRB', 'FIR', 'LBA', 'POL', 'PLA', 'PAS', 'PCA', 'RIS', 'COM', 'IPC', 'MAP', 'MAG', 'PSI', 'PET', 'MOC', 'INS', 'ACT', 'CH5', 'ATS', 'PTR', 'OBS', 'EPP', 'EQE', 'SIM', 'CAP', 'AUL', 'FOT', 'EMO', 'ACC', 'INV', 'ACR', 'AUI', 'EST', 'REG', 'CTR', 'SCT', 'ING', 'SUN', 'OFF', 'IAA', 'PSE', 'AMB'],
  committee_member: ['DSH', 'NOT', 'DOC', 'FIR', 'POL', 'PLA', 'PAS', 'PCA', 'RIS', 'COM', 'IPC', 'MAP', 'INS', 'ACT', 'ACC', 'INV', 'ACR', 'EST', 'REG'],
  management: ['DSH', 'NOT', 'DOC', 'FIR', 'POL', 'PLA', 'PAS', 'PRE', 'COM', 'ACR', 'AUI', 'EST', 'RVD', 'SUN'],
  area_lead: ['DSH', 'NOT', 'DOC', 'FIR', 'IPC', 'PET', 'INS', 'ACT', 'CH5', 'ATS', 'PTR', 'OBS', 'EPP', 'CAP', 'ACC', 'ACR', 'OFF'],
  occupational_doctor: ['DSH', 'NOT', 'TRB', 'EMO', 'VMD', 'DES', 'VUL', 'ACC', 'INV', 'AUD'],
  health_staff: ['NOT', 'TRB', 'EMO', 'VMD', 'DES', 'CAP'],
  hr: ['DSH', 'NOT', 'TEN', 'TRB', 'DOC', 'FIR', 'RIS', 'CAP', 'AUL', 'VTA', 'FOT', 'EMO', 'DES', 'VUL', 'EPP', 'SCT', 'API'],
  trainer: ['AUL', 'CAP', 'NOT'],
  external_learner: [],
  worker: [],
  contractor_admin: ['CTR', 'SCT', 'ING', 'NOT'],
  external_auditor: ['DSH', 'DOC', 'MRL', 'IPC', 'INS', 'CAP', 'AUL', 'ACC', 'INV', 'ACR', 'AUI', 'EST', 'REG'],
};
const ROL_SOLO_LECTURA = ['external_auditor', 'management', 'platform_support'];

const NOTIFICACIONES = [
  { t: 'EMO vencido: Kevin Sánchez Paredes', d: 'Operador de montacargas · Almacén Callao', h: 'Hace 20 min', s: 'bad', r: 'm-emo', u: 1 },
  { t: 'Accidente leve registrado en Planta Ate', d: 'Corte en mano izquierda · Línea de prensas 3', h: 'Hace 2 h', s: 'bad', r: 'm-acc', u: 1 },
  { t: 'SCTR por vencer: 2 trabajadores', d: 'Póliza Pacífico Seguros · vence en 25 días', h: 'Hoy 07:00', s: 'warn', r: 'm-sct', u: 1 },
  { t: 'Nuevo reporte por QR en Almacén Callao', d: 'Condición: derrame de aceite en zona de despacho', h: 'Ayer 18:42', s: 'warn', r: 'm-act', u: 0 },
  { t: 'IPERC «Soldador» enviado a aprobación', d: 'Versión 4 · por Gabriela Montoya', h: 'Ayer 16:10', s: 'info', r: 'm-ipc', u: 0 },
  { t: 'Reunión ordinaria del Comité SST', d: 'Jueves 05/11 · 15:00 · Sala de directorio Planta Ate', h: '28/10', s: 'info', r: 'm-com', u: 0 },
];

const ACTIVIDAD = [
  ['Gabriela Montoya', 'cerró la inspección «Orden y limpieza – Prensas»', 'Hace 35 min'],
  ['Jorge Quispe', 'registró la charla de 5 minutos «Uso de guardas»', 'Hace 1 h'],
  ['Sistema', 'envió 12 recordatorios de capacitación por WhatsApp', 'Hace 2 h'],
  ['Patricia Salazar', 'cargó 3 trabajadores nuevos desde Excel', 'Ayer 17:20'],
  ['Comité SST', 'aprobó el Programa anual de capacitación 2026', '27/10/2026'],
];

// Acciones correctivas (compartidas por varios módulos)
const ACCIONES = [
  { id: 'AC-2026-118', t: 'Instalar guarda fija en prensa troqueladora N.° 3', o: 'Accidente ACC-2026-014', r: 'Edwin Chávez', v: masDias(4), e: 'En proceso', p: 60 },
  { id: 'AC-2026-117', t: 'Señalizar zona de despacho y colocar bandeja antiderrame', o: 'Reporte QR R-0412', r: 'Wilmer Ccahuana', v: masDias(9), e: 'Abierta', p: 10 },
  { id: 'AC-2026-115', t: 'Reemplazar extintor PQS 6 kg vencido – pasillo B', o: 'Inspección INS-2026-221', r: 'Gabriela Montoya', v: masDias(-2), e: 'Vencida', p: 30 },
  { id: 'AC-2026-112', t: 'Capacitar a soldadores en trabajos en caliente', o: 'Auditoría interna AUI-2026-02', r: 'Rocío Villanueva', v: masDias(18), e: 'En proceso', p: 45 },
  { id: 'AC-2026-109', t: 'Reparar barandas de mezzanine del almacén', o: 'Inspección INS-2026-210', r: 'Hugo Calderón', v: masDias(-6), e: 'Vencida', p: 70 },
  { id: 'AC-2026-104', t: 'Actualizar PETS de cambio de matrices', o: 'Investigación INV-2026-006', r: 'Jorge Quispe', v: masDias(25), e: 'Abierta', p: 0 },
  { id: 'AC-2026-099', t: 'Colocar alfombras antifatiga en línea de barnizado', o: 'Evaluación ergonómica', r: 'Ana María Castillo', v: '15/10/2026', e: 'Cerrada', p: 100 },
];

// QR precalculados (los inserta build.py)
const QR = window.__QR || {};
