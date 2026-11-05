/* SUN (+ vista inspector), CON, app del trabajador, IAA, PSE, OFF, SUS (panel del dueño), registro autoservicio */
const BRECHAS = [
  ['Comité SST', 92, 'Acta de instalación y libro de actas al día', 'ok', 'm-com'],
  ['IPERC', 74, '2 puestos con IPERC por vencer (Técnico electricista, Soldador)', 'warn', 'm-ipc'],
  ['Registros obligatorios', 81, 'Registro de monitoreo sin datos de septiembre', 'warn', 'm-reg'],
  ['Capacitación', 68, '4 capacitaciones obligatorias por debajo del 80% de asistencia', 'warn', 'm-cap'],
  ['EMO', 58, '4 EMO vencidos y 1 trabajador sin EMO preocupacional', 'bad', 'm-emo'],
  ['Política y RISST', 100, 'Vigentes y difundidos con cargo', 'ok', 'm-ris'],
  ['EPP', 77, '2 entregas sin firma del trabajador', 'warn', 'm-epp'],
];
R.SUN = (root) => {
  let t = 0;
  const pint = () => {
    const b = $('#sun-body', root);
    if (t === 0) {
      const punt = Math.round(BRECHAS.reduce((a, c) => a + c[1], 0) / BRECHAS.length);
      b.innerHTML = '<div class="grid g-main"><div class="card"><div class="ch"><h3>Autodiagnóstico frente a una inspección</h3><button class="btn sm" id="re">Volver a evaluar</button></div><div class="row" style="gap:20px;margin-bottom:12px">' + anillo(punt, punt >= 80 ? 'var(--ok)' : 'var(--amber)', 'Puntaje') + '<div style="flex:1;min-width:200px"><p><b>Puntaje ' + punt + ' de 100.</b> Si SUNAFIL llegara hoy, estas son las brechas a cerrar primero.</p><p class="small muted" style="margin-top:4px">Evaluado el 30/10/2026 09:30 con datos del sistema. Criterios ' + param('a confirmar por especialista SST/legal') + '.</p></div></div>' +
        '<div class="list">' + BRECHAS.map((x) => '<a class="li" href="#' + x[4] + '" style="color:inherit;text-decoration:none">' + sem(x[3]) + '<div class="lm"><b>' + x[0] + '</b><span>' + x[2] + '</span></div><div style="width:110px">' + barra(x[1]) + '</div><b class="num" style="width:38px;text-align:right">' + x[1] + '</b></a>').join('') + '</div></div>' +
        '<div class="stack"><div class="card"><h3>¿Llegó un inspector?</h3><p class="small muted" style="margin:6px 0 12px">Arma el expediente que te pidan en minutos, por trabajador, periodo u obligación.</p><button class="btn warn lg" id="go-exp" style="width:100%">Generar expediente</button></div><div class="card"><h3>Expedientes recientes</h3><div class="list">' + [['Expediente EXP-2026-004', 'Por obligación: capacitación · 2026'], ['Expediente EXP-2026-003', 'Por trabajador: Elmer Puma Sullca']].map((x) => '<div class="li">' + ic('file') + '<div class="lm"><b>' + x[0] + '</b><span>' + x[1] + '</span></div></div>').join('') + '</div></div></div></div>';
      $('#re', b).onclick = () => toast('Autodiagnóstico actualizado con datos de hoy');
      $('#go-exp', b).onclick = () => { t = 1; sync(); };
    } else if (t === 1) {
      expediente(b);
    } else {
      const link = 'https://verifica.escudosst.com/x/7Hq2-KD9p';
      b.innerHTML = '<div class="grid g2"><div class="card stack"><h3>Enlace para el inspector</h3><p class="small muted">Comparte el expediente en modo solo lectura. El enlace vence y cada acceso queda registrado.</p>' + campo('lk-v', 'Vence', 'select', '7 días', ['24 horas', '3 días', '7 días', '15 días']) + campo('lk-p', 'Clave de acceso (opcional)', 'text', 'SUNAFIL-2026') + '<div class="linkbox"><code id="lk-code">' + link + '</code><button class="btn sm" id="copy">Copiar</button></div><div class="row"><a class="btn pri" href="#sun-inspector">' + ic('eye', 16) + 'Ver como inspector</a><button class="btn danger" id="revk">Revocar enlace</button></div></div>' +
        '<div class="card"><h3>Accesos al enlace</h3>' + tabla([{ l: 'Fecha', k: 0 }, { l: 'Desde', k: 1 }, { l: 'Acción', k: 2 }], [['30/10/2026 10:12', 'Lima · navegador', 'Abrió el índice'], ['30/10/2026 10:15', 'Lima · navegador', 'Descargó «Registro de inspecciones»']]) + '</div></div>';
      $('#copy', b).onclick = () => { const txt = $('#lk-code', b).textContent; const done = () => toast('Enlace copiado'); try { navigator.clipboard.writeText(txt).then(done, () => { selTexto($('#lk-code', b)); toast('Selecciona y copia el enlace', 'warn'); }); } catch (e) { selTexto($('#lk-code', b)); } };
      $('#revk', b).onclick = () => modal('Revocar enlace', '<p>El inspector ya no podrá abrir el expediente con este enlace.</p>', [{ l: 'Cancelar' }, { l: 'Revocar', cls: 'danger', fn: () => toast('Enlace revocado') }]);
    }
  };
  const sync = () => { $$('[data-tabs="sun"] button', root).forEach((x, j) => x.classList.toggle('on', t === j)); pint(); };
  root.innerHTML = encMod('SUN', 'Prepárate para una inspección: autodiagnóstico, expediente en minutos y enlace de solo lectura para el inspector.') +
    tabs('sun', ['Autodiagnóstico', 'Generar expediente', 'Compartir con el inspector'], 0) + '<div id="sun-body"></div>';
  bindTabs(root, 'sun', (i) => { t = i; sync(); });
  pint();
};
function selTexto(el) { const r = document.createRange(); r.selectNodeContents(el); const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
const EXP_DOCS = [
  ['Contrato y alta en T-Registro', 'TRB', 'ok'], ['Registro de inducción', 'CAP', 'ok'], ['Capacitaciones del periodo (4)', 'CAP', 'ok'], ['Entrega de EPP con firma', 'EPP', 'warn'],
  ['Cargo de entrega del RISST', 'RIS', 'ok'], ['IPERC del puesto vigente', 'IPC', 'ok'], ['Constancia de aptitud médica (sin diagnóstico)', 'EMO', 'ok'], ['SCTR vigente', 'SCT', 'ok'], ['Registro de accidentes del periodo', 'ACC', 'ok'], ['Actas del Comité SST', 'COM', 'ok'],
];
function expediente(b) {
  let por = 0; let generado = false;
  const pint = () => {
    b.innerHTML = '<div class="grid g-main"><div class="card stack"><h3>Configura el expediente</h3>' + seg('exp', ['Por trabajador', 'Por periodo', 'Por obligación'], por) +
      '<div class="grid g2">' + (por === 0 ? campo('e-w', 'Trabajador', 'select', 'Elmer Puma Sullca', W.map((w) => w.nombre)) : por === 1 ? campo('e-s', 'Sede', 'select', 'Todas', ['Todas', ...SEDES.map((s) => s.nombre)]) : campo('e-o', 'Obligación', 'select', 'Capacitación (art. 35 Ley 29783)', ['Capacitación (art. 35 Ley 29783)', 'Exámenes médicos (art. 49)', 'Comité SST', 'IPERC']) ) +
      campo('e-p', 'Periodo', 'select', '01/01/2026 – 30/10/2026', ['01/01/2026 – 30/10/2026', '2025 completo', 'Últimos 12 meses']) + '</div>' +
      '<button class="btn pri" id="gen">Generar expediente</button><div id="prog"></div></div>' +
      '<div class="card"><h3>Índice del expediente</h3>' + (generado ? '<p class="small muted" style="margin:4px 0 8px">EXP-2026-005 · ' + EXP_DOCS.length + ' documentos · 1 con observación</p><div class="list">' + EXP_DOCS.map((d, i) => '<div class="li"><span class="pill-plan">' + (i + 1) + '</span><div class="lm"><b>' + d[0] + '</b><span>Módulo ' + d[1] + '</span></div>' + chip(d[2] === 'ok' ? 'Completo' : 'Observado', d[2]) + '</div>').join('') + '</div><div class="row" style="margin-top:10px"><button class="btn" id="zip">Descargar PDF único</button><button class="btn pri" id="share">Compartir con el inspector</button></div>' : vacio('Aún no hay expediente', 'Elige los filtros y pulsa «Generar expediente».')) + '</div></div>';
    bindSeg(b, 'exp', (i) => { por = i; generado = false; pint(); });
    $('#gen', b).onclick = () => {
      const p = $('#prog', b); let k = 0;
      $('#gen', b).disabled = true;
      const step = () => { k++; p.innerHTML = '<div class="small" style="margin-bottom:4px">Reuniendo documentos… ' + Math.min(k, EXP_DOCS.length) + ' de ' + EXP_DOCS.length + '</div>' + barra(Math.round(Math.min(k, EXP_DOCS.length) / EXP_DOCS.length * 100), 'ok') + '<p class="tiny muted" style="margin-top:4px">' + esc(EXP_DOCS[Math.min(k, EXP_DOCS.length) - 1][0]) + '</p>'; if (k < EXP_DOCS.length) setTimeout(step, 110); else { generado = true; setTimeout(() => { pint(); toast('Expediente EXP-2026-005 generado'); }, 150); } };
      step();
    };
    const z = $('#zip', b); if (z) z.onclick = () => toast('PDF único con índice y separadores (simulado)');
    const sh = $('#share', b); if (sh) sh.onclick = () => { const bt = $$('[data-tabs="sun"] button')[2]; if (bt) bt.click(); };
  };
  pint();
}
R['sun-inspector'] = (root) => {
  root.innerHTML = '<div class="publicwrap" style="max-width:900px"><div class="publicnote"><span>' + ic('eye', 16) + ' Vista simulada del inspector: enlace de solo lectura, sin cuenta en EscudoSST.</span><a class="btn sm" href="#m-sun">Volver al sistema</a></div>' +
    '<div class="inspector-head"><div class="row" style="gap:10px">' + LOGO(36) + '<div><b style="font-family:var(--font-d);font-size:1.1rem">Expediente EXP-2026-005</b><div class="small" style="opacity:.8">Industrias Andinas del Sur S.A.C. · RUC 20518472632</div></div></div><div class="small" style="opacity:.85">Trabajador: Elmer Puma Sullca · Periodo 01/01/2026 – 30/10/2026 · Enlace vence el 06/11/2026</div></div>' +
    '<div class="card"><div class="ch"><h3>Índice</h3><span class="small muted">10 documentos</span></div><div class="list">' + EXP_DOCS.map((d, i) => '<button class="li" data-d="' + i + '" style="width:100%;background:none;border:0;border-bottom:1px solid var(--line);text-align:left;cursor:pointer"><span class="pill-plan">' + (i + 1) + '</span><div class="lm"><b>' + d[0] + '</b></div>' + ic('eye', 16) + '</button>').join('') + '</div></div>' +
    '<p class="tiny muted" style="text-align:center">Documento de solo lectura. Cada consulta queda registrada. No incluye diagnósticos médicos: solo la constancia de aptitud.</p></div>';
  $$('[data-d]', root).forEach((x) => x.onclick = () => modal(EXP_DOCS[+x.dataset.d][0], '<div class="paper watermark"><div class="ptit">' + esc(EXP_DOCS[+x.dataset.d][0].toUpperCase()) + '</div><p>Industrias Andinas del Sur S.A.C. · Trabajador: Elmer Puma Sullca (DNI 47•••130)</p><p style="margin-top:8px">Contenido del documento (vista previa simulada).</p></div>', [{ l: 'Cerrar', cls: 'pri' }], { wide: 1 }));
};

/* ---------- Consultora ---------- */
R.CON = (root) => {
  root.innerHTML = encMod('CON', 'Panel multi-cliente con la marca de la consultora. Cada empresa conserva su propio plan.', '<button class="btn" id="wl-conf">Configurar marca blanca</button>' + (esConsultor() ? btnNuevo('Invitar empresa', 'inv') : '')) +
    (!esConsultor() ? nota('Estás viendo el panel de la consultora con otro rol. Cambia a «Consultor SST» para ver la marca blanca de Prevencia.', 'warn') + '<div style="height:12px"></div>' : '') +
    '<div class="grid g4" style="margin-bottom:14px">' + kpi('Empresas gestionadas', '4') + kpi('Trabajadores en total', '308') + kpi('Cumplimiento promedio', '71%') + kpi('Alertas abiertas', '35') + '</div>' +
    '<div class="card"><div class="ch"><h3>Empresas cliente de ' + CONSULTORA.nombre + '</h3><span class="small muted">Toca una fila para entrar a esa empresa</span></div>' +
    tabla([{ l: 'Empresa', f: (e) => '<b>' + esc(e.nombre) + '</b><div class="tiny muted">RUC ' + e.ruc + '</div>' }, { l: 'Sector', k: 'sector' }, { l: 'Trabajadores', k: 'trab' }, { l: 'Plan', f: (e) => '<span class="pill-plan">' + PLANES[e.plan] + '</span>' + (e.addons.length ? ' <span class="tiny muted">+' + e.addons.join(', ') + '</span>' : '') }, { l: 'Figura', k: 'figura' }, { l: 'Cumplimiento', f: (e) => '<div style="min-width:120px"><div class="row between small"><span></span><b>' + e.cumpl + '%</b></div>' + barra(e.cumpl) + '</div>' }, { l: 'Alertas', f: (e) => chip(e.alertas + ' alertas', e.alertas > 10 ? 'bad' : e.alertas > 5 ? 'warn' : 'ok') }], empresasConsultora(), { click: 1 }) + '</div>' +
    '<div class="grid g2" style="margin-top:14px"><div class="card"><h3>Próximas visitas</h3><div class="list">' + [['Constructora Huaylas', 'Inspección de obra · 03/11/2026'], ['AgroExport Chincha', 'Capacitación de inducción · 06/11/2026'], ['Clínica Santa Rosa', 'Reunión del Comité · 10/11/2026']].map((x) => '<div class="li">' + ic('cal') + '<div class="lm"><b>' + x[0] + '</b><span>' + x[1] + '</span></div></div>').join('') + '</div></div>' +
    '<div class="card"><h3>Alertas críticas por empresa</h3><div class="list">' + [['Constructora Huaylas', '6 SCTR vencidos en obra Los Olivos', 'bad'], ['AgroExport Chincha', 'Sin Supervisor SST registrado', 'bad'], ['Industrias Andinas', '4 EMO vencidos', 'warn']].map((x) => '<div class="li">' + sem(x[2]) + '<div class="lm"><b>' + x[0] + '</b><span>' + x[1] + '</span></div></div>').join('') + '</div></div></div>';
  bindTabla(root, empresasConsultora(), (e) => { if (esConsultor()) { aplicarEmpresa(e.id); guardar(); toast('Entraste a ' + e.corto + ' · plan ' + PLANES[e.plan]); $('#app').innerHTML = ''; location.hash = 'm-dsh'; if (location.hash === '#m-dsh') render(); } else toast('Solo el consultor puede cambiar de empresa', 'warn'); });
  $('#wl-conf', root).onclick = () => modal('Marca blanca', '<div class="row">' + LOGO(48, true) + '<div><b>Prevencia SST</b><div class="small muted">Logo, color principal y dominio propios</div></div></div><div class="grid g2">' + campo('wl-n', 'Nombre visible', 'text', 'Prevencia SST') + campo('wl-c', 'Color principal', 'select', 'Verde azulado', ['Verde azulado', 'Azul EscudoSST', 'Granate']) + campo('wl-d', 'Subdominio', 'text', 'prevencia.escudosst.com') + campo('wl-e', 'Correo remitente', 'text', 'alertas@prevencia.pe') + '</div>' + nota('Las empresas cliente verán la marca de Prevencia en el sistema, la app y los correos. Se mantiene «con tecnología EscudoSST».'), [{ l: 'Cancelar' }, { l: 'Guardar', cls: 'pri', fn: () => toast('Marca blanca guardada') }]);
  const inv = $('#inv', root); if (inv) inv.onclick = () => formModal('Invitar empresa cliente', [['RUC', 'text', '20610325891'], ['Razón social', 'text', 'Transportes Mantaro S.A.C.'], ['Correo del administrador', 'email', 'gerencia@transmantaro.pe'], ['Plan sugerido', 'select', 'Profesional', ['Esencial', 'Profesional', 'Corporativo']]], 'Enviar invitación', 'Invitación enviada; la empresa elige y contrata su plan');
};

/* ---------- App del trabajador ---------- */
R['app-trabajador'] = (root) => {
  const w = wById(3);
  const aula = planIncluye(modBy('AUL')) && featActiva('AUL');
  const TABS = [['home', 'Inicio', 'inicio']].concat(aula ? [['book', 'Cursos', 'cursos']] : []).concat([['qr', 'Fotocheck', 'fot'], ['warn', 'Reportar', 'rep'], ['heart', 'Mi salud', 'salud'], ['check', 'Votar', 'votar']]);
  let tab = 'inicio'; let firmado = false; let eppFirm = false; let cursoAb = null;
  const cTrab = cursoBy(CURSO_TRAB);
  const pend = () => [!firmado && ['Firmar cargo del RISST 2026', 'Reglamento interno · versión 3', 'warn', 'risst'], !eppFirm && ['Confirmar entrega de EPP', 'Guantes anticorte y lentes · 28/10', 'warn', 'epp'], !(cTrab.est && cTrab.est.nota >= cTrab.min) && ['Curso: Trabajo seguro con prensas', aula ? 'Aula virtual · 3 h · compromiso obligatorio' : 'Vence en 20 días', 'info', 'cap'], ELECCION.etapa === 2 && !ELECCION.votado && ['Votar en la elección del Comité', 'Cierra hoy 18:00', 'ok', 'votar']].filter(Boolean);
  const pint = () => {
    let body = '';
    if (tab === 'cursos' && cursoAb) {
      $('#wp', root).innerHTML = '<div id="w-st"></div>';
      $$('#wtab button', root).forEach((x, i) => x.classList.toggle('on', TABS[i][2] === tab));
      estudioCurso($('#w-st', root), cursoBy(cursoAb), { alVolver: () => { cursoAb = null; pint(); }, persona: [w.nombre, w.dni, w.puesto] });
      return;
    }
    if (tab === 'cursos') {
      const mis = [[CURSO_TRAB], ['CUR-008', 0.9], ['CUR-003', 0.95]];
      body = '<h2>Mis cursos</h2><p class="small muted">Aula virtual de Industrias Andinas</p>' + mis.map(([id, nf]) => { const c = cursoBy(id); const st = c.est; const it = []; secciones(c).forEach((s2, si) => s2.it.forEach((x, ii) => it.push(si + '-' + ii))); const av = nf ? 100 : st ? Math.round(it.filter((k) => st.hechos.has(k)).length * 100 / it.length) : 0; const apr = nf || (st && st.nota >= c.min); return '<button class="bigbtn" data-cur="' + id + '"' + (nf ? ' data-hecho="1"' : '') + '><span class="bi ' + (apr ? 'ok' : '') + '" style="' + (apr ? '' : 'background:' + c.color + ';color:#fff') + '">' + ic(apr ? 'award' : 'book', 22) + '</span><span style="flex:1;min-width:0"><b>' + esc(c.n) + '</b><span>' + (apr ? 'Aprobado · ' + notaTxt(c.escala, nf || st.nota) : av + '% completado · ' + c.horas + ' h') + '</span>' + barra(av, apr ? 'ok' : 'info') + '</span></button>'; }).join('') +
        recoBloque([['CUR-017', 'Siguiente curso de tu ruta «Operador de prensa»', 'spark'], ['CUR-008', 'Tu certificado de Uso y cuidado de EPP vence en 20 días', 'clock', 'Recapacitarme']], true);
    } else if (tab === 'inicio') {
      const p = pend();
      body = '<div><div class="small muted">Hola,</div><h2>' + esc(w.nombres) + '</h2><div class="small muted">' + esc(w.puesto) + ' · ' + esc(sedeNom(w.sede)) + '</div></div>' +
        '<h3>Mis pendientes (' + p.length + ')</h3>' + (p.length ? p.map((x) => '<button class="bigbtn" data-p="' + x[3] + '"><span class="bi ' + (x[2] === 'warn' ? 'warn' : x[2] === 'ok' ? 'ok' : '') + '">' + ic(x[3] === 'votar' ? 'check' : x[3] === 'cap' ? 'play' : 'pen', 22) + '</span><span><b>' + x[0] + '</b><span>' + x[1] + '</span></span></button>').join('') : '<div class="card">' + vacio('Todo al día', 'No tienes pendientes. ¡Gracias por cuidarte!') + '</div>') +
        '<button class="bigbtn" data-go="rep" style="background:var(--amber-soft);border-color:var(--amber)"><span class="bi warn">' + ic('warn', 22) + '</span><span><b>Reportar acto o condición</b><span>Con foto, en menos de un minuto</span></span></button>';
    } else if (tab === 'fot') {
      body = '<h2>Mi fotocheck</h2><div style="display:flex;justify-content:center">' + credencial(w, credEstado(w)) + '</div><p class="small muted" style="text-align:center">Muéstralo en portería o en campo para que lo escaneen.</p><h3>Mis capacitaciones</h3><div class="list">' + [['Inducción SST', 'Completada · 18/01/2021', 'ok'], ['Uso de extintores', 'Completada · 12/06/2026', 'ok'], ['Trabajo con prensas', 'Vence en 20 días', 'warn'], ['Primeros auxilios', 'Completada · 03/03/2026', 'ok']].map((c) => '<div class="li">' + sem(c[2]) + '<div class="lm"><b>' + c[0] + '</b><span>' + c[1] + '</span></div></div>').join('') + '</div>';
    } else if (tab === 'rep') {
      body = '<h2>Reportar</h2><p class="small muted">Lo recibe el área SST al instante. Puedes enviarlo de forma anónima.</p>' + seg('wt', ['Condición insegura', 'Acto inseguro'], 0) + '<button class="btn lg" id="w-foto">' + ic('camera') + 'Tomar foto</button>' + campo('w-desc', '¿Qué viste?', 'textarea', '') + campo('w-lugar', 'Lugar', 'select', 'Planta Ate – Línea de prensas', ['Planta Ate – Línea de prensas', 'Planta Ate – Comedor', 'Planta Ate – Soldadura']) + '<label class="row small" for="w-anon"><input type="checkbox" id="w-anon"> Enviar como anónimo</label><button class="btn pri lg" id="w-env">Enviar reporte</button>';
    } else if (tab === 'salud') {
      body = '<h2>Mi salud</h2>' + nota('Solo tú y la médica ocupacional pueden ver estos resultados.', '', 'lock') + '<div class="card">' + dl([['Último EMO', 'Periódico · 10/12/2025'], ['Aptitud', chip('Apto con restricciones')], ['Vigencia', 'Hasta ' + masDias(40)], ['Restricción', 'Evitar levantar más de 15 kg']]) + '</div><button class="bigbtn" id="w-res"><span class="bi">' + ic('file', 22) + '</span><span><b>Ver mis resultados</b><span>Te pediremos tu clave</span></span></button><button class="bigbtn" id="w-cita"><span class="bi">' + ic('cal', 22) + '</span><span><b>Mi próximo EMO</b><span>Por programar · te avisaremos</span></span></button>';
    } else {
      body = '<h2>Elección del Comité SST</h2>' + (ELECCION.etapa !== 2 ? nota('La votación no está abierta ahora.', 'warn') : ELECCION.votado ? nota('Ya votaste. Tu voto es secreto. ¡Gracias por participar!', 'ok', 'check') : '<p class="small muted">Elige a un candidato. Tu voto es secreto y no se puede cambiar.</p><div class="stack" style="gap:8px">' + Object.keys(ELECCION.votos).map((n) => '<button class="cand" data-c="' + esc(n) + '"><span class="av">' + iniciales(n) + '</span><b>' + n + '</b></button>').join('') + '</div><button class="btn pri lg" id="w-votar" disabled>Votar</button>');
    }
    $('#wp', root).innerHTML = body;
    $$('#wtab button', root).forEach((x, i) => x.classList.toggle('on', TABS[i][2] === tab));
    $$('[data-cur]', $('#wp', root)).forEach((x) => x.onclick = () => { if (x.dataset.hecho) { toast('Curso aprobado. Tu constancia está en «Fotocheck» › Mis capacitaciones'); return; } cursoAb = x.dataset.cur; pint(); });
    $$('[data-reco]', $('#wp', root)).forEach((x) => x.onclick = () => toast('Te avisaremos cuando el área SST te matricule en este curso'));
    const wp = $('#wp', root);
    $$('[data-p]', wp).forEach((x) => x.onclick = () => {
      const k = x.dataset.p;
      if (k === 'risst' || k === 'epp') firmaModal(k === 'risst' ? 'Firmar cargo del RISST' : 'Confirmar entrega de EPP', k === 'risst' ? 'Declaro haber recibido el Reglamento Interno de Seguridad y Salud en el Trabajo, versión 3.' : 'Declaro haber recibido: guantes anticorte (talla 8) y lentes de seguridad claros.', () => { if (k === 'risst') firmado = true; else eppFirm = true; pint(); });
      if (k === 'cap' && aula) { tab = 'cursos'; cursoAb = CURSO_TRAB; pint(); return; }
      if (k === 'cap') modal('Trabajo con prensas', '<div class="photo" style="width:100%;height:160px;display:grid;place-items:center;color:#fff;background:#1F7FD1">' + ic('play', 40) + '</div><p class="small">Módulo 1 de 3 · 15 min. Al final hay una evaluación de 5 preguntas.</p>', [{ l: 'Más tarde' }, { l: 'Empezar', cls: 'pri', fn: () => toast('Capacitación iniciada (simulado)') }]);
      if (k === 'votar') { tab = 'votar'; pint(); }
    });
    $$('[data-go]', wp).forEach((x) => x.onclick = () => { tab = x.dataset.go; pint(); });
    const f = $('#w-foto', wp); if (f) f.onclick = () => { f.outerHTML = '<div class="photo" style="width:100%;height:140px">' + fotoSim(2) + '</div>'; };
    const e = $('#w-env', wp); if (e) e.onclick = () => { if (!$('#w-desc', wp).value.trim()) { toast('Cuéntanos qué viste', 'warn'); return; } toast('Reporte enviado. ¡Gracias por cuidarnos!'); tab = 'inicio'; pint(); };
    const rs = $('#w-res', wp); if (rs) rs.onclick = () => modal('Confirma que eres tú', campo('w-pin', 'Tu clave de 4 dígitos', 'password', '') + '<p class="tiny muted">Demostración: escribe cualquier número.</p>', [{ l: 'Cancelar' }, { l: 'Ver', cls: 'pri', fn: (ov) => { if (!$('#w-pin', ov).value) { toast('Escribe tu clave', 'warn'); return false; } setTimeout(() => modal('Mis resultados', dl([['Audiometría', 'Normal'], ['Espirometría', 'Normal'], ['Evaluación lumbar', 'Lumbalgia mecánica leve'], ['Recomendación', 'Pausas activas y evitar cargas mayores a 15 kg']]) + '<p class="tiny muted">Acceso registrado. Solo tú y la médica ocupacional ven esta información.</p>', [{ l: 'Cerrar', cls: 'pri' }]), 50); } }]);
    const ci = $('#w-cita', wp); if (ci) ci.onclick = () => toast('Te avisaremos por WhatsApp cuando se programe');
    let sel = null;
    $$('[data-c]', wp).forEach((c) => c.onclick = () => { $$('[data-c]', wp).forEach((x) => x.classList.remove('sel')); c.classList.add('sel'); sel = c.dataset.c; $('#w-votar', wp).disabled = false; });
    const v = $('#w-votar', wp); if (v) v.onclick = () => modal('Confirmar voto', '<p>Vas a votar por <b>' + esc(sel) + '</b>.</p>', [{ l: 'Volver' }, { l: 'Confirmar', cls: 'pri', fn: () => { ELECCION.votos[sel]++; ELECCION.votado = true; toast('Voto emitido'); pint(); } }]);
  };
  root.innerHTML = encabezado('App del trabajador', 'Así ve EscudoSST ' + esc(w.nombre) + ' desde su celular (PWA instalable). Botones grandes y lenguaje simple.', '', [['App del trabajador']]) +
    '<div class="grid g-main" style="align-items:start"><div class="phone"><div class="ps"><span>9:41</span><span>' + (S.addons.has('OFF') ? 'Funciona sin señal' : '4G') + '</span></div><div class="pc"><div style="background:var(--brand);color:var(--brand-ink);padding:12px 14px;display:flex;align-items:center;gap:10px">' + LOGO(28) + '<b style="font-family:var(--font-d)">EscudoSST</b><span style="margin-left:auto" class="av">' + iniciales(w.nombre) + '</span></div><div class="pbody" id="wp"></div>' +
    '<nav class="ptab" id="wtab" style="grid-template-columns:repeat(' + TABS.length + ',1fr)">' + TABS.map((x) => '<button data-wt="' + x[2] + '">' + ic(x[0], 20) + x[1] + '</button>').join('') + '</nav></div></div>' +
    '<div class="stack"><div class="card"><h3>Qué puede hacer el trabajador</h3><ul class="small muted" style="margin:8px 0 0;padding-left:18px;display:flex;flex-direction:column;gap:4px"><li>Ver y resolver sus pendientes</li><li>Firmar cargos (RISST, EPP) con el dedo</li><li>Estudiar sus cursos del aula virtual, aceptar el compromiso, rendir la evaluación y recibir su certificado</li><li>Mostrar su fotocheck con QR</li><li>Reportar actos y condiciones inseguras</li><li>Ver sus propios resultados médicos con clave</li><li>Votar en la elección del Comité</li></ul></div>' + (S.role !== 'worker' ? nota('Para ver la app en pantalla completa, elige «Trabajador» en «Ver como».') : '') + '</div></div>';
  $$('#wtab button', root).forEach((x, i) => x.onclick = () => { tab = TABS[i][2]; cursoAb = null; pint(); });
  pint();
};
function firmaModal(t, decl, ok) {
  modal(t, '<p class="small">' + esc(decl) + '</p><span class="small muted">Firma con tu dedo:</span><canvas class="sigpad" id="sig" width="500" height="150" aria-label="Recuadro de firma"></canvas><button class="btn sm" id="sig-clr" style="align-self:flex-start">Borrar</button><p class="tiny muted">Se registran fecha, hora, dispositivo y ubicación aproximada. La firma no se puede modificar.</p>', [{ l: 'Cancelar' }, { l: 'Firmar', cls: 'pri', fn: () => { ok(); toast('Firmado correctamente'); } }], {
    mount: (ov) => {
      const c = $('#sig', ov); const x = c.getContext('2d'); let d = false;
      x.lineWidth = 2.4; x.lineCap = 'round'; x.strokeStyle = getComputedStyle(document.body).color;
      const pos = (e) => { const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height]; };
      c.onpointerdown = (e) => { d = true; x.beginPath(); x.moveTo(...pos(e)); };
      c.onpointermove = (e) => { if (d) { x.lineTo(...pos(e)); x.stroke(); } };
      c.onpointerup = c.onpointerleave = () => { d = false; };
      x.beginPath(); x.moveTo(60, 100); x.bezierCurveTo(120, 30, 160, 130, 220, 70); x.bezierCurveTo(260, 40, 300, 110, 360, 80); x.stroke();
      $('#sig-clr', ov).onclick = () => x.clearRect(0, 0, c.width, c.height);
    },
  });
}

/* ---------- Asistente IA ---------- */
R.IAA = (root) => {
  let t = 0;
  const sug = [
    { pe: 'Ruido de la máquina de soldar y esmeril', ri: 'Hipoacusia inducida por ruido', p: 3, s: 3, ctl: 'Ingeniería: cabina acústica · EPP: protector auditivo', st: '' },
    { pe: 'Superficies calientes', ri: 'Quemadura en manos y antebrazos', p: 3, s: 2, ctl: 'Administrativo: señalización · EPP: guantes de cuero', st: '' },
    { pe: 'Postura de pie prolongada', ri: 'Trastorno musculoesquelético', p: 2, s: 2, ctl: 'Administrativo: pausas activas · Ingeniería: banco de apoyo', st: '' },
    { pe: 'Gases comprimidos (argón/CO₂)', ri: 'Asfixia en espacio poco ventilado', p: 1, s: 5, ctl: 'Ingeniería: ventilación general · Administrativo: detector de O₂', st: '' },
  ];
  const pint = () => {
    const b = $('#iaa-body', root);
    if (t === 0) {
      b.innerHTML = '<div class="grid g-main"><div class="stack">' + nota('<b>La IA solo sugiere.</b> Cada fila queda como sugerencia pendiente hasta que una persona la acepte. Las filas aceptadas entran al borrador del IPERC, que luego aprueba el Comité SST. No se envían datos médicos ni personales al proveedor de IA.', 'warn', 'spark') +
        '<div class="card"><div class="ch"><h3>Sugerencias para el puesto «Soldador»</h3><span class="small muted">Basadas en el catálogo de peligros y el paquete Manufactura</span></div><div class="stack" id="sg"></div></div></div>' +
        '<div class="card stack"><h3>Pedir sugerencias</h3>' + campo('ia-p', 'Puesto', 'select', 'Soldador', PUESTOS.map((p) => p[0]).concat('Soldador')) + campo('ia-c', 'Contexto adicional', 'textarea', 'Soldadura MIG de cuerpos de envases, nave con ventilación natural.') + '<button class="btn pri" id="ia-go">' + ic('spark', 16) + 'Generar sugerencias</button></div></div>';
      const sg = $('#sg', b);
      const dib = () => {
        sg.innerHTML = sug.map((s, i) => '<div class="sugg ' + (s.st === 'acc' ? 'acc' : s.st === 'dis' ? 'dis' : '') + '"><div class="row between"><b>' + esc(s.pe) + '</b>' + nivelHtml(s.p, s.s) + '</div><span class="small">' + esc(s.ri) + '</span><span class="small muted">Controles: ' + esc(s.ctl) + '</span>' +
          (s.st ? '<span class="small">' + (s.st === 'acc' ? chip('Aceptada · agregada al borrador v4', 'ok') : chip('Descartada', 'na')) + '</span>' : '<div class="row"><button class="btn sm pri" data-a="' + i + '">Aceptar</button><button class="btn sm" data-e="' + i + '">Editar</button><button class="btn sm ghost" data-d="' + i + '">Descartar</button></div>') + '</div>').join('');
        $$('[data-a]', sg).forEach((x) => x.onclick = () => { const s = sug[+x.dataset.a]; s.st = 'acc'; IPERC.filas.push({ pr: 'Fabricación de envases', ac: 'Soldadura de cuerpos', ta: 'Soldar costura lateral con MIG', pe: s.pe, ri: s.ri, p: s.p, s: s.s, ctl: s.ctl.split(' · '), pr2: Math.max(1, s.p - 1), s2: s.s }); dib(); toast('Fila agregada al borrador del IPERC'); });
        $$('[data-d]', sg).forEach((x) => x.onclick = () => { sug[+x.dataset.d].st = 'dis'; dib(); toast('Sugerencia descartada'); });
        $$('[data-e]', sg).forEach((x) => x.onclick = () => { const s = sug[+x.dataset.e]; modal('Editar sugerencia', campo('se-pe', 'Peligro', 'text', s.pe) + campo('se-ri', 'Riesgo', 'text', s.ri) + campo('se-c', 'Controles', 'text', s.ctl), [{ l: 'Cancelar' }, { l: 'Guardar y aceptar', cls: 'pri', fn: (ov) => { s.pe = $('#se-pe', ov).value; s.ri = $('#se-ri', ov).value; s.ctl = $('#se-c', ov).value; s.st = 'acc'; dib(); toast('Sugerencia editada y aceptada'); } }]); });
      };
      dib();
      $('#ia-go', b).onclick = () => { sg.innerHTML = '<div class="stack">' + '<div class="shimmer"></div><div class="shimmer" style="width:70%"></div><div class="shimmer" style="width:85%"></div></div>'; setTimeout(() => { sug.forEach((s) => { s.st = ''; }); dib(); toast('4 sugerencias generadas'); }, 900); };
    } else {
      const pasos = [['¿Qué pasó exactamente?', 'El operador retiró con la mano una pieza atascada en la prensa N.° 3.'], ['¿Por qué metió la mano?', 'No había una herramienta de extracción junto a la máquina.'], ['¿Por qué no había herramienta?', 'El PETS no la exige y nadie la solicitó.'], ['¿Qué barrera debió impedirlo?', 'La guarda fija, que fue retirada en mantenimiento.']];
      b.innerHTML = '<div class="grid g-main"><div class="card stack"><h3>Investigación guiada · ACC-2026-015</h3><p class="small muted">El asistente hace preguntas y propone causas. La persona investigadora decide.</p>' + pasos.map((p, i) => '<div class="card flat" style="background:var(--surface-2)"><b class="small">' + ic('spark', 14) + ' ' + p[0] + '</b><p class="small" style="margin-top:4px">' + p[1] + '</p></div>').join('') + '<div class="sugg"><b>Causas propuestas</b><span class="small">• Falta de guarda fija (condición) · • Ausencia de herramienta de extracción (factor de trabajo) · • Procedimiento incompleto (factor de trabajo)</span><div class="row"><button class="btn sm pri" id="ia-acc">Aceptar en el árbol</button><button class="btn sm">Editar</button></div></div></div>' +
        '<div class="card"><h3>Reglas del asistente</h3><ul class="small muted" style="padding-left:18px;margin:8px 0 0;display:flex;flex-direction:column;gap:4px"><li>Nunca envía datos médicos.</li><li>Las sugerencias se guardan como pendientes.</li><li>Solo una persona puede aplicarlas.</li><li>Queda registro de quién aceptó cada sugerencia.</li></ul></div></div>';
      $('#ia-acc', b).onclick = () => toast('3 causas agregadas al árbol de la investigación');
    }
  };
  root.innerHTML = encMod('IAA', 'IPERC sugerido por puesto e investigación guiada. Complemento: precio por definir.', '') + tabs('iaa', ['IPERC sugerido', 'Investigación guiada'], 0) + '<div id="iaa-body"></div>';
  bindTabs(root, 'iaa', (i) => { t = i; $$('[data-tabs="iaa"] button', root).forEach((x, j) => x.classList.toggle('on', i === j)); pint(); });
  pint();
};

/* ---------- Paquetes sectoriales ---------- */
const PAQUETES = [
  ['Construcción', 'G.050, trabajos en altura, excavaciones', ['IPERC de 42 puestos de obra', 'PETAR de altura y excavación', 'Checklist de andamios', 'Formato de charla diaria'], 'building'],
  ['Minería', 'D.S. 024-2016-EM', ['Anexos del reglamento minero', 'IPERC continuo', 'Inspecciones de labores', 'Programa de capacitación de 4 anexos'], 'risk'],
  ['Agroindustria', 'Fundos y plantas de empaque', ['IPERC de cosecha y empaque', 'Control de agroquímicos', 'Checklist de transporte de personal', 'Ergonomía en mesas'], 'sun'],
  ['Manufactura', 'Máquinas, ruido y químicos', ['IPERC de 30 puestos de planta', 'Inspección de guardas', 'Bloqueo y etiquetado', 'Monitoreo de ruido'], 'box'],
  ['Retail', 'Tiendas y almacenes', ['IPERC de tienda y almacén', 'Checklist de evacuación', 'Manipulación de cargas', 'Atención de emergencias con público'], 'grid'],
  ['Oficinas', 'Trabajo administrativo y remoto', ['IPERC de oficina', 'Ergonomía de puesto con pantalla', 'Teletrabajo', 'Plan de emergencias de edificio'], 'building'],
  ['Clínicas', 'Riesgo biológico y turnos', ['IPERC de personal asistencial', 'Bioseguridad y residuos', 'Vacunación del personal', 'Exposición a radiación'], 'heart'],
];
R.PSE = (root) => {
  const act = new Set(['Manufactura']);
  const pint = () => {
    root.innerHTML = encMod('PSE', 'Plantillas, IPERC base y formatos listos por sector. Complemento: precio por definir.') +
      '<div class="grid g3">' + PAQUETES.map((p, i) => '<div class="card pack"><div class="row between"><div class="row">' + ic(p[3], 22) + '<h3>' + p[0] + '</h3></div>' + (act.has(p[0]) ? chip('Activo', 'ok') : '') + '</div><span class="small muted">' + p[1] + '</span><ul>' + p[2].map((x) => '<li>' + x + '</li>').join('') + '</ul>' +
        (puedeEditar() ? (act.has(p[0]) ? '<button class="btn sm" data-off="' + i + '">Desactivar</button>' : '<button class="btn sm pri" data-on="' + i + '">Activar paquete</button>') : '') + '</div>').join('') + '</div>';
    $$('[data-on]', root).forEach((x) => x.onclick = () => { const p = PAQUETES[+x.dataset.on]; modal('Activar paquete ' + p[0], '<p>Se agregarán a tu empresa:</p><ul class="small">' + p[2].map((y) => '<li>' + y + '</li>').join('') + '</ul>' + nota('Son plantillas: puedes editarlas. Las matrices IPERC entran como borrador y requieren aprobación.'), [{ l: 'Cancelar' }, { l: 'Activar', cls: 'pri', fn: () => { act.add(p[0]); pint(); toast('Paquete ' + p[0] + ' activado'); } }]); });
    $$('[data-off]', root).forEach((x) => x.onclick = () => { act.delete(PAQUETES[+x.dataset.off][0]); pint(); toast('Paquete desactivado; tus registros se conservan'); });
  };
  pint();
};

/* ---------- OFF ---------- */
R.OFF = (root) => {
  pantalla(root, 'OFF', {
    desc: 'Inspecciones, charlas, ATS y reportes sin señal; se sincronizan al volver la conexión.',
    aviso: '<div class="offline" style="border-radius:8px;margin-bottom:14px">' + ic('wifi', 16) + 'Sin conexión — 3 registros por sincronizar (dispositivo de Gabriela Montoya)</div>',
    kpis: [['Dispositivos activos', '23'], ['Lotes sincronizados hoy', '41'], ['Conflictos por revisar', '2'], ['Pendientes en teléfonos', '3']],
    tabs: [
      { nombre: 'Dispositivos', cols: [{ l: 'Dispositivo', k: 0 }, { l: 'Usuario', k: 1 }, { l: 'Última sincronización', k: 2 }, { l: 'Pendientes', k: 3 }, { l: 'Estado', f: (r) => chip(r[4]) }], rows: [['Samsung A54 · campo-07', 'Gabriela Montoya', '30/10/2026 06:12', '3', 'Pendiente'], ['Moto G84 · almacén-02', 'Wilmer Ccahuana', '30/10/2026 09:30', '0', 'Sincronizado'], ['Xiaomi Redmi 13 · prensas-01', 'Jorge Quispe', '30/10/2026 09:02', '0', 'Sincronizado'], ['iPhone 13 · sst-01', 'Rocío Villanueva', '29/10/2026 18:40', '0', 'Sincronizado']], filtros: [{ k: 4, l: 'Estado' }] },
      { nombre: 'Conflictos', cols: [{ l: 'Registro', k: 0 }, { l: 'Campo', k: 1 }, { l: 'Valor en teléfono', k: 2 }, { l: 'Valor en servidor', k: 3 }, { l: 'Estado', f: (r) => chip(r[4]) }], rows: [['INS-2026-222', 'Estado del extintor', 'No cumple', 'Cumple', 'Pendiente'], ['CH5-2026-310', 'Asistentes', '14', '13', 'Pendiente']] },
    ],
    ficha: (r) => ({ t: r[0], s: esc(r[1]), html: dl([['Detalle', esc(r.slice(1).join(' · '))]]) + nota('Los conflictos se registran y una persona elige qué valor conservar. Nada se sobrescribe sin revisión.'), btns: [{ l: 'Conservar el del teléfono', fn: () => toast('Conflicto resuelto') }, { l: 'Conservar el del servidor', cls: 'pri', fn: () => toast('Conflicto resuelto') }] }),
  });
};

/* ---------- Panel del dueño del SaaS ---------- */
R.SUS = (root) => {
  let t = 0;
  const cuentas = [
    ['Industrias Andinas del Sur S.A.C.', 'Directa', 'Profesional', 'OFF, IAA, PSE', '140', 'Activa', '12/03/2026'],
    ['Prevencia Consultores SST', 'Consultora (4 empresas)', 'Consultora', '—', '308', 'Activa', '05/01/2026'],
    ['Transportes Mantaro S.A.C.', 'Directa', 'Esencial', '—', '17', 'En prueba', '22/10/2026'],
    ['Corporación Textil Gamarra S.A.', 'Directa', 'Corporativo', 'ISO', '410', 'Activa', '18/02/2026'],
    ['Instituto Qhaliy de Seguridad Ocupacional S.A.C.', 'Directa', 'Profesional', '—', '22', 'Activa', '09/09/2026'],
    ['Minera Cerro Azul S.A.C.', 'Directa', 'Profesional', 'PSE', '96', 'En prueba', '27/10/2026'],
    ['Panificadora Don Lucho E.I.R.L.', 'Directa', 'Esencial', '—', '12', 'Suspendida', '04/04/2026'],
  ];
  const pint = () => {
    const b = $('#sus-body', root);
    if (t === 0) {
      b.innerHTML = '<div class="grid g4" style="margin-bottom:14px">' + kpi('Cuentas activas', '38') + kpi('Empresas gestionadas', '61') + kpi('En prueba gratuita', '7') + kpi('Trabajadores en la plataforma', '6 284') + '</div>';
      pantallaLista(b.appendChild(document.createElement('div')), cuentas, [{ l: 'Cuenta', k: 0 }, { l: 'Tipo', k: 1 }, { l: 'Plan', k: 2 }, { l: 'Complementos', k: 3 }, { l: 'Trabajadores', k: 4 }, { l: 'Estado', f: (r) => chip(r[5]) }, { l: 'Alta', k: 6 }], [[2, 'Plan'], [5, 'Estado']], (r) => cajon(r[0], esc(r[1]), dl([['Plan', r[2]], ['Complementos', r[3]], ['Trabajadores', r[4]], ['Estado', chip(r[5])], ['Alta', r[6]], ['Importe', '<b>Por definir</b>']]) + nota('El equipo EscudoSST no ve los datos de la empresa. Para dar soporte necesita una autorización del administrador de la cuenta, que queda registrada.', '', 'lock'), [{ l: 'Solicitar acceso de soporte', fn: () => toast('Solicitud enviada al administrador de la cuenta') }, { l: 'Cambiar plan', cls: 'pri', fn: () => toast('Cambio de plan registrado (simulado)') }]));
    } else if (t === 1) {
      const n = { E: 0, P: 0, C: 0 }; MODS.forEach((m) => { if (n[m.plan] !== undefined) n[m.plan]++; });
      b.innerHTML = '<div class="grid g3">' + ['E', 'P', 'C'].map((k) => '<div class="card"><h3>' + PLANES[k] + '</h3><p class="small muted" style="margin:4px 0 10px">' + MODS.filter((m) => (CAT.plan_incluye[k]).includes(m.plan)).length + ' módulos · precio <b>por definir</b></p><div class="row" style="gap:4px">' + MODS.filter((m) => m.plan === k).map((m) => '<span class="pill-plan" title="' + esc(m.nombre) + '">' + m.code + '</span>').join('') + '</div></div>').join('') + '</div>' +
        '<h3 style="margin:18px 0 10px">Complementos</h3>' + tabla([{ l: 'Código', k: 0 }, { l: 'Complemento', k: 1 }, { l: 'Qué resuelve', k: 2 }, { l: 'Precio', f: () => 'Por definir' }, { l: 'Cuentas que lo usan', f: (r) => String((r[0].charCodeAt(0) * 7) % 19 + 2) }], COMPLEMENTOS);
    } else if (t === 2) {
      b.innerHTML = tabla([{ l: 'Cuenta', k: 0 }, { l: 'Plan', k: 1 }, { l: 'Inicio de prueba', k: 2 }, { l: 'Días restantes', k: 3 }, { l: 'Avance de configuración', f: (r) => '<div style="min-width:120px">' + barra(r[4]) + '</div>' }, { l: 'Estado', f: (r) => chip(r[5]) }], [['Transportes Mantaro S.A.C.', 'Esencial', '22/10/2026', '' + 22, 60, 'En prueba'], ['Minera Cerro Azul S.A.C.', 'Profesional', '27/10/2026', '' + 27, 25, 'En prueba'], ['Restaurantes La Sazón S.A.C.', 'Esencial', '03/10/2026', '' + 3, 90, 'Por vencer']]);
    } else {
      b.innerHTML = '<div class="grid g3">' + [['API y aplicación', 'Operativo', '99,97% este mes'], ['Base principal', 'Operativo', 'Réplica al día'], ['Base médica', 'Operativo', 'Cifrada en reposo'], ['Colas de trabajo', 'Operativo', '12 trabajos en cola'], ['Correo transaccional', 'Operativo', '4 812 enviados hoy'], ['WhatsApp', 'Observado', 'Proveedor por definir · modo simulado'], ['Almacenamiento de archivos', 'Operativo', 'Respaldo cifrado 03:00'], ['Sincronización offline', 'Operativo', '2 conflictos abiertos'], ['Asistente IA', 'Operativo', 'Sin datos médicos enviados']].map((x) => '<div class="card"><div class="row between"><h4>' + x[0] + '</h4>' + chip(x[1]) + '</div><p class="small muted" style="margin-top:6px">' + x[2] + '</p></div>').join('') + '</div>';
    }
  };
  root.innerHTML = encabezado('Panel de la plataforma', 'Cuentas, planes, complementos, pruebas y salud del sistema de EscudoSST. Los importes están por definir.', '<a class="btn" href="#registro">Ver registro autoservicio</a>', [['Panel de la plataforma']]) +
    tabs('sus', ['Cuentas y suscripciones', 'Planes y complementos', 'Empresas en prueba', 'Salud del sistema'], 0) + '<div id="sus-body"></div>';
  bindTabs(root, 'sus', (i) => { t = i; $$('[data-tabs="sus"] button', root).forEach((x, j) => x.classList.toggle('on', i === j)); pint(); });
  pint();
};

/* ---------- Registro autoservicio ---------- */
R.registro = (root) => {
  let paso = 0; let plan = 'P'; let cargado = false; let lb = {};
  const pasos = ['Tu empresa', 'Elige tu plan', 'Carga tus trabajadores', 'Línea base'];
  const filasXls = [
    ['1', '70214598', 'Rosa', 'Quispe Lázaro', 'Operario', 'Planta', 'ok', ''], ['2', '4521983', 'Mario', 'Huamán Ríos', 'Chofer', 'Planta', 'bad', 'DNI debe tener 8 dígitos'],
    ['3', '72983104', 'Lucía', 'Paredes Soto', 'Asistente', 'Oficina', 'ok', ''], ['4', '41239876', 'Jorge', '', 'Operario', 'Planta', 'bad', 'Falta apellidos'],
    ['5', '70214598', 'Ana', 'Ccori Vela', 'Almacenero', 'Almacén', 'bad', 'DNI repetido (fila 1)'], ['6', '46019283', 'Pedro', 'Ramos Lima', 'Supervisor', 'Planta', 'ok', ''], ['7', '73019284', 'Elena', 'Torres Gil', 'Operaria', 'Sede Norte', 'warn', 'Sede «Sede Norte» no existe: se creará'],
  ];
  const pint = () => {
    let body = '';
    if (paso === 0) body = '<div class="grid g2">' + campo('r-ruc', 'RUC', 'text', '20610325891') + '<div class="field"><span>&nbsp;</span><button class="btn" id="r-sunat">Buscar en SUNAT (simulado)</button></div>' + campo('r-rs', 'Razón social', 'text', 'Transportes Mantaro S.A.C.') + campo('r-ac', 'Actividad', 'text', 'Transporte de carga por carretera') + campo('r-tr', 'Número de trabajadores', 'number', '17') + campo('r-co', 'Correo del administrador', 'email', 'gerencia@transmantaro.pe') + '</div>' + nota('Con menos de 20 trabajadores corresponde Supervisor SST; desde 20, Comité SST. ' + param('Umbral legal como parámetro'));
    if (paso === 1) body = '<div class="grid g3">' + [['E', 'Para empresas con Supervisor SST', ['IPERC, inspecciones y EPP', 'Capacitaciones y accidentes', 'Los 9 registros obligatorios']], ['P', 'Para empresas con Comité SST', ['Todo Esencial', 'Modo Inspección SUNAFIL', 'Fotocheck con QR, EMO y SCTR']], ['C', 'Para grupos y operaciones de alto riesgo', ['Todo Profesional', 'PETAR, contratistas y portería', 'Vigilancia médica y API']]].map(([k, d, l]) => '<button class="plancard ' + (plan === k ? 'sel' : '') + '" data-pl="' + k + '"><h3>' + PLANES[k] + '</h3><span class="small muted">' + d + '</span><ul>' + l.map((x) => '<li>' + x + '</li>').join('') + '</ul><b class="small">Precio por definir</b></button>').join('') + '</div>' + nota('Empiezas con una prueba gratuita con todas las funciones del plan elegido. Duración ' + param('por definir') + '. No pedimos tarjeta.', 'ok', 'check');
    if (paso === 2) body = (cargado ? '<div class="row between"><span><b>trabajadores_mantaro.xlsx</b> · 7 filas leídas</span><div class="row">' + chip('4 válidas', 'ok') + chip('1 con aviso', 'warn') + chip('3 con error', 'bad') + '</div></div>' + tabla([{ l: 'Fila', k: 0 }, { l: 'DNI', k: 1 }, { l: 'Nombres', k: 2 }, { l: 'Apellidos', k: 3 }, { l: 'Puesto', k: 4 }, { l: 'Sede', k: 5 }, { l: 'Resultado', f: (r) => r[6] === 'ok' ? chip('Válida', 'ok') : chip(r[7], r[6]) }], filasXls) + '<div class="row"><button class="btn" id="r-otro">Subir otro archivo</button><button class="btn" onclick="toast(\'Reporte de errores descargado (simulado)\')">Descargar errores</button></div>' + nota('Se importarán las 4 filas válidas y la fila con aviso. Corrige las 3 filas con error y vuelve a subirlas cuando quieras.', 'warn')
      : '<div class="dz">' + ic('upload', 36) + '<b>Arrastra tu Excel aquí</b><span class="small">o usa la plantilla con columnas DNI, nombres, apellidos, puesto, área y sede</span><div class="row"><button class="btn" onclick="toast(\'Plantilla descargada (simulado)\')">Descargar plantilla</button><button class="btn pri" id="r-up">Simular carga de archivo</button></div></div>');
    if (paso === 3) {
      const preg = [['¿Tienes Política SST firmada y difundida?', 'POL'], ['¿Tienes IPERC de todos los puestos?', 'IPC'], ['¿Tienes Supervisor o Comité SST elegido?', 'COM'], ['¿Realizas EMO a todos los trabajadores?', 'EMO'], ['¿Llevas los registros obligatorios?', 'REG'], ['¿Entregas EPP con cargo firmado?', 'EPP']];
      const si = Object.values(lb).filter((v) => v === 's').length;
      body = '<p class="small muted">Responde rápido. Con esto armamos tu plan de trabajo inicial y tu primer puntaje.</p>' + preg.map((p, i) => '<div class="chk-item"><span class="small">' + p[0] + '</span><div class="tri">' + [['c', 's', 'Sí'], ['a', 'p', 'En parte'], ['n', 'n', 'No']].map(([c, v, l]) => '<button class="' + c + (lb[i] === v ? ' on' : '') + '" data-lb="' + i + '" data-v="' + v + '">' + l + '</button>').join('') + '</div></div>').join('') +
        '<div class="card flat row" style="background:var(--surface-2);margin-top:10px"><div class="kpi"><span class="kl">Línea base estimada</span><span class="kv">' + Math.round((si + Object.values(lb).filter((v) => v === 'p').length * 0.5) / preg.length * 100) + '%</span></div><span class="small muted">Las respuestas «No» o «En parte» se convierten en brechas con acciones sugeridas en el módulo Diagnóstico de línea base.</span></div>';
    }
    root.innerHTML = '<div style="max-width:900px">' + encabezado('Crea tu cuenta en EscudoSST', 'Registro autoservicio: así empieza una empresa nueva por su cuenta.', '', [['Registro autoservicio']]) +
      '<div class="steps">' + pasos.map((p, i) => '<span class="st ' + (i < paso ? 'done' : i === paso ? 'cur' : '') + '"><i>' + (i < paso ? '✓' : i + 1) + '</i>' + p + '</span>').join('') + '</div><div class="card stack">' + body +
      '<div class="row between"><button class="btn" id="r-prev"' + (paso ? '' : ' disabled') + '>Anterior</button><button class="btn pri" id="r-next">' + (paso < 3 ? 'Continuar' : 'Ir a mi panel') + '</button></div></div></div>';
    const s = $('#r-sunat', root); if (s) s.onclick = () => toast('Datos encontrados: Transportes Mantaro S.A.C. · activo y habido');
    $$('[data-pl]', root).forEach((x) => x.onclick = () => { plan = x.dataset.pl; pint(); });
    const up = $('#r-up', root); if (up) up.onclick = () => { cargado = true; pint(); toast('Archivo leído: 7 filas'); };
    const ot = $('#r-otro', root); if (ot) ot.onclick = () => { cargado = false; pint(); };
    $$('[data-lb]', root).forEach((x) => x.onclick = () => { lb[x.dataset.lb] = x.dataset.v; pint(); });
    $('#r-prev', root).onclick = () => { paso--; pint(); };
    $('#r-next', root).onclick = () => { if (paso === 0 && !/^(10|20)\d{9}$/.test($('#r-ruc', root).value)) { toast('El RUC debe tener 11 dígitos y empezar con 10 o 20', 'warn'); return; } if (paso < 3) { paso++; pint(); } else { toast('Cuenta creada · prueba gratuita del plan ' + PLANES[plan] + ' activa'); location.hash = S.role === 'platform_owner' ? 'm-sus' : 'm-dsh'; } };
  };
  pint();
};
