/* Módulo WEB · Sitio público: 9 extras de captación (spec/brief-web-extras.md).
   Calculadora de multas, autodiagnóstico, recursos, casos, comparativa, centro de ayuda, partners, boletín,
   centro de confianza y estado del servicio. Lee y escribe WX (m-web-extras-datos.js) en memoria. */
Object.assign(IC, {
  calc: 'M6 2h12a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM8 6h8v3H8zM8 13h.01M12 13h.01M16 13h.01M8 17h.01M12 17h.01M16 17h.01',
  help: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM9.1 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4.5M12 17.5h.01',
  hand: 'M7 11l3-3 3 1 3-3 5 5-6 6a2 2 0 0 1-2.8 0L7 11zM3 9l4-4 3 3M14 15l-2-2M12 17l-2-2',
  news: 'M4 4h13v16H6a2 2 0 0 1-2-2zM17 8h3v10a2 2 0 0 1-2 2M7 8h7M7 12h7M7 16h4',
  pulse: 'M3 12h4l3-8 4 16 3-8h4',
  compare: 'M4 6h7M4 12h7M4 18h7M15 6l2 2 4-4M15 14l6 6M21 14l-6 6',
  dl: 'M12 3v12M7 10l5 5 5-5M5 21h14',
  check2: 'M9 12l2 2 4-4M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z',
});
const WXU = { ad: { paso: 0, resp: {}, fin: false, enviado: null }, cal: null, calEnv: null, recT: '', recS: '', recQ: '', bo: null, boEmail: '', baja: null, pa: null, ayVoto: {} };

/* Enlaces del desplegable «Recursos» (menú, cajón móvil y pie) */
const WX_REC_LINKS = [
  ['web-calculadora', 'calc', 'Calculadora de multas', 'Rango referencial en UIT y soles'],
  ['web-autodiagnostico', 'quiz', 'Autodiagnóstico Ley 29783', 'Puntaje por sección e informe'],
  ['web-recursos', 'dl', 'Biblioteca de recursos', 'Plantillas y formatos SST'],
  ['web-casos', 'award', 'Casos de éxito', 'Reto, solución y resultados'],
  ['web-comparativa', 'compare', 'EscudoSST vs Excel y papel', 'La diferencia, tema por tema'],
  ['web-ayuda', 'help', 'Centro de ayuda', 'Guías y videos paso a paso'],
  ['web-boletin', 'news', 'Boletín normativo', 'Normativa y SUNAFIL en tu correo'],
  ['web-partners', 'hand', 'Partners y referidos', 'Para consultoras e independientes'],
  ['web-confianza', 'lock', 'Centro de confianza', 'Seguridad y datos personales'],
  ['web-estado', 'pulse', 'Estado del servicio', 'Disponibilidad e incidentes'],
];
const WX_RUTAS = WX_REC_LINKS.map((x) => x[0]);
const RECURSOS_LINKS = () => WX_REC_LINKS.map(([r, i, t, s]) => '<a href="#' + r + '">' + ic(i, 18) + '<span><b>' + esc(t) + '</b><small>' + esc(s) + '</small></span></a>').join('');

/* ---------- Piezas comunes de los formularios de captación ---------- */
const wxMk = (p) => '<div class="w-consent wx-mk"><label for="' + p + '-mk"><input type="checkbox" id="' + p + '-mk"><span>Quiero recibir comunicaciones comerciales de EscudoSST (opcional). Puedo retirar este permiso cuando quiera.</span></label></div>';
function wxCamposLead(p, opt = {}) {
  return '<div class="w-fg">' + wf(p + '-nom', 'Nombre y apellido', 'text', { req: !opt.nomOpc, ac: 'name' }) + wf(p + '-cor', 'Correo de trabajo', 'email', { req: true, ac: 'email' }) +
    (opt.empresa === false ? '' : wf(p + '-emp', 'Empresa', 'text', { req: !!opt.empReq, ac: 'organization' })) + (opt.ruc ? wf(p + '-ruc', 'RUC', 'text', { im: 'numeric', hint: '11 dígitos' }) : '') + (opt.extra || '') + '</div>' +
    consent(p + '-ok', esc(opt.okTxt || 'Acepto que EscudoSST use mis datos para enviarme lo solicitado') + ', según la <a href="#web-legal-privacidad">Política de privacidad</a>.') + wxMk(p) + captcha(p + '-cap') + '<small class="w-err" id="' + p + '-cap-e" role="alert"></small>';
}
/* Valida los campos comunes y devuelve los datos, o null si hay errores */
function wxValidarLead(root, p, opt = {}) {
  const v = (id) => { const e = $('#' + id, root); return e ? (e.value || '').trim() : ''; };
  let ok = true;
  if ($('#' + p + '-nom', root)) ok = errorCampo(root, p + '-nom', !opt.nomOpc && v(p + '-nom').length < 3 ? 'Escribe tu nombre y apellido.' : '') && ok;
  ok = errorCampo(root, p + '-cor', !CORREO_RE.test(v(p + '-cor')) ? 'Escribe un correo válido, por ejemplo nombre@empresa.pe.' : '') && ok;
  if ($('#' + p + '-emp', root)) ok = errorCampo(root, p + '-emp', opt.empReq && !v(p + '-emp') ? 'Escribe el nombre de tu empresa.' : '') && ok;
  if ($('#' + p + '-ruc', root)) ok = errorCampo(root, p + '-ruc', v(p + '-ruc') && !rucValido(v(p + '-ruc')) ? 'Este RUC no es válido. Revisa los dígitos.' : '') && ok;
  if (opt.mas) ok = opt.mas(v) && ok;
  ok = errorCampo(root, p + '-ok', !$('#' + p + '-ok', root).checked ? 'Necesitamos tu consentimiento para enviarte lo solicitado.' : '') && ok;
  const cap = $('#' + p + '-cap', root).classList.contains('ok');
  $('#' + p + '-cap-e', root).textContent = cap ? '' : 'Marca «No soy un robot».';
  if (!(ok && cap)) { const f = $('[aria-invalid="true"], .w-captcha:not(.ok) input', root); if (f) f.focus(); toast('Revisa los campos marcados', 'warn'); return null; }
  return { full_name: v(p + '-nom'), email: v(p + '-cor'), company_name: v(p + '-emp'), ruc: v(p + '-ruc'), marketing: $('#' + p + '-mk', root).checked };
}
const wxHero = (migas, kick, h, lead, extra) => '<section class="w-phero wx-hero"><div class="w-in">' + wMigas(migas) + (kick ? '<p class="w-kick">' + esc(kick) + '</p>' : '') + '<h1>' + esc(h) + '</h1><p class="w-lead">' + lead + '</p>' + (extra || '') + '</div></section>';
const wxDone = (titulo, txt, extra) => '<div class="w-form w-done wx-done" tabindex="-1" id="wx-done"><span class="w-plate green">' + ic('check', 30) + '</span><h2>' + esc(titulo) + '</h2><p>' + txt + '</p>' + (extra || '') + '</div>';
const wxMigasRec = (l) => [['Recursos', 'web-recursos'], [l]];
const focoDone = (root) => { const d = $('#wx-done', root); if (d) d.focus(); };

/* ---------- Portada: bloques nuevos ---------- */
function wxBloqueHerramientas() {
  const tarjetas = [['web-calculadora', 'calc', 'amber', 'Calculadora de multas SUNAFIL', 'Estima un rango referencial en UIT y soles según tu empresa y la gravedad.', 'Calcular mi multa'], ['web-autodiagnostico', 'quiz', 'blue', 'Autodiagnóstico Ley 29783', 'Responde por secciones y conoce el nivel de tu sistema de gestión.', 'Hacer el autodiagnóstico'], ['web-recursos', 'dl', 'green', 'Plantillas y formatos SST', 'IPERC, matriz de capacitación, registros y checklists para descargar.', 'Ver la biblioteca'], ['web-boletin', 'news', 'teal', 'Boletín normativo', 'Cambios de normativa y novedades de SUNAFIL, una vez al mes.', 'Suscribirme gratis']];
  return '<section class="w-sec wx-tools" id="ws-herramientas"><div class="w-in"><div class="w-sh w-shrow"><div><h2>Herramientas gratuitas</h2><p>Úsalas sin registrarte. Los valores legales son referenciales y se confirman con un especialista.</p></div><div class="w-ctas wx-toolcta"><a class="w-btn amb lg" href="#web-calculadora">' + ic('calc', 18) + 'Calcular multa</a><a class="w-btn pri lg" href="#web-autodiagnostico">' + ic('quiz', 18) + 'Autodiagnóstico</a></div></div>' +
    '<div class="wx-toolg">' + tarjetas.map(([r, i, c, t, d, l]) => '<a class="wx-tool" href="#' + r + '"><span class="w-plate ' + c + '">' + ic(i, 24) + '</span><h3>' + esc(t) + '</h3><p>' + esc(d) + '</p><span class="wx-more">' + esc(l) + ic('right', 16) + '</span></a>').join('') + '</div></div></section>';
}
function wxBloqueCasos() {
  const L = WX.casos.filter((c) => c.status === 'published').slice(0, 3);
  if (!L.length) return '';
  return '<section class="w-sec"><div class="w-in"><div class="w-sh w-shrow"><div><h2>Casos de éxito</h2><p>Historias de ejemplo mientras publicamos casos reales con autorización de cada cliente.</p></div><a class="w-btn" href="#web-casos">Ver todos los casos</a></div><div class="wx-casos3">' +
    L.map((c) => '<a class="wx-caso-m" href="#web-casos"><span class="wx-clogo" style="background:' + esc(c.color) + '" aria-hidden="true">' + esc(c.ini) + '</span><span class="w-ejt">Ejemplo</span><h3>' + esc(c.client_name) + '</h3><p>' + esc(c.results[0]) + '</p><small>' + esc(c.sector) + '</small></a>').join('') + '</div></div></section>';
}

/* ---------- 1. Calculadora de multas SUNAFIL ---------- */
function wxTramo(tam, n) {
  const t = WX.tablas[tam].tramos; let idx = 0;
  t.forEach((l, i) => { const lo = parseInt(l, 10); if (n >= lo) idx = i; });
  return idx;
}
function wxCalcular(tam, n, sev, inf) {
  const tb = WX.tablas[tam]; const i = wxTramo(tam, n);
  const max = tb.valores[sev][i]; const disc = wxParam('web.fine_correction_discount_pct').v; const uit = wxParam('web.uit_value').v;
  const min = Math.round(max * (1 - disc / 100) * 1000) / 1000;
  return { company_size: tam, affected_workers: n, severity: sev, infraction_code: inf || '', uit_value: uit, result_min_uit: min, result_max_uit: max, result_min_pen: min * uit, result_max_pen: max * uit, tramo: tb.tramos[i],
    parameters_snapshot_json: { 'web.uit_value': uit, [tb.key]: tb.valores[sev][i], 'web.fine_correction_discount_pct': disc, vig: tb.vig, to_confirm: true } };
}
function wxCalculadora() {
  const r = WXU.cal;
  const radios = (name, ops, sel) => ops.map(([k, l, s]) => '<label for="' + name + '-' + k + '"><input type="radio" name="' + name + '" id="' + name + '-' + k + '" value="' + k + '"' + (sel === k ? ' checked' : '') + '><span><b>' + esc(l) + '</b>' + (s ? '<small>' + esc(s) + '</small>' : '') + '</span></label>').join('');
  const form = '<form class="w-form wx-calf" id="wx-calf" novalidate><h2 class="w-h3">Tu empresa</h2>' +
    '<fieldset class="w-f full w-radios wx-rcards" aria-describedby="cal-tam-e"><legend>Tamaño de la empresa <span class="w-req" aria-hidden="true">*</span></legend>' + radios('cal-tam', [['micro', 'Microempresa', 'Inscrita en el REMYPE'], ['small', 'Pequeña empresa', 'Inscrita en el REMYPE'], ['non_mype', 'No MYPE', 'Mediana o grande']], r && r.company_size) + '<small class="w-err" id="cal-tam-e" role="alert"></small></fieldset>' +
    '<div class="w-fg">' + wf('cal-n', 'Trabajadores afectados', 'number', { req: true, im: 'numeric', val: r ? String(r.affected_workers) : '', hint: 'Los que estarían involucrados en la infracción' }) +
    wf('cal-inf', 'Infracción de ejemplo', 'select', { ops: WX.infracciones.map((x) => x.t), val: r && r.infraction_code ? (WX.infracciones.find((x) => x.code === r.infraction_code) || {}).t : '', hint: 'Al elegir una, se marca su gravedad referencial' }) + '</div>' +
    '<fieldset class="w-f full w-radios wx-rcards wx-sev" aria-describedby="cal-sev-e" style="margin-top:16px"><legend>Gravedad de la infracción <span class="w-req" aria-hidden="true">*</span></legend>' + radios('cal-sev', [['leve', 'Leve', 'Formales o documentales'], ['grave', 'Grave', 'Afectan la gestión SST'], ['muy_grave', 'Muy grave', 'Riesgo o daño a la vida']], r && r.severity) + '<small class="w-err" id="cal-sev-e" role="alert"></small></fieldset>' +
    '<button type="submit" class="w-btn pri lg" id="cal-go">' + ic('calc', 18) + 'Calcular multa</button></form>';
  let res;
  if (!r) res = '<div class="wx-cres wx-cempty"><span class="w-plate amber">' + ic('calc', 26) + '</span><h2 class="w-h3">Tu resultado aparecerá aquí</h2><p>Elige el tamaño de tu empresa, el número de trabajadores afectados y la gravedad.</p></div>';
  else {
    const tn = WX_TAMANOS.find((x) => x[0] === r.company_size)[1]; const sn = WX_SEV.find((x) => x[0] === r.severity)[1];
    const uitP = wxParam('web.uit_value'); const dP = wxParam('web.fine_correction_discount_pct');
    res = '<div class="wx-cres" id="cal-res" tabindex="-1"><p class="wx-rlabel">Rango referencial de la multa <span class="w-chipw">Referencial · a confirmar</span></p>' +
      '<p class="wx-rbig"><b class="num">' + wxNum(r.result_min_uit, 3) + '</b><span>a</span><b class="num">' + wxNum(r.result_max_uit) + '</b><em>UIT</em></p>' +
      '<p class="wx-rsol num">' + wxSoles(r.result_min_pen) + ' – ' + wxSoles(r.result_max_pen) + '</p>' +
      '<dl class="wx-rdl"><dt>Empresa</dt><dd>' + esc(tn) + '</dd><dt>Gravedad</dt><dd>' + esc(sn) + '</dd><dt>Tramo aplicado</dt><dd>' + esc(r.tramo) + ' trabajador(es)</dd><dt>UIT usada</dt><dd>' + param(wxSoles(uitP.v) + ' · vigencia ' + uitP.vig) + '</dd><dt>Con subsanación</dt><dd>Mínimo con un descuento de ' + param(dP.v + ' %') + '</dd><dt>Sin subsanación</dt><dd>Máximo del tramo (' + esc(WX.tablas[r.company_size].key) + ')</dd></dl>' +
      '<div class="w-callout warn"><b>Resultado referencial, no es asesoría legal</b><p>Las tablas, la UIT y el descuento son parámetros con vigencia marcados «a confirmar por especialista legal». La multa real la determina la autoridad en cada caso.</p></div>' +
      (WXU.calEnv ? wxDone('Te enviamos el detalle', 'Revisa <b>' + esc(WXU.calEnv) + '</b>: incluye la tabla aplicada y recomendaciones para subsanar. En el prototipo no se envía ningún correo.', '<div class="w-ctas"><a class="w-btn pri" href="#web-autodiagnostico">Hacer el autodiagnóstico</a><a class="w-btn" href="#web-contacto">Agenda una demo</a></div>')
        : '<form class="wx-lead" id="wx-clf" novalidate><h3>Recibe el detalle por correo</h3><p class="w-fine">Te enviamos el cálculo con la tabla aplicada y cómo reducir el riesgo de multa.</p>' + wxCamposLead('cl', { okTxt: 'Acepto que EscudoSST use mis datos para enviarme el detalle del cálculo' }) + '<button type="submit" class="w-btn pri lg">Enviarme el detalle</button></form>') + '</div>';
  }
  return wxHero(wxMigasRec('Calculadora de multas'), 'Herramienta gratuita', 'Calculadora de multas SUNAFIL', 'Estima cuánto podría costarle a tu empresa una infracción en seguridad y salud en el trabajo, en UIT y en soles.',
    '<p class="wx-disc">' + ic('warn', 16) + '<span>Referencial, no es asesoría legal. Los valores son parámetros con vigencia <b>a confirmar</b>.</span></p>') +
    '<section class="w-sec w-tight"><div class="w-in wx-calg">' + form + res + '</div></section>' +
    '<section class="w-sec w-band"><div class="w-in"><div class="w-sh"><h2>Tablas referenciales usadas</h2><p>UIT por tramo de trabajadores afectados. Valores de ejemplo para la demostración, a confirmar con la normativa vigente.</p></div>' +
    '<div class="wx-tabs3">' + WX_TAMANOS.map(([k, n]) => '<details class="wx-tbld"' + (r && r.company_size === k ? ' open' : '') + '><summary><b>' + esc(n) + '</b><span class="w-chipw">A confirmar</span>' + ic('plus', 18) + '</summary><div class="w-tw"><table class="w-tbl"><thead><tr><th scope="col">Trabajadores</th>' + WX_SEV.map(([, l]) => '<th scope="col">' + l + '</th>').join('') + '</tr></thead><tbody>' + WX.tablas[k].tramos.map((t, i) => '<tr><td>' + esc(t) + '</td>' + WX_SEV.map(([s]) => '<td class="num">' + wxNum(WX.tablas[k].valores[s][i]) + '</td>').join('') + '</tr>').join('') + '</tbody></table></div></details>').join('') + '</div></div></section>' +
    wCTA('Evita la multa antes de que llegue el inspector', 'EscudoSST ordena tu expediente y te avisa antes de cada vencimiento.');
}
function bindCalculadora(root) {
  const f = $('#wx-calf', root);
  $('#cal-inf', root).addEventListener('change', (e) => { const x = WX.infracciones.find((i) => i.t === e.target.value); if (x) { const r = $('#cal-sev-' + x.sev, root); r.checked = true; } });
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    const tam = ($('[name="cal-tam"]:checked', root) || {}).value; const sev = ($('[name="cal-sev"]:checked', root) || {}).value; const n = parseInt($('#cal-n', root).value, 10);
    let ok = true;
    $('#cal-tam-e', root).textContent = tam ? '' : 'Elige el tamaño de tu empresa.'; ok = !!tam && ok;
    ok = errorCampo(root, 'cal-n', !(n >= 1 && n <= 100000) ? 'Escribe un número de trabajadores (1 o más).' : '') && ok;
    $('#cal-sev-e', root).textContent = sev ? '' : 'Elige la gravedad.'; ok = !!sev && ok;
    if (!ok) { toast('Completa los datos para calcular', 'warn'); return; }
    const inf = WX.infracciones.find((i) => i.t === $('#cal-inf', root).value);
    WXU.cal = wxCalcular(tam, n, sev, inf && inf.code); WXU.calEnv = null;
    renderWeb(); const rr = $('#cal-res'); if (rr) { rr.scrollIntoView({ block: 'start' }); rr.focus({ preventScroll: true }); }
  });
  const lf = $('#wx-clf', root);
  if (lf) { bindCaptcha(root, 'cl-cap'); lf.addEventListener('submit', (e) => { e.preventDefault(); const d = wxValidarLead(root, 'cl'); if (!d) return;
    const sn = WX_SEV.find((x) => x[0] === WXU.cal.severity)[1];
    const l = wxLead('fine_calculator', d, 'Calculó una multa ' + sn.toLowerCase() + ' · ' + WXU.cal.tramo + ' trabajadores');
    WX.calculos.unshift(Object.assign({ lead_id: l.id, f: wxAhora() }, WXU.cal));
    WXU.calEnv = d.email; renderWeb(); const dn = $('#wx-done'); if (dn) { dn.scrollIntoView({ block: 'center' }); dn.focus({ preventScroll: true }); } }); }
}

/* ---------- 2. Autodiagnóstico Ley 29783 ---------- */
const WX_RESP = [['si', 'Sí', 1], ['par', 'Parcialmente', 0.5], ['no', 'No', 0]];
const wxSecciones = (c) => [...new Set(c.preguntas.slice().sort((a, b) => a.sort_order - b.sort_order).map((p) => p.section))];
function wxPuntaje(c, resp) {
  const secs = wxSecciones(c); const out = {}; let tot = 0; let max = 0;
  secs.forEach((s) => { let a = 0; let m = 0; c.preguntas.filter((p) => p.section === s).forEach((p) => { const r = WX_RESP.find((x) => x[0] === resp[p.id]); a += (r ? r[2] : 0) * p.weight; m += p.weight; }); out[s] = m ? Math.round((a / m) * 100) : 0; tot += a; max += m; });
  const total = max ? Math.round((tot / max) * 100) : 0;
  return { score_total: total, score_by_section_json: out, level: total < 50 ? 'low' : total < 80 ? 'medium' : 'high' };
}
const WX_NIVEL = { low: ['Bajo', 'bad', 'Tu sistema tiene brechas importantes. Prioriza la política, el IPERC y los registros obligatorios.'], medium: ['Medio', 'warn', 'Tienes una base, pero hay vacíos que una inspección podría encontrar. Ordena la evidencia y los vencimientos.'], high: ['Alto', 'ok', 'Tu sistema está bien encaminado. Mantén la evidencia al día y prepárate con el Modo Inspección SUNAFIL.'] };
function wxAutodiag() {
  const c = WX.cuest; const a = WXU.ad; const secs = wxSecciones(c);
  const etiqueta = '<p class="wx-qver"><span>Cuestionario v' + esc(c.version) + ' · ' + c.preguntas.length + ' preguntas en ' + secs.length + ' secciones</span>' + (c.to_validate ? '<span class="w-chipw">A validar por especialista SST</span>' : '<span class="wx-okc">' + ic('check', 14) + 'Validado por especialista</span>') + '</p>';
  let cuerpo;
  if (!a.fin) {
    const s = secs[Math.min(a.paso, secs.length - 1)]; const qs = c.preguntas.filter((p) => p.section === s).sort((x, y) => x.sort_order - y.sort_order);
    const pct = Math.round((a.paso / secs.length) * 100);
    cuerpo = '<form class="w-form wx-adf" id="wx-adf" novalidate><div class="wx-step"><span>Sección ' + (a.paso + 1) + ' de ' + secs.length + '</span><div class="wx-prog" role="progressbar" aria-label="Avance del cuestionario" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"><i style="width:' + pct + '%"></i></div></div>' +
      '<ol class="wx-secs" aria-label="Secciones">' + secs.map((x, i) => '<li class="' + (i < a.paso ? 'done' : i === a.paso ? 'on' : '') + '"' + (i === a.paso ? ' aria-current="step"' : '') + '>' + esc(x) + '</li>').join('') + '</ol>' +
      '<h2 class="w-h3">' + esc(s) + '</h2>' + qs.map((q, i) => '<fieldset class="wx-q" aria-describedby="' + q.id + '-e"><legend><span class="wx-qn">' + (i + 1) + '</span>' + esc(q.question_text) + '</legend><div class="wx-opts">' + WX_RESP.map(([k, l]) => '<label for="ad-' + q.id + '-' + k + '"><input type="radio" name="ad-' + q.id + '" id="ad-' + q.id + '-' + k + '" value="' + k + '"' + (a.resp[q.id] === k ? ' checked' : '') + '><span>' + l + '</span></label>').join('') + '</div><small class="w-err" id="' + q.id + '-e" role="alert"></small></fieldset>').join('') +
      '<div class="wx-nav">' + (a.paso ? '<button type="button" class="w-btn wx-back" id="ad-prev">' + ic('chev', 16) + 'Anterior</button>' : '<span></span>') + '<button type="submit" class="w-btn pri lg" id="ad-next">' + (a.paso === secs.length - 1 ? 'Ver mi resultado' : 'Siguiente sección') + '</button></div></form>';
  } else {
    const p = wxPuntaje(c, a.resp); const n = WX_NIVEL[p.level];
    cuerpo = '<div class="w-form wx-adres" id="ad-res" tabindex="-1"><div class="wx-adtop"><div class="wx-gauge ' + n[1] + '" style="--p:' + p.score_total + '"><b class="num">' + p.score_total + '<small>%</small></b><span>Puntaje</span></div><div><p class="wx-rlabel">Nivel de tu sistema de gestión SST</p><p class="wx-level ' + n[1] + '">Nivel ' + n[0].toLowerCase() + '</p><p>' + esc(n[2]) + '</p></div></div>' +
      '<h3>Puntaje por sección</h3><ul class="wx-bars">' + Object.entries(p.score_by_section_json).map(([s, v]) => '<li><span>' + esc(s) + '</span>' + barra(v) + '<b class="num">' + v + ' %</b></li>').join('') + '</ul>' +
      (a.enviado ? wxDone('Informe enviado', 'Enviamos tu informe en PDF a <b>' + esc(a.enviado) + '</b> con el detalle por sección y un plan sugerido. En el prototipo no se envía ningún correo.', '<div class="w-ctas"><a class="w-btn pri" href="#web-contacto">Agenda una demo</a><button type="button" class="w-btn" id="ad-again">Volver a empezar</button></div>')
        : '<form class="wx-lead" id="wx-arf" novalidate><h3>Recibe tu informe en PDF</h3><p class="w-fine">Con el detalle por sección y las acciones prioritarias.</p>' + wxCamposLead('ar', { empReq: true, okTxt: 'Acepto que EscudoSST use mis datos para enviarme el informe', extra: wf('ar-trab', 'Número de trabajadores', 'select', { ops: ['Menos de 20', '20 a 200', 'Más de 200'] }) }) + '<div class="w-ctas"><button type="submit" class="w-btn pri lg">Enviarme el informe</button><a class="w-btn lg" href="#web-contacto">Agenda una demo</a></div></form>') + '</div>';
  }
  return wxHero(wxMigasRec('Autodiagnóstico'), 'Herramienta gratuita', 'Autodiagnóstico Ley 29783', 'Responde con honestidad: toma pocos minutos y te dice en qué nivel está tu sistema de gestión de seguridad y salud en el trabajo.', etiqueta) +
    '<section class="w-sec w-tight" id="ws-ad"><div class="w-in w-narrow2">' + cuerpo + '</div></section>' + wCTA('Cierra las brechas con EscudoSST', 'La línea base del sistema arma tu plan de trabajo a partir de lo que falta.');
}
function bindAutodiag(root) {
  const a = WXU.ad; const c = WX.cuest; const secs = wxSecciones(c);
  const ir = (foco) => { renderWeb(); const t = $(foco || '#ws-ad'); if (t) { t.scrollIntoView({ block: 'start' }); if (t.focus) t.focus({ preventScroll: true }); } };
  const f = $('#wx-adf', root);
  if (f) {
    $$('input[type=radio]', f).forEach((r) => r.addEventListener('change', () => { a.resp[r.name.slice(3)] = r.value; errorCampo(root, r.name.slice(3), ''); }));
    f.addEventListener('submit', (e) => { e.preventDefault(); const qs = c.preguntas.filter((p) => p.section === secs[a.paso]); let ok = true;
      qs.forEach((q) => { ok = errorCampo(root, q.id, a.resp[q.id] ? '' : 'Elige una respuesta.') && ok; });
      if (!ok) { toast('Responde todas las preguntas de la sección', 'warn'); return; }
      if (a.paso < secs.length - 1) { a.paso++; ir(); } else { a.fin = true; ir('#ad-res'); } });
    const pv = $('#ad-prev', root); if (pv) pv.onclick = () => { a.paso--; ir(); };
  }
  const af = $('#wx-arf', root);
  if (af) { bindCaptcha(root, 'ar-cap'); af.addEventListener('submit', (e) => { e.preventDefault(); const d = wxValidarLead(root, 'ar', { empReq: true }); if (!d) return;
    d.workers_range = $('#ar-trab', root).value; const p = wxPuntaje(c, a.resp);
    const l = wxLead('self_assessment', d, 'Autodiagnóstico: ' + p.score_total + ' % · nivel ' + WX_NIVEL[p.level][0].toLowerCase());
    WX.envios.unshift(Object.assign({ questionnaire: c.title + ' v' + c.version, lead_id: l.id, f: wxAhora(), answers_json: Object.assign({}, a.resp), report_file_id: 'b2://reports/' + l.id.toLowerCase() + '.pdf' }, p));
    a.enviado = d.email; ir('#wx-done'); }); }
  const ag = $('#ad-again', root); if (ag) ag.onclick = () => { WXU.ad = { paso: 0, resp: {}, fin: false, enviado: null }; ir(); };
}

/* ---------- 3. Biblioteca de recursos ---------- */
const WX_REC_IC = { template: 'grid', guide: 'book', checklist: 'check2', format: 'doc2' };
function wxRecLista() {
  const q = WXU.recQ.toLowerCase();
  const L = WX.recursos.filter((r) => r.status === 'published' && (!WXU.recT || r.resource_type === WXU.recT) && (!WXU.recS || r.sector === WXU.recS || r.sector === 'Todos') && (!q || (r.title + ' ' + r.description).toLowerCase().includes(q)));
  if (!L.length) return '<div class="w-emptyb">' + ic('search', 28) + '<b>No hay recursos con estos filtros</b><p>Prueba con otro tipo o sector.</p></div>';
  return '<div class="wx-recg">' + L.map((r) => '<article class="wx-rec"><div class="wx-rech"><span class="w-plate ' + ({ template: 'blue', guide: 'teal', checklist: 'green', format: 'amber' })[r.resource_type] + '">' + ic(WX_REC_IC[r.resource_type], 22) + '</span><span class="wx-tags"><span>' + esc(WX_TIPO_REC[r.resource_type]) + '</span><span>' + esc(r.fmt) + '</span></span></div><h3>' + esc(r.title) + '</h3><p>' + esc(r.description) + '</p>' +
    '<div class="wx-recf"><span class="wx-sector">' + ic('building', 14) + esc(r.sector === 'Todos' ? 'Todos los sectores' : r.sector) + '</span>' + (r.is_gated ? '<span class="wx-gate">' + ic('lock', 14) + 'Con tus datos</span>' : '<span class="wx-free">' + ic('check', 14) + 'Descarga libre</span>') + '</div>' +
    '<button type="button" class="w-btn ' + (r.is_gated ? '' : 'pri') + ' w-full" data-rdl="' + esc(r.slug) + '">' + ic('dl', 16) + 'Descargar<span class="sr"> ' + esc(r.title) + '</span></button></article>').join('') + '</div>';
}
function wxRecursos() {
  const sectores = ['Todos'].concat(Object.values(WEB_SECTORES).map((s) => s.n));
  return wxHero([['Recursos']], 'Biblioteca', 'Plantillas y formatos SST para descargar', 'Formatos listos para usar mientras ordenas tu sistema. Algunos se descargan libres y otros te piden tus datos para enviarte actualizaciones.') +
    '<section class="w-sec w-tight"><div class="w-in"><div class="wx-filt" role="search"><div class="wx-chips" role="group" aria-label="Tipo de recurso">' + [['', 'Todos']].concat(Object.entries(WX_TIPO_REC)).map(([k, l]) => '<button type="button" data-rt="' + k + '" aria-pressed="' + (WXU.recT === k) + '">' + esc(l) + '</button>').join('') + '</div>' +
    '<label class="sr" for="rc-sec">Sector</label><select id="rc-sec" class="wx-sel">' + sectores.map((s) => '<option value="' + (s === 'Todos' ? '' : esc(s)) + '"' + (WXU.recS === s ? ' selected' : '') + '>' + (s === 'Todos' ? 'Sector: todos' : esc(s)) + '</option>').join('') + '</select>' +
    '<label class="sr" for="rc-q">Buscar recurso</label><input id="rc-q" class="wx-sel wx-qin" type="search" placeholder="Buscar plantilla o guía" value="' + esc(WXU.recQ) + '"></div><div id="rc-list" aria-live="polite">' + wxRecLista() + '</div>' +
    '<p class="w-fine" style="margin-top:18px">Los archivos se entregan con un enlace firmado de corta duración. En el prototipo la descarga es simulada.</p></div></section>' + wCTA('¿Cansado de las plantillas?', 'En EscudoSST el IPERC, la matriz y los registros se llenan solos con la información del sistema.');
}
function wxDescargar(r, lead) {
  r.download_count++; WX.descargas.unshift({ res: r.slug, lead: lead ? lead.id : '—', ip: Math.random().toString(16).slice(2, 6) + '…' + Math.random().toString(16).slice(2, 5), f: wxAhora() });
}
function bindRecursos(root) {
  const pint = () => { $('#rc-list', root).innerHTML = wxRecLista(); bindDl(); };
  $$('[data-rt]', root).forEach((b) => b.addEventListener('click', () => { WXU.recT = b.dataset.rt; $$('[data-rt]', root).forEach((x) => x.setAttribute('aria-pressed', x === b)); pint(); }));
  $('#rc-sec', root).addEventListener('change', (e) => { WXU.recS = e.target.value; pint(); });
  $('#rc-q', root).addEventListener('input', (e) => { WXU.recQ = e.target.value; pint(); });
  const bindDl = () => $$('[data-rdl]', root).forEach((b) => b.addEventListener('click', () => {
    const r = WX.recursos.find((x) => x.slug === b.dataset.rdl);
    if (!r.is_gated) { wxDescargar(r, null); modal('Descarga lista', '<p>«' + esc(r.title) + '» (' + esc(r.fmt) + ') se descargaría ahora con un enlace firmado de corta duración (simulado).</p>', [{ l: 'Entendido', cls: 'pri' }]); return; }
    modal('Descargar «' + r.title + '»', '<p class="small muted" style="margin-bottom:12px">Déjanos tus datos y descarga el archivo. Te avisaremos si publicamos una versión nueva.</p><div class="w wx-inmodal">' + wxCamposLead('rd', { okTxt: 'Acepto que EscudoSST use mis datos para entregarme el recurso' }) + '</div>',
      [{ l: 'Cancelar' }, { l: 'Descargar', cls: 'pri', fn: (ov) => { const d = wxValidarLead(ov, 'rd'); if (!d) return false; const l = wxLead('resource', d, 'Descargó «' + r.title + '»'); wxDescargar(r, l); setTimeout(() => modal('Tu descarga está lista', '<p>«' + esc(r.title) + '» se descargaría ahora con un enlace firmado de corta duración (simulado). También te lo enviamos a <b>' + esc(d.email) + '</b>.</p>', [{ l: 'Listo', cls: 'pri', fn: () => { pint(); } }]), 0); } }],
      { mount: (ov) => bindCaptcha(ov, 'rd-cap') });
  }));
  bindDl();
}

/* ---------- 4. Casos de éxito ---------- */
function wxCasos() {
  const L = WX.casos.filter((c) => c.status === 'published');
  return wxHero([['Casos de éxito']], 'Casos', 'Casos de éxito', 'Cómo ordenan su sistema de seguridad y salud en el trabajo empresas como la tuya. Solo publicamos casos con autorización escrita del cliente.',
    '<p class="wx-disc">' + ic('info', 16) + '<span>Los casos de esta página son <b>ejemplos</b> para la demostración y no corresponden a clientes reales.</span></p>') +
    '<section class="w-sec w-tight"><div class="w-in"><div class="wx-casos">' + (L.length ? L.map((c) => '<article class="wx-caso" id="caso-' + esc(c.slug) + '"><header><span class="wx-clogo lg" style="background:' + esc(c.color) + '" aria-hidden="true">' + esc(c.ini) + '</span><div><span class="w-ejt">Ejemplo</span><h2 class="w-h3">' + esc(c.client_name) + '</h2><span class="w-tagp">' + esc(c.sector) + '</span></div></header>' + (typeof v15CasoExtra === 'function' ? v15CasoExtra(c) : '') +
      '<div class="wx-cg"><div><h3>El reto</h3><p>' + esc(c.challenge) + '</p></div><div><h3>La solución</h3><p>' + esc(c.solution) + '</p></div><div><h3>Resultados</h3><ul class="w-checks">' + c.results.map((x) => '<li>' + ic('check', 18) + '<span>' + esc(x) + '</span></li>').join('') + '</ul></div></div>' +
      (c.quote ? '<blockquote class="wx-quote">«' + esc(c.quote) + '»</blockquote>' : '') + '</article>').join('') : '<div class="w-emptyb"><b>Pronto publicaremos casos</b><p>Estamos preparando historias con autorización de nuestros clientes.</p></div>') + '</div></div></section>' +
    wCTA('¿Quieres un resultado así?', 'Agenda una demo con un caso de tu sector.');
}

/* ---------- 5. Comparativa ---------- */
function wxComparativa() {
  return wxHero([['Recursos', 'web-recursos'], ['EscudoSST vs Excel y papel']], 'Comparativa', 'EscudoSST vs Excel y papel', 'Muchas áreas SST empiezan con hojas de cálculo y carpetas. Funciona hasta que llega una inspección, cambia el responsable o crece la empresa.') +
    '<section class="w-sec w-tight"><div class="w-in w-narrow2"><div class="wx-cmp" role="table" aria-label="Comparativa EscudoSST frente a Excel y papel"><div class="wx-cmph" role="row"><span role="columnheader">Tema</span><span role="columnheader">' + ic('file', 18) + 'Excel y papel</span><span role="columnheader">' + WMARK(20) + 'EscudoSST</span></div>' +
    WX.comparativa.map(([t, a, b]) => '<div class="wx-cmpr" role="row"><b role="rowheader">' + esc(t) + '</b><span role="cell" class="wx-ca"><i class="w-no" aria-hidden="true">' + ic('x', 16) + '</i><span><span class="sr">Excel y papel: </span>' + esc(a) + '</span></span><span role="cell" class="wx-cb"><i class="w-ok" aria-hidden="true">' + ic('check', 16) + '</i><span><span class="sr">EscudoSST: </span>' + esc(b) + '</span></span></div>').join('') + '</div>' +
    '<div class="w-callout"><b>¿No sabes por dónde empezar?</b><p>Calcula el riesgo de una multa o mide tu sistema con el autodiagnóstico gratuito.</p><div class="w-ctas" style="margin-top:10px"><a class="w-btn amb" href="#web-calculadora">Calcular multa</a><a class="w-btn" href="#web-autodiagnostico">Hacer el autodiagnóstico</a></div></div></div></section>' + wCTA();
}

/* ---------- 6. Centro de ayuda ---------- */
const wxArtPub = () => WX.articulos.filter((a) => a.status === 'published');
const wxCat = (slug) => WX.categorias.find((c) => c.slug === slug);
function wxBuscarArt(q) {
  q = q.trim().toLowerCase(); if (q.length < 2) return [];
  return wxArtPub().filter((a) => (a.title + ' ' + a.body.join(' ') + ' ' + a.pasos.join(' ')).toLowerCase().includes(q));
}
const wxArtLink = (a) => '<a class="wx-artl" href="#web-ayuda/a-' + esc(a.slug) + '">' + ic(a.video ? 'play' : 'file', 18) + '<span><b>' + esc(a.title) + '</b><small>' + esc((wxCat(a.cat) || {}).name || '') + (a.video ? ' · con video' : '') + '</small></span>' + ic('right', 16) + '</a>';
function wxFeedbackHTML(a, p) {
  const v = WXU.ayVoto[a.slug];
  if (v === 'gracias') return '<div class="wx-fb done" aria-live="polite">' + ic('check', 18) + '<span>Gracias por tu opinión. Nos ayuda a mejorar este artículo.</span></div>';
  if (v === 'no') return '<div class="wx-fb" aria-live="polite"><p><b>¿Qué faltó?</b> Cuéntanos para mejorar el artículo (opcional).</p><label class="sr" for="' + p + '-com">Comentario</label><textarea id="' + p + '-com" rows="3" class="wx-ta"></textarea><button type="button" class="w-btn" data-fbsend="' + esc(a.slug) + '">Enviar comentario</button></div>';
  return '<div class="wx-fb"><p><b>¿Te sirvió este artículo?</b></p><div class="wx-fbb"><button type="button" class="w-btn" data-fb="si" data-art="' + esc(a.slug) + '" id="' + p + '-si">' + ic('check', 16) + 'Sí</button><button type="button" class="w-btn" data-fb="no" data-art="' + esc(a.slug) + '" id="' + p + '-no">' + ic('x', 16) + 'No</button></div></div>';
}
function wxBindFeedback(root, rep, p) {
  $$('[data-fb]', root).forEach((b) => b.addEventListener('click', () => { const a = WX.articulos.find((x) => x.slug === b.dataset.art); const si = b.dataset.fb === 'si';
    if (si) { a.util[0]++; WX.feedback.unshift({ article: a.slug, was_helpful: true, comment: '', f: wxAhora(), account: p === 'cx' ? empresaActual().corto : null }); WXU.ayVoto[a.slug] = 'gracias'; } else WXU.ayVoto[a.slug] = 'no';
    rep(); }));
  $$('[data-fbsend]', root).forEach((b) => b.addEventListener('click', () => { const a = WX.articulos.find((x) => x.slug === b.dataset.fbsend); a.util[1]++; WX.feedback.unshift({ article: a.slug, was_helpful: false, comment: ($('#' + p + '-com', root) || {}).value || '', f: wxAhora(), account: p === 'cx' ? empresaActual().corto : null }); WXU.ayVoto[a.slug] = 'gracias'; rep(); }));
}
const wxVideoArt = (a) => a.video ? '<button type="button" class="wx-vid" data-artvid="' + esc(a.slug) + '"><span class="w-play" aria-hidden="true">' + ic('play', 26) + '</span><span><b>Video: ' + esc(a.title) + '</b><small>Reproductor simulado · se incrusta desde YouTube o Vimeo</small></span></button>' : '';
const wxCuerpoArt = (a) => a.body.map((x) => '<p>' + esc(x) + '</p>').join('') + (a.pasos.length ? '<h2>Paso a paso</h2><ol class="wx-pasos">' + a.pasos.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ol>' : '');
function wxAyuda(sub) {
  const cats = WX.categorias.filter((c) => c.status === 'published').sort((a, b) => a.sort_order - b.sort_order);
  const buscador = (v) => '<form class="wx-search" id="ay-f" role="search"><label class="sr" for="ay-q">Buscar en el centro de ayuda</label>' + ic('search', 20) + '<input id="ay-q" type="search" placeholder="Buscar: IPERC, inspección, certificado…" value="' + esc(v || '') + '" autocomplete="off"></form><div id="ay-res" aria-live="polite"></div>';
  const side = (on) => '<aside class="wx-aside" aria-label="Categorías"><h2 class="w-fh">Categorías</h2>' + cats.map((c) => '<a href="#web-ayuda/c-' + c.slug + '" class="' + (on === c.slug ? 'on' : '') + '"' + (on === c.slug ? ' aria-current="page"' : '') + '>' + ic(c.icon, 16) + esc(c.name) + '</a>').join('') + '<div class="w-cbox"><b>¿No encontraste lo que buscabas?</b><p class="w-fine">Escríbenos y te ayudamos.</p><a class="w-btn" href="#web-contacto/contacto">Contactar a soporte</a></div></aside>';
  if (sub && sub.startsWith('a-')) {
    const a = WX.articulos.find((x) => x.slug === sub.slice(2));
    if (!a || a.status !== 'published') return { html: wxHero([['Centro de ayuda', 'web-ayuda'], ['Artículo']], '', 'Este artículo no está disponible', 'Puede que se haya movido o que aún no esté publicado.', '<div class="w-ctas"><a class="w-btn pri" href="#web-ayuda">Ir al centro de ayuda</a></div>'), seo: { t: 'Artículo no disponible · Centro de ayuda', d: 'Centro de ayuda de EscudoSST' } };
    const c = wxCat(a.cat); const rel = a.rel.map((s) => WX.articulos.find((x) => x.slug === s)).filter((x) => x && x.status === 'published');
    const m = modBy(a.screen_code);
    return { seo: { t: a.title + ' · Centro de ayuda EscudoSST', d: a.body[0].slice(0, 155) }, html: '<section class="w-phero wx-hero"><div class="w-in">' + wMigas([['Centro de ayuda', 'web-ayuda'], [c.name, 'web-ayuda/c-' + c.slug], [a.title]]) + '</div></section>' +
      '<section class="w-sec w-tight"><div class="w-in wx-helpg">' + side(c.slug) + '<article class="w-prose wx-art" id="ay-art"><h1 class="wx-arth">' + esc(a.title) + '</h1><p class="wx-artm">' + ic(c.icon, 14) + esc(c.name) + (m ? ' · <span>Pantalla de la app: ' + esc(m.nombre) + '</span>' : '') + '</p>' + wxVideoArt(a) + wxCuerpoArt(a) +
      '<div id="ay-fb">' + wxFeedbackHTML(a, 'ay') + '</div>' + (rel.length ? '<h2>Artículos relacionados</h2><div class="wx-artls">' + rel.map(wxArtLink).join('') + '</div>' : '') + '</article></div></section>' };
  }
  if (sub && sub.startsWith('c-')) {
    const c = wxCat(sub.slice(2)) || cats[0]; const L = wxArtPub().filter((a) => a.cat === c.slug);
    return { seo: { t: c.name + ' · Centro de ayuda EscudoSST', d: 'Artículos de ayuda sobre ' + c.name.toLowerCase() + ' en EscudoSST.' }, html: '<section class="w-phero wx-hero"><div class="w-in">' + wMigas([['Centro de ayuda', 'web-ayuda'], [c.name]]) + '<h1>' + esc(c.name) + '</h1><p class="w-lead">' + L.length + ' artículo(s) en esta categoría.</p></div></section>' +
      '<section class="w-sec w-tight"><div class="w-in wx-helpg">' + side(c.slug) + '<div>' + (L.length ? '<div class="wx-artls">' + L.map(wxArtLink).join('') + '</div>' : '<div class="w-emptyb"><b>Aún no hay artículos publicados</b></div>') + '</div></div></section>' };
  }
  const pop = wxArtPub().slice().sort((a, b) => b.util[0] - a.util[0]).slice(0, 6);
  return { html: '<section class="w-phero wx-hero wx-helph"><div class="w-in w-narrow2 w-center">' + wMigas([['Centro de ayuda']]) + '<h1>¿En qué te ayudamos?</h1><p class="w-lead w-center">Guías paso a paso y videos para usar EscudoSST. También los encuentras dentro de la app con el botón «Ayuda».</p>' + buscador('') + '</div></section>' +
    '<section class="w-sec w-tight"><div class="w-in"><h2 class="w-h2s">Categorías</h2><div class="wx-catg">' + cats.map((c) => { const n = wxArtPub().filter((a) => a.cat === c.slug).length; return '<a class="wx-cat" href="#web-ayuda/c-' + c.slug + '"><span class="w-plate blue">' + ic(c.icon, 22) + '</span><span><b>' + esc(c.name) + '</b><small>' + n + ' artículo' + (n === 1 ? '' : 's') + '</small></span></a>'; }).join('') + '</div>' +
    '<h2 class="w-h2s" style="margin-top:44px">Artículos más útiles</h2><div class="wx-artls two">' + pop.map(wxArtLink).join('') + '</div></div></section>' + wCTA('¿Todavía no usas EscudoSST?', 'Regístrate con tu RUC o agenda una demo.') };
}
function bindAyuda(root) {
  const q = $('#ay-q', root);
  if (q) { const run = () => { const L = wxBuscarArt(q.value); $('#ay-res', root).innerHTML = q.value.trim().length < 2 ? '' : L.length ? '<p class="wx-rcount">' + L.length + ' resultado(s) para «' + esc(q.value.trim()) + '»</p><div class="wx-artls">' + L.map(wxArtLink).join('') + '</div>' : '<div class="w-emptyb"><b>Sin resultados para «' + esc(q.value.trim()) + '»</b><p>Prueba con otra palabra o <a href="#web-contacto/contacto">escríbenos</a>.</p></div>'; };
    q.addEventListener('input', run); $('#ay-f', root).addEventListener('submit', (e) => { e.preventDefault(); run(); }); }
  const fb = $('#ay-fb', root);
  if (fb) { const slug = WEB_RUTA[1].slice(2); const rep = () => { const a = WX.articulos.find((x) => x.slug === slug); fb.innerHTML = wxFeedbackHTML(a, 'ay'); wxBindFeedback(fb, rep, 'ay'); }; wxBindFeedback(fb, rep, 'ay'); }
  $$('[data-artvid]', root).forEach((b) => b.addEventListener('click', () => wxVideoModal(b.dataset.artvid)));
}
function wxVideoModal(slug) {
  const a = WX.articulos.find((x) => x.slug === slug);
  modal('Video: ' + a.title, '<div class="w-vmodal" role="img" aria-label="Reproductor de video simulado"><span class="w-play">' + ic('play', 30) + '</span><p>Video del artículo</p></div>' + nota('Reproductor simulado. En el sistema real el video se incrusta desde YouTube o Vimeo con el enlace que se configura en el panel «Sitio web» › Centro de ayuda.'), [{ l: 'Cerrar', cls: 'pri' }], { wide: true });
}

/* ---------- 7. Partners y referidos ---------- */
function wxPartners() {
  const tipos = [['consultancy', 'building', 'blue', 'Consultoras SST', 'Gestiona a tus clientes con el plan Consultora y marca blanca, y recomienda EscudoSST a otras empresas.'], ['independent', 'user', 'green', 'Profesionales independientes', 'Asesoras a empresas por tu cuenta: recomiéndales EscudoSST y acompaña su implementación.'], ['institute', 'award', 'amber', 'Institutos y centros de formación', 'Formas a profesionales SST: usa el aula virtual y presenta EscudoSST a tus alumnos y empresas.']];
  const done = WXU.pa;
  const form = done ? wxDone('Recibimos tu solicitud', 'Tu código de solicitud es <b>' + esc(done.id) + '</b>. Revisaremos tus datos y te escribiremos a <b>' + esc(done.email) + '</b>. Si se aprueba, recibirás tu código de referido.', '<div class="w-ctas"><a class="w-btn" href="#web-consultoras">Conocer el plan Consultora</a></div>')
    : '<form class="w-form" id="wx-pf" novalidate><h2 class="w-h3">Solicita unirte al programa</h2>' +
      '<fieldset class="w-f full w-radios" aria-describedby="pa-tipo-e"><legend>Tipo de partner <span class="w-req" aria-hidden="true">*</span></legend>' + Object.entries(WX_PARTNER_T).map(([k, l]) => '<label for="pa-tipo-' + k + '"><input type="radio" name="pa-tipo" id="pa-tipo-' + k + '" value="' + k + '"><span>' + esc(l) + '</span></label>').join('') + '<small class="w-err" id="pa-tipo-e" role="alert"></small></fieldset>' +
      '<div class="w-fg" style="margin-top:16px">' + wf('pa-emp', 'Empresa o nombre profesional', 'text', { req: true, ac: 'organization' }) + wf('pa-ruc', 'RUC', 'text', { im: 'numeric', hint: '11 dígitos' }) + wf('pa-web', 'Sitio web o perfil profesional', 'url', { ph: 'https://' }) + wf('pa-tel', 'Celular', 'tel', { im: 'tel', ac: 'tel', hint: '9 dígitos' }) + '</div>' +
      '<div style="margin-top:16px">' + wxCamposLead('pa', { empresa: false, okTxt: 'Acepto que EscudoSST use mis datos para evaluar mi solicitud' }) + '</div><button type="submit" class="w-btn pri lg">Enviar solicitud</button></form>';
  return wxHero([['Partners y referidos']], 'Programa de partners', 'Crece con EscudoSST', 'Para consultoras SST, profesionales independientes e institutos. Recomienda EscudoSST a las empresas que acompañas y sigue cada referido desde que se registra hasta que es cliente.') +
    '<section class="w-sec w-tight"><div class="w-in"><div class="wx-ptipos">' + tipos.map(([, i, c, t, d]) => '<div class="w-difr"><span class="w-plate ' + c + '">' + ic(i, 24) + '</span><div><h3>' + esc(t) + '</h3><p>' + esc(d) + '</p></div></div>').join('') + '</div></div></section>' +
    '<section class="w-sec w-band"><div class="w-in"><div class="w-sh"><h2>Cómo funciona</h2><p>Un proceso simple y transparente.</p></div><ol class="w-pasos n4">' + [['Solicita', 'Completa el formulario con los datos de tu empresa o tu perfil profesional.'], ['Aprobamos', 'Revisamos tu solicitud y te damos acceso al programa.'], ['Recibe tu código', 'Comparte tu código de referido con las empresas que recomiendas.'], ['Sigue tus referidos', 'Ve el estado de cada uno: registrado, en prueba o cliente.']].map(([t, d], i) => '<li><span class="w-pn">' + (i + 1) + '</span><h3>' + esc(t) + '</h3><p>' + esc(d) + '</p></li>').join('') + '</ol>' +
    '<div class="wx-benef"><h3>Beneficios</h3><ul class="w-checks">' + ['Código de referido propio y panel de seguimiento', 'Materiales de presentación y demo para tus clientes', 'Acompañamiento en las primeras implementaciones', 'Recompensa por cada referido que se vuelve cliente, según las condiciones del programa (por definir)'].map((x) => '<li>' + ic('check', 18) + '<span>' + esc(x) + '</span></li>').join('') + '</ul></div></div></section>' +
    '<section class="w-sec" id="ws-solicitud"><div class="w-in w-contg">' + form + '<aside class="w-contside"><div class="w-cbox"><h2 class="w-h3">Condiciones</h2><p class="w-fine">El tipo y el valor de la recompensa los define EscudoSST en las condiciones del programa. Mientras tanto se muestran «por definir».</p></div><div class="w-cbox"><h2 class="w-h3">¿Eres consultora?</h2><p class="w-fine">Además del programa, el plan Consultora te deja gestionar a tus clientes con tu propia marca.</p><a class="w-btn" href="#web-consultoras">Ver el plan Consultora</a></div></aside></div></section>';
}
function bindPartners(root) {
  const f = $('#wx-pf', root); if (!f) { focoDone(root); return; }
  bindCaptcha(root, 'pa-cap');
  f.addEventListener('submit', (e) => { e.preventDefault();
    const tipo = ($('[name="pa-tipo"]:checked', root) || {}).value;
    const d = wxValidarLead(root, 'pa', { mas: (v) => { let ok = true; $('#pa-tipo-e', root).textContent = tipo ? '' : 'Elige el tipo de partner.'; ok = !!tipo;
      ok = errorCampo(root, 'pa-emp', !v('pa-emp') ? 'Escribe tu empresa o tu nombre profesional.' : '') && ok;
      ok = errorCampo(root, 'pa-ruc', v('pa-ruc') && !rucValido(v('pa-ruc')) ? 'Este RUC no es válido. Revisa los dígitos.' : '') && ok;
      ok = errorCampo(root, 'pa-tel', v('pa-tel') && !/^9\d{8}$/.test(v('pa-tel').replace(/\s/g, '')) ? 'Escribe un celular de 9 dígitos que empiece con 9.' : '') && ok; return ok; } });
    if (!d) return;
    const v = (id) => $('#' + id, root).value.trim();
    d.company_name = v('pa-emp'); d.ruc = v('pa-ruc'); d.phone = v('pa-tel'); d.sector = 'Consultora SST';
    const l = wxLead('partner', d, 'Solicitó ser partner (' + WX_PARTNER_T[tipo] + ')');
    const id = 'PAR-' + String(WX.sigPartner++).padStart(4, '0');
    WX.partners.unshift({ id, f: fmtF(HOY), lead: l.id, partner_type: tipo, company_name: d.company_name, ruc: d.ruc, website: v('pa-web'), contacto: d.full_name, email: d.email, status: 'pending', reviewed_by: '', reviewed_at: '', notes: '' });
    WXU.pa = { id, email: d.email }; renderWeb(); const t = $('#wx-done'); if (t) { t.scrollIntoView({ block: 'center' }); t.focus({ preventScroll: true }); } });
}

/* ---------- 8. Boletín normativo ---------- */
function wxBoletin(sub) {
  const ed = WX.ediciones.filter((e) => e.status === 'sent');
  if (sub === 'baja') {
    const email = WXU.boEmail || 'ksalazar@correo-demo.pe'; const s = WX.suscriptores.find((x) => x.email === email);
    const hecho = WXU.baja === email || (s && s.status === 'unsubscribed');
    return { seo: { t: 'Darte de baja del boletín · EscudoSST', d: 'Cancela tu suscripción al boletín normativo SST de EscudoSST con un clic.' }, html: wxHero([['Boletín', 'web-boletin'], ['Darte de baja']], '', hecho ? 'Te diste de baja' : 'Darte de baja del boletín', hecho ? 'Ya no recibirás el boletín en <b>' + esc(email) + '</b>. Si fue un error, puedes volver a suscribirte cuando quieras.' : 'Con un clic dejas de recibir el boletín en <b>' + esc(email) + '</b>. No te pediremos nada más.') +
      '<section class="w-sec w-tight"><div class="w-in w-narrow2"><div class="w-form wx-baja">' + (hecho ? '<div class="wx-fb done">' + ic('check', 18) + '<span>Baja registrada el ' + esc(fmtF(HOY)) + '.</span></div><div class="w-ctas"><a class="w-btn pri" href="#web-boletin">Volver a suscribirme</a><a class="w-btn" href="#web">Ir a la portada</a></div>' : '<p class="w-fine">Llegaste aquí desde el enlace «Darte de baja» de un correo. El enlace lleva un token de un solo uso (simulado).</p><div class="w-ctas"><button type="button" class="w-btn pri lg" id="bo-baja">Confirmar la baja</button><a class="w-btn lg" href="#web-boletin">Cambiar mis temas</a></div>') + '</div></div></section>' };
  }
  let caja;
  if (WXU.bo && WXU.bo.estado === 'confirmed') caja = wxDone('Suscripción confirmada', 'Desde ahora recibirás el boletín en <b>' + esc(WXU.bo.email) + '</b>. Puedes darte de baja con un clic desde cualquier correo.', '<div class="w-ctas"><a class="w-btn" href="#web-boletin/baja" id="bo-golink">Ver la página de baja</a><a class="w-btn pri" href="#web-recursos">Ver recursos gratuitos</a></div>');
  else if (WXU.bo) caja = wxDone('Revisa tu correo para confirmar', 'Te enviamos un enlace a <b>' + esc(WXU.bo.email) + '</b>. Tu suscripción se activa cuando lo abras (doble confirmación). Si no lo ves, revisa la carpeta de correo no deseado.', '<div class="w-ctas"><button type="button" class="w-btn pri" id="bo-conf">' + ic('mail', 16) + 'Simular el clic en el correo</button></div><p class="w-fine">En el prototipo no se envía ningún correo.</p>');
  else caja = '<form class="w-form" id="wx-bof" novalidate><h2 class="w-h3">Suscríbete gratis</h2><fieldset class="w-f full w-radios w-checksq" aria-describedby="bo-temas-e"><legend>Temas que te interesan <span class="w-req" aria-hidden="true">*</span></legend>' + WX.temas.map(([k, l]) => '<label for="bo-t-' + k + '"><input type="checkbox" id="bo-t-' + k + '" data-bot="' + k + '" checked><span>' + esc(l) + '</span></label>').join('') + '<small class="w-err" id="bo-temas-e" role="alert"></small></fieldset>' +
    '<div style="margin-top:16px">' + wxCamposLead('bo', { nomOpc: true, empresa: false, okTxt: 'Quiero recibir el boletín normativo SST en mi correo' }) + '</div><button type="submit" class="w-btn pri lg">Suscribirme</button></form>';
  return { html: wxHero([['Recursos', 'web-recursos'], ['Boletín normativo']], 'Boletín', 'Boletín normativo SST', 'Cambios de normativa, novedades de SUNAFIL y guías por sector, explicados en simple. Una vez al mes, sin spam y con baja en un clic.') +
    '<section class="w-sec w-tight"><div class="w-in w-contg" id="ws-bo">' + caja + '<aside class="w-contside"><div class="w-cbox"><h2 class="w-h3">Ediciones anteriores</h2><ul class="wx-eds">' + (ed.length ? ed.map((e) => '<li><b>' + esc(e.subject) + '</b><small>' + esc(e.sent_at.split(' ')[0]) + ' · ' + e.topics.map((t) => esc((WX.temas.find((x) => x[0] === t) || [, t])[1])).join(', ') + '</small></li>').join('') : '<li class="w-fine">Aún no hay ediciones enviadas.</li>') + '</ul></div><div class="w-cbox"><h2 class="w-h3">Tus datos</h2><p class="w-fine">Solo usamos tu correo para el boletín. La casilla de comunicaciones comerciales es aparte y opcional. <a href="#web-legal-privacidad">Política de privacidad</a>.</p></div></aside></div></section>' };
}
function bindBoletin(root) {
  const f = $('#wx-bof', root);
  if (f) { bindCaptcha(root, 'bo-cap'); f.addEventListener('submit', (e) => { e.preventDefault();
    const temas = $$('[data-bot]:checked', root).map((c) => c.dataset.bot);
    const d = wxValidarLead(root, 'bo', { nomOpc: true, mas: () => { $('#bo-temas-e', root).textContent = temas.length ? '' : 'Elige al menos un tema.'; return temas.length > 0; } });
    if (!d) return;
    let s = WX.suscriptores.find((x) => x.email.toLowerCase() === d.email.toLowerCase());
    if (!s) { s = { email: d.email, topics: temas, status: 'pending', f: fmtF(HOY), confirmed_at: '', unsubscribed_at: '' }; WX.suscriptores.unshift(s); } else { s.topics = temas; if (s.status === 'unsubscribed') { s.status = 'pending'; s.unsubscribed_at = ''; } }
    const l = wxLead('newsletter', d, 'Se suscribió al boletín (pendiente de confirmar)'); s.lead = l.id;
    WXU.bo = { email: d.email, estado: s.status }; WXU.boEmail = d.email; WXU.baja = null; renderWeb(); focoDone(document); }); }
  const c = $('#bo-conf', root);
  if (c) c.onclick = () => { const s = WX.suscriptores.find((x) => x.email === WXU.bo.email); s.status = 'confirmed'; s.confirmed_at = fmtF(HOY); WXU.bo.estado = 'confirmed'; const l = WX.leads.find((x) => x.email === s.email); if (l) l.act.unshift('Suscripción al boletín confirmada'); renderWeb(); focoDone(document); };
  const b = $('#bo-baja', root);
  if (b) b.onclick = () => { const email = WXU.boEmail || 'ksalazar@correo-demo.pe'; const s = WX.suscriptores.find((x) => x.email === email); if (s) { s.status = 'unsubscribed'; s.unsubscribed_at = fmtF(HOY); } WXU.baja = email; WXU.bo = null; toast('Baja registrada'); renderWeb(); };
}

/* ---------- 9a. Centro de confianza ---------- */
function wxResumenEstado() {
  const malos = WX.componentes.filter((c) => c.current_status !== 'operational'); const abiertos = WX.incidentes.filter((i) => i.status !== 'resolved');
  if (!malos.length && !abiertos.length) return ['ok', 'Todos los sistemas operativos'];
  if (malos.some((c) => c.current_status === 'major_outage')) return ['bad', 'Interrupción mayor en curso'];
  if (malos.length && malos.every((c) => c.current_status === 'maintenance')) return ['info', 'Mantenimiento en curso'];
  return ['warn', abiertos.length ? 'Incidente en curso' : 'Algunos servicios con problemas'];
}
function wxConfianza() {
  const a = WEB.ajustes; const est = wxResumenEstado();
  const medidas = [['lock', 'Conexión y datos cifrados', 'Todo el tráfico viaja cifrado (HTTPS). Los discos y los campos sensibles se cifran en reposo.'], ['heart', 'Datos médicos aparte', 'La información de salud vive en una base separada y cifrada. En la base principal solo queda el estado de aptitud.'], ['eye', 'Acceso con clave adicional', 'Ver un dato médico exige volver a identificarse y cada acceso queda registrado.'], ['building', 'Cada empresa aislada', 'Los datos de cada empresa cliente están separados a nivel de base de datos.'], ['upload', 'Copias de seguridad cifradas', 'Respaldos cifrados y archivos con enlaces firmados de corta duración.'], ['file', 'Bitácora inalterable', 'Firmas, votos, auditoría y accesos se registran sin posibilidad de edición.']];
  const subs = WX.subencargados.filter((s) => s.status === 'published');
  return wxHero([['Centro de confianza']], 'Confianza', 'Centro de confianza', 'Cómo protegemos la información de tu empresa y de tus trabajadores. Sin sellos ni certificaciones que no tengamos.',
    '<a class="wx-stbar ' + est[0] + '" href="#web-estado"><span class="wx-dot" aria-hidden="true"></span><b>' + esc(est[1]) + '</b><span>Ver estado del servicio</span>' + ic('right', 16) + '</a>') +
    '<section class="w-sec w-tight"><div class="w-in"><h2 class="w-h2s">Seguridad</h2><div class="w-difl wx-medidas">' + medidas.map(([i, t, d]) => '<div class="w-difr"><span class="w-plate blue">' + ic(i, 22) + '</span><div><h3>' + esc(t) + '</h3><p>' + esc(d) + '</p></div></div>').join('') + '</div></div></section>' +
    '<section class="w-sec w-band"><div class="w-in w-split w-split-even"><div><h2>Datos personales y Ley 29733</h2><p class="w-lead2">Tratamos los datos según la Ley de Protección de Datos Personales y su reglamento. Para los datos de tus trabajadores, EscudoSST actúa como encargado de tratamiento de tu empresa.</p><dl class="w-legdl"><dt>Titular</dt><dd>' + esc(a.razon) + '</dd><dt>RUC</dt><dd>' + esc(a.ruc) + '</dd><dt>Domicilio</dt><dd>' + ajusteTxt(a.domicilio, 'Por completar') + '</dd><dt>Contacto</dt><dd>' + ajusteTxt(a.correo, 'Correo por definir') + '</dd></dl></div>' +
    '<div class="w-cbox"><h3>Tus derechos</h3><p class="w-fine">Acceso, rectificación, cancelación y oposición (ARCO). Escríbenos para ejercerlos; el plazo de respuesta es un parámetro a confirmar.</p><ul class="w-checks">' + [['Política de privacidad', 'web-legal-privacidad'], ['Contrato de encargo de tratamiento', 'web-legal-encargo'], ['Acuerdo de nivel de servicio', 'web-legal-sla']].map(([l, r]) => '<li>' + ic('check', 18) + '<a href="#' + r + '">' + esc(l) + '</a></li>').join('') + '</ul></div></div></section>' +
    '<section class="w-sec" id="ws-subencargados"><div class="w-in"><div class="w-sh"><h2>Subencargados</h2><p>Proveedores que tratan datos por cuenta de EscudoSST, con la transferencia internacional que corresponda. Te avisaremos antes de agregar uno nuevo.</p></div>' +
    '<div class="w-tw wx-subt"><table class="w-tbl"><thead><tr><th scope="col">Proveedor</th><th scope="col">Finalidad</th><th scope="col">País</th><th scope="col">Datos tratados</th><th scope="col">Transferencia</th></tr></thead><tbody>' + subs.map((s) => '<tr><td data-l="Proveedor"><b>' + esc(s.name) + '</b></td><td data-l="Finalidad">' + esc(s.purpose) + '</td><td data-l="País">' + esc(s.country) + '</td><td data-l="Datos tratados">' + esc(s.data_categories) + '</td><td data-l="Transferencia">' + esc(s.transfer_basis) + '</td></tr>').join('') + '</tbody></table></div><p class="w-fine">Lista revisada el ' + esc(fmtF(HOY)) + '. Países y bases de transferencia a validar con abogado.</p></div></section>' +
    '<section class="w-sec w-band"><div class="w-in"><div class="w-sh"><h2>Documentos</h2></div><div class="wx-docs">' + [['Política de privacidad', 'Versión vigente', 'web-legal-privacidad'], ['Contrato de encargo de tratamiento', 'Para empresas clientes', 'web-legal-encargo'], ['Acuerdo de nivel de servicio', 'Disponibilidad y soporte', 'web-legal-sla'], ['Resumen de seguridad', 'Por publicar', '']].map(([t, s, r]) => r ? '<a class="wx-doc" href="#' + r + '">' + ic('doc2', 22) + '<span><b>' + esc(t) + '</b><small>' + esc(s) + '</small></span>' + ic('right', 16) + '</a>' : '<div class="wx-doc off">' + ic('doc2', 22) + '<span><b>' + esc(t) + '</b><small class="w-pend">' + esc(s) + '</small></span></div>').join('') + '</div></div></section>' + wCTA();
}

/* ---------- 9b. Estado del servicio ---------- */
const wxUpdates = (i) => '<ol class="w-tl wx-updl">' + i.updates.slice().reverse().map((u) => '<li><time>' + esc(u.at) + '</time><span><b>' + esc(WX_INC_EST[u.status]) + '</b> · ' + esc(u.message) + '</span></li>').join('') + '</ol>';
function wxEstado() {
  const est = wxResumenEstado(); const abiertos = WX.incidentes.filter((i) => i.status !== 'resolved'); const hist = WX.incidentes.filter((i) => i.status === 'resolved');
  const compN = (codes) => codes.map((c) => (WX.componentes.find((x) => x.code === c) || { name: c }).name).join(', ');
  const inc = (i) => '<article class="wx-inc ' + (i.status === 'resolved' ? 'res' : i.impact) + '"><header><div><h3>' + esc(i.title) + '</h3><p class="w-fine">' + esc(compN(i.comps)) + ' · desde ' + esc(i.started_at) + (i.resolved_at ? ' · resuelto ' + esc(i.resolved_at) : '') + '</p></div><span class="wx-impact">' + esc(WX_IMPACTO[i.impact]) + '</span></header>' + wxUpdates(i) + '</article>';
  return wxHero([['Recursos', 'web-recursos'], ['Estado del servicio']], '', 'Estado del servicio', 'Disponibilidad de EscudoSST en tiempo real e historial de incidentes. Hora de Lima.') +
    '<section class="w-sec w-tight"><div class="w-in w-narrow2"><div class="wx-stbig ' + est[0] + '" role="status"><span class="wx-dot" aria-hidden="true"></span><b>' + esc(est[1]) + '</b><small>Actualizado ' + esc(wxAhora()) + '</small></div>' +
    (abiertos.length ? '<h2 class="w-h2s">Incidentes en curso</h2>' + abiertos.map(inc).join('') : '') +
    '<h2 class="w-h2s">Componentes</h2><ul class="wx-comps">' + WX.componentes.slice().sort((a, b) => a.sort_order - b.sort_order).map((c) => { const [l, k] = WX_COMP_EST[c.current_status]; return '<li><span><b>' + esc(c.name) + '</b><small>' + esc(c.description) + '</small></span><span class="wx-cst ' + k + '"><span class="wx-dot" aria-hidden="true"></span>' + esc(l) + '</span></li>'; }).join('') + '</ul>' +
    '<h2 class="w-h2s">Historial de incidentes</h2>' + (hist.length ? hist.map(inc).join('') : '<p class="w-fine">Sin incidentes en los últimos 90 días.</p>') +
    '<div class="w-form wx-stsub" id="ws-avisos">' + (WXU.estSub ? '<div class="wx-fb done">' + ic('check', 18) + '<span>Listo: te avisaremos a <b>' + esc(WXU.estSub) + '</b> cuando haya un incidente o un mantenimiento.</span></div>' : '<form id="wx-esf" novalidate><h2 class="w-h3">Suscríbete a los avisos</h2><p class="w-fine">Te escribimos cuando se abre un incidente, cuando cambia y cuando se resuelve.</p><div class="wx-qrow2">' + wf('es-cor', 'Correo', 'email', { req: true, ac: 'email' }) + '</div>' + consent('es-ok', 'Acepto recibir avisos del estado del servicio en este correo, según la <a href="#web-legal-privacidad">Política de privacidad</a>.') + captcha('es-cap') + '<small class="w-err" id="es-cap-e" role="alert"></small><button type="submit" class="w-btn pri lg">Suscribirme a los avisos</button></form>') + '</div></div></section>';
}
function bindEstado(root) {
  const f = $('#wx-esf', root); if (!f) return;
  bindCaptcha(root, 'es-cap');
  f.addEventListener('submit', (e) => { e.preventDefault(); const v = $('#es-cor', root).value.trim(); let ok = errorCampo(root, 'es-cor', !CORREO_RE.test(v) ? 'Escribe un correo válido.' : '');
    ok = errorCampo(root, 'es-ok', !$('#es-ok', root).checked ? 'Necesitamos tu consentimiento para enviarte avisos.' : '') && ok; const cap = $('#es-cap', root).classList.contains('ok'); $('#es-cap-e', root).textContent = cap ? '' : 'Marca «No soy un robot».';
    if (!ok || !cap) { toast('Revisa los campos marcados', 'warn'); return; }
    WX.estadoSubs.unshift({ email: v, f: wxAhora() }); WXU.estSub = v; renderWeb(); const t = $('#ws-avisos'); if (t) t.scrollIntoView({ block: 'center' }); });
}

/* ---------- Despacho de rutas nuevas (lo llama R.__web) ---------- */
function wxRuta(ruta, sub) {
  const F = {
    'web-calculadora': () => ({ html: wxCalculadora(), mount: bindCalculadora }),
    'web-autodiagnostico': () => ({ html: wxAutodiag(), mount: bindAutodiag }),
    'web-recursos': () => ({ html: wxRecursos(), mount: bindRecursos }),
    'web-casos': () => ({ html: wxCasos() }),
    'web-comparativa': () => ({ html: wxComparativa() }),
    'web-ayuda': () => { const r = wxAyuda(sub); return { html: r.html, mount: bindAyuda, extra: r.seo || null }; },
    'web-partners': () => ({ html: wxPartners(), mount: bindPartners }),
    'web-boletin': () => { const r = wxBoletin(sub); return { html: r.html, mount: bindBoletin, extra: r.seo || null }; },
    'web-confianza': () => ({ html: wxConfianza() }),
    'web-estado': () => ({ html: wxEstado(), mount: bindEstado }),
  };
  return F[ruta] ? F[ruta]() : null;
}

/* ---------- Ayuda contextual dentro de la app (botón «Ayuda» de la cabecera) ---------- */
const WX_PANT_ESP = { inicio: 'DSH', 'aul-banco': 'AUL', 'aul-recomendaciones': 'AUL', capacitador: 'AUL', 'app-trabajador': 'TRB', registro: 'TEN' };
function wxPantalla() { const r = rutaActual().split('/')[0]; return r.startsWith('m-') ? r.slice(2).toUpperCase() : WX_PANT_ESP[r] || 'DSH'; }
function wxAyudaContextual() {
  const code = wxPantalla(); const m = modBy(code); const L = wxArtPub().filter((a) => a.screen_code === code);
  const otros = wxArtPub().filter((a) => a.screen_code !== code).sort((a, b) => b.util[0] - a.util[0]).slice(0, 3);
  const lista = () => '<div class="wx-cx"><p class="small muted">Pantalla actual: <b>' + esc(m ? m.nombre : 'Inicio') + '</b> <code class="tiny">screen_code=' + esc(code) + '</code></p>' +
    (L.length ? '<h3 class="wx-cxh">Para esta pantalla</h3><div class="wx-cxl">' + L.map((a) => '<button type="button" class="wx-cxa" data-cxa="' + esc(a.slug) + '">' + ic(a.video ? 'play' : 'file', 18) + '<span><b>' + esc(a.title) + '</b><small>' + (a.video ? 'Artículo con video' : 'Artículo') + '</small></span>' + ic('right', 16) + '</button>').join('') + '</div>'
      : vacio('Aún no hay artículos para esta pantalla', 'Mientras tanto, revisa los más útiles o busca en el centro de ayuda.')) +
    '<h3 class="wx-cxh">Más consultados</h3><div class="wx-cxl">' + otros.map((a) => '<button type="button" class="wx-cxa" data-cxa="' + esc(a.slug) + '">' + ic('file', 18) + '<span><b>' + esc(a.title) + '</b><small>' + esc(wxCat(a.cat).name) + '</small></span>' + ic('right', 16) + '</button>').join('') + '</div></div>';
  const d = cajon('Ayuda', 'Artículos del centro de ayuda para esta pantalla', lista(), [{ l: 'Abrir el centro de ayuda', fn: () => { location.hash = 'web-ayuda'; } }, { l: 'Cerrar', cls: 'pri' }], (dr) => bindLista(dr));
  function bindLista(dr) { $$('[data-cxa]', dr).forEach((b) => b.addEventListener('click', () => abrir(dr, b.dataset.cxa))); }
  function abrir(dr, slug) {
    const a = WX.articulos.find((x) => x.slug === slug); const db = $('.db', dr);
    const pint = () => { db.innerHTML = '<button type="button" class="btn ghost sm wx-back" id="cx-back">' + ic('chev', 14) + 'Volver</button><article class="wx-cxart"><h3>' + esc(a.title) + '</h3>' + (a.video ? '<button type="button" class="wx-vid sm" data-artvid="' + esc(a.slug) + '"><span class="w-play" aria-hidden="true">' + ic('play', 20) + '</span><span><b>Ver el video</b><small>Reproductor simulado</small></span></button>' : '') + wxCuerpoArt(a) + '<div id="cx-fb">' + wxFeedbackHTML(a, 'cx') + '</div><p class="small"><a href="#web-ayuda/a-' + esc(a.slug) + '" id="cx-open">Abrir en el centro de ayuda</a></p></article>';
      $('#cx-back', db).onclick = () => { db.innerHTML = lista(); bindLista(dr); };
      wxBindFeedback(db, pint, 'cx');
      $$('[data-artvid]', db).forEach((b) => b.addEventListener('click', () => toast('Video del artículo (reproductor simulado)')));
      $('#cx-open', db).addEventListener('click', () => cerrarCapas()); };
    pint();
  }
  return d;
}
