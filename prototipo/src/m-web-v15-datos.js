/* Módulo WEB · v1.5 y v1.5-b (spec/brief-web-v15.md): estado en memoria de métricas, calendario de demos,
   chatbot, conexión con CRM, biblioteca de medios, portafolio, redes, campañas, ventanas emergentes,
   integraciones, novedades, webinars, reseñas y calculadora de ahorro. Todo es de ejemplo y ficticio. */
Object.assign(IC, {
  globe: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM2 12h20M12 2c2.8 3 4 6.5 4 10s-1.2 7-4 10c-2.8-3-4-6.5-4-10s1.2-7 4-10z',
  image: 'M4 4h16v16H4zM4 16l5-5 4 4 3-3 4 4M15.5 8.5h.01',
  folder: 'M3 6a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z',
  bot: 'M12 3v3M7 7h10a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3v-6a3 3 0 0 1 3-3zM9 12h.01M15 12h.01M9 16h6',
  sync: 'M20 12a8 8 0 0 1-14 5.3M4 12a8 8 0 0 1 14-5.3M18 3v4h-4M6 21v-4h4',
  video: 'M3 7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM17 10l4-3v10l-4-3',
  chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  megaphone: 'M3 10v4a1 1 0 0 0 1 1h3l6 4V5L7 9H4a1 1 0 0 0-1 1zM17 8a5 5 0 0 1 0 8',
  plug: 'M9 2v6M15 2v6M6 8h12v3a6 6 0 0 1-12 0zM12 17v5',
  window: 'M3 5h18v14H3zM3 9h18M7 7h.01M10 7h.01',
  piggy: 'M5 11a7 6 0 0 1 13-2l3-1v4l-2 1a7 6 0 0 1-3 4v3h-3v-2h-3v2H7v-3a6 6 0 0 1-2-6zM15 10h.01',
  starf: 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z',
  share: 'M18 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM18 22a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 4M15.4 6.5l-6.8 4',
});
/* Íconos de redes sociales propios (trazos simples, sin marcas registradas dibujadas al detalle) */
const RED_IC = {
  Facebook: 'M14 8h3V4h-3a4 4 0 0 0-4 4v3H7v4h3v7h4v-7h3l1-4h-4V8z',
  Instagram: 'M7 3h10a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4zM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM17.5 6.5h.01',
  LinkedIn: 'M4 9h4v12H4zM6 3a2 2 0 1 1 0 4 2 2 0 0 1 0-4zM10 9h4v2c.6-1.2 2-2.2 4-2.2 3 0 4 2 4 5V21h-4v-6c0-1.5-.5-2.5-2-2.5S14 13.5 14 15v6h-4z',
  TikTok: 'M14 3v11.5a3.5 3.5 0 1 1-3-3.46M14 3c.5 2.6 2.4 4.4 5 4.6',
  YouTube: 'M3 8.5C3 6.6 4.6 5 6.5 5h11C19.4 5 21 6.6 21 8.5v7c0 1.9-1.6 3.5-3.5 3.5h-11C4.6 19 3 17.4 3 15.5zM10 9v6l5-3z',
  X: 'M4 4l16 16M20 4L4 20',
};
const icRed = (n, s = 18) => '<svg width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + (RED_IC[n] || IC.link) + '"/></svg>';
WEB.ajustes.redes = Object.assign({ Facebook: '', Instagram: '', LinkedIn: '', TikTok: '', YouTube: '', X: '' }, WEB.ajustes.redes);
WEB.ajustes.analytics = { ga4: '', gtm: '', metaPixel: '', searchConsole: '' }; // site_settings.analytics_ids · solo con consentimiento

/* Fechas: HOY = viernes 30/10/2026 (America/Lima) */
const d15 = (y, m, d, h = 0, mi = 0) => new Date(y, m - 1, d, h, mi);
const hhmm = (d) => String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
const mismoDia = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
const DIAS_L = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
const MESES_L = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];
const fLarga = (d) => DIAS_L[d.getDay()] + ' ' + d.getDate() + ' de ' + MESES_L[d.getMonth()] + ' de ' + d.getFullYear();
const tok15 = () => Math.random().toString(36).slice(2, 8) + Math.random().toString(36).slice(2, 8);
const meetUrl = (p) => p === 'teams' ? 'teams.microsoft.com/l/meetup-join/ejemplo-' + tok15().slice(0, 8) : p === 'phone' ? 'Llamada telefónica al número registrado' : 'meet.google.com/' + tok15().slice(0, 3) + '-' + tok15().slice(0, 4) + '-' + tok15().slice(0, 3);
const PROV_MEET = { meet: 'Google Meet', teams: 'Microsoft Teams', phone: 'Teléfono' };

/* Serie pseudoaleatoria estable para métricas de ejemplo */
const rnd15 = (s) => { const x = Math.sin(s * 9301 + 49297) * 233280; return x - Math.floor(x); };

const V15 = {
  /* ---- Métricas propias (site_analytics_daily agregadas) · datos de ejemplo ---- */
  dias: Array.from({ length: 30 }, (_, i) => { const d = new Date(HOY); d.setDate(d.getDate() - 29 + i); const fin = d.getDay() === 0 || d.getDay() === 6; const v = Math.round((fin ? 70 : 150) + rnd15(i + 1) * (fin ? 40 : 110) + i * 2.2); return { d, visitas: v, unicos: Math.round(v * 0.74) }; }),
  fuentes: [['organic', 'Orgánico (buscadores)', 41], ['direct', 'Directo', 22], ['social', 'Redes sociales', 15], ['campaign', 'Campañas (UTM)', 12], ['referral', 'Referidos', 7], ['email', 'Correo', 3]],
  dispositivos: [['Escritorio', 58], ['Celular', 38], ['Tableta', 4]],
  paginas: [['/', 'Portada', 1840, 52], ['/planes', 'Planes y precios', 612, 74], ['/herramientas/calculadora-multas', 'Calculadora de multas', 498, 131], ['/sectores/construccion', 'Sector construcción', 305, 66], ['/herramientas/autodiagnostico', 'Autodiagnóstico', 287, 158], ['/contacto', 'Contacto y demo', 241, 49], ['/webinars', 'Webinars', 118, 63]],
  conv: { demo: ['Agenda de demo', 'form', 21], cotizacion: ['Cotización', 'form', 14], contacto: ['Contacto', 'form', 9], calculadora: ['Calculadora de multas', 'tool', 38], autodiag: ['Autodiagnóstico', 'tool', 17], ahorro: ['Calculadora de ahorro', 'tool', 6], recurso: ['Descarga de recursos', 'form', 26], webinar: ['Inscripción a webinar', 'form', 11], whatsapp: ['Clic en WhatsApp', 'cta', 44], chatbot: ['Lead desde el chatbot', 'cta', 7], popup: ['Clic en ventana emergente', 'cta', 5] },
  ev: [], // site_events de esta sesión (solo con consentimiento analítico)

  /* ---- Calendario de demos ---- */
  booking: { public_booking_enabled: true, duration_minutes: 45, buffer_minutes: 15, min_notice_hours: 24, max_days_ahead: 21, default_meeting_provider: 'meet', reminder_24h: true, reminder_1h: true },
  hosts: [
    { id: 'h1', display_name: 'Andrea Salas', rol: 'Asesora comercial', email: 'andrea.salas@correo-demo.pe', color: '#1F7FD1', calendar_provider: 'google', calendar_secret_ref: 'secret://calendar/google/h1', is_active: true, max_per_day: 4, reglas: { 1: ['09:00', '17:00'], 2: ['09:00', '17:00'], 3: ['09:00', '17:00'], 4: ['09:00', '17:00'], 5: ['09:00', '13:00'] } },
    { id: 'h2', display_name: 'Jorge Quispe', rol: 'Especialista SST', email: 'jorge.quispe@correo-demo.pe', color: '#22A35A', calendar_provider: 'none', calendar_secret_ref: '', is_active: true, max_per_day: 3, reglas: { 1: ['10:00', '16:00'], 3: ['10:00', '16:00'], 5: ['10:00', '16:00'] } },
    { id: 'h3', display_name: 'Lucía Torres', rol: 'Asesora comercial', email: 'lucia.torres@correo-demo.pe', color: '#C98A00', calendar_provider: 'microsoft', calendar_secret_ref: 'secret://calendar/microsoft/h3', is_active: true, max_per_day: 3, reglas: { 2: ['14:00', '18:00'], 4: ['14:00', '18:00'] } },
  ],
  bloqueos: [
    { id: 'b1', host: null, desde: d15(2026, 11, 6, 0, 0), hasta: d15(2026, 11, 6, 23, 59), motivo: 'Capacitación interna del equipo (ejemplo)' },
    { id: 'b2', host: 'h2', desde: d15(2026, 11, 9, 0, 0), hasta: d15(2026, 11, 10, 23, 59), motivo: 'Visita a cliente (ejemplo)' },
  ],
  reuniones: [
    { id: 'MTG-0031', demo: 'DEM-0137', host: 'h1', ini: d15(2026, 10, 27, 10, 0), status: 'completed', prov: 'meet', url: 'meet.google.com/ejm-plo1-abc', outcome: 'interested', notas: 'Pidió cotización para 3 sedes.', rec: false },
    { id: 'MTG-0032', demo: 'DEM-0136', host: 'h2', ini: d15(2026, 10, 28, 11, 0), status: 'no_show', prov: 'meet', url: 'meet.google.com/ejm-plo2-def', outcome: '', notas: '', rec: false },
    { id: 'MTG-0033', demo: 'DEM-0140', host: 'h1', ini: d15(2026, 11, 2, 10, 0), status: 'confirmed', prov: 'meet', url: 'meet.google.com/ejm-plo3-ghi', outcome: '', notas: '', rec: false },
    { id: 'MTG-0034', demo: 'DEM-0139', host: 'h3', ini: d15(2026, 11, 3, 15, 0), status: 'proposed', prov: 'teams', url: '', outcome: '', notas: '', rec: false },
    { id: 'MTG-0035', demo: 'DEM-0135', host: 'h2', ini: d15(2026, 11, 4, 11, 0), status: 'confirmed', prov: 'meet', url: 'meet.google.com/ejm-plo5-jkl', outcome: '', notas: '', rec: false },
  ],
  sigMtg: 36,
  calVista: 'semana', calRef: d15(2026, 11, 2), calHost: '', calSel: null,

  /* ---- Chatbot ---- */
  chat: {
    enabled: true, mode: 'guided', greeting: 'Hola, soy el asistente de EscudoSST. ¿En qué te ayudo?', offline_message: 'Ahora estamos fuera de horario. Déjanos tus datos o agenda una demo y te escribimos en el siguiente día hábil.',
    hours: { dias: [1, 2, 3, 4, 5], desde: '09:00', hasta: '18:00' }, handoff_whatsapp: true, ask_lead_after_messages: 3, ai_provider_secret_ref: '', simularHorario: true,
  },
  intents: [
    { id: 'i1', name: 'Agendar demo', phrases: ['demo', 'demostración', 'reunión', 'ver el sistema'], answer: 'Con gusto. Puedes elegir día y hora libres en la agenda, o dejarme tus datos y un asesor te escribe.', cta: 'demo', cta_url: '', status: 'published', usos: 18 },
    { id: 'i2', name: 'Precios y planes', phrases: ['precio', 'cuánto cuesta', 'plan', 'cotización', 'costo'], answer: 'Tenemos los planes Esencial, Profesional, Corporativo y Consultora. El precio depende de tu empresa: pide una cotización y te la enviamos.', cta: 'link', cta_url: '#web-contacto/cotizacion', status: 'published', usos: 25 },
    { id: 'i3', name: 'Multas SUNAFIL', phrases: ['multa', 'sunafil', 'sanción', 'inspección'], answer: 'La calculadora de multas te da un rango referencial en UIT y soles según tu empresa. No es asesoría legal.', cta: 'calculator', cta_url: '#web-calculadora', status: 'published', usos: 14 },
    { id: 'i4', name: 'Hablar con una persona', phrases: ['asesor', 'persona', 'whatsapp', 'humano', 'llamar'], answer: 'Te paso con nuestro equipo por WhatsApp.', cta: 'whatsapp', cta_url: '', status: 'published', usos: 9 },
    { id: 'i5', name: 'Datos médicos', phrases: ['médico', 'emo', 'salud', 'datos personales'], answer: 'Los datos médicos se guardan en una base separada y cifrada; solo el personal de salud autorizado los ve y cada acceso queda registrado.', cta: 'assessment', cta_url: '#web-autodiagnostico', status: 'published', usos: 4 },
    { id: 'i6', name: 'Integración con planillas', phrases: ['planilla', 'integración', 'api'], answer: 'Borrador: respuesta por revisar.', cta: 'none', cta_url: '', status: 'draft', usos: 0 },
  ],
  convs: [
    { id: 'CHT-0091', hash: 'c3f9…a12', ini: '29/10/2026 16:02', path: '/planes', lead: '', handoff: 'none', msgs: [['visitor', '¿cuánto cuesta?', 'i2'], ['bot', 'Tenemos los planes Esencial, Profesional, Corporativo y Consultora…', 'i2']] },
    { id: 'CHT-0092', hash: '7b21…e0d', ini: '29/10/2026 18:40', path: '/', lead: '', handoff: 'whatsapp', msgs: [['visitor', 'quiero hablar con un asesor', 'i4'], ['bot', 'Te paso con nuestro equipo por WhatsApp.', 'i4']] },
  ],
  sigConv: 93,

  /* ---- Conexión con CRM ---- */
  crm: {
    provider: 'kallpa', name: 'Kallpa CRM', base_url: '', secret_ref: '', status: 'paused', last_sync_at: '', last_error: '', probada: false,
    entities: { marketing_lead: true, demo_request: true, demo_meeting: true, partner_application: false, newsletter_subscriber: false },
    mapping: [['full_name', 'contact.name'], ['email', 'contact.email'], ['company_name', 'account.name'], ['ruc', 'account.tax_id'], ['source', 'lead.source'], ['utm_campaign', 'lead.campaign'], ['marketing_consent', 'contact.marketing_opt_in']],
  },
  cola: [],
  sigSync: 1,

  /* ---- Biblioteca de medios (site_media) ---- */
  medios: [],
  sigMedio: 1,
  medCarpeta: '',

  /* ---- Campañas (campaigns + site_pages tipo campaign) ---- */
  campanas: [
    { slug: 'sunafil-google', name: 'Expediente SUNAFIL · búsqueda', channel: 'google_ads', utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'expediente-sunafil', form_type: 'demo', starts_on: '01/11/2026', ends_on: '30/11/2026', status: 'active', goal: 'Solicitudes de demo', h: 'Tu expediente SUNAFIL ordenado, sin carpetas', sub: 'Mira en una demo cómo EscudoSST arma el expediente desde los módulos que ya usas.', visitas: 214, leads: 9 },
    { slug: 'consultoras-linkedin', name: 'Consultoras SST · LinkedIn', channel: 'linkedin', utm_source: 'linkedin', utm_medium: 'paid_social', utm_campaign: 'consultoras-2026', form_type: 'contact', starts_on: '15/10/2026', ends_on: '15/11/2026', status: 'active', goal: 'Contactos de consultoras', h: 'Todas tus empresas cliente en un panel, con tu marca', sub: 'Para consultoras SST que gestionan varias empresas.', visitas: 96, leads: 4 },
  ],

  /* ---- Ventanas emergentes (site_popups) ---- */
  popups: [
    { id: 'pp1', name: 'Salida · calculadora', trigger_type: 'exit_intent', trigger_value: '', pages: 'Todas las públicas', devices: 'all', offer_type: 'fine_calculator', title: 'Antes de irte: ¿cuánto arriesgas?', body: 'Estima en un minuto un rango referencial de multa SUNAFIL según tu empresa. Gratis y sin registrarte.', cta_label: 'Calcular mi multa', frequency_days: 7, status: 'active', vistas: 133, clics: 18 },
    { id: 'pp2', name: 'Scroll · boletín', trigger_type: 'scroll_percent', trigger_value: '70', pages: 'Blog y recursos', devices: 'mobile', offer_type: 'newsletter', title: 'Recibe el boletín normativo', body: 'Cambios de normativa SST en tu correo, una vez al mes.', cta_label: 'Suscribirme', frequency_days: 14, status: 'paused', vistas: 0, clics: 0 },
  ],
  popupVisto: false, // máximo por visita (web.popup_max_per_visit = 1, a confirmar)

  /* ---- Integraciones (site_integrations) · estado veraz ---- */
  integraciones: [
    { slug: 'whatsapp', name: 'WhatsApp Business (API de Meta)', category: 'messaging', description: 'Reportes de actos y condiciones, avisos y recordatorios por WhatsApp.', availability: 'beta', status: 'published' },
    { slug: 'correo', name: 'Correo transaccional', category: 'email', description: 'Avisos, constancias y boletín por correo electrónico.', availability: 'available', status: 'published' },
    { slug: 'almacenamiento', name: 'Almacenamiento de archivos cifrado', category: 'storage', description: 'Documentos y evidencias en almacenamiento en la nube con cifrado del lado del servidor.', availability: 'available', status: 'published' },
    { slug: 'moodle', name: 'Moodle', category: 'lms', description: 'Sincroniza cursos y calificaciones si ya usas Moodle (opcional).', availability: 'beta', status: 'published' },
    { slug: 'pagos', name: 'Pasarela de pagos', category: 'payments', description: 'Cobro de cursos con tarjeta, Yape o PagoEfectivo a través de la pasarela.', availability: 'beta', status: 'published' },
    { slug: 'kallpa', name: 'Kallpa CRM', category: 'crm', description: 'Envía leads y demos del sitio al CRM.', availability: 'planned', status: 'published' },
    { slug: 'calendario', name: 'Google Calendar y Microsoft 365', category: 'calendar', description: 'Disponibilidad de los anfitriones y eventos de las demos.', availability: 'planned', status: 'published' },
    { slug: 'ia', name: 'Asistente IA', category: 'ai', description: 'Sugerencias para IPERC e investigación; una persona decide.', availability: 'beta', status: 'published' },
    { slug: 'ga4', name: 'Google Analytics 4 y Tag Manager', category: 'analytics', description: 'Medición del sitio solo con consentimiento de cookies analíticas.', availability: 'available', status: 'published' },
    { slug: 'planillas', name: 'Sistemas de planillas', category: 'crm', description: 'Importación de trabajadores desde sistemas de planillas.', availability: 'planned', status: 'hidden' },
  ],

  /* ---- Novedades del producto (product_updates) ---- */
  novedades: [
    { slug: 'aula-virtual', title: 'Aula virtual y venta de cursos', body: 'Cursos con video, evaluaciones y certificados verificables dentro de EscudoSST. Los institutos pueden vender sus cursos con pasarela de pagos.', category: 'new', modules: ['AUL', 'VTA'], status: 'published', published_at: '28/10/2026', notify: true, in_app: true },
    { slug: 'matriz-capacitacion', title: 'Matriz de capacitación por cargo con semáforo', body: 'Ve de un vistazo qué cursos le faltan a cada cargo.', category: 'improvement', modules: ['CAP'], status: 'published', published_at: '20/10/2026', notify: false, in_app: true },
    { slug: 'firma-pdf', title: 'Corrección en el PDF de registros firmados', body: 'El PDF ahora incluye todas las firmas cuando hay más de diez participantes.', category: 'fix', modules: ['REG', 'FIR'], status: 'published', published_at: '14/10/2026', notify: false, in_app: true },
  ],

  /* ---- Webinars ---- */
  webinars: [
    { slug: 'expediente-sunafil', title: 'Cómo ordenar tu expediente para una inspección', description: 'Repaso práctico de los documentos del sistema de gestión SST y cómo tenerlos al día.', speaker_name: 'Especialista SST invitado (ejemplo)', speaker_title: 'Consultor SST', starts_at: d15(2026, 11, 12, 18, 0), duration_minutes: 60, meeting_provider: 'meet', meeting_url: '', capacity: 100, status: 'published', recording_public: false, informados: false, ins: [] },
    { slug: 'iperc-sin-excel', title: 'Matriz IPERC sin perderte en el Excel', description: 'Peligros por puesto, valoración del riesgo y controles verificables.', speaker_name: 'Equipo EscudoSST', speaker_title: 'Producto', starts_at: d15(2026, 11, 26, 18, 0), duration_minutes: 60, meeting_provider: 'teams', meeting_url: '', capacity: 3, status: 'published', recording_public: false, informados: false, ins: [] },
    { slug: 'comite-sst', title: 'Elección del Comité SST en línea', description: 'Cómo organizar la elección y las actas del Comité con votación electrónica.', speaker_name: 'Equipo EscudoSST', speaker_title: 'Producto', starts_at: d15(2026, 10, 15, 18, 0), duration_minutes: 60, meeting_provider: 'meet', meeting_url: '', capacity: 100, status: 'finished', recording_public: true, informados: true, ins: [] },
  ],

  /* ---- Reseñas de Google (review_sources / review_snapshots) ---- */
  resenas: {
    provider: 'google', place_id: '', api_secret_ref: '', min_rating: 4, max_to_show: 3, cache_ttl_hours: 24, status: 'paused', last_fetched_at: '', last_error: '',
    snaps: [
      { autor: 'Usuario de ejemplo A', rating: 5, body: 'Nos ayudó a ordenar las inspecciones y el comité revisa todo desde el celular.', f: '12/10/2026' },
      { autor: 'Usuario de ejemplo B', rating: 4, body: 'Buena herramienta para el IPERC. La carga desde Excel fue sencilla.', f: '03/10/2026' },
      { autor: 'Usuario de ejemplo C', rating: 5, body: 'El fotocheck con QR nos ahorra discusiones en la puerta de la obra.', f: '25/09/2026' },
      { autor: 'Usuario de ejemplo D', rating: 3, body: 'Funciona bien; queremos más reportes personalizados.', f: '18/09/2026' },
    ],
  },

  /* ---- Calculadora de ahorro (savings_calculator_settings) ---- */
  ahorro: {
    status: 'published', to_validate: true,
    disclaimer: 'Estimación referencial, no garantizada. Depende de cómo trabaja tu equipo hoy.',
    sup: { horasRegistro: 0.5, horasReportePorSede: 4, reduccion: 40, costoHora: '' }, // horas por registro, horas al mes por sede en reportes, % de reducción, costo hora (vacío)
    calculos: [],
  },
};
/* Origen nuevo de los leads */
Object.assign(WX_FUENTE, { chatbot: 'Chatbot', campaign: 'Campaña', webinar: 'Webinar', savings_calculator: 'Calculadora de ahorro', popup: 'Ventana emergente' });

/* Biblioteca inicial: ilustraciones propias en SVG (no imágenes externas) */
(function () {
  const svg = (fondo, cuerpo) => 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 400"><rect width="640" height="400" fill="' + fondo + '"/>' + cuerpo + '<rect y="372" width="640" height="28" fill="#F4B90F"/><g fill="#0D3B6E">' + Array.from({ length: 23 }, (_, i) => '<path d="M' + (i * 30 - 10) + ' 400l28-28h14l-28 28z"/>').join('') + '</g></svg>');
  const M = [
    ['image', 'Planta industrial (ilustración)', 'Ilustración de una planta industrial con chimenea y casco de seguridad', 'Portada', ['industria'], svg('#E3EFFB', '<rect x="80" y="170" width="200" height="190" fill="#0D3B6E"/><rect x="300" y="210" width="160" height="150" fill="#1F7FD1"/><rect x="110" y="90" width="34" height="80" fill="#0D3B6E"/><path d="M450 300a70 60 0 0 1 140 0z" fill="#F4B90F"/><rect x="440" y="296" width="160" height="16" rx="8" fill="#C98A00"/>')],
    ['image', 'Obra de construcción (ilustración)', 'Ilustración de una obra con grúa y estructura de edificio', 'Sectores', ['construcción'], svg('#EEF1F5', '<path d="M120 60v300M120 70h300M400 70v60" stroke="#0D3B6E" stroke-width="14" fill="none"/><rect x="380" y="130" width="40" height="40" fill="#F4B90F"/><g fill="#1F7FD1"><rect x="220" y="200" width="200" height="160"/></g><g fill="#E3EFFB"><rect x="240" y="220" width="40" height="40"/><rect x="300" y="220" width="40" height="40"/><rect x="360" y="220" width="40" height="40"/></g>')],
    ['image', 'Inspección en campo (ilustración)', 'Ilustración de un celular con una lista de verificación de inspección', 'Portafolio', ['campo', 'inspección'], svg('#DCF4F0', '<rect x="250" y="40" width="150" height="300" rx="22" fill="#0D3B6E"/><rect x="264" y="70" width="122" height="250" rx="8" fill="#FFFFFF"/><g fill="#22A35A"><circle cx="285" cy="110" r="9"/><circle cx="285" cy="150" r="9"/></g><circle cx="285" cy="190" r="9" fill="#E2453C"/><g fill="#C3CFDC"><rect x="302" y="104" width="70" height="12" rx="6"/><rect x="302" y="144" width="60" height="12" rx="6"/><rect x="302" y="184" width="66" height="12" rx="6"/></g>')],
    ['icon', 'Ícono escudo', 'Escudo de EscudoSST', 'Marca', ['logo'], 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><path d="M20 2.5 34.5 7.6v10.6c0 9.3-6.1 16.4-14.5 19.3C11.6 34.6 5.5 27.5 5.5 18.2V7.6Z" fill="#0D3B6E"/><path d="M11.2 23.6c0-5.7 3.9-9.9 8.8-9.9s8.8 4.2 8.8 9.9" fill="#F4B90F"/></svg>')],
    ['document', 'Guía de inicio (PDF)', '', 'Recursos', ['guía'], ''],
    ['video', 'Presentación de EscudoSST', '', 'Videos', ['youtube'], ''],
  ];
  M.forEach(([kind, title, alt, folder, tags, src], i) => V15.medios.push({ id: 'MED-' + String(V15.sigMedio++).padStart(3, '0'), kind, title, alt_text: alt, width: kind === 'image' ? 640 : kind === 'icon' ? 40 : null, height: kind === 'image' ? 400 : kind === 'icon' ? 40 : null, size_bytes: [18400, 16900, 17200, 900, 412000, 0][i], folder, tags, is_example: true, src, f: '01/11/2026' }));
  // Portafolio: año, módulos y galería de los casos (success_stories + success_story_media)
  const extra = { 'metalmecanica-lima': [2026, ['IPC', 'INS', 'ACR'], [['MED-001', 'Planta del cliente (ilustración de ejemplo)']]], 'constructora-arequipa': [2026, ['PTR', 'CTR', 'ING'], [['MED-002', 'Obra (ilustración de ejemplo)']]], 'consultora-trujillo': [2026, ['CON', 'DSH'], []], 'fundo-ica': [2026, ['AUL', 'FOT', 'ACT'], [['MED-003', 'Inspección en campo (ilustración de ejemplo)']]] };
  WX.casos.forEach((c) => { const e = extra[c.slug] || [2026, [], []]; c.project_year = e[0]; c.modules = e[1]; c.galeria = e[2].map(([media_id, caption], k) => ({ media_id, caption, sort_order: k + 1 })); });
})();
WEB.bloques.splice(WEB.bloques.findIndex((b) => b.id === 'testimonios') + 1, 0, { id: 'resenas', l: 'Reseñas de Google', on: true });
WEB.demos.push(
  { id: 'DEM-0137', f: '22/10/2026', nombre: 'Patricia Rojas Vela', empresa: 'Textiles del Pacífico S.A.C. (ejemplo)', ruc: '20512378892', trab: '20 a 200', sector: 'Manufactura', interes: 'Demo', estado: 'Ganada', resp: 'Ventas 1', correo: 'projas@correo-demo.pe' },
  { id: 'DEM-0136', f: '21/10/2026', nombre: 'Martín Chávez Ruiz', empresa: 'Logística Andina Norte S.A.C. (ejemplo)', ruc: '20603419127', trab: '20 a 200', sector: 'Logística', interes: 'Demo', estado: 'Contactada', resp: 'Ventas 2', correo: 'mchavez@correo-demo.pe' },
  { id: 'DEM-0135', f: '20/10/2026', nombre: 'Elena Paredes Soto', empresa: 'Minera Quebrada Alta S.A. (ejemplo)', ruc: '20488812349', trab: 'Más de 200', sector: 'Minería', interes: 'Demo', estado: 'Demo agendada', resp: 'Ventas 1', correo: 'eparedes@correo-demo.pe' },
);
