/* Fotocheck (FOT) + verificación pública, EMO, Comité (COM), Accidentes (ACC), Investigación (INV), Registros (REG) */
function requisitosFot(w) {
  return [
    ['Inducción SST', w.induccion === 'ok' ? 'ok' : 'bad', w.induccion === 'ok' ? 'Completada' : 'Pendiente'],
    ['Capacitaciones del puesto', w.cap, w.cap === 'ok' ? 'Al día' : w.cap === 'warn' ? '1 por vencer' : '1 vencida'],
    ['EMO apto vigente', w.emoDias > 30 && /^Apto/.test(w.aptitud) ? 'ok' : w.emoDias > 0 && /^Apto/.test(w.aptitud) ? 'warn' : 'bad', w.aptitud === 'Pendiente' ? 'Sin EMO' : /^Apto/.test(w.aptitud) ? (w.emoDias > 0 ? 'Vence en ' + w.emoDias + ' días' : 'Vencido') : 'No apto'],
    ['SCTR', w.sctrDias > 30 ? 'ok' : w.sctrDias > 0 ? 'warn' : 'bad', w.sctrDias > 0 ? 'Vence en ' + w.sctrDias + ' días' : 'Vencido'],
  ];
}
const credEstado = (w) => { const r = requisitosFot(w); return r.some((x) => x[1] === 'bad') ? 'bad' : r.some((x) => x[1] === 'warn') ? 'warn' : 'ok'; };
function credencial(w, estado) {
  const band = { ok: ['ok', 'HABILITADO'], warn: ['warn', 'HABILITADO · REQUISITO POR VENCER'], bad: ['bad', 'NO HABILITADO'], rev: ['bad', 'CREDENCIAL REVOCADA'] }[estado];
  return '<div class="cred"><div class="ctop">' + LOGO(30) + '<div><b>Industrias Andinas del Sur</b><span>Fotocheck SST · RUC 20518472632</span></div></div>' +
    '<div class="cbody"><div class="cph">' + iniciales(w.nombre) + '</div><div><div class="cn">' + esc(w.nombres) + '<br>' + esc(w.apellidos) + '</div><div class="cm">DNI ' + w.dni.slice(0, 2) + '•••' + w.dni.slice(-3) + '</div><div class="cm">' + esc(w.puesto) + '</div><div class="cm">' + esc(sedeNom(w.sede)) + '</div></div></div>' +
    '<div class="cqr">' + (QR.fotocheck || '') + '<div class="cm">Escanea para verificar en línea.<br>N.° FC-2026-' + String(w.id).padStart(4, '0') + '<br>Emitido 02/10/2026</div></div><div class="cband ' + band[0] + '">' + band[1] + '</div></div>';
}
R.FOT = (root) => {
  let selId = 3;
  const pint = () => {
    const w = wById(selId);
    const req = requisitosFot(w);
    const est = credEstado(w);
    root.innerHTML = encMod('FOT', 'Credencial con QR que muestra en campo si el trabajador está habilitado, sin exponer datos médicos.', '<a class="btn" href="#pub-verificar">' + ic('qr', 16) + 'Probar verificación pública</a>' + btnExport()) +
      '<div class="grid g4" style="margin-bottom:14px">' + kpi('Fotochecks vigentes', '118') + kpi('Por vencer (30 días)', '14') + kpi('No habilitados', '8') + kpi('Verificaciones este mes', '342', 'En portería y campo') + '</div>' +
      '<div class="grid g-main"><div class="card"><div class="ch"><h3>Emitir fotocheck</h3><select class="input" id="fot-w" style="width:auto;max-width:100%" aria-label="Trabajador">' + W.filter((x) => x.id !== 10).map((x) => '<option value="' + x.id + '"' + (x.id === selId ? ' selected' : '') + '>' + esc(x.nombre) + '</option>').join('') + '</select></div>' +
      '<p class="small muted" style="margin-bottom:10px">Para emitirlo deben cumplirse los requisitos configurados para el puesto «' + esc(w.puesto) + '».</p>' +
      '<div class="list">' + req.map((r) => '<div class="li">' + sem(r[1]) + '<div class="lm"><b>' + r[0] + '</b><span>' + r[2] + '</span></div>' + chip(r[1] === 'ok' ? 'Cumple' : r[1] === 'warn' ? 'Por vencer' : 'No cumple', r[1]) + '</div>').join('') + '</div>' +
      (est === 'bad' ? nota('No se puede emitir: hay requisitos que no se cumplen. Se notificó a RR. HH. y al área SST.', 'bad') : est === 'warn' ? nota('Se puede emitir; el fotocheck mostrará la alerta hasta que se renueve el requisito.', 'warn') : nota('Cumple todos los requisitos.', 'ok', 'check')) +
      '<div class="row" style="margin-top:12px">' + (puedeEditar() ? '<button class="btn pri" id="emitir"' + (est === 'bad' ? ' disabled' : '') + '>Emitir fotocheck</button><button class="btn danger" id="revocar">Revocar</button>' : '') + '</div></div>' +
      '<div class="stack" style="align-items:center">' + credencial(w, est) + '<p class="tiny muted" style="text-align:center;max-width:300px">El QR solo lleva un código seguro. Quien lo escanea ve nombre, foto, puesto y si está habilitado; nunca diagnósticos.</p></div></div>';
    $('#fot-w', root).onchange = (e) => { selId = +e.target.value; pint(); };
    const em = $('#emitir', root); if (em) em.onclick = () => modal('Fotocheck emitido', '<p>Se emitió el fotocheck de <b>' + esc(w.nombre) + '</b>. Está disponible en su app y listo para imprimir en PVC.</p>', [{ l: 'Imprimir (PDF)', fn: () => toast('PDF para impresión generado (simulado)') }, { l: 'Listo', cls: 'pri' }]);
    const rv = $('#revocar', root); if (rv) rv.onclick = () => modal('Revocar fotocheck', '<p>El QR dejará de validar de inmediato y en portería aparecerá «Revocada».</p>' + campo('rev-m', 'Motivo', 'select', 'Cese del trabajador', ['Cese del trabajador', 'Pérdida o robo', 'Requisito vencido', 'Otro']), [{ l: 'Cancelar' }, { l: 'Revocar', cls: 'danger', fn: () => { window.__fotRevocada = true; toast('Fotocheck revocado'); } }]);
  };
  pint();
};
R['pub-verificar'] = (root) => {
  let estado = null; let w = wById(3);
  const casos = [['Vigente', 'ok', 3], ['Requisito por vencer', 'warn', 4], ['Vencida', 'bad', 8], ['Revocada', 'rev', 12]];
  const pint = () => {
    root.innerHTML = '<div class="publicwrap"><div class="publicnote"><span>' + ic('eye', 16) + ' Página pública simulada: verificación al escanear un fotocheck.</span><a class="btn sm" href="#m-fot">Volver al sistema</a></div>' +
      '<div class="row" style="gap:10px">' + LOGO(40) + '<div><b style="font-family:var(--font-d)">Verificación de fotocheck SST</b><div class="small muted">EscudoSST · consulta pública</div></div></div>' +
      (!estado ? '<div class="card stack" style="align-items:center;text-align:center;padding:24px"><div style="width:200px;height:200px;border:3px solid var(--brand);border-radius:18px;display:grid;place-items:center;position:relative;overflow:hidden"><div style="width:150px">' + (QR.fotocheck || '') + '</div><div style="position:absolute;left:0;right:0;top:50%;height:2px;background:var(--amber)"></div></div><p class="muted">Simula el escaneo de un fotocheck en portería:</p><div class="row" style="justify-content:center">' + casos.map((c, i) => '<button class="btn" data-c="' + i + '">' + c[0] + '</button>').join('') + '</div></div>'
        : (() => {
          const ok = estado === 'ok' || estado === 'warn';
          const col = estado === 'ok' ? 'var(--ok)' : estado === 'warn' ? 'var(--amber)' : 'var(--bad)';
          const tit = { ok: 'Habilitado', warn: 'Habilitado con alerta', bad: 'Credencial vencida', rev: 'Credencial revocada' }[estado];
          return '<div class="card" style="padding:0;overflow:hidden"><div style="background:' + col + ';color:' + (estado === 'warn' ? '#2A1C00' : '#fff') + ';padding:18px;text-align:center"><div>' + ic(ok ? 'check' : 'x', 40) + '</div><h2 style="color:inherit">' + tit + '</h2><div class="small">Consultado el 30/10/2026 a las 09:41</div></div>' +
            '<div style="padding:16px" class="stack"><div class="row"><span class="av" style="width:56px;height:56px;font-size:1.1rem">' + iniciales(w.nombre) + '</span><div><b>' + esc(w.nombre) + '</b><div class="small muted">' + esc(w.puesto) + ' · Industrias Andinas del Sur</div><div class="small muted">DNI ' + w.dni.slice(0, 2) + '•••' + w.dni.slice(-3) + '</div></div></div>' +
            (estado === 'rev' ? nota('Esta credencial fue anulada por la empresa. No permitas el ingreso y comunícate con el área SST.', 'bad') :
              '<div class="list">' + [['Inducción SST', 'ok'], ['Capacitaciones', estado === 'bad' ? 'bad' : 'ok'], ['Aptitud médica', estado === 'warn' ? 'warn' : estado === 'bad' ? 'bad' : 'ok'], ['SCTR', 'ok']].map((r) => '<div class="li">' + sem(r[1]) + '<div class="lm"><b>' + r[0] + '</b></div>' + chip(r[1] === 'ok' ? 'Vigente' : r[1] === 'warn' ? 'Por vencer' : 'Vencido', r[1]) + '</div>').join('') + '</div>') +
            '<p class="tiny muted">Por protección de datos (Ley 29733) esta consulta nunca muestra diagnósticos ni resultados médicos. La consulta queda registrada.</p><button class="btn" id="otra">Escanear otro</button></div></div>';
        })()) + '</div>';
    $$('[data-c]', root).forEach((b) => b.onclick = () => { const c = casos[+b.dataset.c]; estado = c[1]; w = wById(c[2]); pint(); });
    const o = $('#otra', root); if (o) o.onclick = () => { estado = null; pint(); };
  };
  pint();
};

/* ---------- EMO ---------- */
let medicoAutenticado = false;
R.EMO = (root) => {
  const medico = S.role === 'occupational_doctor';
  const salud = S.role === 'health_staff';
  const rows = W.slice(0, 24).map((w) => [w.nombre, w.puesto, w.emoDias <= 0 && w.aptitud !== 'Pendiente' ? 'Periódico' : w.id % 3 ? 'Periódico' : 'Preocupacional', 'Clínica Ocupacional San Gabriel', w.aptitud, w.aptitud === 'Pendiente' ? 'Programado 05/11/2026' : w.emoDias > 0 ? masDias(w.emoDias) : masDias(w.emoDias), w.aptitud === 'Pendiente' ? 'Pendiente' : w.emoDias <= 0 ? 'Vencido' : w.emoDias <= 30 ? 'Por vencer' : 'Vigente', w.id]);
  root.innerHTML = encMod('EMO', 'Programación de exámenes médicos ocupacionales, aptitud y vigencias. Los diagnósticos viven en una base médica separada.', btnExport() + (medico || salud || S.role === 'sst_manager' || S.role === 'hr' ? btnNuevo('Programar EMO', 'emo-n') : '')) +
    (medico ? nota('Eres la única persona con acceso al detalle médico. Cada apertura pide tu clave adicional y queda registrada (Ley 29733).', '', 'shield') : nota('Ves solo la aptitud (apto, apto con restricciones, no apto) y su vigencia. Los diagnósticos y resultados solo los ve la médica ocupacional.', '', 'lock')) +
    '<div style="height:12px"></div><div class="grid g4" style="margin-bottom:14px">' + kpi('EMO vigentes', '121', 'de 140 trabajadores') + kpi('Por vencer (30 días)', '9') + kpi('Vencidos', '4') + kpi('Aptos con restricciones', '6') + '</div><div id="emo-l"></div>';
  pantallaLista($('#emo-l', root), rows, [{ l: 'Trabajador', k: 0 }, { l: 'Puesto', k: 1 }, { l: 'Tipo', k: 2 }, { l: 'Clínica', k: 3 }, { l: 'Aptitud', f: (r) => chip(r[4]) }, { l: 'Vigencia', k: 5 }, { l: 'Estado', f: (r) => chip(r[6]) }], [[4, 'Aptitud'], [6, 'Estado']], (r) => fichaEmo(r));
  const n = $('#emo-n', root); if (n) n.onclick = () => formModal('Programar EMO', [['Trabajador', 'select', W[12].nombre, W.map((w) => w.nombre)], ['Tipo', 'select', 'Periódico', ['Preocupacional', 'Periódico', 'De retiro']], ['Clínica', 'select', 'Clínica Ocupacional San Gabriel', ['Clínica Ocupacional San Gabriel', 'Centro Médico Laboral Lima Este']], ['Fecha', 'date', '2026-11-05']], 'Programar', 'EMO programado; se avisó al trabajador por WhatsApp');
  bindExport(root);
};
function fichaEmo(r) {
  const w = wById(r[7]);
  const base = dl([['Trabajador', esc(r[0])], ['Puesto', esc(r[1])], ['Tipo de examen', r[2]], ['Clínica', esc(r[3])], ['Aptitud', chip(r[4])], ['Vigencia', r[5]], ['Restricciones', /restricciones/.test(r[4]) ? 'Evitar levantar más de 15 kg' : 'Ninguna']]);
  const medico = S.role === 'occupational_doctor';
  if (!medico) {
    cajon('EMO · ' + w.nombre, 'Aptitud y vigencia', base + '<div class="card flat stack" style="align-items:center;text-align:center;background:var(--surface-2)">' + ic('lock', 30) + '<b>Acceso restringido</b><p class="small muted">El detalle médico (diagnósticos, resultados y archivos) solo lo puede ver la médica ocupacional. Tu rol: ' + esc(rolNom(S.role)) + '.</p></div>', [{ l: 'Cerrar' }]);
    return;
  }
  const abrir = () => cajon('Historia ocupacional · ' + w.nombre, 'Base médica separada · acceso registrado',
    nota('Acceso registrado el 30/10/2026 09:41 por Dra. Sandra Medina Cárdenas · motivo: control periódico.', 'warn', 'shield') + base +
    '<div><h4 style="margin-bottom:6px">Resultados (datos de ejemplo)</h4>' + dl([['Audiometría', 'Hipoacusia leve bilateral en 4 kHz'], ['Espirometría', 'Normal'], ['Agudeza visual', '20/25 ambos ojos'], ['Evaluación musculoesquelética', 'Lumbalgia mecánica leve'], ['Recomendación', 'Uso permanente de protección auditiva; control en 6 meses']]) + '</div>' +
    '<div class="list"><div class="li">' + ic('file') + '<div class="lm"><b>Informe EMO periódico 2026.pdf</b><span>Bucket médico cifrado · enlace firmado de corta duración</span></div><button class="btn sm" id="ver-pdf">Ver</button></div></div>',
  [{ l: 'Cerrar' }, { l: 'Editar aptitud', cls: 'pri', fn: () => toast('Aptitud actualizada; RR. HH. solo verá el nuevo estado') }], (d) => { const v = $('#ver-pdf', d); if (v) v.onclick = () => toast('Documento abierto con enlace temporal (simulado)'); });
  if (medicoAutenticado) { abrir(); return; }
  modal('Confirma tu identidad', nota('Vas a ver datos de salud, que son datos sensibles. Este acceso quedará registrado con fecha, hora y motivo.', 'warn', 'shield') +
    campo('reauth-p', 'Clave adicional', 'password', '') + campo('reauth-m', 'Motivo del acceso', 'select', 'Control periódico', ['Control periódico', 'Evaluación de aptitud', 'Investigación de accidente', 'Solicitud del trabajador']) + '<p class="tiny muted">Para la demostración escribe cualquier clave.</p>',
  [{ l: 'Cancelar' }, { l: 'Confirmar y ver', cls: 'pri', fn: (ov) => { if (!$('#reauth-p', ov).value) { toast('Escribe tu clave adicional', 'warn'); return false; } medicoAutenticado = true; setTimeout(abrir, 50); toast('Identidad confirmada · acceso registrado'); } }]);
}

/* ---------- Comité SST ---------- */
const ELECCION = { etapa: 2, votos: { 'Juana Apaza Choque': 31, 'Wilmer Ccahuana Rojas': 24, 'Héctor Llanos Bustamante': 18, 'Nelly Quispe Mamani': 22, 'Elmer Puma Sullca': 9 }, votado: false, padron: 128 };
R.COM = (root) => {
  let t = 0;
  const pint = () => {
    const b = $('#com-body', root);
    if (t === 0) {
      b.innerHTML = '<div class="grid g-main"><div class="card"><div class="ch"><h3>Comité SST 2025–2027</h3>' + chip('Vigente') + '</div>' + tabla([{ l: 'Miembro', f: (c) => '<b>' + esc(wById(c[0]).nombre) + '</b>' }, { l: 'Cargo', k: 1 }, { l: 'Representa a', k: 2 }, { l: 'Desde', k: 3 }], COMITE) + '<p class="small muted" style="margin-top:8px">Paritario: 3 representantes del empleador y 3 de los trabajadores. Mandato ' + param('2 años') + '.</p></div>' +
        '<div class="stack"><div class="card"><h3>Próxima reunión</h3><p style="margin-top:6px"><b>Ordinaria N.° 10</b><br><span class="small muted">Jueves 05/11/2026 · 15:00 · Sala de directorio Planta Ate</span></p><button class="btn" style="margin-top:10px" id="conv">Enviar convocatoria</button></div><div class="card"><h3>Acuerdos pendientes</h3><div class="list">' + [['Aprobar IPERC Soldador v4', 'Rocío Villanueva', 'Pendiente'], ['Revisar resultados de monitoreo de ruido', 'Comité', 'En proceso'], ['Inspección mensual a Almacén Callao', 'Wilmer Ccahuana', 'Pendiente']].map((a) => '<div class="li"><div class="lm"><b>' + a[0] + '</b><span>' + a[1] + '</span></div>' + chip(a[2]) + '</div>').join('') + '</div></div></div></div>';
      $('#conv', b).onclick = () => toast('Convocatoria enviada a 6 miembros por correo y WhatsApp');
    } else if (t === 1) {
      eleccion(b);
    } else {
      const actas = [['Acta N.° 09 · Ordinaria', '08/10/2026', '6/6', 4, 'Firmada'], ['Acta N.° 08 · Extraordinaria (accidente ACC-2026-014)', '22/09/2026', '5/6', 3, 'Firmada'], ['Acta N.° 07 · Ordinaria', '10/09/2026', '6/6', 5, 'Firmada'], ['Acta N.° 10 · Ordinaria', '05/11/2026', '—', 0, 'Programada']];
      b.innerHTML = tabla([{ l: 'Reunión', k: 0 }, { l: 'Fecha', k: 1 }, { l: 'Asistencia', k: 2 }, { l: 'Acuerdos', k: 3 }, { l: 'Estado', f: (r) => chip(r[4]) }], actas, { click: 1 });
      bindTabla(b, actas, (a) => cajon(a[0], a[1], '<div class="paper"><div class="ptit">ACTA DE REUNIÓN DEL COMITÉ DE SEGURIDAD Y SALUD EN EL TRABAJO</div><p>En Ate, siendo las 15:00 horas del ' + a[1] + ', se reunieron los miembros del Comité SST de Industrias Andinas del Sur S.A.C. con quórum de ' + a[2] + ' miembros.</p><p style="margin-top:6px"><b>Agenda:</b> 1. Revisión de accidentes del mes. 2. Avance del PASST. 3. Aprobación de documentos.</p><p style="margin-top:6px"><b>Acuerdos:</b> 1. Aprobar el Programa anual de capacitación 2026. 2. Instalar guarda fija en prensa N.° 3. 3. Programar inspección a Almacén Callao.</p></div>' + '<h4>Firmas</h4><div class="row">' + COMITE.map((c) => chip(wById(c[0]).nombres.split(' ')[0] + ' ' + wById(c[0]).apellidos.split(' ')[0], a[4] === 'Firmada' ? 'ok' : 'na')).join('') + '</div>', [{ l: 'Descargar PDF', fn: () => toast('PDF generado (simulado)') }, { l: 'Cerrar', cls: 'pri' }]));
    }
  };
  root.innerHTML = encMod('COM', 'Comité paritario (o Supervisor SST si hay menos de 20 trabajadores): elección electrónica, reuniones, actas y acuerdos.', btnExport()) +
    tabs('com', ['Comité vigente', 'Elección electrónica 2027–2029', 'Reuniones y actas'], 0) + '<div id="com-body"></div>';
  bindTabs(root, 'com', (i) => { t = i; $$('[data-tabs="com"] button', root).forEach((x, j) => x.classList.toggle('on', i === j)); pint(); });
  bindExport(root);
  pint();
};
function eleccion(b) {
  const etapas = ['Convocatoria', 'Inscripción de candidatos', 'Votación', 'Escrutinio', 'Acta'];
  const total = () => Object.values(ELECCION.votos).reduce((a, c) => a + c, 0);
  const pint = () => {
    const e = ELECCION.etapa;
    let body = '';
    if (e === 0) body = '<div class="card"><h3>Convocatoria publicada</h3><p class="small muted" style="margin-top:6px">Publicada el 01/10/2026 en la app, correo y murales. Elección de 3 representantes titulares y 3 suplentes de los trabajadores.</p></div>';
    if (e === 1) body = '<div class="card"><h3>Candidatos inscritos</h3>' + Object.keys(ELECCION.votos).map((n) => '<div class="li" style="display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--line)"><span class="av">' + iniciales(n) + '</span><div><b>' + n + '</b><div class="small muted">Requisitos verificados: mayor de edad, trabajador en planilla, capacitación SST</div></div></div>').join('') + '</div>';
    if (e === 2) body = '<div class="grid g2"><div class="card"><h3>Cédula de votación</h3><p class="small muted" style="margin:6px 0 10px">Así la ve el trabajador en su celular. Un voto por persona, secreto y sin posibilidad de cambio.</p>' + (ELECCION.votado ? nota('Ya votaste. Tu voto es secreto y quedó registrado de forma inalterable.', 'ok', 'check') : '<div class="stack" style="gap:8px">' + Object.keys(ELECCION.votos).map((n) => '<button class="cand" data-cand="' + esc(n) + '"><span class="av">' + iniciales(n) + '</span><b>' + n + '</b></button>').join('') + '<button class="btn pri lg" id="votar" disabled>Emitir mi voto</button></div>') + '</div>' +
      '<div class="card"><h3>Participación en vivo</h3><div class="kpi" style="margin:10px 0"><span class="kv num">' + total() + ' / ' + ELECCION.padron + '</span><span class="kd">' + Math.round(total() / ELECCION.padron * 100) + '% del padrón ha votado · cierre 30/10/2026 18:00</span></div>' + barra(Math.round(total() / ELECCION.padron * 100), 'ok') + '<p class="small muted" style="margin-top:10px">Los resultados por candidato se muestran solo al cerrar la votación.</p><button class="btn" id="simular" style="margin-top:10px">Simular más votos</button></div></div>';
    if (e === 3) { const tot = total(); const ord = Object.entries(ELECCION.votos).sort((a, c) => c[1] - a[1]); body = '<div class="card"><h3>Escrutinio</h3><p class="small muted" style="margin:6px 0 14px">' + tot + ' votos válidos · participación ' + Math.round(tot / ELECCION.padron * 100) + '%</p><div class="stack" style="gap:10px">' + ord.map(([n, v], i) => '<div class="votebar"><span>' + n + (i < 3 ? ' ' + chip('Titular', 'ok') : '') + '</span>' + barra(Math.round(v / ord[0][1] * 100), i < 3 ? 'ok' : '') + '<b class="num">' + v + '</b></div>').join('') + '</div></div>'; }
    if (e === 4) { const ord = Object.entries(ELECCION.votos).sort((a, c) => c[1] - a[1]); body = '<div class="paper"><div class="ptit">ACTA DE ELECCIÓN DE REPRESENTANTES DE LOS TRABAJADORES ANTE EL COMITÉ SST</div><p>Industrias Andinas del Sur S.A.C. · RUC 20518472632 · Elección electrónica realizada el 30/10/2026.</p><table style="margin-top:8px"><thead><tr><th>Candidato</th><th>Votos</th><th>Resultado</th></tr></thead><tbody>' + ord.map(([n, v], i) => '<tr><td>' + n + '</td><td>' + v + '</td><td>' + (i < 3 ? 'Titular' : 'Suplente') + '</td></tr>').join('') + '</tbody></table><p style="margin-top:8px">Junta electoral: Percy Rojas Inga (presidente), Silvia Ochoa Cáceres, Daniel Arévalo Saavedra.</p></div>'; }
    b.innerHTML = '<div class="steps">' + etapas.map((s, i) => '<span class="st ' + (i < e ? 'done' : i === e ? 'cur' : '') + '"><i>' + (i < e ? '✓' : i + 1) + '</i>' + s + '</span>').join('') + '</div>' + body +
      '<div class="row" style="margin-top:14px">' + (e > 0 ? '<button class="btn" id="el-prev">Etapa anterior</button>' : '') + (e < 4 && puedeEditar() ? '<button class="btn pri" id="el-next">' + ['Abrir inscripción', 'Abrir votación', 'Cerrar votación y escrutar', 'Generar acta'][e] + '</button>' : '') + (e === 4 ? '<button class="btn pri" id="el-pdf">Descargar acta en PDF</button>' : '') + '</div>';
    let sel = null;
    $$('[data-cand]', b).forEach((c) => c.onclick = () => { $$('[data-cand]', b).forEach((x) => x.classList.remove('sel')); c.classList.add('sel'); sel = c.dataset.cand; $('#votar', b).disabled = false; });
    const v = $('#votar', b); if (v) v.onclick = () => modal('Confirmar voto', '<p>Vas a votar por <b>' + esc(sel) + '</b>. Una vez emitido no se puede cambiar.</p>', [{ l: 'Volver' }, { l: 'Confirmar voto', cls: 'pri', fn: () => { ELECCION.votos[sel]++; ELECCION.votado = true; pint(); toast('Voto emitido de forma secreta'); } }]);
    const si = $('#simular', b); if (si) si.onclick = () => { Object.keys(ELECCION.votos).forEach((k, i) => { ELECCION.votos[k] += (i * 3 + 2) % 5; }); pint(); };
    const nx = $('#el-next', b); if (nx) nx.onclick = () => { ELECCION.etapa++; pint(); toast('Elección: ' + etapas[ELECCION.etapa]); };
    const pv = $('#el-prev', b); if (pv) pv.onclick = () => { ELECCION.etapa--; pint(); };
    const pd = $('#el-pdf', b); if (pd) pd.onclick = () => toast('Acta en PDF generada (simulado)');
  };
  pint();
}

/* ---------- Accidentes ---------- */
const PLAZO_NOTIF_H = 24;
R.ACC = (root) => {
  let paso = 0; let modo = 'lista';
  const datos = { tipo: 'Accidente de trabajo leve', fecha: '30/10/2026 07:40', lugar: 'Planta Ate – Línea de prensas 3', w: 18, desc: 'Al retirar una pieza atascada en la prensa N.° 3, el operador sufrió un corte en la mano izquierda con el borde de la lámina.', parte: 'Mano izquierda', dias: 2 };
  const eventos = [
    ['ACC-2026-015', 'Accidente leve', '30/10/2026 07:40', 'Planta Ate – Prensas 3', 'Christian Valdivia Neyra', 'Por notificar', 'En investigación'],
    ['ACC-2026-014', 'Accidente incapacitante', '22/09/2026 10:15', 'Planta Ate – Prensas 3', 'Elmer Puma Sullca', 'Notificado', 'Cerrado'],
    ['INC-2026-031', 'Incidente peligroso', '14/10/2026 16:30', 'Almacén Callao – Rampa 2', '—', 'Notificado', 'Cerrado'],
    ['INC-2026-030', 'Incidente', '09/10/2026 11:00', 'Planta Ate – Barnizado', '—', 'No aplica', 'Cerrado'],
    ['EO-2026-002', 'Enfermedad ocupacional', '03/08/2026', 'Planta Ate – Soldadura', 'Teodoro Cusi Achahui', 'Notificado', 'Abierta'],
  ];
  const pint = () => {
    if (modo === 'lista') {
      root.innerHTML = encMod('ACC', 'Registro de accidentes, incidentes y enfermedades, con control del plazo de notificación.', btnExport() + btnNuevo('Registrar evento', 'acc-n')) +
        '<div class="card" style="margin-bottom:14px;border-color:var(--bad)"><div class="row between"><div class="row">' + ic('warn', 28) + '<div><b>ACC-2026-015 · Notificación pendiente</b><div class="small muted">Accidente leve de Christian Valdivia Neyra. Plazo: ' + param(PLAZO_NOTIF_H + ' horas') + ' desde el hecho.</div></div></div><div style="text-align:right"><div class="countdown" id="cd">--:--:--</div><span class="tiny muted">restantes para notificar</span></div></div></div>' +
        '<div class="grid g4" style="margin-bottom:14px">' + kpi('Accidentes en el año', '6', '3 con días perdidos') + kpi('Incidentes peligrosos', '4') + kpi('Enfermedades ocupacionales', '1') + kpi('Investigaciones abiertas', '2') + '</div><div id="acc-l"></div>';
      pantallaLista($('#acc-l', root), eventos, [{ l: 'Código', k: 0 }, { l: 'Tipo', k: 1 }, { l: 'Fecha y hora', k: 2 }, { l: 'Lugar', k: 3 }, { l: 'Afectado', k: 4 }, { l: 'Notificación', f: (r) => chip(r[5], r[5] === 'Por notificar' ? 'bad' : undefined) }, { l: 'Estado', f: (r) => chip(r[6]) }], [[1, 'Tipo'], [6, 'Estado']], (r) => { if (r[0] === 'ACC-2026-015') { modo = 'detalle'; pint(); } else cajon(r[0], r[1], dl([['Fecha', r[2]], ['Lugar', esc(r[3])], ['Afectado', esc(r[4])], ['Notificación', chip(r[5])], ['Estado', chip(r[6])]]), [{ l: 'Abrir investigación', fn: () => { location.hash = 'm-inv'; } }, { l: 'Cerrar', cls: 'pri' }]); });
      const n = $('#acc-n', root); if (n) n.onclick = () => { modo = 'nuevo'; paso = 0; pint(); };
      cuentaRegresiva(root);
      bindExport(root);
      return;
    }
    if (modo === 'detalle') { detalleAccidente(root, () => { modo = 'lista'; pint(); }); return; }
    const pasos = ['Qué pasó', 'Persona afectada', 'Lesión y testigos', 'Notificación'];
    const w = wById(datos.w);
    let body = '';
    if (paso === 0) body = '<div class="grid g2">' + campo('a-tipo', 'Tipo de evento', 'select', datos.tipo, ['Incidente', 'Incidente peligroso', 'Accidente de trabajo leve', 'Accidente incapacitante', 'Accidente mortal', 'Enfermedad ocupacional']) + campo('a-fecha', 'Fecha y hora del hecho', 'text', datos.fecha) + campo('a-lugar', 'Lugar', 'text', datos.lugar) + campo('a-sede', 'Sede', 'select', 'Planta Ate', SEDES.map((s) => s.nombre)) + '</div>' + campo('a-desc', 'Descripción', 'textarea', datos.desc);
    if (paso === 1) body = campo('a-w', 'Trabajador afectado', 'select', w.nombre, W.map((x) => x.nombre)) + '<div class="card flat" style="background:var(--surface-2)">' + dl([['Puesto', esc(w.puesto)], ['Área', esc(w.area)], ['Antigüedad', 'Desde ' + w.ingreso], ['SCTR', w.sctrDias > 0 ? chip('Vigente') : chip('Vencido')], ['Aptitud', chip(w.aptitud)]]) + '</div>';
    if (paso === 2) body = '<div class="grid g2">' + campo('a-parte', 'Parte del cuerpo', 'select', datos.parte, ['Mano izquierda', 'Mano derecha', 'Ojos', 'Cabeza', 'Espalda', 'Pie']) + campo('a-dias', 'Días de descanso estimados', 'number', datos.dias) + campo('a-t1', 'Testigo 1', 'select', 'Liliana Vargas Huamán', W.map((x) => x.nombre)) + campo('a-t2', 'Atención médica', 'select', 'Tópico de planta y luego EsSalud', ['Tópico de planta', 'Tópico de planta y luego EsSalud', 'Clínica afiliada al SCTR']) + '</div><div class="field"><span>Fotos del lugar</span><div class="row"><div class="photo">' + fotoSim(1) + '</div><div class="photo">' + fotoSim(3) + '</div><button class="btn" onclick="toast(\'Foto adjuntada\')">' + ic('camera', 16) + 'Agregar</button></div></div>';
    if (paso === 3) body = nota('Según el tipo «' + esc(datos.tipo) + '», el sistema calcula la obligación de notificar al Ministerio de Trabajo (SAT) dentro de ' + param(PLAZO_NOTIF_H + ' horas') + '. El plazo es un parámetro con vigencia y debe confirmarlo un especialista.', 'warn') +
      '<div class="card flat stack" style="align-items:center;text-align:center;background:var(--surface-2)"><span class="small muted">Tiempo restante para notificar</span><div class="countdown" id="cd">--:--:--</div></div>' + campo('a-notif', 'Constancia de notificación (N.° SAT)', 'text', '') + '<p class="small muted">Al guardar se avisará al Comité SST, a la gerencia y al médico ocupacional, y se abrirá la investigación.</p>';
    root.innerHTML = encabezado('Registrar evento', 'Paso ' + (paso + 1) + ' de 4', '', [[CAT.grupos[6], 'm-acc'], ['Accidentes, incidentes y enfermedades', 'm-acc'], ['Registrar']]) +
      '<div class="steps">' + pasos.map((p, i) => '<span class="st ' + (i < paso ? 'done' : i === paso ? 'cur' : '') + '"><i>' + (i < paso ? '✓' : i + 1) + '</i>' + p + '</span>').join('') + '</div><div class="card stack" style="max-width:820px">' + body +
      '<div class="row between" style="margin-top:6px"><button class="btn" id="a-prev">' + (paso ? 'Anterior' : 'Cancelar') + '</button><button class="btn pri" id="a-next">' + (paso < 3 ? 'Siguiente' : 'Guardar y notificar') + '</button></div></div>';
    const sv = () => { const g = (id) => { const el = $('#' + id, root); return el ? el.value : null; }; if (g('a-tipo')) datos.tipo = g('a-tipo'); if (g('a-desc')) datos.desc = g('a-desc'); if (g('a-w')) datos.w = W.find((x) => x.nombre === g('a-w')).id; };
    $('#a-prev', root).onclick = () => { sv(); if (!paso) { modo = 'lista'; } else paso--; pint(); };
    $('#a-next', root).onclick = () => { sv(); if (paso < 3) { paso++; pint(); } else { toast('Evento registrado y notificaciones enviadas'); modo = 'detalle'; pint(); } };
    if (paso === 3) cuentaRegresiva(root);
  };
  pint();
};
function cuentaRegresiva(root) {
  const inicio = new Date(2026, 9, 30, 7, 40).getTime();
  const fin = inicio + PLAZO_NOTIF_H * 3600 * 1000;
  let ahora = HOY.getTime();
  const tick = () => { const el = $('#cd', root); if (!el) { clearInterval(window.__cdT); return; } const r = Math.max(0, fin - ahora); const h = Math.floor(r / 3600000), m = Math.floor(r % 3600000 / 60000), s = Math.floor(r % 60000 / 1000); el.textContent = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'); el.style.color = h < 6 ? 'var(--bad)' : 'var(--amber-ink)'; ahora += 1000; };
  clearInterval(window.__cdT); tick(); window.__cdT = setInterval(tick, 1000);
}
function detalleAccidente(root, volver) {
  root.innerHTML = encabezado('ACC-2026-015 · Accidente leve', 'Christian Valdivia Neyra · Operador de prensa troqueladora · 30/10/2026 07:40', '<button class="btn" id="volver">Volver a la lista</button><button class="btn pri" id="notif">Registrar notificación</button>', [[CAT.grupos[6], 'm-acc'], ['Accidentes', 'm-acc'], ['ACC-2026-015']]) +
    '<div class="grid g-main"><div class="stack"><div class="card">' + dl([['Lugar', 'Planta Ate – Línea de prensas 3'], ['Descripción', 'Al retirar una pieza atascada en la prensa N.° 3, el operador sufrió un corte en la mano izquierda con el borde de la lámina.'], ['Lesión', 'Corte en mano izquierda · 2 días de descanso'], ['Testigos', 'Liliana Vargas Huamán'], ['SCTR', chip('Vigente') + ' Pacífico Seguros']]) + '</div>' +
    '<div class="card"><div class="ch"><h3>Investigación · árbol de causas</h3><a class="small" href="#m-inv">Abrir investigación completa</a></div>' + arbolCausas() + '</div>' +
    '<div class="card"><h3>Acciones correctivas</h3>' + tabla([{ l: 'Acción', k: 't' }, { l: 'Responsable', k: 'r' }, { l: 'Vence', k: 'v' }, { l: 'Estado', f: (a) => chip(a.e) }], ACCIONES.filter((a) => /prensa|PETS/.test(a.t))) + '</div></div>' +
    '<div class="stack"><div class="card" style="border-color:var(--bad)"><h3>Plazo de notificación</h3><div class="countdown" id="cd" style="margin:8px 0">--:--:--</div><p class="small muted">Notificar al Ministerio de Trabajo dentro de ' + param(PLAZO_NOTIF_H + ' horas') + '. Se avisará a la Jefa SST cuando falten 6 horas.</p></div>' +
    '<div class="card"><h3>Seguimiento</h3><div class="timeline" style="margin-top:10px"><div class="ev">Evento registrado<span>Jorge Quispe · 07:55</span></div><div class="ev">Aviso enviado a Comité, Gerencia y médica<span>Sistema · 07:56</span></div><div class="ev">Atención en tópico<span>Verónica Palomino · 08:10</span></div><div class="ev">Investigación iniciada<span>Rocío Villanueva · 09:20</span></div></div></div></div></div>';
  $('#volver', root).onclick = volver;
  $('#notif', root).onclick = () => modal('Registrar notificación', campo('n-sat', 'N.° de constancia SAT', 'text', 'SAT-2026-0481273') + campo('n-f', 'Fecha y hora de notificación', 'text', '30/10/2026 10:05'), [{ l: 'Cancelar' }, { l: 'Guardar', cls: 'pri', fn: () => toast('Notificación registrada dentro del plazo') }]);
  cuentaRegresiva(root);
}
function arbolCausas() {
  return '<div class="tree"><div class="tnode"><div class="tb final">Corte en la mano izquierda del operador</div></div><div class="tchild">' +
    '<div class="tnode"><div class="tb hecho">Introdujo la mano en la zona de la matriz</div></div><div class="tchild"><div class="tnode"><div class="tb">La pieza se atascó y no había herramienta de extracción</div></div><div class="tnode"><div class="tb">Práctica habitual no corregida por la supervisión</div></div></div>' +
    '<div class="tnode"><div class="tb hecho">La prensa N.° 3 no tenía guarda fija</div></div><div class="tchild"><div class="tnode"><div class="tb">Guarda retirada en mantenimiento y no repuesta</div></div><div class="tnode"><div class="tb">El PETS de cambio de matrices no exige verificar guardas</div></div></div>' +
    '<div class="tnode"><div class="tb hecho">Guantes anticorte vencidos</div></div></div></div><p class="tiny muted" style="margin-top:8px">Los recuadros azules son hechos; los demás, antecedentes. El rojo es la lesión.</p>';
}
R.INV = (root) => {
  const inv = [['INV-2026-007', 'ACC-2026-015 · Corte en mano', 'Árbol de causas', 'Rocío Villanueva', 'En investigación'], ['INV-2026-006', 'ACC-2026-014 · Atrapamiento de dedo', 'Árbol de causas', 'Rocío Villanueva', 'Cerrada'], ['INV-2026-005', 'INC-2026-031 · Montacargas sin freno', 'SCAT', 'Gabriela Montoya', 'Cerrada']];
  root.innerHTML = encMod('INV', 'Investigación de causas con árbol de causas o SCAT, equipo investigador y acciones.', btnExport() + (S.addons.has('IAA') ? '<a class="btn" href="#m-iaa">' + ic('spark', 16) + 'Investigación guiada con IA</a>' : '')) +
    '<div class="grid g-main"><div class="card"><div class="ch"><h3>INV-2026-007 · Árbol de causas</h3>' + chip('En investigación') + '</div>' + arbolCausas() + (puedeEditar() ? '<button class="btn" id="add-c" style="margin-top:12px">' + ic('plus', 16) + 'Agregar causa</button>' : '') + '</div>' +
    '<div class="stack"><div class="card"><h3>Equipo investigador</h3><div class="list">' + [[1, 'Líder'], [2, 'Jefe de área'], [11, 'Representante de los trabajadores'], [15, 'Médica ocupacional']].map(([id, r]) => '<div class="li"><span class="av">' + iniciales(wById(id).nombre) + '</span><div class="lm"><b>' + esc(wById(id).nombre) + '</b><span>' + r + '</span></div></div>').join('') + '</div></div>' +
    '<div class="card"><h3>Causas por tipo</h3>' + dl([['Causas inmediatas', 'Acto: introducir la mano · Condición: falta de guarda'], ['Causas básicas', 'Factor de trabajo: procedimiento incompleto · Factor personal: hábito no corregido']]) + '</div></div></div>' +
    '<h3 style="margin:18px 0 10px">Todas las investigaciones</h3>' + tabla([{ l: 'Código', k: 0 }, { l: 'Evento', k: 1 }, { l: 'Método', k: 2 }, { l: 'Líder', k: 3 }, { l: 'Estado', f: (r) => chip(r[4]) }], inv);
  const a = $('#add-c', root); if (a) a.onclick = () => formModal('Agregar causa', [['Causa', 'text', ''], ['Depende de', 'select', 'La prensa N.° 3 no tenía guarda fija', ['Introdujo la mano en la zona de la matriz', 'La prensa N.° 3 no tenía guarda fija', 'Guantes anticorte vencidos']], ['Tipo', 'select', 'Antecedente', ['Hecho', 'Antecedente']]], 'Agregar', 'Causa agregada al árbol');
  bindExport(root);
};

/* ---------- Registros obligatorios ---------- */
const REGISTROS = [
  ['Accidentes de trabajo, enfermedades ocupacionales, incidentes peligrosos y otros incidentes', 'ACC'], ['Exámenes médicos ocupacionales', 'EMO'], ['Monitoreo de agentes físicos, químicos, biológicos, psicosociales y disergonómicos', 'MAG'],
  ['Inspecciones internas de seguridad y salud en el trabajo', 'INS'], ['Estadísticas de seguridad y salud en el trabajo', 'EST'], ['Equipos de seguridad o emergencia', 'EQE'],
  ['Inducción, capacitación, entrenamiento y simulacros de emergencia', 'CAP'], ['Auditorías', 'AUI'], ['Investigación y medidas correctivas (accidentes e incidentes)', 'INV'],
];
R.REG = (root) => {
  let sel = 3; let per = '2026 – octubre';
  const pint = () => {
    const filas = {
      0: [['ACC-2026-015', '30/10/2026', 'Accidente leve', 'Christian Valdivia Neyra', 'Corte en mano izquierda', '2'], ['ACC-2026-014', '22/09/2026', 'Incapacitante', 'Elmer Puma Sullca', 'Atrapamiento de dedo', '12']],
      3: [['INS-2026-224', '30/10/2026', 'Orden y limpieza', 'Planta Ate', 'Gabriela Montoya', '2 hallazgos'], ['INS-2026-223', '29/10/2026', 'Montacargas', 'Almacén Callao', 'Wilmer Ccahuana', 'Sin hallazgos'], ['INS-2026-221', '24/10/2026', 'Extintores', 'Planta Ate', 'Gabriela Montoya', '3 hallazgos']],
    }[sel] || [['—', '15/10/2026', 'Registro de ejemplo', 'Planta Ate', 'Rocío Villanueva', 'Conforme'], ['—', '22/10/2026', 'Registro de ejemplo', 'Almacén Callao', 'Gabriela Montoya', 'Conforme']];
    $('#reg-prev', root).innerHTML = '<div class="paper watermark" style="overflow-x:auto"><div class="ptit">REGISTRO DE ' + esc(REGISTROS[sel][0].toUpperCase()) + '</div>' +
      '<table><tbody><tr><th>Razón social</th><td>Industrias Andinas del Sur S.A.C.</td><th>RUC</th><td>20518472632</td></tr><tr><th>Domicilio</th><td>Av. Nicolás Ayllón 4820, Ate</td><th>Actividad</th><td>Fabricación de envases metálicos</td></tr><tr><th>N.° trabajadores</th><td>140</td><th>Periodo</th><td>' + esc(per) + '</td></tr></tbody></table>' +
      '<table style="margin-top:8px"><thead><tr><th>Código</th><th>Fecha</th><th>Tipo</th><th>Lugar / persona</th><th>Responsable / detalle</th><th>Resultado</th></tr></thead><tbody>' + filas.map((f) => '<tr>' + f.map((c) => '<td>' + esc(c) + '</td>').join('') + '</tr>').join('') + '</tbody></table>' +
      '<table style="margin-top:8px"><tbody><tr><th>Responsable del registro</th><td>Rocío Villanueva Tapia · Jefa SST</td><th>Firma</th><td>Firmado electrónicamente 30/10/2026</td></tr></tbody></table><p style="margin-top:6px;font-size:.66rem">Formato referencial R.M. 050-2013-TR.</p></div>';
  };
  root.innerHTML = encMod('REG', 'Los 9 registros obligatorios en formato R.M. 050-2013-TR, llenados con los datos del sistema.') +
    '<div class="grid" style="grid-template-columns:minmax(0,1fr) minmax(0,2fr)"><div class="card stack"><h3>1. Elige el registro</h3><div class="list" id="reg-list">' + REGISTROS.map((r, i) => '<button class="li" data-r="' + i + '" style="background:' + (i === sel ? 'var(--brand-soft)' : 'none') + ';border:0;border-bottom:1px solid var(--line);text-align:left;cursor:pointer;width:100%;padding:9px 8px;border-radius:6px"><span class="pill-plan">' + (i + 1) + '</span><div class="lm"><b style="font-weight:' + (i === sel ? 700 : 400) + '">' + esc(r[0]) + '</b></div></button>').join('') + '</div>' +
    '<h3>2. Elige el periodo</h3>' + campo('reg-per', 'Periodo', 'select', per, ['2026 – octubre', '2026 – septiembre', '2026 – tercer trimestre', '2026 – año completo', '2025 – año completo']) +
    '<div class="row"><button class="btn pri" id="pdf">' + ic('down', 16) + 'PDF</button><button class="btn" id="xls">' + ic('down', 16) + 'Excel</button></div></div>' +
    '<div class="stack" style="min-width:0"><h3>Vista previa</h3><div id="reg-prev"></div></div></div>';
  $$('[data-r]', root).forEach((b) => b.onclick = () => { sel = +b.dataset.r; $$('[data-r]', root).forEach((x) => { x.style.background = +x.dataset.r === sel ? 'var(--brand-soft)' : 'none'; $('b', x).style.fontWeight = +x.dataset.r === sel ? 700 : 400; }); pint(); });
  $('#reg-per', root).onchange = (e) => { per = e.target.value; pint(); };
  $('#pdf', root).onclick = () => toast('PDF del registro generado (simulado)');
  $('#xls', root).onclick = () => toast('Excel del registro generado (simulado)');
  pint();
};
