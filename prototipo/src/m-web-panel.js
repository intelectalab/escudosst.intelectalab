/* Módulo WEB · Panel «Sitio web» del Dueño de la plataforma (#m-web/<sección>). Edita WEB en memoria; el sitio público lo refleja. */
const WP_SECC = [
  ['paginas', 'Páginas', 'layers'], ['menu', 'Menú', 'menu'], ['planes', 'Planes y precios', 'tag'], ['videos', 'Videos', 'play'],
  ['productos', 'Otros productos', 'grid'], ['testimonios', 'Testimonios', 'chat'], ['faq', 'Preguntas frecuentes', 'quiz'], ['blog', 'Blog (WordPress)', 'ext'],
  ['whatsapp', 'WhatsApp', 'phone'], ['politicas', 'Políticas', 'doc2'], ['reclamos', 'Libro de Reclamaciones', 'book'], ['demos', 'Solicitudes de demo', 'cal'],
  ['redirecciones', 'Redirecciones', 'link'], ['legal', 'Datos legales y marca', 'building'],
];
const verSitio = (r, l) => '<a class="btn" href="#' + (r || 'web') + '">' + ic('eye', 16) + esc(l || 'Ver sitio público') + '</a>';
function wpNav(sec) {
  const n = (k) => { if (typeof WX === 'undefined') return 0; if (k === 'leads') return WX.leads.filter((x) => x.status === 'new').length + WEB.demos.filter((d) => (d.lead || 'new') === 'new').length; if (k === 'partners') return WX.partners.filter((p) => p.status === 'pending').length; return 0; };
  const badge = (k) => k === 'reclamos' ? '<b class="wp-badge">' + (WEB.reclamos.filter((r) => r.estado !== 'Respondido').length || '') + '</b>' : k === 'demos' ? '<b class="wp-badge">' + (WEB.demos.filter((d) => d.estado === 'Nueva').length || '') + '</b>' : n(k) ? '<b class="wp-badge">' + n(k) + '</b>' : '';
  const grupo = (t, L) => '<div class="wp-ng" role="group" aria-label="' + t + '"><span class="wp-ngl">' + t + '</span><div class="wp-ngi">' + L.map(([k, l, i]) => '<a href="#m-web/' + k + '" class="' + (sec === k ? 'on' : '') + '"' + (sec === k ? ' aria-current="page"' : '') + '>' + ic(i, 16) + '<span>' + esc(l) + '</span>' + badge(k) + '</a>').join('') + '</div></div>';
  return '<nav class="wp-nav wp-nav2" aria-label="Secciones del sitio web">' + grupo('Sitio', WP_SECC.filter((x) => !x[3])) + grupo('Captación y confianza', WP_SECC.filter((x) => x[3])) + '</nav>';
}
const wpCampo = (id, l, val, opt = {}) => '<label class="field" for="' + id + '"><span>' + esc(l) + (opt.cnt ? ' <em class="wp-cnt" data-cnt="' + id + '" data-max="' + opt.cnt + '"></em>' : '') + '</span>' + (opt.area ? '<textarea id="' + id + '"' + (opt.ph ? ' placeholder="' + esc(opt.ph) + '"' : '') + '>' + esc(val) + '</textarea>' : '<input id="' + id + '" type="' + (opt.tipo || 'text') + '" value="' + esc(val == null ? '' : val) + '"' + (opt.ph ? ' placeholder="' + esc(opt.ph) + '"' : '') + (opt.im ? ' inputmode="' + opt.im + '"' : '') + '>') + (opt.hint ? '<small class="muted tiny">' + opt.hint + '</small>' : '') + '</label>';
function contadores(root) { $$('[data-cnt]', root).forEach((c) => { const i = $('#' + c.dataset.cnt, root); const f = () => { const n = i.value.length; const max = +c.dataset.max; c.textContent = n + '/' + max; c.className = 'wp-cnt ' + (n > max ? 'bad' : n < max * 0.5 ? 'warn' : 'ok'); }; i.addEventListener('input', f); f(); }); }

/* Vista previa de Google y de la tarjeta para redes */
function googlePrev(seo) {
  return '<div class="wp-g" aria-label="Vista previa en Google"><div class="wp-gsite"><span class="wp-gfav">' + WMARK(18) + '</span><span><b>EscudoSST</b><small>https://' + esc(urlSitio(seo.slug === '/' ? '' : seo.slug).replace(/\/$/, '')) + '</small></span></div><h4 class="wp-gt">' + esc(seo.t.length > 62 ? seo.t.slice(0, 60) + '…' : seo.t) + '</h4><p class="wp-gd">' + esc(seo.d.length > 160 ? seo.d.slice(0, 157) + '…' : seo.d) + '</p>' + (seo.noindex ? '<p class="wp-gno">' + ic('eye', 14) + 'Con «noindex» esta página no aparecerá en Google.</p>' : '') + '</div>';
}
function socialPrev(seo) {
  return '<div class="wp-og" aria-label="Vista previa de la tarjeta para redes"><div class="wp-ogimg">' + (seo.og === 'subida' ? '<div class="wp-ogup">' + ic('upload', 26) + '<span>Imagen subida (simulada) · 1200 × 630</span></div>' : '<svg viewBox="0 0 1200 630" aria-hidden="true"><rect width="1200" height="630" fill="#0D3B6E"/><rect y="560" width="1200" height="70" fill="url(#wp-cinta)"/><defs><pattern id="wp-cinta" width="44" height="44" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="44" height="44" fill="#F4B90F"/><rect width="22" height="44" fill="#0A2F58"/></pattern></defs><g transform="translate(80 90) scale(3.2)"><path d="M20 2.5 34.5 7.6v10.6c0 9.3-6.1 16.4-14.5 19.3C11.6 34.6 5.5 27.5 5.5 18.2V7.6Z" fill="#1F7FD1"/><path d="M11.2 23.6c0-5.7 3.9-9.9 8.8-9.9s8.8 4.2 8.8 9.9" fill="#FFFFFF"/><rect x="8.6" y="23.2" width="22.8" height="3.4" rx="1.7" fill="#F4B90F"/></g><text x="80" y="330" fill="#FFFFFF" style="font:600 64px Lexend,system-ui,sans-serif">' + esc(seo.t.split(' · ')[0].slice(0, 30)) + '</text><text x="80" y="410" fill="#9DB3CF" style="font:400 34px system-ui,sans-serif">' + esc(urlSitio()) + '</text></svg>') + '</div><div class="wp-ogt"><small>' + esc(urlSitio().toUpperCase()) + '</small><b>' + esc(seo.t) + '</b><span>' + esc(seo.d) + '</span></div></div>';
}

/* Secciones */
function wpPaginas(root) {
  const filas = Object.entries(WEB.paginas).map(([k, p]) => ({ k, ...p }));
  const kp = [['Páginas publicadas', filas.filter((p) => p.estado === 'Publicado').length], ['Borradores', filas.filter((p) => p.estado === 'Borrador').length], ['Reclamos abiertos', WEB.reclamos.filter((r) => r.estado !== 'Respondido').length], ['Demos nuevas', WEB.demos.filter((d) => d.estado === 'Nueva').length]];
  return '<div class="grid g4" style="margin-bottom:16px">' + kp.map(([l, v]) => kpi(l, v)).join('') + '</div>' +
    tabla([{ l: 'Página', f: (r) => '<b>' + esc(r.n) + '</b>' + (r.tipo ? '<br><span class="tiny muted">' + esc(r.tipo) + '</span>' : '') }, { l: 'URL', f: (r) => '<span class="small muted">' + esc(urlSitio(r.seo.slug === '/' ? '/' : r.seo.slug)) + '</span>' }, { l: 'Estado', f: (r) => chip(r.estado) }, { l: 'SEO', f: (r) => { const okT = r.seo.t.length <= 60 && r.seo.t.length >= 25; const okD = r.seo.d.length <= 160 && r.seo.d.length >= 70; return (okT && okD ? chip('Completo', 'ok') : chip('Revisar', 'warn')) + (r.seo.noindex ? ' ' + chip('noindex', 'na') : ''); } }, { l: 'Editado', k: 'editado' }], filas, { click: true });
}
function bindPaginas(root) { const filas = Object.keys(WEB.paginas); bindTabla(root, filas.map((k) => k), (k) => { location.hash = 'm-web/ed-' + k; }); }

function wpEditor(root, k) {
  const p = WEB.paginas[k]; const a = WEB.ajustes;
  if (!p) { root.innerHTML += vacio('Página no encontrada', 'Vuelve a la lista de páginas.', '<a class="btn" href="#m-web/paginas">Ver páginas</a>'); return; }
  const esPortada = k === 'web';
  const bloques = esPortada ? '<div class="card"><div class="ch"><h3>Bloques de la portada</h3><span class="small muted">Activa, oculta u ordena</span></div><div class="wp-blocks">' + WEB.bloques.map((b, i) => '<div class="wp-block"><span class="wp-drag">' + ic('drag', 16) + '</span><span class="wp-bn">' + esc(b.l) + '</span><span class="wp-bact"><button class="iconbtn" data-bup="' + i + '" aria-label="Subir ' + esc(b.l) + '"' + (i === 0 ? ' disabled' : '') + '>' + ic('up', 16) + '</button><button class="iconbtn" data-bdn="' + i + '" aria-label="Bajar ' + esc(b.l) + '"' + (i === WEB.bloques.length - 1 ? ' disabled' : '') + '>' + ic('dn', 16) + '</button><input type="checkbox" class="switch" role="switch" data-bon="' + i + '" aria-label="Mostrar ' + esc(b.l) + '"' + (b.on ? ' checked' : '') + '></span></div>').join('') + '</div></div>' +
    '<div class="card"><h3>Héroe</h3><div class="stack" style="gap:10px;margin-top:10px">' + wpCampo('ed-lema', 'Título principal (lema)', a.lema) + wpCampo('ed-sub', 'Subtítulo', a.heroSub, { area: true }) + a.ventajas.map((v, i) => wpCampo('ed-v' + i, 'Ventaja ' + (i + 1), v)).join('') + '</div></div>'
    : k === 'web-comparativa' && typeof wpxComparativaEd === 'function' ? wpxComparativaEd() : '<div class="card"><div class="ch"><h3>Bloques de la página</h3><span class="small muted">Editor por bloques simulado</span></div><div class="wp-blocks">' + ['Encabezado con título y subtítulo', 'Contenido principal', 'Preguntas frecuentes', 'Llamado final'].map((b, i) => '<div class="wp-block"><span class="wp-drag">' + ic('drag', 16) + '</span><span class="wp-bn">' + esc(b) + '</span><span class="wp-bact"><button class="btn sm" data-bed="' + i + '">Editar</button></span></div>').join('') + '</div>' + nota('En el sistema real cada bloque se edita aquí con su propio formulario. En el prototipo, edita la portada para ver cambios en vivo.') + '</div>';
  root.insertAdjacentHTML('beforeend', '<div class="wp-ed"><div class="stack">' + bloques + '</div><div class="stack">' +
    '<div class="card"><div class="ch"><h3>Publicación</h3>' + chip(p.estado) + '</div><div class="row"><button class="btn" id="ed-borr">Guardar borrador</button><button class="btn pri" id="ed-pub">Publicar</button>' + verSitio(k.replace('/', '/'), 'Ver en el sitio') + '</div></div>' +
    '<div class="card"><h3>SEO</h3><div class="stack" style="gap:10px;margin-top:10px">' + wpCampo('seo-t', 'Título para buscadores', p.seo.t, { cnt: 60 }) + wpCampo('seo-d', 'Descripción', p.seo.d, { area: true, cnt: 160 }) + wpCampo('seo-u', 'URL amigable', p.seo.slug, { hint: 'https://' + esc(urlSitio()) + '<b id="seo-uh">' + esc(p.seo.slug) + '</b>' }) +
    '<label class="field" for="seo-og"><span>Imagen para redes (Open Graph)</span><select id="seo-og"><option value="auto"' + (p.seo.og === 'auto' ? ' selected' : '') + '>Generada con la marca</option><option value="subida"' + (p.seo.og === 'subida' ? ' selected' : '') + '>Subir imagen (simulado)</option></select></label>' +
    sw('seo-ni', p.seo.noindex, 'No indexar (noindex)', 'La página no aparece en buscadores; sigue accesible por enlace.') + '</div></div>' +
    '<div class="card"><h3>Vista previa en Google</h3><div id="seo-gp" style="margin-top:10px">' + googlePrev(p.seo) + '</div></div>' +
    '<div class="card"><h3>Tarjeta para redes</h3><div id="seo-op" style="margin-top:10px">' + socialPrev(p.seo) + '</div></div></div></div>');
  contadores(root);
  const sync = () => { p.seo.t = $('#seo-t', root).value; p.seo.d = $('#seo-d', root).value; let u = $('#seo-u', root).value.trim(); if (!u.startsWith('/')) u = '/' + u; p.seo.slug = u.toLowerCase().replace(/[^a-z0-9/\-]/g, '-'); p.seo.og = $('#seo-og', root).value; p.seo.noindex = $('#seo-ni', root).checked; $('#seo-uh', root).textContent = p.seo.slug; $('#seo-gp', root).innerHTML = googlePrev(p.seo); $('#seo-op', root).innerHTML = socialPrev(p.seo); p.editado = fmtF(HOY); };
  ['seo-t', 'seo-d', 'seo-u', 'seo-og', 'seo-ni'].forEach((id) => { $('#' + id, root).addEventListener('input', sync); $('#' + id, root).addEventListener('change', sync); });
  $('#ed-borr', root).onclick = () => { p.estado = 'Borrador'; toast('Guardado como borrador: «' + p.n + '» no se muestra al público'); render(); };
  $('#ed-pub', root).onclick = () => { p.estado = 'Publicado'; toast('Publicado: «' + p.n + '»'); render(); };
  if (esPortada) {
    $('#ed-lema', root).addEventListener('input', (e) => { a.lema = e.target.value; });
    $('#ed-sub', root).addEventListener('input', (e) => { a.heroSub = e.target.value; });
    a.ventajas.forEach((_, i) => $('#ed-v' + i, root).addEventListener('input', (e) => { a.ventajas[i] = e.target.value; }));
    const mover = (i, d) => { const b = WEB.bloques; [b[i], b[i + d]] = [b[i + d], b[i]]; render(); };
    $$('[data-bup]', root).forEach((b) => b.addEventListener('click', () => mover(+b.dataset.bup, -1)));
    $$('[data-bdn]', root).forEach((b) => b.addEventListener('click', () => mover(+b.dataset.bdn, 1)));
    $$('[data-bon]', root).forEach((c) => c.addEventListener('change', () => { WEB.bloques[+c.dataset.bon].on = c.checked; toast((c.checked ? 'Bloque visible: ' : 'Bloque oculto: ') + WEB.bloques[+c.dataset.bon].l); }));
  }
  $$('[data-bed]', root).forEach((b) => b.addEventListener('click', () => toast('Editor del bloque (simulado)')));
  if (k === 'web-comparativa' && typeof wpxBindComparativa === 'function') wpxBindComparativa(root);
}

function wpMenu(root) {
  root.insertAdjacentHTML('beforeend', '<div class="card" style="max-width:760px"><div class="ch"><h3>Menú principal</h3>' + verSitio('web') + '</div><div class="wp-blocks">' + WEB.menu.map((m, i) => '<div class="wp-block"><span class="wp-drag">' + ic('drag', 16) + '</span><label class="sr" for="mn-' + i + '">Texto de «' + esc(m.l) + '»</label><input class="input wp-in" id="mn-' + i + '" value="' + esc(m.l) + '"><span class="small muted wp-dest">' + (m.r === 'blog' ? 'WordPress: ' + esc(WEB.ajustes.blogUrl) : '/' + esc(m.r.replace(/^web-?/, '').replace('/', '#'))) + '</span><span class="wp-bact"><button class="iconbtn" data-mup="' + i + '" aria-label="Subir"' + (i === 0 ? ' disabled' : '') + '>' + ic('up', 16) + '</button><button class="iconbtn" data-mdn="' + i + '" aria-label="Bajar"' + (i === WEB.menu.length - 1 ? ' disabled' : '') + '>' + ic('dn', 16) + '</button><input type="checkbox" class="switch" role="switch" data-mon="' + i + '" aria-label="Mostrar en el menú"' + (m.on ? ' checked' : '') + '></span></div>').join('') + '</div>' + nota('«Productos» y «Sectores» se despliegan con sus submenús. «Iniciar sesión», «Agenda una demo» y «Prueba gratis» siempre están visibles.') + '</div>');
  WEB.menu.forEach((m, i) => $('#mn-' + i, root).addEventListener('input', (e) => { m.l = e.target.value || m.l; }));
  const mover = (i, d) => { const b = WEB.menu; [b[i], b[i + d]] = [b[i + d], b[i]]; render(); };
  $$('[data-mup]', root).forEach((b) => b.addEventListener('click', () => mover(+b.dataset.mup, -1)));
  $$('[data-mdn]', root).forEach((b) => b.addEventListener('click', () => mover(+b.dataset.mdn, 1)));
  $$('[data-mon]', root).forEach((c) => c.addEventListener('change', () => { WEB.menu[+c.dataset.mon].on = c.checked; toast('Menú actualizado'); }));
}

function wpPlanes(root) {
  const num = (v) => (v == null ? '' : v);
  root.insertAdjacentHTML('beforeend', nota('Si un plan no tiene precio, el sitio muestra <b>«Solicitar cotización»</b>. Escribe un precio para ver cómo cambia la tarjeta. Los precios de EscudoSST aún no están definidos: en la demostración nada se guarda.', 'warn') +
    '<div class="wp-plans">' + WEB.planes.map((p, i) => '<div class="card"><div class="ch"><h3>' + esc(p.nombre) + '</h3>' + (p.destacado ? chip('Recomendado', 'info') : '') + '</div><div class="stack" style="gap:10px">' +
      '<div class="grid g2">' + wpCampo('pp-m' + i, 'Precio mensual (S/)', num(p.m), { tipo: 'number', ph: 'Sin precio', im: 'decimal' }) + wpCampo('pp-a' + i, 'Precio anual (S/)', num(p.a), { tipo: 'number', ph: 'Sin precio', im: 'decimal' }) + '</div>' +
      p.lim.map((l, j) => wpCampo('pp-l' + i + '-' + j, 'Límite o ventaja ' + (j + 1), l)).join('') +
      sw('pp-pub' + i, p.publicado, 'Visible en el sitio', '') + '<label class="row small" for="pp-d' + i + '"><input type="radio" name="pp-dest" id="pp-d' + i + '"' + (p.destacado ? ' checked' : '') + '> Destacar como «Recomendado»</label></div></div>').join('') + '</div>' +
    '<div class="card" style="margin-top:14px"><div class="ch"><h3>Complementos</h3>' + sw('pp-igv', WEB.ajustes.igv, 'Mostrar «más IGV» junto al precio', '') + '</div>' + tabla([{ l: 'Complemento', f: (r) => '<b>' + esc(r[1]) + '</b> <span class="tiny muted">' + r[0] + '</span>' }, { l: 'Mensual (S/)', f: (r) => '<input class="input" type="number" inputmode="decimal" placeholder="Sin precio" aria-label="Precio mensual de ' + esc(r[1]) + '" data-adm="' + r[0] + '" value="' + num((WEB.addonsPrecio[r[0]] || {}).m) + '">' }, { l: 'Anual (S/)', f: (r) => '<input class="input" type="number" inputmode="decimal" placeholder="Sin precio" aria-label="Precio anual de ' + esc(r[1]) + '" data-ada="' + r[0] + '" value="' + num((WEB.addonsPrecio[r[0]] || {}).a) + '">' }], COMPLEMENTOS) + '</div>' +
    '<div class="card" style="margin-top:14px"><div class="ch"><h3>Vista previa de las tarjetas</h3><div class="row">' + seg('pp-per', ['Mensual', 'Anual'], WUI.periodo === 'a' ? 1 : 0) + verSitio('web-planes', 'Ver en el sitio') + '</div></div><div class="w wp-prev" id="pp-prev">' + planCards(WUI.periodo) + '</div></div>');
  const prev = () => { $('#pp-prev', root).innerHTML = planCards(WUI.periodo); };
  const val = (x) => { const t = String(x).trim(); return t === '' ? null : Math.max(0, +t); };
  WEB.planes.forEach((p, i) => {
    $('#pp-m' + i, root).addEventListener('input', (e) => { p.m = val(e.target.value); prev(); });
    $('#pp-a' + i, root).addEventListener('input', (e) => { p.a = val(e.target.value); prev(); });
    p.lim.forEach((_, j) => $('#pp-l' + i + '-' + j, root).addEventListener('input', (e) => { p.lim[j] = e.target.value; prev(); }));
    $('#pp-pub' + i, root).addEventListener('change', (e) => { p.publicado = e.target.checked; prev(); });
    $('#pp-d' + i, root).addEventListener('change', () => { WEB.planes.forEach((x) => { x.destacado = x === p; }); prev(); });
  });
  $('#pp-igv', root).addEventListener('change', (e) => { WEB.ajustes.igv = e.target.checked; prev(); });
  $$('[data-adm], [data-ada]', root).forEach((inp) => inp.addEventListener('input', () => { const c = inp.dataset.adm || inp.dataset.ada; const o = WEB.addonsPrecio[c] || (WEB.addonsPrecio[c] = {}); o[inp.dataset.adm ? 'm' : 'a'] = val(inp.value); }));
  bindSeg(root, 'pp-per', (i) => { WUI.periodo = i ? 'a' : 'm'; $$('[data-seg="pp-per"] button', root).forEach((b, j) => b.classList.toggle('on', i === j)); prev(); });
}

function wpVideos(root) {
  root.insertAdjacentHTML('beforeend', '<div class="grid g2">' + WEB.videos.map((v, i) => '<div class="card"><div class="ch"><h3>Video ' + (i + 1) + '</h3>' + sw('vd-on' + i, v.on, 'Visible', '') + '</div><div class="stack" style="gap:10px">' + wpCampo('vd-t' + i, 'Título', v.t) + wpCampo('vd-d' + i, 'Descripción', v.d, { area: true }) +
    '<label class="field" for="vd-p' + i + '"><span>Plataforma</span><select id="vd-p' + i + '"><option' + (v.prov === 'YouTube' ? ' selected' : '') + '>YouTube</option><option' + (v.prov === 'Vimeo' ? ' selected' : '') + '>Vimeo</option></select></label>' + wpCampo('vd-u' + i, 'Enlace del video', v.url, { ph: v.prov === 'YouTube' ? 'https://www.youtube.com/watch?v=…' : 'https://vimeo.com/…', hint: 'Se incrusta en la portada. Vacío: se muestra la miniatura dibujada y un aviso.' }) + '</div></div>').join('') + '</div>' + '<div class="row" style="margin-top:12px">' + verSitio('web/videos', 'Ver en la portada') + '</div>');
  WEB.videos.forEach((v, i) => {
    $('#vd-t' + i, root).addEventListener('input', (e) => { v.t = e.target.value; }); $('#vd-d' + i, root).addEventListener('input', (e) => { v.d = e.target.value; });
    $('#vd-p' + i, root).addEventListener('change', (e) => { v.prov = e.target.value; }); $('#vd-u' + i, root).addEventListener('input', (e) => { v.url = e.target.value.trim(); });
    $('#vd-on' + i, root).addEventListener('change', (e) => { v.on = e.target.checked; toast(v.on ? 'Video visible' : 'Video oculto'); });
  });
}

function wpProductos(root) {
  root.insertAdjacentHTML('beforeend', nota('Tarjetas de «Otros productos de ' + esc(WEB.ajustes.razon) + '» en la portada. Los 7 productos llevan la etiqueta «Descripción editable» hasta que revises su texto; el enlace queda vacío hasta que lo escribas.') + '<div class="wp-prods">' + WEB.productos.map((p, i) => '<div class="card"><div class="ch"><span class="w-plogo" style="background:' + esc(p.color) + '" aria-hidden="true">' + (p.logo ? '<img src="' + p.logo + '" alt="">' : esc(p.ini)) + '</span>' + sw('pr-on' + i, p.on, 'Visible', '') + '</div><div class="stack" style="gap:10px">' + wpCampo('pr-n' + i, 'Nombre', p.n) + wpCampo('pr-d' + i, 'Descripción corta', p.d) + wpCampo('pr-u' + i, 'Enlace', p.url, { ph: 'https://…' }) +
    '<div class="row"><label class="btn sm" for="pr-f' + i + '">' + ic('upload', 14) + 'Subir logo</label><input type="file" id="pr-f' + i + '" accept="image/*" class="sr"><label class="row small" for="pr-c' + i + '">Color <input type="color" id="pr-c' + i + '" value="' + esc(p.color) + '"></label><button class="btn sm danger" data-prdel="' + i + '">Quitar</button></div></div></div>').join('') + '</div><div class="row" style="margin-top:12px"><button class="btn pri" id="pr-add">' + ic('plus', 16) + 'Agregar producto</button>' + verSitio('web/productos', 'Ver en la portada') + '</div>');
  WEB.productos.forEach((p, i) => {
    $('#pr-n' + i, root).addEventListener('input', (e) => { p.n = e.target.value; p.ini = iniciales(p.n || 'P').toUpperCase(); });
    $('#pr-d' + i, root).addEventListener('input', (e) => { p.d = e.target.value; }); $('#pr-u' + i, root).addEventListener('input', (e) => { p.url = e.target.value.trim(); });
    $('#pr-on' + i, root).addEventListener('change', (e) => { p.on = e.target.checked; });
    $('#pr-c' + i, root).addEventListener('input', (e) => { p.color = e.target.value; });
    $('#pr-f' + i, root).addEventListener('change', (e) => leerImagen(e.target.files[0], (url) => { p.logo = url; toast('Logo de «' + p.n + '» cargado'); render(); }));
  });
  $$('[data-prdel]', root).forEach((b) => b.addEventListener('click', () => { WEB.productos.splice(+b.dataset.prdel, 1); toast('Producto quitado'); render(); }));
  $('#pr-add', root).onclick = () => { WEB.productos.push({ id: Date.now(), n: 'Nuevo producto', d: 'Descripción corta', url: '', color: '#1F7FD1', ini: 'NP', on: true }); render(); };
}
function leerImagen(f, cb) { if (!f) return; if (!/^image\//.test(f.type) || f.size > 1024 * 1024) { toast('Sube una imagen de hasta 1 MB', 'warn'); return; } const r = new FileReader(); r.onload = () => cb(r.result); r.readAsDataURL(f); }

function wpTestimonios(root) {
  root.insertAdjacentHTML('beforeend', nota('Solo los aprobados se muestran en la portada. Los de la demostración son de ejemplo y se rotulan «Ejemplo»; no usan nombres reales.') +
    tabla([{ l: 'Persona', f: (r) => '<span class="row" style="gap:8px;flex-wrap:nowrap"><span class="av" style="width:30px;height:30px;font-size:.72rem">' + esc(r.ini) + '</span><span><b>' + esc(r.cargo) + '</b><br><span class="tiny muted">' + esc(r.emp) + '</span></span></span>' }, { l: 'Comentario', f: (r) => '<span class="small">' + esc(r.txt) + '</span>' }, { l: 'Estado', f: (r) => chip(r.estado === 'Aprobado' ? 'Aprobado' : r.estado === 'Oculto' ? 'Archivado' : 'Pendiente') }, { l: 'Acciones', f: (r) => '<span class="row" style="flex-wrap:nowrap">' + (r.estado !== 'Aprobado' ? '<button class="btn sm pri" data-tap="' + r.id + '">Aprobar</button>' : '<button class="btn sm" data-toc="' + r.id + '">Ocultar</button>') + '</span>' }], WEB.testimonios) +
    '<div class="row" style="margin-top:12px"><button class="btn pri" id="ts-add">' + ic('plus', 16) + 'Agregar testimonio</button>' + verSitio('web', 'Ver en la portada') + '</div>');
  $$('[data-tap]', root).forEach((b) => b.addEventListener('click', () => { WEB.testimonios.find((t) => t.id === +b.dataset.tap).estado = 'Aprobado'; toast('Testimonio aprobado: ya se muestra en la portada'); render(); }));
  $$('[data-toc]', root).forEach((b) => b.addEventListener('click', () => { WEB.testimonios.find((t) => t.id === +b.dataset.toc).estado = 'Oculto'; toast('Testimonio oculto'); render(); }));
  $('#ts-add', root).onclick = () => modal('Agregar testimonio', '<div class="stack" style="gap:10px">' + wpCampo('tn-c', 'Cargo', '') + wpCampo('tn-e', 'Empresa (descripción genérica)', '', { ph: 'Por ejemplo: empresa minera, Cajamarca' }) + wpCampo('tn-t', 'Comentario', '', { area: true }) + nota('Para publicar el nombre de una persona o empresa real necesitas su autorización por escrito.') + '</div>', [{ l: 'Cancelar' }, { l: 'Guardar como pendiente', cls: 'pri', fn: (ov) => { const c = $('#tn-c', ov).value.trim(); const t = $('#tn-t', ov).value.trim(); if (!c || !t) { toast('Completa el cargo y el comentario', 'warn'); return false; } WEB.testimonios.push({ id: Date.now(), ini: iniciales(c).toUpperCase(), cargo: c, emp: $('#tn-e', ov).value.trim() || 'Empresa', txt: t, estado: 'Pendiente' }); toast('Testimonio guardado: pendiente de aprobación'); render(); } }]);
}

function wpFaq(root) {
  root.insertAdjacentHTML('beforeend', tabla([{ l: 'Pregunta', f: (r) => '<b>' + esc(r.q) + '</b><br><span class="tiny muted">' + esc(r.a.slice(0, 90)) + '…</span>' }, { l: 'Página', f: (r) => chip(r.p === 'portada' ? 'Portada' : 'Planes', 'info') }, { l: '', f: (r) => '<button class="btn sm danger" data-fdel="' + r.id + '" aria-label="Quitar la pregunta">' + ic('trash', 14) + '</button>' }], WEB.faqs, { click: true }) + '<div class="row" style="margin-top:12px"><button class="btn pri" id="fq-add">' + ic('plus', 16) + 'Agregar pregunta</button>' + verSitio('web/faq') + '</div>');
  const editar = (f) => modal(f ? 'Editar pregunta' : 'Agregar pregunta', '<div class="stack" style="gap:10px">' + wpCampo('fq-q', 'Pregunta', f ? f.q : '') + wpCampo('fq-a', 'Respuesta', f ? f.a : '', { area: true }) + '<label class="field" for="fq-p"><span>Página</span><select id="fq-p"><option value="portada"' + (!f || f.p === 'portada' ? ' selected' : '') + '>Portada</option><option value="planes"' + (f && f.p === 'planes' ? ' selected' : '') + '>Planes y precios</option></select></label></div>', [{ l: 'Cancelar' }, { l: 'Guardar', cls: 'pri', fn: (ov) => { const q = $('#fq-q', ov).value.trim(); const a = $('#fq-a', ov).value.trim(); if (!q || !a) { toast('Completa la pregunta y la respuesta', 'warn'); return false; } if (f) Object.assign(f, { q, a, p: $('#fq-p', ov).value }); else WEB.faqs.push({ id: Date.now(), q, a, p: $('#fq-p', ov).value }); toast('Pregunta guardada'); render(); } }]);
  bindTabla(root, WEB.faqs, editar);
  $$('[data-fdel]', root).forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); const i = WEB.faqs.findIndex((f) => f.id === +b.dataset.fdel); WEB.faqs.splice(i, 1); toast('Pregunta quitada'); render(); }));
  $('#fq-add', root).onclick = () => editar(null);
}

function wpBlog(root) {
  const a = WEB.ajustes;
  root.insertAdjacentHTML('beforeend', '<div class="card" style="max-width:760px"><h3>Blog en WordPress</h3><p class="small muted" style="margin-top:4px">El blog se crea y edita en WordPress, aparte de EscudoSST. El menú «Blog» y el pie enlazan a esta dirección.</p><div class="stack" style="gap:12px;margin-top:12px">' +
    wpCampo('bl-u', 'URL del blog en WordPress', a.blogUrl, { ph: 'blog.escudosst.com', hint: 'Puede ser un subdominio o una ruta, por ejemplo escudosst.com/blog.' }) + sw('bl-p', a.blogPortada, 'Mostrar últimos artículos en la portada', 'Se leen de la API REST pública de WordPress con caché. En el prototipo son de ejemplo.') + '</div></div>' +
    '<div class="card" style="max-width:760px;margin-top:14px"><h3>Últimos artículos (ejemplo)</h3>' + tabla([{ l: 'Artículo', f: (r) => '<b>' + esc(r.t) + '</b>' }, { l: 'Categoría', k: 'cat' }, { l: 'Fecha', k: 'f' }], WEB.ultimos) + '<div class="row" style="margin-top:10px">' + verSitio('web/blog', 'Ver en la portada') + '</div></div>');
  $('#bl-u', root).addEventListener('input', (e) => { a.blogUrl = e.target.value.trim().replace(/^https?:\/\//, ''); });
  $('#bl-p', root).addEventListener('change', (e) => { a.blogPortada = e.target.checked; WEB.bloques.find((b) => b.id === 'blog').on = e.target.checked; toast(e.target.checked ? 'Últimos artículos visibles en la portada' : 'Últimos artículos ocultos'); });
}

function wpWhatsapp(root) {
  const w = WEB.whatsapp;
  const etq = { web: 'Portada', 'web-planes': 'Planes y precios', 'web-contacto': 'Contacto y demo', 'web-consultoras': 'Consultoras', sector: 'Páginas por sector ({sector} se reemplaza)', otras: 'Demás páginas' };
  root.insertAdjacentHTML('beforeend', '<div class="grid g-main"><div class="card"><h3>Botón flotante de WhatsApp</h3><div class="stack" style="gap:12px;margin-top:10px">' + wpCampo('wa-n', 'Número de WhatsApp (Perú)', w.numero, { im: 'tel', ph: '9XXXXXXXX', hint: 'Se antepone +51. Vacío: el botón avisa que el número está por definir.' }) +
    Object.keys(w.msgs).map((k) => wpCampo('wa-m-' + k, 'Mensaje en ' + etq[k], w.msgs[k], { area: true })).join('') + '</div></div>' +
    '<div class="card"><h3>Vista previa</h3><div class="wp-wa" id="wa-prev"></div><div class="row" style="margin-top:10px">' + verSitio('web-planes', 'Probar en Planes') + '</div></div></div>');
  const prev = () => { const n = w.numero.replace(/\D/g, ''); $('#wa-prev', root).innerHTML = '<p class="small muted">Enlace de la portada</p><code class="wp-code">https://wa.me/' + (n ? '51' + n : '…') + '?text=' + encodeURIComponent(w.msgs.web).slice(0, 60) + '…</code><div class="wp-bubble">' + esc(w.msgs.web) + '</div>' + (n && !/^9\d{8}$/.test(n) ? nota('El número debe tener 9 dígitos y empezar con 9.', 'warn') : ''); };
  $('#wa-n', root).addEventListener('input', (e) => { w.numero = e.target.value.replace(/\D/g, '').slice(0, 9); prev(); });
  Object.keys(w.msgs).forEach((k) => $('#wa-m-' + k, root).addEventListener('input', (e) => { w.msgs[k] = e.target.value; prev(); }));
  prev();
}

function wpPoliticas(root) {
  root.insertAdjacentHTML('beforeend', nota('Los textos legales se redactan y validan con un abogado. Al publicar una versión que «requiere re-aceptación», los usuarios deben aceptarla de nuevo al entrar; cada aceptación queda registrada y no se puede modificar.', 'warn') +
    tabla([{ l: 'Política', f: (r) => '<b>' + esc(r.t) + '</b>' }, { l: 'Versión vigente', f: (r) => { const v = r.vers.find((x) => x.estado === 'Vigente'); return '<b>' + esc(v.v) + '</b> <span class="tiny muted">desde ' + esc(v.f) + '</span>' + (v.re ? ' ' + chip('Re-aceptación', 'warn') : ''); } }, { l: 'Versiones', f: (r) => r.vers.length }, { l: '', f: (r) => '<button class="btn sm pri" data-pnew="' + r.id + '">Publicar nueva versión</button>' }], WEB.politicas, { click: true }) +
    '<h3 style="margin:18px 0 8px">Registro de aceptaciones</h3><p class="small muted" style="margin-bottom:8px">Inalterable: solo se agregan filas. IP enmascarada en la vista.</p>' + tabla([{ l: 'Cuenta', f: (r) => esc(r[0]) }, { l: 'Documento', f: (r) => esc(r[1]) + ' <span class="tiny muted">v' + esc(r[2]) + '</span>' }, { l: 'Fecha', f: (r) => esc(r[3]) }, { l: 'IP', f: (r) => esc(r[4]) }, { l: 'Navegador', f: (r) => esc(r[5]) }], WEB.aceptaciones));
  const nueva = (p) => { const vig = p.vers.find((x) => x.estado === 'Vigente'); const sig = (Math.floor(+vig.v) + (vig.v.includes('.') ? 0 : 0)) + '.' + (+(vig.v.split('.')[1] || 0) + 1);
    modal('Publicar nueva versión · ' + p.t, '<div class="stack" style="gap:10px">' + wpCampo('pv-v', 'Número de versión', sig) + wpCampo('pv-n', 'Resumen de cambios', '', { area: true, ph: 'Qué cambió y por qué' }) + sw('pv-re', true, 'Requiere re-aceptación', 'Los usuarios y clientes deberán aceptar esta versión al entrar.') + nota('Confirma que el texto fue validado por un abogado antes de publicar.') + '</div>', [{ l: 'Cancelar' }, { l: 'Publicar versión', cls: 'pri', fn: (ov) => { const v = $('#pv-v', ov).value.trim(); const n = $('#pv-n', ov).value.trim(); if (!v || !n) { toast('Escribe la versión y el resumen de cambios', 'warn'); return false; } if (p.vers.some((x) => x.v === v)) { toast('Esa versión ya existe', 'warn'); return false; } p.vers.forEach((x) => { x.estado = 'Archivada'; }); p.vers.unshift({ v, f: fmtF(HOY), estado: 'Vigente', re: $('#pv-re', ov).checked, nota: n }); toast('Versión ' + v + ' publicada' + ($('#pv-re', ov).checked ? ': se pedirá re-aceptación' : '')); render(); } }]); };
  bindTabla(root, WEB.politicas, (p) => cajon(p.t, 'Historial de versiones', tabla([{ l: 'Versión', f: (v) => '<b>' + esc(v.v) + '</b>' }, { l: 'Fecha', k: 'f' }, { l: 'Estado', f: (v) => chip(v.estado === 'Vigente' ? 'Vigente' : 'Archivada', v.estado === 'Vigente' ? 'ok' : 'na') }, { l: 'Cambios', f: (v) => esc(v.nota) + (v.re ? ' ' + chip('Re-aceptación', 'warn') : '') }], p.vers, { noResp: true }) + '<h4 style="margin:14px 0 6px">Secciones</h4><ol class="small">' + p.sec.map((s) => '<li>' + esc(s) + '</li>').join('') + '</ol>', [{ l: 'Ver en el sitio', fn: () => { location.hash = 'web-legal-' + p.id; } }, { l: 'Publicar nueva versión', cls: 'pri', fn: () => { setTimeout(() => nueva(p), 0); } }]));
  $$('[data-pnew]', root).forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); nueva(WEB.politicas.find((p) => p.id === b.dataset.pnew)); }));
}

function semPlazo(r) {
  if (r.estado === 'Respondido') return '<span class="row" style="gap:6px;flex-wrap:nowrap">' + sem('na') + '<span class="small">Atendido</span></span>';
  const n = habilesHasta(r.vence); const c = n < 0 ? 'bad' : n <= 3 ? 'warn' : 'ok';
  return '<span class="row" style="gap:6px;flex-wrap:nowrap">' + sem(c) + '<span class="small">' + (n < 0 ? 'Vencido hace ' + (-n) + ' d. háb.' : n === 0 ? 'Vence hoy' : n + ' d. háb.') + '</span></span>';
}
function wpReclamos(root) {
  const L = WEB.reclamos.slice().reverse();
  const ab = L.filter((r) => r.estado !== 'Respondido');
  const kp = [['Abiertos', ab.length], ['Por vencer (3 d. háb.)', ab.filter((r) => { const n = habilesHasta(r.vence); return n >= 0 && n <= 3; }).length], ['Vencidos', ab.filter((r) => habilesHasta(r.vence) < 0).length], ['Respondidos', L.length - ab.length]];
  root.insertAdjacentHTML('beforeend', '<div class="grid g4" style="margin-bottom:14px">' + kp.map(([l, v]) => kpi(l, v)).join('') + '</div>' +
    nota('Plazo de respuesta: ' + param(WEB.ajustes.plazoLibro + ' días hábiles') + ', prórroga: ' + param(WEB.ajustes.prorrogaLibro + ' días hábiles') + ', conservación: ' + param(WEB.ajustes.conservaLibro + ' años') + '. Las hojas no se pueden eliminar. Validar con abogado. Responsable: <b>' + esc(WEB.ajustes.respLibro) + '</b>.') +
    '<div class="row" style="margin:12px 0"><button class="btn" id="lr-ind">' + ic('down', 16) + 'Reporte para Indecopi</button>' + verSitio('web-reclamaciones', 'Ver el formulario público') + '</div>' +
    tabla([{ l: 'Código', f: (r) => '<b class="num">' + esc(r.cod) + '</b>' }, { l: 'Fecha', k: 'f' }, { l: 'Tipo', f: (r) => chip(r.tipo, r.tipo === 'Reclamo' ? 'info' : 'na') }, { l: 'Consumidor', k: 'nombre' }, { l: 'Estado', f: (r) => chip(r.estado === 'Nuevo' ? 'Pendiente' : r.estado === 'Respondido' ? 'Resuelto' : r.estado === 'Prorrogado' ? 'En proceso' : 'En proceso') + (r.estado === 'Prorrogado' ? ' <span class="tiny muted">prorrogado</span>' : '') }, { l: 'Plazo', f: semPlazo }], L, { click: true }));
  bindTabla(root, L, (r) => fichaReclamo(r));
  $('#lr-ind', root).onclick = () => modal('Reporte para Indecopi', '<div class="grid g2">' + campo('ri-d', 'Desde', 'date', '2026-10-01') + campo('ri-h', 'Hasta', 'date', '2026-10-31') + '</div>' + tabla([{ l: 'Concepto', f: (x) => x[0] }, { l: 'Cantidad', f: (x) => '<b>' + x[1] + '</b>' }], [['Hojas registradas', L.length], ['Reclamos', L.filter((r) => r.tipo === 'Reclamo').length], ['Quejas', L.filter((r) => r.tipo === 'Queja').length], ['Respondidas en plazo', L.filter((r) => r.estado === 'Respondido').length], ['Con prórroga', L.filter((r) => r.hist.some((h) => /Prórroga/.test(h[1]))).length]], { noResp: true }) + '<p class="tiny muted" style="margin-top:8px">El formato del reporte se confirma con el área legal.</p>', [{ l: 'Cancelar' }, { l: 'Excel', fn: () => toast('Reporte en Excel generado (simulado)') }, { l: 'PDF', cls: 'pri', fn: () => toast('Reporte en PDF generado (simulado)') }]);
}
function fichaReclamo(r) {
  const abierto = r.estado !== 'Respondido';
  cajon(r.cod, r.tipo + ' · registrada el ' + r.f, dl([['Estado', chip(r.estado === 'Nuevo' ? 'Pendiente' : r.estado)], ['Plazo', semPlazo(r) + ' <span class="tiny muted">hasta ' + esc(r.vence) + '</span>'], ['Consumidor', esc(r.nombre)], ['Documento', esc(r.doc)], ['Correo', esc(r.correo)], ['Bien', esc(r.bien) + (r.descBien ? ': ' + esc(r.descBien) : '')], ['Monto', r.monto ? 'S/ ' + esc(r.monto) : 'No indica'], ['Detalle', esc(r.det)], ['Pedido', esc(r.ped)]].concat(r.apod ? [['Apoderado', esc(r.apod)]] : []).concat(r.resp ? [['Respuesta', esc(r.resp)]] : [])) +
    '<h4 style="margin:14px 0 6px">Historial (inalterable)</h4><ol class="w-tl wp-tl">' + r.hist.map(([f, t]) => '<li><time>' + esc(f) + '</time><span>' + esc(t) + '</span></li>').join('') + '</ol>',
  abierto ? [{ l: 'Prorrogar', fn: () => { setTimeout(() => prorrogar(r), 0); } }, { l: 'Responder', cls: 'pri', fn: () => { setTimeout(() => responder(r), 0); } }] : [{ l: 'Descargar hoja', fn: () => toast('PDF de la hoja generado (simulado)') }, { l: 'Cerrar', cls: 'pri' }]);
}
const ahoraTxt = () => { const n = new Date(); return fmtF(HOY) + ' ' + String(n.getHours()).padStart(2, '0') + ':' + String(n.getMinutes()).padStart(2, '0'); };
function responder(r) {
  modal('Responder ' + r.cod, '<p class="small muted" style="margin-bottom:8px">La respuesta se envía al correo del consumidor y queda en el historial.</p>' + wpCampo('rp-t', 'Respuesta', '', { area: true }), [{ l: 'Cancelar' }, { l: 'Enviar respuesta', cls: 'pri', fn: (ov) => { const t = $('#rp-t', ov).value.trim(); if (t.length < 10) { toast('Escribe una respuesta más completa', 'warn'); return false; } r.estado = 'Respondido'; r.resp = t; r.hist.push([ahoraTxt(), 'Respuesta enviada al consumidor']); toast('Respuesta enviada a ' + r.correo + ' (simulado)'); render(); } }]);
}
function prorrogar(r) {
  modal('Prorrogar ' + r.cod, '<p class="small muted" style="margin-bottom:8px">La prórroga se informa al consumidor antes del vencimiento, con su motivo. Plazo adicional: ' + param(WEB.ajustes.prorrogaLibro + ' días hábiles') + '.</p>' + wpCampo('pr-m', 'Motivo de la prórroga', '', { area: true }), [{ l: 'Cancelar' }, { l: 'Prorrogar', cls: 'pri', fn: (ov) => { const m = $('#pr-m', ov).value.trim(); if (m.length < 10) { toast('Explica el motivo de la prórroga', 'warn'); return false; } r.estado = 'Prorrogado'; r.vence = fmtF(sumarHabiles(parseF(r.vence), WEB.ajustes.prorrogaLibro)); r.hist.push([ahoraTxt(), 'Prórroga: ' + m]); toast('Plazo prorrogado hasta el ' + r.vence); render(); } }]);
}

const DEMO_EST = ['Nueva', 'Contactada', 'Demo agendada', 'Ganada', 'Descartada'];
function wpDemos(root) {
  const f = WUI.demoF || '';
  const L = WEB.demos.filter((d) => !f || d.estado === f);
  root.insertAdjacentHTML('beforeend', '<div class="grid g4" style="margin-bottom:14px">' + DEMO_EST.slice(0, 4).map((e) => kpi(e, WEB.demos.filter((d) => d.estado === e).length)).join('') + '</div>' +
    '<div class="filters"><select class="input" id="dm-f" aria-label="Filtrar por estado"><option value="">Estado: todos</option>' + DEMO_EST.map((e) => '<option' + (f === e ? ' selected' : '') + '>' + e + '</option>').join('') + '</select><span class="muted small" style="margin-left:auto">' + L.length + ' de ' + WEB.demos.length + '</span>' + btnExport() + '</div>' +
    tabla([{ l: 'Solicitud', f: (r) => '<b>' + esc(r.nombre) + '</b><br><span class="tiny muted">' + esc(r.id) + ' · ' + esc(r.f) + '</span>' }, { l: 'Empresa', f: (r) => esc(r.empresa) + (r.ruc && r.ruc !== '—' ? '<br><span class="tiny muted">RUC ' + esc(r.ruc) + '</span>' : '') }, { l: 'Interés', f: (r) => esc(r.interes) + (r.agenda ? '<br><span class="tiny muted">' + ic('cal', 12) + ' ' + esc(r.agenda) + '</span>' : '') }, { l: 'Estado', f: (r) => '<select class="input" data-dme="' + r.id + '" aria-label="Estado de ' + esc(r.id) + '">' + DEMO_EST.map((e) => '<option' + (e === r.estado ? ' selected' : '') + '>' + e + '</option>').join('') + '</select>' }, { l: 'Responsable', f: (r) => '<select class="input" data-dmr="' + r.id + '" aria-label="Responsable de ' + esc(r.id) + '">' + WEB.responsables.map((e) => '<option' + (e === r.resp ? ' selected' : '') + '>' + e + '</option>').join('') + '</select>' }], L, { vacioT: 'No hay solicitudes con este estado', vacioS: 'Cambia el filtro.' }) +
    '<div class="row" style="margin-top:12px">' + verSitio('web-contacto', 'Ver el formulario público') + '</div>');
  $('#dm-f', root).onchange = (e) => { WUI.demoF = e.target.value; render(); };
  $$('[data-dme]', root).forEach((s) => s.addEventListener('change', () => { WEB.demos.find((d) => d.id === s.dataset.dme).estado = s.value; toast(s.dataset.dme + ': ' + s.value); }));
  $$('[data-dmr]', root).forEach((s) => s.addEventListener('change', () => { WEB.demos.find((d) => d.id === s.dataset.dmr).resp = s.value; toast(s.dataset.dmr + ' asignada a ' + s.value); }));
  bindExport(root);
}

function wpRedirecciones(root) {
  root.insertAdjacentHTML('beforeend', '<div class="card" style="margin-bottom:14px"><h3>Nueva redirección 301</h3><div class="wp-redir">' + wpCampo('rd-de', 'Desde (ruta antigua)', '', { ph: '/ruta-antigua' }) + wpCampo('rd-a', 'Hacia (ruta nueva o URL)', '', { ph: '/ruta-nueva' }) + '<button class="btn pri" id="rd-add">Agregar</button></div></div>' +
    tabla([{ l: 'Desde', f: (r) => '<code>' + esc(r.de) + '</code>' }, { l: 'Hacia', f: (r) => '<code>' + esc(r.a) + '</code>' }, { l: 'Tipo', f: (r) => chip(r.tipo, 'info') }, { l: '', f: (r, i) => '<button class="btn sm danger" data-rddel="' + esc(r.de) + '" aria-label="Quitar redirección">' + ic('trash', 14) + '</button>' }], WEB.redirecciones) + nota('También se generan automáticamente <b>sitemap.xml</b>, <b>robots.txt</b>, URLs canónicas y datos estructurados (Organization, Product/Offer, FAQPage, BreadcrumbList).'));
  $('#rd-add', root).onclick = () => { const de = $('#rd-de', root).value.trim(); const a = $('#rd-a', root).value.trim(); if (!/^\/[a-z0-9\-/]*$/i.test(de)) { toast('La ruta de origen debe empezar con /', 'warn'); return; } if (!a) { toast('Escribe el destino', 'warn'); return; } if (WEB.redirecciones.some((r) => r.de === de)) { toast('Ya existe una redirección desde ' + de, 'warn'); return; } WEB.redirecciones.push({ de, a, tipo: '301', hits: 0 }); toast('Redirección agregada'); render(); };
  $$('[data-rddel]', root).forEach((b) => b.addEventListener('click', () => { WEB.redirecciones = WEB.redirecciones.filter((r) => r.de !== b.dataset.rddel); toast('Redirección quitada'); render(); }));
}

const LOGO_EJEMPLO = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 168 40"><rect x="1" y="3" width="34" height="34" rx="9" fill="#0D3B6E"/><path d="M8 26c0-6 4.5-10 10-10s10 4 10 10" fill="#F4B90F"/><rect x="6" y="25" width="24" height="4" rx="2" fill="#FFFFFF"/><text x="44" y="27" font-family="Lexend,Segoe UI,sans-serif" font-size="19" font-weight="600" fill="#1F7FD1">Escudo<tspan fill="#0D3B6E">SST</tspan></text></svg>');
function wpLegal(root) {
  const a = WEB.ajustes; const an = a.anuncio;
  root.insertAdjacentHTML('beforeend', '<div class="grid g-main"><div class="stack"><div class="card"><h3>Datos legales y de la empresa</h3><p class="small muted" style="margin-top:4px">Se usan en el pie de página, las políticas y el Libro de Reclamaciones.</p><div class="grid g2" style="margin-top:12px">' +
    wpCampo('lg-razon', 'Razón social', a.razon) + wpCampo('lg-ruc', 'RUC', a.ruc, { im: 'numeric' }) + '<div style="grid-column:1/-1">' + wpCampo('lg-dom', 'Domicilio legal', a.domicilio, { ph: 'Por completar' }) + '</div>' +
    wpCampo('lg-dominio', 'Dominio', a.dominio, { ph: 'escudosst.com' }) + wpCampo('lg-marca', 'Nombre comercial', a.marca) + wpCampo('lg-cor', 'Correo de contacto', a.correo, { tipo: 'email', ph: 'Por definir' }) + wpCampo('lg-tel', 'Teléfono de contacto', a.telefono, { tipo: 'tel', ph: 'Por definir' }) +
    '<div style="grid-column:1/-1">' + wpCampo('lg-hor', 'Horario de atención', a.horario) + '</div>' + wpCampo('lg-rl', 'Responsable del Libro de Reclamaciones', a.respLibro) + wpCampo('lg-rlc', 'Correo del responsable', a.correoLibro, { tipo: 'email', ph: 'Por definir' }) +
    wpCampo('lg-pl', 'Plazo de respuesta (días hábiles)', a.plazoLibro, { tipo: 'number', hint: 'Parámetro con vigencia · a confirmar por especialista legal' }) + wpCampo('lg-pr', 'Prórroga (días hábiles)', a.prorrogaLibro, { tipo: 'number', hint: 'Parámetro con vigencia · a confirmar' }) +
    '</div><div class="row" style="margin-top:12px"><button class="btn pri" id="lg-save">Guardar cambios</button>' + verSitio('web-reclamaciones', 'Ver el Libro de Reclamaciones') + '</div></div>' +
    '<div class="card"><h3>Redes sociales</h3><p class="small muted" style="margin-top:4px">Se editan en su propia sección y aparecen con ícono en el pie y en contacto.</p><a class="btn" style="margin-top:10px" href="#m-web/redes">Editar redes sociales</a></div></div>' +
    '<div class="stack"><div class="card"><h3>Logo del sitio</h3><div class="wp-logo" id="lg-prev">' + wLogo(40) + '</div><p class="tiny muted">' + esc(a.logoNombre) + '. Formatos SVG o PNG, hasta 1 MB.</p><div class="row" style="margin-top:10px"><label class="btn pri" for="lg-file">' + ic('upload', 16) + 'Subir logo</label><input type="file" id="lg-file" accept="image/*" class="sr"><button class="btn" id="lg-ej">Usar logo de ejemplo</button><button class="btn" id="lg-med">Elegir de Medios</button>' + (a.logo ? '<button class="btn ghost" id="lg-rst">Volver al provisional</button>' : '') + '</div></div>' +
    '<div class="card"><h3>Barra de anuncio</h3><div class="stack" style="gap:10px;margin-top:10px">' + sw('an-on', an.on, 'Mostrar la barra de anuncio', '') + wpCampo('an-t', 'Texto', an.texto) + wpCampo('an-e', 'Texto del enlace', an.enlace) + '<label class="field" for="an-r"><span>Lleva a</span><select id="an-r">' + [['web/m-aula', 'Portada · Aula virtual'], ['web-planes', 'Planes y precios'], ['web-contacto', 'Agenda una demo'], ['web-consultoras', 'Consultoras']].map(([r, l]) => '<option value="' + r + '"' + (an.ruta === r ? ' selected' : '') + '>' + l + '</option>').join('') + '</select></label></div></div></div></div>');
  const ids = { razon: 'lg-razon', ruc: 'lg-ruc', domicilio: 'lg-dom', dominio: 'lg-dominio', marca: 'lg-marca', correo: 'lg-cor', telefono: 'lg-tel', horario: 'lg-hor', respLibro: 'lg-rl', correoLibro: 'lg-rlc' };
  $('#lg-save', root).onclick = () => {
    const ruc = $('#lg-ruc', root).value.trim(); if (!rucValido(ruc)) { toast('El RUC no es válido', 'warn'); $('#lg-ruc', root).focus(); return; }
    const pl = +$('#lg-pl', root).value; const pr = +$('#lg-pr', root).value; if (!(pl > 0) || !(pr >= 0)) { toast('Revisa los plazos', 'warn'); return; }
    Object.entries(ids).forEach(([k, id]) => { a[k] = $('#' + id, root).value.trim(); });
    a.dominio = a.dominio.replace(/^https?:\/\//, '').replace(/\/$/, ''); a.plazoLibro = pl; a.prorrogaLibro = pr;
    toast('Datos de la empresa guardados: el pie y el Libro de Reclamaciones ya los muestran');
  };
  $('#lg-file', root).addEventListener('change', (e) => leerImagen(e.target.files[0], (url) => { a.logo = url; a.logoNombre = e.target.files[0].name; toast('Logo cargado: el sitio ya lo muestra'); render(); }));
  $('#lg-ej', root).onclick = () => { a.logo = LOGO_EJEMPLO; a.logoNombre = 'Logo de ejemplo'; toast('Logo de ejemplo aplicado al sitio'); render(); };
  const lm = $('#lg-med', root); if (lm && typeof v15ElegirMedio === 'function') lm.onclick = () => v15ElegirMedio('Logo del sitio', (m) => { a.logo = m.src; a.logoNombre = m.title; a.logoMedia = m.id; toast('Logo tomado de la biblioteca: el sitio ya lo muestra'); render(); });
  const rst = $('#lg-rst', root); if (rst) rst.onclick = () => { a.logo = null; a.logoNombre = 'Logo provisional'; toast('Logo provisional restablecido'); render(); };
  $('#an-on', root).addEventListener('change', (e) => { an.on = e.target.checked; WUI.anuncioOff = false; });
  $('#an-t', root).addEventListener('input', (e) => { an.texto = e.target.value; }); $('#an-e', root).addEventListener('input', (e) => { an.enlace = e.target.value; });
  $('#an-r', root).addEventListener('change', (e) => { an.ruta = e.target.value; });
}

R.WEB = (root, m, sub) => {
  restaurarSeo();
  const sec = !sub ? 'paginas' : sub.startsWith('ed-') ? 'paginas' : sub;
  const info = WP_SECC.find((x) => x[0] === sec) || WP_SECC[0];
  const edit = sub && sub.startsWith('ed-') ? sub.slice(3) : null;
  root.innerHTML = encabezado(edit ? 'Editar «' + (WEB.paginas[edit] || { n: edit }).n + '»' : 'Sitio web · ' + info[1], edit ? 'Bloques, publicación y SEO de la página.' : 'Lo que cambies aquí se refleja en el sitio público durante la demostración. Nada se guarda.', verSitio('web'), [[CAT.grupos[1]], ['Sitio web', 'm-web']].concat(edit ? [['Páginas', 'm-web/paginas'], [WEB.paginas[edit] ? WEB.paginas[edit].n : edit]] : [[info[1]]])) + wpNav(sec);
  if (edit) { wpEditor(root, edit); return; }
  const F = { paginas: () => { root.insertAdjacentHTML('beforeend', wpPaginas(root)); bindPaginas(root); }, menu: wpMenu, planes: wpPlanes, videos: wpVideos, productos: wpProductos, testimonios: wpTestimonios, faq: wpFaq, blog: wpBlog, whatsapp: wpWhatsapp, politicas: wpPoliticas, reclamos: wpReclamos, demos: wpDemos, redirecciones: wpRedirecciones, legal: wpLegal };
  (F[sec] || (typeof WXP !== 'undefined' && WXP[sec]) || F.paginas)(root);
};
