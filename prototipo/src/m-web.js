/* Módulo WEB · Sitio web público de EscudoSST (rutas #web…). Lee el estado editable de WEB (m-web-datos.js). */
const WUI = { periodo: 'm', grupoAbierto: { 1: true }, menuMovil: false, lrTab: 0, anuncioOff: false, ag: { dia: null, hora: null } };

/* ---------- SEO de la vista pública ---------- */
let SEO_ORIGINAL = null;
function metaTag(attr, key, val) {
  let m = document.head.querySelector('meta[' + attr + '="' + key + '"]');
  if (val == null) { if (m) m.remove(); return; }
  if (!m) { m = document.createElement('meta'); m.setAttribute(attr, key); document.head.appendChild(m); }
  m.setAttribute('content', val);
}
function webSeo(clave, extra) {
  if (!SEO_ORIGINAL) SEO_ORIGINAL = { t: document.title, d: (document.head.querySelector('meta[name="description"]') || {}).content || '' };
  const p = WEB.paginas[clave] || { seo: { t: 'EscudoSST', d: '' } };
  const t = (extra && extra.t) || p.seo.t; const d = (extra && extra.d) || p.seo.d;
  document.title = t;
  metaTag('name', 'description', d);
  metaTag('property', 'og:title', t);
  metaTag('property', 'og:description', d);
  metaTag('property', 'og:type', clave.startsWith('web-blog/') ? 'article' : 'website');
  metaTag('name', 'robots', p.seo.noindex ? 'noindex, follow' : null);
}
function restaurarSeo() {
  if (!SEO_ORIGINAL) return;
  document.title = SEO_ORIGINAL.t;
  metaTag('name', 'description', SEO_ORIGINAL.d);
  ['og:title', 'og:description', 'og:type'].forEach((k) => metaTag('property', k, null));
  metaTag('name', 'robots', null);
}

/* ---------- Marca e ilustraciones (SVG propios) ---------- */
const WMARK = (s = 34) => '<svg class="w-mark" width="' + s + '" height="' + s + '" viewBox="0 0 40 40" aria-hidden="true"><path class="wm-sh" d="M20 2.5 34.5 7.6v10.6c0 9.3-6.1 16.4-14.5 19.3C11.6 34.6 5.5 27.5 5.5 18.2V7.6Z"/><path d="M11.2 23.6c0-5.7 3.9-9.9 8.8-9.9s8.8 4.2 8.8 9.9" fill="#FFFFFF"/><path d="M20 13.7v-2.3" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round"/><rect x="8.6" y="23.2" width="22.8" height="3.4" rx="1.7" fill="#F4B90F"/></svg>';
const LIBRO_IC = (s = 28) => '<svg width="' + s + '" height="' + s + '" viewBox="0 0 32 32" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" stroke-linecap="round"><path d="M16 8.5c-3-2.2-7-2.6-11-1.6v17c4-1 8-.6 11 1.6 3-2.2 7-2.6 11-1.6v-17c-4-1-8-.6-11 1.6z"/><path d="M16 8.5v17"/><path d="M8.5 12.5c1.6-.2 3.2 0 4.6.6M8.5 16.5c1.6-.2 3.2 0 4.6.6M19 13.1c1.4-.6 3-.8 4.6-.6"/></svg>';

function miniQR(x, y, cel, n) {
  // patrón determinista con tres marcas de posición (decorativo, no es un QR real)
  let h = '<g transform="translate(' + x + ' ' + y + ')"><rect width="' + (cel * n) + '" height="' + (cel * n) + '" fill="#FFFFFF"/>';
  const marca = (a, b) => '<rect x="' + a * cel + '" y="' + b * cel + '" width="' + 3 * cel + '" height="' + 3 * cel + '" fill="none" stroke="#10233F" stroke-width="' + cel * 0.8 + '"/><rect x="' + (a + 1) * cel + '" y="' + (b + 1) * cel + '" width="' + cel + '" height="' + cel + '" fill="#10233F"/>';
  for (let i = 0; i < n; i++) for (let j = 0; j < n; j++) {
    const enMarca = (i < 4 && j < 4) || (i >= n - 4 && j < 4) || (i < 4 && j >= n - 4);
    if (!enMarca && ((i * 7 + j * 11 + i * j) % 5 < 2)) h += '<rect x="' + i * cel + '" y="' + j * cel + '" width="' + cel + '" height="' + cel + '" fill="#10233F"/>';
  }
  return h + marca(0.4, 0.4) + marca(n - 3.4, 0.4) + marca(0.4, n - 3.4) + '</g>';
}

function ilHero() {
  const barras = [46, 62, 38, 70, 54, 80];
  return '<svg class="w-il w-il-hero" viewBox="0 0 640 520" role="img" aria-label="Ilustración: planta industrial con el tablero de EscudoSST, un fotocheck con código QR, un casco de seguridad y el avance del expediente SUNAFIL">' +
    '<defs><pattern id="w-cinta" width="22" height="22" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="22" height="22" fill="#F4B90F"/><rect width="11" height="22" fill="#0D3B6E"/></pattern></defs>' +
    '<rect x="28" y="36" width="584" height="432" rx="40" class="il-bg"/>' +
    '<circle cx="548" cy="96" r="46" class="il-bg2"/>' +
    // planta: nave con techo en diente de sierra, chimenea y almacén
    '<g class="il-bld"><path d="M52 430V330l32-22v22l32-22v22l32-22v22l32-22v122z"/><rect x="104" y="226" width="20" height="104" rx="3"/><rect x="250" y="362" width="120" height="68"/></g>' +
    '<g class="il-bld2"><rect x="64" y="352" width="20" height="20" rx="2"/><rect x="96" y="352" width="20" height="20" rx="2"/><rect x="128" y="352" width="20" height="20" rx="2"/><rect x="160" y="352" width="20" height="20" rx="2"/><rect x="276" y="388" width="68" height="42"/></g>' +
    '<path d="M108 216c-12-10-6-26 8-26 6-14 26-14 30-2 14-2 20 14 10 22" class="il-humo"/>' +
    // suelo con cinta de peligro
    '<rect x="44" y="430" width="552" height="12" fill="url(#w-cinta)"/>' +
    // tablero
    '<g class="il-tab" transform="translate(0 -34)"><rect x="196" y="104" width="364" height="252" rx="20" class="il-shadow" transform="translate(6 10)"/><rect x="196" y="104" width="364" height="252" rx="20" class="il-card"/>' +
    '<path d="M196 124a20 20 0 0 1 20-20h324a20 20 0 0 1 20 20v18H196z" fill="#0D3B6E"/>' +
    '<path d="M218 113l7 2.5v5.3c0 4.6-3 8-7 9.4-4-1.4-7-4.8-7-9.4v-5.3z" fill="#FFFFFF"/><rect x="232" y="118" width="64" height="7" rx="3.5" fill="#9DB3CF"/><circle cx="532" cy="122" r="7" fill="#F4B90F"/>' +
    '<g class="il-tile"><rect x="212" y="156" width="104" height="66" rx="12"/><rect x="326" y="156" width="104" height="66" rx="12"/><rect x="440" y="156" width="104" height="66" rx="12"/></g>' +
    '<text x="226" y="196" class="il-t il-big" fill="#22A35A">78%</text><text x="340" y="196" class="il-t il-big il-amb">9</text><text x="454" y="196" class="il-t il-big" fill="#E2453C">2</text>' +
    '<text x="226" y="212" class="il-t il-sm">Cumplimiento</text><text x="340" y="212" class="il-t il-sm">Por vencer</text><text x="454" y="212" class="il-t il-sm">Vencidos</text>' +
    '<g>' + barras.map((h, i) => '<rect class="il-barra" style="animation-delay:' + (0.15 + i * 0.07) + 's" x="' + (222 + i * 30) + '" y="' + (336 - h) + '" width="18" height="' + h + '" rx="4" fill="' + (i === 5 ? '#1FB5A3' : '#1F7FD1') + '"/>').join('') + '<path d="M214 337h188" class="il-eje"/></g>' +
    [['#22A35A', 0], ['#F4B90F', 1], ['#E2453C', 2], ['#22A35A', 3]].map(([c, i]) => '<circle cx="428" cy="' + (252 + i * 24) + '" r="6" fill="' + c + '"/><rect x="442" y="' + (248 + i * 24) + '" width="' + [92, 70, 84, 60][i] + '" height="8" rx="4" class="il-ln"/>').join('') +
    '</g>' +
    // fotocheck
    '<g transform="translate(446 236) rotate(6)"><rect width="148" height="200" rx="16" class="il-shadow" transform="translate(5 8)"/><rect width="148" height="200" rx="16" class="il-card"/><path d="M0 16A16 16 0 0 1 16 0h116a16 16 0 0 1 16 16v22H0z" fill="#1F7FD1"/><rect x="56" y="-10" width="36" height="16" rx="5" class="il-clip"/>' +
    '<circle cx="40" cy="68" r="20" class="il-av"/><path d="M33 64a7 7 0 1 1 14 0 7 7 0 0 1-14 0zM27 84c2-7 7-10 13-10s11 3 13 10" class="il-av2"/>' +
    '<rect x="70" y="56" width="62" height="8" rx="4" class="il-ln2"/><rect x="70" y="70" width="44" height="7" rx="3.5" class="il-ln"/>' +
    '<rect x="16" y="100" width="62" height="20" rx="10" fill="#E0F4E8"/><circle cx="28" cy="110" r="4" fill="#22A35A"/><text x="37" y="114.5" class="il-t il-chip" fill="#17733F">Vigente</text>' +
    miniQR(78, 128, 3.4, 17) + '</g>' +
    // casco
    '<g transform="translate(292 366)"><path d="M10 54c0-30 20-48 46-48s46 18 46 48z" fill="#F4B90F"/><path d="M48 7h16v47H48z" fill="#E0A50B"/><path d="M0 52h112a6 6 0 0 1 0 12H0a6 6 0 0 1 0-12z" fill="#E0A50B"/><path d="M26 26c6-8 14-12 22-13" stroke="#FFF4D1" stroke-width="5" stroke-linecap="round" fill="none"/></g>' +
    // anillo SUNAFIL
    '<g transform="translate(116 106)"><circle r="52" class="il-card il-ringbg"/><circle r="40" fill="none" class="il-ring0" stroke-width="9"/><circle r="40" fill="none" stroke="#1FB5A3" stroke-width="9" stroke-linecap="round" stroke-dasharray="216 252" transform="rotate(-90)" class="il-ring"/>' +
    '<path d="M0-17 13-12.5v9.5c0 8.5-5.5 14.7-13 17.3C-7.5 11.7-13 5.5-13-3v-9.5z" fill="#0D3B6E"/><path d="M-6 -1l4.5 4.5L7-5" stroke="#FFFFFF" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/></g>' +
    '<g transform="translate(63 166)"><rect width="106" height="28" rx="14" class="il-card il-pill"/><text x="53" y="18.5" text-anchor="middle" class="il-t il-pillt">SUNAFIL 86%</text></g>' +
    '</svg>';
}

function ilSector(k, cls) {
  const base = '<rect x="6" y="8" width="228" height="132" rx="22" class="il-bg"/><rect x="18" y="122" width="204" height="7" fill="url(#w-cinta-s)"/>';
  const defs = '<defs><pattern id="w-cinta-s" width="12" height="12" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="12" height="12" fill="#F4B90F"/><rect width="6" height="12" fill="#0D3B6E"/></pattern></defs>';
  const esc_ = {
    construccion: '<g class="il-bld"><rect x="40" y="62" width="70" height="60"/></g><g class="il-grua" fill="none" stroke-linecap="round"><path d="M40 62h70M40 82h70M40 102h70M58 62v60M76 62v60M94 62v60"/><path d="M164 122V30M176 122V30M164 40l12 12M176 52l-12 12M164 64l12 12M176 76l-12 12M164 88l12 12M156 30h60M156 30H92M136 30v30"/></g><rect x="126" y="60" width="20" height="13" rx="2" fill="#F08A24"/><path d="M188 120c0-12 8-19 17-19s17 7 17 19z" fill="#F4B90F"/><rect x="183" y="118" width="44" height="5" rx="2.5" fill="#E0A50B"/>',
    mineria: '<path d="M18 122 78 44l34 40 24-26 86 64z" class="il-bld"/><path d="M92 122v-22a18 18 0 0 1 36 0v22z" fill="#0D3B6E"/><path d="M150 112h50l-6 10h-38z" fill="#F08A24"/><circle cx="160" cy="124" r="5" fill="#0D3B6E"/><circle cx="190" cy="124" r="5" fill="#0D3B6E"/><path d="M148 100c4-12 22-14 30-6 8-6 22 0 22 12h-52z" class="il-bld2"/><circle cx="196" cy="34" r="14" class="il-bg2"/>',
    manufactura: '<g class="il-bld"><path d="M28 122V76l26-18v18l26-18v18l26-18v64z"/><rect x="92" y="34" width="12" height="40" rx="2"/></g><g class="il-bld2"><rect x="38" y="90" width="14" height="14" rx="2"/><rect x="62" y="90" width="14" height="14" rx="2"/><rect x="86" y="90" width="14" height="14" rx="2"/></g><g transform="translate(166 78)"><circle r="30" fill="#1F7FD1"/><circle r="11" class="il-card"/><g fill="#1F7FD1">' + [0, 45, 90, 135, 180, 225, 270, 315].map((a) => '<rect x="-6" y="-38" width="12" height="12" rx="2" transform="rotate(' + a + ')"/>').join('') + '</g></g>',
    agroindustria: '<circle cx="190" cy="40" r="18" fill="#F4B90F"/><g fill="none" stroke-linecap="round" stroke-width="5" class="il-surco"><path d="M18 120c40-26 90-34 150-30M30 106c40-20 80-28 130-26M48 92c34-14 66-20 104-20"/></g><g fill="#22A35A"><path d="M60 98c-2-12 6-18 14-18-1 10-6 16-14 18zM104 86c-2-12 6-18 14-18-1 10-6 16-14 18zM140 80c-2-12 6-18 14-18-1 10-6 16-14 18z"/></g><g transform="translate(166 92)"><rect width="48" height="30" rx="3" fill="#F08A24"/><path d="M0 10h48M0 20h48" stroke="#C56A12" stroke-width="2"/></g>',
    oficinas: '<g class="il-bld"><rect x="34" y="30" width="84" height="92" rx="4"/></g><g class="il-bld2">' + [0, 1, 2, 3].map((r) => [0, 1, 2].map((c) => '<rect x="' + (44 + c * 24) + '" y="' + (40 + r * 20) + '" width="16" height="12" rx="2"/>').join('')).join('') + '</g><g transform="translate(138 58)"><rect width="78" height="52" rx="6" fill="#0D3B6E"/><rect x="6" y="6" width="66" height="36" rx="3" class="il-card"/><path d="M14 34l12-10 10 6 14-14 12 8" stroke="#1FB5A3" stroke-width="3.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="M30 52h18v8H30zM22 62h34" stroke="#0D3B6E" stroke-width="4" stroke-linecap="round"/></g>',
  };
  return '<svg class="w-il ' + (cls || '') + '" viewBox="0 0 240 148" aria-hidden="true">' + defs + base + (esc_[k] || '') + '</svg>';
}

function ilConsultora() {
  const emp = [['#1F7FD1', 'Profesional', 34, 40], ['#0D3B6E', 'Corporativo', 330, 40], ['#22A35A', 'Esencial', 34, 236], ['#1FB5A3', 'Profesional', 330, 236]];
  return '<svg class="w-il" viewBox="0 0 480 340" role="img" aria-label="Ilustración: el panel de la consultora conectado con cuatro empresas cliente, cada una con su propio plan">' +
    '<rect x="8" y="8" width="464" height="324" rx="34" class="il-bg"/>' +
    '<g class="il-cable" fill="none" stroke-width="3" stroke-dasharray="6 7"><path d="M150 84c40 0 50 50 90 50M330 84c-40 0-50 50-90 50M150 280c40 0 50-50 90-50M330 280c-40 0-50-50-90-50"/></g>' +
    emp.map(([c, p, x, y]) => '<g transform="translate(' + x + ' ' + y + ')"><rect width="116" height="66" rx="14" class="il-shadow" transform="translate(3 6)"/><rect width="116" height="66" rx="14" class="il-card"/><rect x="12" y="12" width="22" height="22" rx="6" fill="' + c + '"/><rect x="42" y="14" width="60" height="7" rx="3.5" class="il-ln2"/><rect x="42" y="26" width="40" height="6" rx="3" class="il-ln"/><rect x="12" y="44" width="' + (p.length * 6.2 + 14) + '" height="14" rx="7" class="il-chipbg"/><text x="19" y="54.5" class="il-t il-chip2">' + p + '</text></g>').join('') +
    '<g transform="translate(170 118)"><rect width="140" height="104" rx="18" class="il-shadow" transform="translate(4 8)"/><rect width="140" height="104" rx="18" fill="#0E7A70"/><rect x="16" y="16" width="30" height="30" rx="9" fill="#FFFFFF"/><path d="M22 38c0-6 4-10 9-10s9 4 9 10" fill="none" stroke="#0E7A70" stroke-width="3" stroke-linecap="round"/><circle cx="31" cy="23" r="3" fill="#F4B90F"/><rect x="54" y="20" width="66" height="8" rx="4" fill="#CFEDE9"/><rect x="54" y="34" width="44" height="6" rx="3" fill="#7CCFC5"/>' +
    [0, 1, 2, 3].map((i) => '<rect x="16" y="' + (58 + i * 10) + '" width="' + [96, 80, 104, 70][i] + '" height="5" rx="2.5" fill="#5DBFB3"/>').join('') + '</g>' +
    '</svg>';
}

function ilAula() {
  return '<svg class="w-il" viewBox="0 0 480 340" role="img" aria-label="Ilustración: curso virtual con avance y un certificado verificable con código QR">' +
    '<rect x="8" y="8" width="464" height="324" rx="34" class="il-bg"/>' +
    '<g transform="translate(36 46)"><rect width="270" height="200" rx="18" class="il-shadow" transform="translate(4 8)"/><rect width="270" height="200" rx="18" class="il-card"/><rect x="14" y="14" width="242" height="110" rx="12" fill="#0D3B6E"/><circle cx="135" cy="69" r="24" fill="#FFFFFF" opacity=".95"/><path d="M128 57v24l20-12z" fill="#0D3B6E"/><rect x="14" y="138" width="170" height="9" rx="4.5" class="il-ln2"/><rect x="14" y="156" width="242" height="10" rx="5" class="il-ln"/><rect x="14" y="156" width="168" height="10" rx="5" fill="#22A35A"/><text x="14" y="188" class="il-t il-sm">Avance 70%</text></g>' +
    '<g transform="translate(250 150) rotate(4)"><rect width="200" height="146" rx="14" class="il-shadow" transform="translate(4 8)"/><rect width="200" height="146" rx="14" class="il-card"/><rect x="10" y="10" width="180" height="126" rx="8" fill="none" stroke="#F4B90F" stroke-width="2.5"/><rect x="28" y="28" width="92" height="9" rx="4.5" class="il-ln2"/><rect x="28" y="44" width="70" height="7" rx="3.5" class="il-ln"/><rect x="28" y="58" width="80" height="7" rx="3.5" class="il-ln"/><circle cx="58" cy="102" r="18" fill="#F4B90F"/><path d="M50 116l-4 16 12-6 12 6-4-16" fill="#E0A50B"/><path d="M51 102l5 5 9-10" stroke="#FFFFFF" stroke-width="3" fill="none" stroke-linecap="round"/>' + miniQR(124, 70, 3.2, 17) + '</g>' +
    '</svg>';
}

/* ---------- Estructura común ---------- */
const urlSitio = (ruta) => (WEB.ajustes.dominio || 'dominio-por-definir') + (ruta || '');
const blogHref = () => 'https://' + (WEB.ajustes.blogUrl || 'blog.dominio-por-definir');
function wLogo(s = 34) {
  const a = WEB.ajustes;
  if (a.logo) return '<img class="w-logoimg" src="' + a.logo + '" alt="' + esc(a.marca) + '" height="' + s + '">';
  return WMARK(s) + '<span>' + esc(a.marca) + '</span>';
}
function wAnuncio() {
  const an = WEB.ajustes.anuncio;
  if (!an.on || WUI.anuncioOff) return '';
  return '<div class="w-ann" role="region" aria-label="Anuncio"><div class="w-in"><p><span class="w-anndot" aria-hidden="true"></span>' + esc(an.texto) + (an.enlace ? ' <a href="#' + esc(an.ruta) + '">' + esc(an.enlace) + '</a>' : '') + '</p><button type="button" class="w-annx" id="w-annx" aria-label="Cerrar el anuncio">' + ic('x', 16) + '</button></div></div>';
}
const SECTOR_LINKS = () => Object.entries(WEB_SECTORES).map(([k, s]) => '<a href="#web-sector-' + k + '">' + ic(s.ic, 18) + '<span>' + esc(s.n) + '</span></a>').join('');
const PRODUCTO_LINKS = () => BLOQUES.map((b) => '<a href="#web/m-' + b.id + '">' + ic(b.ic, 18) + '<span><b>' + esc(b.corto) + '</b><small>' + esc(b.sub) + '</small></span></a>').join('') + '<a class="w-ddall" href="#web-producto">' + ic('grid', 18) + '<span><b>Todos los módulos</b><small>' + MODS.filter((m) => m.plan !== 'I').length + ' módulos en 8 grupos</small></span></a>';
function wHeader(ruta, sub) {
  const items = WEB.menu.filter((x) => x.on);
  const activo = (r) => (r === 'web/como-funciona' ? ruta === 'web' && sub === 'como-funciona' : ruta === r) || (r === 'web-sectores' && ruta.startsWith('web-sector')) || (r === 'web-producto' && ruta === 'web' && /^m-/.test(sub || '')) || (r === 'web-recursos' && typeof WX_RUTAS !== 'undefined' && WX_RUTAS.includes(ruta));
  const href = (x) => x.r === 'blog' ? blogHref() + '" target="_blank" rel="noopener' : '#' + x.r;
  const navItem = (x) => {
    const cur = activo(x.r) ? ' aria-current="page"' : '';
    if (x.id === 'productos' || x.id === 'sectores' || x.id === 'recursos') return '<div class="w-ddw' + (x.id === 'productos' ? ' w-ddw-p' : x.id === 'recursos' ? ' w-ddw-p w-ddw-r' : '') + '"><a class="w-nl' + (cur ? ' on' : '') + '" href="#' + x.r + '"' + cur + '>' + esc(x.l) + ic('chev', 14) + '</a><div class="w-dd">' + (x.id === 'productos' ? PRODUCTO_LINKS() : x.id === 'recursos' ? RECURSOS_LINKS() : SECTOR_LINKS()) + '</div></div>';
    return '<a class="w-nl' + (cur ? ' on' : '') + '" href="' + href(x) + '"' + cur + '>' + esc(x.l) + (x.r === 'blog' ? '<span class="sr"> (se abre en otra pestaña)</span>' : '') + '</a>';
  };
  return '<a class="w-skip" href="#w-main" id="w-skip">Saltar al contenido</a>' + wAnuncio() + '<header class="w-head"><div class="w-in w-hrow">' +
    '<a class="w-logo" href="#web" aria-label="' + esc(WEB.ajustes.marca) + ', ir a la portada">' + wLogo(34) + '</a>' +
    '<nav class="w-nav" aria-label="Principal">' + items.map(navItem).join('') + '</nav>' +
    '<div class="w-hact"><button class="w-login" id="w-login" type="button">Iniciar sesión</button><a class="w-btn w-hdemo" href="#web-contacto">Agenda una demo</a><a class="w-btn pri w-hcta" href="#registro">Prueba gratis</a>' +
    '<button class="w-burger" id="w-burger" type="button" aria-expanded="' + WUI.menuMovil + '" aria-controls="w-drawer" aria-label="' + (WUI.menuMovil ? 'Cerrar el menú' : 'Abrir el menú') + '">' + ic(WUI.menuMovil ? 'x' : 'menu', 22) + '</button></div></div>' +
    '<div class="w-drawer" id="w-drawer"' + (WUI.menuMovil ? '' : ' hidden') + '><nav aria-label="Menú móvil">' + items.map((x) => '<a class="w-ml' + (activo(x.r) ? ' on' : '') + '" href="' + href(x) + '">' + esc(x.l) + (x.r === 'blog' ? ic('ext', 16) : '') + '</a>' + (x.id === 'sectores' ? '<div class="w-msub">' + SECTOR_LINKS() + '</div>' : x.id === 'productos' ? '<div class="w-msub">' + PRODUCTO_LINKS() + '</div>' : x.id === 'recursos' ? '<div class="w-msub">' + RECURSOS_LINKS() + '</div>' : '')).join('') + '</nav>' +
    '<div class="w-mact"><a class="w-btn pri lg" href="#registro">Prueba gratis</a><a class="w-btn lg" href="#web-contacto">Agenda una demo</a><button class="w-btn ghost lg" type="button" id="w-login2">Iniciar sesión</button></div></div></header>';
}

function wFooter() {
  const a = WEB.ajustes;
  const col = (t, links) => '<div class="w-fcol"><h2 class="w-fh">' + esc(t) + '</h2><ul>' + links.map(([l, r, ext]) => '<li><a href="' + (ext ? r + '" target="_blank" rel="noopener' : '#' + r) + '">' + esc(l) + '</a></li>').join('') + '</ul></div>';
  const redes = Object.entries(a.redes).filter(([, u]) => u && u.trim());
  return '<footer class="w-foot"><div class="w-in"><div class="w-fgrid">' +
    '<div class="w-fbrand"><a class="w-logo" href="#web">' + wLogo(32) + '</a><p>Software de seguridad y salud en el trabajo para empresas peruanas.</p>' +
    '<a class="w-libro" href="#web-reclamaciones">' + LIBRO_IC(30) + '<span><b>Libro de Reclamaciones</b><small>Registra un reclamo o una queja</small></span></a></div>' +
    col('Producto', [['Todos los módulos', 'web-producto'], ['Cómo funciona', 'web/como-funciona'], ['Planes y precios', 'web-planes'], ['Consultoras', 'web-consultoras'], ['Prueba gratis', 'registro']]) +
    col('Sectores', Object.entries(WEB_SECTORES).map(([k, s]) => [s.n, 'web-sector-' + k])) +
    col('Recursos', [['Calculadora de multas', 'web-calculadora'], ['Autodiagnóstico Ley 29783', 'web-autodiagnostico'], ['Plantillas y formatos', 'web-recursos'], ['Casos de éxito', 'web-casos'], ['EscudoSST vs Excel y papel', 'web-comparativa'], ['Centro de ayuda', 'web-ayuda'], ['Boletín normativo', 'web-boletin'], ['Calculadora de ahorro', 'web-calculadora-ahorro'], ['Webinars', 'web-webinars']]) +
    col('Empresa', [['Agenda una demo', 'web-contacto'], ['Solicitar cotización', 'web-contacto/cotizacion'], ['Contacto', 'web-contacto/contacto'], ['Blog', blogHref(), true], ['Partners y referidos', 'web-partners'], ['Centro de confianza', 'web-confianza'], ['Estado del servicio', 'web-estado'], ['Novedades', 'web-novedades'], ['Integraciones', 'web-integraciones'], ['Otros productos', 'web/productos']]) +
    '<div class="w-fcol"><h2 class="w-fh">Legal</h2><ul>' + WEB.politicas.map((p) => '<li><a href="#web-legal-' + p.id + '">' + esc(p.t) + '</a></li>').join('') + '<li><button type="button" class="w-flink" id="w-cookiecfg">Preferencias de cookies</button></li><li><a href="#web-reclamaciones">Libro de Reclamaciones</a></li></ul></div>' +
    '</div><div class="w-fleg"><p>© 2026 ' + esc(a.razon) + ' · RUC ' + esc(a.ruc) + ' · Domicilio legal: ' + ajusteTxt(a.domicilio, 'por completar') + '</p>' +
    '<p>' + esc(urlSitio()) + ' · ' + ajusteTxt(a.correo, 'correo por definir') + ' · ' + ajusteTxt(a.telefono, 'teléfono por definir') + '</p>' +
    '<div class="w-redes">' + (typeof v15RedesHTML === 'function' ? v15RedesHTML() : redes.length ? 'Síguenos: ' + redes.map(([n, u]) => '<a href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(n) + '</a>').join(' ') : 'Redes sociales: por configurar') + '</div></div></div></footer>';
}

function wCookies() {
  if (WEB.cookies) return '';
  return '<div class="w-cookie" role="region" aria-label="Aviso de cookies"><div class="w-cookie-in"><div><h2>Usamos cookies</h2><p>Las necesarias hacen que el sitio funcione. Con tu permiso, usamos también cookies de analítica y de marketing. <a href="#web-legal-cookies">Política de cookies</a></p></div>' +
    '<div class="w-cookie-b"><button type="button" class="w-btn" data-ck="no">Rechazar</button><button type="button" class="w-btn" data-ck="cfg">Configurar</button><button type="button" class="w-btn pri" data-ck="si">Aceptar todo</button></div></div></div>';
}
function cookieCfg() {
  const c = WEB.cookies || { n: true, a: false, m: false };
  modal('Preferencias de cookies', '<p class="small muted" style="margin-bottom:10px">Elige qué cookies aceptas. Puedes cambiarlo cuando quieras desde el pie de página.</p><div class="stack" style="gap:10px">' +
    sw('ck-n', true, 'Necesarias', 'Sesión, seguridad y tus preferencias. Siempre activas.', true) + sw('ck-a', c.a, 'Analítica', 'Nos ayudan a entender cómo se usa el sitio, de forma agregada.') + sw('ck-m', c.m, 'Marketing', 'Permiten medir campañas y mostrarte anuncios relevantes.') + '</div>',
  [{ l: 'Cancelar' }, { l: 'Guardar preferencias', cls: 'pri', fn: (ov) => { WEB.cookies = { n: true, a: $('#ck-a', ov).checked, m: $('#ck-m', ov).checked }; toast('Preferencias de cookies guardadas'); const b = $('.w-cookie'); if (b) b.remove(); ubicarWa(); } }]);
}

/* WhatsApp flotante: número y mensaje por página editables en el panel */
function waMensaje(clave) {
  const m = WEB.whatsapp.msgs;
  if (clave.startsWith('web-sector-')) return (m.sector || '').replace('{sector}', (WEB_SECTORES[clave.slice(11)] || {}).n || '');
  return m[clave] || m.otras;
}
const wWa = () => '<button type="button" class="w-wa" id="w-wa" aria-label="Escríbenos por WhatsApp">' + ic('chat', 24) + '<span>WhatsApp</span></button>';
function ubicarWa() { const w = $('#w-wa'); if (!w) return; const c = $('.w-cookie'); w.style.bottom = c ? (c.offsetHeight + 14) + 'px' : ''; }
function abrirWa(clave) {
  const num = (WEB.whatsapp.numero || '').replace(/\D/g, '');
  const msg = waMensaje(clave);
  modal('Escríbenos por WhatsApp', (num ? '<p class="small muted">Se abrirá WhatsApp con el número <b>+51 ' + esc(num) + '</b> y este mensaje, que puedes cambiar antes de enviarlo.</p>' : nota('El número de WhatsApp está <b>por definir</b>. Se configura en el panel «Sitio web» › WhatsApp.', 'warn')) +
    '<label class="field" for="wa-msg" style="margin-top:10px"><span>Mensaje</span><textarea id="wa-msg">' + esc(msg) + '</textarea></label><p class="tiny muted" style="margin-top:8px">Enlace: wa.me/' + (num ? '51' + esc(num) : '…') + '?text=…</p>',
  [{ l: 'Cancelar' }, { l: 'Abrir WhatsApp', cls: 'pri', fn: () => toast(num ? 'Se abriría WhatsApp (simulado)' : 'Configura el número en el panel para activar WhatsApp', num ? '' : 'warn') }]);
}

function wCTA(t, s) {
  return '<section class="w-sec w-ctaf"><div class="w-in"><div class="w-ctabox"><div><h2>' + esc(t || 'Llega preparado a tu próxima inspección') + '</h2><p>' + esc(s || 'Regístrate con el RUC de tu empresa o agenda una demo con un caso de tu sector.') + '</p></div><div class="w-ctas"><a class="w-btn amb lg" href="#registro">Prueba gratis</a><a class="w-btn onnavy lg" href="#web-contacto">Agenda una demo</a></div></div></div></section>';
}
const wMigas = (items) => '<nav class="w-crumbs" aria-label="Ruta de navegación"><a href="#web">Inicio</a>' + items.map(([l, r]) => '<span aria-hidden="true">/</span>' + (r ? '<a href="#' + r + '">' + esc(l) + '</a>' : '<span aria-current="page">' + esc(l) + '</span>')).join('') + '</nav>';
const wFaq = (lista) => '<div class="w-faq">' + lista.map((f) => '<details><summary><span>' + esc(f.q) + '</span>' + ic('plus', 20) + '</summary><p>' + esc(f.a) + '</p></details>').join('') + '</div>';

const DIFS = [
  ['SUN', 'shield', 'Modo Inspección SUNAFIL', 'Autodiagnóstico, expediente ordenado y un enlace de solo lectura para el inspector.', 'blue'],
  ['FOT', 'qr', 'Fotocheck SST con QR', 'Credencial que muestra si el trabajador está habilitado: inducción, EMO, EPP y SCTR al día.', 'green'],
  ['ACT', 'chat', 'Reporte sin app', 'Cualquier persona reporta un acto o condición insegura con un QR o por WhatsApp.', 'amber'],
  ['CON', 'building', 'Modo consultora', 'Varias empresas cliente en un panel, con tu propia marca.', 'teal'],
  ['OFF', 'wifi', 'Funciona sin señal', 'Inspecciones y registros en campo sin conexión; se sincronizan al volver.', 'navy'],
  ['IAA', 'spark', 'Asistente IA', 'Sugiere peligros, controles y causas. Una persona revisa y decide.', 'orange'],
  ['PSE', 'layers', 'Paquetes sectoriales', 'Plantillas y formatos listos para construcción, minería, agroindustria y más.', 'red'],
];

/* ---------- Maquetas de pantalla (HTML dibujado, no capturas) ---------- */
const mkWin = (ruta, cuerpo, cls) => '<div class="mk-win ' + (cls || '') + '" aria-hidden="true"><div class="mk-bar"><i></i><i></i><i></i><span>' + esc(urlSitio('/app/' + ruta)) + '</span></div><div class="mk-body">' + cuerpo + '</div></div>';
const mkPhone = (cuerpo, cls) => '<div class="mk-phone ' + (cls || '') + '" aria-hidden="true"><div class="mk-notch"></div><div class="mk-pbody">' + cuerpo + '</div></div>';
const mkChip = (t, c) => '<span class="mk-chip ' + c + '">' + esc(t) + '</span>';
function mk(k) {
  if (k === 'sun') return mkWin('sunafil/expediente', '<div class="mk-h"><b>Expediente SUNAFIL</b>' + mkChip('86% listo', 'ok') + '</div><div class="mk-sun"><div class="mk-ring" style="--p:86"><span>86%</span></div><div class="mk-list">' +
    [['Política y objetivos SST', 100, 'ok'], ['Matriz IPERC vigente', 100, 'ok'], ['Registros obligatorios', 89, 'warn'], ['Capacitaciones del año', 72, 'warn'], ['Actas del Comité SST', 100, 'ok']].map(([t, p, c]) => '<div class="mk-req"><span>' + t + '</span><i><b class="' + c + '" style="width:' + p + '%"></b></i><em>' + p + '%</em></div>').join('') + '</div></div><div class="mk-foot"><span class="mk-btn">Compartir con el inspector</span><span class="mk-btn ghost">Descargar índice</span></div>');
  if (k === 'ipc') return mkWin('iperc', '<div class="mk-h"><b>Matriz IPERC · Prensado</b>' + mkChip('En revisión', 'warn') + '</div><table class="mk-tbl"><thead><tr><th>Peligro</th><th>Riesgo</th><th>Nivel</th></tr></thead><tbody>' +
    [['Partes móviles de prensa', 'Atrapamiento', 'Intolerable', 'bad'], ['Ruido de troqueladora', 'Hipoacusia', 'Importante', 'or'], ['Piso con aceite', 'Caída al mismo nivel', 'Moderado', 'warn'], ['Pantalla de control', 'Fatiga visual', 'Tolerable', 'ok']].map(([a, b, c, d]) => '<tr><td>' + a + '</td><td>' + b + '</td><td>' + mkChip(c, d) + '</td></tr>').join('') + '</tbody></table><div class="mk-foot"><span class="mk-btn">Enviar a aprobación</span><span class="mk-sugg">' + ic('spark', 14) + '3 controles sugeridos</span></div>');
  if (k === 'fot') return '<div class="mk-duo">' + mkPhone('<div class="mk-cred"><div class="mk-credh">Fotocheck SST</div><div class="mk-av">CM</div><b>Carmen R. Mamani</b><small>Operadora de prensa</small>' + mkChip('Habilitada', 'ok') + '<div class="mk-qr">' + (window.__QR ? window.__QR.fotocheck : '') + '</div></div>') +
    '<div class="mk-card mk-reqs"><b>Requisitos</b>' + [['Inducción', 'ok', 'Vigente'], ['EMO · aptitud', 'ok', 'Apto'], ['EPP entregado', 'ok', 'Al día'], ['SCTR', 'warn', 'Por vencer']].map(([t, c, e]) => '<div class="mk-rq"><span class="mk-dot ' + c + '"></span><span>' + t + '</span><em>' + e + '</em></div>').join('') + '<small>Sin datos médicos: solo la aptitud.</small></div></div>';
  if (k === 'act') return '<div class="mk-duo">' + mkPhone('<div class="mk-chat"><div class="mk-chath">' + ic('chat', 14) + 'Reportes SST</div><p class="in">Hola. ¿Qué quieres reportar?</p><p class="out">Condición insegura</p><p class="in">Envía una foto y dónde está.</p><p class="out mk-photo"><span></span>Almacén Callao, zona de despacho</p><p class="in">Listo. Tu reporte es el <b>R-0413</b>.</p></div>') +
    '<div class="mk-card mk-qrc"><div class="mk-qr sm">' + (window.__QR ? window.__QR.reporte : '') + '</div><b>Escanea y reporta</b><small>Sin instalar apps. Puedes hacerlo de forma anónima.</small></div></div>';
  if (k === 'aul') return mkWin('aula/CUR-014', '<div class="mk-h"><b>Trabajos en caliente</b>' + mkChip('Escala 0-20', 'info') + '</div><div class="mk-course"><div class="mk-video"><span>' + ic('play', 18) + '</span></div><div class="mk-secs">' +
    [['Introducción', 'ok'], ['Permisos y PETAR', 'ok'], ['Prevención de incendios', 'cur'], ['Evaluación final', 'lock']].map(([t, c]) => '<div class="mk-sec ' + c + '"><span>' + (c === 'ok' ? ic('check', 13) : c === 'lock' ? ic('lock', 12) : ic('play', 11)) + '</span>' + t + '</div>').join('') + '</div></div><div class="mk-prog"><span>Avance</span><i><b style="width:62%"></b></i><em>62%</em></div><div class="mk-foot"><span class="mk-btn">Continuar</span><span class="mk-sugg">' + ic('award', 14) + 'Certificado con QR al aprobar</span></div>');
  if (k === 'con') return mkWin('consultora', '<div class="mk-h mk-wl"><span class="mk-wlogo"></span><b>Prevencia SST</b><small>con tecnología EscudoSST</small></div><table class="mk-tbl"><thead><tr><th>Empresa</th><th>Plan</th><th>Cumplimiento</th></tr></thead><tbody>' +
    [['Industrias Andinas', 'Profesional', 78, 'ok'], ['Constructora Huaylas', 'Corporativo', 64, 'warn'], ['AgroExport Chincha', 'Esencial', 52, 'warn'], ['Clínica Santa Rosa', 'Profesional', 88, 'ok']].map(([a, b, c, d]) => '<tr><td>' + a + '</td><td>' + mkChip(b, 'info') + '</td><td><span class="mk-mini"><i><b class="' + d + '" style="width:' + c + '%"></b></i>' + c + '%</span></td></tr>').join('') + '</tbody></table>', 'mk-teal');
  if (k === 'off') return '<div class="mk-duo">' + mkPhone('<div class="mk-off">' + ic('wifi', 14) + 'Sin conexión · 3 en cola</div><div class="mk-ins"><b>Inspección de almacén</b>' + [['Extintores señalizados', 'c'], ['Pasillos libres', 'n'], ['Botiquín completo', 'c'], ['Orden y limpieza', 'c']].map(([t, v]) => '<div class="mk-q"><span>' + t + '</span><em class="' + v + '">' + (v === 'c' ? 'Cumple' : 'No cumple') + '</em></div>').join('') + '<span class="mk-btn full">Guardar en el equipo</span></div>') +
    '<div class="mk-card mk-sync"><b>Al volver la señal</b><div class="mk-rq"><span class="mk-dot ok"></span><span>3 registros sincronizados</span></div><div class="mk-rq"><span class="mk-dot warn"></span><span>1 conflicto registrado</span></div><small>Nada se pisa: los conflictos quedan para revisión.</small></div></div>';
  if (k === 'iaa') return mkWin('iperc/sugerencias', '<div class="mk-h"><b>Sugerencias del asistente</b>' + mkChip('Revisa antes de aplicar', 'warn') + '</div>' +
    [['Peligro', 'Atrapamiento en rodillos de alimentación'], ['Control', 'Guarda fija con enclavamiento'], ['Causa probable', 'Falta de bloqueo durante la limpieza']].map(([t, d], i) => '<div class="mk-ai"><div><small>' + ic('spark', 12) + t + ' sugerido</small><b>' + d + '</b></div><div class="mk-aib"><span class="mk-btn sm">Aceptar</span><span class="mk-btn sm ghost">Editar</span>' + (i === 2 ? '<span class="mk-btn sm ghost">Descartar</span>' : '') + '</div></div>').join('') + '<small class="mk-note">La IA solo sugiere. Una persona decide y queda registrado.</small>');
  return '';
}
const BLOQUES = [
  { id: 'sunafil', code: 'SUN', ic: 'shield', corto: 'Modo Inspección SUNAFIL', sub: 'Expediente listo para el inspector', t: 'Llega preparado a SUNAFIL con un expediente ordenado', d: 'Responde el autodiagnóstico, mira qué te falta y genera el expediente con su índice. El inspector recibe un enlace de solo lectura.', v: ['Autodiagnóstico guiado con avance por requisito', 'Cada documento sale del módulo donde se gestiona, siempre vigente', 'Enlace de solo lectura con vencimiento para el inspector'], mk: 'sun', demo: ['sst_manager', 'm-sun'] },
  { id: 'iperc', code: 'IPC', ic: 'risk', corto: 'Matriz IPERC', sub: 'Peligros, riesgos y controles', t: 'Una matriz IPERC viva, no un Excel olvidado', d: 'Identifica peligros por puesto y proceso, valora el riesgo con colores claros y envía la matriz a aprobación del Comité.', v: ['Filas editables con versiones y aprobación en cadena', 'Mapa de riesgos con pictogramas sobre tu plano', 'Cada control se convierte en una acción con responsable'], mk: 'ipc', demo: ['sst_manager', 'm-ipc'] },
  { id: 'fotocheck', code: 'FOT', ic: 'qr', corto: 'Fotocheck SST con QR', sub: 'Saber quién está habilitado', t: 'Un QR que dice si tu trabajador puede entrar a trabajar', d: 'El fotocheck reúne inducción, aptitud médica, EPP y SCTR. Al escanearlo se ve si está habilitado, sin mostrar datos médicos.', v: ['Semáforo de requisitos para cada trabajador', 'Verificación pública desde cualquier celular', 'Revocación inmediata si algo vence'], mk: 'fot', demo: ['sst_manager', 'm-fot'] },
  { id: 'reportes', code: 'ACT', ic: 'chat', corto: 'Reporte por QR y WhatsApp', sub: 'Reportes sin instalar nada', t: 'Que cualquiera reporte un peligro en segundos', d: 'Pega códigos QR en planta o comparte un número de WhatsApp. Los reportes llegan a una bandeja y se convierten en acciones.', v: ['Reporte con foto y ubicación, también anónimo', 'Puntos QR por zona, área o sede', 'Seguimiento hasta el cierre de la acción correctiva'], mk: 'act', demo: ['sst_manager', 'm-act'] },
  { id: 'aula', code: 'AUL', ic: 'book', corto: 'Aula virtual y venta de cursos', sub: 'Capacita y vende tus cursos', t: 'Capacita a tu gente y, si quieres, vende tus cursos', d: 'El aula vive dentro de EscudoSST: cursos con video, evaluaciones y certificados verificables. Si eres instituto o consultora, véndelos con pasarela de pagos.', v: ['Matrícula por cargo según la matriz de capacitación', 'Tiempo de permanencia real para acreditar horas', 'Venta con cupones, cupos para empresas y comprobantes'], mk: 'aul', demo: ['sst_manager', 'm-aul'] },
  { id: 'consultora', code: 'CON', ic: 'building', corto: 'Modo consultora', sub: 'Varias empresas, tu marca', t: 'Todas tus empresas cliente en un panel, con tu marca', d: 'Para consultoras SST: ve el cumplimiento y las alertas de cada cliente y entra a su sistema con un clic, con tu logo y tu color.', v: ['Cada cliente conserva su plan y sus datos separados', 'Marca blanca en el sistema y en la app del trabajador', 'Precio por empresa gestionada'], mk: 'con', demo: ['consultant', 'm-con'] },
  { id: 'offline', code: 'OFF', ic: 'wifi', corto: 'App móvil offline', sub: 'Trabaja sin señal', t: 'Inspecciona en campo aunque no haya señal', d: 'La app guarda inspecciones, charlas y reportes en el equipo. Al volver la señal, todo se sincroniza y los conflictos quedan registrados.', v: ['Fotos y firmas sin conexión', 'Sincronización sin duplicados', 'Ideal para obra, mina y fundo'], mk: 'off', demo: ['sst_manager', 'm-ins'] },
  { id: 'ia', code: 'IAA', ic: 'spark', corto: 'Asistente IA', sub: 'Sugerencias que tú apruebas', t: 'Un asistente que sugiere y tú decides', d: 'La IA propone peligros y controles para tu IPERC y guía la investigación de accidentes. Nada se aplica sin que una persona lo apruebe.', v: ['Sugerencias por puesto y actividad', 'Investigación de causas guiada', 'Nunca usa datos médicos'], mk: 'iaa', demo: ['sst_manager', 'm-iaa'] },
];

/* Videos con miniatura dibujada */
function videoThumb(v, i) {
  return '<button type="button" class="w-video" data-video="' + v.id + '" aria-label="Reproducir: ' + esc(v.t) + '"><svg viewBox="0 0 480 270" aria-hidden="true"><rect width="480" height="270" fill="' + (i ? '#0A2F58' : '#0D3B6E') + '"/>' +
    (i ? '<g opacity=".9"><rect x="40" y="46" width="250" height="178" rx="12" fill="#14497F"/><rect x="58" y="66" width="120" height="10" rx="5" fill="#9DB3CF"/>' + [0, 1, 2, 3].map((r) => '<rect x="58" y="' + (92 + r * 30) + '" width="214" height="20" rx="6" fill="#1B5A99"/><rect x="62" y="' + (96 + r * 30) + '" width="' + [200, 170, 120, 190][r] + '" height="12" rx="4" fill="' + ['#22A35A', '#22A35A', '#F4B90F', '#22A35A'][r] + '" opacity=".85"/>').join('') + '</g><circle cx="370" cy="132" r="56" fill="none" stroke="#14497F" stroke-width="16"/><circle cx="370" cy="132" r="56" fill="none" stroke="#1FB5A3" stroke-width="16" stroke-dasharray="302 352" transform="rotate(-90 370 132)" stroke-linecap="round"/>'
      : '<g opacity=".9"><rect x="36" y="40" width="408" height="190" rx="14" fill="#14497F"/><rect x="36" y="40" width="408" height="28" rx="14" fill="#0A2F58"/>' + [0, 1, 2].map((c) => '<rect x="' + (56 + c * 130) + '" y="86" width="112" height="56" rx="10" fill="#1B5A99"/><rect x="' + (68 + c * 130) + '" y="100" width="44" height="18" rx="4" fill="' + ['#22A35A', '#F4B90F', '#E2453C'][c] + '"/>').join('') + [46, 62, 38, 70, 54, 80, 66].map((h, j) => '<rect x="' + (60 + j * 30) + '" y="' + (214 - h) + '" width="18" height="' + h + '" rx="4" fill="#4DA3EC"/>').join('') + '</g>') +
    '<rect x="0" y="236" width="480" height="34" fill="url(#w-cinta-v)"/><defs><pattern id="w-cinta-v" width="20" height="20" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="20" height="20" fill="#F4B90F"/><rect width="10" height="20" fill="#0D3B6E"/></pattern></defs></svg>' +
    '<span class="w-play">' + ic('play', 26) + '</span><span class="w-vprov">' + esc(v.prov) + '</span></button>';
}
function abrirVideo(id) {
  const v = WEB.videos.find((x) => x.id === id); if (!v) return;
  modal(v.t, '<div class="w-vmodal" role="img" aria-label="Reproductor de video simulado"><span class="w-play">' + ic('play', 30) + '</span><p>Video de ' + esc(v.prov) + '</p></div><p class="small" style="margin-top:12px">' + esc(v.d) + '</p>' +
    nota(v.url ? 'Se reproduce el video incrustado desde ' + esc(v.prov) + ': <b>' + esc(v.url) + '</b>. En el prototipo el reproductor es simulado.' : 'El enlace del video de ' + esc(v.prov) + ' se configura en el panel «Sitio web» › Videos. En el prototipo el reproductor es simulado.'), [{ l: 'Cerrar', cls: 'pri' }], { wide: true });
}

/* Agenda de demo con fecha y hora simuladas */
const HORAS_DEMO = ['09:00', '10:30', '12:00', '15:00', '16:30'];
const DIAS_SEM = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
const MESES_C = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];
function diasDemo() { const out = []; const d = new Date(HOY); while (out.length < 10) { d.setDate(d.getDate() + 1); if (d.getDay() && d.getDay() !== 6) out.push(new Date(d)); } return out; }
const ocupado = (i, j) => (i * 3 + j * 2) % 5 === 0;
function agendaHTML() {
  const ag = WUI.ag || (WUI.ag = { dia: null, hora: null });
  const dias = diasDemo();
  return '<form class="w-form w-agenda" id="w-ag" novalidate><div class="w-agsel"><div><h3 class="w-agt">Elige un día</h3><div class="w-days" role="radiogroup" aria-label="Día de la demo">' +
    dias.map((d, i) => '<button type="button" role="radio" aria-checked="' + (ag.dia === i) + '" data-dia="' + i + '"><small>' + DIAS_SEM[d.getDay()] + '</small><b>' + d.getDate() + '</b><small>' + MESES_C[d.getMonth()] + '</small></button>').join('') + '</div></div>' +
    '<div><h3 class="w-agt">Elige una hora <small>(hora de Lima)</small></h3><div class="w-hours" role="radiogroup" aria-label="Hora de la demo">' + (ag.dia == null ? '<p class="w-fine">Primero elige un día.</p>' : HORAS_DEMO.map((h, j) => ocupado(ag.dia, j) ? '<button type="button" disabled aria-disabled="true"><s>' + h + '</s><small>Ocupado</small></button>' : '<button type="button" role="radio" aria-checked="' + (ag.hora === h) + '" data-hora="' + h + '">' + h + '</button>').join('')) + '</div><small class="w-err" id="ag-sel-e" role="alert"></small></div></div>' +
    '<div class="w-fg">' + wf('ag-nom', 'Nombre y apellido', 'text', { req: true, ac: 'name' }) + wf('ag-cor', 'Correo de trabajo', 'email', { req: true, ac: 'email' }) + wf('ag-emp', 'Empresa', 'text', { req: true, ac: 'organization' }) + wf('ag-ruc', 'RUC', 'text', { req: true, im: 'numeric', hint: '11 dígitos' }) + '</div>' +
    '<div class="w-consent"><label for="ag-ok"><input type="checkbox" id="ag-ok" aria-describedby="ag-ok-e"><span>Acepto el uso de mis datos para coordinar la demo, según la <a href="#web-legal-privacidad">Política de privacidad</a>. <span class="w-req" aria-hidden="true">*</span></span></label><small class="w-err" id="ag-ok-e" role="alert"></small></div>' +
    captcha('ag-cap') + '<small class="w-err" id="ag-cap-e" role="alert"></small><button type="submit" class="w-btn pri lg">Agendar la demo</button></form>';
}
function bindAgenda(root) {
  const f = $('#w-ag', root); if (!f) return;
  bindCaptcha(root, 'ag-cap');
  const refrescar = (foco) => { const nuevo = document.createElement('div'); nuevo.innerHTML = agendaHTML(); const vals = {}; $$('input', f).forEach((i) => { vals[i.id] = i.type === 'checkbox' ? i.checked : i.value; }); const sel = $('.w-agsel', nuevo); $('.w-agsel', f).replaceWith(sel); bindSel(); if (foco) { const b = $(foco, f); if (b) b.focus(); } };
  const bindSel = () => {
    $$('[data-dia]', f).forEach((b) => b.addEventListener('click', () => { WUI.ag.dia = +b.dataset.dia; WUI.ag.hora = null; refrescar('[data-dia="' + b.dataset.dia + '"]'); }));
    $$('[data-hora]', f).forEach((b) => b.addEventListener('click', () => { WUI.ag.hora = b.dataset.hora; refrescar('[data-hora="' + b.dataset.hora + '"]'); }));
  };
  bindSel();
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    const v = (id) => ($('#' + id, root).value || '').trim();
    let ok = true;
    const selOk = WUI.ag.dia != null && WUI.ag.hora; $('#ag-sel-e', root).textContent = selOk ? '' : 'Elige un día y una hora.'; ok = selOk && ok;
    ok = errorCampo(root, 'ag-nom', v('ag-nom').length < 3 ? 'Escribe tu nombre y apellido.' : '') && ok;
    ok = errorCampo(root, 'ag-cor', !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v('ag-cor')) ? 'Escribe un correo válido, por ejemplo nombre@empresa.pe.' : '') && ok;
    ok = errorCampo(root, 'ag-emp', !v('ag-emp') ? 'Escribe el nombre de tu empresa.' : '') && ok;
    ok = errorCampo(root, 'ag-ruc', !/^\d{11}$/.test(v('ag-ruc')) ? 'El RUC tiene 11 dígitos.' : !rucValido(v('ag-ruc')) ? 'Este RUC no es válido. Revisa los dígitos.' : '') && ok;
    ok = errorCampo(root, 'ag-ok', !$('#ag-ok', root).checked ? 'Necesitamos tu consentimiento para coordinar la demo.' : '') && ok;
    const cap = $('#ag-cap', root).classList.contains('ok'); $('#ag-cap-e', root).textContent = cap ? '' : 'Marca «No soy un robot».'; ok = cap && ok;
    if (!ok) { const p = $('[aria-invalid="true"], .w-captcha:not(.ok) input', root) || $('[data-dia]', root); if (p) p.focus(); toast('Revisa los campos marcados', 'warn'); return; }
    const d = diasDemo()[WUI.ag.dia]; const cuando = DIAS_SEM[d.getDay()] + ' ' + fmtF(d) + ' · ' + WUI.ag.hora;
    const id = 'DEM-0' + WEB.sigDemo++;
    WEB.demos.unshift({ id, f: fmtF(HOY), nombre: v('ag-nom'), empresa: v('ag-emp'), ruc: v('ag-ruc'), trab: '—', sector: '—', interes: 'Demo', estado: 'Demo agendada', resp: 'Sin asignar', agenda: cuando, correo: v('ag-cor') });
    WUI.ag = { dia: null, hora: null };
    f.outerHTML = '<div class="w-form w-done" tabindex="-1" id="w-done"><span class="w-plate green">' + ic('check', 30) + '</span><h2>Tu demo quedó agendada</h2><p><b>' + esc(cuando) + '</b> (hora de Lima). Te enviaremos la invitación a <b>' + esc(v('ag-cor')) + '</b>.</p><p class="w-fine">Código ' + id + '. En el prototipo no se envía ningún correo; la solicitud aparece en el panel del Dueño de la plataforma.</p><div class="w-ctas"><button type="button" class="w-btn" id="ag-cal">' + ic('cal', 18) + 'Agregar a mi calendario</button><button type="button" class="w-btn pri" data-demo-int>Ver la demo interactiva ahora</button></div></div>';
    $('#w-done', root).focus();
    $('#ag-cal', root).onclick = () => toast('Archivo de calendario generado (simulado)');
    $$('[data-demo-int]', root).forEach((b) => b.addEventListener('click', demoInteractiva));
  });
}
function demoInteractiva() { document.body.classList.remove('is-web'); restaurarSeo(); irComo('sst_manager', 'inicio'); toast('Demo interactiva: recorre el sistema con datos de ejemplo'); }

function planCards(per) {
  const igv = WEB.ajustes.igv;
  const tarjeta = (p) => {
    const v = p[per]; const n = p.id === 'K' ? null : modsDePlan(p.id); const conPrecio = v != null && v !== '';
    return '<article class="w-plan' + (p.destacado ? ' dest' : '') + '" aria-labelledby="pl-' + p.id + '">' + (p.destacado ? '<span class="w-rec">Recomendado</span>' : '') +
      '<h3 id="pl-' + p.id + '">' + esc(p.nombre) + '</h3><p class="w-pfor">' + esc(p.para) + '</p>' +
      '<div class="w-price">' + (conPrecio ? '<b class="num">' + precioTxt(v, per) + '</b><small>' + (p.id === 'K' ? 'por empresa gestionada' : 'por empresa') + (igv ? ' · más IGV' : '') + '</small>' : '<b class="w-cot">Solicitar cotización</b><small>' + (p.id === 'K' ? 'Precio por empresa gestionada' : 'Precio según tu empresa') + '</small>') + '</div>' +
      '<a class="w-btn ' + (p.destacado ? 'pri' : '') + ' lg w-full" href="' + (conPrecio && p.id !== 'K' ? '#registro' : '#web-contacto/cotizacion') + '">' + (conPrecio && p.id !== 'K' ? 'Prueba gratis' : 'Pedir cotización') + '</a>' +
      '<p class="w-pinc">' + (n ? '<b>' + n + ' módulos</b>' + (p.id !== 'E' ? ', incluye todo ' + (p.id === 'P' ? 'Esencial' : 'Profesional') : '') : '<b>Modo consultora y marca blanca</b>') + '</p><ul class="w-plim">' + p.lim.map((l) => '<li>' + ic('check', 16) + '<span>' + esc(l) + '</span></li>').join('') + '</ul></article>';
  };
  const vis = WEB.planes.filter((p) => p.publicado);
  return '<div class="w-plans n' + vis.length + '">' + vis.map(tarjeta).join('') + '</div>';
}
const periodoSel = (per) => '<div class="w-period" role="group" aria-label="Periodo de facturación"><button type="button" data-per="m" aria-pressed="' + (per === 'm') + '">Mensual</button><button type="button" data-per="a" aria-pressed="' + (per === 'a') + '">Anual</button></div>';

/* ---------- Portada ---------- */
function wPortada() {
  const a = WEB.ajustes;
  const B = {};
  B.hero = '<section class="w-hero"><div class="w-in w-hero-g"><div class="w-hero-t"><p class="w-kick">Software SST para empresas peruanas</p><h1>' + esc(a.lema) + '</h1><p class="w-lead">' + esc(a.heroSub) + '</p>' +
    '<ul class="w-vent">' + a.ventajas.filter(Boolean).map((x) => '<li>' + ic('check', 18) + '<span>' + esc(x) + '</span></li>').join('') + '</ul>' +
    '<div class="w-ctas"><a class="w-btn pri lg" href="#registro">Prueba gratis</a><a class="w-btn lg" href="#web-contacto">Agenda una demo</a></div><p class="w-fine">Registro autoservicio con el RUC de tu empresa. ¿Cuánto arriesgas? <a href="#web-calculadora">Calcula tu multa</a> o <a href="#web-autodiagnostico">haz el autodiagnóstico gratis</a>.</p></div>' +
    '<div class="w-hero-i">' + ilHero() + '</div></div></section>';
  B.confianza = '<section class="w-trust" aria-label="Sellos de confianza"><div class="w-in"><ul>' +
    [['shield', 'Pensado para la Ley 29783 y su reglamento'], ['lock', 'Datos personales según la Ley 29733'], ['cloud', 'Datos cifrados en la nube'], ['user', 'Soporte desde Perú, en español']].map(([i, t]) => '<li>' + ic(i, 20) + '<span>' + esc(t) + '</span></li>').join('') + '</ul></div></section>';
  const vids = WEB.videos.filter((v) => v.on);
  B.videos = vids.length ? '<section class="w-sec" id="ws-videos"><div class="w-in"><div class="w-sh"><h2>Mira EscudoSST en acción</h2><p>Una presentación general y un tutorial paso a paso.</p></div><div class="w-videos">' + vids.map((v, i) => '<figure class="w-vfig">' + videoThumb(v, i) + '<figcaption><b>' + esc(v.t) + '</b><span>' + esc(v.d) + '</span></figcaption></figure>').join('') + '</div></div></section>' : '';
  B.modulos = '<section class="w-sec w-band" id="ws-modulos"><div class="w-in"><div class="w-sh"><h2>Lo que puedes hacer con EscudoSST</h2><p>Los módulos que más diferencian a EscudoSST, tal como se ven en el sistema.</p></div>' +
    BLOQUES.map((b, i) => { const m = modBy(b.code); return '<article class="w-blk' + (i % 2 ? ' rev' : '') + '" id="ws-m-' + b.id + '"><div class="w-blkt"><span class="w-plate ' + ['blue', 'red', 'green', 'amber', 'navy', 'teal', 'navy', 'orange'][i] + '">' + ic(b.ic, 24) + '</span><h3>' + esc(b.t) + '</h3><p>' + esc(b.d) + '</p><ul class="w-checks">' + b.v.map((x) => '<li>' + ic('check', 18) + '<span>' + esc(x) + '</span></li>').join('') + '</ul>' +
      '<p class="w-tagp">' + esc(m.plan === '+' ? 'Complemento para cualquier plan' : m.plan === 'K' ? 'Plan Consultora' : 'Desde el plan ' + m.plan_nombre) + '</p><div class="w-ctas"><a class="w-btn pri" href="#registro">Probar gratis</a><button type="button" class="w-btn" data-vdemo="' + i + '">Ver demo</button></div></div><div class="w-blki">' + mk(b.mk) + '</div></article>'; }).join('') + '</div></section>';
  B.grupos = '<section class="w-sec"><div class="w-in"><div class="w-sh"><h2>Todo el sistema de gestión, en ' + MODS.filter((m) => m.plan !== 'I').length + ' módulos</h2><p>Activa lo que necesitas según tu plan. Los módulos se agrupan como trabaja un área SST.</p></div><div class="w-grupos">' +
    Object.entries(CAT.grupos).map(([g, gn]) => { const ms = MODS.filter((m) => m.grupo === +g && m.plan !== 'I'); return '<a class="w-grupo" href="#web-producto"><span class="w-gi">' + ic(GRUPO_IC[g], 22) + '</span><span class="w-gt"><b>' + esc(gn) + '</b><small>' + ms.length + ' módulos</small></span><span class="w-gm">' + ms.slice(0, 3).map((m) => esc(m.nombre)).join(', ') + (ms.length > 3 ? ' y ' + (ms.length - 3) + ' más' : '') + '</span></a>'; }).join('') + '</div></div></section>';
  B.pasos = '<section class="w-sec w-band" id="ws-como-funciona"><div class="w-in"><div class="w-sh"><h2>Cómo funciona</h2><p>De tu registro al primer expediente, en cuatro pasos.</p></div><ol class="w-pasos n4">' +
    [['Regístrate con tu RUC', 'Validamos la empresa, eliges tu plan y creas tu cuenta.'], ['Carga tu información', 'Importa a tus trabajadores desde Excel con validación por fila.'], ['Haz tu línea base', 'El diagnóstico te dice qué falta y arma tu plan de trabajo.'], ['Gestiona y llega preparado', 'Sigue el plan anual, recibe alertas y genera el expediente SUNAFIL.']].map(([t, d], i) => '<li><span class="w-pn">' + (i + 1) + '</span><h3>' + esc(t) + '</h3><p>' + esc(d) + '</p></li>').join('') + '</ol></div></section>';
  B.planes = '<section class="w-sec" id="ws-planes"><div class="w-in"><div class="w-sh w-shc"><h2>Planes y precios</h2><p>Planes acumulativos: cada uno incluye todo lo del anterior.</p>' + periodoSel(WUI.periodo) + '</div>' + planCards(WUI.periodo) + '<p class="w-center"><a class="w-btn" href="#web-planes">Comparar módulo por módulo</a></p></div></section>';
  B.sectores = '<section class="w-sec w-band"><div class="w-in"><div class="w-sh"><h2>Hecho para tu sector</h2><p>Los paquetes sectoriales traen plantillas, peligros y formatos de cada actividad.</p></div><div class="w-sects">' +
    Object.entries(WEB_SECTORES).map(([k, s]) => '<a class="w-sect" href="#web-sector-' + k + '">' + ilSector(k) + '<h3>' + esc(s.n) + '</h3><p>' + esc(s.h) + '</p></a>').join('') + '</div></div></section>';
  B.demo = '<section class="w-sec" id="ws-demo"><div class="w-in"><div class="w-sh"><h2>Mira EscudoSST con tus propios ojos</h2><p>Agenda una demo con un asesor o recorre ahora la demo interactiva con datos de ejemplo.</p></div><div class="w-demog"><div class="w-demoa"><h3>Agenda una demo</h3>' + agendaHTML() + '</div>' +
    '<div class="w-demob"><div class="w-demobi">' + mk('sun') + '</div><h3>Demo interactiva</h3><p>Entra al prototipo navegable como Jefa SST de una empresa de ejemplo. Prueba el IPERC, una inspección y el Modo SUNAFIL.</p><button type="button" class="w-btn amb lg" data-demo-int>Ver demo interactiva</button></div></div></div></section>';
  const tes = WEB.testimonios.filter((t) => t.estado === 'Aprobado');
  B.testimonios = tes.length ? '<section class="w-sec w-band"><div class="w-in"><div class="w-sh"><h2>Lo que dicen quienes lo usan</h2><p>Comentarios de ejemplo para la demostración.</p></div><div class="w-tests">' + tes.map((t) => '<figure class="w-test"><span class="w-ejt">Ejemplo</span><blockquote>' + esc(t.txt) + '</blockquote><figcaption><span class="w-tav" aria-hidden="true">' + esc(t.ini) + '</span><span><b>' + esc(t.cargo) + '</b><small>' + esc(t.emp) + '</small></span></figcaption></figure>').join('') + '</div></div></section>' : '';
  B.blog = a.blogPortada ? '<section class="w-sec"><div class="w-in"><div class="w-sh w-shrow"><div><h2>Últimos artículos</h2><p>Guías prácticas desde nuestro blog.</p></div><a class="w-btn" href="' + blogHref() + '" target="_blank" rel="noopener">Ir al blog' + ic('ext', 16) + '<span class="sr"> (se abre en otra pestaña)</span></a></div><div class="w-posts">' +
    WEB.ultimos.map((p, i) => '<a class="w-post" href="' + blogHref() + '" target="_blank" rel="noopener"><div class="w-thumb t-' + (i + 1) + '" aria-hidden="true">' + ic(['shield', 'risk', 'heart'][i], 36) + '</div><div class="w-pbody"><span class="w-pcat">' + esc(p.cat) + '</span><h3>' + esc(p.t) + '</h3><p>' + esc(p.ex) + '</p><time>' + esc(p.f) + '</time></div></a>').join('') + '</div><p class="w-fine w-center">Los artículos se leen del WordPress (' + esc(a.blogUrl || 'URL por definir') + '). En el prototipo son de ejemplo.</p></div></section>' : '';
  const prods = WEB.productos.filter((p) => p.on);
  B.productos = prods.length ? '<section class="w-sec w-band" id="ws-productos"><div class="w-in"><div class="w-sh"><h2>Otros productos de ' + esc(a.razon) + '</h2><p>Más soluciones del mismo equipo. Las descripciones son provisionales y editables.</p></div><div class="w-prods">' + prods.map((p) => '<div class="w-prod"><span class="w-plogo" style="background:' + esc(p.color) + '" aria-hidden="true">' + (p.logo ? '<img src="' + p.logo + '" alt="">' : esc(p.ini)) + '</span><div><span class="w-ejt">Descripción editable</span><h3>' + esc(p.n) + '</h3><p>' + esc(p.d) + '</p>' + (p.url ? '<a href="' + esc(p.url) + '" target="_blank" rel="noopener">Conocer ' + esc(p.n) + '<span class="sr"> (se abre en otra pestaña)</span></a>' : '<span class="w-pend">Enlace por definir</span>') + '</div></div>').join('') + '</div></div></section>' : '';
  B.faq = '<section class="w-sec"><div class="w-in w-faqg"><div class="w-sh"><h2>Preguntas frecuentes</h2><p>¿Tienes otra duda? <a href="#web-contacto/contacto">Escríbenos</a>.</p></div>' + wFaq(WEB.faqs.filter((f) => f.p === 'portada')) + '</div></section>';
  B.herramientas = wxBloqueHerramientas();
  B.casos = wxBloqueCasos();
  B.cta = wCTA();
  return WEB.bloques.filter((b) => b.on).map((b) => B[b.id] || '').join('');
}

/* ---------- Producto ---------- */
function wProducto() {
  return '<section class="w-phero"><div class="w-in">' + wMigas([['Producto']]) + '<h1>Un sistema de gestión SST completo, sin papeles</h1><p class="w-lead">Desde la línea base hasta la revisión por la dirección. Cada módulo guarda evidencia, responsables y fechas, y alimenta tus indicadores.</p><div class="w-ctas"><a class="w-btn pri lg" href="#registro">Prueba gratis</a><a class="w-btn lg" href="#web-planes">Ver planes</a></div></div></section>' +
    '<section class="w-sec"><div class="w-in"><div class="w-sh"><h2>Los 7 diferenciadores</h2></div><div class="w-difl">' +
    DIFS.map((d) => { const m = modBy(d[0]); return '<div class="w-difr"><span class="w-plate ' + d[4] + '">' + ic(d[1], 24) + '</span><div><h3>' + esc(d[2]) + '</h3><p>' + esc(d[3]) + '</p><span class="w-tagp">' + esc(m.plan === '+' ? 'Complemento' : m.plan === 'K' ? 'Plan Consultora' : 'Desde el plan ' + m.plan_nombre) + '</span></div></div>'; }).join('') + '</div></div></section>' +
    '<section class="w-sec w-band"><div class="w-in"><div class="w-sh"><h2>Módulos por grupo</h2><p>Los que no incluye tu plan se pueden sumar al cambiar de plan o como complemento.</p></div>' +
    Object.entries(CAT.grupos).map(([g, gn]) => '<div class="w-mg"><h3>' + ic(GRUPO_IC[g], 20) + esc(gn) + '</h3><ul>' + MODS.filter((m) => m.grupo === +g && m.plan !== 'I').map((m) => '<li><b>' + esc(m.nombre) + '</b><span>' + esc(m.resuelve) + '</span><em>' + esc(m.plan === '+' ? 'Complemento' : m.plan === 'K' ? 'Consultora' : m.plan_nombre) + '</em></li>').join('') + '</ul></div>').join('') +
    '</div></section>' + wCTA();
}

/* ---------- Planes ---------- */
function wPlanes() {
  const per = WUI.periodo;
  const celda = (m, k) => {
    if (m.plan === '+') return '<td><span class="w-cpl">Complemento</span></td>';
    if (m.plan === 'K') return '<td><span class="w-cpl">Plan Consultora</span></td>';
    const ok = (CAT.plan_incluye[k] || []).includes(m.plan);
    return '<td>' + (ok ? '<span class="w-ok" title="Incluido">' + ic('check', 18) + '<span class="sr">Incluido</span></span>' : '<span class="w-no" title="No incluido">' + ic('lock', 16) + '<span class="sr">No incluido</span></span>') + '</td>';
  };
  const comp = Object.entries(CAT.grupos).map(([g, gn]) => {
    const ms = MODS.filter((m) => m.grupo === +g && m.plan !== 'I');
    const abierto = !!WUI.grupoAbierto[g];
    return '<div class="w-cg' + (abierto ? ' open' : '') + '"><button type="button" class="w-cgh" data-cg="' + g + '" aria-expanded="' + abierto + '"><span>' + ic(GRUPO_IC[g], 18) + esc(gn) + '</span><small>' + ms.length + ' módulos</small>' + ic('chev', 18) + '</button>' +
      '<div class="w-cgb"' + (abierto ? '' : ' hidden') + '><table class="w-cmp"><caption class="sr">' + esc(gn) + ': módulos por plan</caption><colgroup><col><col class="w-cc"><col class="w-cc"><col class="w-cc"></colgroup><thead class="sr"><tr><th scope="col">Módulo</th><th scope="col">Esencial</th><th scope="col">Profesional</th><th scope="col">Corporativo</th></tr></thead><tbody>' +
      ms.map((m) => '<tr><th scope="row"><b>' + esc(m.nombre) + '</b><small>' + esc(m.resuelve) + '</small></th>' + (m.plan === '+' || m.plan === 'K' ? '<td colspan="3" class="w-tdc">' + (m.plan === '+' ? '<span class="w-cpl">Complemento para cualquier plan</span>' : '<span class="w-cpl">Incluido en el plan Consultora</span>') + '</td>' : celda(m, 'E') + celda(m, 'P') + celda(m, 'C')) + '</tr>').join('') + '</tbody></table></div></div>';
  }).join('');
  const addons = COMPLEMENTOS.map(([c, n, d]) => { const pr = WEB.addonsPrecio[c] || {}; const v = pr[per]; return '<div class="w-addon"><h3>' + esc(n) + '</h3><p>' + esc(d) + '</p><span class="w-aprice">' + (v != null && v !== '' ? '<b class="num">' + precioTxt(v, per) + '</b>' : 'Solicitar cotización') + '</span></div>'; }).join('');
  return '<section class="w-phero w-center-h"><div class="w-in">' + wMigas([['Planes y precios']]) + '<h1>Un plan para cada etapa de tu sistema SST</h1><p class="w-lead">Todos los planes son acumulativos: cada uno incluye todo lo del anterior. Suma complementos cuando los necesites.</p>' +
    periodoSel(per) + '</div></section>' +
    '<section class="w-sec w-tight"><div class="w-in">' + planCards(per) + '<p class="w-fine w-center">Los precios se publican cuando estén definidos. Mientras tanto, te enviamos una cotización según el tamaño de tu empresa.</p></div></section>' +
    '<section class="w-sec w-band" id="comparativa"><div class="w-in"><div class="w-sh"><h2>Compara módulo por módulo</h2><p>Toca un grupo para ver sus módulos.</p></div>' +
    '<div class="w-cmph" aria-hidden="true"><span>Módulo</span><span><span class="lg">Esencial</span><span class="sh">Esen.</span></span><span><span class="lg">Profesional</span><span class="sh">Prof.</span></span><span><span class="lg">Corporativo</span><span class="sh">Corp.</span></span></div>' + comp +
    '<div class="w-cmpf"><button type="button" class="w-btn" id="w-cg-all">' + (Object.values(WUI.grupoAbierto).filter(Boolean).length === 8 ? 'Plegar todos los grupos' : 'Desplegar todos los grupos') + '</button><p class="w-fine">' + MODS.filter((m) => m.plan !== 'I').length + ' módulos contratables. Además, ' + MODS.filter((m) => m.plan === 'I').length + ' módulos internos de la plataforma (suscripciones y sitio web) que opera EscudoSST.</p></div></div></section>' +
    '<section class="w-sec"><div class="w-in"><div class="w-sh"><h2>Complementos</h2><p>Se suman a cualquier plan.</p></div><div class="w-addons">' + addons + '</div></div></section>' +
    '<section class="w-sec w-band"><div class="w-in w-faqg"><div class="w-sh"><h2>Facturación y planes</h2><p>¿Necesitas una cotización formal? <a href="#web-contacto/cotizacion">Solicítala aquí</a>.</p></div>' + wFaq(WEB.faqs.filter((f) => f.p === 'planes')) + '</div></section>' + wCTA('¿No sabes qué plan elegir?', 'Cuéntanos cuántos trabajadores y sedes tienes y te recomendamos el plan adecuado.');
}

/* ---------- Sectores ---------- */
function wSectores() {
  return '<section class="w-phero"><div class="w-in">' + wMigas([['Sectores']]) + '<h1>EscudoSST para tu sector</h1><p class="w-lead">Cada actividad tiene sus peligros, sus permisos y su normativa. Los paquetes sectoriales los traen listos para usar.</p></div></section>' +
    '<section class="w-sec w-tight"><div class="w-in"><div class="w-sects">' + Object.entries(WEB_SECTORES).map(([k, s]) => '<a class="w-sect" href="#web-sector-' + k + '">' + ilSector(k) + '<h2 class="w-h3">' + esc(s.n) + '</h2><p>' + esc(s.h) + '</p></a>').join('') + '</div></div></section>' + wCTA();
}
function wSector(k) {
  const s = WEB_SECTORES[k];
  return '<section class="w-phero"><div class="w-in w-hero-g w-sector-h"><div>' + wMigas([['Sectores', 'web-sectores'], [s.n]]) + '<p class="w-kick">' + esc(s.n) + '</p><h1>' + esc(s.h) + '</h1><p class="w-lead">' + esc(s.d) + '</p><div class="w-ctas"><a class="w-btn pri lg" href="#registro">Prueba gratis</a><a class="w-btn lg" href="#web-contacto">Agenda una demo</a></div></div><div class="w-hero-i">' + ilSector(k, 'w-il-big') + '</div></div></section>' +
    '<section class="w-sec"><div class="w-in"><div class="w-sh"><h2>Lo que más cuesta en ' + esc(s.n.toLowerCase()) + '</h2></div><div class="w-dolores">' + s.dolores.map(([t, d]) => '<div class="w-dolor"><span class="w-plate amber">' + ic('warn', 22) + '</span><h3>' + esc(t) + '</h3><p>' + esc(d) + '</p></div>').join('') + '</div></div></section>' +
    '<section class="w-sec w-band"><div class="w-in"><div class="w-sh"><h2>Módulos clave para tu operación</h2></div><div class="w-modk">' + s.mods.map((c) => { const m = modBy(c); return '<div class="w-mk"><span class="w-gi">' + ic(GRUPO_IC[m.grupo], 20) + '</span><div><b>' + esc(m.nombre) + '</b><span>' + esc(m.resuelve) + '</span></div><em>' + esc(m.plan === '+' ? 'Complemento' : m.plan_nombre) + '</em></div>'; }).join('') + '</div></div></section>' +
    '<section class="w-sec"><div class="w-in w-split w-split-even"><div class="w-norma"><h2>Normativa del sector</h2><p>' + esc(s.norma) + '</p><p class="w-fine">Referencia general. La vigencia y el alcance de cada norma se confirman con un especialista SST o legal.</p></div>' +
    '<div class="w-ejemplo"><span class="w-ejt">Ejemplo ilustrativo</span><h3>Así se vería en tu empresa</h3><p>' + esc(s.ej) + '</p></div></div></section>' + wCTA('Llévalo a tu ' + (k === 'oficinas' ? 'empresa' : k === 'construccion' ? 'obra' : 'operación'), 'Te mostramos EscudoSST con un caso de ' + s.n.toLowerCase() + '.');
}

/* ---------- Consultoras ---------- */
function wConsultoras() {
  return '<section class="w-phero"><div class="w-in w-hero-g"><div>' + wMigas([['Consultoras']]) + '<h1>Atiende a todas tus empresas cliente desde un solo panel</h1><p class="w-lead">El plan Consultora reúne a tus clientes en un tablero, con tu logo y tu color. Cada empresa conserva su plan y sus datos separados.</p><div class="w-ctas"><a class="w-btn pri lg" href="#web-contacto/cotizacion">Solicitar cotización</a><a class="w-btn lg" href="#web-planes">Ver planes</a></div></div><div class="w-hero-i">' + ilConsultora() + '</div></div></section>' +
    '<section class="w-sec"><div class="w-in"><div class="w-feats">' + [['grid', 'Panel multi-cliente', 'Cumplimiento, alertas y vencimientos de cada empresa en una sola tabla.'], ['tag', 'Marca blanca', 'Tu logo y tu color en el sistema que ven tus clientes y sus trabajadores.'], ['lock', 'Datos aislados', 'Cada empresa cliente está separada. Tu equipo solo ve lo que le asignas.'], ['plan', 'Un plan por cliente', 'Cada cliente puede tener Esencial, Profesional o Corporativo según su tamaño.']].map(([i, t, d]) => '<div class="w-feat"><span class="w-plate teal">' + ic(i, 22) + '</span><h3>' + esc(t) + '</h3><p>' + esc(d) + '</p></div>').join('') + '</div></div></section>' +
    '<section class="w-sec w-band"><div class="w-in w-split w-split-even"><div><h2>Cómo se cobra</h2><p class="w-lead2">El precio del plan Consultora es por empresa gestionada. Sumas o quitas clientes cuando lo necesites.</p><ul class="w-checks"><li>' + ic('check', 18) + '<span>Cada cliente mantiene su propio plan y complementos.</span></li><li>' + ic('check', 18) + '<span>Puedes facturar tú al cliente o que el cliente contrate directo.</span></li><li>' + ic('check', 18) + '<span>Si un cliente se va, sus datos le pertenecen y se exportan.</span></li></ul></div>' +
    '<div class="w-ejemplo"><span class="w-ejt">Ejemplo ilustrativo</span><h3>Una consultora con cuatro clientes</h3><p>Una constructora en plan Corporativo, una planta en Profesional, un fundo en Esencial y una clínica en Profesional. La consultora ve las alertas de las cuatro cada mañana y entra a cada una con un clic.</p></div></div></section>' + wCTA('Lleva tu consultora al siguiente nivel', 'Agenda una demo del modo consultora con tus propios clientes de ejemplo.');
}

/* ---------- Contacto: demo, contacto y cotización ---------- */
function rucValido(r) {
  if (!/^(10|15|17|20)\d{9}$/.test(r)) return false;
  const p = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2]; const s = p.reduce((a, w, i) => a + w * +r[i], 0); let d = 11 - (s % 11); if (d === 10) d = 0; if (d === 11) d = 1; return d === +r[10];
}
const wf = (id, l, tipo, opt = {}) => '<div class="w-f' + (opt.full ? ' full' : '') + '"><label for="' + id + '">' + esc(l) + (opt.req ? ' <span class="w-req" aria-hidden="true">*</span>' : ' <span class="w-opt">(opcional)</span>') + '</label>' +
  (tipo === 'select' ? '<select id="' + id + '"' + (opt.req ? ' required' : '') + ' aria-describedby="' + id + '-e"><option value="">Elige una opción</option>' + opt.ops.map((o) => '<option' + (opt.val === o ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>'
    : tipo === 'textarea' ? '<textarea id="' + id + '" rows="' + (opt.rows || 4) + '"' + (opt.req ? ' required' : '') + ' aria-describedby="' + id + '-e"' + (opt.ph ? ' placeholder="' + esc(opt.ph) + '"' : '') + '></textarea>'
      : '<input id="' + id + '" type="' + tipo + '"' + (opt.req ? ' required' : '') + (opt.ac ? ' autocomplete="' + opt.ac + '"' : '') + (opt.im ? ' inputmode="' + opt.im + '"' : '') + (opt.ph ? ' placeholder="' + esc(opt.ph) + '"' : '') + (opt.val ? ' value="' + esc(opt.val) + '"' : '') + (opt.ro ? ' readonly' : '') + ' aria-describedby="' + id + '-e">') +
  (opt.hint ? '<small class="w-hint">' + opt.hint + '</small>' : '') + '<small class="w-err" id="' + id + '-e" role="alert"></small></div>';
const captcha = (id) => '<div class="w-captcha" id="' + id + '"><label for="' + id + '-c"><input type="checkbox" id="' + id + '-c"><span class="w-capbox" aria-hidden="true"></span><span>No soy un robot</span></label><span class="w-capst" aria-live="polite"></span><span class="w-capbrand">Verificación simulada</span></div>';
function bindCaptcha(root, id) {
  const cb = $('#' + id + '-c', root); const st = $('#' + id + ' .w-capst', root); const box = $('#' + id, root);
  cb.addEventListener('change', () => { if (!cb.checked) { box.classList.remove('ok', 'load'); st.textContent = ''; return; } box.classList.add('load'); st.textContent = 'Verificando…'; setTimeout(() => { box.classList.remove('load'); box.classList.add('ok'); st.textContent = 'Verificado'; }, 600); });
}
function errorCampo(root, id, msg) { const e = $('#' + id + '-e', root); const i = $('#' + id, root); if (e) e.textContent = msg || ''; if (i) { i.setAttribute('aria-invalid', msg ? 'true' : 'false'); i.closest('.w-f') && i.closest('.w-f').classList.toggle('bad', !!msg); } return !msg; }
const consent = (id, txt) => '<div class="w-consent"><label for="' + id + '"><input type="checkbox" id="' + id + '" aria-describedby="' + id + '-e"><span>' + txt + ' <span class="w-req" aria-hidden="true">*</span></span></label><small class="w-err" id="' + id + '-e" role="alert"></small></div>';
const CORREO_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

const CTABS = [['demo', 'Agenda una demo'], ['contacto', 'Contacto'], ['cotizacion', 'Solicitar cotización']];
function wContacto(sub) {
  const t = Math.max(0, CTABS.findIndex((x) => x[0] === sub));
  const cuerpo = t === 0 ? '<div class="w-demoa">' + agendaHTML() + '</div>'
    : t === 1 ? '<form class="w-form" id="w-ct" novalidate><div class="w-fg">' + wf('ct-nom', 'Nombre y apellido', 'text', { req: true, ac: 'name' }) + wf('ct-cor', 'Correo', 'email', { req: true, ac: 'email' }) +
      wf('ct-asu', 'Asunto', 'select', { req: true, full: true, ops: ['Consulta comercial', 'Soporte a un cliente', 'Prensa o alianzas', 'Otro'] }) + wf('ct-msg', 'Mensaje', 'textarea', { req: true, full: true, rows: 5 }) + '</div>' +
      consent('ct-ok', 'Acepto que EscudoSST use mis datos para responder, según la <a href="#web-legal-privacidad">Política de privacidad</a>.') + captcha('ct-cap') + '<small class="w-err" id="ct-cap-e" role="alert"></small><button type="submit" class="w-btn pri lg">Enviar mensaje</button></form>'
      : '<form class="w-form" id="w-cot" novalidate><h2 class="w-h3">Tu empresa</h2><div class="w-fg">' + wf('co-nom', 'Nombre y apellido', 'text', { req: true, ac: 'name' }) + wf('co-car', 'Cargo', 'text', { ac: 'organization-title' }) +
      wf('co-emp', 'Empresa', 'text', { req: true, ac: 'organization' }) + wf('co-ruc', 'RUC', 'text', { req: true, im: 'numeric', hint: '11 dígitos' }) + wf('co-cor', 'Correo de trabajo', 'email', { req: true, ac: 'email' }) + wf('co-tel', 'Celular', 'tel', { req: true, ac: 'tel', im: 'tel', hint: '9 dígitos' }) + '</div>' +
      '<h2 class="w-h3">Lo que necesitas</h2><div class="w-fg">' + wf('co-plan', 'Plan de interés', 'select', { req: true, ops: ['Esencial', 'Profesional', 'Corporativo', 'Consultora', 'Aún no lo sé'] }) + wf('co-trab', 'Número de trabajadores', 'select', { req: true, ops: ['Menos de 20', '20 a 200', 'Más de 200'] }) +
      wf('co-sed', 'Número de sedes', 'text', { im: 'numeric' }) + wf('co-per', 'Facturación', 'select', { req: true, ops: ['Mensual', 'Anual'] }) +
      '<fieldset class="w-f full w-radios w-checksq"><legend>Complementos de interés <span class="w-opt">(opcional)</span></legend>' + COMPLEMENTOS.map(([c, n]) => '<label><input type="checkbox" data-co-add="' + c + '"><span>' + esc(n) + '</span></label>').join('') + '</fieldset>' + wf('co-msg', 'Comentarios', 'textarea', { full: true, rows: 3 }) + '</div>' +
      consent('co-ok', 'Acepto que EscudoSST use mis datos para preparar la cotización, según la <a href="#web-legal-privacidad">Política de privacidad</a>.') + '<div class="w-consent"><label for="co-mk"><input type="checkbox" id="co-mk"><span>Quiero recibir novedades y guías por correo (opcional).</span></label></div>' + captcha('co-cap') + '<small class="w-err" id="co-cap-e" role="alert"></small><button type="submit" class="w-btn pri lg">Solicitar cotización</button></form>';
  return '<section class="w-phero"><div class="w-in">' + wMigas([['Contacto']]) + '<h1>' + esc(['Agenda una demo', 'Escríbenos', 'Solicita una cotización'][t]) + '</h1><p class="w-lead">' + esc(['Elige día y hora. Te mostramos EscudoSST con un caso de tu sector.', 'Te respondemos por correo en horario de oficina.', 'Cuéntanos sobre tu empresa y te enviamos una propuesta con el plan adecuado.'][t]) + '</p>' +
    '<div class="w-lrtabs" role="tablist" aria-label="Formularios de contacto">' + CTABS.map(([k, l], i) => '<a role="tab" href="#web-contacto/' + k + '" aria-selected="' + (i === t) + '">' + esc(l) + '</a>').join('') + '</div></div></section>' +
    '<section class="w-sec w-tight"><div class="w-in w-contg">' + cuerpo +
    '<aside class="w-contside"><div class="w-cbox"><h2 class="w-h3">Otras formas de contacto</h2><dl><dt>Correo</dt><dd>' + ajusteTxt(WEB.ajustes.correo, 'Por definir') + '</dd><dt>Teléfono</dt><dd>' + ajusteTxt(WEB.ajustes.telefono, 'Por definir') + '</dd><dt>WhatsApp</dt><dd>' + ajusteTxt(WEB.whatsapp.numero ? '+51 ' + WEB.whatsapp.numero : '', 'Por definir') + '</dd><dt>Horario</dt><dd>' + esc(WEB.ajustes.horario) + '</dd></dl>' + (typeof v15RedesHTML === 'function' ? '<h3 class="w-fh2">Redes sociales</h3>' + v15RedesHTML() : '') + '</div>' +
    '<div class="w-cbox"><h2 class="w-h3">¿Prefieres verlo ya?</h2><p>Recorre la demo interactiva con datos de ejemplo, sin registrarte.</p><button type="button" class="w-btn amb" data-demo-int>Ver demo interactiva</button></div></aside></div></section>';
}
function enviado(root, sel, titulo, txt, id) {
  $(sel, root).outerHTML = '<div class="w-form w-done" tabindex="-1" id="w-done"><span class="w-plate green">' + ic('check', 30) + '</span><h2>' + esc(titulo) + '</h2><p>' + txt + '</p><p class="w-fine">Código ' + id + '. En el prototipo no se envía ningún correo; la solicitud aparece en el panel del Dueño de la plataforma.</p><div class="w-ctas"><a class="w-btn" href="#web">Volver a la portada</a><a class="w-btn pri" href="#web-planes">Ver planes</a></div></div>';
  $('#w-done', root).focus();
}
function bindContacto(root) {
  bindAgenda(root);
  const v = (id) => ($('#' + id, root).value || '').trim();
  const fin = (ok, capId) => { const cap = $('#' + capId, root).classList.contains('ok'); $('#' + capId + '-e', root).textContent = cap ? '' : 'Marca «No soy un robot».'; ok = cap && ok; if (!ok) { const p = $('[aria-invalid="true"], .w-captcha:not(.ok) input', root); if (p) p.focus(); toast('Revisa los campos marcados', 'warn'); } return ok; };
  const ct = $('#w-ct', root);
  if (ct) { bindCaptcha(root, 'ct-cap'); ct.addEventListener('submit', (e) => { e.preventDefault(); let ok = true;
    ok = errorCampo(root, 'ct-nom', v('ct-nom').length < 3 ? 'Escribe tu nombre y apellido.' : '') && ok;
    ok = errorCampo(root, 'ct-cor', !CORREO_RE.test(v('ct-cor')) ? 'Escribe un correo válido.' : '') && ok;
    ok = errorCampo(root, 'ct-asu', !v('ct-asu') ? 'Elige un asunto.' : '') && ok;
    ok = errorCampo(root, 'ct-msg', v('ct-msg').length < 10 ? 'Cuéntanos un poco más.' : '') && ok;
    ok = errorCampo(root, 'ct-ok', !$('#ct-ok', root).checked ? 'Necesitamos tu consentimiento para responderte.' : '') && ok;
    if (!fin(ok, 'ct-cap')) return;
    const id = 'DEM-0' + WEB.sigDemo++; WEB.demos.unshift({ id, f: fmtF(HOY), nombre: v('ct-nom'), empresa: '—', ruc: '—', trab: '—', sector: '—', interes: 'Contacto', estado: 'Nueva', resp: 'Sin asignar', correo: v('ct-cor') });
    enviado(root, '#w-ct', 'Recibimos tu mensaje', 'Te responderemos a <b>' + esc(v('ct-cor')) + '</b>.', id); }); }
  const co = $('#w-cot', root);
  if (co) { bindCaptcha(root, 'co-cap'); co.addEventListener('submit', (e) => { e.preventDefault(); let ok = true;
    ok = errorCampo(root, 'co-nom', v('co-nom').length < 3 ? 'Escribe tu nombre y apellido.' : '') && ok;
    ok = errorCampo(root, 'co-emp', !v('co-emp') ? 'Escribe el nombre de tu empresa.' : '') && ok;
    ok = errorCampo(root, 'co-ruc', !/^\d{11}$/.test(v('co-ruc')) ? 'El RUC tiene 11 dígitos.' : !rucValido(v('co-ruc')) ? 'Este RUC no es válido. Revisa los dígitos.' : '') && ok;
    ok = errorCampo(root, 'co-cor', !CORREO_RE.test(v('co-cor')) ? 'Escribe un correo válido, por ejemplo nombre@empresa.pe.' : '') && ok;
    ok = errorCampo(root, 'co-tel', !/^9\d{8}$/.test(v('co-tel').replace(/\s/g, '')) ? 'Escribe un celular de 9 dígitos que empiece con 9.' : '') && ok;
    ok = errorCampo(root, 'co-plan', !v('co-plan') ? 'Elige un plan o «Aún no lo sé».' : '') && ok;
    ok = errorCampo(root, 'co-trab', !v('co-trab') ? 'Elige un rango.' : '') && ok;
    ok = errorCampo(root, 'co-sed', v('co-sed') && !/^\d{1,3}$/.test(v('co-sed')) ? 'Escribe un número.' : '') && ok;
    ok = errorCampo(root, 'co-per', !v('co-per') ? 'Elige mensual o anual.' : '') && ok;
    ok = errorCampo(root, 'co-ok', !$('#co-ok', root).checked ? 'Necesitamos tu consentimiento para preparar la cotización.' : '') && ok;
    if (!fin(ok, 'co-cap')) return;
    const adds = $$('[data-co-add]:checked', root).map((c) => c.dataset.coAdd);
    const id = 'DEM-0' + WEB.sigDemo++; WEB.demos.unshift({ id, f: fmtF(HOY), nombre: v('co-nom'), empresa: v('co-emp'), ruc: v('co-ruc'), trab: v('co-trab'), sector: '—', interes: 'Cotización · ' + v('co-plan') + (adds.length ? ' + ' + adds.join(', ') : ''), estado: 'Nueva', resp: 'Sin asignar', correo: v('co-cor') });
    enviado(root, '#w-cot', 'Recibimos tu solicitud de cotización', 'Un asesor te enviará la propuesta a <b>' + esc(v('co-cor')) + '</b>.', id); }); }
}

/* ---------- Políticas ---------- */
function wPolitica(id) {
  const p = WEB.politicas.find((x) => x.id === id) || WEB.politicas[0];
  const vig = p.vers.find((v) => v.estado === 'Vigente') || p.vers[0];
  return '<section class="w-phero w-legh"><div class="w-in">' + wMigas([['Legal'], [p.t]]) + '<h1>' + esc(p.t) + '</h1><p class="w-legm"><span>Versión vigente <b>' + esc(vig.v) + '</b></span><span>Desde el <time>' + esc(vig.f) + '</time></span>' + (vig.re ? '<span class="w-chipw">Requiere nueva aceptación</span>' : '') + '<a href="#web-legal-' + p.id + '" data-hist>Historial de versiones</a></p></div></section>' +
    '<section class="w-sec w-tight"><div class="w-in w-legg"><aside class="w-toc w-legtoc" aria-label="Índice"><details open><summary>Contenido</summary><ol>' + p.sec.map((s, i) => '<li><a href="#web-legal-' + p.id + '" data-ancla="s' + i + '">' + esc(s) + '</a></li>').join('') + '</ol></details>' +
    '<nav class="w-legnav" aria-label="Otras políticas"><h2 class="w-fh">Otras políticas</h2>' + WEB.politicas.filter((x) => x.id !== p.id).map((x) => '<a href="#web-legal-' + x.id + '">' + esc(x.t) + '</a>').join('') + '</nav></aside>' +
    '<div class="w-prose w-legal"><div class="w-callout warn"><b>Documento en preparación</b><p>Esta página muestra la estructura del documento. El texto se redacta y valida con un abogado antes de publicarse.</p></div>' +
    (p.id === 'privacidad' ? '<dl class="w-legdl"><dt>Titular</dt><dd>' + esc(WEB.ajustes.razon) + '</dd><dt>RUC</dt><dd>' + esc(WEB.ajustes.ruc) + '</dd><dt>Domicilio</dt><dd>' + ajusteTxt(WEB.ajustes.domicilio, 'Por completar') + '</dd></dl>' : '') +
    p.sec.map((s, i) => '<h2 id="art-s' + i + '"><span class="w-secn">' + (i + 1) + '.</span> ' + esc(s) + '</h2><p class="w-ph">Texto por redactar y validar con abogado.</p>' + (p.id === 'cookies' && i === 4 ? '<div class="w-tw"><table class="w-tbl"><thead><tr><th scope="col">Cookie</th><th scope="col">Categoría</th><th scope="col">Finalidad</th><th scope="col">Duración</th></tr></thead><tbody><tr><td>Sesión</td><td>Necesaria</td><td class="w-pend">Por definir</td><td class="w-pend">Por definir</td></tr><tr><td>Preferencias de cookies</td><td>Necesaria</td><td class="w-pend">Por definir</td><td class="w-pend">Por definir</td></tr><tr><td>Analítica</td><td>Analítica</td><td class="w-pend">Por definir</td><td class="w-pend">Por definir</td></tr></tbody></table></div><button type="button" class="w-btn" id="w-cookiecfg2">Cambiar mis preferencias</button>' : '')).join('') +
    '<h2 id="art-hist">Historial de versiones</h2><div class="w-tw"><table class="w-tbl"><thead><tr><th scope="col">Versión</th><th scope="col">Fecha</th><th scope="col">Estado</th><th scope="col">Cambios</th></tr></thead><tbody>' + p.vers.map((v) => '<tr><td><b>' + esc(v.v) + '</b></td><td>' + esc(v.f) + '</td><td>' + chip(v.estado === 'Vigente' ? 'Vigente' : 'Archivada', v.estado === 'Vigente' ? 'ok' : 'na') + '</td><td>' + esc(v.nota) + (v.re ? ' <span class="w-chipw">Re-aceptación</span>' : '') + '</td></tr>').join('') + '</tbody></table></div></div></div></section>';
}

/* ---------- Libro de Reclamaciones ---------- */
function sumarHabiles(fecha, n) { const d = new Date(fecha); let k = 0; while (k < n) { d.setDate(d.getDate() + 1); if (d.getDay() !== 0 && d.getDay() !== 6) k++; } return d; }
function parseF(s) { const [d, m, y] = s.split(' ')[0].split('/').map(Number); return new Date(y, m - 1, d); }
function habilesHasta(s) { const fin = parseF(s); const d = new Date(HOY.getFullYear(), HOY.getMonth(), HOY.getDate()); let n = 0; const paso = fin >= d ? 1 : -1; while ((paso > 0 && d < fin) || (paso < 0 && d > fin)) { d.setDate(d.getDate() + paso); if (d.getDay() !== 0 && d.getDay() !== 6) n += paso; } return n; }

function wReclamaciones() {
  const a = WEB.ajustes;
  const tab = WUI.lrTab;
  return '<section class="w-phero w-lrh"><div class="w-in w-narrow2 w-lrhead">' + wMigas([['Libro de Reclamaciones']]) + '<div class="w-lrt"><span class="w-lrico">' + LIBRO_IC(44) + '</span><div><h1>Libro de Reclamaciones</h1><p class="w-lead">Conforme al Código de Protección y Defensa del Consumidor. Registra un reclamo o una queja y guarda el código de tu hoja para consultar su estado.</p></div></div>' +
    '<div class="w-lrtabs" role="tablist" aria-label="Libro de Reclamaciones"><button role="tab" type="button" data-lr="0" aria-selected="' + (tab === 0) + '">Registrar una hoja</button><button role="tab" type="button" data-lr="1" aria-selected="' + (tab === 1) + '">Consultar estado</button></div></div></section>' +
    '<section class="w-sec w-tight"><div class="w-in w-narrow2" id="w-lrbody">' + (tab === 0 ? lrForm(a) : lrConsulta()) + '</div></section>';
}
function lrForm(a) {
  const ahora = new Date(); const hora = String(ahora.getHours()).padStart(2, '0') + ':' + String(ahora.getMinutes()).padStart(2, '0');
  return '<form class="w-form w-lr" id="w-lrf" novalidate>' +
    '<div class="w-lrmeta"><div><span>Hoja N.º</span><b class="num">LR-2026-' + String(WEB.sigReclamo).padStart(6, '0') + '</b><small>Se asigna al enviar</small></div><div><span>Fecha</span><b class="num">' + fmtF(HOY) + '</b></div><div><span>Hora</span><b class="num">' + hora + '</b></div></div>' +
    '<fieldset class="w-fs"><legend><span class="w-fsn">1</span>Datos del proveedor</legend><dl class="w-legdl w-prov"><dt>Razón social</dt><dd>' + esc(a.razon) + '</dd><dt>RUC</dt><dd>' + esc(a.ruc) + '</dd><dt>Domicilio</dt><dd>' + ajusteTxt(a.domicilio, 'Por completar') + '</dd><dt>Sitio web</dt><dd>' + ajusteTxt(a.dominio, 'Dominio por definir') + '</dd></dl></fieldset>' +
    '<fieldset class="w-fs"><legend><span class="w-fsn">2</span>Datos del consumidor</legend><div class="w-fg">' +
    wf('lr-nom', 'Nombres', 'text', { req: true, ac: 'given-name' }) + wf('lr-ape', 'Apellidos', 'text', { req: true, ac: 'family-name' }) +
    wf('lr-td', 'Tipo de documento', 'select', { req: true, ops: ['DNI', 'Carné de extranjería', 'Pasaporte', 'RUC'] }) + wf('lr-nd', 'Número de documento', 'text', { req: true, im: 'numeric' }) +
    wf('lr-dom', 'Domicilio', 'text', { req: true, full: true, ac: 'street-address' }) +
    wf('lr-tel', 'Teléfono o celular', 'tel', { req: true, ac: 'tel', im: 'tel' }) + wf('lr-cor', 'Correo electrónico', 'email', { req: true, ac: 'email', hint: 'Te enviaremos aquí la constancia.' }) +
    '<div class="w-f full w-chk"><label for="lr-men"><input type="checkbox" id="lr-men"><span>El consumidor es menor de edad</span></label></div></div>' +
    '<div class="w-apod" id="lr-apod" hidden><h3>Datos del padre, madre o apoderado</h3><div class="w-fg">' + wf('lr-anom', 'Nombres y apellidos', 'text', { req: true }) + wf('lr-and', 'Número de documento', 'text', { req: true, im: 'numeric' }) + wf('lr-acor', 'Correo o teléfono', 'text', { req: true }) + '</div></div></fieldset>' +
    '<fieldset class="w-fs"><legend><span class="w-fsn">3</span>Bien contratado</legend><div class="w-radios w-rbig" role="radiogroup" aria-label="Tipo de bien"><label><input type="radio" name="lr-bien" value="Producto"><span><b>Producto</b><small>Un bien que compraste</small></span></label><label><input type="radio" name="lr-bien" value="Servicio" checked><span><b>Servicio</b><small>Por ejemplo, tu suscripción a EscudoSST</small></span></label></div><div class="w-fg">' +
    wf('lr-monto', 'Monto reclamado (S/)', 'text', { im: 'decimal', ph: '0.00' }) + wf('lr-desc', 'Descripción del producto o servicio', 'text', { req: true, ph: 'Por ejemplo: plan Profesional, suscripción anual' }) + '</div></fieldset>' +
    '<fieldset class="w-fs"><legend><span class="w-fsn">4</span>Detalle de la reclamación</legend><div class="w-radios w-rbig" role="radiogroup" aria-label="Tipo de reclamación"><label><input type="radio" name="lr-tipo" value="Reclamo" checked><span><b>Reclamo</b><small>Disconformidad relacionada con el producto o servicio contratado.</small></span></label><label><input type="radio" name="lr-tipo" value="Queja"><span><b>Queja</b><small>Malestar o descontento con la atención, que no se relaciona con el producto o servicio.</small></span></label></div><div class="w-fg">' +
    wf('lr-det', 'Detalle', 'textarea', { req: true, full: true, rows: 5, ph: 'Cuéntanos qué pasó' }) + wf('lr-ped', 'Pedido del consumidor', 'textarea', { req: true, full: true, rows: 3, ph: '¿Qué solución esperas?' }) + '</div></fieldset>' +
    '<fieldset class="w-fs"><legend><span class="w-fsn">5</span>Aceptación</legend><div class="w-consent"><label for="lr-dec"><input type="checkbox" id="lr-dec" aria-describedby="lr-dec-e"><span>Declaro que los datos consignados son verdaderos. <span class="w-req" aria-hidden="true">*</span></span></label><small class="w-err" id="lr-dec-e" role="alert"></small>' +
    '<label for="lr-pri"><input type="checkbox" id="lr-pri" aria-describedby="lr-pri-e"><span>Acepto el tratamiento de mis datos para atender esta reclamación, según la <a href="#web-legal-privacidad">Política de privacidad</a>. <span class="w-req" aria-hidden="true">*</span></span></label><small class="w-err" id="lr-pri-e" role="alert"></small></div>' +
    '<ul class="w-lrnotes"><li>La formulación del reclamo no impide acudir a otras vías de solución de controversias ni es requisito previo para interponer una denuncia ante el INDECOPI.</li><li>El proveedor responde en un plazo no mayor a ' + param(WEB.ajustes.plazoLibro + ' días hábiles', 'Parámetro con vigencia · referencia, a confirmar por especialista legal') + ', prorrogable cuando la naturaleza del reclamo lo justifique.</li><li>Textos referenciales, a validar con abogado.</li></ul>' +
    captcha('lr-cap') + '<small class="w-err" id="lr-cap-e" role="alert"></small></fieldset>' +
    '<div class="w-lrsend"><button type="submit" class="w-btn pri lg">Enviar hoja de reclamación</button></div></form>';
}
function lrHoja(r) {
  const a = WEB.ajustes;
  return '<div class="w-hoja" id="w-hoja" tabindex="-1"><div class="w-hojah"><span class="w-plate green">' + ic('check', 26) + '</span><div><h2>Tu hoja fue registrada</h2><p>Guarda este código para consultar el estado.</p></div></div>' +
    '<div class="w-hojacod"><span>Código</span><b class="num">' + esc(r.cod) + '</b><span>' + esc(r.f) + '</span></div>' +
    '<div class="w-hojab"><h3>Proveedor</h3><dl class="w-legdl"><dt>Razón social</dt><dd>' + esc(a.razon) + '</dd><dt>RUC</dt><dd>' + esc(a.ruc) + '</dd><dt>Domicilio</dt><dd>' + ajusteTxt(a.domicilio, 'Por completar') + '</dd></dl>' +
    '<h3>Consumidor</h3><dl class="w-legdl"><dt>Nombre</dt><dd>' + esc(r.nombre) + '</dd><dt>Documento</dt><dd>' + esc(r.doc) + '</dd><dt>Correo</dt><dd>' + esc(r.correo) + '</dd>' + (r.apod ? '<dt>Apoderado</dt><dd>' + esc(r.apod) + '</dd>' : '') + '</dl>' +
    '<h3>Reclamación</h3><dl class="w-legdl"><dt>Bien</dt><dd>' + esc(r.bien) + (r.descBien ? ': ' + esc(r.descBien) : '') + '</dd><dt>Monto</dt><dd>' + (r.monto ? 'S/ ' + esc(r.monto) : 'No indica') + '</dd><dt>Tipo</dt><dd>' + esc(r.tipo) + '</dd><dt>Detalle</dt><dd>' + esc(r.det) + '</dd><dt>Pedido</dt><dd>' + esc(r.ped) + '</dd><dt>Respuesta hasta</dt><dd>' + esc(r.vence) + ' ' + param('(' + WEB.ajustes.plazoLibro + ' días hábiles)', 'Parámetro con vigencia · a confirmar') + '</dd></dl></div>' +
    '<div class="w-callout"><b>Constancia enviada</b><p>Enviamos una copia de esta hoja a <b>' + esc(r.correo) + '</b> y al responsable del Libro de Reclamaciones. En el prototipo no se envía ningún correo.</p></div>' +
    '<div class="w-ctas"><button type="button" class="w-btn" id="lr-pdf">' + ic('down', 18) + 'Descargar PDF</button><button type="button" class="w-btn" id="lr-print">Imprimir</button><button type="button" class="w-btn pri" id="lr-ver">Consultar estado</button></div></div>';
}
function lrConsulta(cod) {
  return '<form class="w-form w-lrq" id="w-lrq" novalidate><h2 class="w-h3">Consulta el estado de tu hoja</h2><p>Escribe el código que recibiste, por ejemplo LR-2026-000028.</p><div class="w-qrow">' + wf('lrq-cod', 'Código de la hoja', 'text', { req: true, val: cod || '', ph: 'LR-2026-000000' }) + '<button type="submit" class="w-btn pri lg">Consultar</button></div></form><div id="lrq-res" aria-live="polite"></div>';
}
function lrResultado(r) {
  if (!r) return '<div class="w-emptyb"><h2 class="w-h3">No encontramos esa hoja</h2><p>Revisa que el código tenga el formato LR-2026-000000. Si el problema sigue, escríbenos.</p></div>';
  const cls = r.estado === 'Respondido' ? 'ok' : r.estado === 'Prorrogado' ? 'warn' : 'info';
  return '<div class="w-lrres"><div class="w-lrresh"><div><span class="w-fine">Hoja</span><h2 class="num">' + esc(r.cod) + '</h2></div>' + chip(r.estado, cls) + '</div><dl class="w-legdl"><dt>Tipo</dt><dd>' + esc(r.tipo) + '</dd><dt>Registrada</dt><dd>' + esc(r.f) + '</dd><dt>Respuesta hasta</dt><dd>' + esc(r.vence) + '</dd></dl><h3>Seguimiento</h3><ol class="w-tl">' + r.hist.map(([f, t]) => '<li><time>' + esc(f) + '</time><span>' + esc(t) + '</span></li>').join('') + '</ol></div>';
}
function bindReclamaciones(root) {
  $$('[data-lr]', root).forEach((b) => b.addEventListener('click', () => { WUI.lrTab = +b.dataset.lr; renderWeb(); }));
  const f = $('#w-lrf', root);
  if (f) {
    bindCaptcha(root, 'lr-cap');
    $('#lr-men', root).addEventListener('change', (e) => { $('#lr-apod', root).hidden = !e.target.checked; });
    f.addEventListener('submit', (e) => {
      e.preventDefault();
      const v = (id) => ($('#' + id, root).value || '').trim();
      let ok = true;
      ok = errorCampo(root, 'lr-nom', !v('lr-nom') ? 'Escribe tus nombres.' : '') && ok;
      ok = errorCampo(root, 'lr-ape', !v('lr-ape') ? 'Escribe tus apellidos.' : '') && ok;
      ok = errorCampo(root, 'lr-td', !v('lr-td') ? 'Elige el tipo de documento.' : '') && ok;
      const td = v('lr-td'); const nd = v('lr-nd');
      ok = errorCampo(root, 'lr-nd', !nd ? 'Escribe el número de documento.' : td === 'DNI' && !/^\d{8}$/.test(nd) ? 'El DNI tiene 8 dígitos.' : td === 'RUC' && !rucValido(nd) ? 'Este RUC no es válido.' : '') && ok;
      ok = errorCampo(root, 'lr-dom', !v('lr-dom') ? 'Escribe tu domicilio.' : '') && ok;
      ok = errorCampo(root, 'lr-tel', !/^\d{7,9}$/.test(v('lr-tel').replace(/\s/g, '')) ? 'Escribe un teléfono de 7 a 9 dígitos.' : '') && ok;
      ok = errorCampo(root, 'lr-cor', !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v('lr-cor')) ? 'Escribe un correo válido.' : '') && ok;
      if ($('#lr-men', root).checked) {
        ok = errorCampo(root, 'lr-anom', !v('lr-anom') ? 'Escribe el nombre del apoderado.' : '') && ok;
        ok = errorCampo(root, 'lr-and', !v('lr-and') ? 'Escribe su documento.' : '') && ok;
        ok = errorCampo(root, 'lr-acor', !v('lr-acor') ? 'Escribe un correo o teléfono.' : '') && ok;
      }
      ok = errorCampo(root, 'lr-monto', v('lr-monto') && !/^\d+([.,]\d{1,2})?$/.test(v('lr-monto')) ? 'Escribe un monto como 150.00.' : '') && ok;
      ok = errorCampo(root, 'lr-desc', !v('lr-desc') ? 'Describe el producto o servicio.' : '') && ok;
      ok = errorCampo(root, 'lr-det', v('lr-det').length < 10 ? 'Cuéntanos con más detalle qué pasó.' : '') && ok;
      ok = errorCampo(root, 'lr-ped', !v('lr-ped') ? 'Indica qué solución esperas.' : '') && ok;
      ok = errorCampo(root, 'lr-dec', !$('#lr-dec', root).checked ? 'Marca la declaración para continuar.' : '') && ok;
      ok = errorCampo(root, 'lr-pri', !$('#lr-pri', root).checked ? 'Necesitamos tu consentimiento para atender la hoja.' : '') && ok;
      const cap = $('#lr-cap', root).classList.contains('ok'); $('#lr-cap-e', root).textContent = cap ? '' : 'Marca «No soy un robot».'; ok = cap && ok;
      if (!ok) { const p = $('[aria-invalid="true"], .w-captcha:not(.ok) input', root); if (p) p.focus(); toast('Revisa los campos marcados', 'warn'); return; }
      const ahora = new Date();
      const r = { cod: 'LR-2026-' + String(WEB.sigReclamo++).padStart(6, '0'), f: fmtF(HOY) + ' ' + String(ahora.getHours()).padStart(2, '0') + ':' + String(ahora.getMinutes()).padStart(2, '0'), tipo: $('[name="lr-tipo"]:checked', root).value, nombre: v('lr-nom') + ' ' + v('lr-ape'), doc: (td === 'Carné de extranjería' ? 'CE' : td) + ' ' + nd, correo: v('lr-cor'), bien: $('[name="lr-bien"]:checked', root).value, descBien: v('lr-desc'), monto: v('lr-monto'), det: v('lr-det'), ped: v('lr-ped'), apod: $('#lr-men', root).checked ? v('lr-anom') + ' · ' + v('lr-and') : '', estado: 'Nuevo' };
      r.vence = fmtF(sumarHabiles(HOY, WEB.ajustes.plazoLibro));
      r.hist = [[r.f, 'Hoja registrada y constancia enviada por correo']];
      WEB.reclamos.push(r);
      $('#w-lrbody', root).innerHTML = lrHoja(r);
      $('#w-hoja', root).focus(); window.scrollTo(0, 0);
      $('#lr-pdf', root).onclick = () => toast('PDF de la hoja generado (simulado)');
      $('#lr-print', root).onclick = () => toast('Vista de impresión (simulada)');
      $('#lr-ver', root).onclick = () => { WUI.lrTab = 1; renderWeb(); $('#lrq-cod').value = r.cod; $('#w-lrq').requestSubmit(); };
    });
  }
  const q = $('#w-lrq', root);
  if (q) q.addEventListener('submit', (e) => {
    e.preventDefault();
    const c = $('#lrq-cod', root).value.trim().toUpperCase();
    if (!errorCampo(root, 'lrq-cod', !c ? 'Escribe el código de tu hoja.' : '')) return;
    $('#lrq-res', root).innerHTML = lrResultado(WEB.reclamos.find((r) => r.cod === c));
  });
}

/* ---------- Despachador ---------- */
let WEB_RUTA = ['web', null];
function renderWeb() { const c = $('#content'); if (c) R.__web(c, WEB_RUTA[0], WEB_RUTA[1]); }
R.__web = (root, ruta, sub) => {
  WEB_RUTA = [ruta, sub];
  document.body.classList.add('is-web');
  let html = ''; let clave = ruta; let extra = null; let mount = null;
  if (ruta === 'web') { html = wPortada(); mount = bindAgenda; }
  else if (ruta === 'web-producto') html = wProducto();
  else if (ruta === 'web-planes') html = wPlanes();
  else if (ruta === 'web-sectores') html = wSectores();
  else if (ruta.startsWith('web-sector-') && WEB_SECTORES[ruta.slice(11)]) html = wSector(ruta.slice(11));
  else if (ruta === 'web-consultoras') html = wConsultoras();
  else if (ruta === 'web-contacto') { html = wContacto(sub); mount = bindContacto; }
  else if (ruta.startsWith('web-legal-') && WEB.politicas.some((p) => p.id === ruta.slice(10))) html = wPolitica(ruta.slice(10));
  else if (ruta === 'web-reclamaciones') { html = wReclamaciones(); mount = bindReclamaciones; }
  else if (typeof wxRuta === 'function' && WX_RUTAS.includes(ruta)) { const x = wxRuta(ruta, sub); html = x.html; mount = x.mount || null; extra = x.extra || null; }
  else { html = '<section class="w-phero"><div class="w-in"><h1>No encontramos esta página</h1><p class="w-lead">Puede que el enlace haya cambiado. Si buscabas el blog, ahora está en <a href="' + blogHref() + '" target="_blank" rel="noopener">' + esc(WEB.ajustes.blogUrl) + '</a>.</p><div class="w-ctas"><a class="w-btn pri" href="#web">Ir a la portada</a></div></div></section>'; clave = 'web'; extra = { t: 'Página no encontrada · EscudoSST', d: '' }; }
  if (WEB.paginas[clave] && WEB.paginas[clave].estado === 'Borrador') html = '<div class="w-draft" role="note"><div class="w-in">' + ic('eye', 16) + '<span>Vista previa: esta página está en <b>borrador</b> y no se muestra al público. Publícala desde el panel «Sitio web».</span></div></div>' + html;
  root.innerHTML = '<div class="w" id="w-root">' + wHeader(ruta, sub) + '<main id="w-main" tabindex="-1">' + html + '</main>' + wFooter() + wWa() + wCookies() + '</div>';
  webSeo(clave, extra);
  bindWeb(root, clave);
  if (mount) mount(root);
  ubicarWa();
  if (ruta === 'web' && sub) { const t = $('#ws-' + sub, root); if (t) setTimeout(() => { t.scrollIntoView({ block: 'start' }); }, 0); }
};
function bindWeb(root, clave) {
  const login = () => modal('Iniciar sesión', '<p class="small muted" style="margin-bottom:12px">En el prototipo no hay cuentas reales. Entra a la demostración como la Jefa SST de Industrias Andinas.</p><div class="stack" style="gap:10px">' + campo('lg-u', 'Correo', 'email', 'rocio.villanueva@correo-demo.pe') + campo('lg-p', 'Contraseña', 'password', '••••••••') + '</div>', [{ l: 'Cancelar' }, { l: 'Entrar a la demostración', cls: 'pri', fn: () => { document.body.classList.remove('is-web'); restaurarSeo(); irComo('sst_manager', 'm-dsh'); } }]);
  $$('#w-login, #w-login2', root).forEach((b) => b.addEventListener('click', login));
  $('#w-skip', root).addEventListener('click', (e) => { e.preventDefault(); $('#w-main', root).focus(); });
  const ax = $('#w-annx', root); if (ax) ax.addEventListener('click', () => { WUI.anuncioOff = true; const n = $('.w-ann', root); if (n) n.remove(); });
  const bg = $('#w-burger', root);
  bg.addEventListener('click', () => { WUI.menuMovil = !WUI.menuMovil; const d = $('#w-drawer', root); d.hidden = !WUI.menuMovil; bg.setAttribute('aria-expanded', WUI.menuMovil); bg.setAttribute('aria-label', WUI.menuMovil ? 'Cerrar el menú' : 'Abrir el menú'); bg.innerHTML = ic(WUI.menuMovil ? 'x' : 'menu', 22); if (WUI.menuMovil) { const f = $('#w-drawer a', root); if (f) f.focus(); } });
  $$('#w-drawer a', root).forEach((a) => a.addEventListener('click', () => { WUI.menuMovil = false; }));
  $$('.w-dd a', root).forEach((a) => a.addEventListener('click', () => { if (document.activeElement) document.activeElement.blur(); }));
  $$('[data-ck]', root).forEach((b) => b.addEventListener('click', () => {
    const k = b.dataset.ck;
    if (k === 'cfg') { cookieCfg(); return; }
    WEB.cookies = { n: true, a: k === 'si', m: k === 'si' }; const c = $('.w-cookie', root); if (c) c.remove(); ubicarWa(); toast(k === 'si' ? 'Aceptaste todas las cookies' : 'Solo usaremos cookies necesarias');
  }));
  $$('#w-cookiecfg, #w-cookiecfg2', root).forEach((b) => b.addEventListener('click', cookieCfg));
  $('#w-wa', root).addEventListener('click', () => abrirWa(clave));
  $$('[data-video]', root).forEach((b) => b.addEventListener('click', () => abrirVideo(b.dataset.video)));
  $$('[data-vdemo]', root).forEach((b) => b.addEventListener('click', () => { const bl = BLOQUES[+b.dataset.vdemo]; document.body.classList.remove('is-web'); restaurarSeo(); irComo(bl.demo[0], bl.demo[1]); toast('Demo: ' + bl.corto); }));
  $$('[data-demo-int]', root).forEach((b) => b.addEventListener('click', demoInteractiva));
  $$('[data-per]', root).forEach((b) => b.addEventListener('click', () => { WUI.periodo = b.dataset.per; const y = window.scrollY; renderWeb(); window.scrollTo(0, y); const n = $('[data-per="' + WUI.periodo + '"]'); if (n) n.focus({ preventScroll: true }); }));
  $$('[data-cg]', root).forEach((b) => b.addEventListener('click', () => { const g = b.dataset.cg; WUI.grupoAbierto[g] = !WUI.grupoAbierto[g]; const w = b.parentElement; w.classList.toggle('open', WUI.grupoAbierto[g]); $('.w-cgb', w).hidden = !WUI.grupoAbierto[g]; b.setAttribute('aria-expanded', WUI.grupoAbierto[g]); const all = $('#w-cg-all', root); if (all) all.textContent = Object.values(WUI.grupoAbierto).filter(Boolean).length === 8 ? 'Plegar todos los grupos' : 'Desplegar todos los grupos'; }));
  const all = $('#w-cg-all', root);
  if (all) all.addEventListener('click', () => { const abrir = Object.values(WUI.grupoAbierto).filter(Boolean).length < 8; Object.keys(CAT.grupos).forEach((g) => { WUI.grupoAbierto[g] = abrir; }); const y = window.scrollY; renderWeb(); window.scrollTo(0, y); });
  $$('[data-ancla]', root).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); const t = $('#art-' + a.dataset.ancla, root); if (t) { t.scrollIntoView({ block: 'start' }); t.setAttribute('tabindex', '-1'); t.focus({ preventScroll: true }); } }));
  $$('[data-hist]', root).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); const t = $('#art-hist', root); if (t) t.scrollIntoView({ block: 'start' }); }));
}
