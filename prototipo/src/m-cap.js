/* Capacitaciones e inducciones (CAP): lista, matriz de capacitación por cargo, certificados y plantilla; verificación pública por código. */

const CERT_CLS = { 'En validación': 'warn', Validado: 'ok', Anulado: 'na' };
const CERTIFICADOS = [
  { cod: 'CERT-2026-000142', emp: 'andinas', w: 4, curso: 'CUR-014', r: 0.86, emit: '14/10/2026', vence: '14/10/2027', est: 'Validado', valida: 'Rocío Villanueva Tapia' },
  { cod: 'CERT-2026-000139', emp: 'andinas', w: 16, curso: 'CUR-014', r: 0.8, emit: '12/10/2026', vence: '12/10/2027', est: 'Validado', valida: 'Rocío Villanueva Tapia' },
  { cod: 'CERT-2026-000155', emp: 'andinas', w: 26, curso: 'CUR-014', r: 0.76, emit: '29/10/2026', vence: '29/10/2027', est: 'En validación' },
  { cod: 'CERT-2026-000156', emp: 'andinas', w: 5, curso: 'CUR-017', r: 0.82, emit: '29/10/2026', vence: '29/10/2027', est: 'En validación' },
  { cod: 'CERT-2026-000157', emp: 'andinas', w: 37, curso: 'CUR-011', r: 0.9, emit: '30/10/2026', vence: '30/10/2027', est: 'En validación' },
  { cod: 'CERT-2026-000121', emp: 'andinas', w: 30, curso: 'CUR-008', r: 0.9, emit: '19/11/2025', vence: masDias(20), est: 'Validado', valida: 'Rocío Villanueva Tapia', porVencer: true },
  { cod: 'CERT-2026-000088', emp: 'andinas', w: 22, curso: 'CUR-003', r: 0.95, emit: '03/03/2026', vence: 'Sin vencimiento', est: 'Validado', valida: 'Gabriela Montoya Díaz' },
  { cod: 'CERT-2026-000097', emp: 'andinas', w: 12, curso: 'CUR-017', r: 0.72, emit: '28/08/2026', vence: '28/08/2027', est: 'Anulado', motivo: 'Se comprobó que la evaluación la rindió otra persona.', anula: 'Rocío Villanueva Tapia · 02/09/2026' },
  { cod: 'CERT-2026-000131', emp: 'qhaliy', ext: 0, curso: 'QH-110', r: 0.84, emit: '21/09/2026', vence: '21/09/2027', est: 'Validado', valida: 'Maribel Sotomayor Quiroz' },
  { cod: 'CERT-2026-000149', emp: 'qhaliy', ext: 2, curso: 'QH-104', r: 0.81, emit: '20/10/2026', vence: '20/10/2027', est: 'Validado', valida: 'Maribel Sotomayor Quiroz' },
  { cod: 'CERT-2026-000153', emp: 'qhaliy', ext: 5, curso: 'QH-101', r: 0.77, emit: '28/10/2026', vence: '28/10/2028', est: 'En validación' },
];
const certBy = (cod) => CERTIFICADOS.find((x) => x.cod === cod);
const certPersona = (r) => r.w ? wById(r.w) : r.persona ? { nombre: r.persona[0], dni: r.persona[1], puesto: r.persona[2] } : { nombre: PERS_EXT[r.ext][0], dni: PERS_EXT[r.ext][1], puesto: 'Participante externo' };
let certSiguiente = 158;
function emitirCertAlumno(c, st, persona) {
  if (st.cert) return;
  const cod = 'CERT-2026-' + String(certSiguiente++).padStart(6, '0');
  CERTIFICADOS.unshift({ cod, emp: c.emp, w: c.emp === 'andinas' && persona[0] === W[2].nombre ? 3 : null, persona, curso: c.id, r: st.nota, emit: '30/10/2026', vence: '30/10/2027', est: 'En validación' });
  st.cert = cod;
}
function certHTML(r, opt = {}) {
  const c = cursoBy(r.curso); const p = certPersona(r); const e = EMPRESAS.find((x) => x.id === r.emp) || EMPRESAS[0];
  const fir = r.emp === 'qhaliy' ? ['Maribel Sotomayor Quiroz', 'Directora académica'] : ['Rocío Villanueva Tapia', 'Jefa de SST'];
  const o = Object.assign({ v: 'clasica', nota: true, horas: true, qr: true, f2: true }, opt);
  return '<div class="cert v-' + o.v + '"><div class="cert-in">' +
    (r.est !== 'Validado' ? '<span class="cert-wm">' + (r.est === 'Anulado' ? 'ANULADO' : 'EN VALIDACIÓN') + '</span>' : '') +
    '<div class="cert-top"><span class="cert-logo" style="background:' + (r.emp === 'qhaliy' ? '#0E7A70' : '#0D3B6E') + '">' + iniciales(e.corto) + '</span><div><b>' + esc(e.nombre) + '</b><span>RUC ' + e.ruc + '</span></div><span class="cert-code">' + r.cod + '</span></div>' +
    '<div class="cert-k">Certificado</div><div class="cert-s">Se certifica que</div><div class="cert-n">' + esc(p.nombre) + '</div><div class="cert-s">DNI ' + enmascarar(p.dni) + ' · ' + esc(p.puesto) + '</div>' +
    '<div class="cert-s">aprobó el curso</div><div class="cert-c">' + esc(c.n) + '</div>' +
    '<div class="cert-s">' + [o.horas ? 'Duración: ' + c.horas + ' horas' : '', o.nota ? 'Nota: ' + notaTxt(c.escala, r.r) + (escNum(c.escala) ? '/' + c.escala.split('-')[1] : '') : '', 'Emitido el ' + r.emit, r.vence === 'Sin vencimiento' ? 'Sin vencimiento' : 'Vigente hasta el ' + r.vence].filter(Boolean).join(' · ') + '</div>' +
    '<div class="cert-f"><div class="sig"><i></i><b>' + fir[0] + '</b><span>' + fir[1] + '</span></div>' + (o.qr ? '<div class="cert-qr">' + (QR.certificado || '') + '<span>Verifica con el código en verifica.escudosst.com</span></div>' : '') + (o.f2 ? '<div class="sig"><i></i><b>' + esc(c.cap) + '</b><span>Capacitador</span></div>' : '') + '</div>' +
    '<div class="cert-pie">Emitido con EscudoSST · Aula virtual de ' + esc(e.corto) + '</div></div></div>';
}

/* ---------- Matriz de capacitación por cargo ---------- */
const MATRIZ_CURSOS = [['Inducción SST general', 'CUR-003', 'Inducción'], ['Uso y cuidado de EPP', 'CUR-008', 'EPP'], ['Trabajo seguro con prensas', 'CUR-011', 'Prensas'], ['Trabajos en caliente', 'CUR-014', 'En caliente'], ['Bloqueo y etiquetado', 'CUR-017', 'LOTO'], ['Montacargas', 'CUR-019', 'Montacargas'], ['Primeros auxilios', null, 'Primeros auxilios']];
const MATRIZ = {
  'Operador de prensa troqueladora': [22, 20, 15, null, 17, null, null],
  Soldador: [10, 9, null, 7, null, null, null],
  'Operador de línea de barnizado': [12, 12, null, null, null, null, null],
  'Técnico electricista': [6, 6, null, null, 4, null, 5],
  'Mecánico de mantenimiento': [8, 7, null, 6, 5, null, null],
  'Operador de montacargas': [9, 9, null, null, null, 6, null],
  Almacenero: [13, 12, null, null, null, null, null],
  'Inspector de calidad': [9, 9, null, null, null, null, null],
  'Asistente administrativo': [7, null, null, null, null, null, 5],
  'Supervisor de producción': [5, 5, 4, null, 5, null, 3],
};
const totCargo = (p) => (PUESTOS.find((x) => x[0] === p) || [0, 0, 1])[2];
const semPct = (p) => p >= 90 ? 'ok' : p >= 70 ? 'warn' : 'bad';
function cumplMatriz() { let a = 0; let t = 0; Object.entries(MATRIZ).forEach(([p, v]) => v.forEach((x) => { if (x != null) { a += x; t += totCargo(p); } })); return Math.round(a * 100 / t); }

R.CAP = (root) => {
  let t = 0;
  const emp = empAula();
  const aulaOn = acceso('AUL') === 'ok' || (planIncluye(modBy('AUL')) && featActiva('AUL'));
  const certs = () => CERTIFICADOS.filter((x) => x.emp === emp);
  const canVal = !soloLectura() && S.role !== 'trainer';
  const tabsN = ['Capacitaciones', 'Matriz por cargo', 'Certificados', 'Plantilla de certificado'];
  root.innerHTML = encMod('CAP', 'Capacitaciones presenciales y del aula virtual, matriz de cursos obligatorios por cargo y certificados con verificación pública.', '<a class="btn" href="#pub-certificado">' + ic('shield', 16) + 'Verificar un certificado</a>' + btnExport() + btnNuevo('Programar capacitación')) +
    '<div class="grid g4" style="margin-bottom:16px" id="cap-k"></div>' + tabs('cap', tabsN, 0) + '<div id="cap-b"></div>';
  const kp = () => { $('#cap-k', root).innerHTML = kpi('Horas por trabajador (año)', '<span style="color:var(--brand)">14,2</span>', 'Presencial + aula virtual') + kpi('Cumplimiento de la matriz', '<span style="color:var(--' + (cumplMatriz() >= 90 ? 'ok' : 'amber-ink') + ')">' + cumplMatriz() + '%</span>', 'Cursos obligatorios por cargo') + kpi('Certificados por validar', '<span style="color:var(--orange)">' + certs().filter((x) => x.est === 'En validación').length + '</span>') + kpi('Certificados por vencer', '<span style="color:var(--bad)">' + certs().filter((x) => x.porVencer).length + '</span>', 'En los próximos 30 días'); };
  const b = $('#cap-b', root);
  const pint = () => {
    kp();
    if (t === 0) {
      const rows = [['28/10/2026', 'Uso de extintores', 'Presencial', 'Bomberos voluntarios Ate', '42/45', '17,8', 'Realizado'], ['22/10/2026', 'Inducción SST (nuevos ingresos)', 'Presencial', 'Rocío Villanueva', '3/3', '18,0', 'Realizado'], ['12/11/2026', 'Trabajos en caliente (práctica)', 'Presencial', CAPACITADOR, '—', '—', 'Programado'], ['05/11/2026', 'Bloqueo y etiquetado', 'Virtual en vivo', 'Edwin Chávez', '—', '—', 'Programado'], ['15/09/2026', 'Primeros auxilios', 'Presencial', 'Cruz Roja Peruana', '38/40', '16,9', 'Realizado']]
        .concat(aulaOn ? cursosEmp().filter((c) => c.estado === 'Publicado').slice(0, 4).map((c) => ['Permanente', c.n, 'Aula virtual', c.cap, c.aprob + '/' + c.mat, '—', 'En curso']) : []);
      b.innerHTML = (aulaOn ? nota('Las aprobaciones del aula virtual se registran aquí como constancias y suman horas por el tiempo de permanencia real.', '', 'play') : nota('El aula virtual está desactivada para esta empresa: aquí se registran solo capacitaciones presenciales o en vivo.', 'warn', 'power')) + '<div style="height:12px"></div><div id="cap-l"></div>';
      pantallaLista($('#cap-l', b), rows, [{ l: 'Fecha', k: 0 }, { l: 'Tema', f: (r) => '<b>' + esc(r[1]) + '</b>' }, { l: 'Modalidad', f: (r) => chip(r[2], r[2] === 'Aula virtual' ? 'info' : 'na') }, { l: 'Instructor', k: 3 }, { l: 'Asistentes', k: 4 }, { l: 'Nota promedio', k: 5 }, { l: 'Estado', f: (r) => chip(r[6]) }], [[2, 'Modalidad'], [6, 'Estado']], (r) => cajon(r[1], esc(r[2]) + ' · ' + r[0], dl([['Instructor', esc(r[3])], ['Asistentes', r[4]], ['Nota promedio', r[5]], ['Estado', chip(r[6])]]) + (r[2] === 'Aula virtual' ? '<a class="btn" href="#m-aul/' + (cursosEmp().find((c) => c.n === r[1]) || {}).id + '">Abrir el curso en el aula</a>' : ''), [{ l: 'Ver constancias', fn: () => toast('Constancias generadas en PDF (simulado)') }, { l: 'Tomar asistencia por QR', cls: 'pri', fn: () => toast('QR de asistencia mostrado (simulado)') }]));
    } else if (t === 1) {
      const rows = Object.keys(MATRIZ).map((p) => [p, totCargo(p)].concat(MATRIZ[p]));
      const cel = (r, j) => { const v = r[2 + j]; if (v == null) return '<span class="mx-na" title="No obligatorio">—</span>'; const pc = Math.round(v * 100 / r[1]); return '<span class="mx ' + semPct(pc) + '" title="' + v + ' de ' + r[1] + '">' + sem(semPct(pc)) + '<b class="num">' + pc + '%</b><span class="tiny muted">' + v + '/' + r[1] + '</span></span>'; };
      b.innerHTML = '<div class="row between" style="margin-bottom:12px"><div class="gauge-legend"><span>' + sem('ok') + ' 90% o más</span><span>' + sem('warn') + ' 70% a 89%</span><span>' + sem('bad') + ' menos de 70%</span><span><b>—</b> no obligatorio</span></div>' + (canVal ? '<button class="btn" id="mx-ed">' + ic('pen', 16) + 'Editar cursos obligatorios</button>' : '') + '</div>' +
        '<div class="mxw">' + tabla([{ l: 'Cargo', w: '210px', f: (r) => '<b>' + esc(r[0]) + '</b><div class="tiny muted">' + r[1] + ' trabajadores</div>' }].concat(MATRIZ_CURSOS.map((m, j) => ({ l: m[2], f: (r) => cel(r, j) }))), rows, { click: 1 }) + '</div><p class="tiny muted" style="margin-top:6px">' + MATRIZ_CURSOS.map((m) => '<b>' + m[2] + '</b>: ' + m[0]).filter((x, i) => MATRIZ_CURSOS[i][2] !== MATRIZ_CURSOS[i][0]).join(' · ') + '</p>' +
        '<div style="height:12px"></div>' + nota('Cuando un trabajador cambia de cargo, se le matriculan los cursos obligatorios del nuevo cargo. Las brechas alimentan las recomendaciones de cursos.');
      bindTabla(b, rows, (r) => {
        const ws = W.filter((w) => w.puesto === r[0]);
        cajon(r[0], r[1] + ' trabajadores', '<div class="list">' + MATRIZ_CURSOS.map((m, j) => { const v = r[2 + j]; if (v == null) return ''; const pc = Math.round(v * 100 / r[1]); return '<div class="li">' + sem(semPct(pc)) + '<div class="lm"><b>' + esc(m[0]) + '</b><span>' + v + ' de ' + r[1] + ' cumplen · ' + (r[1] - v) + ' pendiente(s)</span></div><b class="num">' + pc + '%</b></div>'; }).join('') + '</div>' +
          (ws.length ? '<div><h4 style="margin-bottom:6px">Pendientes de ejemplo</h4><div class="list">' + ws.slice(0, 4).map((w) => '<div class="li"><span class="av" style="width:30px;height:30px;font-size:.72rem">' + iniciales(w.nombre) + '</span><div class="lm"><b>' + esc(w.nombre) + '</b><span>' + (w.cap === 'ok' ? 'Al día' : 'Falta 1 curso obligatorio') + '</span></div>' + chip(w.cap === 'ok' ? 'Cumple' : 'Pendiente', w.cap === 'ok' ? 'ok' : 'warn') + '</div>').join('') + '</div></div>' : ''),
        canVal ? [{ l: 'Cerrar' }, { l: 'Notificar a pendientes', cls: 'pri', fn: () => toast('Aviso enviado a los pendientes de «' + r[0] + '»') }] : [{ l: 'Cerrar' }]);
      });
      const ed = $('#mx-ed', b); if (ed) ed.onclick = () => {
        const ov = modal('Cursos obligatorios por cargo', campo('mx-p', 'Cargo', 'select', Object.keys(MATRIZ)[0], Object.keys(MATRIZ)) + '<div class="checklist" id="mx-c"></div>', [{ l: 'Cancelar' }, { l: 'Guardar', cls: 'pri', fn: (o) => { const p = $('#mx-p', o).value; MATRIZ[p] = MATRIZ_CURSOS.map((m, j) => $('#mxc-' + j, o).checked ? (MATRIZ[p][j] != null ? MATRIZ[p][j] : 0) : null); toast('Matriz actualizada; se matriculó a los trabajadores del cargo'); pint(); } }]);
        const dib = () => { const p = $('#mx-p', ov).value; $('#mx-c', ov).innerHTML = MATRIZ_CURSOS.map((m, j) => '<label for="mxc-' + j + '"><input type="checkbox" id="mxc-' + j + '"' + (MATRIZ[p][j] != null ? ' checked' : '') + '><span><b>' + esc(m[0]) + '</b></span></label>').join(''); };
        $('#mx-p', ov).onchange = dib; dib();
      };
    } else if (t === 2) {
      const lst = certs();
      const pend = lst.filter((x) => x.est === 'En validación');
      b.innerHTML = '<div class="row between" style="margin-bottom:12px">' + nota('Cada certificado nace «En validación» al aprobar el curso. El administrador lo valida o lo anula con motivo. Los cursos verificables se pueden comprobar en la página pública por código o QR.', '', 'award') + (canVal && pend.length ? '<button class="btn pri" id="ce-all">' + ic('check', 16) + 'Validar pendientes (' + pend.length + ')</button>' : '') + '</div><div id="ce-l"></div>';
      pantallaLista($('#ce-l', b), lst.map((x) => [x.cod, certPersona(x).nombre, cursoBy(x.curso).n, notaTxt(cursoBy(x.curso).escala, x.r), x.emit, x.vence, x.est, certPersona(x).puesto]), [{ l: 'Código', f: (r) => '<span class="mono">' + r[0] + '</span>' }, { l: 'Participante', f: (r) => '<b>' + esc(r[1]) + '</b><div class="tiny muted">' + esc(r[7]) + '</div>' }, { l: 'Curso', k: 2 }, { l: 'Nota', k: 3 }, { l: 'Emitido', k: 4 }, { l: 'Vence', f: (r) => certBy(r[0]).porVencer ? '<span class="row" style="gap:6px;display:inline-flex">' + sem('warn') + r[5] + '</span>' : esc(r[5]) }, { l: 'Estado', f: (r) => chip(r[6], CERT_CLS[r[6]]) }], [[6, 'Estado']], (r) => fichaCert(certBy(r[0]), canVal, pint));
      const all = $('#ce-all', b); if (all) all.onclick = () => modal('Validar ' + pend.length + ' certificados', '<p>Se validarán los certificados en validación de esta empresa. El participante recibirá el suyo en su app o portal.</p>', [{ l: 'Cancelar' }, { l: 'Validar', cls: 'pri', fn: () => { pend.forEach((x) => { x.est = 'Validado'; x.valida = USUARIOS[S.role][0]; }); toast(pend.length + ' certificados validados'); pint(); } }]);
    } else {
      const o = { v: 'clasica', nota: true, horas: true, qr: true, f2: true };
      const ej = certBy('CERT-2026-000142');
      const dib = () => { $('#pl-prev', b).innerHTML = certHTML(emp === 'qhaliy' ? certBy('CERT-2026-000131') : ej, o); };
      b.innerHTML = '<div class="grid g-main" style="align-items:start"><div class="stack"><div id="pl-prev"></div><p class="tiny muted">Ejemplo con datos de demostración. El QR lleva solo el código del certificado.</p></div><div class="card stack" style="gap:12px"><h3>Plantilla</h3><div class="field"><span>Diseño</span>' + seg('pl-v', ['Clásica', 'Moderna'], 0) + '</div>' +
        sw('pl-n', true, 'Mostrar la nota') + sw('pl-h', true, 'Mostrar las horas') + sw('pl-q', true, 'QR de verificación', 'Enlaza a la página pública por código') + sw('pl-f', true, 'Firma del capacitador') + campo('pl-x', 'Texto adicional', 'text', 'Conforme al Programa Anual de Capacitación 2026') +
        (canVal ? '<button class="btn pri" id="pl-g">Guardar plantilla</button>' : '') + '</div></div>';
      bindSeg(b, 'pl-v', (i) => { o.v = i ? 'moderna' : 'clasica'; $$('[data-seg="pl-v"] button', b).forEach((x, j) => x.classList.toggle('on', i === j)); dib(); });
      [['pl-n', 'nota'], ['pl-h', 'horas'], ['pl-q', 'qr'], ['pl-f', 'f2']].forEach(([id, k]) => { $('#' + id, b).onchange = (e) => { o[k] = e.target.checked; dib(); }; });
      const g = $('#pl-g', b); if (g) g.onclick = () => toast('Plantilla de certificado guardada');
      dib();
    }
  };
  bindTabs(root, 'cap', (i) => { t = i; $$('[data-tabs="cap"] button', root).forEach((x, j) => { x.classList.toggle('on', i === j); x.setAttribute('aria-selected', i === j); }); pint(); });
  bindExport(root);
  const nv = $('#nuevo', root); if (nv) nv.onclick = () => formModal('Programar capacitación', [['Tema'], ['Instructor', 'select', CAPACITADOR, [CAPACITADOR, 'Gabriela Montoya Díaz', 'Externo']], ['Fecha', 'date'], ['Modalidad', 'select', 'Presencial', ['Presencial', 'Virtual en vivo'].concat(aulaOn ? ['Aula virtual'] : [])], ['Dirigido a', 'select', 'Soldadores', ['Todo el personal', 'Soldadores', 'Operadores de montacargas', 'Nuevos ingresos']]], 'Programar', 'Capacitación programada; se avisó a los participantes');
  pint();
};
function fichaCert(r, canVal, done) {
  const btns = [{ l: 'Cerrar' }];
  if (r.est === 'Validado') btns.push({ l: 'Ver verificación pública', fn: () => { window.__certCodigo = r.cod; location.hash = 'pub-certificado'; } });
  if (canVal && r.est !== 'Anulado') btns.push({ l: 'Anular', cls: 'danger', fn: () => { setTimeout(() => modal('Anular ' + r.cod, '<p>La verificación pública mostrará «Anulado». El participante recibirá un aviso. Esta acción queda en la bitácora.</p>' + campo('an-m', 'Motivo (obligatorio)', 'textarea', ''), [{ l: 'Cancelar' }, { l: 'Anular certificado', cls: 'danger', fn: (ov) => { const m = $('#an-m', ov).value.trim(); if (!m) { toast('Escribe el motivo de la anulación', 'warn'); return false; } r.est = 'Anulado'; r.motivo = m; r.anula = USUARIOS[S.role][0] + ' · 30/10/2026'; toast('Certificado ' + r.cod + ' anulado'); done(); } }]), 30); } });
  if (canVal && r.est === 'En validación') btns.push({ l: 'Validar', cls: 'pri', fn: () => { r.est = 'Validado'; r.valida = USUARIOS[S.role][0]; toast('Certificado ' + r.cod + ' validado'); done(); } });
  cajon(r.cod, esc(certPersona(r).nombre) + ' · ' + esc(cursoBy(r.curso).n), '<div class="cert-mini">' + certHTML(r) + '</div>' + dl([['Estado', chip(r.est, CERT_CLS[r.est])], ['Emitido', r.emit], ['Vence', r.vence], ['Verificable', cursoBy(r.curso).verif ? 'Sí, por código y QR' : 'No (curso no verificable)']].concat(r.valida ? [['Validado por', esc(r.valida)]] : []).concat(r.est === 'Anulado' ? [['Motivo de anulación', esc(r.motivo)], ['Anulado por', esc(r.anula)]] : [])), btns);
}

/* ---------- Página pública: verificación de certificado por código ---------- */
R['pub-certificado'] = (root) => {
  let cod = window.__certCodigo || ''; window.__certCodigo = '';
  const ej = [['CERT-2026-000142', 'Válido'], ['CERT-2026-000097', 'Anulado'], ['CERT-2026-000155', 'En validación'], ['CERT-2026-999999', 'No existe']];
  const pint = (buscado) => {
    let res = '';
    if (buscado) {
      const r = certBy(cod); const c = r && cursoBy(r.curso);
      if (!r || !c.verif) res = '<div class="card vres"><div class="vh na">' + ic('search', 34) + '<h2>No encontramos ese certificado</h2></div><div class="vb"><p class="small">Revisa que el código esté completo (formato CERT-AAAA-NNNNNN) o consulta a la empresa que lo emitió.</p></div></div>';
      else if (r.est === 'En validación') res = '<div class="card vres"><div class="vh warn">' + ic('clock', 34) + '<h2>Aún no validado</h2></div><div class="vb"><p class="small">El certificado existe, pero la empresa emisora todavía no lo valida. Vuelve a consultar más tarde.</p></div></div>';
      else {
        const p = certPersona(r); const e = EMPRESAS.find((x) => x.id === r.emp);
        res = '<div class="card vres"><div class="vh ' + (r.est === 'Validado' ? 'ok' : 'bad') + '">' + ic(r.est === 'Validado' ? 'check' : 'x', 38) + '<h2>' + (r.est === 'Validado' ? 'Certificado válido' : 'Certificado anulado') + '</h2><div class="small">' + r.cod + ' · consultado el 30/10/2026 a las 09:41</div></div><div class="vb stack">' +
          (r.est === 'Anulado' ? nota('La empresa emisora anuló este certificado. Ya no tiene validez.', 'bad') : '') +
          dl([['Titular', '<b>' + esc(p.nombre) + '</b>'], ['Documento', 'DNI ' + enmascarar(p.dni)], ['Curso', esc(c.n)], ['Duración', c.horas + ' horas'], ['Nota', notaTxt(c.escala, r.r) + (escNum(c.escala) ? ' de ' + c.escala.split('-')[1] : '')], ['Emitido', r.emit], ['Vigencia', r.vence === 'Sin vencimiento' ? 'Sin vencimiento' : 'Hasta el ' + r.vence], ['Emitido por', esc(e.nombre) + '<div class="tiny muted">RUC ' + e.ruc + '</div>']]) +
          (r.est === 'Validado' ? '<details><summary class="small" style="cursor:pointer;font-weight:700">Ver el certificado</summary><div style="margin-top:10px">' + certHTML(r) + '</div></details>' : '') + '</div></div>';
      }
    }
    root.innerHTML = '<div class="publicwrap"><div class="publicnote"><span>' + ic('eye', 16) + ' Página pública simulada: verificación de un certificado por código.</span><a class="btn sm" href="#m-cap">Volver al sistema</a></div>' +
      '<div class="row" style="gap:10px">' + LOGO(40) + '<div><b style="font-family:var(--font-d)">Verificación de certificados</b><div class="small muted">EscudoSST · consulta pública</div></div></div>' +
      '<div class="card stack"><label class="field" for="vc-c"><span>Código del certificado</span><input id="vc-c" class="mono" placeholder="CERT-2026-000000" value="' + esc(cod) + '" autocomplete="off"></label><button class="btn pri lg" id="vc-go">' + ic('search', 18) + 'Verificar</button>' +
      '<div><span class="tiny muted">Códigos de ejemplo:</span><div class="row" style="gap:6px;margin-top:4px">' + ej.map((x) => '<button class="btn sm" data-ej="' + x[0] + '">' + x[1] + '</button>').join('') + '</div></div></div>' + res +
      '<p class="tiny muted">Solo se muestran certificados de cursos marcados como verificables. La consulta no muestra datos médicos ni de contacto y queda registrada.</p></div>';
    const go = () => { cod = $('#vc-c', root).value.trim().toUpperCase(); if (!/^CERT-\d{4}-\d{6}$/.test(cod)) { toast('El código debe tener el formato CERT-AAAA-NNNNNN', 'warn'); return; } pint(true); };
    $('#vc-go', root).onclick = go;
    $('#vc-c', root).onkeydown = (e) => { if (e.key === 'Enter') go(); };
    $$('[data-ej]', root).forEach((x) => x.onclick = () => { cod = x.dataset.ej; pint(true); });
  };
  pint(!!cod);
};
