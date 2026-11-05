/* Módulos con pantalla por configuración (lista con filtros + ficha + formulario). */
const C = (l, k, f) => ({ l, k, f });
const CH = (l, k) => ({ l, f: (r) => chip(r[k]) });
const fichaArr = (cols, titleIdx, extra, btns) => (r) => ({ t: String(r[titleIdx || 0]), s: '', html: dl(cols.map((c) => [c.l, c.f ? c.f(r) : esc(r[c.k])])) + (extra ? extra(r) : ''), btns: btns ? btns(r) : [{ l: 'Cerrar' }] });
function cfgSimple(code, o) {
  const fx = typeof o.extra === 'function' ? o.extra : null;
  const cfg = Object.assign({}, o);
  if (fx) delete cfg.extra;
  if (o.cols && !o.ficha) cfg.ficha = fichaArr(o.cols, 0, fx, o.btns);
  R[code] = (root) => pantalla(root, code, cfg);
}

/* 1. Núcleo */
/* Activación por empresa (dentro del plan) de Aula virtual y Venta de cursos */
function modulosEmpresa() {
  const pl = planIncluye(modBy('AUL'));
  const f = S.feat[S.empresa] || {};
  const adm = ['account_admin', 'sst_manager', 'consultant', 'hr'].includes(S.role) && pl;
  return '<div class="card" style="margin-bottom:14px" id="mods-emp"><div class="ch"><h3>Módulos de la empresa</h3>' + (pl ? chip('Incluidos en el plan ' + PLANES[S.plan], 'ok') : chip('Requieren el plan Profesional', 'warn')) + '</div>' +
    '<p class="small muted" style="margin-bottom:10px">Aunque el plan los incluya, cada empresa decide si los usa. Desactivarlos no borra datos.</p><div class="grid g2">' +
    '<div class="card flat stack" style="gap:6px;background:var(--surface-2)">' + sw('fe-AUL', pl && f.AUL, 'Aula virtual', 'Portal propio de cursos para capacitar al personal', !adm) + '</div>' +
    '<div class="card flat stack" style="gap:6px;background:var(--surface-2)">' + sw('fe-VTA', pl && f.VTA, 'Venta de cursos y pagos', 'Vende cursos a personas y empresas con pasarela de pagos. Viene desactivado.', !adm || !f.AUL) + '</div></div>' +
    '<div class="row small muted" style="gap:16px;margin-top:10px"><span><b>Solo capacitación presencial:</b> ambos apagados</span><span><b>Capacita a su personal:</b> solo el aula</span><span><b>Instituto o consultora que vende cursos:</b> ambos activos</span></div></div>';
}
function bindModulosEmpresa(root) {
  ['AUL', 'VTA'].forEach((k) => { const x = $('#fe-' + k, root); if (x) x.onchange = () => { setFeat(k, x.checked); toast((k === 'AUL' ? 'Aula virtual' : 'Venta de cursos y pagos') + (x.checked ? ' activado' : ' desactivado') + ' para ' + empresaActual().corto); $('#mods-emp', root).outerHTML = modulosEmpresa(); bindModulosEmpresa(root); pintarMenu(rutaActual().split('/')[0]); $('#demobar').innerHTML = demoBar(); bindDemo(); }; });
}
R.TEN = (root) => pantalla(root, 'TEN', {
  aviso: modulosEmpresa(),
  mount: bindModulosEmpresa,
  desc: 'Estructura de ' + esc(empresaActual().nombre) + ': sedes, áreas, puestos y turnos.',
  extra: '<div class="card" style="margin-bottom:14px"><div class="row" style="gap:24px">' + dl([['Razón social', esc(empresaActual().nombre)], ['RUC', empresaActual().ruc], ['Actividad', esc(empresaActual().sector)]]) + dl([['Trabajadores', String(empresaActual().trab)], ['Figura SST', empresaActual().figura], ['Plan', PLANES[S.plan]]]) + '</div></div>',
  nuevo: ['Nueva sede', [['Nombre'], ['Dirección'], ['Tipo', 'select', 'Planta de producción', ['Planta de producción', 'Almacén', 'Oficina', 'Obra']], ['Responsable', 'select', W[0].nombre, W.map((w) => w.nombre)]], 'Crear sede', 'Sede creada'],
  tabs: [
    { nombre: 'Sedes', cols: [C('Sede', 'nombre'), C('Dirección', 'dir'), C('Tipo', 'tipo'), C('Trabajadores', 'trab'), C('Responsable', 'resp')], rows: SEDES, ficha: (s) => fichaSimple(s.nombre, esc(s.tipo), [['Dirección', esc(s.dir)], ['Trabajadores', s.trab], ['Responsable', esc(s.resp)], ['Áreas', AREAS.filter((a) => a[1] === s.id).map((a) => esc(a[0])).join(', ')]]) },
    { nombre: 'Áreas', cols: [C('Área', 0), C('Sede', 1, (r) => sedeNom(r[1])), C('Trabajadores', 2)], rows: AREAS, filtros: [{ k: 1, l: 'Sede' }] },
    { nombre: 'Puestos', cols: [C('Puesto', 0), C('Área', 1), C('Trabajadores', 2), C('Nivel de riesgo', 3, (r) => chip(r[3]))], rows: PUESTOS, filtros: [{ k: 3, l: 'Riesgo' }] },
    { nombre: 'Turnos', cols: [C('Turno', 0), C('Horario', 1), C('Sedes', 2)], rows: [['Mañana', '06:00 – 14:00', 'Planta Ate, Almacén Callao'], ['Tarde', '14:00 – 22:00', 'Planta Ate'], ['Noche', '22:00 – 06:00', 'Planta Ate'], ['Administrativo', '08:30 – 18:00', 'Oficina San Isidro']] },
  ],
});
cfgSimple('USR', {
  desc: 'Acceso por rol, sede y módulo. Doble factor obligatorio para roles con datos sensibles.',
  nuevo: ['Invitar usuario', [['Correo', 'email'], ['Rol', 'select', 'Jefe de área / línea', CAT.roles.map((r) => r.nombre)], ['Alcance', 'select', 'Todas las sedes', ['Todas las sedes', ...SEDES.map((s) => s.nombre)]]], 'Enviar invitación', 'Invitación enviada por correo'],
  cols: [C('Usuario', 0), C('Correo', 1), C('Rol', 2), C('Alcance', 3), C('Doble factor', 4, (r) => chip(r[4])), CH('Estado', 5)],
  rows: [['Rocío Villanueva Tapia', 'rvillanueva@andinasdelsur.pe', 'Jefe SST', 'Todas las sedes', 'Activo', 'Activo'], ['Gabriela Montoya Díaz', 'gmontoya@andinasdelsur.pe', 'Supervisor SST', 'Planta Ate', 'Activo', 'Activo'], ['Dra. Sandra Medina Cárdenas', 'smedina@andinasdelsur.pe', 'Médico ocupacional', 'Todas las sedes', 'Activo', 'Activo'], ['Patricia Salazar Loayza', 'psalazar@andinasdelsur.pe', 'Recursos Humanos', 'Todas las sedes', 'Activo', 'Activo'], ['Jorge Luis Quispe Huamán', 'jquispe@andinasdelsur.pe', 'Jefe de área / línea', 'Producción', 'Pendiente', 'Activo'], ['Mónica Herrera Bazán', 'auditora@certificaperu.pe', 'Auditor externo', 'Periodo 2026 · vence 15/11/2026', 'Activo', 'Activo'], ['Eduardo Ponce Retamozo', 'eponce@montajesrimac.pe', 'Administrador de contratista', 'Montajes Eléctricos Rímac', 'Pendiente', 'Pendiente']],
  filtros: [{ k: 2, l: 'Rol' }, { k: 5, l: 'Estado' }],
});
R.TRB = (root) => {
  const rows = W.map((w) => [w.nombre, w.dni, w.puesto, w.area, sedeNom(w.sede), w.aptitud, w.induccion === 'ok' ? 'Completada' : 'Pendiente', w.id]);
  pantalla(root, 'TRB', {
    desc: 'Ficha única del trabajador con su legajo SST. Carga masiva desde Excel.',
    acciones: '<a class="btn" href="#registro">' + ic('upload', 16) + 'Cargar desde Excel</a>',
    nuevo: ['Nuevo trabajador', [['DNI'], ['Nombres'], ['Apellidos'], ['Puesto', 'select', PUESTOS[0][0], PUESTOS.map((p) => p[0])], ['Sede', 'select', 'Planta Ate', SEDES.map((s) => s.nombre)], ['Fecha de ingreso', 'date']], 'Registrar', 'Trabajador registrado; se programó su inducción'],
    kpis: [['Trabajadores activos', '140'], ['Ingresos del mes', '3'], ['Sin inducción', '2'], ['Legajo completo', '91%']],
    cols: [C('Trabajador', 0, (r) => '<b>' + esc(r[0]) + '</b>'), C('DNI', 1), C('Puesto', 2), C('Área', 3), C('Sede', 4), C('Aptitud', 5, (r) => chip(r[5])), C('Inducción', 6, (r) => chip(r[6]))],
    rows, filtros: [{ k: 4, l: 'Sede' }, { k: 3, l: 'Área' }, { k: 5, l: 'Aptitud' }],
    ficha: (r) => fichaTrab(wById(r[7])),
    mount: () => { if (window.__abrirTrab) { const w = wById(window.__abrirTrab); window.__abrirTrab = null; setTimeout(() => { const f = fichaTrab(w); cajon(f.t, f.s, f.html, f.btns); }, 30); } },
  });
};
function fichaTrab(w) {
  const req = requisitosFot(w);
  return { t: w.nombre, s: esc(w.puesto) + ' · ' + esc(sedeNom(w.sede)), html: '<div class="row"><span class="av" style="width:56px;height:56px;font-size:1.1rem">' + iniciales(w.nombre) + '</span><div>' + chip(credEstado(w) === 'bad' ? 'No habilitado' : 'Habilitado', credEstado(w)) + '</div></div>' + dl([['DNI', w.dni], ['Área', esc(w.area)], ['Ingreso', w.ingreso], ['Aptitud médica', chip(w.aptitud) + (S.role === 'occupational_doctor' ? '' : ' <span class="tiny muted">(sin diagnóstico)</span>')]]) +
    '<div><h4 style="margin-bottom:6px">Legajo SST</h4><div class="list">' + req.map((x) => '<div class="li">' + sem(x[1]) + '<div class="lm"><b>' + x[0] + '</b><span>' + x[2] + '</span></div></div>').join('') + '<div class="li">' + sem(w.epp) + '<div class="lm"><b>EPP</b><span>' + (w.epp === 'ok' ? 'Entregas al día' : w.epp === 'warn' ? 'Reposición próxima' : 'EPP vencido') + '</span></div></div><div class="li">' + sem('ok') + '<div class="lm"><b>Cargo RISST</b><span>Firmado 15/03/2026</span></div></div></div></div>', btns: [{ l: 'Ver fotocheck', fn: () => { location.hash = 'm-fot'; } }, { l: 'Cerrar', cls: 'pri' }] };
}
cfgSimple('DOC', {
  desc: 'Documentos del sistema con versiones, vigencias y cargos de entrega.',
  nuevo: ['Subir documento', [['Título'], ['Categoría', 'select', 'Procedimiento', ['Política', 'Procedimiento', 'Plan', 'Formato', 'Instructivo']], ['Vigencia hasta', 'date'], ['Requiere cargo de entrega', 'select', 'Sí', ['Sí', 'No']]], 'Subir', 'Documento cargado como borrador'],
  kpis: [['Documentos vigentes', '86'], ['Por vencer', '5'], ['En revisión', '3'], ['Cargos pendientes', '17']],
  cols: [C('Código', 0), C('Documento', 1), C('Categoría', 2), C('Versión', 3), C('Vigencia', 4), CH('Estado', 5), C('Cargos', 6)],
  rows: [['POL-SST-01', 'Política de Seguridad y Salud en el Trabajo', 'Política', 'v3', '31/12/2026', 'Vigente', '138/140'], ['PLN-EMG-01', 'Plan de respuesta ante emergencias', 'Plan', 'v4', '15/11/2026', 'Por vencer', '—'], ['PRO-LOTO-02', 'Bloqueo y etiquetado de energías', 'Procedimiento', 'v2', '30/06/2027', 'Vigente', '22/22'], ['PRO-ALT-01', 'Trabajos en altura', 'Procedimiento', 'v3', '28/02/2027', 'En revisión', '—'], ['FOR-ATS-01', 'Formato de análisis de trabajo seguro', 'Formato', 'v1', '—', 'Vigente', '—'], ['INS-MON-01', 'Operación segura de montacargas', 'Instructivo', 'v2', '10/01/2026', 'Vencido', '9/9']],
  filtros: [{ k: 2, l: 'Categoría' }, { k: 5, l: 'Estado' }],
  extra: () => '<div class="timeline"><div class="ev">Versión vigente aprobada<span>Comité SST · 15/03/2026</span></div><div class="ev">Versión anterior obsoleta<span>Archivada con su historial</span></div></div>',
});
cfgSimple('NOT', {
  desc: 'Reglas de alerta por vencimientos y eventos, enviadas por correo, WhatsApp y la app.',
  nuevo: ['Nueva regla', [['Evento', 'select', 'Vencimiento de EMO', ['Vencimiento de EMO', 'Vencimiento de SCTR', 'Vencimiento de EPP', 'Accidente registrado', 'Acción correctiva vencida']], ['Anticipación (días)', 'number', '30'], ['Canales', 'select', 'Correo y WhatsApp', ['Correo', 'WhatsApp', 'App', 'Correo y WhatsApp']], ['Destinatarios', 'select', 'Jefe SST y RR. HH.', ['Jefe SST', 'Jefe SST y RR. HH.', 'Trabajador y jefe directo']]], 'Crear regla', 'Regla de alerta creada'],
  tabs: [
    { nombre: 'Reglas de alerta', cols: [C('Evento', 0), C('Anticipación', 1), C('Canales', 2), C('Destinatarios', 3), CH('Estado', 4)], rows: [['Vencimiento de EMO', '30 y 7 días', 'Correo, WhatsApp', 'RR. HH., trabajador', 'Activa'], ['Vencimiento de SCTR', '30 días', 'Correo', 'RR. HH., Jefe SST', 'Activa'], ['Accidente registrado', 'Inmediato', 'Correo, WhatsApp, app', 'Comité, Gerencia, médica', 'Activa'], ['Acción correctiva vencida', 'Al vencer', 'Correo, app', 'Responsable, Jefe SST', 'Activa'], ['Capacitación por vencer', '15 días', 'WhatsApp', 'Trabajador', 'Inactiva']] },
    { nombre: 'Envíos recientes', cols: [C('Fecha', 0), C('Mensaje', 1), C('Canal', 2), C('Destinatario', 3), CH('Estado', 4)], rows: NOTIFICACIONES.map((n, i) => [n.h, n.t, ['Correo', 'WhatsApp', 'App'][i % 3], ['Rocío Villanueva', 'Patricia Salazar', 'Comité SST'][i % 3], i === 2 ? 'Error' : 'Entregado']), filtros: [{ k: 2, l: 'Canal' }] },
  ],
});
cfgSimple('FIR', {
  desc: 'Firma electrónica de trabajadores y responsables, con fecha, dispositivo y ubicación. Las firmas no se modifican.',
  kpis: [['Solicitudes abiertas', '24'], ['Firmadas este mes', '412'], ['Rechazadas', '1'], ['Tiempo medio de firma', '1,3 días']],
  cols: [C('Documento', 0), C('Firmante', 1), C('Enviado', 2), C('Canal', 3), CH('Estado', 4)],
  rows: [['Cargo RISST v3', 'Carmen Rosa Mamani Condori', '28/10/2026', 'App', 'Por firmar'], ['Entrega de EPP N.° 1187', 'Christian Valdivia Neyra', '28/10/2026', 'App', 'Por firmar'], ['Acta Comité N.° 09', 'Martín Gutiérrez Aliaga', '08/10/2026', 'Web', 'Firmado'], ['Inspección INS-2026-224', 'Gabriela Montoya Díaz', '30/10/2026', 'App', 'Firmado'], ['PETAR PT-2026-087', 'Jorge Luis Quispe Huamán', '30/10/2026', 'App', 'Firmado']],
  filtros: [{ k: 4, l: 'Estado' }],
  btns: (r) => [{ l: 'Reenviar recordatorio', fn: () => toast('Recordatorio enviado por WhatsApp') }, { l: 'Cerrar', cls: 'pri' }],
});
cfgSimple('AUD', {
  desc: 'Quién hizo qué y cuándo. Los accesos a datos médicos se registran aparte. Registros inalterables.',
  tabs: [
    { nombre: 'Bitácora general', cols: [C('Fecha y hora', 0), C('Usuario', 1), C('Acción', 2), C('Registro', 3), C('Desde', 4)], rows: [['30/10/2026 09:38', 'Gabriela Montoya', 'Cerró inspección', 'INS-2026-224', 'App · 190.236.xx.xx'], ['30/10/2026 09:20', 'Rocío Villanueva', 'Creó investigación', 'INV-2026-007', 'Web'], ['30/10/2026 07:55', 'Jorge Quispe', 'Registró accidente', 'ACC-2026-015', 'App'], ['29/10/2026 16:10', 'Gabriela Montoya', 'Envió a aprobación', 'IPERC Soldador v4', 'Web'], ['29/10/2026 15:02', 'Patricia Salazar', 'Importó trabajadores', '3 filas', 'Web']], filtros: [{ k: 1, l: 'Usuario' }] },
    { nombre: 'Accesos a datos sensibles', cols: [C('Fecha y hora', 0), C('Usuario', 1), C('Dato consultado', 2), C('Motivo', 3), CH('Re-autenticación', 4)], rows: [['30/10/2026 08:51', 'Dra. Sandra Medina', 'EMO de Carmen Rosa Mamani', 'Control periódico', 'Aceptado'], ['29/10/2026 17:30', 'Dra. Sandra Medina', 'Historia de Teodoro Cusi', 'Vigilancia médica', 'Aceptado'], ['29/10/2026 11:12', 'Patricia Salazar', 'EMO de Ricardo Espinoza', '—', 'Rechazado'], ['28/10/2026 10:05', 'Carmen Rosa Mamani', 'Sus propios resultados', 'Solicitud del trabajador', 'Aceptado']] },
  ],
});

/* 2. Planificación */
cfgSimple('LBA', {
  desc: 'Diagnóstico inicial con la lista de verificación de la R.M. 050-2013-TR y el cierre de brechas.',
  kpis: [['Línea base 2026', '74%', 'Evaluada el 15/01/2026'], ['Ítems evaluados', '118'], ['Brechas abiertas', '21'], ['Brechas cerradas', '43']],
  cols: [C('Lineamiento', 0), C('Ítems', 1), C('Cumplimiento', 2, (r) => '<div style="min-width:120px">' + barra(r[2]) + '<span class="tiny">' + r[2] + '%</span></div>'), C('Brechas', 3), CH('Estado', 4)],
  rows: [['Compromiso e involucramiento', 12, 92, 1, 'Cumple'], ['Política de SST', 8, 100, 0, 'Cumple'], ['Planeamiento y aplicación', 24, 71, 6, 'En proceso'], ['Implementación y operación', 31, 68, 8, 'En proceso'], ['Evaluación normativa', 9, 56, 3, 'No cumple'], ['Verificación', 22, 77, 2, 'En proceso'], ['Control de información y documentos', 12, 83, 1, 'Cumple']],
  filtros: [{ k: 4, l: 'Estado' }],
});
cfgSimple('POL', {
  desc: 'Política SST versionada, aprobada por gerencia y difundida; objetivos con metas medibles.',
  tabs: [
    { nombre: 'Objetivos 2026', cols: [C('Objetivo', 0), C('Indicador', 1), C('Meta', 2), C('Avance', 3, (r) => '<div style="min-width:110px">' + barra(r[3]) + '<span class="tiny">' + r[3] + '%</span></div>'), CH('Estado', 4)], rows: [['Reducir accidentes con baja', 'Índice de frecuencia', '≤ 8,0', 85, 'En proceso'], ['Capacitar a todo el personal', '% de horas ejecutadas', '100%', 81, 'En proceso'], ['Cumplir EMO periódicos', '% de EMO vigentes', '100%', 86, 'En proceso'], ['Cerrar acciones a tiempo', '% cerradas en plazo', '≥ 90%', 72, 'En proceso']] },
    { nombre: 'Versiones de la política', cols: [C('Versión', 0), C('Aprobada por', 1), C('Fecha', 2), CH('Estado', 3), C('Difusión', 4)], rows: [['v3', 'Martín Gutiérrez (Gerente general)', '15/01/2026', 'Vigente', '138 de 140 firmaron'], ['v4 (borrador)', '—', '—', 'En revisión', '—'], ['v2', 'Martín Gutiérrez', '10/01/2025', 'Obsoleto', '—']] },
  ],
  acciones: S.role === 'management' ? '<button class="btn pri" onclick="toast(\'Política v4 aprobada con su firma\')">Aprobar versión 4</button>' : '',
});
cfgSimple('MRL', {
  desc: 'Normas aplicables a la empresa y la evaluación de su cumplimiento.',
  nuevo: ['Agregar requisito', [['Norma'], ['Artículo'], ['Requisito'], ['Responsable', 'select', 'Rocío Villanueva Tapia', W.map((w) => w.nombre)]], 'Agregar', 'Requisito agregado a la matriz'],
  cols: [C('Norma', 0), C('Artículo', 1), C('Requisito', 2), C('Evidencia', 3), CH('Cumplimiento', 4)],
  rows: [['Ley 29783', 'Art. 29', 'Constituir Comité SST (20 o más trabajadores)', 'Acta de instalación 2025', 'Cumple'], ['D.S. 005-2012-TR', 'Art. 33', 'Llevar los registros obligatorios', 'Módulo Registros', 'Cumple'], ['Ley 29783', 'Art. 49 d)', 'Practicar EMO antes, durante y al término', '4 EMO vencidos', 'Parcial'], ['D.S. 005-2012-TR', 'Art. 74', 'Entregar RISST a cada trabajador', 'Cargos firmados', 'Cumple'], ['R.M. 375-2008-TR', 'Norma ergonómica', 'Evaluación ergonómica de puestos', 'Pendiente en barnizado', 'No cumple'], ['Ley 29733', 'Art. 13', 'Consentimiento para datos de salud', 'Formato firmado en alta', 'Cumple']],
  filtros: [{ k: 0, l: 'Norma' }, { k: 4, l: 'Cumplimiento' }],
});
cfgSimple('PLA', {
  desc: 'Plan anual: objetivos, metas, recursos y responsables, aprobado por el Comité.',
  cols: [C('Objetivo', 0), C('Meta', 1), C('Recursos', 2), C('Responsable', 3), CH('Estado', 4)],
  rows: [['Reducir accidentes con baja', 'IF ≤ 8,0', 'Guardas, capacitación, supervisión', 'Rocío Villanueva', 'En proceso'], ['Cumplir programa de capacitación', '100% de horas', 'Instructores internos y externos', 'Patricia Salazar', 'En proceso'], ['Controlar exposición a ruido', 'Bajo el límite en 100% de puntos', 'Cabinas acústicas, protectores', 'Edwin Chávez', 'Pendiente'], ['Preparación ante emergencias', '4 simulacros al año', 'Brigadas, equipos', 'Gabriela Montoya', 'En proceso']],
  aviso: nota('Plan Anual SST 2026 aprobado por el Comité SST el 20/01/2026 (Acta N.° 01). Montos de recursos: ver Presupuesto SST.', 'ok', 'check') + '<div style="height:12px"></div>',
});
cfgSimple('PAS', {
  desc: 'Programa Anual SST: actividades, responsables, fechas y porcentaje de avance.',
  kpis: [['Avance del programa', '72%'], ['Actividades', '64'], ['Ejecutadas', '41'], ['Atrasadas', '5']],
  nuevo: ['Nueva actividad', [['Actividad'], ['Responsable', 'select', W[0].nombre, W.map((w) => w.nombre)], ['Mes programado', 'select', 'Noviembre', ['Noviembre', 'Diciembre']], ['Objetivo', 'select', 'Reducir accidentes con baja', ['Reducir accidentes con baja', 'Cumplir programa de capacitación']]], 'Agregar', 'Actividad agregada al PASST'],
  cols: [C('Actividad', 0), C('Objetivo', 1), C('Responsable', 2), C('Programado', 3), C('Avance', 4, (r) => '<div style="min-width:110px">' + barra(r[4]) + '<span class="tiny">' + r[4] + '%</span></div>'), CH('Estado', 5)],
  rows: [['Inspección mensual de extintores', 'Emergencias', 'Gabriela Montoya', 'Ene–Dic', 83, 'En proceso'], ['Simulacro de sismo', 'Emergencias', 'Gabriela Montoya', 'Oct', 100, 'Ejecutado'], ['Monitoreo de ruido en prensas', 'Ruido', 'Edwin Chávez', 'Sep', 40, 'Atrasado'], ['Capacitación en trabajos en caliente', 'Capacitación', 'Rocío Villanueva', 'Nov', 0, 'Programado'], ['Revisión de IPERC de mantenimiento', 'Riesgos', 'Rocío Villanueva', 'Oct', 60, 'En proceso'], ['Reuniones mensuales del Comité', 'Comité', 'Martín Gutiérrez', 'Ene–Dic', 90, 'En proceso']],
  filtros: [{ k: 5, l: 'Estado' }, { k: 2, l: 'Responsable' }],
  btns: () => [{ l: 'Registrar ejecución', cls: 'pri', fn: () => toast('Ejecución registrada con evidencia') }, { l: 'Cerrar' }],
});
cfgSimple('PCA', {
  desc: 'Programa anual de capacitación aprobado por el Comité, con las 4 capacitaciones obligatorias.',
  cols: [C('Tema', 0), C('Dirigido a', 1), C('Mes', 2), C('Horas', 3), C('Tipo', 4), CH('Estado', 5)],
  rows: [['Inducción SST', 'Nuevos ingresos', 'Permanente', 4, 'Obligatoria', 'En proceso'], ['Uso y cuidado de EPP', 'Todo el personal', 'Mar', 2, 'Obligatoria', 'Ejecutado'], ['Respuesta ante emergencias', 'Todo el personal', 'Jun', 2, 'Obligatoria', 'Ejecutado'], ['IPERC y reporte de actos', 'Todo el personal', 'Ago', 2, 'Obligatoria', 'Ejecutado'], ['Trabajos en caliente', 'Soldadores', 'Nov', 4, 'Específica', 'Programado'], ['Manejo defensivo de montacargas', 'Operadores', 'Dic', 6, 'Específica', 'Programado']],
  filtros: [{ k: 4, l: 'Tipo' }, { k: 5, l: 'Estado' }],
  aviso: nota('Aprobado por el Comité SST el 27/10/2026 (Acta N.° 09).', 'ok', 'check') + '<div style="height:12px"></div>',
});
cfgSimple('RIS', {
  desc: 'Reglamento Interno de SST con versiones y cargo de entrega firmado por cada trabajador.',
  kpis: [['Versión vigente', 'v3'], ['Cargos firmados', '138 / 140'], ['Pendientes', '2'], ['Próxima revisión', 'Mar 2027']],
  cols: [C('Trabajador', 0), C('Puesto', 1), C('Versión', 2), C('Entregado', 3), CH('Cargo', 4)],
  rows: W.slice(0, 14).map((w, i) => [w.nombre, w.puesto, 'v3', i === 2 || i === 12 ? '—' : '15/03/2026', i === 2 || i === 12 ? 'Por firmar' : 'Firmado']),
  filtros: [{ k: 4, l: 'Cargo' }],
  btns: (r) => r[4] === 'Por firmar' ? [{ l: 'Reenviar a la app', cls: 'pri', fn: () => toast('Solicitud de firma reenviada') }] : [{ l: 'Ver cargo firmado', fn: () => toast('Cargo con firma y fecha (simulado)') }],
});
cfgSimple('PRE', {
  desc: 'Presupuesto SST por actividad y su ejecución. Los montos de este prototipo son referenciales e inventados.',
  kpis: [['Líneas presupuestadas', '18'], ['Ejecución', '64%'], ['Por aprobar', '1'], ['Desviación', '−3%']],
  cols: [C('Rubro', 0), C('Actividad del PASST', 1), C('Presupuestado', 2), C('Ejecutado', 3), C('Avance', 4, (r) => '<div style="min-width:110px">' + barra(r[4]) + '</div>')],
  rows: [['EPP', 'Reposición anual de EPP', 'S/ —', 'S/ —', 72], ['Capacitación', 'Instructores externos', 'S/ —', 'S/ —', 58], ['Salud ocupacional', 'EMO periódicos', 'S/ —', 'S/ —', 80], ['Ingeniería', 'Guardas y cabinas acústicas', 'S/ —', 'S/ —', 35], ['Emergencias', 'Recarga de extintores', 'S/ —', 'S/ —', 100]],
});

/* 3. Riesgos */
R.MAP = (root) => {
  const marcas = [[18, 30, 'Riesgo mecánico', 'bad'], [42, 28, 'Ruido', 'warn'], [66, 34, 'Trabajos en caliente', 'bad'], [30, 70, 'Riesgo eléctrico', 'bad'], [74, 72, 'Extintor', 'ok'], [52, 62, 'Salida de emergencia', 'ok'], [86, 52, 'Primeros auxilios', 'ok'], [10, 55, 'Uso obligatorio de EPP', 'info']];
  const col = { bad: '#E2453C', warn: '#F4B90F', ok: '#22A35A', info: '#1F7FD1' };
  root.innerHTML = encMod('MAP', 'Plano de la sede con pictogramas vinculados al IPERC.', btnExport()) +
    '<div class="grid g-main"><div class="card"><div class="ch"><h3>Planta Ate · Nave 1</h3><select class="input" style="width:auto" aria-label="Plano"><option>Planta Ate · Nave 1</option><option>Almacén Callao</option><option>Oficina San Isidro · Piso 7</option></select></div><div style="overflow-x:auto"><svg viewBox="0 0 100 90" style="width:100%;min-width:420px;background:var(--surface-2);border-radius:8px" role="img" aria-label="Plano con pictogramas"><rect x="4" y="6" width="92" height="78" fill="none" stroke="var(--line-strong)" stroke-width=".8"/><rect x="6" y="10" width="44" height="34" fill="var(--surface)" stroke="var(--line-strong)" stroke-width=".4"/><text x="8" y="14" font-size="2.6" fill="var(--muted)">Línea de prensas</text><rect x="54" y="10" width="40" height="34" fill="var(--surface)" stroke="var(--line-strong)" stroke-width=".4"/><text x="56" y="14" font-size="2.6" fill="var(--muted)">Soldadura</text><rect x="6" y="50" width="40" height="32" fill="var(--surface)" stroke="var(--line-strong)" stroke-width=".4"/><text x="8" y="54" font-size="2.6" fill="var(--muted)">Mantenimiento</text><rect x="50" y="50" width="44" height="32" fill="var(--surface)" stroke="var(--line-strong)" stroke-width=".4"/><text x="52" y="54" font-size="2.6" fill="var(--muted)">Barnizado y despacho interno</text>' +
    marcas.map((m, i) => '<g data-mk="' + i + '" style="cursor:pointer">' + (m[3] === 'bad' || m[3] === 'warn' ? '<path d="M' + m[0] + ' ' + (m[1] - 3.4) + 'l3.4 6h-6.8z" fill="' + col[m[3]] + '" stroke="#262B33" stroke-width=".3"/>' : '<rect x="' + (m[0] - 3) + '" y="' + (m[1] - 3) + '" width="6" height="6" rx="1" fill="' + col[m[3]] + '"/>') + '</g>').join('') + '</svg></div></div>' +
    '<div class="card"><h3>Leyenda</h3><div class="list">' + marcas.map((m, i) => '<button class="li" data-mk="' + i + '" style="background:none;border:0;border-bottom:1px solid var(--line);width:100%;text-align:left;cursor:pointer"><span class="sem" style="background:' + col[m[3]] + '"></span><div class="lm"><b>' + m[2] + '</b><span>' + (m[3] === 'bad' || m[3] === 'warn' ? 'Advertencia · vinculado al IPERC' : m[3] === 'ok' ? 'Equipo de emergencia' : 'Obligación') + '</span></div></button>').join('') + '</div></div></div>';
  $$('[data-mk]', root).forEach((g) => g.addEventListener('click', () => { const m = marcas[+g.dataset.mk]; modal(m[2], dl([['Ubicación', 'Planta Ate · Nave 1'], ['Tipo', m[3] === 'ok' ? 'Equipo de emergencia' : 'Peligro'], ['IPERC vinculado', m[3] === 'bad' ? 'Operador de prensa troqueladora v5 · fila 3' : '—']]), [{ l: 'Cerrar', cls: 'pri' }]); }));
  bindExport(root);
};
cfgSimple('MAG', {
  desc: 'Mediciones de agentes físicos, químicos y biológicos frente a límites permisibles.',
  kpis: [['Puntos de monitoreo', '24'], ['Sobre el límite', '2'], ['Cerca del límite', '3'], ['Última campaña', 'Sep 2026']],
  cols: [C('Punto', 0), C('Agente', 1), C('Resultado', 2), C('Límite', 3, (r) => param(r[3], 'Límite permisible como parámetro con vigencia · a confirmar')), CH('Estado', 4)],
  rows: [['Prensa N.° 3', 'Ruido', '88,4 dB(A)', '85 dB(A) · 8 h', 'Sobre el límite'], ['Cabina de soldadura 2', 'Humos metálicos', '4,1 mg/m³', '5 mg/m³', 'Cerca del límite'], ['Línea de barnizado', 'Tolueno', '28 ppm', '50 ppm', 'Dentro del límite'], ['Almacén Callao', 'Iluminación', '180 lux', '≥ 200 lux', 'Sobre el límite'], ['Oficina San Isidro', 'Temperatura', '24 °C', '≤ 26 °C', 'Dentro del límite']],
  filtros: [{ k: 1, l: 'Agente' }, { k: 4, l: 'Estado' }],
});
cfgSimple('PSI', {
  desc: 'Evaluaciones ergonómicas por puesto y encuestas psicosociales anónimas.',
  tabs: [
    { nombre: 'Evaluaciones ergonómicas', cols: [C('Puesto', 0), C('Método', 1), C('Puntaje', 2), CH('Nivel', 3), C('Fecha', 4)], rows: [['Operador de línea de barnizado', 'REBA', '9', 'Alto', '12/08/2026'], ['Almacenero', 'NIOSH', '1,8', 'Medio', '20/07/2026'], ['Asistente administrativo', 'ROSA', '4', 'Bajo', '05/06/2026']] },
    { nombre: 'Encuestas psicosociales', cols: [C('Encuesta', 0), C('Participación', 1), C('Dimensión crítica', 2), CH('Estado', 3)], rows: [['Riesgo psicosocial 2026', '82% (115/140)', 'Exigencias de ritmo', 'Cerrada'], ['Clima de seguridad', '64%', '—', 'En curso']] },
  ],
});
cfgSimple('PET', {
  desc: 'Procedimientos escritos de trabajo seguro por tarea crítica, con versiones.',
  nuevo: ['Nuevo PETS', [['Título'], ['Tarea crítica'], ['Área', 'select', 'Producción', AREAS.map((a) => a[0])]], 'Crear borrador', 'PETS creado como borrador'],
  cols: [C('Código', 0), C('Procedimiento', 1), C('Área', 2), C('Versión', 3), CH('Estado', 4)],
  rows: [['PETS-PRO-04', 'Cambio de matrices en prensa troqueladora', 'Producción', 'v2', 'En revisión'], ['PETS-MAN-02', 'Bloqueo y etiquetado de energías', 'Mantenimiento', 'v3', 'Vigente'], ['PETS-ALM-01', 'Operación de montacargas', 'Logística y almacén', 'v2', 'Vigente'], ['PETS-PRO-07', 'Limpieza de tanque de barniz (espacio confinado)', 'Producción', 'v1', 'Vigente']],
  filtros: [{ k: 2, l: 'Área' }],
});
cfgSimple('MOC', {
  desc: 'Evaluar los riesgos antes de cambios en procesos, equipos o personal.',
  nuevo: ['Solicitar cambio', [['Cambio propuesto'], ['Tipo', 'select', 'Equipo', ['Proceso', 'Equipo', 'Material', 'Personal', 'Instalación']], ['Fecha prevista', 'date']], 'Enviar', 'Solicitud de cambio enviada a evaluación'],
  cols: [C('Código', 0), C('Cambio', 1), C('Tipo', 2), C('Solicitante', 3), CH('Estado', 4)],
  rows: [['MOC-2026-05', 'Nueva prensa hidráulica de 200 t', 'Equipo', 'Jorge Quispe', 'En evaluación'], ['MOC-2026-04', 'Cambio de barniz base solvente a base agua', 'Material', 'Ana María Castillo', 'Aprobado'], ['MOC-2026-03', 'Turno noche en Almacén Callao', 'Personal', 'Wilmer Ccahuana', 'Rechazado']],
});

/* 4. Campo */
cfgSimple('CH5', {
  desc: 'Charlas de 5 minutos antes de la jornada, con asistencia firmada.',
  nuevo: ['Registrar charla', [['Tema'], ['Expositor', 'select', W[1].nombre, W.map((w) => w.nombre)], ['Área', 'select', 'Producción', AREAS.map((a) => a[0])], ['Fecha', 'date', '2026-10-30']], 'Registrar y pedir firmas', 'Charla registrada; los asistentes firman desde la app'],
  kpis: [['Charlas en octubre', '64'], ['Asistencia promedio', '94%'], ['Temas distintos', '22'], ['Firmas pendientes', '6']],
  cols: [C('Fecha', 0), C('Tema', 1), C('Expositor', 2), C('Área', 3), C('Asistentes', 4), CH('Estado', 5)],
  rows: [['30/10/2026', 'Uso de guardas en prensas', 'Jorge Quispe', 'Producción', '21/22', 'Firmado'], ['30/10/2026', 'Tránsito de montacargas', 'Wilmer Ccahuana', 'Logística y almacén', '18/18', 'Firmado'], ['29/10/2026', 'Orden y limpieza', 'Gabriela Montoya', 'Producción', '19/22', 'Parcial'], ['29/10/2026', 'Bloqueo de energías', 'Edwin Chávez', 'Mantenimiento', '14/14', 'Firmado']],
  filtros: [{ k: 3, l: 'Área' }],
});
cfgSimple('ATS', {
  desc: 'Análisis de trabajo seguro antes de la tarea: pasos, peligros, controles y firmas del equipo.',
  nuevo: ['Nuevo ATS', [['Tarea'], ['Lugar'], ['Supervisor', 'select', W[1].nombre, W.map((w) => w.nombre)], ['Fecha', 'date', '2026-10-30']], 'Crear', 'ATS creado; el equipo lo firma en la app'],
  cols: [C('Código', 0), C('Tarea', 1), C('Lugar', 2), C('Equipo', 3), CH('Estado', 4)],
  rows: [['ATS-2026-412', 'Cambio de rodamientos en prensa N.° 2', 'Planta Ate', '3 personas', 'Firmado'], ['ATS-2026-411', 'Descarga de bobinas de acero', 'Almacén Callao', '4 personas', 'Firmado'], ['ATS-2026-410', 'Limpieza de canaletas en techo', 'Planta Ate', '2 personas', 'Por firmar']],
  extra: () => tabla([C('Paso', 0), C('Peligro', 1), C('Control', 2)], [['1. Bloquear energía', 'Arranque inesperado', 'Candado y tarjeta personal'], ['2. Retirar guarda', 'Cortes', 'Guantes anticorte'], ['3. Cambiar rodamiento', 'Golpes', 'Herramienta adecuada']]),
});
cfgSimple('OBS', {
  desc: 'Seguridad basada en el comportamiento: observaciones de conductas seguras y a mejorar.',
  kpis: [['Observaciones en octubre', '86'], ['Conductas seguras', '91%'], ['Conducta más observada', 'Uso de EPP'], ['Observadores activos', '12']],
  cols: [C('Fecha', 0), C('Área', 1), C('Observador', 2), C('Conductas seguras', 3), C('A mejorar', 4)],
  rows: [['30/10/2026', 'Producción', 'Jorge Quispe', '9', '1 · lentes'], ['29/10/2026', 'Logística y almacén', 'Wilmer Ccahuana', '8', '0'], ['28/10/2026', 'Mantenimiento', 'Edwin Chávez', '7', '2 · postura']],
});
cfgSimple('EPP', {
  desc: 'Entrega de EPP con firma, stock por almacén y vencimientos por trabajador.',
  nuevo: ['Registrar entrega', [['Trabajador', 'select', W[2].nombre, W.map((w) => w.nombre)], ['EPP', 'select', 'Guantes anticorte', ['Guantes anticorte', 'Lentes de seguridad', 'Protector auditivo', 'Zapatos de seguridad', 'Respirador P100']], ['Cantidad', 'number', '1'], ['Motivo', 'select', 'Reposición', ['Ingreso', 'Reposición', 'Deterioro', 'Pérdida']]], 'Registrar y pedir firma', 'Entrega registrada; el trabajador firma en la app'],
  tabs: [
    { nombre: 'Entregas', cols: [C('Trabajador', 0), C('EPP', 1), C('Entregado', 2), C('Vence', 3), CH('Firma', 4), CH('Estado', 5)], rows: W.slice(0, 12).map((w, i) => [w.nombre, ['Guantes anticorte', 'Protector auditivo', 'Lentes de seguridad', 'Zapatos de seguridad'][i % 4], '1' + (i % 9) + '/0' + (1 + i % 9) + '/2026', w.epp === 'bad' ? 'Vencido' : w.epp === 'warn' ? masDias(10) : masDias(80 + i), i === 3 ? 'Por firmar' : 'Firmado', w.epp === 'bad' ? 'Vencido' : w.epp === 'warn' ? 'Por vencer' : 'Vigente']), filtros: [{ k: 5, l: 'Estado' }, { k: 1, l: 'EPP' }] },
    { nombre: 'Stock', cols: [C('EPP', 0), C('Almacén', 1), C('Stock', 2), C('Mínimo', 3), CH('Estado', 4)], rows: [['Guantes anticorte', 'Planta Ate', '18', '30', 'Stock bajo'], ['Protector auditivo de copa', 'Planta Ate', '44', '20', 'Ok'], ['Respirador P100', 'Planta Ate', '0', '10', 'Sin stock'], ['Zapatos de seguridad (talla 40)', 'Almacén Callao', '12', '6', 'Ok']] },
    { nombre: 'EPP por puesto', cols: [C('Puesto', 0), C('EPP requerido', 1), C('Reposición', 2)], rows: [['Soldador', 'Careta fotosensible, respirador P100, mandil de cuero, guantes de cuero', 'Según desgaste'], ['Operador de prensa troqueladora', 'Guantes anticorte, lentes, protector auditivo', 'Guantes cada 30 días'], ['Operador de montacargas', 'Casco, chaleco reflectivo, zapatos', 'Chaleco cada 6 meses']] },
  ],
});
cfgSimple('EQE', {
  desc: 'Inventario de extintores, botiquines y equipos de emergencia con su mantenimiento.',
  kpis: [['Extintores', '38'], ['Por recargar', '3'], ['Botiquines', '9'], ['Inspección mensual', '92%']],
  cols: [C('Código', 0), C('Equipo', 1), C('Ubicación', 2), C('Vence', 3), CH('Estado', 4)],
  rows: [['EXT-ATE-014', 'Extintor PQS 6 kg', 'Planta Ate · Pasillo B', '12/10/2026', 'Vencido'], ['EXT-ATE-015', 'Extintor CO₂ 5 kg', 'Planta Ate · Tablero general', '20/11/2026', 'Por vencer'], ['BOT-CAL-02', 'Botiquín tipo B', 'Almacén Callao · Despacho', '—', 'Operativo'], ['LUZ-SI-03', 'Luz de emergencia', 'Oficina San Isidro · Escalera', '—', 'Operativo'], ['CAM-ATE-01', 'Camilla rígida', 'Planta Ate · Tópico', '—', 'Operativo']],
  filtros: [{ k: 1, l: 'Equipo' }, { k: 4, l: 'Estado' }],
});
cfgSimple('SIM', {
  desc: 'Programación de simulacros, participación y brigadas de emergencia.',
  tabs: [
    { nombre: 'Simulacros', cols: [C('Fecha', 0), C('Tipo', 1), C('Sede', 2), C('Participantes', 3), C('Tiempo de evacuación', 4), CH('Estado', 5)], rows: [['15/10/2026', 'Sismo', 'Planta Ate', '96', '3 min 40 s', 'Ejecutado'], ['15/10/2026', 'Sismo', 'Oficina San Isidro', '15', '2 min 10 s', 'Ejecutado'], ['20/11/2026', 'Incendio', 'Almacén Callao', '—', '—', 'Programado'], ['10/12/2026', 'Derrame químico', 'Planta Ate', '—', '—', 'Programado']] },
    { nombre: 'Brigadas', cols: [C('Brigada', 0), C('Líder', 1), C('Miembros', 2), C('Capacitación', 3, (r) => chip(r[3]))], rows: [['Evacuación', 'Percy Rojas Inga', '8', 'Vigente'], ['Primeros auxilios', 'Verónica Palomino Sotelo', '6', 'Vigente'], ['Lucha contra incendios', 'Edwin Chávez Ramos', '7', 'Por vencer']] },
  ],
});

/* 5. Personas */
const restringidoMed = () => S.role !== 'occupational_doctor' && S.role !== 'health_staff';
const avisoMed = (code) => S.role === 'occupational_doctor' ? nota('Datos médicos de la base separada. Cada apertura de detalle exige tu clave adicional y queda registrada.', '', 'shield') + '<div style="height:12px"></div>' : nota('Ves solo información no sensible. El detalle clínico es exclusivo de la médica ocupacional.', '', 'lock') + '<div style="height:12px"></div>';
const fichaMed = (r, campos) => { if (S.role === 'occupational_doctor') { if (!medicoAutenticado) { modal('Confirma tu identidad', nota('Vas a ver datos de salud. El acceso quedará registrado.', 'warn', 'shield') + campo('rm-p', 'Clave adicional', 'password', ''), [{ l: 'Cancelar' }, { l: 'Confirmar', cls: 'pri', fn: (ov) => { if (!$('#rm-p', ov).value) { toast('Escribe tu clave adicional', 'warn'); return false; } medicoAutenticado = true; setTimeout(() => cajon(r[0], 'Acceso registrado', dl(campos), [{ l: 'Cerrar' }]), 50); } }]); return null; } return { t: r[0], s: 'Acceso registrado', html: dl(campos) }; } return { t: r[0], s: 'Acceso restringido', html: '<div class="card flat stack" style="align-items:center;text-align:center;background:var(--surface-2)">' + ic('lock', 30) + '<b>Acceso restringido</b><p class="small muted">Solo la médica ocupacional puede ver el detalle clínico.</p></div>' }; };
function pantallaMed(root, code, o) {
  pantalla(root, code, Object.assign({}, o, { aviso: avisoMed(code), ficha: (r) => { const f = fichaMed(r, o.det(r)); return f || { t: r[0], s: '', html: '<p class="muted">Esperando confirmación de identidad…</p>', btns: [{ l: 'Cerrar' }] }; } }));
  if (S.role === 'occupational_doctor' && !medicoAutenticado) { /* la ficha pedirá la clave */ }
}
R.VMD = (root) => pantallaMed(root, 'VMD', {
  desc: 'Historia ocupacional y programas de vigilancia médica con acceso restringido (Ley 29733).',
  kpis: [['Programas activos', '4'], ['Casos en seguimiento', '11'], ['Controles este mes', '7'], ['Accesos registrados', '23']],
  cols: [C('Trabajador', 0), C('Programa', 1), C('Próximo control', 2), CH('Estado', 3)],
  rows: [['Teodoro Cusi Achahui', 'Conservación auditiva', '12/11/2026', 'En curso'], ['Carmen Rosa Mamani Condori', 'Trastornos musculoesqueléticos', '20/11/2026', 'En curso'], ['Héctor Llanos Bustamante', 'Exposición a humos metálicos', '05/12/2026', 'En curso'], ['Ricardo Espinoza Lazo', 'Riesgo cardiovascular', '04/11/2026', 'Abierto']],
  det: (r) => [['Programa', r[1]], ['Nota clínica (ejemplo)', 'Control audiométrico con descenso de 5 dB en 4 kHz respecto a la línea base.'], ['Indicación', 'Reubicación temporal fuera de prensas; protector de copa.']],
});
R.DES = (root) => pantallaMed(root, 'DES', {
  desc: 'Descansos médicos y ausentismo. RR. HH. ve días y fechas; el diagnóstico solo la médica.',
  kpis: [['Días perdidos (mes)', '38'], ['Tasa de ausentismo', '1,9%'], ['Descansos activos', '3'], ['Por accidente de trabajo', '1']],
  cols: [C('Trabajador', 0), C('Desde', 1), C('Hasta', 2), C('Días', 3), C('Origen', 4), CH('Estado', 5)],
  rows: [['Christian Valdivia Neyra', '30/10/2026', '31/10/2026', '2', 'Accidente de trabajo', 'En curso'], ['Ricardo Espinoza Lazo', '20/10/2026', '03/11/2026', '15', 'Enfermedad común', 'En curso'], ['Gladys Ccori Mendoza', '14/10/2026', '16/10/2026', '3', 'Enfermedad común', 'Cerrado']],
  filtros: [{ k: 4, l: 'Origen' }],
  det: (r) => [['Periodo', r[1] + ' – ' + r[2]], ['Diagnóstico (CIE-10, ejemplo)', 'S61.4 Herida de la mano'], ['Certificado', 'CITT EsSalud N.° A-2026-118273 (inventado)']],
});
R.VUL = (root) => pantallaMed(root, 'VUL', {
  desc: 'Protección de gestantes, menores de edad y personas con discapacidad, con ajustes del puesto.',
  cols: [C('Trabajador', 0), C('Condición', 1, (r) => restringidoMed() ? '<span class="muted">Reservado</span>' : esc(r[1])), C('Ajuste del puesto', 2), CH('Estado', 3)],
  rows: [['Mariela Rivas Contreras', 'Gestante (2.º trimestre)', 'Reubicada fuera de barnizado (solventes)', 'Vigente'], ['Hugo Calderón Meza', 'Discapacidad auditiva', 'Alarma luminosa en su zona', 'Vigente']],
  det: (r) => [['Condición', r[1]], ['Ajuste', r[2]], ['Revisión', 'Mensual por la médica ocupacional']],
});

/* 6. Eventos */
R.ACR = (root) => pantalla(root, 'ACR', {
  desc: 'Acciones correctivas de inspecciones, reportes, accidentes y auditorías, con evidencia de cierre.',
  nuevo: ['Nueva acción', [['Acción'], ['Origen', 'select', 'Inspección', ['Inspección', 'Reporte', 'Accidente', 'Auditoría', 'Comité']], ['Responsable', 'select', W[4].nombre, W.map((w) => w.nombre)], ['Vence', 'date']], 'Crear', 'Acción correctiva creada y notificada al responsable'],
  kpis: [['Abiertas', String(ACCIONES.filter((a) => a.e !== 'Cerrada').length)], ['Vencidas', String(ACCIONES.filter((a) => a.e === 'Vencida').length)], ['Cerradas en plazo', '86%'], ['Cerradas este mes', '18']],
  cols: [C('Código', 'id'), C('Acción', 't'), C('Origen', 'o'), C('Responsable', 'r'), C('Vence', 'v'), C('Avance', 'p', (a) => '<div style="min-width:90px">' + barra(a.p) + '</div>'), C('Estado', 'e', (a) => chip(a.e))],
  rows: ACCIONES, filtros: [{ k: 'e', l: 'Estado' }, { k: 'r', l: 'Responsable' }],
  ficha: (a) => { setTimeout(() => fichaAccion(a), 0); return { t: a.id, s: '', html: '' }; },
});
cfgSimple('AUI', {
  desc: 'Auditorías internas y externas del SG-SST con sus hallazgos.',
  cols: [C('Código', 0), C('Tipo', 1), C('Auditor', 2), C('Fecha', 3), C('Hallazgos', 4), CH('Estado', 5)],
  rows: [['AUI-2026-02', 'Interna', 'Gabriela Montoya', '18/09/2026', '2 NC menores · 4 observaciones', 'Cerrado'], ['AUD-2026-EXT', 'Externa (obligatoria)', 'Mónica Herrera Bazán · auditora registrada', '10/11/2026', '—', 'Programado'], ['AUI-2026-01', 'Interna', 'Rocío Villanueva', '20/03/2026', '1 NC mayor · 3 menores', 'Cerrado']],
});
R.EST = (root) => {
  pantalla(root, 'EST', {
    desc: 'Horas-hombre trabajadas e índices de frecuencia, gravedad y accidentabilidad por mes.',
    aviso: nota('Factores de cálculo como parámetro: ' + param('IF = N.° accidentes × 1 000 000 / HHT') + ' · ' + param('IG = días perdidos × 1 000 000 / HHT') + ' · ' + param('IA = IF × IG / 1 000') + '.', 'warn') + '<div style="height:12px"></div>',
    extra: '<div class="card" style="margin-bottom:14px">' + lineChart([{ n: 'Índice de frecuencia', c: 'var(--brand)', v: [9.1, 8.4, 7.9, 10.2, 8.8, 7.1, 6.5, 7.4, 6.9, 6.8], area: 1 }], ['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O'], { aria: 'Índice de frecuencia mensual' }) + '</div>',
    cols: [C('Mes', 0), C('Trabajadores', 1), C('Horas-hombre', 2), C('Accidentes con baja', 3), C('Días perdidos', 4), C('IF', 5), C('IG', 6), C('IA', 7)],
    rows: [['Octubre', 140, '29 120', 1, 2, '34,3', '68,7', '2,36'], ['Septiembre', 139, '28 912', 1, 12, '34,6', '415,0', '14,36'], ['Agosto', 138, '30 360', 0, 0, '0,0', '0,0', '0,00'], ['Julio', 137, '30 140', 0, 0, '0,0', '0,0', '0,00'], ['Junio', 136, '28 288', 1, 5, '35,4', '176,8', '6,25']],
    nuevo: ['Registrar horas-hombre', [['Mes', 'select', 'Noviembre', ['Noviembre']], ['Trabajadores promedio', 'number', '140'], ['Horas-hombre trabajadas', 'number', '29000']], 'Guardar', 'Horas-hombre registradas; índices recalculados'],
  });
};
cfgSimple('RVD', {
  desc: 'Revisión anual del SG-SST por la alta dirección, con sus decisiones.',
  cols: [C('Año', 0), C('Fecha', 1), C('Participantes', 2), C('Decisiones', 3), CH('Estado', 4)],
  rows: [['2026', '15/12/2026', 'Gerencia, Comité, Jefa SST', '—', 'Programado'], ['2025', '16/12/2025', 'Gerencia, Comité, Jefa SST', '6 decisiones · 5 cumplidas', 'Cerrado']],
  extra: () => '<h4>Entradas del informe</h4><ul class="small muted" style="padding-left:18px"><li>Cumplimiento de objetivos y del PASST</li><li>Índices y tendencia de accidentabilidad</li><li>Resultados de auditorías</li><li>Estado de acciones correctivas</li></ul>',
});
/* 7. Contratistas */
cfgSimple('CTR', {
  desc: 'Acreditación de empresas contratistas y sus trabajadores: documentos, vigencias y aprobación.',
  nuevo: ['Invitar contratista', [['RUC', 'text', ''], ['Razón social'], ['Correo del administrador', 'email'], ['Servicio', 'select', 'Mantenimiento eléctrico', ['Mantenimiento eléctrico', 'Limpieza industrial', 'Transporte', 'Seguridad', 'Obras civiles']]], 'Enviar invitación', 'Invitación enviada al portal de contratistas'],
  kpis: [['Contratistas activos', '9'], ['Acreditados', '6'], ['Con documentos vencidos', '2'], ['Trabajadores de terceros', '58']],
  cols: [C('Contratista', 0), C('RUC', 1), C('Servicio', 2), C('Trabajadores', 3), C('Documentos', 4, (r) => '<div style="min-width:100px">' + barra(r[4]) + '<span class="tiny">' + r[4] + '%</span></div>'), CH('Acreditación', 5)],
  rows: [['Montajes Eléctricos Rímac E.I.R.L.', '20609182742', 'Mantenimiento eléctrico', '6', 80, 'En revisión'], ['Limpiezas Industriales Sol S.A.C.', '20482371567', 'Limpieza industrial', '14', 100, 'Acreditado'], ['Transportes Chalaco S.R.L.', '20559013481', 'Transporte', '8', 60, 'Observado'], ['Seguridad Integral Inca S.A.C.', '20518472632', 'Seguridad', '12', 100, 'Acreditado']],
  filtros: [{ k: 5, l: 'Acreditación' }],
  extra: () => '<h4>Documentos requeridos</h4><div class="list">' + [['Ficha RUC', 'ok'], ['SCTR de trabajadores', 'warn'], ['IPERC del servicio', 'ok'], ['EMO de trabajadores', 'bad'], ['Plan de seguridad', 'ok']].map((d) => '<div class="li">' + sem(d[1]) + '<div class="lm"><b>' + d[0] + '</b></div></div>').join('') + '</div>',
  btns: () => S.role === 'contractor_admin' ? [{ l: 'Subir documento', cls: 'pri', fn: () => toast('Documento subido; queda en revisión') }] : [{ l: 'Observar', fn: () => toast('Observación enviada al contratista') }, { l: 'Aprobar acreditación', cls: 'pri', fn: () => toast('Contratista acreditado') }],
});
cfgSimple('SCT', {
  desc: 'Pólizas SCTR (salud y pensión) y su vigencia por trabajador.',
  kpis: [['Pólizas vigentes', '2'], ['Trabajadores cubiertos', '138 / 140'], ['Por vencer (30 días)', '1'], ['Sin cobertura', '2']],
  tabs: [
    { nombre: 'Cobertura por trabajador', cols: [C('Trabajador', 0), C('Póliza salud', 1), C('Póliza pensión', 2), C('Vence', 3), CH('Estado', 4)], rows: W.slice(0, 32).map((w) => [w.nombre, 'EsSalud · SCTR-S 88213', 'Pacífico · SCTR-P 40917', masDias(w.sctrDias), w.sctrDias <= 0 ? 'Vencido' : w.sctrDias <= 30 ? 'Por vencer' : 'Vigente']), filtros: [{ k: 4, l: 'Estado' }] },
    { nombre: 'Pólizas', cols: [C('Póliza', 0), C('Aseguradora', 1), C('Cobertura', 2), C('Vigencia', 3), CH('Estado', 4)], rows: [['SCTR-S 88213', 'EsSalud', 'Salud', '01/01/2026 – 31/12/2026', 'Vigente'], ['SCTR-P 40917', 'Pacífico Seguros', 'Pensión', '24/11/2025 – 24/11/2026', 'Por vencer']] },
  ],
});
cfgSimple('ING', {
  desc: 'Portería: escaneo del QR del tercero y respuesta inmediata de habilitado o no habilitado.',
  aviso: '<div class="grid g2" style="margin-bottom:14px"><div class="card" style="text-align:center;border:2px solid var(--ok)"><span style="color:var(--ok)">' + ic('check', 40) + '</span><h2>Habilitado</h2><p class="small muted">Luis Fernando Aquino Torres · Montajes Eléctricos Rímac · 09:31</p></div><div class="card" style="text-align:center;border:2px solid var(--bad)"><span style="color:var(--bad)">' + ic('x', 40) + '</span><h2>No habilitado</h2><p class="small muted">Iván Choque Laura · Transportes Chalaco · SCTR vencido · 09:12</p></div></div>',
  cols: [C('Hora', 0), C('Persona', 1), C('Empresa', 2), C('Punto', 3), CH('Resultado', 4), C('Motivo', 5)],
  rows: [['09:31', 'Luis Fernando Aquino Torres', 'Montajes Eléctricos Rímac', 'Portería Planta Ate', 'Habilitado', '—'], ['09:12', 'Iván Choque Laura', 'Transportes Chalaco', 'Portería Almacén Callao', 'No habilitado', 'SCTR vencido'], ['08:47', 'Rosa Mendoza Ccallo', 'Limpiezas Industriales Sol', 'Portería Planta Ate', 'Habilitado', '—']],
  filtros: [{ k: 4, l: 'Resultado' }],
});
cfgSimple('HOM', {
  desc: 'Evaluación y calificación de proveedores con criterios SST. Complemento: precio por definir.',
  cols: [C('Proveedor', 0), C('Rubro', 1), C('Puntaje', 2), CH('Calificación', 3), C('Próxima evaluación', 4)],
  rows: [['Distribuidora de EPP Andina S.A.C.', 'EPP', '92/100', 'Aprobado', '03/2027'], ['Recargas Extintores Lima E.I.R.L.', 'Emergencias', '74/100', 'Observado', '12/2026'], ['Clínica Ocupacional San Gabriel', 'Salud ocupacional', '88/100', 'Aprobado', '06/2027']],
});
/* 8. Diferenciadores e integraciones restantes */
cfgSimple('ISO', {
  desc: 'ISO 45001, 9001 y 14001 en una sola gestión: cláusulas y evidencias. Complemento: precio por definir.',
  cols: [C('Norma', 0), C('Cláusula', 1), C('Requisito', 2), C('Evidencias', 3), CH('Estado', 4)],
  rows: [['ISO 45001', '6.1.2', 'Identificación de peligros', 'Matrices IPERC (12)', 'Cumple'], ['ISO 45001', '5.4', 'Consulta y participación', 'Actas del Comité', 'Cumple'], ['ISO 14001', '6.1.2', 'Aspectos ambientales', '—', 'Pendiente'], ['ISO 9001', '7.5', 'Información documentada', 'Gestión documental', 'Cumple']],
  filtros: [{ k: 0, l: 'Norma' }],
});
cfgSimple('AMB', {
  desc: 'Aspectos ambientales y registro de residuos (SSOMA). Complemento: precio por definir.',
  tabs: [
    { nombre: 'Aspectos ambientales', cols: [C('Actividad', 0), C('Aspecto', 1), C('Impacto', 2), CH('Significancia', 3)], rows: [['Barnizado', 'Emisión de COV', 'Contaminación del aire', 'Alto'], ['Troquelado', 'Generación de chatarra', 'Uso de recursos', 'Medio'], ['Mantenimiento', 'Aceite usado', 'Contaminación del suelo', 'Alto']] },
    { nombre: 'Residuos', cols: [C('Fecha', 0), C('Residuo', 1), C('Cantidad', 2), C('Gestor (EO-RS)', 3), CH('Estado', 4)], rows: [['28/10/2026', 'Aceite usado', '220 L', 'Ecogestión Lima S.A.C.', 'Entregado'], ['20/10/2026', 'Chatarra de acero', '1,8 t', 'Reciclados del Sur', 'Entregado']] },
  ],
});
cfgSimple('API', {
  desc: 'Conexión con planillas, ERP y aulas virtuales mediante API y webhooks.',
  tabs: [
    { nombre: 'Clientes de API', cols: [C('Nombre', 0), C('Permisos', 1), C('Último uso', 2), CH('Estado', 3)], rows: [['Planilla (sistema de RR. HH.)', 'workers.read, workers.write', '30/10/2026 06:00', 'Activo'], ['ERP · centro de costos', 'kpi.read', '29/10/2026 23:00', 'Activo'], ['Aula virtual externa', 'trainings.write', '—', 'Inactivo']] },
    { nombre: 'Webhooks', cols: [C('Evento', 0), C('Destino', 1), C('Últimas entregas', 2), CH('Estado', 3)], rows: [['sst_event.created', 'https://intranet.andinasdelsur.pe/hooks/sst', '12 ok · 0 error', 'Activo'], ['worker.credential.revoked', 'https://porteria.andinasdelsur.pe/api', '3 ok · 1 error', 'Observado']] },
  ],
});

/* Cobertura: todo módulo del catálogo tiene renderizador */
MODS.forEach((m) => { if (!R[m.code]) console.warn('Módulo sin pantalla:', m.code); });
