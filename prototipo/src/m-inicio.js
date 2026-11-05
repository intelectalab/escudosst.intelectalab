/* Recorrido del prototipo y dashboards (DSH) */
function irComo(rol, ruta, extra) {
  S.role = rol;
  if (rol === 'consultant') aplicarEmpresa('andinas'); else if (rol === 'external_learner') aplicarEmpresa('qhaliy'); else { S.empresa = 'andinas'; S.plan = 'P'; S.addons = new Set(['OFF', 'IAA', 'PSE']); }
  if (extra) extra();
  guardar(); $('#app').innerHTML = '';
  if (location.hash === '#' + ruta) render(); else location.hash = ruta;
}
R.inicio = (root) => {
  const tours = [
    ['sst_manager', 'm-aul', 'Aula virtual y venta de cursos', 'Industrias Andinas capacita a su personal con el aula (venta desactivada); el Instituto Qhaliy además vende cursos.', ['Curso por dentro: contenido, participantes, notas, evaluaciones y configuración', 'Banco de preguntas e importación desde Excel', 'Recomendaciones de cursos y su conversión', 'Matriz por cargo y certificados con QR', 'Venta en Qhaliy: pedidos, cupos, pasarela, comprobantes y catálogo público'], 'aula'],
    ['sst_manager', 'm-dsh', 'Jefa SST', 'Rocío gestiona el sistema de Industrias Andinas del Sur.', ['Panel con PASST, índices y vencimientos', 'Matriz IPERC editable y envío a aprobación', 'Inspección con hallazgos → acción correctiva', 'Modo Inspección SUNAFIL y expediente', 'Accidente con cuenta regresiva de notificación']],
    ['worker', 'app-trabajador', 'Trabajadora', 'Carmen usa la app desde su celular, con botones grandes.', ['Mis pendientes y firma del RISST', 'Mi fotocheck con QR', 'Reportar una condición insegura', 'Votar en la elección del Comité', 'Ver mis propios resultados médicos']],
    ['consultant', 'm-con', 'Consultora', 'Renzo, de Prevencia Consultores, atiende 4 empresas con su propia marca.', ['Tabla de empresas con cumplimiento y alertas', 'Cambiar de empresa cliente (cambia el plan)', 'Marca blanca: logo y color de Prevencia', 'Entrar al panel de cada empresa']],
    ['occupational_doctor', 'm-emo', 'Médica ocupacional', 'La Dra. Medina es la única que ve datos médicos, con clave adicional.', ['Lista de EMO con aptitud y vigencias', 'Abrir el detalle médico → re-autenticación', 'Aviso de registro de acceso (Ley 29733)', 'Compara: como RR. HH. solo ves la aptitud']],
    ['platform_owner', 'm-sus', 'Dueño del SaaS', 'El equipo EscudoSST administra cuentas, planes y complementos.', ['Cuentas, suscripciones y empresas en prueba', 'Módulos por plan y complementos', 'Salud del sistema', 'Registro autoservicio de una empresa nueva']],
  ];
  root.innerHTML = '<div style="max-width:1100px">' +
    '<div class="row" style="gap:14px;margin-bottom:14px">' + '<span style="color:var(--brand)">' + LOGO(52) + '</span><div><h1>Recorrido del prototipo</h1><p class="muted">EscudoSST · Cumple la Ley 29783 y llega preparado a SUNAFIL</p></div></div>' +
    '<div class="card" style="margin-bottom:16px"><div class="grid g3">' +
    '<div><h3>1. Elige quién eres</h3><p class="small muted" style="margin-top:4px">En la barra oscura de arriba, «Ver como» cambia entre los 17 roles; «Empresa» alterna entre Industrias Andinas (solo capacita a su personal) y el Instituto Qhaliy (vende cursos). El menú, los indicadores y los botones se adaptan.</p></div>' +
    '<div><h3>2. Prueba los planes</h3><p class="small muted" style="margin-top:4px">«Plan» y «Complementos» activan o bloquean módulos. Los no incluidos se ven con candado. No hay precios: están por definir.</p></div>' +
    '<div><h3>3. Navega con libertad</h3><p class="small muted" style="margin-top:4px">Todo es simulado con datos inventados: nada se guarda ni se envía. Los botones muestran lo que pasaría en el sistema real.</p></div>' +
    '</div></div>' +
    '<h2 style="margin:20px 0 10px">Recorridos recomendados</h2><div class="grid g3">' +
    tours.map((t, i) => '<div class="tour' + (t[5] ? ' nuevo' : '') + '"><div class="row" style="gap:10px"><span class="av">' + (t[5] ? ic('book', 18) : iniciales(USUARIOS[t[0]][0].replace('Dra. ', ''))) + '</span><div><h3>' + esc(t[2]) + '</h3><span class="small muted">' + (t[5] ? 'Nuevo · plan Profesional' : esc(rolNom(t[0]))) + '</span></div></div><p class="small">' + esc(t[3]) + '</p><ol>' + t[4].map((x) => '<li>' + esc(x) + '</li>').join('') + '</ol>' +
      (t[5] ? '<div class="row" style="gap:6px;margin-top:auto"><button class="btn pri" data-tour="' + i + '">Empezar como Jefa SST</button><button class="btn sm" data-aula="trainer">Como Capacitador</button><button class="btn sm" data-aula="worker">Como Trabajadora</button><button class="btn sm" data-aula="qhaliy">Instituto con venta</button><button class="btn sm" data-aula="external_learner">Como Participante externo</button><a class="btn sm" href="#pub-cursos">Catálogo público</a></div>' : '<button class="btn pri" data-tour="' + i + '" style="align-self:flex-start;margin-top:auto">Empezar recorrido</button>') + '</div>').join('') +
    '<div class="tour nuevo"><div class="row" style="gap:10px"><span class="av">' + ic('globe', 18) + '</span><div><h3>Sitio público y su panel</h3><span class="small muted">Nuevo · módulo interno WEB</span></div></div><p class="small">La web comercial de EscudoSST: portada, planes con comparativa, sectores, políticas y Libro de Reclamaciones. El Dueño de la plataforma la edita desde «Sitio web».</p><ol><li>Portada con módulos, videos, demo y WhatsApp</li><li>Planes: escribe un precio en el panel y mira la tarjeta</li><li>Libro de Reclamaciones: registra y consulta una hoja</li><li>Panel: páginas y SEO, reclamos, demos, datos legales</li></ol><div class="row" style="gap:6px;margin-top:auto"><a class="btn pri" href="#web">Abrir el sitio público</a><button class="btn sm" data-web-panel>Panel «Sitio web»</button><a class="btn sm" href="#web-planes">Planes</a><a class="btn sm" href="#web-reclamaciones">Libro de Reclamaciones</a></div></div>' +
    '<div class="tour"><h3>Páginas públicas</h3><p class="small">Lo que ve alguien sin cuenta, desde su celular.</p><div class="stack" style="gap:8px"><a class="btn" href="#pub-reporte">' + ic('qr', 16) + 'Reporte por QR</a><a class="btn" href="#pub-verificar">' + ic('shield', 16) + 'Verificar un fotocheck</a><a class="btn" href="#sun-inspector">' + ic('eye', 16) + 'Lo que ve el inspector SUNAFIL</a><a class="btn" href="#pub-certificado">' + ic('award', 16) + 'Verificar un certificado</a><a class="btn" href="#pub-cursos">' + ic('cart', 16) + 'Catálogo de cursos a la venta</a><a class="btn" href="#web">' + ic('globe', 16) + 'Sitio web de EscudoSST</a></div></div>' +
    '</div>' +
    '<h2 style="margin:24px 0 6px">Mapa de los ' + MODS.length + ' módulos</h2><p class="small muted" style="margin-bottom:12px">Cada enlace abre su pantalla con el rol y plan actuales. Si ves un candado, cambia el plan o el rol en la barra superior.</p>' +
    Object.entries(CAT.grupos).map(([g, gn]) => '<h3 style="margin:14px 0 8px">' + esc(gn) + '</h3><div class="modmap">' + MODS.filter((m) => m.grupo === +g).map((m) => '<a href="#m-' + m.code.toLowerCase() + '"><span class="mc">' + m.code + '</span><span class="mn">' + esc(m.nombre) + '</span><span class="pill-plan">' + (m.plan === '+' ? 'Compl.' : m.plan === 'K' ? 'Consult.' : m.plan === 'I' ? 'Interno' : m.plan_nombre) + '</span></a>').join('') + '</div>').join('') +
    '</div>';
  $$('[data-web-panel]', root).forEach((b) => b.addEventListener('click', () => { irComo('platform_owner', 'm-web/paginas'); toast('Panel «Sitio web» del Dueño de la plataforma'); }));
  $$('[data-tour]', root).forEach((b) => b.addEventListener('click', () => { const t = tours[+b.dataset.tour]; irComo(t[0], t[1]); toast('Recorrido: ' + t[2]); }));
  $$('[data-aula]', root).forEach((b) => b.addEventListener('click', () => { const k = b.dataset.aula;
    if (k === 'qhaliy') { irComo('sst_manager', 'm-vta', () => aplicarEmpresa('qhaliy')); toast('Instituto Qhaliy: aula y venta de cursos activas'); }
    else if (k === 'external_learner') { irComo('external_learner', 'alumno-cursos', () => aplicarEmpresa('qhaliy')); toast('Portal del participante externo'); }
    else { irComo(k, k === 'trainer' ? 'capacitador' : 'app-trabajador'); toast(k === 'trainer' ? 'Panel del capacitador' : 'App de la trabajadora: pestaña «Cursos»'); } }));
};

/* --- gráficos SVG sencillos --- */
function lineChart(series, labels, opt = {}) {
  const w = 560, h = 190, pl = 34, pr = 12, pt = 12, pb = 26;
  const max = opt.max || Math.ceil(Math.max(...series.flatMap((s) => s.v)) * 1.15);
  const x = (i) => pl + i * (w - pl - pr) / (labels.length - 1);
  const y = (v) => pt + (h - pt - pb) * (1 - v / max);
  let g = '';
  for (let k = 0; k <= 4; k++) { const v = max * k / 4; g += '<line class="gl" x1="' + pl + '" x2="' + (w - pr) + '" y1="' + y(v) + '" y2="' + y(v) + '"/><text x="' + (pl - 6) + '" y="' + (y(v) + 3) + '" text-anchor="end">' + (opt.fmt ? opt.fmt(v) : Math.round(v)) + '</text>'; }
  labels.forEach((l, i) => { g += '<text x="' + x(i) + '" y="' + (h - 8) + '" text-anchor="middle">' + l + '</text>'; });
  series.forEach((s) => {
    const pts = s.v.map((v, i) => x(i) + ',' + y(v)).join(' ');
    if (s.area) g += '<polygon points="' + x(0) + ',' + y(0) + ' ' + pts + ' ' + x(s.v.length - 1) + ',' + y(0) + '" fill="' + s.c + '" opacity=".12"/>';
    g += '<polyline points="' + pts + '" fill="none" stroke="' + s.c + '" stroke-width="2.4" stroke-linejoin="round"' + (s.dash ? ' stroke-dasharray="5 4"' : '') + '/>';
    const li = s.v.length - 1; g += '<circle cx="' + x(li) + '" cy="' + y(s.v[li]) + '" r="4" fill="' + s.c + '"/>';
  });
  return '<svg class="svgchart" viewBox="0 0 ' + w + ' ' + h + '" width="100%" role="img" aria-label="' + esc(opt.aria || 'Gráfico') + '">' + g + '</svg>' +
    '<div class="gauge-legend">' + series.map((s) => '<span><i style="width:14px;height:3px;background:' + s.c + ';display:inline-block"></i>' + esc(s.n) + '</span>').join('') + '</div>';
}
function anillo(p, color, lbl) {
  const r = 46, c = 2 * Math.PI * r;
  return '<div class="ring" aria-label="' + esc(lbl || p + '%') + '"><svg viewBox="0 0 112 112"><circle cx="56" cy="56" r="' + r + '" fill="none" stroke="var(--surface-2)" stroke-width="12"/><circle cx="56" cy="56" r="' + r + '" fill="none" stroke="' + (color || 'var(--brand)') + '" stroke-width="12" stroke-linecap="round" stroke-dasharray="' + (c * p / 100) + ' ' + c + '"/></svg><span class="rv num">' + p + '%</span></div>';
}
function mapaCalor() {
  const m = [[2, 5, 3], [4, 9, 6], [3, 7, 2]];
  const lv = [[1, 2, 3], [2, 3, 4], [3, 4, 5]];
  const ps = ['Probable', 'Posible', 'Improbable'];
  return '<div class="heat"><span></span><span class="hx">Leve</span><span class="hx">Moderado</span><span class="hx">Grave</span>' +
    [2, 1, 0].map((pi, rr) => '<span class="hl">' + ps[rr] + '</span>' + [0, 1, 2].map((si) => '<button class="hc lv-' + lv[pi][si] + '" style="border:0;cursor:pointer" data-heat="' + ps[rr] + ' × ' + ['Leve', 'Moderado', 'Grave'][si] + '" aria-label="' + m[pi][si] + ' riesgos">' + m[pi][si] + '</button>').join('')).join('') +
    '</div><p class="tiny muted" style="margin-top:6px">Riesgos residuales de las matrices IPERC vigentes (probabilidad × severidad). Toca una celda para ver el detalle.</p>';
}

R.DSH = (root) => {
  if (S.role === 'management') return dashGerencia(root);
  const emp = empresaActual();
  const venc = [
    ['EMO', 'Kevin Sánchez Paredes', 'Vencido hace 6 días', 'bad', 'm-emo'], ['EMO', 'Alejandro Zevallos Tello', 'Vence en 4 días', 'warn', 'm-emo'],
    ['SCTR', 'Brenda Ayala Peña', 'Vencido hace 3 días', 'bad', 'm-sct'], ['SCTR', 'Fernando Benites Carhuas', 'Vence en 25 días', 'warn', 'm-sct'],
    ['EPP', 'Christian Valdivia Neyra · Guantes anticorte', 'Vencido', 'bad', 'm-epp'], ['Capacitación', 'Milagros Pacheco Ugarte · Inducción', 'Pendiente', 'bad', 'm-cap'],
    ['EMO', 'Christian Valdivia Neyra', 'Vence en 8 días', 'warn', 'm-emo'], ['Capacitación', 'Ricardo Espinoza Lazo · Bloqueo y etiquetado', 'Vencida', 'bad', 'm-cap'],
  ];
  const sedeSel = window.__sedeDash || 'todas';
  const factor = { todas: 1, ate: 1.08, callao: .86, sanisidro: 1.12 }[sedeSel];
  const pas = Math.min(100, Math.round(72 * factor));
  root.innerHTML = encabezado('Hola, ' + USUARIOS[S.role][0].split(' ')[0].replace('Dra.', 'Dra. Sandra'), 'Así está el sistema de gestión SST de ' + esc(emp.corto) + ' hoy, 30 de octubre de 2026.',
    '<select class="input" id="dash-sede" aria-label="Sede" style="width:auto"><option value="todas">Todas las sedes</option>' + SEDES.map((s) => '<option value="' + s.id + '"' + (sedeSel === s.id ? ' selected' : '') + '>' + s.nombre + '</option>').join('') + '</select>' + btnExport(), [['Panel de inicio']]) +
    (S.role === 'external_auditor' ? nota('Acceso de auditoría: lectura del periodo 01/01/2026 – 30/09/2026. Vence el 15/11/2026.', 'warn') + '<div style="height:12px"></div>' : '') +
    '<div class="grid g-main">' +
    '<div class="stack">' +
    '<div class="card"><div class="ch"><h3>Cumplimiento del Programa Anual SST 2026</h3><a href="#m-pas" class="small">Ver PASST</a></div><div class="row" style="gap:20px;align-items:center">' + anillo(pas, 'var(--brand)', 'Avance PASST') +
    '<div style="flex:1;min-width:220px" class="stack">' + [['Capacitaciones', Math.round(81 * factor)], ['Inspecciones', Math.round(76 * factor)], ['EMO programados', Math.round(64 * factor)], ['Simulacros', 50], ['Reuniones del Comité', 90]].map(([n, p]) => { p = Math.min(100, p); return '<div><div class="row between small"><span>' + n + '</span><b class="num">' + p + '%</b></div>' + barra(p) + '</div>'; }).join('') + '</div></div></div>' +
    '<div class="grid g4">' + kpi('Índice de frecuencia', '<span>' + (6.8 * factor).toFixed(1).replace('.', ',') + '</span>', 'Meta ≤ 8,0 · ' + param('factor 1 000 000 HH')) + kpi('Índice de gravedad', '<span>' + Math.round(41 * factor) + '</span>', 'Días perdidos · ' + param('factor 1 000 000 HH')) + kpi('Índice de accidentabilidad', '<span>' + (0.28 * factor).toFixed(2).replace('.', ',') + '</span>', 'IF × IG / 1 000') + kpi('Días sin accidentes con baja', '<span>23</span>', 'Último: 07/10/2026') + '</div>' +
    '<div class="card"><div class="ch"><h3>Índices mensuales 2026</h3><span class="small muted">Frecuencia y gravedad /10</span></div>' + lineChart([{ n: 'Índice de frecuencia', c: 'var(--brand)', v: [9.1, 8.4, 7.9, 10.2, 8.8, 7.1, 6.5, 7.4, 6.9, 6.8], area: 1 }, { n: 'Índice de gravedad ÷ 10', c: 'var(--amber)', v: [5.8, 4.9, 4.1, 7.2, 5.5, 3.8, 3.1, 4.6, 4.2, 4.1] }], ['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O'], { aria: 'Índices de frecuencia y gravedad por mes' }) + '</div>' +
    '<div class="card"><div class="ch"><h3>Acciones correctivas abiertas</h3><a class="small" href="#m-acr">Ver todas</a></div>' + tabla([{ l: 'Acción', f: (a) => '<b>' + esc(a.t) + '</b><div class="tiny muted">' + a.id + ' · ' + esc(a.o) + '</div>' }, { l: 'Responsable', k: 'r' }, { l: 'Vence', k: 'v' }, { l: 'Estado', f: (a) => chip(a.e) }], ACCIONES.filter((a) => a.e !== 'Cerrada').slice(0, 5), { click: 1 }) + '</div>' +
    '</div>' +
    '<div class="stack">' +
    '<div class="card"><div class="ch"><h3>Vencimientos próximos</h3><span class="chip bad">' + venc.filter((v) => v[3] === 'bad').length + ' vencidos</span></div><div class="list">' + venc.map((v) => '<a class="li" href="#' + v[4] + '" style="text-decoration:none;color:inherit">' + sem(v[3]) + '<div class="lm"><b>' + esc(v[1]) + '</b><span>' + v[0] + ' · ' + v[2] + '</span></div>' + ic('right', 14) + '</a>').join('') + '</div></div>' +
    '<div class="card"><div class="ch"><h3>Mapa de calor de riesgos</h3><a class="small" href="#m-ipc">IPERC</a></div>' + mapaCalor() + '</div>' +
    '<div class="card"><h3>Actividad reciente</h3><div class="timeline" style="margin-top:12px">' + ACTIVIDAD.map((a) => '<div class="ev"><b>' + esc(a[0]) + '</b> ' + esc(a[1]) + '<span>' + esc(a[2]) + '</span></div>').join('') + '</div></div>' +
    '</div></div>';
  $('#dash-sede', root).onchange = (e) => { window.__sedeDash = e.target.value; R.DSH(root); };
  bindTabla(root, ACCIONES.filter((a) => a.e !== 'Cerrada').slice(0, 5), fichaAccion);
  $$('[data-heat]', root).forEach((b) => b.onclick = () => modal('Riesgos ' + b.dataset.heat, tabla([{ l: 'Puesto', k: 0 }, { l: 'Peligro', k: 1 }, { l: 'Control pendiente', k: 2 }], [['Soldador', 'Radiación UV y humos metálicos', 'Extracción localizada'], ['Operador de prensa', 'Atrapamiento por partes móviles', 'Guarda fija N.° 3'], ['Operador de montacargas', 'Atropello en zona de despacho', 'Señalización de pasillos']]), [{ l: 'Cerrar' }, { l: 'Abrir IPERC', cls: 'pri', fn: () => { location.hash = 'm-ipc'; } }]));
  bindExport(root);
};
function fichaAccion(a) {
  cajon(a.id, esc(a.o), dl([['Acción', esc(a.t)], ['Responsable', esc(a.r)], ['Vence', a.v], ['Estado', chip(a.e)], ['Avance', '<div style="max-width:200px">' + barra(a.p) + '</div><span class="small">' + a.p + '%</span>']]) +
    '<div><h4 style="margin-bottom:8px">Evidencia de cierre</h4><div class="row"><div class="photo">' + fotoSim(2) + '</div><div class="photo">' + fotoSim(4) + '</div></div></div>' +
    '<div class="timeline"><div class="ev">Acción creada desde ' + esc(a.o) + '<span>Rocío Villanueva · 12/10/2026</span></div><div class="ev">Avance reportado ' + a.p + '%<span>' + esc(a.r) + ' · 28/10/2026</span></div></div>',
  puedeEditar() && a.e !== 'Cerrada' ? [{ l: 'Cerrar' }, { l: 'Subir evidencia', fn: () => toast('Evidencia adjuntada (simulado)') }, { l: 'Marcar como cerrada', cls: 'pri', fn: () => { a.e = 'Cerrada'; a.p = 100; toast('Acción ' + a.id + ' cerrada'); render(); } }] : [{ l: 'Cerrar' }]);
}
function fotoSim(n) {
  const c = [['#8FB3D9', '#5D7FA6', '#F4B90F'], ['#B7A88F', '#7E6E55', '#E2453C'], ['#9FC5A8', '#5E8A68', '#1F7FD1'], ['#C9C2B5', '#8A8274', '#F4B90F'], ['#A9B8C9', '#6B7C91', '#22A35A']][n % 5];
  return '<svg viewBox="0 0 96 72" aria-label="Foto de evidencia simulada" role="img"><rect width="96" height="72" fill="' + c[0] + '"/><rect y="46" width="96" height="26" fill="' + c[1] + '"/><rect x="' + (14 + n * 9) % 50 + '" y="22" width="26" height="30" fill="' + c[2] + '" opacity=".85"/><circle cx="76" cy="14" r="7" fill="#fff" opacity=".6"/></svg>';
}

function dashGerencia(root) {
  root.innerHTML = encabezado('Panel de Gerencia', 'Resumen ejecutivo de Seguridad y Salud en el Trabajo · ' + esc(empresaActual().corto), btnExport(), [['Panel de Gerencia']]) +
    '<div class="grid g4" style="margin-bottom:14px">' + kpi('Cumplimiento del SG-SST', '78%', '+6 puntos frente a junio') + kpi('Accidentes con baja (año)', '3', '2025: 7 en el mismo periodo') + kpi('Días perdidos (año)', '19', 'Meta anual ≤ 40') + kpi('Puntaje autodiagnóstico SUNAFIL', '82/100', '5 brechas abiertas') + '</div>' +
    '<div class="grid g2"><div class="card"><h3>Accidentabilidad 2025 vs 2026</h3>' + lineChart([{ n: '2026', c: 'var(--brand)', v: [2.9, 2.1, 1.6, 3.4, 2.4, 1.2, 1.0, 1.7, 1.4, 1.3], area: 1 }, { n: '2025', c: 'var(--muted)', v: [3.8, 3.5, 4.2, 3.9, 3.1, 3.6, 2.9, 3.3, 3.0, 2.8], dash: 1 }], ['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O'], { aria: 'Índice de accidentabilidad' }) + '</div>' +
    '<div class="card"><h3>Pendientes de su aprobación</h3><div class="list">' +
    [['Política SST versión 2026', 'Revisada por el Comité el 15/10', 'm-pol'], ['Revisión por la dirección 2026', 'Informe anual listo para revisión', 'm-rvd'], ['Presupuesto SST 2027', 'Propuesta del área SST', 'm-pre']].map((x) => '<a class="li" href="#' + x[2] + '" style="color:inherit;text-decoration:none">' + ic('pen') + '<div class="lm"><b>' + x[0] + '</b><span>' + x[1] + '</span></div>' + chip('Pendiente') + '</a>').join('') + '</div></div>' +
    '<div class="card"><h3>Cumplimiento por sede</h3><div class="stack" style="margin-top:12px">' + [['Planta Ate', 81], ['Almacén Callao', 67], ['Oficina San Isidro', 92]].map(([n, p]) => '<div><div class="row between small"><span>' + n + '</span><b>' + p + '%</b></div>' + barra(p) + '</div>').join('') + '</div></div>' +
    '<div class="card"><h3>Riesgos críticos abiertos</h3><div class="list">' + [['Atrapamiento en prensa N.° 3', 'Producción · acción AC-2026-118 al 60%'], ['Caída desde mezzanine del almacén', 'Logística · barandas por reparar'], ['Exposición a humos de soldadura', 'Producción · monitoreo sobre el límite']].map((x) => '<div class="li">' + sem('bad') + '<div class="lm"><b>' + x[0] + '</b><span>' + x[1] + '</span></div></div>').join('') + '</div></div></div>';
  bindExport(root);
}
