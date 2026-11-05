/* IPERC (IPC), Inspecciones (INS), Actos y condiciones (ACT) + página pública, PETAR (PTR) */
const JER = ['Eliminación', 'Sustitución', 'Ingeniería', 'Administrativo', 'EPP'];
const NIVEL = (v) => v >= 17 ? ['Intolerable', 5] : v >= 9 ? ['Importante', 4] : v >= 5 ? ['Moderado', 3] : v >= 3 ? ['Tolerable', 2] : ['Trivial', 1];
const IPERC = {
  puesto: 'Soldador', version: 4, estado: 'Borrador',
  filas: [
    { pr: 'Fabricación de envases', ac: 'Soldadura de cuerpos', ta: 'Soldar costura lateral con MIG', pe: 'Humos metálicos', ri: 'Inhalación de humos (fiebre de soldador)', p: 3, s: 3, ctl: ['Ingeniería: extracción localizada', 'EPP: respirador P100'], pr2: 2, s2: 2 },
    { pr: 'Fabricación de envases', ac: 'Soldadura de cuerpos', ta: 'Soldar costura lateral con MIG', pe: 'Radiación ultravioleta', ri: 'Quemadura ocular (queratitis)', p: 3, s: 2, ctl: ['Ingeniería: biombos', 'EPP: careta fotosensible'], pr2: 1, s2: 2 },
    { pr: 'Fabricación de envases', ac: 'Preparación de piezas', ta: 'Esmerilar rebabas', pe: 'Partículas proyectadas', ri: 'Lesión ocular', p: 2, s: 3, ctl: ['Ingeniería: guarda del esmeril', 'EPP: lentes y careta'], pr2: 1, s2: 3 },
    { pr: 'Fabricación de envases', ac: 'Soldadura de cuerpos', ta: 'Manipular cilindros de gas', pe: 'Cilindro sin asegurar', ri: 'Golpe o aplastamiento por caída', p: 2, s: 4, ctl: ['Ingeniería: cadenas de sujeción', 'Administrativo: PETS de manipulación'], pr2: 1, s2: 4 },
    { pr: 'Mantenimiento de equipos', ac: 'Cambio de antorcha', ta: 'Desconectar equipo de soldar', pe: 'Energía eléctrica', ri: 'Contacto eléctrico indirecto', p: 2, s: 5, ctl: ['Administrativo: bloqueo y etiquetado', 'EPP: guantes dieléctricos'], pr2: 1, s2: 5 },
  ],
  versiones: [['v4', 'Borrador', 'Gabriela Montoya', '29/10/2026', 'Agrega manipulación de cilindros'], ['v3', 'Vigente', 'Comité SST', '18/03/2026', 'Revisión anual'], ['v2', 'Obsoleto', 'Comité SST', '22/03/2025', 'Nueva línea MIG'], ['v1', 'Obsoleto', 'Rocío Villanueva', '10/02/2024', 'Versión inicial']],
};
const nivelHtml = (p, s) => { const n = NIVEL(p * s); return '<span class="nivel lv-' + n[1] + '">' + (p * s) + ' · ' + n[0] + '</span>'; };

R.IPC = (root) => {
  let tab = 0;
  const ed = puedeEditar() && IPERC.estado === 'Borrador';
  const pint = () => {
    const pasos = ['Borrador', 'En revisión', 'Aprobado'];
    const idx = Math.max(0, pasos.indexOf(IPERC.estado));
    let body = '';
    if (tab === 0) {
      body = '<div class="row between" style="margin-bottom:10px"><div class="steps" style="margin:0">' + pasos.map((p, i) => '<span class="st ' + (i < idx || IPERC.estado === 'Aprobado' ? 'done' : i === idx ? 'cur' : '') + '"><i>' + (i < idx || IPERC.estado === 'Aprobado' ? '✓' : i + 1) + '</i>' + p + '</span>').join('') + '</div><div class="row">' +
        (S.addons.has('IAA') && ed ? '<a class="btn" href="#m-iaa">' + ic('spark', 16) + 'Sugerir filas con IA</a>' : '') +
        (ed ? '<button class="btn" id="add-fila">' + ic('plus', 16) + 'Agregar fila</button><button class="btn pri" id="enviar">Enviar a aprobación</button>' : '') +
        (IPERC.estado === 'En revisión' && (S.role === 'sst_manager' || S.role === 'committee_member' || esConsultor()) ? '<button class="btn" id="observar">Observar</button><button class="btn pri" id="aprobar">Aprobar versión ' + IPERC.version + '</button>' : '') +
        (IPERC.estado === 'Aprobado' && puedeEditar() ? '<button class="btn" id="nueva-ver">Crear versión ' + (IPERC.version + 1) + '</button>' : '') + '</div></div>' +
        (IPERC.estado === 'En revisión' ? nota('Versión ' + IPERC.version + ' enviada al Comité SST. Mientras se revisa, las filas no se pueden editar.', 'warn') + '<div style="height:10px"></div>' : '') +
        (IPERC.estado === 'Aprobado' ? nota('Versión ' + IPERC.version + ' aprobada por el Comité SST el 30/10/2026. Ya es la versión vigente y se notificó a los 10 soldadores.', 'ok', 'check') + '<div style="height:10px"></div>' : '') +
        '<div class="tw"><table class="iperc"><thead><tr><th class="grp" colspan="5">Identificación</th><th class="grp" colspan="3">Evaluación inicial</th><th class="grp">Controles por jerarquía</th><th class="grp" colspan="3">Riesgo residual</th>' + (ed ? '<th></th>' : '') + '</tr><tr><th>Proceso</th><th>Actividad</th><th>Tarea</th><th>Peligro</th><th>Riesgo</th><th>P</th><th>S</th><th>Nivel</th><th>Medidas</th><th>P</th><th>S</th><th>Nivel</th>' + (ed ? '<th></th>' : '') + '</tr></thead><tbody>' +
        IPERC.filas.map((f, i) => {
          const sel = (k, v) => ed ? '<select data-i="' + i + '" data-k="' + k + '" aria-label="' + k + '">' + [1, 2, 3, 4, 5].map((n) => '<option' + (n === v ? ' selected' : '') + '>' + n + '</option>').join('') + '</select>' : v;
          const txt = (k) => ed ? '<input class="t" data-i="' + i + '" data-k="' + k + '" value="' + esc(f[k]) + '" aria-label="' + k + '">' : esc(f[k]);
          return '<tr><td>' + txt('pr') + '</td><td>' + txt('ac') + '</td><td>' + txt('ta') + '</td><td>' + txt('pe') + '</td><td>' + txt('ri') + '</td><td>' + sel('p', f.p) + '</td><td>' + sel('s', f.s) + '</td><td>' + nivelHtml(f.p, f.s) + '</td><td style="min-width:200px">' + f.ctl.map((c) => '<span class="ctl">' + esc(c) + '</span>').join('') + (ed ? ' <button class="btn ghost sm" data-ctl="' + i + '">+ control</button>' : '') + '</td><td>' + sel('pr2', f.pr2) + '</td><td>' + sel('s2', f.s2) + '</td><td>' + nivelHtml(f.pr2, f.s2) + '</td>' + (ed ? '<td><button class="iconbtn" data-del="' + i + '" aria-label="Eliminar fila">' + ic('x', 16) + '</button></td>' : '') + '</tr>';
        }).join('') + '</tbody></table></div>' +
        '<div class="row" style="margin-top:10px"><span class="tiny muted">Criterio P × S (1–5). Niveles: </span>' + [['Trivial', 1], ['Tolerable', 2], ['Moderado', 3], ['Importante', 4], ['Intolerable', 5]].map((n) => '<span class="nivel lv-' + n[1] + '">' + n[0] + '</span>').join(' ') + '<span class="tiny muted">· Criterio configurable por empresa.</span></div>';
    } else if (tab === 1) {
      body = tabla([{ l: 'Versión', k: 0 }, { l: 'Estado', f: (r) => chip(r[1]) }, { l: 'Aprobado / editado por', k: 2 }, { l: 'Fecha', k: 3 }, { l: 'Motivo del cambio', k: 4 }], IPERC.versiones, { click: 1 });
    } else {
      body = tabla([{ l: 'Puesto', k: 0 }, { l: 'Área', k: 1 }, { l: 'Filas', k: 2 }, { l: 'Riesgos importantes', k: 3 }, { l: 'Versión vigente', k: 4 }, { l: 'Estado', f: (r) => chip(r[5]) }],
        [['Soldador', 'Producción', 5, 2, 'v3 · 18/03/2026', 'En elaboración'], ['Operador de prensa troqueladora', 'Producción', 9, 3, 'v5 · 02/09/2026', 'Vigente'], ['Operador de montacargas', 'Logística y almacén', 7, 2, 'v2 · 11/04/2026', 'Vigente'], ['Técnico electricista', 'Mantenimiento', 8, 4, 'v3 · 20/01/2026', 'Por vencer'], ['Almacenero', 'Logística y almacén', 6, 1, 'v2 · 05/05/2026', 'Vigente'], ['Asistente administrativo', 'Administración y finanzas', 4, 0, 'v1 · 15/02/2026', 'Vigente']], { click: 1 });
    }
    $('#ipc-body', root).innerHTML = body;
    $$('#ipc-body [data-k]', root).forEach((el) => el.addEventListener('change', () => { const f = IPERC.filas[+el.dataset.i]; f[el.dataset.k] = el.tagName === 'SELECT' ? +el.value : el.value; if (el.tagName === 'SELECT') pint(); }));
    $$('#ipc-body [data-del]', root).forEach((b) => b.onclick = () => modal('Eliminar fila', '<p>¿Eliminar la fila «' + esc(IPERC.filas[+b.dataset.del].pe) + '»? Quedará registrado en el historial de la versión.</p>', [{ l: 'Cancelar' }, { l: 'Eliminar', cls: 'danger', fn: () => { IPERC.filas.splice(+b.dataset.del, 1); pint(); toast('Fila eliminada'); } }]));
    $$('#ipc-body [data-ctl]', root).forEach((b) => b.onclick = () => modal('Agregar control', '<p class="small muted">Elige el nivel según la jerarquía de controles (art. 21 de la Ley 29783).</p>' + campo('ctl-j', 'Jerarquía', 'select', 'Ingeniería', JER) + campo('ctl-t', 'Medida', 'text', ''), [{ l: 'Cancelar' }, { l: 'Agregar', cls: 'pri', fn: (ov) => { const t = $('#ctl-t', ov).value.trim(); if (!t) { toast('Escribe la medida de control', 'warn'); return false; } IPERC.filas[+b.dataset.ctl].ctl.push($('#ctl-j', ov).value + ': ' + t); pint(); toast('Control agregado'); } }]));
    const af = $('#add-fila', root); if (af) af.onclick = () => { IPERC.filas.push({ pr: 'Fabricación de envases', ac: '', ta: '', pe: '', ri: '', p: 1, s: 1, ctl: [], pr2: 1, s2: 1 }); pint(); toast('Fila agregada al final'); };
    const en = $('#enviar', root); if (en) en.onclick = () => modal('Enviar a aprobación', '<p>La versión ' + IPERC.version + ' del IPERC de <b>Soldador</b> pasará a revisión del Comité SST. Mientras tanto, la versión ' + (IPERC.version - 1) + ' sigue vigente.</p>' + campo('ipc-com', 'Comentario para el Comité', 'textarea', 'Se agrega la manipulación de cilindros de gas por observación de la inspección INS-2026-221.'), [{ l: 'Cancelar' }, { l: 'Enviar', cls: 'pri', fn: () => { IPERC.estado = 'En revisión'; IPERC.versiones[0][1] = 'En revisión'; R.IPC(root); toast('IPERC enviado a aprobación del Comité SST'); } }]);
    const ap = $('#aprobar', root); if (ap) ap.onclick = () => modal('Aprobar IPERC', '<p>Al aprobar, la versión ' + IPERC.version + ' será la vigente y la versión ' + (IPERC.version - 1) + ' pasará a obsoleta. Se pedirá a los trabajadores del puesto que firmen su difusión.</p>', [{ l: 'Cancelar' }, { l: 'Aprobar con mi firma', cls: 'pri', fn: () => { IPERC.estado = 'Aprobado'; IPERC.versiones[0][1] = 'Vigente'; IPERC.versiones[0][2] = 'Comité SST'; IPERC.versiones[1][1] = 'Obsoleto'; R.IPC(root); toast('IPERC versión ' + IPERC.version + ' aprobado'); } }]);
    const ob = $('#observar', root); if (ob) ob.onclick = () => modal('Observar IPERC', campo('ob-t', 'Observación', 'textarea', ''), [{ l: 'Cancelar' }, { l: 'Devolver con observaciones', cls: 'pri', fn: () => { IPERC.estado = 'Borrador'; IPERC.versiones[0][1] = 'Borrador'; R.IPC(root); toast('IPERC devuelto con observaciones'); } }]);
    const nv = $('#nueva-ver', root); if (nv) nv.onclick = () => { IPERC.version++; IPERC.estado = 'Borrador'; IPERC.versiones.unshift(['v' + IPERC.version, 'Borrador', USUARIOS[S.role][0], '30/10/2026', 'Nueva revisión']); R.IPC(root); toast('Versión ' + IPERC.version + ' creada como borrador'); };
    if (tab === 1) bindTabla(root, IPERC.versiones, (v) => cajon('IPERC Soldador ' + v[0], chip(v[1]), dl([['Estado', chip(v[1])], ['Responsable', esc(v[2])], ['Fecha', v[3]], ['Motivo', esc(v[4])]]) + nota('Las versiones aprobadas no se pueden modificar. Para cambiar algo se crea una versión nueva.'), [{ l: 'Comparar con la actual', fn: () => toast('Comparación de versiones (simulado)') }, { l: 'Descargar PDF', cls: 'pri', fn: () => toast('PDF generado (simulado)') }]));
    if (tab === 2) bindTabla(root, [0, 1, 2, 3, 4, 5], () => { tab = 0; R.IPC(root); });
  };
  root.innerHTML = encMod('IPC', 'Matriz de identificación de peligros, evaluación de riesgos y controles por puesto y tarea.', btnExport()) +
    '<div class="card" style="margin-bottom:14px"><div class="row" style="gap:18px">' + dl([['Puesto', '<b>Soldador</b> · Producción · Planta Ate'], ['Trabajadores expuestos', '10']]) + dl([['Versión en edición', 'v' + IPERC.version + ' ' + chip(IPERC.estado)], ['Versión vigente', 'v' + (IPERC.estado === 'Aprobado' ? IPERC.version : IPERC.version - 1) + ' · aprobada por el Comité SST']]) + '</div></div>' +
    tabs('ipc', ['Matriz del puesto', 'Historial de versiones', 'Todas las matrices'], tab) + '<div id="ipc-body"></div>';
  bindTabs(root, 'ipc', (i) => { tab = i; $$('[data-tabs="ipc"] button', root).forEach((b, j) => b.classList.toggle('on', i === j)); pint(); });
  bindExport(root);
  pint();
};

/* ---------- Inspecciones ---------- */
const PLANTILLAS = {
  'Orden y limpieza – Planta': ['Pasillos y vías de evacuación libres de obstáculos', 'Materiales apilados de forma estable y a altura permitida', 'Residuos segregados en contenedores rotulados', 'Pisos sin derrames de aceite ni barniz', 'Herramientas guardadas después de su uso'],
  'Extintores y equipos de emergencia': ['Extintor con carga vigente y precinto', 'Señalización visible y a la altura reglamentaria', 'Acceso libre al extintor', 'Botiquín con insumos completos', 'Luces de emergencia operativas'],
  'Montacargas (pre-uso)': ['Alarma de retroceso y circulina operativas', 'Frenos y freno de estacionamiento', 'Horquillas sin fisuras', 'Cinturón de seguridad en buen estado', 'Operador con licencia interna vigente'],
};
R.INS = (root) => {
  const off = S.addons.has('OFF');
  let vista = 0;
  const lista = [
    ['INS-2026-224', 'Orden y limpieza – Planta', 'Planta Ate · Prensas', 'Gabriela Montoya', '30/10/2026', 'Cerrada', 2],
    ['INS-2026-223', 'Montacargas (pre-uso)', 'Almacén Callao', 'Wilmer Ccahuana', '29/10/2026', 'Cerrada', 0],
    ['INS-2026-222', 'Extintores y equipos de emergencia', 'Oficina San Isidro', 'Patricia Salazar', '28/10/2026', 'En proceso', 1],
    ['INS-2026-221', 'Extintores y equipos de emergencia', 'Planta Ate · Pasillo B', 'Gabriela Montoya', '24/10/2026', 'Cerrada', 3],
    ['INS-2026-220', 'Orden y limpieza – Planta', 'Almacén Callao · Despacho', 'Hugo Calderón', '22/10/2026', 'Programada', 0],
  ];
  const pint = () => {
    if (vista === 0) {
      $('#ins-body', root).innerHTML = '<div class="grid g4" style="margin-bottom:14px">' + kpi('Programadas en octubre', '18') + kpi('Ejecutadas', '15', '83% del programa') + kpi('Hallazgos abiertos', '6') + kpi('Plantillas activas', '12') + '</div>' +
        tabla([{ l: 'Código', k: 0 }, { l: 'Plantilla', k: 1 }, { l: 'Lugar', k: 2 }, { l: 'Inspector', k: 3 }, { l: 'Fecha', k: 4 }, { l: 'Hallazgos', k: 6 }, { l: 'Estado', f: (r) => chip(r[5]) }], lista, { click: 1 });
      bindTabla(root, lista, (r) => cajon(r[0], esc(r[1]), dl([['Lugar', esc(r[2])], ['Inspector', esc(r[3])], ['Fecha', r[4]], ['Ubicación GPS', '-12.0431, -76.9527 (precisión 8 m)'], ['Estado', chip(r[5])], ['Hallazgos', r[6] + ' → acciones correctivas']]) + '<div class="row"><div class="photo">' + fotoSim(1) + '</div><div class="photo">' + fotoSim(3) + '</div></div>', [{ l: 'Descargar PDF', fn: () => toast('PDF generado (simulado)') }, { l: 'Cerrar', cls: 'pri' }]));
    } else {
      ejecutarInspeccion($('#ins-body', root), off);
    }
  };
  root.innerHTML = encMod('INS', 'Checklists con fotos y ubicación; cada «no cumple» puede generar una acción correctiva.', btnExport() + (puedeEditar() ? '<button class="btn pri" id="ins-nueva">' + ic('plus', 16) + 'Nueva inspección</button>' : '')) +
    tabs('ins', ['Inspecciones', 'Ejecutar inspección (vista de campo)'], 0) + '<div id="ins-body"></div>';
  const sw = (i) => { vista = i; $$('[data-tabs="ins"] button', root).forEach((b, j) => b.classList.toggle('on', i === j)); pint(); };
  bindTabs(root, 'ins', sw);
  const nb = $('#ins-nueva', root); if (nb) nb.onclick = () => sw(1);
  bindExport(root);
  pint();
};
function ejecutarInspeccion(host, off) {
  let pl = Object.keys(PLANTILLAS)[0];
  const resp = {}; const hallazgos = {};
  const dibujar = () => {
    const items = PLANTILLAS[pl];
    const hechas = Object.keys(resp).length;
    const nc = Object.values(resp).filter((v) => v === 'n').length;
    host.innerHTML = '<div class="grid g-main"><div class="phone" style="width:390px"><div class="ps"><span>9:41</span><span>' + (off ? 'Sin señal' : '4G') + '</span></div>' +
      (off ? '<div class="offline">' + ic('wifi', 16) + 'Sin conexión — 3 registros por sincronizar</div>' : '') +
      '<div class="pc"><div class="pbody">' +
      '<label class="field" for="ins-pl"><span>Plantilla</span><select id="ins-pl">' + Object.keys(PLANTILLAS).map((k) => '<option' + (k === pl ? ' selected' : '') + '>' + esc(k) + '</option>').join('') + '</select></label>' +
      '<div class="row small muted">' + ic('field', 14) + 'Planta Ate · Línea de prensas · -12.0431, -76.9527</div>' +
      '<div><div class="row between small"><span>' + hechas + ' de ' + items.length + ' respondidas</span><span>' + nc + ' no cumple</span></div>' + barra(Math.round(hechas / items.length * 100), 'ok') + '</div>' +
      '<div>' + items.map((it, i) => '<div class="chk-item" style="grid-template-columns:1fr"><div class="small"><b>' + (i + 1) + '.</b> ' + esc(it) + '</div><div class="row"><div class="tri" role="group" aria-label="Respuesta">' + [['c', 'Cumple'], ['n', 'No cumple'], ['a', 'No aplica']].map(([k, l]) => '<button class="' + k + (resp[i] === k ? ' on' : '') + '" data-q="' + i + '" data-v="' + k + '">' + l + '</button>').join('') + '</div><button class="iconbtn" data-foto="' + i + '" aria-label="Tomar foto">' + ic('camera') + '</button></div>' +
        (hallazgos[i] && hallazgos[i].foto ? '<div class="row"><div class="photo">' + fotoSim(i) + '</div><span class="tiny muted">Foto adjunta · 09:4' + i + '</span></div>' : '') +
        (resp[i] === 'n' ? '<div class="note warn" style="flex-direction:column"><b class="small">Hallazgo</b><input class="input" data-h="' + i + '" placeholder="Describe lo observado" value="' + esc((hallazgos[i] || {}).t || '') + '"><label class="small row" for="gac-' + i + '"><input type="checkbox" id="gac-' + i + '" data-ac="' + i + '"' + ((hallazgos[i] || {}).ac !== false ? ' checked' : '') + '> Generar acción correctiva</label></div>' : '') + '</div>').join('') + '</div>' +
      '<button class="btn pri lg" id="ins-fin"' + (hechas < items.length ? ' disabled' : '') + '>Finalizar y firmar</button>' +
      '</div></div></div>' +
      '<div class="stack"><div class="card"><h3>Cómo funciona en campo</h3><p class="small muted" style="margin-top:6px">El inspector responde desde su celular. Cada «No cumple» pide describir el hallazgo y, si se marca, crea una acción correctiva con responsable y plazo.</p></div>' +
      (off ? '<div class="card"><h3>Modo sin conexión</h3><p class="small muted" style="margin-top:6px">El complemento <b>App móvil offline</b> está activo: la inspección se guarda en el teléfono y se sincroniza al recuperar la señal. Los conflictos se registran, no se pisan.</p><button class="btn" id="sync" style="margin-top:10px">' + ic('upload', 16) + 'Simular sincronización</button></div>' : '<div class="card"><h3>¿Sin señal en planta?</h3><p class="small muted" style="margin-top:6px">Con el complemento «App móvil offline» se puede inspeccionar sin conexión. Actívalo en «Complementos» para verlo.</p></div>') + '</div></div>';
    $('#ins-pl', host).onchange = (e) => { pl = e.target.value; Object.keys(resp).forEach((k) => delete resp[k]); Object.keys(hallazgos).forEach((k) => delete hallazgos[k]); dibujar(); };
    $$('[data-q]', host).forEach((b) => b.onclick = () => { resp[b.dataset.q] = b.dataset.v; dibujar(); });
    $$('[data-foto]', host).forEach((b) => b.onclick = () => { const i = b.dataset.foto; hallazgos[i] = Object.assign(hallazgos[i] || {}, { foto: 1 }); toast('Foto tomada y adjuntada'); dibujar(); });
    $$('[data-h]', host).forEach((inp) => inp.oninput = () => { hallazgos[inp.dataset.h] = Object.assign(hallazgos[inp.dataset.h] || {}, { t: inp.value }); });
    $$('[data-ac]', host).forEach((c) => c.onchange = () => { hallazgos[c.dataset.ac] = Object.assign(hallazgos[c.dataset.ac] || {}, { ac: c.checked }); });
    const s = $('#sync', host); if (s) s.onclick = () => { s.disabled = true; s.textContent = 'Sincronizando…'; setTimeout(() => { toast('3 registros sincronizados · 0 conflictos'); s.textContent = 'Sincronizado'; const o = $('.offline', host); if (o) { o.innerHTML = ic('check', 16) + 'Conectado · todo sincronizado'; o.style.background = 'var(--ok)'; o.style.color = '#fff'; } }, 900); };
    $('#ins-fin', host).onclick = () => {
      const n = Object.entries(resp).filter(([, v]) => v === 'n').map(([k]) => +k);
      const acs = n.filter((i) => (hallazgos[i] || {}).ac !== false);
      acs.forEach((i, j) => ACCIONES.unshift({ id: 'AC-2026-' + (119 + j), t: (hallazgos[i] || {}).t || PLANTILLAS[pl][i], o: 'Inspección INS-2026-225', r: 'Jorge Quispe', v: masDias(7), e: 'Abierta', p: 0 }));
      modal('Inspección finalizada', '<p>Se registró la inspección <b>INS-2026-225</b> con ' + n.length + ' hallazgo(s).</p>' + (acs.length ? nota('Se crearon ' + acs.length + ' acción(es) correctiva(s) asignadas a Jorge Quispe con plazo de 7 días.', 'ok', 'check') : '') + (off ? nota('Guardada en el teléfono. Se enviará al recuperar la señal.', 'warn', 'wifi') : ''), [{ l: 'Ver acciones correctivas', fn: () => { location.hash = 'm-acr'; } }, { l: 'Listo', cls: 'pri' }]);
      Object.keys(resp).forEach((k) => delete resp[k]); dibujar();
    };
  };
  dibujar();
}

/* ---------- Actos y condiciones subestándar ---------- */
const REPORTES = [
  ['R-0412', 'QR', 'Condición', 'Derrame de aceite en zona de despacho', 'Almacén Callao – Zona de despacho', 'Anónimo', '29/10/2026 18:42', 'Abierta'],
  ['R-0411', 'WhatsApp', 'Acto', 'Operario sin lentes en esmeril', 'Planta Ate – Soldadura', 'Héctor Llanos', '29/10/2026 11:05', 'En proceso'],
  ['R-0410', 'App', 'Condición', 'Luminaria apagada en pasillo C', 'Planta Ate – Pasillo C', 'Juana Apaza', '28/10/2026 07:30', 'Cerrada'],
  ['R-0409', 'Web', 'Condición', 'Tomacorriente sin tapa en sala de reuniones', 'Oficina San Isidro – Piso 7', 'Lucía Fernández', '27/10/2026 16:12', 'Cerrada'],
  ['R-0408', 'QR', 'Acto', 'Montacargas circulando a alta velocidad', 'Almacén Callao – Pasillo 2', 'Anónimo', '26/10/2026 14:50', 'En proceso'],
  ['R-0407', 'WhatsApp', 'Condición', 'Escalera portátil con peldaño roto', 'Planta Ate – Mantenimiento', 'Wálter Inga', '25/10/2026 09:15', 'Abierta'],
];
R.ACT = (root) => {
  let t = 0;
  const pint = () => {
    const b = $('#act-body', root);
    if (t === 0) {
      pantallaLista(b, REPORTES, [{ l: 'Código', k: 0 }, { l: 'Origen', f: (r) => '<span class="chip info nodot">' + ic(r[1] === 'QR' ? 'qr' : r[1] === 'WhatsApp' ? 'chat' : r[1] === 'App' ? 'phone' : 'grid', 13) + ' ' + r[1] + '</span>' }, { l: 'Tipo', k: 2 }, { l: 'Descripción', k: 3 }, { l: 'Punto', k: 4 }, { l: 'Reportado por', k: 5 }, { l: 'Fecha', k: 6 }, { l: 'Estado', f: (r) => chip(r[7]) }], [[1, 'Origen'], [2, 'Tipo'], [7, 'Estado']], (r) => cajon(r[0] + ' · ' + r[2], esc(r[4]), '<div class="photo" style="width:100%;height:180px">' + fotoSim(+r[0].slice(-1)) + '</div>' + dl([['Descripción', esc(r[3])], ['Origen', r[1]], ['Reportado por', esc(r[5])], ['Fecha', r[6]], ['Estado', chip(r[7])]]) + campo('act-asig', 'Asignar a', 'select', 'Wilmer Ccahuana', ['Wilmer Ccahuana', 'Gabriela Montoya', 'Jorge Quispe']), puedeEditar() ? [{ l: 'Descartar', fn: () => toast('Reporte descartado') }, { l: 'Crear acción correctiva', cls: 'pri', fn: () => { r[7] = 'En proceso'; toast('Acción correctiva creada y asignada'); pint(); } }] : [{ l: 'Cerrar' }]));
    } else if (t === 1) {
      b.innerHTML = '<div class="grid g3">' + [['Almacén Callao – Zona de despacho', 14], ['Planta Ate – Línea de prensas', 9], ['Planta Ate – Comedor', 3], ['Oficina San Isidro – Piso 7', 1]].map((p) => '<div class="card stack" style="align-items:center;text-align:center"><div style="width:140px">' + (QR.reporte || '') + '</div><b>' + esc(p[0]) + '</b><span class="small muted">' + p[1] + ' reportes este año</span><div class="row"><button class="btn sm" data-print>Imprimir cartel</button><a class="btn sm pri" href="#pub-reporte">Probar el QR</a></div></div>').join('') + '</div>';
      $$('[data-print]', b).forEach((x) => x.onclick = () => toast('Cartel A4 con QR generado en PDF (simulado)'));
    } else {
      b.innerHTML = '<div class="grid g2"><div><div class="wahead">' + ic('chat') + '<div><b>EscudoSST · Industrias Andinas</b><div class="tiny">Cuenta de empresa verificada (simulado)</div></div></div><div class="wa" id="wa"></div><div class="row" style="margin-top:8px"><input class="input" id="wa-in" placeholder="Escribe un mensaje" style="flex:1"><button class="btn pri" id="wa-send">Enviar</button></div></div>' +
        '<div class="card"><h3>Reporte por WhatsApp</h3><p class="small muted" style="margin-top:6px">El trabajador escribe al número de la empresa. Un asistente le pide el lugar, una foto y la descripción, y crea el reporte en la bandeja. Proveedor de WhatsApp por definir.</p><button class="btn" id="wa-reset" style="margin-top:12px">Reiniciar conversación</button></div></div>';
      chatWA(b);
    }
  };
  root.innerHTML = encMod('ACT', 'Cualquier persona reporta por QR, WhatsApp, la app o la web, sin instalar nada.', '<a class="btn" href="#pub-reporte">' + ic('qr', 16) + 'Ver página pública del QR</a>' + btnExport()) +
    '<div class="grid g4" style="margin-bottom:14px">' + kpi('Reportes en octubre', '27', '+8 frente a septiembre') + kpi('Por QR y WhatsApp', '63%', 'Sin necesidad de app') + kpi('Abiertos', '9') + kpi('Tiempo medio de atención', '2,4 días') + '</div>' +
    tabs('act', ['Bandeja de reportes', 'Puntos QR', 'Simulación de WhatsApp'], 0) + '<div id="act-body"></div>';
  bindTabs(root, 'act', (i) => { t = i; $$('[data-tabs="act"] button', root).forEach((x, j) => x.classList.toggle('on', i === j)); pint(); });
  bindExport(root);
  pint();
};
function chatWA(b) {
  const guion = [
    ['in', 'Hola 👷 Soy el asistente SST de Industrias Andinas. ¿Qué quieres reportar?\n1. Acto inseguro\n2. Condición insegura'],
    ['out', '2'], ['in', '¿Dónde ocurre? Envía la sede y la zona.'], ['out', 'Almacén Callao, zona de despacho'],
    ['in', 'Gracias. Envía una foto si puedes (opcional).'], ['out', '📷 Foto'], ['in', 'Describe brevemente lo que viste.'], ['out', 'Hay aceite derramado junto a la rampa 2, alguien se puede resbalar'],
    ['in', '✅ Reporte R-0413 registrado. El área SST ya fue notificada. Gracias por cuidarnos.'],
  ];
  let paso = 0;
  const wa = $('#wa', b);
  const pintarMsg = () => { wa.innerHTML = guion.slice(0, paso + 1).map((m) => '<div class="b ' + m[0] + '">' + esc(m[1]) + '<small>18:4' + Math.min(9, paso) + '</small></div>').join(''); };
  const avanzar = () => { if (paso + 2 < guion.length) { paso += 2; pintarMsg(); if (paso === guion.length - 1) toast('Reporte R-0413 creado desde WhatsApp'); } };
  pintarMsg();
  const inp = $('#wa-in', b);
  const send = () => { avanzar(); inp.value = paso + 1 < guion.length ? guion[paso + 1][1] : ''; };
  inp.value = guion[1][1];
  $('#wa-send', b).onclick = send;
  inp.onkeydown = (e) => { if (e.key === 'Enter') send(); };
  $('#wa-reset', b).onclick = () => { paso = 0; pintarMsg(); inp.value = guion[1][1]; };
}
function pantallaLista(host, rows, cols, filtros, onRow) {
  let q = ''; const fs = {};
  const pint = () => {
    let r = rows.filter((x) => !q || x.join(' ').toLowerCase().includes(q.toLowerCase()));
    filtros.forEach(([k]) => { if (fs[k]) r = r.filter((x) => String(x[k]) === fs[k]); });
    host.innerHTML = '<div class="filters"><input class="input" type="search" id="pl-q" placeholder="Buscar" value="' + esc(q) + '" aria-label="Buscar">' + filtros.map(([k, l]) => '<select class="input" data-pf="' + k + '" aria-label="' + l + '"><option value="">' + l + ': todos</option>' + [...new Set(rows.map((x) => String(x[k])))].map((o) => '<option' + (fs[k] === o ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>').join('') + '<span class="small muted" style="margin-left:auto">' + r.length + ' de ' + rows.length + '</span></div>' + tabla(cols, r, { click: !!onRow });
    const qi = $('#pl-q', host); qi.oninput = () => { q = qi.value; pint(); const n = $('#pl-q', host); n.focus(); n.setSelectionRange(q.length, q.length); };
    $$('[data-pf]', host).forEach((s) => s.onchange = () => { fs[s.dataset.pf] = s.value; pint(); });
    if (onRow) bindTabla(host, r, onRow);
  };
  pint();
}

R['pub-reporte'] = (root) => {
  let anon = true; let enviado = false; let tipo = 'Condición insegura'; let foto = false;
  const pint = () => {
    root.innerHTML = '<div class="publicwrap">' +
      '<div class="publicnote"><span>' + ic('eye', 16) + ' Página pública simulada: así se ve al escanear el QR, sin iniciar sesión.</span><a class="btn sm" href="#m-act">Volver al sistema</a></div>' +
      '<div class="row" style="gap:10px"><span>' + LOGO(40) + '</span><div><b style="font-family:var(--font-d)">Industrias Andinas del Sur</b><div class="small muted">Reporte de seguridad · con tecnología EscudoSST</div></div></div>' +
      (enviado ? '<div class="card stack" style="align-items:center;text-align:center;padding:28px"><span style="color:var(--ok)">' + ic('check', 52) + '</span><h2>Gracias, tu reporte fue enviado</h2><p class="muted">Código <b>R-0413</b>. El área SST lo revisará hoy.' + (anon ? ' Lo enviaste de forma anónima: nadie sabrá quién lo hizo.' : '') + '</p><button class="btn" id="otro">Enviar otro reporte</button></div>'
        : '<div class="card stack"><div class="note">' + ic('field') + '<div><b>Almacén Callao – Zona de despacho</b><div class="small muted">Punto de reporte QR-CAL-03</div></div></div>' +
        '<div class="field"><span>¿Qué quieres reportar?</span>' + seg('tipo', ['Condición insegura', 'Acto inseguro', 'Incidente'], ['Condición insegura', 'Acto inseguro', 'Incidente'].indexOf(tipo)) + '</div>' +
        '<div class="field"><span>Foto (opcional)</span>' + (foto ? '<div class="row"><div class="photo" style="width:140px;height:100px">' + fotoSim(1) + '</div><button class="btn sm" id="quitar">Quitar</button></div>' : '<button class="btn lg" id="foto" style="justify-content:flex-start">' + ic('camera') + 'Tomar o subir foto</button>') + '</div>' +
        campo('pub-desc', '¿Qué viste?', 'textarea', 'Aceite derramado junto a la rampa 2, cerca del montacargas.') +
        '<label class="row small" for="pub-anon"><input type="checkbox" id="pub-anon"' + (anon ? ' checked' : '') + '> Enviar de forma anónima</label>' +
        (anon ? '' : '<div class="grid g2">' + campo('pub-nom', 'Tu nombre', 'text', '') + campo('pub-dni', 'DNI', 'text', '') + '</div>') +
        '<button class="btn pri lg" id="enviar-pub">Enviar reporte</button><p class="tiny muted">No pedimos datos médicos. Tus datos se tratan según la Ley 29733. Protección contra envíos repetidos activa.</p></div>') +
      '</div>';
    if (enviado) { $('#otro', root).onclick = () => { enviado = false; foto = false; pint(); }; return; }
    bindSeg(root, 'tipo', (i) => { tipo = ['Condición insegura', 'Acto inseguro', 'Incidente'][i]; pint(); });
    const f = $('#foto', root); if (f) f.onclick = () => { foto = true; pint(); };
    const qx = $('#quitar', root); if (qx) qx.onclick = () => { foto = false; pint(); };
    $('#pub-anon', root).onchange = (e) => { anon = e.target.checked; pint(); };
    $('#enviar-pub', root).onclick = () => { if (!$('#pub-desc', root).value.trim()) { toast('Describe lo que viste para poder enviar', 'warn'); return; } enviado = true; pint(); };
  };
  pint();
};

/* ---------- PETAR ---------- */
const PERMISOS = [
  { id: 'PT-2026-087', tipo: 'Trabajo en altura', tarea: 'Cambio de luminarias en nave de prensas (6 m)', lugar: 'Planta Ate – Nave 1', sol: 'Wálter Inga', ej: [38, 5], fecha: '30/10/2026 08:00–13:00', estado: 'Pendiente', aprob: 1 },
  { id: 'PT-2026-086', tipo: 'Trabajo en caliente', tarea: 'Soldadura de soporte de tolva', lugar: 'Planta Ate – Mantenimiento', sol: 'Roberto Gamarra', ej: [16, 40], fecha: '29/10/2026 14:00–18:00', estado: 'Vigente', aprob: 3 },
  { id: 'PT-2026-085', tipo: 'Espacio confinado', tarea: 'Limpieza de tanque de barniz', lugar: 'Planta Ate – Barnizado', sol: 'Jorge Quispe', ej: [23, 11], fecha: '28/10/2026', estado: 'Cerrado', aprob: 3 },
  { id: 'PT-2026-084', tipo: 'Trabajo eléctrico', tarea: 'Mantenimiento de tablero general', lugar: 'Almacén Callao', sol: 'Edwin Chávez', ej: [5, 22], fecha: '27/10/2026', estado: 'Suspendido', aprob: 3 },
];
R.PTR = (root) => {
  root.innerHTML = encMod('PTR', 'Permiso escrito de trabajo de alto riesgo: altura, caliente, espacio confinado y eléctrico.', btnExport() + btnNuevo('Solicitar PETAR', 'ptr-n')) +
    '<div class="grid g4" style="margin-bottom:14px">' + kpi('Vigentes ahora', '1') + kpi('Por aprobar', '1') + kpi('Suspendidos este mes', '1') + kpi('Cerrados este mes', '14') + '</div><div id="ptr-l"></div>';
  const l = $('#ptr-l', root);
  l.innerHTML = tabla([{ l: 'Código', k: 'id' }, { l: 'Tipo', k: 'tipo' }, { l: 'Tarea', k: 'tarea' }, { l: 'Lugar', k: 'lugar' }, { l: 'Horario', k: 'fecha' }, { l: 'Estado', f: (p) => chip(p.estado) }], PERMISOS, { click: 1 });
  bindTabla(l, PERMISOS, (p) => fichaPetar(p, root));
  const n = $('#ptr-n', root); if (n) n.onclick = () => formModal('Solicitar PETAR', [['Tipo de trabajo', 'select', 'Trabajo en altura', ['Trabajo en altura', 'Trabajo en caliente', 'Espacio confinado', 'Trabajo eléctrico']], ['Lugar', 'text', 'Planta Ate – Nave 2'], ['Tarea', 'text', ''], ['Fecha', 'date', '2026-10-31'], ['Ejecutante 1', 'select', 'Wálter Inga Chuquimango', W.map((w) => w.nombre)], ['Ejecutante 2', 'select', 'Edwin Chávez Ramos', W.map((w) => w.nombre)]], 'Enviar solicitud', 'PETAR enviado al supervisor para su aprobación');
  bindExport(root);
};
function fichaPetar(p, root) {
  const cadena = ['Supervisor del área', 'Supervisor SST', 'Jefe de planta'];
  const nombres = ['Jorge Quispe', 'Gabriela Montoya', 'Rocío Villanueva'];
  const req = (w) => [['Inducción', w.induccion === 'ok' ? 'ok' : 'bad'], ['EMO apto vigente', w.emoDias > 0 && /^Apto/.test(w.aptitud) ? 'ok' : 'bad'], ['SCTR vigente', w.sctrDias > 0 ? 'ok' : 'bad'], ['Capacitación ' + p.tipo.toLowerCase(), w.cap === 'bad' ? 'bad' : 'ok'], ['EPP del puesto', w.epp === 'bad' ? 'bad' : w.epp]];
  const html = () => dl([['Tipo', esc(p.tipo)], ['Tarea', esc(p.tarea)], ['Lugar', esc(p.lugar)], ['Horario', p.fecha], ['Solicitado por', esc(p.sol)], ['Estado', chip(p.estado)]]) +
    '<div><h4 style="margin-bottom:6px">Requisitos de los ejecutantes</h4>' + p.ej.map((id) => { const w = wById(id); return '<div class="card flat" style="margin-bottom:8px;padding:10px"><b class="small">' + esc(w.nombre) + '</b> <span class="tiny muted">' + esc(w.puesto) + '</span><div class="row" style="margin-top:6px;gap:6px">' + req(w).map(([n, s]) => chip(n, s)).join('') + '</div></div>'; }).join('') + '</div>' +
    '<div><h4 style="margin-bottom:6px">Verificaciones previas</h4>' + ['Área señalizada y delimitada', 'Arnés y línea de vida inspeccionados', 'Punto de anclaje certificado', 'Condiciones climáticas adecuadas'].map((x, i) => '<label class="row small" style="padding:3px 0" for="pv-' + i + '"><input type="checkbox" id="pv-' + i + '" checked> ' + x + '</label>').join('') + '</div>' +
    '<div><h4 style="margin-bottom:4px">Aprobaciones en cadena</h4><div class="chain">' + cadena.map((c, i) => { const done = i < p.aprob; const cur = i === p.aprob && p.estado === 'Pendiente'; return '<div class="cl ' + (done ? 'done' : cur ? 'cur' : '') + '"><i>' + (done ? '✓' : i + 1) + '</i><div><b class="small">' + c + '</b><div class="tiny muted">' + nombres[i] + (done ? ' · firmó 30/10 07:' + (40 + i * 5) : cur ? ' · esperando su firma' : '') + '</div></div>' + (done ? chip('Aprobado') : cur ? chip('Pendiente') : '') + '</div>'; }).join('') + '</div></div>';
  const btns = [];
  if (puedeEditar()) {
    if (p.estado === 'Pendiente') btns.push({ l: 'Rechazar', cls: 'danger', fn: () => { p.estado = 'Rechazado'; toast('PETAR rechazado'); R.PTR(root); } }, { l: 'Aprobar (' + cadena[p.aprob] + ')', cls: 'pri', fn: () => { p.aprob++; if (p.aprob >= 3) p.estado = 'Vigente'; toast(p.aprob >= 3 ? 'PETAR aprobado y vigente' : 'Aprobación registrada; pasa a ' + cadena[p.aprob]); R.PTR(root); fichaPetar(p, root); } });
    if (p.estado === 'Vigente') btns.push({ l: 'Suspender', cls: 'danger', fn: () => { modal('Suspender PETAR', campo('sus-m', 'Motivo de la suspensión', 'textarea', 'Lluvia intensa: superficie resbaladiza.'), [{ l: 'Cancelar' }, { l: 'Suspender trabajo', cls: 'pri', fn: () => { p.estado = 'Suspendido'; toast('PETAR suspendido: se avisó a los ejecutantes'); R.PTR(root); } }]); return false; } }, { l: 'Cerrar permiso', cls: 'pri', fn: () => { p.estado = 'Cerrado'; toast('PETAR cerrado: área entregada en orden'); R.PTR(root); } });
    if (p.estado === 'Suspendido') btns.push({ l: 'Reanudar', cls: 'pri', fn: () => { p.estado = 'Vigente'; toast('PETAR reanudado'); R.PTR(root); } });
  }
  btns.push({ l: 'Cerrar' });
  cajon(p.id, esc(p.tipo), html(), btns);
}
