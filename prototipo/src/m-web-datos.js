/* Módulo WEB · Sitio web público y CMS: estado editable de la demostración (en memoria).
   Lo edita el panel del Dueño de la plataforma (#m-web) y lo lee el sitio público (#web…).
   Precios: ninguno publicado. Si el plan no tiene precio, la web muestra «Solicitar cotización». */
const WEB = {
  ajustes: {
    razon: 'GRUPO DE SOLUCIONES CREATIVAS SAC', ruc: '20607431117', domicilio: '', dominio: 'escudosst.com',
    correo: '', telefono: '', whatsapp: '', horario: 'Lunes a viernes, horario de oficina (por definir)',
    respLibro: 'Por designar', correoLibro: '', marca: 'EscudoSST',
    lema: 'Cumple la Ley 29783 y llega preparado a SUNAFIL',
    heroSub: 'EscudoSST ordena tu sistema de seguridad y salud en el trabajo en un solo lugar. IPERC, inspecciones, capacitaciones, EMO y registros obligatorios, con el expediente listo cuando llegue el inspector.',
    igv: true, plazoLibro: 15, prorrogaLibro: 15, conservaLibro: 5,
    logo: null, logoNombre: 'Logo provisional',
    blogUrl: 'blog.escudosst.com', blogPortada: true,
    anuncio: { on: true, texto: 'Nuevo: aula virtual y venta de cursos dentro de EscudoSST.', enlace: 'Conócela', ruta: 'web/m-aula' },
    ventajas: ['Expediente SUNAFIL ordenado y listo para compartir', 'Fotocheck con QR y reportes sin instalar apps', 'Alertas antes de cada vencimiento, por correo y WhatsApp'],
    redes: { LinkedIn: '', Facebook: '', YouTube: '', Instagram: '' },
  },
  whatsapp: { numero: '', msgs: { web: 'Hola, quiero conocer EscudoSST.', 'web-planes': 'Hola, quiero una cotización de EscudoSST para mi empresa.', 'web-contacto': 'Hola, quiero agendar una demo de EscudoSST.', 'web-consultoras': 'Hola, soy de una consultora SST y quiero conocer el plan Consultora.', sector: 'Hola, quiero ver EscudoSST para el sector {sector}.', otras: 'Hola, tengo una consulta sobre EscudoSST.' } },
  videos: [
    { id: 'v1', t: 'Presentación de EscudoSST', d: 'Un recorrido por el sistema: del panel al expediente SUNAFIL.', prov: 'YouTube', url: '', on: true },
    { id: 'v2', t: 'Tutorial: tu primer expediente SUNAFIL', d: 'Autodiagnóstico, documentos pendientes y enlace para el inspector.', prov: 'Vimeo', url: '', on: true },
  ],
  productos: [
    { id: 1, n: 'Gestión de personal', d: 'Planillas, legajos y vacaciones para pymes.', url: '', color: '#1FB5A3', ini: 'GP', on: true },
    { id: 2, n: 'Agenda de citas', d: 'Reservas en línea y recordatorios por WhatsApp.', url: '', color: '#F08A24', ini: 'AC', on: true },
    { id: 3, n: 'Punto de venta', d: 'Ventas, inventario y comprobantes electrónicos.', url: '', color: '#22A35A', ini: 'PV', on: true },
    { id: 4, n: 'Aula para colegios', d: 'Notas, tareas y comunicación con las familias.', url: '', color: '#E2453C', ini: 'AE', on: true },
  ],
  testimonios: [
    { id: 1, ini: 'MR', cargo: 'Jefa de SST', emp: 'Empresa manufacturera, Lima', txt: 'Antes armábamos el expediente en una semana. Ahora lo tenemos al día y el comité revisa todo desde el celular.', estado: 'Aprobado' },
    { id: 2, ini: 'JC', cargo: 'Gerente de operaciones', emp: 'Constructora, Arequipa', txt: 'Los PETAR se aprueban en obra y sabemos quién entra sin SCTR antes de que pase la puerta.', estado: 'Aprobado' },
    { id: 3, ini: 'LP', cargo: 'Consultor SST', emp: 'Consultora, Trujillo', txt: 'Atiendo a mis clientes con mi propia marca y veo sus alertas en un solo panel.', estado: 'Aprobado' },
    { id: 4, ini: 'RS', cargo: 'Supervisor SST', emp: 'Fundo agroexportador, Ica', txt: 'Las inducciones de campaña dejaron de ser un caos.', estado: 'Pendiente' },
  ],
  planes: [
    { id: 'E', nombre: 'Esencial', para: 'Para empresas con Supervisor SST que empiezan a ordenar su sistema.', lim: ['Menos de 20 trabajadores (referencial)', 'Supervisor SST como figura legal', 'Sedes y usuarios por definir'], m: null, a: null, publicado: true, destacado: false },
    { id: 'P', nombre: 'Profesional', para: 'Para empresas con Comité SST que quieren llegar preparadas a SUNAFIL.', lim: ['De 20 a 200 trabajadores (referencial)', 'Modo Inspección SUNAFIL y fotocheck QR', 'Aula virtual y venta de cursos'], m: null, a: null, publicado: true, destacado: true },
    { id: 'C', nombre: 'Corporativo', para: 'Para operaciones grandes, varias sedes o actividades de alto riesgo.', lim: ['Más de 200 trabajadores (referencial)', 'PETAR, contratistas e ingreso por QR', 'API e integraciones'], m: null, a: null, publicado: true, destacado: false },
    { id: 'K', nombre: 'Consultora', para: 'Para consultoras SST que gestionan varias empresas con su propia marca.', lim: ['Precio por empresa gestionada', 'Cada empresa cliente conserva su plan', 'Marca blanca: logo y color'], m: null, a: null, publicado: true, destacado: false },
  ],
  addonsPrecio: {}, // código → { m, a } · vacío = «Solicitar cotización»
  menu: [
    { id: 'productos', l: 'Productos', r: 'web-producto', on: true },
    { id: 'como', l: 'Cómo funciona', r: 'web/como-funciona', on: true },
    { id: 'planes', l: 'Planes', r: 'web-planes', on: true },
    { id: 'sectores', l: 'Sectores', r: 'web-sectores', on: true },
    { id: 'consultoras', l: 'Consultoras', r: 'web-consultoras', on: true },
    { id: 'blog', l: 'Blog', r: 'blog', on: true },
    { id: 'contacto', l: 'Contacto', r: 'web-contacto', on: true },
  ],
  bloques: [
    { id: 'hero', l: 'Héroe con ventajas', on: true },
    { id: 'confianza', l: 'Sellos de confianza', on: true },
    { id: 'videos', l: 'Videos', on: true },
    { id: 'modulos', l: 'Módulos (bloques con maqueta)', on: true },
    { id: 'grupos', l: 'Todos los módulos por grupo', on: true },
    { id: 'pasos', l: 'Cómo funciona', on: true },
    { id: 'planes', l: 'Planes y precios', on: true },
    { id: 'sectores', l: 'Sectores', on: true },
    { id: 'demo', l: 'Demostración', on: true },
    { id: 'testimonios', l: 'Comentarios', on: true },
    { id: 'blog', l: 'Últimos artículos del blog', on: true },
    { id: 'productos', l: 'Otros productos', on: true },
    { id: 'faq', l: 'Preguntas frecuentes', on: true },
    { id: 'cta', l: 'Llamado final', on: true },
  ],
  paginas: {},
  faqs: [
    { id: 1, p: 'portada', q: '¿EscudoSST reemplaza a mi Jefe o Supervisor SST?', a: 'No. Es la herramienta con la que tu responsable SST trabaja. Le quita el trabajo de armar Excel, perseguir firmas y vigilar vencimientos, para que dedique su tiempo a la prevención.' },
    { id: 2, p: 'portada', q: '¿Sirve para empresas con menos de 20 trabajadores?', a: 'Sí. El plan Esencial está pensado para empresas con Supervisor SST. Cuando la empresa crece y necesita Comité, puedes pasar a Profesional sin perder datos.' },
    { id: 3, p: 'portada', q: '¿Qué pasa con los datos médicos de mis trabajadores?', a: 'Se guardan en una base separada y cifrada. Solo el personal de salud autorizado los ve, con una clave adicional, y cada acceso queda registrado según la Ley 29733.' },
    { id: 4, p: 'portada', q: '¿Mis trabajadores necesitan instalar una app?', a: 'No para reportar: escanean un QR o escriben por WhatsApp. Para firmar, ver su fotocheck o llevar cursos usan una app web que se abre desde el celular.' },
    { id: 5, p: 'portada', q: '¿Funciona sin señal en campo?', a: 'Con el complemento App móvil offline puedes inspeccionar y registrar sin conexión. Al recuperar señal, la información se sincroniza y los conflictos quedan registrados.' },
    { id: 6, p: 'portada', q: '¿Puedo migrar mi información actual?', a: 'Sí. Puedes importar trabajadores desde Excel con validación por fila y empezar con un diagnóstico de línea base.' },
    { id: 7, p: 'planes', q: '¿Cómo se factura?', a: 'Por suscripción mensual o anual, con comprobante electrónico. Las condiciones exactas se detallan en la cotización.' },
    { id: 8, p: 'planes', q: '¿Puedo cambiar de plan?', a: 'Sí, en cualquier momento. Si bajas de plan, tus datos no se borran: los módulos que dejas de usar quedan en solo lectura.' },
    { id: 9, p: 'planes', q: '¿Qué son los complementos?', a: 'Funciones que se suman a cualquier plan, como la app offline, el asistente IA o los paquetes sectoriales. Contratas solo lo que necesitas.' },
    { id: 10, p: 'planes', q: '¿Cómo cobra el plan Consultora?', a: 'Por empresa gestionada. Cada empresa cliente conserva su propio plan y sus datos separados.' },
    { id: 11, p: 'planes', q: '¿Hay periodo de prueba?', a: 'Puedes registrarte con el RUC de tu empresa y probar la plataforma. Las condiciones de la prueba se informan al registrarte.' },
  ],
  ultimos: [
    { t: 'Cómo preparar tu expediente para una inspección de SUNAFIL', cat: 'SUNAFIL', f: '28/10/2026', ex: 'Documentos que conviene tener al día y errores frecuentes.' },
    { t: 'Matriz IPERC: cómo identificar peligros sin perderte en el Excel', cat: 'Gestión de riesgos', f: '21/10/2026', ex: 'Peligros por puesto, valoración y controles verificables.' },
    { t: 'Datos médicos de tus trabajadores: lo que exige la Ley 29733', cat: 'Salud ocupacional', f: '07/10/2026', ex: 'Por qué la aptitud no es lo mismo que el diagnóstico.' },
  ],
  politicas: [
    { id: 'terminos', t: 'Términos y condiciones', corto: 'Términos', vers: [{ v: '1.0', f: '01/11/2026', estado: 'Vigente', re: false, nota: 'Primera versión publicada' }, { v: '0.9', f: '15/10/2026', estado: 'Archivada', re: false, nota: 'Borrador interno para revisión legal' }],
      sec: ['Objeto y aceptación', 'Definiciones', 'Registro y cuenta', 'Planes, pagos y facturación', 'Uso aceptable', 'Datos de la empresa cliente', 'Propiedad intelectual', 'Disponibilidad y soporte', 'Limitación de responsabilidad', 'Suspensión y terminación', 'Modificaciones', 'Ley aplicable y jurisdicción', 'Contacto'] },
    { id: 'privacidad', t: 'Política de privacidad y protección de datos personales', corto: 'Privacidad', vers: [{ v: '1.0', f: '01/11/2026', estado: 'Vigente', re: false, nota: 'Primera versión publicada' }],
      sec: ['Titular del banco de datos', 'Datos personales que tratamos', 'Finalidades del tratamiento', 'Consentimiento y base legal', 'Datos sensibles de salud', 'Encargados de tratamiento', 'Flujo transfronterizo', 'Plazo de conservación', 'Medidas de seguridad', 'Derechos de los titulares (ARCO)', 'Cambios a esta política'] },
    { id: 'cookies', t: 'Política de cookies', corto: 'Cookies', vers: [{ v: '1.0', f: '01/11/2026', estado: 'Vigente', re: false, nota: 'Primera versión publicada' }],
      sec: ['Qué son las cookies', 'Cookies necesarias', 'Cookies de analítica', 'Cookies de marketing', 'Lista de cookies utilizadas', 'Cómo cambiar tus preferencias', 'Cambios a esta política'] },
    { id: 'encargo', t: 'Contrato de encargo de tratamiento de datos', corto: 'Encargo de tratamiento', vers: [{ v: '1.0', f: '01/11/2026', estado: 'Vigente', re: false, nota: 'Primera versión publicada' }],
      sec: ['Partes', 'Objeto del encargo', 'Instrucciones del responsable', 'Confidencialidad', 'Medidas de seguridad', 'Subencargados', 'Asistencia en el ejercicio de derechos', 'Notificación de incidentes', 'Devolución y supresión de datos', 'Auditoría y verificación'] },
    { id: 'sla', t: 'Acuerdo de nivel de servicio', corto: 'Nivel de servicio', vers: [{ v: '1.0', f: '01/11/2026', estado: 'Vigente', re: false, nota: 'Primera versión publicada' }],
      sec: ['Alcance', 'Disponibilidad comprometida', 'Mantenimientos programados', 'Niveles de severidad', 'Canales de soporte', 'Créditos de servicio', 'Exclusiones', 'Reportes de servicio'] },
  ],
  aceptaciones: [
    ['Industrias Andinas del Sur S.A.C.', 'Términos y condiciones', '1.0', '01/11/2026 09:12', '190.42.xxx.xxx', 'Chrome · Windows'],
    ['Constructora Huaylas Ingenieros S.A.C.', 'Contrato de encargo de tratamiento de datos', '1.0', '01/11/2026 10:03', '181.65.xxx.xxx', 'Edge · Windows'],
    ['Prevencia Consultores SST', 'Términos y condiciones', '1.0', '01/11/2026 11:47', '200.37.xxx.xxx', 'Safari · macOS'],
  ],
  reclamos: [
    { cod: 'LR-2026-000026', f: '02/10/2026 16:20', tipo: 'Reclamo', nombre: 'Héctor Valdivia Ramos', doc: 'DNI 40918273', correo: 'h.valdivia@correo-demo.pe', bien: 'Servicio', monto: '', det: 'Se cobró la renovación anual sin el aviso previo que esperaba.', ped: 'Revisión del cargo y emisión de nota de crédito si corresponde.', estado: 'Respondido', vence: '23/10/2026', hist: [['02/10/2026 16:20', 'Hoja registrada y constancia enviada por correo'], ['05/10/2026 10:02', 'Asignado a Atención al cliente'], ['16/10/2026 12:40', 'Respuesta enviada al consumidor']] },
    { cod: 'LR-2026-000027', f: '09/10/2026 09:41', tipo: 'Queja', nombre: 'Ana Lucía Ferrer Gómez', doc: 'DNI 45120933', correo: 'alferrer@correo-demo.pe', bien: 'Servicio', monto: '', det: 'Demora en la atención por el chat de soporte.', ped: 'Mejorar el tiempo de respuesta.', estado: 'Respondido', vence: '30/10/2026', hist: [['09/10/2026 09:41', 'Hoja registrada y constancia enviada por correo'], ['20/10/2026 15:10', 'Respuesta enviada al consumidor']] },
    { cod: 'LR-2026-000028', f: '17/10/2026 18:05', tipo: 'Reclamo', nombre: 'Julio César Atoche Rivas', doc: 'RUC 10457788213', correo: 'jatoche@correo-demo.pe', bien: 'Servicio', monto: '', det: 'No pude descargar el comprobante de mi suscripción.', ped: 'Envío del comprobante por correo.', estado: 'En proceso', vence: '07/11/2026', hist: [['17/10/2026 18:05', 'Hoja registrada y constancia enviada por correo'], ['20/10/2026 09:30', 'Asignado a Facturación']] },
    { cod: 'LR-2026-000029', f: '21/10/2026 11:12', tipo: 'Reclamo', nombre: 'Rosa Elvira Cahuana Mesa', doc: 'DNI 41003388', correo: 'rcahuana@correo-demo.pe', bien: 'Servicio', monto: '', det: 'La migración de datos desde Excel tomó más de lo informado.', ped: 'Compensación en la siguiente factura.', estado: 'Prorrogado', vence: '02/11/2026', hist: [['21/10/2026 11:12', 'Hoja registrada y constancia enviada por correo'], ['28/10/2026 17:22', 'Prórroga: se requiere información del área técnica']] },
    { cod: 'LR-2026-000030', f: '29/10/2026 20:47', tipo: 'Queja', nombre: 'Miguel Ángel Sifuentes Lazo', doc: 'CE 001928374', correo: 'msifuentes@correo-demo.pe', bien: 'Servicio', monto: '', det: 'El formulario de demo no confirmó el envío.', ped: 'Que me contacten.', estado: 'Nuevo', vence: '19/11/2026', hist: [['29/10/2026 20:47', 'Hoja registrada y constancia enviada por correo']] },
  ],
  sigReclamo: 31,
  demos: [
    { id: 'DEM-0141', f: '30/10/2026', nombre: 'Lucía Benavides Paz', empresa: 'Metalmecánica Pachacámac S.A.C.', ruc: '20512378892', trab: '20 a 200', sector: 'Manufactura', interes: 'Demo', estado: 'Nueva', resp: 'Sin asignar' },
    { id: 'DEM-0140', f: '29/10/2026', nombre: 'Fernando Ríos Alarcón', empresa: 'Obras y Montajes Rímac S.A.C.', ruc: '20603419127', trab: 'Más de 200', sector: 'Construcción', interes: 'Demo', estado: 'Contactada', resp: 'Ventas 1' },
    { id: 'DEM-0139', f: '27/10/2026', nombre: 'Carolina Ugarte Neyra', empresa: 'Asesores SST del Norte E.I.R.L.', ruc: '20605581234', trab: 'Menos de 20', sector: 'Consultora SST', interes: 'Consultora', estado: 'Demo agendada', resp: 'Ventas 2' },
    { id: 'DEM-0138', f: '24/10/2026', nombre: 'Raúl Espinoza Tello', empresa: 'Agrícola Santa Inés S.A.', ruc: '20488812349', trab: '20 a 200', sector: 'Agroindustria', interes: 'Prueba gratis', estado: 'Descartada', resp: 'Ventas 1' },
  ],
  sigDemo: 142,
  responsables: ['Sin asignar', 'Ventas 1', 'Ventas 2', 'Dueño de la plataforma'],
  redirecciones: [
    { de: '/precios', a: '/planes', tipo: '301', hits: 0 },
    { de: '/recursos', a: 'https://blog.escudosst.com', tipo: '301', hits: 0 },
    { de: '/sector/obras', a: '/sectores/construccion', tipo: '301', hits: 0 },
  ],
  cookies: null, // null = sin decidir; { n:true, a:bool, m:bool }
};

/* Páginas del sitio con SEO editable. clave = ruta pública */
const WEB_PAGINAS = [
  ['web', 'Portada', 'EscudoSST · Software SST para cumplir la Ley 29783 en Perú', 'Gestiona tu sistema de seguridad y salud en el trabajo: IPERC, inspecciones, capacitaciones, EMO y Modo Inspección SUNAFIL. Prueba gratis con tu RUC.', '/'],
  ['web-producto', 'Producto', 'Producto · Módulos y diferenciadores de EscudoSST', 'Los módulos de EscudoSST para planificar, gestionar riesgos, operar en campo, cuidar la salud ocupacional y mejorar de forma continua.', '/producto'],
  ['web-planes', 'Planes y precios', 'Planes y precios · EscudoSST', 'Compara los planes Esencial, Profesional, Corporativo y Consultora módulo por módulo. Solicita una cotización para tu empresa.', '/planes'],
  ['web-sectores', 'Sectores', 'Software SST por sector · EscudoSST', 'Construcción, minería, manufactura, agroindustria y oficinas: plantillas y módulos pensados para cada sector.', '/sectores'],
  ['web-sector-construccion', 'Sector construcción', 'Software SST para construcción · EscudoSST', 'PETAR, ATS, contratistas y SCTR para obras en Perú. Controla trabajos de alto riesgo y llega preparado a SUNAFIL.', '/sectores/construccion'],
  ['web-sector-mineria', 'Sector minería', 'Software SST para minería · EscudoSST', 'Gestión de seguridad y salud ocupacional para operaciones mineras y contratistas mineros en Perú.', '/sectores/mineria'],
  ['web-sector-manufactura', 'Sector manufactura', 'Software SST para manufactura · EscudoSST', 'IPERC por puesto, inspecciones de planta, EPP y monitoreo de agentes para plantas industriales.', '/sectores/manufactura'],
  ['web-sector-agroindustria', 'Sector agroindustria', 'Software SST para agroindustria · EscudoSST', 'Inducciones masivas por campaña, fotocheck QR y reportes por WhatsApp para fundos y plantas de empaque.', '/sectores/agroindustria'],
  ['web-sector-oficinas', 'Sector oficinas y servicios', 'Software SST para oficinas y servicios · EscudoSST', 'Ergonomía, riesgos psicosociales, comité y capacitaciones virtuales para empresas de servicios.', '/sectores/oficinas'],
  ['web-consultoras', 'Consultoras', 'EscudoSST para consultoras SST · Marca blanca', 'Gestiona todas tus empresas cliente desde un panel, con tu logo y tu color. Cada cliente conserva su plan y sus datos separados.', '/consultoras'],
  ['web-contacto', 'Contacto y demo', 'Contacto y demo · EscudoSST', 'Agenda una demo, escríbenos o solicita una cotización de EscudoSST para tu empresa.', '/contacto'],
  ['web-reclamaciones', 'Libro de Reclamaciones', 'Libro de Reclamaciones · EscudoSST', 'Registra un reclamo o una queja en el Libro de Reclamaciones virtual de EscudoSST y consulta su estado con tu código.', '/libro-de-reclamaciones'],
  ['web-legal-terminos', 'Términos y condiciones', 'Términos y condiciones · EscudoSST', 'Términos y condiciones de uso de la plataforma EscudoSST.', '/legal/terminos'],
  ['web-legal-privacidad', 'Política de privacidad', 'Política de privacidad y protección de datos · EscudoSST', 'Cómo EscudoSST trata los datos personales conforme a la Ley 29733 y su reglamento.', '/legal/privacidad'],
  ['web-legal-cookies', 'Política de cookies', 'Política de cookies · EscudoSST', 'Qué cookies usa el sitio de EscudoSST y cómo cambiar tus preferencias.', '/legal/cookies'],
  ['web-legal-encargo', 'Encargo de tratamiento', 'Contrato de encargo de tratamiento de datos · EscudoSST', 'Condiciones con las que EscudoSST trata los datos de las empresas clientes como encargado.', '/legal/encargo-de-tratamiento'],
  ['web-legal-sla', 'Acuerdo de nivel de servicio', 'Acuerdo de nivel de servicio · EscudoSST', 'Disponibilidad, soporte y créditos de servicio de la plataforma EscudoSST.', '/legal/nivel-de-servicio'],
];
WEB_PAGINAS.forEach(([k, n, t, d, slug], i) => {
  WEB.paginas[k] = { n, estado: 'Publicado', seo: { t, d, slug, og: 'auto', noindex: k === 'web-reclamaciones' }, editado: '01/11/2026' };
});
WEB.paginas['web-sector-oficinas'].estado = 'Borrador';

/* Sectores: plantilla común */
const WEB_SECTORES = {
  construccion: { n: 'Construcción', ic: 'crane', h: 'Obras seguras, contratistas en regla y PETAR sin papel', d: 'Varias obras, cuadrillas que rotan y subcontratistas que cambian cada semana. EscudoSST te da el control de cada frente de trabajo desde el celular.',
    dolores: [['Trabajos de alto riesgo todos los días', 'Altura, excavaciones, izaje y trabajos en caliente con permisos que se firman en papel y se pierden.'], ['Contratistas que entran sin SCTR vigente', 'Validar a cada trabajador de un subcontratista a la entrada de obra toma tiempo y genera riesgo.'], ['Personal que rota entre obras', 'Inducciones, EPP y capacitaciones difíciles de seguir cuando la gente cambia de frente.']],
    mods: ['PTR', 'ATS', 'CTR', 'SCT', 'ING', 'INS', 'EPP', 'FOT', 'OFF'], norma: 'Ley 29783 y su reglamento, la norma de seguridad para el sector construcción y la norma técnica de seguridad durante la construcción, además de las disposiciones sobre seguro complementario de trabajo de riesgo (SCTR).',
    ej: 'Una constructora con tres obras activa el paquete de construcción: cada PETAR se aprueba en cadena desde el celular, el control de ingreso rechaza a quien no tiene SCTR vigente y el expediente de cada obra se arma solo.' },
  mineria: { n: 'Minería', ic: 'pick', h: 'Seguridad minera con trazabilidad de punta a punta', d: 'Operaciones remotas, contratistas mineros y exigencias del sector. Registra en campo sin señal y consolida todo en un mismo sistema.',
    dolores: [['Zonas sin cobertura', 'Las inspecciones en mina o en campamento se llenan en papel y se transcriben días después.'], ['Muchas empresas contratistas', 'Cada contratista tiene su documentación, sus trabajadores y sus vencimientos.'], ['Estándares y permisos exigentes', 'PETS, PETAR y observaciones de conducta que deben quedar firmadas y trazables.']],
    mods: ['OFF', 'PTR', 'PET', 'OBS', 'CTR', 'MAG', 'EMO', 'IPC', 'INV'], norma: 'Ley 29783 y su reglamento, y el reglamento de seguridad y salud ocupacional en minería (D.S. 024-2016-EM) y sus modificatorias.',
    ej: 'Una contratista minera usa la app offline en interior mina: las inspecciones y observaciones se sincronizan al volver a superficie y el residente ve los hallazgos en el tablero del día.' },
  manufactura: { n: 'Manufactura', ic: 'gear', h: 'Tu planta bajo control, puesto por puesto', d: 'Máquinas, agentes físicos y químicos, turnos y mucho personal. Ordena el IPERC por puesto y convierte cada hallazgo en una acción con responsable.',
    dolores: [['IPERC desactualizado', 'La matriz vive en un Excel que nadie revisa cuando cambia una máquina o un proceso.'], ['Hallazgos que no se cierran', 'Las inspecciones encuentran lo mismo cada mes porque las acciones no tienen dueño ni fecha.'], ['Monitoreos y EPP dispersos', 'Ruido, iluminación, polvo y entregas de EPP en archivos distintos.']],
    mods: ['IPC', 'MAP', 'INS', 'ACT', 'ACR', 'EPP', 'MAG', 'CH5', 'EST'], norma: 'Ley 29783 y su reglamento, normas sobre agentes físicos, químicos y ergonómicos, y los formatos referenciales de registros obligatorios.',
    ej: 'Una planta de envases pega códigos QR en cada zona: los operarios reportan condiciones inseguras desde el celular y cada reporte se convierte en una acción correctiva con responsable.' },
  agroindustria: { n: 'Agroindustria', ic: 'leaf', h: 'Campañas con cientos de ingresos, sin perder el control', d: 'Personal temporal que llega por campaña, fundos extensos y plantas de empaque. Induce, identifica y escucha a tu gente a escala.',
    dolores: [['Inducciones masivas', 'Cientos de ingresos por campaña que deben recibir inducción y EPP antes de empezar.'], ['Personal sin correo ni app', 'Muchos trabajadores solo usan WhatsApp y comparten celular.'], ['Agroquímicos y ergonomía', 'Exposición a sustancias y tareas repetitivas que exigen control y vigilancia.']],
    mods: ['CAP', 'AUL', 'FOT', 'ACT', 'EPP', 'PSI', 'EMO', 'TRB', 'PSE'], norma: 'Ley 29783 y su reglamento, la norma básica de ergonomía y las disposiciones sobre manejo de sustancias químicas y plaguicidas.',
    ej: 'Un fundo carga por Excel a los ingresantes de campaña, los matricula por cargo en la inducción del aula y entrega el fotocheck QR solo a quien aprobó y recibió su EPP.' },
  oficinas: { n: 'Oficinas y servicios', ic: 'building', h: 'Cumple sin complicarte aunque tu riesgo sea bajo', d: 'Empresas de servicios, oficinas y trabajo remoto también tienen obligaciones. Cúmplelas con lo justo y sin papeleo.',
    dolores: [['Se cree que no aplica', 'Muchas oficinas descubren sus obligaciones recién con una inspección.'], ['Riesgos ergonómicos y psicosociales', 'Pantallas, posturas, carga de trabajo y teletrabajo.'], ['Comité y capacitaciones al día', 'Elecciones, actas y las capacitaciones anuales que se postergan.']],
    mods: ['LBA', 'POL', 'COM', 'PCA', 'AUL', 'PSI', 'REG', 'SUN', 'DOC'], norma: 'Ley 29783 y su reglamento, la norma básica de ergonomía, la normativa de teletrabajo y las disposiciones sobre riesgos psicosociales.',
    ej: 'Una empresa de servicios elige a su Comité con votación electrónica, dicta las capacitaciones anuales en el aula virtual y mantiene el expediente SUNAFIL al día.' },
};

/* Iconos propios para el sitio (24×24, trazo) */
Object.assign(IC, {
  crane: 'M4 21h8M7 21V5l12 3M7 5l-3 3M7 9h11M15 8v6M13 14h4v3h-4z',
  pick: 'M4 20l9-9M14 4c3 0 6 3 6 6M8 6c4-3 9-3 12 2M10 8l6 6',
  gear: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
  leaf: 'M5 21c0-9 5-15 15-16-1 10-7 15-15 16zM5 21l8-8',
  cloud: 'M7 18a5 5 0 1 1 1-9.9A6 6 0 0 1 19 10a4 4 0 0 1-1 8zM10 14l2 2 3-4',
  map: 'M9 4 3 6v14l6-2 6 2 6-2V4l-6 2zM9 4v14M15 6v14',
  globe: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM2 12h20M12 2c3 3 4 6.5 4 10s-1 7-4 10c-3-3-4-6.5-4-10s1-7 4-10z',
  mail: 'M3 5h18v14H3zM3 6l9 7 9-7',
  doc2: 'M6 2h9l5 5v15H6zM14 2v6h6M9 13h6M9 17h6',
  ext: 'M14 4h6v6M20 4l-9 9M18 14v6H4V6h6',
  layers: 'M12 3 2 8l10 5 10-5zM2 13l10 5 10-5M2 17.5l10 5 10-5',
  dn: 'M12 5v14M6 13l6 6 6-6',
  drag: 'M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01',
});

const precioTxt = (v, per) => 'S/ ' + Number(v).toLocaleString('es-PE', { maximumFractionDigits: 2 }) + (per === 'a' ? ' /año' : ' /mes');
const modsDePlan = (k) => MODS.filter((x) => (CAT.plan_incluye[k] || []).includes(x.plan)).length;
const ajusteTxt = (v, def) => (v && String(v).trim()) ? esc(v) : '<span class="w-pend">' + esc(def || 'Por completar') + '</span>';
