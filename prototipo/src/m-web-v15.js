/* Módulo WEB · Sitio público v1.5 (spec/brief-web-v15.md): portada con el lenguaje visual de Stitch (corregido),
   agenda pública con horarios libres reales, gestión de la reserva (reprogramar o cancelar), widget de chatbot,
   ventana emergente con intención de salida, campañas, integraciones, novedades, webinars, reseñas y calculadora de ahorro. */
const V15U = { chat: { open: false, conv: null, form: false }, rp: { dia: null, hora: null, abierto: false }, ah: null, wb: null };

/* Páginas nuevas con SEO editable */
[
  ['web-integraciones', 'Integraciones', 'Integraciones de EscudoSST · Estado de cada conexión', 'WhatsApp, Moodle, pagos, CRM, calendario, correo, almacenamiento e IA: qué está disponible, qué está en beta y qué está planificado.', '/integraciones', 'Integraciones'],
  ['web-novedades', 'Novedades del producto', 'Novedades de EscudoSST · Funciones nuevas y mejoras', 'Lo nuevo en EscudoSST: funciones, mejoras y correcciones por módulo.', '/novedades', 'Novedades'],
  ['web-webinars', 'Webinars', 'Webinars de EscudoSST · Seguridad y salud en el trabajo', 'Sesiones en vivo y grabadas sobre gestión SST en Perú. Inscríbete gratis.', '/webinars', 'Webinars'],
  ['web-calculadora-ahorro', 'Calculadora de ahorro', 'Calculadora de ahorro frente a Excel y papel · EscudoSST', 'Estima cuántas horas al mes podría ahorrar tu equipo SST al dejar el Excel y el papel. Estimación referencial con supuestos visibles.', '/herramientas/calculadora-ahorro', 'Herramienta'],
  ['web-demo', 'Gestionar mi demo', 'Gestiona tu demo · EscudoSST', 'Revisa, reprograma o cancela la demo que agendaste con EscudoSST.', '/demo/gestionar', 'Agenda'],
  ['web-l', 'Landings de campaña', 'Campaña · EscudoSST', 'Página de aterrizaje de campaña de EscudoSST.', '/l', 'Campaña (campaign)'],
].forEach(([k, n, t, d, slug, tipo]) => { WEB.paginas[k] = { n, tipo, estado: 'Publicado', seo: { t, d, slug, og: 'auto', noindex: k === 'web-demo' || k === 'web-l' }, editado: '01/11/2026' }; });
WX_REC_LINKS.splice(2, 0, ['web-calculadora-ahorro', 'piggy', 'Calculadora de ahorro', 'Horas ahorradas frente a Excel y papel']);
WX_REC_LINKS.push(['web-webinars', 'video', 'Webinars', 'En vivo y grabados'], ['web-novedades', 'spark', 'Novedades', 'Lo nuevo del producto'], ['web-integraciones', 'plug', 'Integraciones', 'Estado de cada conexión']);
WX_RUTAS.length = 0; WX_REC_LINKS.forEach((x) => WX_RUTAS.push(x[0])); WX_RUTAS.push('web-demo', 'web-l');

/* Orden de la portada según la composición de Stitch */
(function () { const orden = ['hero', 'confianza', 'videos', 'modulos', 'grupos', 'pasos', 'planes', 'herramientas', 'sectores', 'demo', 'casos', 'testimonios', 'resenas', 'productos', 'blog', 'faq', 'cta']; WEB.bloques.sort((a, b) => orden.indexOf(a.id) - orden.indexOf(b.id)); const m = WEB.bloques.find((b) => b.id === 'modulos'); if (m) m.l = 'Módulos (tarjetas con mini-indicador)'; })();

/* Eventos propios (site_events): solo con consentimiento analítico, sin datos personales */
function v15Evento(event_type, element_key, lead_id) {
  if (!WEB.cookies || !WEB.cookies.a) return;
  V15.ev.push({ occurred_at: wxAhora(), event_type, path: '/' + (WEB_RUTA[0] || 'web').replace(/^web-?/, ''), element_key: element_key || '', utm_source: WX.utm.source, utm_medium: WX.utm.medium, utm_campaign: WX.utm.campaign, session_hash: 'sh-' + HOY.getDate() + 'a9', lead_id: lead_id || '' });
}

/* ---------- Agenda: horarios libres según anfitriones, bloqueos y reuniones ---------- */
const minDe = (s) => { const [h, m] = s.split(':').map(Number); return h * 60 + m; };
function v15Ocupa(m) { const ini = m.ini.getTime(); return [ini, ini + (m.dur || V15.booking.duration_minutes) * 60000]; }
function v15HostLibre(h, ini, ignorar) {
  const B = V15.booking; const r = h.reglas[ini.getDay()];
  if (!h.is_active || !r) return false;
  const t = ini.getHours() * 60 + ini.getMinutes();
  if (t < minDe(r[0]) || t + B.duration_minutes > minDe(r[1])) return false;
  const a = ini.getTime(); const b = a + B.duration_minutes * 60000; const buf = B.buffer_minutes * 60000;
  if (V15.bloqueos.some((x) => (!x.host || x.host === h.id) && a < x.hasta.getTime() && b > x.desde.getTime())) return false;
  const delDia = V15.reuniones.filter((m) => m.host === h.id && m.id !== ignorar && !['cancelled'].includes(m.status) && mismoDia(m.ini, ini));
  if (delDia.length >= h.max_per_day) return false;
  return !delDia.some((m) => { const [x, y] = v15Ocupa(m); return a < y + buf && b > x - buf; });
}
const v15HostPara = (ini, ignorar, pref) => { const L = V15.hosts.filter((h) => v15HostLibre(h, ini, ignorar)); return L.find((h) => h.id === pref) || L[0] || null; };
function v15Horas() { const B = V15.booking; const out = []; for (let t = 9 * 60; t + B.duration_minutes <= 18 * 60; t += B.duration_minutes + B.buffer_minutes) out.push(String(Math.floor(t / 60)).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0')); return out; }
function v15Dias(n = 10) {
  const B = V15.booking; const out = []; const d = new Date(HOY.getFullYear(), HOY.getMonth(), HOY.getDate()); const lim = new Date(d); lim.setDate(lim.getDate() + B.max_days_ahead);
  while (out.length < n && d < lim) { d.setDate(d.getDate() + 1); if (v15SlotsDia(d).some((s) => s.host)) out.push(new Date(d)); }
  return out;
}
function v15SlotsDia(dia, ignorar) {
  const minIni = HOY.getTime() + V15.booking.min_notice_hours * 3600000;
  return v15Horas().map((h) => { const [hh, mm] = h.split(':').map(Number); const ini = new Date(dia.getFullYear(), dia.getMonth(), dia.getDate(), hh, mm); const trabaja = V15.hosts.some((x) => x.is_active && x.reglas[ini.getDay()] && ini.getHours() * 60 >= minDe(x.reglas[ini.getDay()][0]) - 0 && ini.getHours() * 60 + mm + V15.booking.duration_minutes <= minDe(x.reglas[ini.getDay()][1])); return { h, ini, trabaja, host: ini.getTime() >= minIni ? v15HostPara(ini, ignorar) : null }; }).filter((s) => s.trabaja);
}
function v15Selector(pref, est) {
  const dias = v15Dias();
  if (!V15.booking.public_booking_enabled) return '<p class="w-fine">La agenda pública está desactivada. Déjanos tus datos y te escribimos para coordinar.</p>';
  return '<div class="w-agsel"><div><h3 class="w-agt">Elige un día</h3><div class="w-days" role="radiogroup" aria-label="Día de la demo">' +
    dias.map((d, i) => '<button type="button" role="radio" aria-checked="' + (est.dia === i) + '" data-' + pref + 'dia="' + i + '"><small>' + DIAS_SEM[d.getDay()] + '</small><b>' + d.getDate() + '</b><small>' + MESES_C[d.getMonth()] + '</small></button>').join('') + '</div></div>' +
    '<div><h3 class="w-agt">Elige una hora <small>(hora de Lima)</small></h3><div class="w-hours" role="radiogroup" aria-label="Hora de la demo">' + (est.dia == null ? '<p class="w-fine">Primero elige un día.</p>' : v15SlotsDia(dias[est.dia], est.ignorar).map((s) => s.host ? '<button type="button" role="radio" aria-checked="' + (est.hora === s.h) + '" data-' + pref + 'hora="' + s.h + '">' + s.h + '</button>' : '<button type="button" disabled aria-disabled="true"><s>' + s.h + '</s><small>Ocupado</small></button>').join('')) + '</div><small class="w-err" id="' + pref + '-sel-e" role="alert"></small></div></div>';
}
const v15Cuando = (d) => DIAS_SEM[d.getDay()] + ' ' + fmtF(d) + ' · ' + hhmm(d);

agendaHTML = function () {
  const ag = WUI.ag || (WUI.ag = { dia: null, hora: null });
  return '<form class="w-form w-agenda" id="w-ag" novalidate>' + v15Selector('', ag).replace(/data-dia=/g, 'data-dia=').replace(/data-hora=/g, 'data-hora=').replace('id="-sel-e"', 'id="ag-sel-e"') +
    '<p class="w-fine s-durac">' + ic('clock', 14) + ' Duración configurada: ' + V15.booking.duration_minutes + ' min · reunión por ' + esc(PROV_MEET[V15.booking.default_meeting_provider]) + '. La reunión no se graba, salvo que lo autorices expresamente al inicio.</p>' +
    '<div class="w-fg">' + wf('ag-nom', 'Nombre y apellido', 'text', { req: true, ac: 'name' }) + wf('ag-cor', 'Correo de trabajo', 'email', { req: true, ac: 'email' }) + wf('ag-emp', 'Empresa', 'text', { req: true, ac: 'organization' }) + wf('ag-ruc', 'RUC', 'text', { req: true, im: 'numeric', hint: '11 dígitos' }) + '</div>' +
    '<div class="w-consent"><label for="ag-ok"><input type="checkbox" id="ag-ok" aria-describedby="ag-ok-e"><span>Acepto el uso de mis datos para coordinar la demo, según la <a href="#web-legal-privacidad">Política de privacidad</a>. <span class="w-req" aria-hidden="true">*</span></span></label><small class="w-err" id="ag-ok-e" role="alert"></small></div>' +
    '<div class="w-consent wx-mk"><label for="ag-mk"><input type="checkbox" id="ag-mk"><span>Quiero recibir comunicaciones comerciales de EscudoSST (opcional). Puedo retirar este permiso cuando quiera.</span></label></div>' +
    captcha('ag-cap') + '<small class="w-err" id="ag-cap-e" role="alert"></small><button type="submit" class="w-btn pri lg">' + ic('cal', 18) + 'Agendar la demo</button></form>';
};
/* Selector de día y hora reutilizable (agenda de la portada y reprogramación) */
function v15BindSel(cont, pref, est, html) {
  const pint = (foco) => { const n = document.createElement('div'); n.innerHTML = html(); $('.w-agsel', cont).replaceWith($('.w-agsel', n)); bind(); if (foco) { const b = $(foco, cont); if (b) b.focus(); } };
  const bind = () => {
    $$('[data-' + pref + 'dia]', cont).forEach((b) => b.addEventListener('click', () => { est.dia = +b.getAttribute('data-' + pref + 'dia'); est.hora = null; pint('[data-' + pref + 'dia="' + est.dia + '"]'); }));
    $$('[data-' + pref + 'hora]', cont).forEach((b) => b.addEventListener('click', () => { est.hora = b.getAttribute('data-' + pref + 'hora'); pint('[data-' + pref + 'hora="' + est.hora + '"]'); }));
  };
  bind();
}
function v15Reservar(d, datos, mk) {
  const host = v15HostPara(d);
  const id = 'DEM-0' + WEB.sigDemo++;
  const prov = V15.booking.default_meeting_provider;
  WEB.demos.unshift({ id, f: fmtF(HOY), nombre: datos.nombre, empresa: datos.empresa, ruc: datos.ruc, trab: '—', sector: '—', interes: 'Demo', estado: 'Demo agendada', resp: 'Sin asignar', agenda: v15Cuando(d), correo: datos.correo, mk: !!mk, origen: datos.origen || 'Agenda pública' });
  const m = { id: 'MTG-00' + V15.sigMtg++, demo: id, host: host ? host.id : '', ini: d, status: 'proposed', prov, url: meetUrl(prov), outcome: '', notas: '', rec: false, token: tok15() + tok15(), creado: wxAhora() };
  V15.reuniones.push(m);
  v15Encolar('demo_request', id, 'create'); v15Encolar('demo_meeting', m.id, 'create');
  v15Evento('demo_booked', 'agenda', '');
  V15.conv.demo[2]++;
  return { id, m };
}
bindAgenda = function (root) {
  const f = $('#w-ag', root); if (!f) return;
  bindCaptcha(root, 'ag-cap');
  v15BindSel(f, '', WUI.ag, () => v15Selector('', WUI.ag).replace('id="-sel-e"', 'id="ag-sel-e"'));
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    const v = (id) => ($('#' + id, root).value || '').trim();
    let ok = true;
    const selOk = WUI.ag.dia != null && WUI.ag.hora; $('#ag-sel-e', root).textContent = selOk ? '' : 'Elige un día y una hora.'; ok = selOk && ok;
    ok = errorCampo(root, 'ag-nom', v('ag-nom').length < 3 ? 'Escribe tu nombre y apellido.' : '') && ok;
    ok = errorCampo(root, 'ag-cor', !CORREO_RE.test(v('ag-cor')) ? 'Escribe un correo válido, por ejemplo nombre@empresa.pe.' : '') && ok;
    ok = errorCampo(root, 'ag-emp', !v('ag-emp') ? 'Escribe el nombre de tu empresa.' : '') && ok;
    ok = errorCampo(root, 'ag-ruc', !/^\d{11}$/.test(v('ag-ruc')) ? 'El RUC tiene 11 dígitos.' : !rucValido(v('ag-ruc')) ? 'Este RUC no es válido. Revisa los dígitos.' : '') && ok;
    ok = errorCampo(root, 'ag-ok', !$('#ag-ok', root).checked ? 'Necesitamos tu consentimiento para coordinar la demo.' : '') && ok;
    const cap = $('#ag-cap', root).classList.contains('ok'); $('#ag-cap-e', root).textContent = cap ? '' : 'Marca «No soy un robot».'; ok = cap && ok;
    if (!ok) { const p = $('[aria-invalid="true"], .w-captcha:not(.ok) input', root) || $('[data-dia]', root); if (p) p.focus(); toast('Revisa los campos marcados', 'warn'); return; }
    const dia = v15Dias()[WUI.ag.dia]; const [hh, mm] = WUI.ag.hora.split(':').map(Number);
    const d = new Date(dia.getFullYear(), dia.getMonth(), dia.getDate(), hh, mm);
    if (!v15HostPara(d)) { toast('Ese horario acaba de ocuparse. Elige otro.', 'warn'); WUI.ag.hora = null; renderWeb(); return; }
    const { id, m } = v15Reservar(d, { nombre: v('ag-nom'), empresa: v('ag-emp'), ruc: v('ag-ruc'), correo: v('ag-cor') }, $('#ag-mk', root).checked);
    WUI.ag = { dia: null, hora: null };
    f.outerHTML = v15Confirmacion(m, id, v('ag-cor'));
    v15BindConfirm(root);
  });
};
function v15Confirmacion(m, id, correo) {
  return '<div class="w-form w-done s-done" tabindex="-1" id="w-done"><span class="w-plate green">' + ic('check', 30) + '</span><h2>Tu demo quedó agendada</h2><p><b>' + esc(fLarga(m.ini)) + ', ' + hhmm(m.ini) + '</b> (hora de Lima). Enviamos la invitación a <b>' + esc(correo) + '</b>.</p>' +
    '<div class="s-meet"><span class="s-meeti">' + ic('video', 20) + '</span><div><small>Enlace de la reunión · ' + esc(PROV_MEET[m.prov]) + '</small><b class="num" id="ag-url">' + esc(m.url) + '</b><small>Un asesor confirmará la cita; te avisaremos 24 h y 1 h antes.</small></div></div>' +
    '<p class="w-fine">Código ' + id + '. En el prototipo no se envía ningún correo; la solicitud aparece en el calendario del panel.</p><div class="w-ctas"><a class="w-btn" id="ag-gest" href="#web-demo/' + m.token + '">' + ic('cal', 18) + 'Reprogramar o cancelar</a><button type="button" class="w-btn" id="ag-cal">' + ic('down', 18) + 'Agregar a mi calendario</button><button type="button" class="w-btn pri" data-demo-int>Ver la demo interactiva ahora</button></div></div>';
}
function v15BindConfirm(root) {
  const d = $('#w-done', root); if (d) d.focus();
  const c = $('#ag-cal', root); if (c) c.onclick = () => toast('Archivo de calendario (.ics) generado (simulado)');
  $$('[data-demo-int]', root).forEach((b) => b.addEventListener('click', demoInteractiva));
}

/* ---------- Gestionar la reserva: #web-demo/<token> ---------- */
const MTG_EST = { proposed: ['Por confirmar', 'warn'], confirmed: ['Confirmada', 'ok'], rescheduled: ['Reprogramada · por confirmar', 'warn'], cancelled: ['Cancelada', 'na'], completed: ['Realizada', 'ok'], no_show: ['No asistió', 'bad'] };
function v15Gestion(sub) {
  const m = V15.reuniones.find((x) => x.token && x.token === sub);
  if (!m) return wxHero([['Gestionar mi demo']], 'Agenda', 'No encontramos esa reserva', 'Usa el enlace del correo de confirmación. Si el enlace venció, agenda una nueva demo.', '<div class="w-ctas"><a class="w-btn pri" href="#web/demo">Agendar una demo</a></div>');
  const dem = WEB.demos.find((x) => x.id === m.demo) || {}; const h = V15.hosts.find((x) => x.id === m.host); const e = MTG_EST[m.status];
  const activa = !['cancelled', 'completed', 'no_show'].includes(m.status);
  return wxHero([['Gestionar mi demo']], 'Tu reserva', 'Demo de EscudoSST', 'Hola, ' + esc((dem.nombre || '').split(' ')[0]) + '. Aquí puedes revisar, reprogramar o cancelar tu demo.') +
    '<section class="w-sec w-tight"><div class="w-in w-narrow"><div class="s-card s-gest" id="dm-card"><div class="s-gesth"><span class="s-icot">' + ic('cal', 22) + '</span><div><h2 class="w-h3">' + esc(fLarga(m.ini)) + ' · ' + hhmm(m.ini) + '</h2><p class="w-fine">Hora de Lima · ' + V15.booking.duration_minutes + ' min configurados</p></div><span class="s-badge ' + e[1] + '" id="dm-est">' + esc(e[0]) + '</span></div>' +
    '<dl class="s-dl"><div><dt>Empresa</dt><dd>' + esc(dem.empresa || '—') + '</dd></div><div><dt>Reunión</dt><dd>' + esc(PROV_MEET[m.prov]) + '</dd></div><div><dt>Enlace</dt><dd><span class="num s-url">' + esc(m.url) + '</span>' + (m.status === 'confirmed' ? '' : '<br><small class="w-fine">Queda activo cuando el asesor confirme.</small>') + '</dd></div><div><dt>Anfitrión</dt><dd>' + (m.status === 'confirmed' && h ? esc(h.display_name) + ' · ' + esc(h.rol) + ' <span class="w-ejt">Ejemplo</span>' : 'Por asignar') + '</dd></div></dl>' +
    (activa ? '<div class="w-ctas"><button type="button" class="w-btn pri" id="dm-rp">' + ic('cal', 18) + 'Reprogramar</button><button type="button" class="w-btn" id="dm-cn">Cancelar la demo</button></div><div id="dm-rpw"' + (V15U.rp.abierto ? '' : ' hidden') + '>' + v15RpHTML(m) + '</div>' : '<p class="w-fine">Esta reserva ya no admite cambios. <a href="#web/demo">Agenda una nueva demo</a>.</p>') +
    '</div><p class="w-fine w-center">Enlace seguro de un solo uso para esta reserva. En el prototipo no se envía ningún correo.</p></div></section>';
}
const v15RpHTML = (m) => '<form class="w-form s-rp" id="dm-rpf" novalidate><h3 class="w-h3">Elige el nuevo horario</h3>' + v15Selector('rp', Object.assign(V15U.rp, { ignorar: m.id })).replace('id="rp-sel-e"', 'id="rp-sel-e"') + '<button type="submit" class="w-btn pri">Confirmar el nuevo horario</button></form>';
function v15BindGestion(root) {
  const m = V15.reuniones.find((x) => x.token && x.token === WEB_RUTA[1]); if (!m) return;
  const rp = $('#dm-rp', root); if (!rp) return;
  rp.onclick = () => { V15U.rp = { dia: null, hora: null, abierto: true }; renderWeb(); const b = $('[data-rpdia]'); if (b) b.focus(); };
  const f = $('#dm-rpf', root);
  if (f) {
    v15BindSel(f, 'rp', V15U.rp, () => v15Selector('rp', V15U.rp));
    f.addEventListener('submit', (e) => { e.preventDefault(); if (V15U.rp.dia == null || !V15U.rp.hora) { $('#rp-sel-e', root).textContent = 'Elige un día y una hora.'; return; }
      const dia = v15Dias()[V15U.rp.dia]; const [hh, mm] = V15U.rp.hora.split(':').map(Number); const d = new Date(dia.getFullYear(), dia.getMonth(), dia.getDate(), hh, mm);
      const h = v15HostPara(d, m.id, m.host); if (!h) { toast('Ese horario ya no está libre', 'warn'); return; }
      m.ini = d; m.host = h.id; m.status = 'rescheduled'; m.url = meetUrl(m.prov); const dem = WEB.demos.find((x) => x.id === m.demo); if (dem) dem.agenda = v15Cuando(d);
      v15Encolar('demo_meeting', m.id, 'update'); V15U.rp = { dia: null, hora: null, abierto: false }; toast('Demo reprogramada: ' + v15Cuando(d)); renderWeb(); });
  }
  $('#dm-cn', root).onclick = () => modal('Cancelar la demo', '<label class="field" for="dm-mot"><span>Motivo (opcional)</span><select id="dm-mot"><option>Ya no la necesito</option><option>Prefiero otra fecha más adelante</option><option>Otro motivo</option></select></label>', [{ l: 'Volver' }, { l: 'Cancelar la demo', cls: 'danger', fn: (ov) => { m.status = 'cancelled'; m.cancel_reason = $('#dm-mot', ov).value; v15Encolar('demo_meeting', m.id, 'update'); toast('Demo cancelada'); renderWeb(); } }]);
}

/* ---------- Portada con la composición de Stitch (corregida) ---------- */
const sh15 = (eyebrow, t, s, cls, aside) => '<div class="s-sh ' + (cls || '') + '"><div><p class="s-eyebrow">' + esc(eyebrow) + '</p><h2>' + esc(t) + '</h2>' + (s ? '<p class="s-sub">' + s + '</p>' : '') + '</div>' + (aside || '') + '</div>';
const REG8 = [['Accidentes e incidentes', 'ok'], ['Exámenes médicos', 'ok'], ['Monitoreo de agentes', 'warn'], ['Inspecciones internas', 'ok'], ['Estadísticas SST', 'ok'], ['Equipos de emergencia', 'ok'], ['Capacitación y simulacros', 'warn'], ['Auditorías', 'ok']];
function v15HeroCard() {
  return '<div class="s-hcard" aria-label="Ejemplo del Modo Inspección SUNAFIL"><div class="s-hcardh"><span class="s-stripe" aria-hidden="true"></span>' + ic('shield', 18) + '<b>Modo Inspección SUNAFIL</b><span class="s-tagej">Ejemplo</span></div><div class="s-hcardb">' +
    '<div class="s-hco"><span class="s-hcoi" aria-hidden="true">IA</span><div><b>Industrias Andinas del Sur S.A.C.</b><small>Empresa ficticia · Sede Planta Ate</small></div><span class="s-hcon">140 trabajadores</span></div>' +
    '<div class="s-hpct"><div><small class="s-lbl">Expediente de la inspección</small><p><b class="num">86 %</b> <span>de documentos al día</span></p><small class="s-ok">' + ic('check', 14) + ' Faltan 2 documentos por actualizar</small></div><div class="s-ring" style="--p:86" aria-hidden="true"><span>86%</span></div></div>' +
    '<div class="s-regh"><small>8 registros obligatorios</small><small><b>6 de 8</b> al día</small></div><div class="s-reg8">' + REG8.map(([t, c]) => '<span class="' + c + '">' + (c === 'ok' ? ic('check', 13) : ic('warn', 13)) + esc(t) + '</span>').join('') + '</div>' +
    '<div class="s-hcardf"><span>' + ic('qr', 18) + '<small>Enlace de solo lectura para el inspector, con vencimiento</small></span><a class="w-btn s-btnnavy" href="#web/m-sunafil">Ver cómo funciona</a></div></div></div>';
}
const MINI = {
  sunafil: () => '<div class="s-mini"><div class="s-minih"><small>Expediente</small><b class="s-g">86 %</b></div><i class="s-bar"><b style="width:86%"></b></i></div>',
  iperc: () => '<div class="s-mini"><div class="s-minih"><small>Riesgos por nivel</small><b class="s-r">2 intolerables</b></div><i class="s-risk"><b class="ok" style="flex:3"></b><b class="warn" style="flex:2"></b><b class="or" style="flex:1.4"></b><b class="bad" style="flex:.8"></b></i></div>',
  fotocheck: () => '<div class="s-mini s-minirow"><small>Personal</small><span class="s-badge ok">128 habilitados</span><span class="s-badge warn">6 por vencer</span></div>',
  reportes: () => '<div class="s-mini s-minirow">' + ic('chat', 16) + '<small><b>Reporte R-0413</b> recibido por WhatsApp</small><span class="s-badge ok">Nuevo</span></div>',
  aula: () => '<div class="s-mini"><div class="s-minih"><small>Trabajos en caliente</small><b>62 %</b></div><i class="s-bar"><b style="width:62%;background:#1F7FD1"></b></i></div>',
  consultora: () => '<div class="s-mini s-minirow"><small>Empresas cliente</small><span class="s-badge info">4 activas</span><span class="s-badge warn">1 con alertas</span></div>',
  offline: () => '<div class="s-mini s-minirow">' + ic('wifi', 16) + '<small>Sin conexión</small><span class="s-badge warn">3 en cola</span></div>',
  ia: () => '<div class="s-mini s-minirow">' + ic('spark', 16) + '<small>Sugerencias</small><span class="s-badge info">3 por revisar</span></div>',
};
function v15SectorTiles() {
  const ref = { construccion: 'D.S. 011-2019-TR y G.050', mineria: 'D.S. 024-2016-EM', manufactura: 'Ley 29783 y su reglamento', agroindustria: 'Ley 29783 y su reglamento', oficinas: 'R.M. 375-2008-TR' };
  return '<div class="s-sects">' + Object.entries(WEB_SECTORES).map(([k, s]) => '<a class="s-sect" href="#web-sector-' + k + '"><span class="s-icot">' + ic(s.ic, 24) + '</span><b>' + esc(s.n) + '</b><small>' + esc(ref[k] || 'Ley 29783') + '</small></a>').join('') + '</div><p class="w-fine w-center">Normas de referencia por sector, a validar por especialista SST.</p>';
}
wxBloqueHerramientas = function () {
  const T = [['web-calculadora', 'calc', 'amber', 'Calculadora de multas SUNAFIL', 'Rango referencial en UIT y soles según tu empresa y la gravedad.', 'Calcular mi multa'], ['web-autodiagnostico', 'quiz', 'blue', 'Autodiagnóstico Ley 29783', 'Responde por secciones y conoce el nivel de tu sistema de gestión.', 'Hacer el autodiagnóstico'], ['web-calculadora-ahorro', 'piggy', 'teal', 'Calculadora de ahorro', 'Horas que podrías ahorrar frente a Excel y papel, con supuestos visibles.', 'Estimar mi ahorro'], ['web-recursos', 'dl', 'green', 'Plantillas y formatos SST', 'IPERC, matriz de capacitación, registros y checklists para descargar.', 'Ver la biblioteca'], ['web-webinars', 'video', 'navy', 'Webinars', 'Sesiones en vivo y grabadas sobre gestión SST.', 'Ver los webinars'], ['web-boletin', 'news', 'red', 'Boletín normativo', 'Cambios de normativa y novedades de SUNAFIL, una vez al mes.', 'Suscribirme gratis']];
  return '<section class="w-sec wx-tools" id="ws-herramientas"><div class="w-in">' + sh15('Herramientas gratuitas', 'Herramientas gratuitas para el profesional SST', 'Úsalas sin registrarte. Los valores legales son referenciales y se confirman con un especialista.', 's-shrow', '<div class="w-ctas wx-toolcta"><a class="w-btn amb" href="#web-calculadora">' + ic('calc', 18) + 'Calcular multa</a><a class="w-btn pri" href="#web-autodiagnostico">' + ic('quiz', 18) + 'Autodiagnóstico</a></div>') +
    '<div class="s-g3">' + T.map(([r, i, c, t, d, l]) => '<a class="s-card s-tool" href="#' + r + '"><span class="w-plate ' + c + '">' + ic(i, 24) + '</span><h3>' + esc(t) + '</h3><p>' + esc(d) + '</p><span class="s-more">' + esc(l) + ic('right', 16) + '</span></a>').join('') + '</div></div></section>';
};
function v15Resenas() {
  const R = V15.resenas; const L = R.snaps.filter((s) => s.rating >= R.min_rating).slice(0, R.max_to_show);
  if (!L.length) return '';
  const est = (n) => '<span class="s-stars" aria-label="' + n + ' de 5 estrellas">' + [1, 2, 3, 4, 5].map((k) => '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="' + IC.starf + '" fill="' + (k <= n ? '#F4B90F' : 'none') + '" stroke="#C98A00" stroke-width="1.4"/></svg>').join('') + '</span>';
  return '<section class="w-sec" id="ws-resenas"><div class="w-in">' + sh15('Reseñas', 'Lo que dicen en Google', 'Reseñas del perfil de Google Business de EscudoSST, sin editar. <b>En el prototipo son de ejemplo.</b>', 's-shrow', '<span class="s-gattr">' + ic('globe', 16) + 'Reseñas de Google</span>') +
    '<div class="s-g3">' + L.map((s) => '<figure class="s-card s-rev"><div class="s-revh">' + est(s.rating) + '<span class="w-ejt">Ejemplo</span></div><blockquote>' + esc(s.body) + '</blockquote><figcaption><b>' + esc(s.autor) + '</b><small>' + esc(s.f) + ' · <span class="s-gsrc">Reseña de Google</span></small></figcaption></figure>').join('') + '</div><p class="w-fine w-center">Se muestran las reseñas desde ' + R.min_rating + ' estrellas, hasta ' + R.max_to_show + ', según el filtro del panel; no se eligen ni se editan a mano.</p></div></section>';
}
wPortada = function () {
  const a = WEB.ajustes; const B = {};
  B.hero = '<section class="w-hero s-hero"><div class="w-in s-herog"><div class="s-herot"><p class="s-pill">' + ic('shield', 15) + 'Pensado para la Ley 29783 y su reglamento</p><h1>' + esc(a.lema).replace('SUNAFIL', '<span class="s-acc">SUNAFIL</span>') + '</h1><p class="w-lead">' + esc(a.heroSub) + '</p>' +
    '<ul class="s-vent">' + a.ventajas.filter(Boolean).map((x) => '<li><span class="s-chk">' + ic('check', 15) + '</span><span>' + esc(x) + '</span></li>').join('') + '</ul>' +
    '<div class="w-ctas"><a class="w-btn pri lg" href="#registro">Prueba gratis' + ic('right', 18) + '</a><a class="w-btn lg" href="#web/demo">' + ic('play', 18) + 'Agenda una demo guiada</a></div><p class="w-fine s-herofine">' + ic('user', 15) + ' Regístrate con tu RUC. ¿Cuánto arriesgas? <a href="#web-calculadora">Calcula tu multa</a> o <a href="#web-autodiagnostico">haz el autodiagnóstico</a>.</p></div>' +
    '<div class="s-heroi">' + v15HeroCard() + '</div></div></section>';
  B.confianza = '<section class="s-trust" aria-label="Sellos de confianza"><div class="w-in"><ul class="s-g4">' +
    [['shield', 'Pensado para la Ley 29783', 'Módulos alineados al sistema de gestión SST y sus registros.'], ['lock', 'Datos según la Ley 29733', 'Datos médicos separados, con acceso restringido y registrado.'], ['cloud', 'Datos cifrados en la nube', 'Cifrado en tránsito y en reposo, con copias de seguridad.'], ['user', 'Soporte desde Perú', 'Atención en español, en horario de oficina.']].map(([i, t, d]) => '<li class="s-seal"><span class="s-icot navy">' + ic(i, 22) + '</span><span><b>' + esc(t) + '</b><small>' + esc(d) + '</small></span></li>').join('') + '</ul></div></section>';
  const vids = WEB.videos.filter((v) => v.on);
  B.videos = vids.length ? '<section class="w-sec s-band" id="ws-videos"><div class="w-in">' + sh15('Demostración', 'Mira EscudoSST en acción', 'Una presentación general y un tutorial paso a paso.', 's-shc') + '<div class="s-g2">' + vids.map((v, i) => '<figure class="s-card s-vid">' + videoThumb(v, i) + '<figcaption><b>' + esc(v.t) + '</b><span>' + esc(v.d) + '</span><button type="button" class="s-link" data-video="' + v.id + '-b">Ver video ' + ic('right', 15) + '</button></figcaption></figure>').join('') + '</div></div></section>' : '';
  B.modulos = '<section class="w-sec" id="ws-modulos"><div class="w-in">' + sh15('Cumplimiento integral', 'Módulos pensados para el sistema de gestión SST', 'Los módulos que más diferencian a EscudoSST. Las cifras de las tarjetas son ilustrativas.', 's-shrow', '<a class="s-link" href="#web-producto">Ver los ' + MODS.filter((m) => m.plan !== 'I').length + ' módulos' + ic('right', 16) + '</a>') +
    '<div class="s-g4 s-mods">' + BLOQUES.map((b, i) => { const m = modBy(b.code); return '<article class="s-card s-mod" id="ws-m-' + b.id + '"><span class="w-plate ' + ['blue', 'red', 'green', 'amber', 'navy', 'teal', 'navy', 'orange'][i] + '">' + ic(b.ic, 22) + '</span><p class="s-eyebrow">Módulo ' + String(i + 1).padStart(2, '0') + ' · ' + esc(m.plan === '+' ? 'Complemento' : m.plan === 'K' ? 'Plan Consultora' : 'Desde ' + m.plan_nombre) + '</p><h3>' + esc(b.corto) + '</h3><p>' + esc(b.d) + '</p>' + (MINI[b.id] ? MINI[b.id]() : '') +
      '<div class="s-modb"><button type="button" class="w-btn s-soft" data-vdemo="' + i + '">Ver demo' + ic('right', 15) + '</button><button type="button" class="w-btn ghost" data-mk="' + i + '">Ver pantalla</button></div></article>'; }).join('') + '</div></div></section>';
  B.grupos = '<section class="w-sec s-band"><div class="w-in">' + sh15('Todo el sistema', 'Todo el sistema de gestión, en ' + MODS.filter((m) => m.plan !== 'I').length + ' módulos', 'Activa lo que necesitas según tu plan. Los módulos se agrupan como trabaja un área SST.') + '<div class="w-grupos">' +
    Object.entries(CAT.grupos).map(([g, gn]) => { const ms = MODS.filter((m) => m.grupo === +g && m.plan !== 'I'); return '<a class="w-grupo" href="#web-producto"><span class="w-gi">' + ic(GRUPO_IC[g], 22) + '</span><span class="w-gt"><b>' + esc(gn) + '</b><small>' + ms.length + ' módulos</small></span><span class="w-gm">' + ms.slice(0, 3).map((m) => esc(m.nombre)).join(', ') + (ms.length > 3 ? ' y ' + (ms.length - 3) + ' más' : '') + '</span></a>'; }).join('') + '</div></div></section>';
  B.pasos = '<section class="w-sec" id="ws-como-funciona"><div class="w-in">' + sh15('Cómo funciona', 'Cómo funciona EscudoSST', 'Del registro al expediente, en cuatro pasos acompañados.', 's-shc') + '<ol class="s-g4 s-pasos">' +
    [['building', 'Regístrate con tu RUC', 'Completa la razón social y la dirección con tu RUC, elige tu plan y crea tu cuenta.'], ['people', 'Carga tu personal', 'Importa a tus trabajadores, sedes y puestos desde Excel, con validación por fila.'], ['risk', 'Configura tu sistema SST', 'Haz la línea base y aplica las plantillas de tu sector.'], ['shield', 'Gestiona y llega preparado', 'Sigue el plan anual, recibe alertas antes de cada vencimiento y genera el expediente.']].map(([i, t, d], k) => '<li class="s-card s-paso"><div class="s-pasoh"><span class="s-num ' + (k === 3 ? 'g' : '') + '">' + (k + 1) + '</span>' + ic(i, 24) + '</div><h3>' + esc(t) + '</h3><p>' + esc(d) + '</p></li>').join('') + '</ol></div></section>';
  B.planes = '<section class="w-sec s-band" id="ws-planes"><div class="w-in">' + sh15('Planes', 'Planes para cada tamaño de empresa', 'Planes acumulativos: cada uno incluye todo lo del anterior. Rangos de trabajadores referenciales.', 's-shc') + '<div class="w-center s-perw">' + periodoSel(WUI.periodo) + '</div>' + planCards(WUI.periodo) + '<p class="w-center"><a class="w-btn" href="#web-planes">Comparar módulo por módulo</a></p></div></section>';
  B.sectores = '<section class="w-sec"><div class="w-in">' + sh15('Sectores', 'Adaptado a los sectores de mayor exigencia', 'Los paquetes sectoriales traen plantillas, peligros y formatos de cada actividad.', 's-shc') + v15SectorTiles() + '</div></section>';
  B.demo = '<section class="w-sec s-band" id="ws-demo"><div class="w-in"><div class="s-demog"><div class="s-card s-demoa"><p class="s-eyebrow">Atención personalizada</p><h2>Agenda una demostración guiada</h2><p class="s-sub">Un asesor te muestra la plataforma con un caso de tu sector. Elige un día y una hora libres.</p>' + agendaHTML() + '</div>' +
    '<div class="s-demob"><p class="s-pill dark">' + ic('play', 15) + 'Demo interactiva</p><h2>Prueba la demo interactiva</h2><p>Entra al prototipo navegable como Jefa SST de una empresa ficticia, con datos de ejemplo.</p><ul>' +
    [['grid', 'Datos de ejemplo precargados', 'Una empresa ficticia con trabajadores, actas, capacitaciones e inspecciones.'], ['shield', 'Modo Inspección SUNAFIL', 'Autodiagnóstico, expediente y vista del inspector.'], ['qr', 'Reporte por QR', 'Cómo llega un reporte de condición insegura a la bandeja.']].map(([i, t, d]) => '<li><span>' + ic(i, 20) + '</span><span><b>' + esc(t) + '</b><small>' + esc(d) + '</small></span></li>').join('') +
    '</ul><button type="button" class="w-btn amb lg w-full" data-demo-int>' + ic('play', 18) + 'Ver demo interactiva</button><p class="s-fine2">Sin registrarte · datos ficticios</p></div></div></div></section>';
  const tes = WEB.testimonios.filter((t) => t.estado === 'Aprobado');
  B.testimonios = tes.length ? '<section class="w-sec s-band"><div class="w-in">' + sh15('Comentarios', 'Lo que dicen quienes lo usan', 'Comentarios de ejemplo para la demostración.', 's-shc') + '<div class="s-g3">' + tes.map((t) => '<figure class="s-card s-test"><div class="s-revh"><span class="w-ejt">Ejemplo</span></div><blockquote>' + esc(t.txt) + '</blockquote><figcaption><span class="w-tav" aria-hidden="true">' + esc(t.ini) + '</span><span><b>' + esc(t.cargo) + '</b><small>' + esc(t.emp) + '</small></span></figcaption></figure>').join('') + '</div></div></section>' : '';
  B.resenas = v15Resenas();
  B.blog = a.blogPortada ? '<section class="w-sec"><div class="w-in">' + sh15('Blog', 'Blog y guías SST', 'Guías prácticas desde nuestro blog en WordPress.', 's-shrow', '<a class="w-btn" href="' + blogHref() + '" target="_blank" rel="noopener">Ir al blog' + ic('ext', 16) + '<span class="sr"> (se abre en otra pestaña)</span></a>') + '<div class="s-g3">' +
    WEB.ultimos.map((p, i) => '<a class="s-card s-post" href="' + blogHref() + '" target="_blank" rel="noopener"><div class="w-thumb t-' + (i + 1) + '" aria-hidden="true">' + ic(['shield', 'risk', 'heart'][i], 36) + '</div><div class="s-postb"><span class="w-pcat">' + esc(p.cat) + '</span><h3>' + esc(p.t) + '</h3><p>' + esc(p.ex) + '</p><time>' + esc(p.f) + '</time></div></a>').join('') + '</div><p class="w-fine w-center">Los artículos y sus fechas se leen del WordPress (' + esc(a.blogUrl || 'URL por definir') + '). En el prototipo son de ejemplo.</p></div></section>' : '';
  const prods = WEB.productos.filter((p) => p.on);
  B.productos = prods.length ? '<section class="w-sec s-band" id="ws-productos"><div class="w-in">' + sh15('Ecosistema', 'Otros productos de ' + a.razon, 'Más soluciones del mismo equipo. Las descripciones son provisionales y editables.', 's-shc') + '<div class="w-prods s-prods">' + prods.map((p) => '<div class="s-card w-prod"><span class="w-plogo" style="background:' + esc(p.color) + '" aria-hidden="true">' + (p.logo ? '<img src="' + p.logo + '" alt="">' : esc(p.ini)) + '</span><div><span class="w-ejt">Descripción editable</span><h3>' + esc(p.n) + '</h3><p>' + esc(p.d) + '</p>' + (p.url ? '<a href="' + esc(p.url) + '" target="_blank" rel="noopener">Conocer ' + esc(p.n) + '<span class="sr"> (se abre en otra pestaña)</span></a>' : '<span class="w-pend">Enlace por definir</span>') + '</div></div>').join('') + '</div></div></section>' : '';
  B.faq = '<section class="w-sec"><div class="w-in s-faqw">' + sh15('Preguntas frecuentes', 'Preguntas frecuentes', '¿Tienes otra duda? <a href="#web-contacto/contacto">Escríbenos</a> o pregúntale al asistente.', 's-shc') + wFaq(WEB.faqs.filter((f) => f.p === 'portada')) + '</div></section>';
  B.herramientas = wxBloqueHerramientas();
  B.casos = wxBloqueCasos();
  B.cta = wCTA();
  return WEB.bloques.filter((b) => b.on).map((b) => B[b.id] || '').join('');
};
wCTA = function (t, s) {
  return '<section class="s-ctaf"><span class="s-stripe-top" aria-hidden="true"></span><div class="w-in s-ctag"><div><p class="s-pill dark amb">' + ic('warn', 15) + 'Prepárate antes de la inspección</p><h2>' + esc(t || 'Llega preparado a tu próxima inspección') + '</h2><p>' + esc(s || 'Regístrate con el RUC de tu empresa o agenda una demo con un caso de tu sector.') + '</p></div><div class="w-ctas"><a class="w-btn pri lg" href="#registro">Prueba gratis' + ic('right', 18) + '</a><a class="w-btn lg s-btnwhite" href="#web/demo">' + ic('cal', 18) + 'Agenda una demo</a></div></div></section>';
};

/* ---------- Chatbot del sitio ---------- */
const norm15 = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
function v15EnHorario() { const c = V15.chat; if (!c.simularHorario) return false; const d = HOY; const t = d.getHours() * 60 + d.getMinutes(); return c.hours.dias.includes(d.getDay()) && t >= minDe(c.hours.desde) && t < minDe(c.hours.hasta); }
function v15Responder(txt) {
  const n = norm15(txt); const pub = V15.intents.filter((i) => i.status === 'published');
  const it = pub.find((i) => i.phrases.some((p) => n.includes(norm15(p))));
  if (it) { it.usos++; return { body: it.answer, intent: it.id, cta: it.cta, url: it.cta_url }; }
  if (V15.chat.mode === 'ai') {
    const pal = n.split(/\W+/).filter((w) => w.length > 3);
    const fuentes = WEB.faqs.map((f) => ({ t: f.q, a: f.a })).concat(WX.articulos.filter((a) => a.status === 'published').map((a) => ({ t: a.title, a: a.body[0] })));
    const best = fuentes.map((f) => ({ f, s: pal.filter((w) => norm15(f.t + ' ' + f.a).includes(w)).length })).sort((a, b) => b.s - a.s)[0];
    if (best && best.s) return { body: best.f.a + ' (Fuente: «' + best.f.t + '»)', intent: '', ai: true, cta: 'none' };
  }
  return { body: 'No tengo esa respuesta. Puedo ayudarte con una demo, precios, multas SUNAFIL o pasarte con una persona.', intent: '', cta: 'menu' };
}
function v15ChatHTML() {
  const c = V15.chat; const u = V15U.chat; const conv = u.conv; const enH = v15EnHorario();
  if (!c.enabled) return '';
  const msgs = conv ? conv.msgs : [];
  const ctaBtn = (m) => { const k = m[3]; if (!k || k === 'none') return ''; const L = { demo: [['demo', 'Agendar una demo'], ['lead', 'Que me escriba un asesor']], whatsapp: [['wa', 'Abrir WhatsApp']], calculator: [['go:#web-calculadora', 'Abrir la calculadora']], assessment: [['go:#web-autodiagnostico', 'Hacer el autodiagnóstico']], link: [['go:' + (m[4] || '#web'), 'Ver más']], menu: [['demo', 'Agendar una demo'], ['wa', 'Hablar por WhatsApp'], ['go:#web-contacto/contacto', 'Escribir un mensaje']] }[k] || []; return '<div class="cb-ctas">' + L.map(([a, l]) => '<button type="button" class="cb-cta" data-cba="' + esc(a) + '">' + esc(l) + '</button>').join('') + '</div>'; };
  return '<div class="cb' + (u.open ? ' open' : '') + '" id="cb"><button type="button" class="cb-fab" id="cb-fab" aria-expanded="' + u.open + '" aria-controls="cb-panel" aria-label="' + (u.open ? 'Cerrar el asistente' : 'Abrir el asistente') + '">' + ic(u.open ? 'x' : 'bot', 24) + '</button>' +
    '<section class="cb-panel" id="cb-panel" role="dialog" aria-label="Asistente de EscudoSST"' + (u.open ? '' : ' hidden') + '><header class="cb-h"><span class="cb-av">' + ic('bot', 20) + '</span><div><b>Asistente de EscudoSST</b><small><i class="' + (enH ? 'on' : '') + '"></i>' + (enH ? 'En horario de atención' : 'Fuera de horario') + (c.mode === 'ai' ? ' · IA con contenido publicado' : ' · respuestas guiadas') + '</small></div><button type="button" class="cb-x" id="cb-x" aria-label="Cerrar el asistente">' + ic('x', 18) + '</button></header>' +
    '<div class="cb-log" id="cb-log" aria-live="polite"><div class="cb-m bot"><p>' + esc(c.greeting) + '</p></div>' + (enH ? '' : '<div class="cb-m bot"><p>' + esc(c.offline_message) + '</p></div>') +
    msgs.map((m) => '<div class="cb-m ' + (m[0] === 'visitor' ? 'me' : 'bot') + '"><p>' + esc(m[1]) + '</p>' + (m[0] === 'bot' ? ctaBtn(m) : '') + '</div>').join('') +
    (u.form ? '<form class="cb-form" id="cb-form" novalidate><b>Déjanos tus datos</b>' + ['cb-nom|Nombre y apellido|text', 'cb-cor|Correo de trabajo|email', 'cb-emp|Empresa|text'].map((x) => { const [id, l, t] = x.split('|'); return '<label for="' + id + '"><span>' + l + (id !== 'cb-emp' ? ' *' : '') + '</span><input id="' + id + '" type="' + t + '"></label>'; }).join('') +
      '<label class="cb-ck" for="cb-ok"><input type="checkbox" id="cb-ok"><span>Acepto el uso de mis datos para que me contacten, según la <a href="#web-legal-privacidad">Política de privacidad</a>. *</span></label><label class="cb-ck" for="cb-mk"><input type="checkbox" id="cb-mk"><span>Quiero recibir comunicaciones comerciales (opcional).</span></label><small class="cb-err" id="cb-err" role="alert"></small><button type="submit" class="w-btn pri">Enviar</button></form>' : '') +
    '</div><div class="cb-quick">' + V15.intents.filter((i) => i.status === 'published').slice(0, 4).map((i) => '<button type="button" data-cbq="' + i.id + '">' + esc(i.name) + '</button>').join('') + '</div>' +
    '<form class="cb-in" id="cb-in"><label for="cb-txt" class="sr">Escribe tu pregunta</label><input id="cb-txt" type="text" placeholder="Escribe tu pregunta…" autocomplete="off"><button type="submit" aria-label="Enviar">' + ic('right', 18) + '</button></form><p class="cb-fine">No compartas datos médicos por este chat. <a href="#web-legal-privacidad">Privacidad</a></p></section></div>';
}
function v15ChatPint() { const w = $('#cb'); if (!w) return; const y = $('#cb-log') ? $('#cb-log').scrollTop : 0; w.outerHTML = v15ChatHTML(); v15ChatBind(); const l = $('#cb-log'); if (l) l.scrollTop = l.scrollHeight || y; }
function v15Conv() {
  const u = V15U.chat;
  if (!u.conv) { u.conv = { id: 'CHT-0' + V15.sigConv++, hash: 's' + tok15().slice(0, 3) + '…' + tok15().slice(0, 3), ini: wxAhora(), path: '/' + (WEB_RUTA[0] || 'web').replace(/^web-?/, ''), lead: '', handoff: 'none', msgs: [] }; V15.convs.unshift(u.conv); }
  return u.conv;
}
function v15ChatEnviar(txt, intentId) {
  const conv = v15Conv(); const it = intentId ? V15.intents.find((i) => i.id === intentId) : null;
  const r = it ? (it.usos++, { body: it.answer, intent: it.id, cta: it.cta, url: it.cta_url }) : v15Responder(txt);
  conv.msgs.push(['visitor', txt, r.intent]); conv.msgs.push(['bot', r.body, r.intent, r.cta, r.url, r.ai]);
  const n = conv.msgs.filter((m) => m[0] === 'visitor').length;
  if (!conv.lead && n === V15.chat.ask_lead_after_messages && r.cta !== 'demo') conv.msgs.push(['bot', '¿Quieres que un asesor te escriba? Déjanos tus datos.', '', 'demo']);
  v15ChatPint();
}
function v15ChatBind() {
  const u = V15U.chat; const tog = () => { u.open = !u.open; if (u.open) v15Evento('chatbot_open', 'widget'); v15ChatPint(); const t = $(u.open ? '#cb-txt' : '#cb-fab'); if (t) t.focus(); };
  const fab = $('#cb-fab'); if (!fab) return; fab.onclick = tog; const x = $('#cb-x'); if (x) x.onclick = tog;
  const fi = $('#cb-in'); if (fi) fi.onsubmit = (e) => { e.preventDefault(); const t = $('#cb-txt').value.trim(); if (!t) return; v15ChatEnviar(t); const n = $('#cb-txt'); if (n) n.focus(); };
  $$('[data-cbq]').forEach((b) => b.addEventListener('click', () => v15ChatEnviar(b.textContent, b.dataset.cbq)));
  $$('[data-cba]').forEach((b) => b.addEventListener('click', () => {
    const a = b.dataset.cba; const conv = v15Conv();
    if (a === 'demo' || a === 'lead') { u.form = true; u.formDemo = a === 'demo'; v15ChatPint(); const n = $('#cb-nom'); if (n) n.focus(); return; }
    if (a === 'wa') { conv.handoff = 'whatsapp'; v15Evento('whatsapp_click', 'chatbot'); abrirWa(WEB_RUTA[0]); return; }
    if (a.startsWith('go:')) { u.open = false; location.hash = a.slice(4); }
  }));
  const f = $('#cb-form');
  if (f) f.onsubmit = (e) => {
    e.preventDefault(); const v = (id) => $('#' + id).value.trim();
    const err = v('cb-nom').length < 3 ? 'Escribe tu nombre y apellido.' : !CORREO_RE.test(v('cb-cor')) ? 'Escribe un correo válido.' : !$('#cb-ok').checked ? 'Necesitamos tu consentimiento para contactarte.' : '';
    if (err) { $('#cb-err').textContent = err; return; }
    const conv = v15Conv();
    const l = wxLead('chatbot', { full_name: v('cb-nom'), email: v('cb-cor'), company_name: v('cb-emp'), marketing: $('#cb-mk').checked }, [wxAhora(), 'Lead desde el chatbot (' + conv.id + ')' + (u.formDemo ? ', quiere una demo' : '')]);
    conv.lead = l.id; conv.handoff = 'form'; u.form = false; V15.conv.chatbot[2]++; v15Evento('chatbot_lead', 'form', l.id);
    conv.msgs.push(['bot', 'Listo, ' + v('cb-nom').split(' ')[0] + '. Registramos tus datos (' + l.id + ').' + (u.formDemo ? ' Elige ahora un día y una hora libres para tu demo.' : ' Un asesor te escribirá.'), '', u.formDemo ? 'link' : 'none', '#web/demo']);
    toast('Lead registrado desde el chatbot: ' + l.id); v15ChatPint();
  };
}

/* ---------- Ventana emergente con intención de salida ---------- */
const POP_EXCL = (r) => r === 'web-reclamaciones' || r.startsWith('web-legal-') || r === 'web-contacto' || r === 'web-l' || r === 'web-demo' || r === 'web-partners' || r === 'web-boletin';
const POP_OFERTA = { fine_calculator: '#web-calculadora', savings_calculator: '#web-calculadora-ahorro', self_assessment: '#web-autodiagnostico', resource: '#web-recursos', demo: '#web/demo', newsletter: '#web-boletin', webinar: '#web-webinars' };
function v15Salida() {
  if (!document.body.classList.contains('is-web') || $('.ov') || V15.popupVisto) return false;
  const r = WEB_RUTA[0] || 'web'; if (POP_EXCL(r)) return false;
  const p = V15.popups.find((x) => x.status === 'active' && x.trigger_type === 'exit_intent' && x.devices !== 'mobile'); if (!p) return false;
  V15.popupVisto = true; p.vistas++; v15Evento('popup_view', p.id);
  modal(p.title, '<div class="s-pop"><span class="w-plate amber">' + ic('calc', 26) + '</span><p>' + esc(p.body) + '</p><p class="tiny muted">Se muestra como máximo una vez por visita. Puedes cerrarla con Esc.</p></div>', [{ l: 'Ahora no', fn: () => v15Evento('popup_close', p.id) }, { l: p.cta_label, cls: 'pri', fn: () => { p.clics++; V15.conv.popup[2]++; v15Evento('popup_click', p.id); location.hash = POP_OFERTA[p.offer_type].slice(1); } }]);
  const ov = $('.ov'); if (ov) ov.classList.add('s-popov');
  return true;
}
document.addEventListener('mouseout', (e) => { if (!e.relatedTarget && e.clientY <= 0) v15Salida(); });

/* ---------- Páginas nuevas ---------- */
const AVAIL = { available: ['Disponible', 'ok'], beta: ['Beta', 'warn'], planned: ['Planificada', 'na'] };
const CAT_INT = { messaging: 'Mensajería', email: 'Correo', storage: 'Almacenamiento', lms: 'Aula virtual', payments: 'Pagos', crm: 'CRM', calendar: 'Calendario', ai: 'Inteligencia artificial', analytics: 'Analítica' };
const CAT_INT_IC = { messaging: 'chat', email: 'news', storage: 'cloud', lms: 'book', payments: 'card', crm: 'people', calendar: 'cal', ai: 'spark', analytics: 'chart' };
function v15Integraciones() {
  const L = V15.integraciones.filter((i) => i.status === 'published');
  return wxHero([['Recursos', 'web-recursos'], ['Integraciones']], 'Integraciones', 'Integraciones de EscudoSST', 'Con qué se conecta EscudoSST y en qué estado está cada conexión. Solo marcamos «Disponible» lo que ya funciona.') +
    '<section class="w-sec w-tight"><div class="w-in"><div class="s-legend">' + Object.values(AVAIL).map(([l, c]) => '<span class="s-badge ' + c + '">' + l + '</span>').join('') + '<small>Disponible: en uso · Beta: en pruebas con clientes · Planificada: aún no disponible.</small></div><div class="s-g3">' +
    L.map((i) => '<article class="s-card s-int"><div class="s-inth"><span class="s-icot">' + ic(CAT_INT_IC[i.category] || 'plug', 22) + '</span><span class="s-badge ' + AVAIL[i.availability][1] + '">' + AVAIL[i.availability][0] + '</span></div><h2 class="w-h3">' + esc(i.name) + '</h2><p>' + esc(i.description) + '</p><small class="w-fine">' + esc(CAT_INT[i.category]) + '</small></article>').join('') + '</div></div></section>' + wCTA('¿Necesitas otra integración?', 'Cuéntanos qué sistema usas y lo evaluamos con tu equipo.');
}
const NOV_CAT = { new: ['Nuevo', 'info'], improvement: ['Mejora', 'ok'], fix: ['Corrección', 'na'] };
const v15NovPub = () => V15.novedades.filter((n) => n.status === 'published');
function v15Novedades() {
  return wxHero([['Recursos', 'web-recursos'], ['Novedades']], 'Novedades', 'Novedades del producto', 'Funciones nuevas, mejoras y correcciones de EscudoSST. También las ves dentro de la app, en «Novedades».') +
    '<section class="w-sec w-tight"><div class="w-in w-narrow"><ol class="s-nov">' + v15NovPub().map((n) => '<li class="s-card" id="nov-' + esc(n.slug) + '"><div class="s-novh"><span class="s-badge ' + NOV_CAT[n.category][1] + '">' + NOV_CAT[n.category][0] + '</span><time>' + esc(n.published_at) + '</time></div><h2 class="w-h3">' + esc(n.title) + '</h2><p>' + esc(n.body) + '</p><p class="s-mods2">' + n.modules.map((c) => { const m = modBy(c); return '<span>' + esc(m ? m.nombre : c) + '</span>'; }).join('') + '</p></li>').join('') + '</ol><p class="w-center"><a class="w-btn" href="#web-boletin">' + ic('news', 18) + 'Recibir las novedades por correo</a></p></div></section>';
}
const WB_PROV = { meet: 'Google Meet', teams: 'Microsoft Teams', zoom: 'Zoom', other: 'Otra plataforma' };
function v15Webinars() {
  const prox = V15.webinars.filter((w) => ['published', 'live'].includes(w.status)); const grab = V15.webinars.filter((w) => w.status === 'finished' && w.recording_public && w.informados);
  const libres = (w) => w.capacity - w.ins.filter((x) => x.status !== 'cancelled').length;
  return wxHero([['Recursos', 'web-recursos'], ['Webinars']], 'Webinars', 'Webinars sobre gestión SST', 'Sesiones gratuitas en vivo con casos prácticos. Inscríbete y te enviamos el enlace y los recordatorios.') +
    '<section class="w-sec w-tight"><div class="w-in"><h2 class="w-h2s">Próximos</h2><div class="s-g2">' + prox.map((w) => { const n = libres(w); return '<article class="s-card s-web" id="wb-' + w.slug + '"><div class="s-webh"><span class="s-date"><b>' + w.starts_at.getDate() + '</b><small>' + MESES_C[w.starts_at.getMonth()] + '</small></span><div><h3>' + esc(w.title) + '</h3><p class="w-fine">' + esc(fLarga(w.starts_at)) + ' · ' + hhmm(w.starts_at) + ' (Lima) · ' + esc(WB_PROV[w.meeting_provider]) + '</p></div></div><p>' + esc(w.description) + '</p><p class="w-fine">' + ic('user', 14) + ' ' + esc(w.speaker_name) + ' · ' + esc(w.speaker_title) + '</p>' +
      '<div class="s-webf"><span class="s-badge ' + (n > 0 ? (n <= 3 ? 'warn' : 'ok') : 'bad') + '">' + (n > 0 ? n + ' cupos libres' : 'Sin cupos') + '</span>' + (n > 0 ? '<button type="button" class="w-btn pri" data-wbi="' + w.slug + '">Inscribirme</button>' : '<span class="w-fine">Avísame si se libera un cupo: <a href="#web-boletin">suscríbete</a></span>') + '</div>' +
      (V15U.wb && V15U.wb.slug === w.slug ? '<div id="wb-fw">' + (V15U.wb.ok ? wxDone('Inscripción confirmada', 'Te enviamos el enlace a <b>' + esc(V15U.wb.ok) + '</b> y te recordaremos 24 h y 1 h antes. Puedes cancelar con el enlace del correo.') : '<form class="w-form" id="wb-f" novalidate>' + wxCamposLead('wb', { empReq: true }) + '<button type="submit" class="w-btn pri">Confirmar inscripción</button></form>') + '</div>' : '') + '</article>'; }).join('') + '</div>' +
    '<h2 class="w-h2s" style="margin-top:40px">Grabados</h2>' + (grab.length ? '<div class="s-g3">' + grab.map((w) => '<article class="s-card s-web"><button type="button" class="wx-vid" data-wbv="' + w.slug + '"><span class="w-play" aria-hidden="true">' + ic('play', 22) + '</span><span><b>' + esc(w.title) + '</b><small>Grabación · ' + esc(fmtF(w.starts_at)) + '</small></span></button><p class="w-fine">Publicada con aviso previo al ponente y a los asistentes.</p></article>').join('') + '</div>' : '<p class="w-fine">Aún no hay grabaciones publicadas.</p>') + '</div></section>';
}
function v15BindWebinars(root) {
  $$('[data-wbi]', root).forEach((b) => b.addEventListener('click', () => { V15U.wb = { slug: b.dataset.wbi, ok: null }; renderWeb(); const n = $('#wb-nom'); if (n) n.focus(); }));
  $$('[data-wbv]', root).forEach((b) => b.addEventListener('click', () => toast('Grabación del webinar (reproductor simulado)')));
  const f = $('#wb-f', root); if (!f) return; bindCaptcha(root, 'wb-cap');
  f.addEventListener('submit', (e) => { e.preventDefault(); const d = wxValidarLead(root, 'wb', { empReq: true }); if (!d) return; const w = V15.webinars.find((x) => x.slug === V15U.wb.slug);
    if (w.ins.some((x) => x.email.toLowerCase() === d.email.toLowerCase() && x.status !== 'cancelled')) { toast('Ese correo ya está inscrito en este webinar', 'warn'); return; }
    if (w.capacity - w.ins.filter((x) => x.status !== 'cancelled').length <= 0) { toast('Ya no quedan cupos', 'warn'); return; }
    const l = wxLead('webinar', d, [wxAhora(), 'Inscripción al webinar «' + w.title + '»']); w.ins.push({ lead: l.id, nombre: d.full_name, email: d.email, empresa: d.company_name, status: 'registered', registered_at: wxAhora(), token: tok15() });
    V15.conv.webinar[2]++; v15Evento('webinar_registered', w.slug, l.id); v15Encolar('marketing_lead', l.id, 'update'); V15U.wb.ok = d.email; renderWeb(); focoDone(root); });
}
/* Calculadora de ahorro frente a Excel y papel */
function v15AhorroCalc(x) {
  const S = V15.ahorro.sup; const horasReg = x.registros * S.horasRegistro; const horasRep = x.sedes * S.horasReportePorSede; const actual = horasReg + horasRep + x.horasSemana * 4.33;
  const ahorro = actual * S.reduccion / 100; const costo = x.costoHora !== '' && x.costoHora != null ? ahorro * Number(x.costoHora) : S.costoHora !== '' ? ahorro * Number(S.costoHora) : null;
  return { actual: Math.round(actual), ahorro: Math.round(ahorro), costo };
}
function v15Ahorro() {
  const A = V15.ahorro; const S = A.sup; const r = V15U.ah;
  const campos = [['ah-t', 'Trabajadores', 140], ['ah-s', 'Sedes', 3], ['ah-r', 'Registros y formatos al mes', 120], ['ah-h', 'Horas por semana en Excel y papel', 10]];
  return wxHero([['Recursos', 'web-recursos'], ['Calculadora de ahorro']], 'Herramienta gratuita', 'Calculadora de ahorro frente a Excel y papel', 'Estima cuántas horas al mes podría ahorrar tu equipo SST. Es una <b>estimación referencial, no garantizada</b>.') +
    '<section class="w-sec w-tight"><div class="w-in"><div class="s-ahg"><form class="s-card w-form" id="ah-f" novalidate><h2 class="w-h3">Tu situación actual</h2><div class="w-fg">' + campos.map(([id, l, v]) => '<div class="w-f"><label for="' + id + '">' + l + '</label><input id="' + id + '" type="number" min="0" inputmode="numeric" value="' + (r ? r.x[id] : v) + '"></div>').join('') +
    '<div class="w-f full"><label for="ah-c">Costo por hora de tu equipo (S/, opcional)</label><input id="ah-c" type="number" min="0" inputmode="decimal" placeholder="Vacío: solo horas" value="' + (r && r.x['ah-c'] !== '' ? r.x['ah-c'] : '') + '"><small class="w-hint">Si no lo indicas, mostramos solo horas.</small></div></div><button type="submit" class="w-btn pri lg">' + ic('calc', 18) + 'Calcular mi ahorro</button></form>' +
    '<div class="s-card s-ahr" id="ah-res" aria-live="polite">' + (r ? '<p class="s-eyebrow">Resultado referencial</p><p class="s-big"><b class="num">' + r.res.ahorro + ' h</b> <span>al mes que podrías ahorrar</span></p><p>De unas <b>' + r.res.actual + ' h</b> al mes dedicadas hoy a registros, reportes y Excel.</p>' + (r.res.costo != null ? '<p class="s-big2"><b class="num">' + wxSoles(r.res.costo) + '</b> al mes en tiempo del equipo</p>' : '<p class="w-fine">Indica el costo por hora si quieres verlo en soles.</p>') +
      '<p class="s-disc">' + ic('info', 16) + esc(A.disclaimer) + (A.to_validate ? ' Supuestos por validar.' : '') + '</p>' + (r.enviado ? wxDone('Te enviamos el detalle', 'Revisa <b>' + esc(r.enviado) + '</b>. En el prototipo no se envía ningún correo.') : '<form class="w-form" id="ahl-f" novalidate><h3 class="w-h3">Recibe el detalle por correo</h3>' + wxCamposLead('ahl', { empReq: true }) + '<button type="submit" class="w-btn pri">Enviarme el detalle</button></form>')
      : '<p class="s-eyebrow">Resultado</p><p class="w-fine">Completa tus datos y calcula. El resultado aparece aquí.</p>') + '</div></div>' +
    '<details class="s-card s-sup" open><summary><b>Supuestos que usamos</b> (visibles y editables por EscudoSST)</summary><ul><li>' + S.horasRegistro + ' h por cada registro o formato llenado y archivado a mano.</li><li>' + S.horasReportePorSede + ' h al mes por sede para consolidar reportes e indicadores.</li><li>Reducción estimada del ' + S.reduccion + ' % de ese tiempo al usar la plataforma.</li><li>Costo por hora: ' + (S.costoHora === '' ? 'no se asume; lo indicas tú' : 'S/ ' + esc(S.costoHora)) + '.</li></ul></details></div></section>';
}
function v15BindAhorro(root) {
  $('#ah-f', root).addEventListener('submit', (e) => { e.preventDefault(); const x = {}; ['ah-t', 'ah-s', 'ah-r', 'ah-h', 'ah-c'].forEach((id) => { x[id] = $('#' + id, root).value; });
    if (['ah-t', 'ah-s', 'ah-r', 'ah-h'].some((k) => !(Number(x[k]) >= 0) || x[k] === '')) { toast('Completa los cuatro datos con números', 'warn'); return; }
    const res = v15AhorroCalc({ trabajadores: +x['ah-t'], sedes: +x['ah-s'], registros: +x['ah-r'], horasSemana: +x['ah-h'], costoHora: x['ah-c'] });
    V15U.ah = { x, res, enviado: null }; v15Evento('savings_calculated', 'calc'); V15.conv.ahorro[2]++; renderWeb(); const n = $('#ah-res'); if (n) n.scrollIntoView({ block: 'nearest' }); });
  const f = $('#ahl-f', root); if (!f) return; bindCaptcha(root, 'ahl-cap');
  f.addEventListener('submit', (e) => { e.preventDefault(); const d = wxValidarLead(root, 'ahl', { empReq: true }); if (!d) return; const r = V15U.ah;
    const l = wxLead('savings_calculator', d, [wxAhora(), 'Calculadora de ahorro: ' + r.res.ahorro + ' h/mes estimadas']);
    V15.ahorro.calculos.unshift({ lead: l.id, f: wxAhora(), inputs: { trabajadores: r.x['ah-t'], sedes: r.x['ah-s'], registros: r.x['ah-r'], horas: r.x['ah-h'], costo: r.x['ah-c'] }, sup: JSON.parse(JSON.stringify(V15.ahorro.sup)), horas: r.res.ahorro, costo: r.res.costo, idem: wxIdem() });
    r.enviado = d.email; renderWeb(); focoDone(root); });
}
/* Landing de campaña: #web-l/<slug> (noindex, UTM de la campaña) */
const CANAL = { google_ads: 'Google Ads', meta_ads: 'Meta Ads', linkedin: 'LinkedIn', email: 'Correo', other: 'Otro' };
const FORM_T = { demo: 'Solicitud de demo', quote: 'Cotización', contact: 'Contacto', resource: 'Recurso', assessment: 'Autodiagnóstico', calculator: 'Calculadora' };
function v15Landing(sub) {
  const c = V15.campanas.find((x) => x.slug === sub);
  if (!c || c.status !== 'active') return { html: wxHero([['Campaña']], 'Campaña', 'Esta campaña no está vigente', 'Puede que haya terminado. Conoce EscudoSST en la portada.', '<div class="w-ctas"><a class="w-btn pri" href="#web">Ir a la portada</a></div>'), extra: { t: 'Campaña no vigente · EscudoSST', d: 'La campaña no está vigente.' } };
  if (V15U.lastL !== c.slug) { c.visitas++; V15U.lastL = c.slug; WX.utm = { source: c.utm_source, medium: c.utm_medium, campaign: c.utm_campaign }; v15Evento('campaign_view', c.slug); }
  const ok = V15U.lOk && V15U.lOk.slug === c.slug;
  return { html: '<section class="s-lhero"><div class="w-in s-lg"><div><p class="s-pill dark">' + ic('megaphone', 15) + 'Campaña · ' + esc(CANAL[c.channel]) + '</p><h1>' + esc(c.h) + '</h1><p class="w-lead">' + esc(c.sub) + '</p><ul class="s-vent dark">' + WEB.ajustes.ventajas.map((x) => '<li><span class="s-chk">' + ic('check', 15) + '</span><span>' + esc(x) + '</span></li>').join('') + '</ul><p class="s-utm">UTM: <code>' + esc(c.utm_source + ' / ' + c.utm_medium + ' / ' + c.utm_campaign) + '</code></p></div>' +
    '<div class="s-card">' + (ok ? wxDone('¡Gracias!', 'Recibimos tu solicitud (' + esc(V15U.lOk.lead) + '). ' + (c.form_type === 'demo' ? 'Elige ahora un día y una hora: <a href="#web/demo">abrir la agenda</a>.' : 'Te escribiremos pronto.')) : '<form class="w-form" id="cl-f" novalidate><h2 class="w-h3">' + esc(FORM_T[c.form_type] || 'Contacto') + '</h2>' + wxCamposLead('cl', { empReq: true, ruc: true }) + '<button type="submit" class="w-btn pri lg w-full">' + (c.form_type === 'demo' ? 'Quiero mi demo' : 'Enviar') + '</button></form>') + '</div></div></section>' +
    (B_REG8()), extra: { t: c.h + ' · EscudoSST', d: c.sub }, mount: (root) => { const f = $('#cl-f', root); if (!f) return; bindCaptcha(root, 'cl-cap'); f.addEventListener('submit', (e) => { e.preventDefault(); const d = wxValidarLead(root, 'cl', { empReq: true }); if (!d) return; const l = wxLead('campaign', d, [wxAhora(), 'Landing de campaña «' + c.name + '» (' + FORM_T[c.form_type] + ')']); c.leads++; V15U.lOk = { slug: c.slug, lead: l.id }; renderWeb(); focoDone(root); }); } };
}
const B_REG8 = () => '<section class="w-sec"><div class="w-in">' + sh15('Por qué EscudoSST', 'Todo tu sistema SST en un solo lugar', '', 's-shc') + '<div class="s-g3">' + BLOQUES.slice(0, 3).map((b) => '<div class="s-card s-mod"><span class="w-plate blue">' + ic(b.ic, 22) + '</span><h3>' + esc(b.corto) + '</h3><p>' + esc(b.d) + '</p></div>').join('') + '</div></div></section>';

/* Enrutado de las páginas nuevas */
const _wxRuta0 = wxRuta;
wxRuta = function (ruta, sub) {
  const F = {
    'web-integraciones': () => ({ html: v15Integraciones() }),
    'web-novedades': () => ({ html: v15Novedades() }),
    'web-webinars': () => ({ html: v15Webinars(), mount: v15BindWebinars }),
    'web-calculadora-ahorro': () => ({ html: v15Ahorro(), mount: v15BindAhorro }),
    'web-demo': () => ({ html: v15Gestion(sub), mount: v15BindGestion }),
    'web-l': () => v15Landing(sub),
  };
  return F[ruta] ? F[ruta]() : _wxRuta0(ruta, sub);
};
/* Montaje común de cada vista pública: chatbot, botones nuevos, eventos */
const _web0 = R.__web;
R.__web = function (root, ruta, sub) {
  if (ruta !== 'web-demo') V15U.rp.abierto = false;
  _web0(root, ruta, sub);
  const w = $('#w-root', root);
  if (w) { w.insertAdjacentHTML('beforeend', v15ChatHTML()); v15ChatBind(); }
  if (ruta === 'web-l' || ruta === 'web-demo') metaTag('name', 'robots', 'noindex, follow');
  $$('[data-mk]', root).forEach((b) => b.addEventListener('click', () => { const bl = BLOQUES[+b.dataset.mk]; modal(bl.t, '<div class="s-mkm">' + mk(bl.mk) + '</div><p class="small muted" style="margin-top:10px">Maqueta dibujada de la pantalla del sistema, con datos de ejemplo.</p>', [{ l: 'Cerrar' }, { l: 'Ver demo', cls: 'pri', fn: () => { document.body.classList.remove('is-web'); restaurarSeo(); irComo(bl.demo[0], bl.demo[1]); } }], { wide: true }); }));
  $$('[data-video$="-b"]', root).forEach((b) => { b.addEventListener('click', (e) => { e.stopImmediatePropagation(); abrirVideo(b.dataset.video.slice(0, -2)); }, true); });
  const wa = $('#w-wa', root); if (wa) wa.addEventListener('click', () => { V15.conv.whatsapp[2]++; v15Evento('whatsapp_click', ruta); });
  v15Evento('page_view', ruta);
};
