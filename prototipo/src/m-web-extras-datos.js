/* Módulo WEB · 9 extras de captación (spec/brief-web-extras.md): estado editable en memoria.
   Lo edita el panel «Sitio web» (#m-web/<sección>) y lo lee el sitio público. Nada se guarda.
   Valores legales: parámetros con vigencia y to_confirm=true. Ningún precio de planes. */

/* «Otros productos» de GRUPO DE SOLUCIONES CREATIVAS (site_products): descripción editable, sin URLs inventadas */
WEB.productos = [
  { id: 1, n: 'Plannio', d: 'Gestión de redes sociales y anuncios: calendario, publicación y métricas de tus marcas.', url: '', color: '#7B4FD6', ini: 'PL', on: true },
  { id: 2, n: 'GestionaHub', d: 'Gestión empresarial: procesos, clientes y operación en un solo panel.', url: '', color: '#1764AB', ini: 'GH', on: true },
  { id: 3, n: 'Edunova', d: 'Gestión escolar: matrícula, notas, pagos y comunicación con las familias.', url: '', color: '#E2453C', ini: 'ED', on: true },
  { id: 4, n: 'Yachay Campus', d: 'Plataforma de cursos virtuales para vender y dictar capacitaciones en línea.', url: '', color: '#0E7A70', ini: 'YC', on: true },
  { id: 5, n: 'Qhatu POS', d: 'Punto de venta y ERP comercial: ventas, inventario y comprobantes electrónicos.', url: '', color: '#22A35A', ini: 'QP', on: true },
  { id: 6, n: 'Ayni HR', d: 'Recursos humanos: legajos, asistencia, vacaciones y evaluaciones del personal.', url: '', color: '#F08A24', ini: 'AH', on: true },
  { id: 7, n: 'TaskFlow', d: 'Seguimiento de proyectos y tareas por versiones, responsables y fechas.', url: '', color: '#0D3B6E', ini: 'TF', on: true },
];

/* La ruta /recursos pasa a ser la biblioteca: la redirección antigua al blog apunta ahora desde /guias */
WEB.redirecciones = WEB.redirecciones.map((r) => (r.de === '/recursos' ? { de: '/guias', a: 'https://blog.escudosst.com', tipo: '301', hits: 0 } : r));

/* Menú: desplegable «Recursos» con las herramientas y páginas nuevas */
WEB.menu.splice(WEB.menu.findIndex((m) => m.id === 'blog'), 0, { id: 'recursos', l: 'Recursos', r: 'web-recursos', on: true });

/* Portada: bloque de herramientas gratuitas (después de los sellos) y casos de éxito (antes de comentarios) */
WEB.bloques.splice(WEB.bloques.findIndex((b) => b.id === 'confianza') + 1, 0, { id: 'herramientas', l: 'Herramientas gratuitas', on: true });
WEB.bloques.splice(WEB.bloques.findIndex((b) => b.id === 'testimonios'), 0, { id: 'casos', l: 'Casos de éxito', on: true });

/* Páginas nuevas con SEO editable */
[
  ['web-calculadora', 'Calculadora de multas SUNAFIL', 'Calculadora de multas SUNAFIL · EscudoSST', 'Estima un rango referencial de multa en UIT y soles según el tamaño de tu empresa, la gravedad y los trabajadores afectados.', '/herramientas/calculadora-multas', 'Herramienta'],
  ['web-autodiagnostico', 'Autodiagnóstico Ley 29783', 'Autodiagnóstico gratuito Ley 29783 · EscudoSST', 'Responde un cuestionario por secciones y conoce el nivel de tu sistema de gestión SST. Recibe el informe en PDF por correo.', '/herramientas/autodiagnostico', 'Herramienta'],
  ['web-recursos', 'Biblioteca de recursos', 'Plantillas y formatos SST descargables · EscudoSST', 'Plantillas de IPERC, matriz de capacitación, registros obligatorios y checklists SST para descargar.', '/recursos', 'Biblioteca'],
  ['web-casos', 'Casos de éxito', 'Casos de éxito · EscudoSST', 'Cómo empresas peruanas ordenan su sistema SST con EscudoSST: reto, solución y resultados.', '/casos', 'Casos'],
  ['web-comparativa', 'EscudoSST vs Excel y papel', 'EscudoSST vs Excel y papel · Comparativa', 'Compara gestionar la seguridad y salud en el trabajo con Excel y papel frente a un sistema como EscudoSST.', '/comparativa', 'Comparativa'],
  ['web-ayuda', 'Centro de ayuda', 'Centro de ayuda · EscudoSST', 'Guías paso a paso, videos y respuestas para usar EscudoSST: IPERC, inspecciones, capacitaciones, SUNAFIL y más.', '/ayuda', 'Ayuda'],
  ['web-partners', 'Partners y referidos', 'Programa de partners y referidos · EscudoSST', 'Para consultoras SST, profesionales independientes e institutos: recomienda EscudoSST y haz seguimiento de tus referidos.', '/partners', 'Partners'],
  ['web-boletin', 'Boletín normativo SST', 'Boletín normativo SST · EscudoSST', 'Recibe en tu correo los cambios de normativa SST, novedades de SUNAFIL y guías por sector. Gratis y con baja en un clic.', '/boletin', 'Boletín'],
  ['web-confianza', 'Centro de confianza', 'Centro de confianza · Seguridad y datos personales · EscudoSST', 'Cómo protege EscudoSST tu información: cifrado, copias, datos médicos separados, Ley 29733 y lista de subencargados.', '/confianza', 'Confianza'],
  ['web-estado', 'Estado del servicio', 'Estado del servicio · EscudoSST', 'Disponibilidad de la aplicación, la API, la base médica, el correo, WhatsApp y la pasarela de pagos, con su historial de incidentes.', '/estado', 'Estado'],
].forEach(([k, n, t, d, slug, tipo]) => { WEB.paginas[k] = { n, tipo, estado: 'Publicado', seo: { t, d, slug, og: 'auto', noindex: false }, editado: '01/11/2026' }; });
WEB.paginas['web-comparativa'].tipo = 'Comparativa (comparison)';

/* ---------- Estado de los extras ---------- */
const WX = {
  utm: { source: '(directo)', medium: '—', campaign: '—' }, // UTM de la visita de demostración
  sigLead: 1208, sigPartner: 19, sigInc: 8, sigIdem: 4100,
  /* marketing_leads · source: fine_calculator, self_assessment, resource, partner, newsletter, demo, contact */
  leads: [
    { id: 'LEAD-1207', f: '31/10/2026', full_name: 'Marisol Quispe Rojas', email: 'mquispe@textilesandinos-demo.pe', company_name: 'Textiles Andinos del Centro S.A.C.', ruc: '20514788121', phone: '987123456', sector: 'Manufactura', workers_range: '20 a 200', source: 'fine_calculator', utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'multas-sunafil', marketing_consent: true, marketing_consent_at: '31/10/2026 18:22', status: 'new', assigned_to: 'Sin asignar', act: ['Calculó una multa grave · 21–30 trabajadores'] },
    { id: 'LEAD-1206', f: '30/10/2026', full_name: 'Diego Paredes Lozano', email: 'dparedes@agrovalle-demo.pe', company_name: 'Agroindustrias Valle Sur S.A.', ruc: '', phone: '', sector: 'Agroindustria', workers_range: 'Más de 200', source: 'self_assessment', utm_source: 'linkedin', utm_medium: 'social', utm_campaign: 'autodiagnostico', marketing_consent: false, marketing_consent_at: '', status: 'contacted', assigned_to: 'Ventas 1', act: ['Autodiagnóstico: 46 % · nivel bajo', 'Informe PDF enviado'] },
    { id: 'LEAD-1205', f: '29/10/2026', full_name: 'Karina Salazar Vega', email: 'ksalazar@correo-demo.pe', company_name: '', ruc: '', phone: '', sector: 'Construcción', workers_range: '', source: 'resource', utm_source: 'boletin', utm_medium: 'email', utm_campaign: 'edicion-octubre', marketing_consent: true, marketing_consent_at: '29/10/2026 08:14', status: 'qualified', assigned_to: 'Ventas 2', act: ['Descargó «Plantilla de matriz IPERC»'] },
    { id: 'LEAD-1204', f: '27/10/2026', full_name: 'Óscar Medina Torres', email: 'omedina@sstnorte-demo.pe', company_name: 'SST Norte Consultores E.I.R.L.', ruc: '20605123457', phone: '944556677', sector: 'Consultora SST', workers_range: 'Menos de 20', source: 'partner', utm_source: '(directo)', utm_medium: '—', utm_campaign: '—', marketing_consent: true, marketing_consent_at: '27/10/2026 11:05', status: 'converted', assigned_to: 'Dueño de la plataforma', act: ['Solicitud de partner PAR-0017 aprobada'] },
    { id: 'LEAD-1203', f: '25/10/2026', full_name: '', email: 'jefaturasst@minerapuno-demo.pe', company_name: '', ruc: '', phone: '', sector: '', workers_range: '', source: 'newsletter', utm_source: 'google', utm_medium: 'organic', utm_campaign: '—', marketing_consent: false, marketing_consent_at: '', status: 'new', assigned_to: 'Sin asignar', act: ['Suscripción al boletín confirmada'] },
    { id: 'LEAD-1202', f: '22/10/2026', full_name: 'Pedro Ramírez Chávez', email: 'pramirez@correo-demo.pe', company_name: 'Servicios Generales Rímac S.R.L.', ruc: '', phone: '', sector: 'Oficinas y servicios', workers_range: 'Menos de 20', source: 'fine_calculator', utm_source: '(directo)', utm_medium: '—', utm_campaign: '—', marketing_consent: false, marketing_consent_at: '', status: 'discarded', assigned_to: 'Ventas 1', act: ['Calculó una multa leve · 1–5 trabajadores'] },
  ],
  calculos: [], // fine_calculations
  envios: [], // assessment_submissions
  descargas: [ // resource_downloads (inalterable)
    { res: 'plantilla-iperc', lead: 'LEAD-1205', ip: 'a3f9…c21', f: '29/10/2026 08:14' },
    { res: 'checklist-inspeccion', lead: '—', ip: '7be2…914', f: '28/10/2026 19:40' },
  ],
  /* Parámetros legales (system_parameters) · todos to_confirm=true */
  params: [
    { key: 'web.uit_value', l: 'Valor de la UIT', v: 5500, unidad: 'S/', vig: '01/01/2026', ref: 'Decreto supremo anual de la UIT (a confirmar)', to_confirm: true },
    { key: 'web.fine_correction_discount_pct', l: 'Descuento referencial por subsanación', v: 90, unidad: '%', vig: '01/01/2026', ref: 'Reglamento de la Ley General de Inspección del Trabajo (a confirmar)', to_confirm: true },
    { key: 'web.lead_retention_months', l: 'Conservación de leads sin conversión', v: 24, unidad: 'meses', vig: '01/11/2026', ref: 'Política de privacidad · Ley 29733 (a confirmar)', to_confirm: true },
  ],
  /* Tablas web.fine_table_micro / _small / _non_mype · UIT por tramo de trabajadores afectados (valores de ejemplo) */
  tablas: {},
  infracciones: [
    { code: 'SST-L-01', t: 'Falta de orden y limpieza que no implica riesgo para la integridad física', sev: 'leve' },
    { code: 'SST-G-01', t: 'No contar con la evaluación de riesgos (IPERC) actualizada', sev: 'grave' },
    { code: 'SST-G-02', t: 'No llevar los registros obligatorios del sistema de gestión', sev: 'grave' },
    { code: 'SST-G-03', t: 'No dar la capacitación en SST a los trabajadores', sev: 'grave' },
    { code: 'SST-M-01', t: 'Incumplir medidas de protección que causen un accidente con daño grave', sev: 'muy_grave' },
    { code: 'SST-M-02', t: 'No paralizar una labor con riesgo grave e inminente', sev: 'muy_grave' },
  ],
  /* Cuestionario del autodiagnóstico: publicado y borrador (assessment_questionnaires / assessment_questions) */
  cuest: null, borrador: null,
  /* Biblioteca (resource_items) */
  recursos: [
    { slug: 'plantilla-iperc', title: 'Plantilla de matriz IPERC', description: 'Identificación de peligros, evaluación de riesgos y controles por puesto, con la escala de probabilidad y severidad lista para usar.', resource_type: 'template', sector: 'Todos', is_gated: true, status: 'published', download_count: 148, fmt: 'Excel' },
    { slug: 'matriz-capacitacion', title: 'Matriz de capacitación por cargo', description: 'Cursos obligatorios por puesto con semáforo de cumplimiento y fechas de vencimiento.', resource_type: 'template', sector: 'Todos', is_gated: true, status: 'published', download_count: 96, fmt: 'Excel' },
    { slug: 'registros-obligatorios', title: 'Formatos de los registros obligatorios', description: 'Estructura de los registros del sistema de gestión SST, basada en los formatos referenciales vigentes.', resource_type: 'format', sector: 'Todos', is_gated: true, status: 'published', download_count: 131, fmt: 'Word' },
    { slug: 'checklist-inspeccion', title: 'Checklist de inspección de planta', description: 'Lista de verificación de orden, máquinas, EPP, señalización y emergencias para recorridos mensuales.', resource_type: 'checklist', sector: 'Manufactura', is_gated: false, status: 'published', download_count: 212, fmt: 'PDF' },
    { slug: 'guia-sunafil', title: 'Guía: cómo prepararte para una inspección de SUNAFIL', description: 'Qué documentos suelen pedir, cómo ordenarlos y qué hacer el día de la visita.', resource_type: 'guide', sector: 'Todos', is_gated: false, status: 'published', download_count: 305, fmt: 'PDF' },
    { slug: 'checklist-petar', title: 'Checklist de PETAR para trabajos en altura', description: 'Verificación previa de permisos, ejecutantes, equipos y condiciones antes de iniciar el trabajo.', resource_type: 'checklist', sector: 'Construcción', is_gated: true, status: 'published', download_count: 58, fmt: 'PDF' },
    { slug: 'induccion-campana', title: 'Formato de inducción para personal de campaña', description: 'Registro de inducción masiva con firma y entrega de EPP para fundos y plantas de empaque.', resource_type: 'format', sector: 'Agroindustria', is_gated: false, status: 'published', download_count: 41, fmt: 'Word' },
    { slug: 'guia-teletrabajo', title: 'Guía de ergonomía para teletrabajo', description: 'Recomendaciones para puestos con pantalla en casa y lista de autoevaluación del trabajador.', resource_type: 'guide', sector: 'Oficinas y servicios', is_gated: false, status: 'draft', download_count: 0, fmt: 'PDF' },
  ],
  /* Casos de éxito (success_stories) · los de la demo son de ejemplo */
  casos: [
    { slug: 'metalmecanica-lima', client_name: 'Empresa metalmecánica de Lima', ini: 'MM', color: '#1764AB', sector: 'Manufactura', challenge: 'El IPERC vivía en un Excel por área y las inspecciones encontraban los mismos hallazgos cada mes porque las acciones no tenían responsable.', solution: 'IPERC por puesto con versiones y aprobación, inspecciones desde el celular y cada hallazgo convertido en acción correctiva con fecha.', results: ['Acciones correctivas con responsable y fecha desde el primer mes', 'Expediente SUNAFIL armado desde el sistema', 'Comité revisando avances desde el celular'], quote: 'Dejamos de perseguir firmas: cada hallazgo tiene dueño.', consent: 'consentimiento-metalmecanica.pdf', is_example: true, status: 'published', approved_by: 'Dueño de la plataforma', approved_at: '20/10/2026' },
    { slug: 'constructora-arequipa', client_name: 'Constructora con obras en Arequipa', ini: 'CA', color: '#F08A24', sector: 'Construcción', challenge: 'Varias obras con subcontratistas que cambiaban cada semana y permisos de alto riesgo firmados en papel.', solution: 'PETAR con aprobación en cadena, control de ingreso que valida SCTR vigente y acreditación de contratistas.', results: ['PETAR aprobados en obra, sin papel', 'Ingreso bloqueado a quien no tiene SCTR vigente', 'Contratistas con documentos al día'], quote: 'Sabemos quién entra a obra antes de que pase la puerta.', consent: 'consentimiento-constructora.pdf', is_example: true, status: 'published', approved_by: 'Dueño de la plataforma', approved_at: '22/10/2026' },
    { slug: 'consultora-trujillo', client_name: 'Consultora SST de Trujillo', ini: 'CT', color: '#0E7A70', sector: 'Consultora SST', challenge: 'Doce empresas cliente, cada una con su carpeta de Excel y sus vencimientos.', solution: 'Modo consultora con marca blanca: un panel con las alertas de todos los clientes y cada empresa con sus datos separados.', results: ['Un solo panel para todas las empresas cliente', 'Informes con la marca de la consultora', 'Alertas antes de cada vencimiento'], quote: 'Atiendo a más clientes sin aumentar el papeleo.', consent: 'consentimiento-consultora.pdf', is_example: true, status: 'published', approved_by: 'Dueño de la plataforma', approved_at: '25/10/2026' },
    { slug: 'fundo-ica', client_name: 'Fundo agroexportador de Ica', ini: 'FI', color: '#22A35A', sector: 'Agroindustria', challenge: 'Cientos de ingresos por campaña que debían recibir inducción y EPP antes de empezar.', solution: 'Carga por Excel, matrícula por cargo en el aula virtual y fotocheck QR solo para quien aprobó y recibió su EPP.', results: ['Inducciones de campaña registradas con firma', 'Fotocheck QR para el personal habilitado', 'Reportes de condiciones inseguras por WhatsApp'], quote: 'Las inducciones de campaña dejaron de ser un caos.', consent: '', is_example: true, status: 'draft', approved_by: '', approved_at: '' },
  ],
  /* Centro de ayuda (help_categories / help_articles) · screen_code = pantalla de la app */
  categorias: [
    { slug: 'primeros-pasos', name: 'Primeros pasos', icon: 'star', sort_order: 1, status: 'published' },
    { slug: 'riesgos', name: 'Gestión de riesgos', icon: 'risk', sort_order: 2, status: 'published' },
    { slug: 'campo', name: 'Inspecciones y campo', icon: 'field', sort_order: 3, status: 'published' },
    { slug: 'capacitaciones', name: 'Capacitaciones y aula', icon: 'people', sort_order: 4, status: 'published' },
    { slug: 'sunafil', name: 'Modo Inspección SUNAFIL', icon: 'shield', sort_order: 5, status: 'published' },
    { slug: 'salud', name: 'Salud ocupacional', icon: 'heart', sort_order: 6, status: 'published' },
    { slug: 'cuenta', name: 'Cuenta y usuarios', icon: 'user', sort_order: 7, status: 'published' },
  ],
  articulos: [
    { slug: 'primeros-pasos-cuenta', cat: 'primeros-pasos', title: 'Primeros pasos: configura tu empresa en EscudoSST', screen_code: 'DSH', video: true, status: 'published', util: [42, 3], rel: ['importar-trabajadores', 'invitar-usuarios'],
      body: ['Después de registrarte con el RUC, entra a «Empresas, sedes y áreas» y completa tus sedes, áreas y puestos. Esa estructura la usan el IPERC, las capacitaciones y los indicadores.', 'Luego importa a tus trabajadores desde Excel y haz el diagnóstico de línea base: te dirá qué falta y armará tu plan de trabajo.'], pasos: ['Completa sedes, áreas y puestos', 'Importa a tus trabajadores', 'Haz la línea base', 'Revisa el plan anual sugerido'] },
    { slug: 'importar-trabajadores', cat: 'primeros-pasos', title: 'Importar trabajadores desde Excel', screen_code: 'TRB', video: false, status: 'published', util: [31, 4], rel: ['primeros-pasos-cuenta'],
      body: ['Descarga la plantilla, completa una fila por trabajador y súbela. El sistema valida cada fila (DNI, puesto, fecha de ingreso) y te muestra los errores antes de guardar.'], pasos: ['Descarga la plantilla', 'Completa una fila por trabajador', 'Sube el archivo y corrige los errores por fila'] },
    { slug: 'invitar-usuarios', cat: 'cuenta', title: 'Invitar usuarios y asignar roles', screen_code: 'USR', video: false, status: 'published', util: [18, 1], rel: ['primeros-pasos-cuenta'],
      body: ['En «Usuarios, roles y permisos» invita por correo a cada persona y elige su rol. Cada rol ve solo lo que necesita: por ejemplo, Recursos Humanos no ve datos médicos.'], pasos: ['Abre Usuarios, roles y permisos', 'Elige «Invitar usuario»', 'Escribe el correo y elige el rol'] },
    { slug: 'crear-iperc', cat: 'riesgos', title: 'Cómo crear y aprobar tu matriz IPERC', screen_code: 'IPC', video: true, status: 'published', util: [57, 6], rel: ['mapa-riesgos', 'inspeccion-celular'],
      body: ['El IPERC se arma por puesto: agrega cada tarea, sus peligros y riesgos, valora la probabilidad y la severidad y define los controles. Al terminar, envíalo a aprobación.', 'Cada aprobación crea una versión nueva; las anteriores quedan en el historial.'], pasos: ['Elige el puesto o el proceso', 'Agrega tareas, peligros y riesgos', 'Valora y define controles', 'Envía a aprobación'] },
    { slug: 'mapa-riesgos', cat: 'riesgos', title: 'Dibujar el mapa de riesgos de una sede', screen_code: 'MAP', video: false, status: 'published', util: [12, 2], rel: ['crear-iperc'],
      body: ['Sube el plano de la sede y arrastra los pictogramas sobre cada zona. El mapa se puede imprimir en tamaño cartel.'], pasos: ['Sube el plano', 'Coloca los pictogramas', 'Publica e imprime'] },
    { slug: 'inspeccion-celular', cat: 'campo', title: 'Hacer una inspección desde el celular', screen_code: 'INS', video: true, status: 'published', util: [39, 2], rel: ['reportes-qr', 'crear-iperc'],
      body: ['Abre la inspección programada, responde cada punto del checklist y toma fotos. Si marcas «No cumple», el sistema te pide crear una acción correctiva con responsable y fecha.'], pasos: ['Abre la inspección', 'Responde el checklist con fotos', 'Convierte cada «No cumple» en acción'] },
    { slug: 'reportes-qr', cat: 'campo', title: 'Reportes de actos y condiciones por QR o WhatsApp', screen_code: 'ACT', video: false, status: 'published', util: [26, 3], rel: ['inspeccion-celular'],
      body: ['Imprime los carteles QR de cada zona. Cualquier trabajador puede reportar sin instalar nada, incluso de forma anónima, y el reporte llega a tu bandeja.'], pasos: ['Crea los puntos de reporte', 'Imprime los carteles', 'Atiende la bandeja'] },
    { slug: 'registrar-accidente', cat: 'campo', title: 'Registrar un accidente y su investigación', screen_code: 'ACC', video: false, status: 'published', util: [21, 1], rel: ['inspeccion-celular'],
      body: ['Registra el evento en cuatro pasos. La cuenta regresiva te muestra el plazo de notificación, que es un parámetro con vigencia a confirmar por especialista. Luego arma el árbol de causas.'], pasos: ['Registra el evento', 'Revisa la cuenta regresiva', 'Investiga con el árbol de causas'] },
    { slug: 'matriz-capacitacion', cat: 'capacitaciones', title: 'Matriz de capacitación por cargo', screen_code: 'CAP', video: false, status: 'published', util: [19, 2], rel: ['crear-curso-aula'],
      body: ['Define qué cursos son obligatorios para cada cargo. La matriz muestra en semáforo quién está al día, quién está por vencer y quién falta.'], pasos: ['Elige el cargo', 'Marca los cursos obligatorios', 'Revisa el semáforo'] },
    { slug: 'crear-curso-aula', cat: 'capacitaciones', title: 'Crear un curso en el aula virtual', screen_code: 'AUL', video: true, status: 'published', util: [24, 1], rel: ['matriz-capacitacion'],
      body: ['Crea el curso, organiza el contenido en secciones (videos, documentos, evaluaciones) y matricula por cargo o de forma manual. Puedes activar el recorrido secuencial y el certificado verificable.'], pasos: ['Crea el curso', 'Agrega secciones y contenido', 'Configura la evaluación', 'Matricula a los participantes'] },
    { slug: 'expediente-sunafil', cat: 'sunafil', title: 'Armar el expediente para una inspección de SUNAFIL', screen_code: 'SUN', video: true, status: 'published', util: [64, 4], rel: ['crear-iperc', 'registrar-accidente'],
      body: ['El Modo Inspección SUNAFIL hace un autodiagnóstico, te muestra los documentos pendientes y arma el expediente. Puedes compartir un enlace de solo lectura con el inspector.'], pasos: ['Haz el autodiagnóstico', 'Completa los documentos pendientes', 'Comparte el enlace con el inspector'] },
    { slug: 'emo-aptitud', cat: 'salud', title: 'EMO y aptitud: quién ve qué', screen_code: 'EMO', video: false, status: 'published', util: [15, 0], rel: ['invitar-usuarios'],
      body: ['La aptitud (apto, apto con restricciones, no apto) la ve el área SST. Los resultados y diagnósticos solo los ve el personal de salud, con una clave adicional, y cada acceso queda registrado.'], pasos: ['Registra el EMO', 'El médico registra la aptitud', 'SST ve solo la aptitud y la vigencia'] },
    { slug: 'fotocheck-qr', cat: 'salud', title: 'Emitir el fotocheck SST con QR', screen_code: 'FOT', video: false, status: 'draft', util: [0, 0], rel: [],
      body: ['El fotocheck se habilita cuando el trabajador tiene inducción, EMO, EPP y SCTR al día. El QR muestra si está habilitado, sin datos médicos.'], pasos: ['Revisa los requisitos', 'Emite la credencial', 'Imprime o comparte el QR'] },
  ],
  feedback: [], // help_article_feedback (inalterable)
  /* Partners y referidos */
  partners: [
    { id: 'PAR-0017', f: '27/10/2026', lead: 'LEAD-1204', partner_type: 'consultancy', company_name: 'SST Norte Consultores E.I.R.L.', ruc: '20605123457', website: '', contacto: 'Óscar Medina Torres', email: 'omedina@sstnorte-demo.pe', status: 'approved', reviewed_by: 'Dueño de la plataforma', reviewed_at: '28/10/2026', notes: 'Atiende empresas de Piura y Lambayeque.' },
    { id: 'PAR-0018', f: '30/10/2026', lead: '', partner_type: 'independent', company_name: 'Ing. Liliana Huamán Ccori', ruc: '10457712345', website: '', contacto: 'Liliana Huamán Ccori', email: 'lhuaman@correo-demo.pe', status: 'pending', reviewed_by: '', reviewed_at: '', notes: '' },
  ],
  codigos: [ // referral_codes
    { code: 'SSTNORTE-26', partner: 'PAR-0017', reward: { tipo: 'Por definir', valor: '' }, is_active: true },
  ],
  referidos: [ // referrals
    { code: 'SSTNORTE-26', empresa: 'Molinos del Norte S.A.C.', f: '29/10/2026', status: 'trial', converted_at: '' },
    { code: 'SSTNORTE-26', empresa: 'Transportes Chiclayo E.I.R.L.', f: '30/10/2026', status: 'registered', converted_at: '' },
    { code: 'SSTNORTE-26', empresa: 'Clínica San Miguel de Piura S.A.C.', f: '20/10/2026', status: 'customer', converted_at: '31/10/2026' },
  ],
  /* Boletín normativo */
  temas: [['normativa', 'Normativa SST'], ['sunafil', 'SUNAFIL e inspecciones'], ['sectores', 'Novedades por sector']],
  suscriptores: [
    { email: 'jefaturasst@minerapuno-demo.pe', topics: ['normativa', 'sunafil', 'sectores'], status: 'confirmed', f: '25/10/2026', confirmed_at: '25/10/2026', unsubscribed_at: '' },
    { email: 'ksalazar@correo-demo.pe', topics: ['normativa', 'sectores'], status: 'confirmed', f: '12/10/2026', confirmed_at: '12/10/2026', unsubscribed_at: '' },
    { email: 'r.flores@fabrica-demo.pe', topics: ['sunafil'], status: 'confirmed', f: '03/10/2026', confirmed_at: '03/10/2026', unsubscribed_at: '' },
    { email: 'mmontes@correo-demo.pe', topics: ['normativa'], status: 'pending', f: '31/10/2026', confirmed_at: '', unsubscribed_at: '' },
    { email: 'avillar@correo-demo.pe', topics: ['normativa', 'sunafil'], status: 'unsubscribed', f: '15/09/2026', confirmed_at: '15/09/2026', unsubscribed_at: '20/10/2026' },
  ],
  ediciones: [
    { id: 'NL-2026-10', subject: 'Boletín SST · octubre 2026', topics: ['normativa', 'sunafil'], body: 'Resumen de cambios normativos del mes, preguntas frecuentes de las inspecciones y una guía para preparar el expediente.', status: 'sent', scheduled_at: '', sent_at: '15/10/2026 08:00', recipients_count: 3, opens_count: 2, clicks_count: 1 },
    { id: 'NL-2026-09', subject: 'Boletín SST · setiembre 2026', topics: ['normativa', 'sectores'], body: 'Novedades del sector construcción y recordatorio de capacitaciones anuales.', status: 'sent', scheduled_at: '', sent_at: '15/09/2026 08:00', recipients_count: 2, opens_count: 2, clicks_count: 1 },
    { id: 'NL-2026-11', subject: 'Boletín SST · noviembre 2026', topics: ['normativa', 'sunafil', 'sectores'], body: 'Borrador: temas del mes por definir.', status: 'draft', scheduled_at: '', sent_at: '', recipients_count: 0, opens_count: 0, clicks_count: 0 },
  ],
  /* Subencargados (subprocessors) · transferencia internacional */
  subencargados: [
    { id: 1, name: 'Contabo', purpose: 'Servidores de la aplicación, colas y base principal (disco cifrado)', country: 'Por confirmar (región del servidor)', data_categories: 'Datos de la empresa, trabajadores y registros SST (sin datos médicos)', transfer_basis: 'Contrato de encargo y cláusulas de protección (a validar con abogado)', website: '', status: 'published' },
    { id: 2, name: 'DigitalOcean', purpose: 'Base de datos médica separada, gestionada y cifrada en reposo', country: 'Por confirmar (región del servidor)', data_categories: 'Datos de salud ocupacional (EMO, vigilancia, descansos)', transfer_basis: 'Contrato de encargo y cláusulas de protección (a validar con abogado)', website: '', status: 'published' },
    { id: 3, name: 'Backblaze B2', purpose: 'Almacenamiento de archivos y respaldos con cifrado del lado del servidor', country: 'Por confirmar (región del bucket)', data_categories: 'Evidencias, fotos y PDF; documentos médicos en un bucket separado', transfer_basis: 'Contrato de encargo y cláusulas de protección (a validar con abogado)', website: '', status: 'published' },
    { id: 4, name: 'Elastic Email', purpose: 'Envío de correos transaccionales y del boletín', country: 'Por confirmar', data_categories: 'Nombre y correo del destinatario, contenido del aviso (nunca datos médicos)', transfer_basis: 'Contrato de encargo y cláusulas de protección (a validar con abogado)', website: '', status: 'published' },
    { id: 5, name: 'Proveedor de WhatsApp Business (por definir)', purpose: 'Avisos y reportes por WhatsApp', country: 'Por definir', data_categories: 'Número de celular y texto del mensaje (nunca datos médicos)', transfer_basis: 'Por definir', website: '', status: 'published' },
    { id: 6, name: 'Pasarela de pagos (por definir)', purpose: 'Cobro de suscripciones y venta de cursos', country: 'Perú (por confirmar)', data_categories: 'Datos de facturación; la tarjeta la procesa la pasarela, EscudoSST no la guarda', transfer_basis: 'Por definir', website: '', status: 'published' },
  ],
  /* Estado del servicio */
  componentes: [
    { code: 'app', name: 'Aplicación web y app móvil', description: 'Panel, módulos y app del trabajador', current_status: 'operational', sort_order: 1 },
    { code: 'api', name: 'API e integraciones', description: 'API REST y webhooks', current_status: 'operational', sort_order: 2 },
    { code: 'medical_db', name: 'Base médica', description: 'EMO, vigilancia médica y descansos', current_status: 'operational', sort_order: 3 },
    { code: 'email', name: 'Correo', description: 'Alertas, invitaciones y boletín', current_status: 'operational', sort_order: 4 },
    { code: 'whatsapp', name: 'WhatsApp', description: 'Avisos y reportes por WhatsApp', current_status: 'operational', sort_order: 5 },
    { code: 'payments', name: 'Pasarela de pagos', description: 'Suscripciones y venta de cursos', current_status: 'operational', sort_order: 6 },
  ],
  incidentes: [
    { id: 'INC-0007', title: 'Demora en el envío de correos de alerta', impact: 'minor', status: 'resolved', started_at: '24/10/2026 14:10', resolved_at: '24/10/2026 15:02', comps: ['email'],
      updates: [{ status: 'investigating', message: 'Detectamos demoras en la entrega de correos de alerta. Las alertas siguen visibles en la aplicación.', at: '24/10/2026 14:10' }, { status: 'identified', message: 'El proveedor de correo confirmó una cola retrasada en su servicio.', at: '24/10/2026 14:31' }, { status: 'resolved', message: 'Los correos pendientes se entregaron. El servicio funciona con normalidad.', at: '24/10/2026 15:02' }] },
    { id: 'INC-0006', title: 'Mantenimiento programado de la base médica', impact: 'maintenance', status: 'resolved', started_at: '12/10/2026 23:00', resolved_at: '13/10/2026 00:10', comps: ['medical_db'],
      updates: [{ status: 'monitoring', message: 'Mantenimiento programado en curso. Los módulos de salud ocupacional estarán en solo lectura.', at: '12/10/2026 23:00' }, { status: 'resolved', message: 'Mantenimiento terminado.', at: '13/10/2026 00:10' }] },
  ],
  estadoSubs: [],
  comparativa: [
    ['Matriz IPERC', 'Un Excel por área que nadie actualiza cuando cambia un proceso', 'IPERC por puesto con versiones, aprobación e historial'],
    ['Inspecciones', 'Papel en campo y transcripción días después', 'Checklist en el celular, con fotos, incluso sin señal'],
    ['Hallazgos', 'Se repiten cada mes porque no tienen dueño', 'Cada «No cumple» se convierte en acción con responsable y fecha'],
    ['Vencimientos', 'Se descubren cuando ya vencieron', 'Alertas por correo y WhatsApp antes de cada vencimiento'],
    ['Capacitaciones', 'Listas de asistencia en papel y certificados sueltos', 'Matriz por cargo, aula virtual y certificados verificables'],
    ['Firmas', 'Perseguir a cada persona con el formato impreso', 'Firma electrónica desde el celular'],
    ['Datos médicos', 'En carpetas compartidas que cualquiera puede abrir', 'Base separada y cifrada, con clave adicional y registro de accesos'],
    ['Inspección de SUNAFIL', 'Una semana armando carpetas', 'Expediente ordenado y enlace de solo lectura para el inspector'],
    ['Indicadores', 'Fórmulas que se rompen al copiar hojas', 'Estadísticas e índices calculados con parámetros vigentes'],
    ['Varias sedes o clientes', 'Un archivo por sede, sin vista consolidada', 'Todo en un panel, con datos separados por empresa'],
  ],
};

/* Tramos de trabajadores afectados por tamaño de empresa y extremos referenciales (UIT) por gravedad */
const WX_TAMANOS = [
  ['micro', 'Microempresa', 'web.fine_table_micro', ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10 y más'], { leve: [0.045, 0.45], grave: [0.11, 1.13], muy_grave: [0.23, 2.25] }],
  ['small', 'Pequeña empresa', 'web.fine_table_small', ['1–5', '6–10', '11–20', '21–30', '31–40', '41–50', '51–60', '61–70', '71–99', '100 y más'], { leve: [0.09, 1.58], grave: [0.45, 4.5], muy_grave: [0.77, 7.65] }],
  ['non_mype', 'No MYPE', 'web.fine_table_non_mype', ['1–10', '11–25', '26–50', '51–100', '101–200', '201–300', '301–400', '401–500', '501–999', '1000 y más'], { leve: [0.26, 15.7], grave: [1.57, 26.27], muy_grave: [2.63, 52.53] }],
];
const WX_SEV = [['leve', 'Leve'], ['grave', 'Grave'], ['muy_grave', 'Muy grave']];
WX_TAMANOS.forEach(([k, , key, tramos, ext]) => {
  const fila = {};
  WX_SEV.forEach(([s]) => { const [a, b] = ext[s]; fila[s] = tramos.map((_, i) => Math.round(a * Math.pow(b / a, i / (tramos.length - 1)) * 100) / 100); });
  WX.tablas[k] = { key, tramos, valores: fila, vig: '01/01/2026', to_confirm: true };
});

/* Cuestionario inicial · «a validar por especialista SST» */
const WX_PREG = [
  ['Política y organización', 'La empresa tiene una política SST firmada por la alta dirección y difundida a los trabajadores', 3, 'Ley 29783 · principios y política'],
  ['Política y organización', 'Está constituido el Comité SST o designado el Supervisor SST, según el número de trabajadores', 3, 'Ley 29783 · comité o supervisor'],
  ['Política y organización', 'Existe un Reglamento Interno de SST entregado a los trabajadores', 2, 'Reglamento · RISST'],
  ['Planificación', 'Hay una matriz IPERC actualizada por puesto o proceso', 3, 'Reglamento · evaluación de riesgos'],
  ['Planificación', 'Se cuenta con un Plan y un Programa Anual de SST aprobados', 2, 'Reglamento · plan anual'],
  ['Planificación', 'Se hizo el diagnóstico de línea base del sistema de gestión', 2, 'R.M. 050-2013-TR · línea base'],
  ['Capacitación', 'Los trabajadores reciben al menos las capacitaciones anuales obligatorias', 3, 'Ley 29783 · capacitación'],
  ['Capacitación', 'Las inducciones de ingreso quedan registradas con firma', 2, 'Registros obligatorios'],
  ['Salud ocupacional', 'Se realizan los exámenes médicos ocupacionales según la periodicidad aplicable', 3, 'Reglamento · vigilancia de la salud'],
  ['Salud ocupacional', 'Los resultados médicos se guardan con confidencialidad y acceso restringido', 2, 'Ley 29733 · datos sensibles'],
  ['Registros y mejora', 'Se llevan los registros obligatorios del sistema de gestión', 3, 'Reglamento · registros obligatorios'],
  ['Registros y mejora', 'Los accidentes e incidentes se investigan y generan acciones correctivas', 3, 'Reglamento · investigación'],
  ['Registros y mejora', 'Se hacen inspecciones internas periódicas con seguimiento de hallazgos', 2, 'Registros obligatorios'],
  ['Emergencias', 'Existe un plan de respuesta ante emergencias con brigadas', 2, 'Reglamento · emergencias'],
  ['Emergencias', 'Se realizan simulacros y se registran', 1, 'Registros obligatorios'],
];
WX.borrador = { title: 'Autodiagnóstico Ley 29783', version: '1.0', status: 'published', to_validate: true, published_at: '01/11/2026',
  preguntas: WX_PREG.map(([section, question_text, weight, legal_reference], i) => ({ id: 'q' + (i + 1), section, question_text, help_text: '', weight, sort_order: i + 1, legal_reference })) };
WX.cuest = JSON.parse(JSON.stringify(WX.borrador));

/* Utilidades compartidas */
const WX_FUENTE = { fine_calculator: 'Calculadora de multas', self_assessment: 'Autodiagnóstico', resource: 'Recurso descargable', partner: 'Partners', newsletter: 'Boletín', demo: 'Solicitud de demo', contact: 'Contacto' };
const WX_LEAD_EST = [['new', 'Nuevo'], ['contacted', 'Contactado'], ['qualified', 'Calificado'], ['converted', 'Convertido'], ['discarded', 'Descartado']];
const WX_TIPO_REC = { template: 'Plantilla', guide: 'Guía', checklist: 'Checklist', format: 'Formato' };
const WX_PARTNER_T = { consultancy: 'Consultora SST', independent: 'Profesional independiente', institute: 'Instituto o centro de formación' };
const WX_REF_EST = [['registered', 'Registrado'], ['trial', 'En prueba'], ['customer', 'Cliente'], ['cancelled', 'Cancelado']];
const WX_COMP_EST = { operational: ['Operativo', 'ok'], degraded: ['Rendimiento degradado', 'warn'], partial_outage: ['Interrupción parcial', 'warn'], major_outage: ['Interrupción mayor', 'bad'], maintenance: ['Mantenimiento', 'info'] };
const WX_INC_EST = { investigating: 'Investigando', identified: 'Causa identificada', monitoring: 'En observación', resolved: 'Resuelto' };
const WX_IMPACTO = { minor: 'Menor', major: 'Mayor', critical: 'Crítico', maintenance: 'Mantenimiento' };
const wxParam = (key) => WX.params.find((p) => p.key === key);
const wxNum = (n, d = 2) => Number(n).toLocaleString('es-PE', { minimumFractionDigits: d, maximumFractionDigits: d });
const wxSoles = (n) => 'S/ ' + Number(n).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const wxIdem = () => 'idem-' + (WX.sigIdem++).toString(36) + '-' + Math.random().toString(36).slice(2, 6);
const wxAhora = () => { const n = new Date(); return fmtF(HOY) + ' ' + String(n.getHours()).padStart(2, '0') + ':' + String(n.getMinutes()).padStart(2, '0'); };

/* Crea o actualiza un lead (por correo) con origen, UTM y consentimiento comercial separado */
function wxLead(source, d, actividad) {
  const email = (d.email || '').toLowerCase();
  let l = WX.leads.find((x) => x.email.toLowerCase() === email);
  const mk = !!d.marketing;
  if (l) {
    ['full_name', 'company_name', 'ruc', 'phone', 'sector', 'workers_range'].forEach((k) => { if (d[k]) l[k] = d[k]; });
    if (mk && !l.marketing_consent) { l.marketing_consent = true; l.marketing_consent_at = wxAhora(); }
    l.act.unshift(actividad);
    if (!l.origenes) l.origenes = [l.source];
    if (!l.origenes.includes(source)) l.origenes.push(source);
  } else {
    l = { id: 'LEAD-' + WX.sigLead++, f: fmtF(HOY), full_name: d.full_name || '', email: d.email, company_name: d.company_name || '', ruc: d.ruc || '', phone: d.phone || '', sector: d.sector || '', workers_range: d.workers_range || '', source, utm_source: WX.utm.source, utm_medium: WX.utm.medium, utm_campaign: WX.utm.campaign, marketing_consent: mk, marketing_consent_at: mk ? wxAhora() : '', status: 'new', assigned_to: 'Sin asignar', act: [actividad] };
    WX.leads.unshift(l);
  }
  l.idem = wxIdem();
  return l;
}
