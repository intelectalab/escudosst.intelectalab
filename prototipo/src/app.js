/* Núcleo del prototipo: estado de demostración, permisos por rol/plan, shell, router y componentes. */
const CAT = window.__CAT;
const MODS = CAT.modulos;
const modBy = (c) => MODS.find((m) => m.code === c);
const R = {}; // renderizadores por código de módulo o ruta especial

const S = { role: 'sst_manager', plan: 'P', addons: new Set(['OFF', 'IAA', 'PSE']), empresa: 'andinas', theme: 'auto', collapsed: false, openGroups: {}, feat: JSON.parse(JSON.stringify(FEAT_INICIAL)) };
try { const g = JSON.parse(localStorage.getItem('escudosst-demo') || 'null'); if (g) { S.role = g.role || S.role; S.plan = g.plan || S.plan; S.addons = new Set((g.addons || [...S.addons]).filter((a) => COMPLEMENTOS.some((c) => c[0] === a))); S.empresa = g.empresa || S.empresa; S.theme = g.theme || 'auto'; S.collapsed = !!g.collapsed; if (g.feat) Object.keys(S.feat).forEach((k) => { if (g.feat[k]) S.feat[k] = g.feat[k]; }); } } catch (e) { /* sin almacenamiento: se usan valores por defecto */ }
if (!CAT.roles.some((r) => r.code === S.role)) S.role = 'sst_manager';
if (!EMPRESAS.some((e) => e.id === S.empresa)) S.empresa = 'andinas';
const guardar = () => { try { localStorage.setItem('escudosst-demo', JSON.stringify({ role: S.role, plan: S.plan, addons: [...S.addons], empresa: S.empresa, theme: S.theme, collapsed: S.collapsed, feat: S.feat })); } catch (e) { /* opcional */ } };

const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const $ = (sel, el) => (el || document).querySelector(sel);
const $$ = (sel, el) => [...(el || document).querySelectorAll(sel)];
const rolNom = (c) => (CAT.roles.find((r) => r.code === c) || {}).nombre || c;
const empresaActual = () => EMPRESAS.find((e) => e.id === S.empresa) || EMPRESAS[0];
const esConsultor = () => S.role === 'consultant';
const empresasConsultora = () => EMPRESAS.filter((e) => EMPRESAS_CONSULTORA.includes(e.id));
/* Activación por empresa dentro del plan: AUL y VTA */
const featActiva = (code) => !FEAT_MODS.includes(code) || !!(S.feat[S.empresa] || {})[code];
function setFeat(code, on) { const f = S.feat[S.empresa] || (S.feat[S.empresa] = { AUL: false, VTA: false }); f[code] = on; if (code === 'AUL' && !on) f.VTA = false; if (code === 'VTA' && on) f.AUL = true; guardar(); }
const marcaBlanca = () => esConsultor();
const soloLectura = () => ROL_SOLO_LECTURA.includes(S.role);
const puedeEditar = () => !soloLectura();

/* ---------- Iconos (trazos propios, 24×24) ---------- */
const IC = {
  home: 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z',
  grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
  plan: 'M8 3h8v3H8zM6 5H4v16h16V5h-2M8 11h8M8 15h5',
  risk: 'M12 3l10 18H2zM12 10v5M12 18v.5',
  field: 'M9 4h6l1 3h4v13H4V7h4zM12 11v5M9.5 13.5h5',
  people: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21c0-4 3-6 7-6s7 2 7 6M17 11a3 3 0 1 0 0-6M22 21c0-3-2-5-5-5.5',
  event: 'M12 2v4M12 18v4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M2 12h4M18 12h4M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8',
  truck: 'M2 6h12v10H2zM14 10h4l3 3v3h-7M6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
  star: 'M12 3l2.8 5.8 6.2.9-4.5 4.4 1 6.2L12 17.4 6.5 20.3l1-6.2L3 9.7l6.2-.9z',
  lock: 'M6 11h12v10H6zM8.5 11V7.5a3.5 3.5 0 0 1 7 0V11',
  chev: 'M6 9l6 6 6-6',
  right: 'M9 6l6 6-6 6',
  menu: 'M3 6h18M3 12h18M3 18h18',
  bell: 'M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 20a2 2 0 0 0 4 0',
  search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4',
  x: 'M6 6l12 12M18 6L6 18',
  plus: 'M12 5v14M5 12h14',
  check: 'M5 12l5 5L20 7',
  down: 'M12 4v12M6 10l6 6 6-6M4 20h16',
  qr: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2zM14 18h2M18 14h2',
  camera: 'M4 7h4l2-3h4l2 3h4v13H4zM12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  file: 'M6 2h9l5 5v15H6zM14 2v6h6',
  shield: 'M12 2l8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5z',
  info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 11v6M12 7.5v.5',
  warn: 'M12 3l10 18H2zM12 10v5M12 18v.5',
  wifi: 'M2 2l20 20M8.5 16.5a5 5 0 0 1 7 0M5 13a10 10 0 0 1 5-2.7M19 13a10 10 0 0 0-2.3-1.7M12 20h.01',
  pen: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c0-4 4-6 8-6s8 2 8 6',
  chat: 'M4 4h16v12H8l-4 4z',
  spark: 'M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M6 18l2.5-2.5M15.5 8.5L18 6',
  box: 'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10',
  heart: 'M12 20s-8-5-8-11a4.5 4.5 0 0 1 8-2.5A4.5 4.5 0 0 1 20 9c0 6-8 11-8 11z',
  sun: 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
  moon: 'M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z',
  link: 'M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1',
  play: 'M7 4l13 8-13 8z',
  cal: 'M4 5h16v16H4zM4 10h16M9 3v4M15 3v4',
  upload: 'M12 16V4M6 10l6-6 6 6M4 20h16',
  phone: 'M7 2h10v20H7zM11 18h2',
  building: 'M4 21V4h10v17M14 9h6v12M7 8h4M7 12h4M7 16h4M17 13h1M17 17h1',
  eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  power: 'M12 3v8M6.3 6.8a8 8 0 1 0 11.4 0',
  book: 'M4 4h6a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H4zM20 4h-6a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h6z',
  cart: 'M3 4h2l2.4 11h10.2L20 8H6.3M9 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM17 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2z',
  card: 'M3 6h18v12H3zM3 10h18M7 15h4',
  clock: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 7v5l3 2',
  trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13',
  up: 'M12 19V5M6 11l6-6 6 6',
  text: 'M5 6h14M5 10h14M5 14h10M5 18h7',
  award: 'M12 15a6 6 0 1 0 0-12 6 6 0 0 0 0 12zM8.5 14 7 22l5-3 5 3-1.5-8',
  tag: 'M3 12V3h9l9 9-9 9zM7.5 7.5h.01',
  quiz: 'M9 9a3 3 0 1 1 4 2.8c-.6.3-1 .9-1 1.6V14M12 17.5v.5M4 4h16v16H4z',
};
const ic = (n, s = 18) => '<svg width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + (IC[n] || IC.info) + '"/></svg>';
const GRUPO_IC = { 1: 'grid', 2: 'plan', 3: 'risk', 4: 'field', 5: 'people', 6: 'event', 7: 'truck', 8: 'star' };

const LOGO = (s = 34, wl = false) => wl
  ? '<svg width="' + s + '" height="' + s + '" viewBox="0 0 40 40" aria-hidden="true"><rect x="2" y="2" width="36" height="36" rx="10" fill="#FFFFFF"/><path d="M11 27c0-6 4-11 9-11s9 5 9 11" fill="none" stroke="#0E7A70" stroke-width="3.2" stroke-linecap="round"/><circle cx="20" cy="12" r="3.2" fill="#F4B90F"/></svg>'
  : '<svg width="' + s + '" height="' + s + '" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 2.5 34.5 7.6v10.6c0 9.3-6.1 16.4-14.5 19.3C11.6 34.6 5.5 27.5 5.5 18.2V7.6Z" fill="#FFFFFF"/><path d="M11.2 23.6c0-5.7 3.9-9.9 8.8-9.9s8.8 4.2 8.8 9.9" fill="#1F7FD1"/><path d="M20 13.7v-2.3" stroke="#1F7FD1" stroke-width="2.4" stroke-linecap="round"/><rect x="8.6" y="23.2" width="22.8" height="3.4" rx="1.7" fill="#F4B90F"/><path d="M17.6 14.4c-1.5 1.8-2.2 4.5-2.2 8.8" stroke="#FFFFFF" stroke-width="1.6" fill="none" stroke-linecap="round" opacity=".7"/></svg>';

/* ---------- Acceso por rol y plan ---------- */
function rolVe(code) {
  const lista = ROL_MODULOS[S.role];
  if (code === 'SUS' || code === 'WEB') return S.role === 'platform_owner' || S.role === 'platform_support';
  if (code === 'CON') return S.role === 'consultant' || S.role === 'account_admin' || S.role === 'platform_owner';
  if (lista === '*') return true;
  return (lista || []).includes(code);
}
function planIncluye(m) {
  if (m.plan === 'I') return S.role === 'platform_owner' || S.role === 'platform_support';
  if (m.plan === 'K') return S.role === 'consultant' || S.role === 'platform_owner';
  if (m.plan === '+') return S.addons.has(m.code);
  return (CAT.plan_incluye[S.plan] || []).includes(m.plan);
}
function acceso(code) {
  const m = modBy(code);
  if (!m) return 'ok';
  if (!rolVe(code)) return 'rol';
  if (!planIncluye(m)) return 'plan';
  if (!featActiva(code)) return 'empresa';
  return 'ok';
}
/* Para rutas propias dentro de un módulo (banco de preguntas, capacitador, etc.) */
function guardMod(root, code) { const a = acceso(code); if (a === 'ok') return false; bloqueado(root, modBy(code), a); return true; }
const planTexto = (m) => m.plan === '+' ? 'el complemento «' + m.nombre + '»' : m.plan === 'K' ? 'el plan Consultora' : m.plan === 'I' ? 'el panel interno de EscudoSST' : 'el plan ' + m.plan_nombre;

/* ---------- Componentes ---------- */
const ESTADOS = [
  [/^(vigente|aprobad[oa]|cerrad[oa]|cumple|apto|habilitad[oa]|activ[oa]|completad[oa]|conforme|firmad[oa]|entregad[oa]|ejecutad[oa]|realizad[oa]|al día|operativ[oa]|sincronizad[oa]|dentro del límite|sí|ok|acreditad[oa]|en regla|publicad[oa]|notificad[oa]|pagad[oa]|aceptad[oa]|resuelt[oa])/i, 'ok'],
  [/^(por vencer|en proceso|en revisión|abiert[oa]|pendiente|programad[oa]|apto con restricciones|en curso|observad[oa]|en prueba|en ejecución|por firmar|parcial|borrador|en investigación|cerca del límite|en elaboración|enviad[oa]|stock bajo|por renovar|en evaluación|medio|moderado|suspendid[oa] temporal)/i, 'warn'],
  [/^(vencid[oa]|no cumple|no apto|rechazad[oa]|suspendid[oa]|revocad[oa]|crítico|fuera de plazo|sobre el límite|no habilitad[oa]|sin stock|alto|intolerable|bloquead[oa]|error|morosa|no conforme|falta)/i, 'bad'],
  [/^(no aplica|obsolet[oa]|anulad[oa]|archivad[oa]|inactiv[oa]|bajo|trivial)/i, 'na'],
];
function chipCls(t) { for (const [re, c] of ESTADOS) if (re.test(String(t).trim())) return c; return 'info'; }
const chip = (t, cls) => '<span class="chip ' + (cls || chipCls(t)) + '">' + esc(t) + '</span>';
const sem = (c) => '<span class="sem ' + c + '" aria-hidden="true"></span>';
const semTxt = { ok: 'Vigente', warn: 'Por vencer', bad: 'Vencido', na: 'No aplica', pend: 'Pendiente' };
const barra = (p, cls) => '<div class="bar ' + (cls || (p >= 80 ? 'ok' : p >= 50 ? 'warn' : 'bad')) + '" role="progressbar" aria-valuenow="' + p + '" aria-valuemin="0" aria-valuemax="100"><i style="width:' + p + '%"></i></div>';
const param = (txt, nota) => '<span class="param" title="' + esc(nota || 'Parámetro con vigencia · a confirmar por especialista SST/legal') + '">' + esc(txt) + '</span>';
const nota = (txt, cls, icn) => '<div class="note ' + (cls || '') + '">' + ic(icn || (cls === 'warn' || cls === 'bad' ? 'warn' : 'info')) + '<div>' + txt + '</div></div>';
const kpi = (l, v, d, extra) => '<div class="card kpi">' + '<span class="kl">' + esc(l) + '</span><span class="kv num">' + v + '</span>' + (d ? '<span class="kd">' + d + '</span>' : '') + (extra || '') + '</div>';
const vacio = (t, s, btn) => '<div class="empty">' + ic('box', 44) + '<b>' + esc(t) + '</b><span class="small">' + esc(s) + '</span>' + (btn || '') + '</div>';

function tabla(cols, rows, opt = {}) {
  if (!rows.length) return '<div class="tw">' + vacio(opt.vacioT || 'No hay registros con estos filtros', opt.vacioS || 'Cambia los filtros o crea un registro nuevo.') + '</div>';
  const th = cols.map((c) => '<th' + (c.w ? ' style="width:' + c.w + '"' : '') + '>' + esc(c.l) + '</th>').join('');
  const tr = rows.map((r, i) => '<tr' + (opt.click ? ' class="click" data-row="' + i + '" tabindex="0"' : '') + '>' + cols.map((c, j) => {
    const v = c.f ? c.f(r) : esc(r[c.k]);
    return '<td data-l="' + esc(c.l) + '"' + (j === 0 ? ' class="tdmain"' : '') + '>' + v + '</td>';
  }).join('') + '</tr>').join('');
  return '<div class="tw ' + (opt.noResp ? '' : 'rt') + '"><table><thead><tr>' + th + '</tr></thead><tbody>' + tr + '</tbody></table></div>';
}
function bindTabla(root, rows, fn) {
  $$('tr[data-row]', root).forEach((tr) => {
    const go = () => fn(rows[+tr.dataset.row]);
    tr.addEventListener('click', go);
    tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
  });
}

function toast(msg, cls) {
  const t = document.createElement('div');
  t.className = 'toast ' + (cls || '');
  t.setAttribute('role', 'status');
  t.innerHTML = '<span class="ti">' + (cls === 'warn' ? '!' : '✓') + '</span><span>' + esc(msg) + '</span>';
  $('#toasts').appendChild(t);
  setTimeout(() => t.remove(), 3600);
}
function cerrarCapas() { $$('.ov, .drawer').forEach((x) => x.remove()); }
function modal(titulo, cuerpo, botones = [], opt = {}) {
  cerrarCapas();
  const ov = document.createElement('div');
  ov.className = 'ov';
  ov.innerHTML = '<div class="modal ' + (opt.wide ? 'wide' : '') + '" role="dialog" aria-modal="true" aria-label="' + esc(titulo) + '"><div class="mh"><h2>' + esc(titulo) + '</h2><button class="iconbtn" data-x aria-label="Cerrar">' + ic('x') + '</button></div><div class="mb">' + cuerpo + '</div>' + (botones.length ? '<div class="mf"></div>' : '') + '</div>';
  const mf = $('.mf', ov);
  botones.forEach((b) => { const el = document.createElement('button'); el.className = 'btn ' + (b.cls || ''); el.textContent = b.l; el.onclick = () => { const r = b.fn ? b.fn(ov) : null; if (r !== false) ov.remove(); }; mf.appendChild(el); });
  ov.addEventListener('click', (e) => { if (e.target === ov || e.target.closest('[data-x]')) ov.remove(); });
  document.body.appendChild(ov);
  const f = $('input, select, textarea, .mf .btn', ov); if (f) f.focus();
  if (opt.mount) opt.mount(ov);
  return ov;
}
function cajon(titulo, sub, cuerpo, botones = [], mount) {
  cerrarCapas();
  const ov = document.createElement('div'); ov.className = 'ov'; ov.style.background = 'rgba(6,16,32,.35)';
  const d = document.createElement('aside'); d.className = 'drawer'; d.setAttribute('role', 'dialog'); d.setAttribute('aria-label', titulo);
  d.innerHTML = '<div class="dh"><div><h2>' + esc(titulo) + '</h2>' + (sub ? '<p class="muted small">' + sub + '</p>' : '') + '</div><button class="iconbtn" data-x aria-label="Cerrar">' + ic('x') + '</button></div><div class="db">' + cuerpo + '</div>' + (botones.length ? '<div class="df"></div>' : '');
  const df = $('.df', d);
  const cerrar = () => { d.remove(); ov.remove(); };
  botones.forEach((b) => { const el = document.createElement('button'); el.className = 'btn ' + (b.cls || ''); el.textContent = b.l; el.onclick = () => { const r = b.fn ? b.fn(d) : null; if (r !== false) cerrar(); }; df.appendChild(el); });
  ov.onclick = cerrar; $('[data-x]', d).onclick = cerrar;
  document.body.appendChild(ov); document.body.appendChild(d);
  if (mount) mount(d);
  return d;
}
const dl = (pares) => '<dl class="dl">' + pares.map(([a, b]) => '<dt>' + esc(a) + '</dt><dd>' + b + '</dd>').join('') + '</dl>';
const campo = (id, l, tipo = 'text', val = '', opts) => '<label class="field" for="' + id + '"><span>' + esc(l) + '</span>' + (tipo === 'select'
  ? '<select id="' + id + '">' + opts.map((o) => '<option' + (o === val ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>'
  : tipo === 'textarea' ? '<textarea id="' + id + '">' + esc(val) + '</textarea>'
    : '<input id="' + id + '" type="' + tipo + '" value="' + esc(val) + '">') + '</label>';
function formModal(titulo, campos, okTxt, okMsg, extra) {
  const cuerpo = (extra || '') + '<div class="grid g2">' + campos.map((c, i) => campo('f' + i + '-' + Date.now() % 1000, c[0], c[1] || 'text', c[2] || '', c[3])).join('') + '</div>';
  modal(titulo, cuerpo, [{ l: 'Cancelar' }, { l: okTxt, cls: 'pri', fn: () => toast(okMsg) }]);
}
const tabs = (id, items, on) => '<div class="tabs" role="tablist" data-tabs="' + id + '">' + items.map((t, i) => '<button role="tab" aria-selected="' + (i === on) + '" class="' + (i === on ? 'on' : '') + '" data-i="' + i + '">' + esc(t) + '</button>').join('') + '</div>';
function bindTabs(root, id, fn) { $$('[data-tabs="' + id + '"] button', root).forEach((b) => b.addEventListener('click', () => fn(+b.dataset.i))); }
const seg = (id, items, on) => '<div class="seg" data-seg="' + id + '">' + items.map((t, i) => '<button class="' + (i === on ? 'on' : '') + '" data-i="' + i + '">' + esc(t) + '</button>').join('') + '</div>';
function bindSeg(root, id, fn) { $$('[data-seg="' + id + '"] button', root).forEach((b) => b.addEventListener('click', () => fn(+b.dataset.i))); }

function encabezado(titulo, desc, acciones = '', migas) {
  const m = migas || [];
  return '<nav class="crumbs" aria-label="Ruta"><a href="#inicio">EscudoSST</a>' + m.map((x) => '<span>›</span>' + (x[1] ? '<a href="#' + x[1] + '">' + esc(x[0]) + '</a>' : '<span>' + esc(x[0]) + '</span>')).join('') + '</nav>' +
    '<div class="phead"><div class="pt"><h1>' + esc(titulo) + '</h1>' + (desc ? '<p>' + desc + '</p>' : '') + '</div><div class="pa">' + acciones + '</div></div>';
}
function encMod(code, desc, acciones) {
  const m = modBy(code);
  const pl = m.plan === 'I' ? 'Interno' : m.plan === 'K' ? 'Consultora' : m.plan === '+' ? 'Complemento' : m.plan_nombre;
  return encabezado(m.nombre, (desc || m.resuelve) + ' <span class="pill-plan" title="Disponible desde">' + esc(m.numero + ' · ' + pl) + '</span>', acciones, [[CAT.grupos[m.grupo]], [m.nombre]]);
}
const btnNuevo = (txt, id) => puedeEditar() ? '<button class="btn pri" id="' + (id || 'nuevo') + '">' + ic('plus', 16) + esc(txt) + '</button>' : '<span class="chip info nodot">Solo lectura</span>';
const btnExport = () => '<button class="btn" data-export>' + ic('down', 16) + 'Exportar</button>';
function bindExport(root) { $$('[data-export]', root).forEach((b) => b.addEventListener('click', () => modal('Exportar', '<p>Elige el formato. En el prototipo la descarga es simulada.</p>', [{ l: 'Cancelar' }, { l: 'Excel', fn: () => toast('Excel generado (simulado)') }, { l: 'PDF', cls: 'pri', fn: () => toast('PDF generado (simulado)') }]))); }

/* Pantalla genérica dirigida por configuración: KPIs + pestañas + filtros + tabla + ficha + formulario */
function pantalla(root, code, cfg) {
  let tabOn = 0; let q = ''; const fsel = {};
  const pint = () => {
    const cur = cfg.tabs ? cfg.tabs[tabOn] : cfg;
    let rows = cur.rows.slice();
    if (q) rows = rows.filter((r) => JSON.stringify(r).toLowerCase().includes(q.toLowerCase()));
    (cur.filtros || []).forEach((f) => { if (fsel[f.k]) rows = rows.filter((r) => String(r[f.k]) === fsel[f.k]); });
    const filtrosHtml = '<div class="filters"><input class="input" id="buscar-' + code + '" type="search" placeholder="Buscar en ' + esc((cur.nombre || modBy(code).nombre).toLowerCase()) + '" value="' + esc(q) + '" aria-label="Buscar">' +
      (cur.filtros || []).map((f) => { const ops = [...new Set(cur.rows.map((r) => String(r[f.k])))]; return '<select class="input" data-f="' + f.k + '" aria-label="' + esc(f.l) + '"><option value="">' + esc(f.l) + ': todos</option>' + ops.map((o) => '<option' + (fsel[f.k] === o ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>'; }).join('') +
      '<span class="muted small" style="margin-left:auto">' + rows.length + ' de ' + cur.rows.length + '</span></div>';
    $('#gen-body', root).innerHTML = (cur.antes || '') + filtrosHtml + tabla(cur.cols, rows, { click: true }) + (cur.despues || '');
    const bi = $('#buscar-' + code, root);
    bi.addEventListener('input', () => { q = bi.value; const pos = bi.selectionStart; pint(); const n = $('#buscar-' + code, root); n.focus(); n.setSelectionRange(pos, pos); });
    $$('[data-f]', root).forEach((s) => s.addEventListener('change', () => { fsel[s.dataset.f] = s.value; pint(); }));
    const ficha = cur.ficha || cfg.ficha;
    const ficha2 = ficha || (typeof fichaArr === 'function' && cur.rows.length && Array.isArray(cur.rows[0]) ? (r) => fichaArr(cur.cols, 0)(r) : null);
    if (ficha2) bindTabla(root, rows, (r) => { const f = ficha2(r); cajon(f.t, f.s, f.html, f.btns || [{ l: 'Cerrar' }], f.mount); });
    if (cur.mount) cur.mount(root);
  };
  root.innerHTML = encMod(code, cfg.desc, (cfg.acciones || '') + btnExport() + (cfg.nuevo ? btnNuevo(cfg.nuevo[0]) : '')) +
    (cfg.aviso || '') + (cfg.kpis ? '<div class="grid g4" style="margin-bottom:16px">' + cfg.kpis.map((k) => kpi(k[0], k[1], k[2])).join('') + '</div>' : '') +
    (cfg.extra || '') + (cfg.tabs ? tabs('gen', cfg.tabs.map((t) => t.nombre), 0) : '') + '<div id="gen-body"></div>';
  if (cfg.tabs) bindTabs(root, 'gen', (i) => { tabOn = i; q = ''; Object.keys(fsel).forEach((k) => delete fsel[k]); $$('[data-tabs="gen"] button', root).forEach((b, j) => { b.classList.toggle('on', i === j); b.setAttribute('aria-selected', i === j); }); pint(); });
  if (cfg.nuevo && puedeEditar()) $('#nuevo', root).addEventListener('click', () => formModal(cfg.nuevo[0], cfg.nuevo[1], cfg.nuevo[2] || 'Guardar', cfg.nuevo[3] || 'Registro guardado'));
  bindExport(root);
  pint();
  if (cfg.mount) cfg.mount(root);
}
const fichaSimple = (titulo, sub, pares, extra, btns) => ({ t: titulo, s: sub, html: dl(pares) + (extra || ''), btns: btns || [{ l: 'Cerrar' }] });

/* ---------- Shell ---------- */
const DEFAULT_RUTA = { trainer: 'capacitador', external_learner: 'alumno-cursos', worker: 'app-trabajador', platform_owner: 'm-sus', platform_support: 'm-sus', consultant: 'm-con', occupational_doctor: 'm-emo', health_staff: 'm-emo', contractor_admin: 'm-ctr', external_auditor: 'm-reg', hr: 'm-trb', committee_member: 'm-com', area_lead: 'm-ins' };
const ESPECIALES = { 'pub-reporte': 1, 'pub-verificar': 1, 'sun-inspector': 1, 'pub-certificado': 1, 'pub-cursos': 1 };
const esWebRuta = (r) => r === 'web' || /^web-/.test(r); // sitio público de EscudoSST (módulo WEB)
const esEspecial = (r) => !!ESPECIALES[r] || esWebRuta(r);

function demoBar() {
  const roles = CAT.roles.map((r) => '<option value="' + r.code + '"' + (r.code === S.role ? ' selected' : '') + '>' + esc(r.nombre) + '</option>').join('');
  const planes = Object.entries(PLANES).map(([k, v]) => '<option value="' + k + '"' + (k === S.plan ? ' selected' : '') + '>' + v + '</option>').join('');
  const lista = esConsultor() ? empresasConsultora() : EMPRESAS.filter((e) => EMPRESAS_DEMO.includes(e.id));
  const verEmp = esConsultor() || !['platform_owner', 'platform_support', 'external_learner', 'worker'].includes(S.role);
  const emps = lista.map((e) => '<option value="' + e.id + '"' + (e.id === S.empresa ? ' selected' : '') + '>' + esc(e.corto) + ' · ' + PLANES[e.plan] + (esConsultor() ? '' : (S.feat[e.id] || {}).VTA ? ' · vende cursos' : ' · solo su personal') + '</option>').join('');
  return '<span class="tag">PROTOTIPO</span><button class="dbtn dtoggle" id="d-toggle" aria-expanded="false">Opciones de demostración</button><div class="dctl">' +
    '<label for="d-rol">Ver como <select id="d-rol">' + roles + '</select></label>' +
    '<label for="d-plan">Plan <select id="d-plan"' + (esConsultor() ? ' disabled title="En modo consultora cada empresa conserva su plan"' : '') + '>' + planes + '</select></label>' +
    '<span style="position:relative"><button class="dbtn" id="d-addons" aria-expanded="false">Complementos (' + S.addons.size + ')</button></span>' +
    (verEmp ? '<label for="d-emp">Empresa <select id="d-emp">' + emps + '</select></label>' : '') +
    '<label for="d-tema">Tema <select id="d-tema"><option value="auto"' + (S.theme === 'auto' ? ' selected' : '') + '>Automático</option><option value="light"' + (S.theme === 'light' ? ' selected' : '') + '>Claro</option><option value="dark"' + (S.theme === 'dark' ? ' selected' : '') + '>Oscuro</option></select></label>' +
    '<a class="dbtn" href="#inicio" style="text-decoration:none">Recorrido</a><a class="dbtn" href="#web" style="text-decoration:none">Sitio público</a></div>';
}
function bindDemo() {
  const db = $('#demobar');
  $('#d-toggle').onclick = () => { db.classList.toggle('open'); $('#d-toggle').setAttribute('aria-expanded', db.classList.contains('open')); medirBarra(); };
  $('#d-rol').onchange = (e) => { S.role = e.target.value; ajustarEmpresaRol(); guardar(); toast('Ahora ves el sistema como ' + rolNom(S.role)); location.hash = DEFAULT_RUTA[S.role] || 'm-dsh'; montar(); };
  $('#d-plan').onchange = (e) => { S.plan = e.target.value; guardar(); toast('Plan ' + PLANES[S.plan] + ' activo en la demostración'); montar(); };
  const de = $('#d-emp'); if (de) de.onchange = (e) => { aplicarEmpresa(e.target.value); guardar(); toast('Empresa activa: ' + empresaActual().corto); montar(); };
  $('#d-tema').onchange = (e) => { S.theme = e.target.value; aplicarTema(); guardar(); };
  $('#d-addons').onclick = (e) => {
    e.stopPropagation();
    const ex = $('.addons-pop'); if (ex) { ex.remove(); return; }
    const p = document.createElement('div'); p.className = 'addons-pop';
    p.innerHTML = '<h4 style="margin-bottom:6px">Complementos contratados</h4><p class="small muted" style="margin-bottom:6px">Se suman a cualquier plan. Precio por definir.</p>' + COMPLEMENTOS.map(([c, n, d]) => '<label for="ad-' + c + '"><input type="checkbox" id="ad-' + c + '" data-ad="' + c + '"' + (S.addons.has(c) ? ' checked' : '') + '><span><b>' + esc(n) + '</b> <span class="code">' + c + '</span><br><span class="small muted">' + esc(d) + '</span></span></label>').join('');
    e.target.parentElement.appendChild(p);
    const r = p.getBoundingClientRect(); if (r.right > window.innerWidth - 8) p.style.right = '0';
    $$('[data-ad]', p).forEach((cb) => cb.addEventListener('change', () => { if (cb.checked) S.addons.add(cb.dataset.ad); else S.addons.delete(cb.dataset.ad); guardar(); toast((cb.checked ? 'Complemento activado: ' : 'Complemento desactivado: ') + cb.dataset.ad); montar(true); }));
    p.addEventListener('click', (ev) => ev.stopPropagation());
  };
}
function aplicarEmpresa(id) { S.empresa = id; const e = empresaActual(); S.plan = e.plan; S.addons = new Set(e.addons); }
function ajustarEmpresaRol() {
  if (S.role === 'consultant') aplicarEmpresa(EMPRESAS_CONSULTORA.includes(S.empresa) ? S.empresa : 'andinas');
  else if (S.role === 'external_learner') aplicarEmpresa('qhaliy');
  else if (!EMPRESAS_DEMO.includes(S.empresa)) aplicarEmpresa('andinas');
}
function aplicarTema() { const r = document.documentElement; if (S.theme === 'auto') r.removeAttribute('data-theme'); else r.setAttribute('data-theme', S.theme); }
function medirBarra() { const h = $('#demobar').offsetHeight; document.documentElement.style.setProperty('--dbh', h + 'px'); }

function menu(ruta) {
  const it = (href, icono, txt, extra) => '<a class="solo ' + (ruta === href ? 'on' : '') + '" href="#' + href + '">' + ic(icono) + '<span class="gl">' + esc(txt) + '</span>' + (extra || '') + '</a>';
  let h = '<div class="nav">' + it('inicio', 'play', 'Recorrido del prototipo');
  if (S.role === 'worker') return h + it('app-trabajador', 'phone', 'Mi app de trabajador') + '</div>';
  if (S.role === 'external_learner') return h + '<div class="sep"></div>' + it('alumno-cursos', 'play', 'Mis cursos') + it('alumno-certificados', 'star', 'Mis certificados') + it('alumno-compras', 'file', 'Mis compras') + '<div class="sep"></div>' + it('pub-cursos', 'grid', 'Catálogo de cursos') + '</div>';
  if (S.role === 'trainer') {
    const lk = acceso('AUL') !== 'ok' ? '<span class="lk" style="margin-left:auto">' + ic('lock', 14) + '</span>' : '';
    return h + '<div class="sep"></div>' + it('capacitador', 'user', 'Mi panel de capacitador', lk) + it('m-aul', 'play', 'Aula virtual', lk) + it('aul-banco', 'file', 'Banco de preguntas', lk) + it('aul-recomendaciones', 'spark', 'Recomendaciones', lk) + it('m-cap', 'people', 'Capacitaciones') + it('m-not', 'bell', 'Alertas y notificaciones') + '</div>';
  }
  if (S.role === 'platform_owner' || S.role === 'platform_support') { const full = rutaActual(); const sub = (k, l) => '<a class="it sub ' + (full === 'm-web/' + k || (k === 'resumen' && full === 'm-web') || (k === 'paginas' && full.startsWith('m-web/ed-')) ? 'on' : '') + '" href="#m-web/' + k + '">' + esc(l) + '</a>';
    h += it('m-sus', 'grid', 'Panel de la plataforma') + it('registro', 'user', 'Registro autoservicio') + it('m-aud', 'file', 'Bitácora de auditoría') + '<div class="sep"></div>' + it('m-web', 'globe', 'Sitio web') + '<div class="items wp-subs">' + sub('resumen', 'Resumen') + sub('calendario', 'Calendario de demos') + sub('chatbot', 'Chatbot') + sub('crm', 'Conexión con CRM') + sub('medios', 'Medios') + sub('paginas', 'Páginas y SEO') + sub('planes', 'Planes y precios') + sub('reclamos', 'Libro de Reclamaciones') + sub('demos', 'Solicitudes de demo') + sub('leads', 'Bandeja de leads') + sub('estado', 'Estado del servicio') + sub('legal', 'Datos legales y marca') + '</div>' + it('web', 'eye', 'Ver sitio público'); if (S.role === 'platform_support') h += it('m-usr', 'people', 'Usuarios (soporte)'); return h + '</div>'; }
  if (esConsultor()) h += it('m-con', 'building', 'Panel de la consultora');
  if (rolVe('DSH')) h += it('m-dsh', 'home', 'Panel de inicio');
  if (S.role === 'sst_manager' || S.role === 'account_admin' || esConsultor()) h += it('m-sun', 'shield', 'Modo Inspección SUNAFIL', acceso('SUN') !== 'ok' ? '<span class="lk" style="margin-left:auto">' + ic('lock', 14) + '</span>' : '');
  h += '<div class="sep"></div>';
  Object.entries(CAT.grupos).forEach(([g, gn]) => {
    const ms = MODS.filter((m) => m.grupo === +g && rolVe(m.code) && m.code !== 'DSH' && m.code !== 'SUS' && m.code !== 'WEB');
    if (!ms.length) return;
    const abierto = S.openGroups[g] !== undefined ? S.openGroups[g] : ms.some((m) => 'm-' + m.code.toLowerCase() === ruta) || (g === '5' && /^aul-/.test(ruta)) || g === '2' || g === '3' || g === '4';
    h += '<div class="grp ' + (abierto ? '' : 'closed') + '" data-g="' + g + '"><button class="g" aria-expanded="' + abierto + '">' + ic(GRUPO_IC[g]) + '<span class="gl">' + esc(gn) + '</span><span class="chev">' + ic('chev', 14) + '</span></button><div class="items">' +
      ms.map((m) => {
        const r = 'm-' + m.code.toLowerCase(); const ok = planIncluye(m); const on = featActiva(m.code);
        let a = '<a class="it ' + (ruta === r ? 'on ' : '') + (ok ? (on ? '' : 'off') : 'lock') + '" href="#' + r + '" title="' + esc(ok && !on ? 'Desactivado por la empresa · ' + m.resuelve : m.resuelve) + '">' + esc(m.nombre) + (ok ? (on ? '' : '<span class="offt" aria-label="Desactivado por la empresa">Desactivado</span>') : '<span class="lk" aria-label="No incluido en el plan">' + ic('lock', 13) + '</span>') + '</a>';
        if (m.code === 'AUL' && ok && on) a += '<a class="it sub ' + (ruta === 'aul-banco' ? 'on' : '') + '" href="#aul-banco">Banco de preguntas</a><a class="it sub ' + (ruta === 'aul-recomendaciones' ? 'on' : '') + '" href="#aul-recomendaciones">Recomendaciones</a>';
        if (m.code === 'VTA' && ok && on) a += '<a class="it sub" href="#pub-cursos">Catálogo público</a>';
        return a;
      }).join('') + '</div></div>';
  });
  if (S.role === 'account_admin') h += '<div class="sep"></div>' + it('registro', 'user', 'Registro autoservicio');
  h += '<div class="sep"></div>' + it('app-trabajador', 'phone', 'Vista app del trabajador');
  return h + '</div>';
}

function shell() {
  const u = USUARIOS[S.role];
  const wl = marcaBlanca();
  const emp = empresaActual();
  const coChip = (S.role === 'platform_owner' || S.role === 'platform_support') ? '<div class="co-chip"><b>Plataforma EscudoSST</b><span>Panel interno</span></div>'
    : '<div class="co-chip"><b>' + esc(emp.nombre) + '</b><span>RUC ' + emp.ruc + ' · Plan ' + PLANES[S.plan] + '</span></div>';
  const noLeidas = S.role === 'worker' ? 0 : NOTIFICACIONES.filter((n) => n.u).length;
  document.body.classList.toggle('wl', wl);
  $('#app').innerHTML =
    '<div class="shell ' + (S.collapsed ? 'collapsed' : '') + '" id="shell"><div class="scrim" id="scrim"></div>' +
    '<aside class="side" id="side" aria-label="Menú principal"><a class="brand" href="#inicio">' + LOGO(36, wl) + '<span class="bt"><span class="bn">' + (wl ? 'Prevencia SST' : 'EscudoSST') + '</span><span class="bs">' + (wl ? 'con tecnología EscudoSST' : 'Protege a tu gente') + '</span></span></a>' + coChip + '<div id="nav"></div><div class="foot">Datos de demostración inventados.<br>Precios por definir.</div></aside>' +
    '<div class="main"><header class="topbar"><button class="iconbtn" id="menu-btn" aria-label="Abrir o colapsar el menú">' + ic('menu') + '</button>' +
    '<div class="search"><span>' + ic('search', 16) + '</span><input id="gsearch" type="search" placeholder="Buscar trabajador, módulo o registro" aria-label="Búsqueda global" autocomplete="off"><span class="kbd">/</span></div>' +
    '<div class="user"><button class="iconbtn helpbtn" id="news-btn" aria-label="Novedades del producto" title="Novedades del producto">' + ic('spark') + '<span>Novedades</span></button><button class="iconbtn helpbtn" id="help-btn" aria-label="Ayuda para esta pantalla" title="Ayuda para esta pantalla">' + ic('help') + '<span>Ayuda</span></button><span style="position:relative"><button class="iconbtn" id="bell" aria-label="Notificaciones">' + ic('bell') + (noLeidas ? '<span class="dot">' + noLeidas + '</span>' : '') + '</button></span>' +
    '<div class="who"><b>' + esc(u[0]) + '</b><span>' + esc(u[1]) + '</span></div><span class="av">' + esc(u[2] || iniciales(u[0].replace('Dra. ', ''))) + '</span></div></header>' +
    '<main class="content" id="content" tabindex="-1"></main></div></div>';
  $('#menu-btn').onclick = () => { const sh = $('#shell'); if (window.innerWidth <= 900) sh.classList.toggle('drawer'); else { S.collapsed = !S.collapsed; sh.classList.toggle('collapsed', S.collapsed); guardar(); } };
  $('#scrim').onclick = () => $('#shell').classList.remove('drawer');
  $('#bell').onclick = (e) => { e.stopPropagation(); popNotif(e.currentTarget.parentElement); };
  $('#news-btn').onclick = () => { if (typeof v15NovApp === 'function') v15NovApp(); };
  $('#help-btn').onclick = () => { if (typeof wxAyudaContextual === 'function') wxAyudaContextual(); };
  busquedaGlobal();
}
function pintarMenu(ruta) {
  $('#nav').innerHTML = menu(ruta);
  $$('#nav .grp > .g').forEach((b) => b.addEventListener('click', () => { const g = b.parentElement; g.classList.toggle('closed'); S.openGroups[g.dataset.g] = !g.classList.contains('closed'); b.setAttribute('aria-expanded', !g.classList.contains('closed')); }));
  $$('#nav a').forEach((a) => a.addEventListener('click', () => $('#shell').classList.remove('drawer')));
}
function popNotif(host) {
  const ex = $('.notif-pop'); if (ex) { ex.remove(); return; }
  const p = document.createElement('div'); p.className = 'pop notif-pop';
  const lista = S.role === 'worker' ? [] : NOTIFICACIONES;
  p.innerHTML = '<div class="row between" style="padding:6px 10px"><h4>Notificaciones</h4><button class="btn ghost sm" id="leer">Marcar todo como leído</button></div>' +
    (lista.length ? lista.map((n) => '<a class="notif ' + (n.u ? 'unread' : '') + '" href="#' + n.r + '" style="text-decoration:none;color:inherit">' + sem(n.s === 'info' ? 'na' : n.s) + '<div class="nm"><b>' + esc(n.t) + '</b><div class="small muted">' + esc(n.d) + '</div></div><span class="tiny muted">' + esc(n.h) + '</span></a>').join('') : vacio('Sin notificaciones', 'Te avisaremos cuando algo requiera tu atención.')) +
    '<div style="padding:8px 10px" class="tiny muted">Se envían también por correo y WhatsApp según tus preferencias.</div>';
  host.appendChild(p);
  $('#leer', p).onclick = (e) => { e.stopPropagation(); NOTIFICACIONES.forEach((n) => { n.u = 0; }); p.remove(); const d = $('#bell .dot'); if (d) d.remove(); toast('Notificaciones marcadas como leídas'); };
}
function busquedaGlobal() {
  const inp = $('#gsearch'); const host = inp.parentElement;
  const run = () => {
    const ex = $('.pop', host); if (ex) ex.remove();
    const q = inp.value.trim().toLowerCase(); if (q.length < 2) return;
    const mods = MODS.filter((m) => rolVe(m.code) && (m.nombre + ' ' + m.resuelve + ' ' + m.code).toLowerCase().includes(q)).slice(0, 6);
    const trab = S.role === 'platform_owner' ? [] : W.filter((w) => (w.nombre + ' ' + w.dni + ' ' + w.puesto).toLowerCase().includes(q)).slice(0, 5);
    const acc = ACCIONES.filter((a) => (a.id + ' ' + a.t).toLowerCase().includes(q)).slice(0, 4);
    const p = document.createElement('div'); p.className = 'pop';
    let h = '';
    if (mods.length) h += '<div class="rh">Módulos</div>' + mods.map((m) => '<a class="ri" href="#m-' + m.code.toLowerCase() + '">' + ic(GRUPO_IC[m.grupo], 16) + '<span>' + esc(m.nombre) + '<br><span class="tiny muted">' + esc(m.resuelve) + '</span></span>' + (planIncluye(m) ? '' : '<span style="margin-left:auto">' + ic('lock', 14) + '</span>') + '</a>').join('');
    if (trab.length) h += '<div class="rh">Trabajadores</div>' + trab.map((w) => '<a class="ri" href="#m-trb" data-w="' + w.id + '"><span class="av" style="width:28px;height:28px;font-size:.7rem">' + iniciales(w.nombre) + '</span><span>' + esc(w.nombre) + '<br><span class="tiny muted">DNI ' + w.dni + ' · ' + esc(w.puesto) + '</span></span></a>').join('');
    if (acc.length) h += '<div class="rh">Acciones correctivas</div>' + acc.map((a) => '<a class="ri" href="#m-acr">' + ic('check', 16) + '<span>' + esc(a.id) + ' · ' + esc(a.t) + '</span></a>').join('');
    p.innerHTML = h || '<div class="empty" style="padding:18px">Sin resultados para «' + esc(inp.value) + '». Prueba con un DNI, un apellido o el nombre de un módulo.</div>';
    host.appendChild(p);
    $$('a', p).forEach((a) => a.addEventListener('click', () => { if (a.dataset.w) window.__abrirTrab = +a.dataset.w; inp.value = ''; p.remove(); }));
  };
  inp.addEventListener('input', run);
  inp.addEventListener('keydown', (e) => { if (e.key === 'Escape') { inp.value = ''; run(); } if (e.key === 'Enter') { const a = $('.pop a', host); if (a) a.click(); } });
}
document.addEventListener('keydown', (e) => { if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { const g = $('#gsearch'); if (g) { e.preventDefault(); g.focus(); } } if (e.key === 'Escape') cerrarCapas(); });
document.addEventListener('click', (e) => { if (!e.target.closest('.notif-pop, #bell')) { const p = $('.notif-pop'); if (p) p.remove(); } if (!e.target.closest('.addons-pop, #d-addons')) { const p = $('.addons-pop'); if (p) p.remove(); } if (!e.target.closest('.search')) { const p = $('.search .pop'); if (p) p.remove(); } });

function bloqueado(root, m, motivo) {
  if (motivo === 'empresa') {
    const admin = ['account_admin', 'sst_manager', 'consultant', 'hr'].includes(S.role);
    root.innerHTML = encabezado(m.nombre, '', '', [[CAT.grupos[m.grupo]], [m.nombre]]) + '<div class="card locked"><div class="lic off">' + ic('power', 34) + '</div><h2>Desactivado por la empresa</h2>' +
      '<p class="muted">Tu plan <b>' + PLANES[S.plan] + '</b> incluye «' + esc(m.nombre) + '», pero <b>' + esc(empresaActual().corto) + '</b> lo tiene desactivado. No es un bloqueo del plan: la empresa decide si lo usa.</p>' +
      (m.code === 'VTA' ? nota('La venta de cursos viene desactivada por defecto. Actívala solo si la empresa vende cursos a personas o a otras empresas (por ejemplo, un instituto o una consultora).') : nota('Sin el aula virtual, las capacitaciones se siguen registrando en «Capacitaciones e inducciones» (presenciales).')) +
      '<div class="row" style="justify-content:center">' + (admin ? '<button class="btn pri" id="feat-on">Activar ' + (m.code === 'VTA' ? 'la venta de cursos' : 'el aula virtual') + '</button><a class="btn" href="#m-ten">Ir a Módulos de la empresa</a>' : '<span class="small muted">Pídelo al administrador de tu cuenta.</span>') + '</div></div>';
    const b = $('#feat-on', root); if (b) b.onclick = () => { setFeat(m.code, true); toast('«' + m.nombre + '» activado para ' + empresaActual().corto); render(); };
    return;
  }
  if (motivo === 'rol') {
    root.innerHTML = encabezado(m.nombre, '', '', [[CAT.grupos[m.grupo]], [m.nombre]]) + '<div class="card locked"><div class="lic">' + ic('lock', 34) + '</div><h2>Acceso restringido para tu rol</h2><p class="muted">Como <b>' + esc(rolNom(S.role)) + '</b> no tienes permiso para ver «' + esc(m.nombre) + '». Si lo necesitas, pídelo al administrador de tu cuenta.</p><a class="btn" href="#' + (DEFAULT_RUTA[S.role] || 'm-dsh') + '">Volver a mi inicio</a></div>';
    return;
  }
  root.innerHTML = encabezado(m.nombre, '', '', [[CAT.grupos[m.grupo]], [m.nombre]]) +
    '<div class="card locked"><div class="lic">' + ic('lock', 34) + '</div><h2>Disponible en ' + esc(planTexto(m)) + '</h2><p class="muted">' + esc(m.resuelve) + '. Tu empresa tiene el plan <b>' + PLANES[S.plan] + '</b>' + (S.addons.size ? ' con ' + S.addons.size + ' complemento(s)' : '') + '.</p>' +
    '<div class="grid g3" style="width:100%;text-align:left">' + ['Esencial', 'Profesional', 'Corporativo'].map((p, i) => { const k = 'EPC'[i]; const n = MODS.filter((x) => (CAT.plan_incluye[k] || []).includes(x.plan)).length; return '<div class="card" style="' + (k === m.plan ? 'border-color:var(--brand);border-width:2px' : '') + '"><h4>' + p + '</h4><p class="small muted">' + n + ' módulos</p><p class="small"><b>Precio por definir</b></p></div>'; }).join('') + '</div>' +
    nota('Si bajas de plan, tus datos no se borran: quedan en solo lectura.') +
    '<div class="row" style="justify-content:center">' + (S.role === 'account_admin' || S.role === 'sst_manager' || esConsultor() ? '<button class="btn pri" id="mejorar">Solicitar ' + (m.plan === '+' ? 'el complemento' : 'cambio de plan') + '</button>' : '') + '<a class="btn" href="#m-dsh">Volver al panel</a></div></div>';
  const b = $('#mejorar', root); if (b) b.onclick = () => modal('Solicitud enviada', '<p>Registramos tu interés en ' + esc(planTexto(m)) + '. Un asesor de EscudoSST te contactará. Precio por definir.</p><p class="small muted">En la demostración puedes activarlo desde la barra superior: «Plan» o «Complementos».</p>', [{ l: 'Entendido', cls: 'pri' }]);
}

function rutaActual() { const h = location.hash.replace('#', ''); return h || 'inicio'; }
function render() {
  const rutaComp = rutaActual();
  const ruta = rutaComp.split('/')[0];
  const subRuta = rutaComp.split('/')[1] || null;
  const especial = esEspecial(ruta);
  $('#demobar').innerHTML = demoBar(); bindDemo(); medirBarra();
  if (!esWebRuta(ruta)) { document.body.classList.remove('is-web'); if (typeof restaurarSeo === 'function') restaurarSeo(); }
  if (especial) {
    document.body.classList.remove('wl');
    cerrarCapas();
    $('#app').innerHTML = '<div id="content" class="publicwrap-host"></div>';
    window.scrollTo(0, 0);
    if (esWebRuta(ruta)) R.__web($('#content'), ruta, subRuta); else R[ruta]($('#content'));
    return;
  }
  if (!$('#shell')) shell();
  pintarMenu(ruta);
  const root = $('#content');
  root.innerHTML = '';
  cerrarCapas();
  if (ruta.startsWith('m-')) {
    const code = ruta.slice(2).toUpperCase();
    const m = modBy(code);
    if (!m) { root.innerHTML = vacio('Pantalla no encontrada', 'Vuelve al recorrido.'); return; }
    const a = acceso(code);
    if (a !== 'ok') bloqueado(root, m, a);
    else (R[code] || R.__generico)(root, m, subRuta);
  } else if (R[ruta]) R[ruta](root);
  else root.innerHTML = vacio('Pantalla no encontrada', 'Revisa el enlace o vuelve al recorrido.', '<a class="btn" href="#inicio">Ir al recorrido</a>');
  window.scrollTo(0, 0);
}
function montar(conservarRuta) { $('#app').innerHTML = ''; aplicarTema(); render(); if (!conservarRuta) { /* sin cambios */ } }
window.addEventListener('hashchange', () => { const r = rutaActual().split('/')[0]; if (!$('#shell') && !esEspecial(r)) $('#app').innerHTML = ''; else if ($('#shell') && esEspecial(r)) $('#app').innerHTML = ''; render(); });
window.addEventListener('resize', () => medirBarra());

R.__generico = (root, m) => {
  root.innerHTML = encMod(m.code) + '<div class="card">' + vacio('Pantalla en preparación', 'Este módulo aún no tiene pantalla de ejemplo.') + '</div>';
};
