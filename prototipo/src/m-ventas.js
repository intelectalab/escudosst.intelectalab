/* Venta de cursos y pagos (VTA), catálogo público con carrito y pago simulado, portal del participante externo.
   Los precios de los cursos los define cada empresa cliente: no son precios de EscudoSST. */

const sol = (n) => n === 0 ? 'Gratis' : 'S/ ' + Math.floor(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ',' + String(Math.round((n % 1) * 100)).padStart(2, '0');
const OFERTAS = [
  { id: 'QH-101', precio: 350, promo: null, modal: 'Virtual autoguiado', vend: 64, estado: 'Publicado', desc: 'Forma al Supervisor SST que exige la ley en empresas con menos de 20 trabajadores: sistema de gestión, IPERC, inspecciones e investigación de accidentes.', aprende: ['Elaborar y actualizar la matriz IPERC', 'Planificar inspecciones y el programa anual', 'Investigar incidentes con árbol de causas'], cat: 'Gestión SST' },
  { id: 'QH-104', precio: 120, promo: 99, modal: 'Virtual autoguiado + práctica', vend: 118, estado: 'Publicado', desc: 'Prevención de caídas: sistemas de detención, puntos de anclaje, inspección del arnés y rescate.', aprende: ['Inspeccionar arnés y línea de vida', 'Elegir el punto de anclaje correcto', 'Plan de rescate básico'], cat: 'Alto riesgo' },
  { id: 'QH-107', precio: 130, promo: null, modal: 'Virtual autoguiado', vend: 41, estado: 'Publicado', desc: 'Ingreso seguro a espacios confinados: medición de gases, permisos, vigía y rescate.', aprende: ['Medir la atmósfera antes de ingresar', 'Roles de vigía y supervisor', 'Llenado del permiso de ingreso'], cat: 'Alto riesgo' },
  { id: 'QH-110', precio: 95, promo: null, modal: 'Virtual autoguiado', vend: 80, estado: 'Publicado', desc: 'Identifica peligros, evalúa riesgos y define controles con la jerarquía de controles, con ejemplos reales.', aprende: ['Diferenciar peligro y riesgo', 'Calcular el nivel de riesgo', 'Proponer controles eficaces'], cat: 'Gestión SST' },
  { id: 'QH-112', precio: 60, promo: null, modal: 'Virtual autoguiado', vend: 66, estado: 'Pausado', desc: 'Atención inicial de heridas, quemaduras, fracturas y RCP básica.', aprende: ['Evaluar la escena', 'RCP básica', 'Inmovilizaciones'], cat: 'Emergencias' },
  { id: 'QH-115', precio: 0, promo: null, modal: 'Virtual autoguiado', vend: 0, estado: 'Borrador', desc: 'Funciones del Comité SST y proceso de elección.', aprende: ['Funciones del comité', 'Elección de representantes'], cat: 'Gestión SST' },
];
const OFERTAS_AND = [{ id: 'CUR-014', precio: 150, promo: null, modal: 'Virtual autoguiado', vend: 0, estado: 'Borrador', desc: 'Para personal de contratistas que trabaja en la planta.', aprende: ['Peligros y controles', 'Permiso de trabajo'], cat: 'Alto riesgo' }];
const ofertasEmp = () => S.empresa === 'qhaliy' ? OFERTAS : OFERTAS_AND;
const ofBy = (id) => OFERTAS.find((o) => o.id === id);
const precioOf = (o) => o.promo != null ? o.promo : o.precio;
const CUPONES = [['EMPRESA10', '10 %', 'Empresas', '12 usos', 'Vigente'], ['CYBERSST', '20 %', 'Todos', '41 usos', 'Vencido'], ['COMITE2026', 'S/ 30,00', 'Comité SST: funciones', '0 usos', 'Programado']];
const PEDIDOS = [
  { id: 'PED-2026-0412', cli: 'Luz Marina Quispe Ortiz', tipo: 'Persona', items: [['QH-104', 1]], total: 99, medio: 'Yape (vía pasarela)', est: 'Pagado', f: '29/10/2026 20:14', comp: 'B001-000231' },
  { id: 'PED-2026-0411', cli: 'Minera Cerro Azul S.A.C.', tipo: 'Empresa', items: [['QH-107', 15]], total: 1950, medio: 'Tarjeta', est: 'Pagado', f: '29/10/2026 11:02', comp: 'F001-000087' },
  { id: 'PED-2026-0410', cli: 'Jhonatan Rivera Salas', tipo: 'Persona', items: [['QH-101', 1]], total: 350, medio: 'PagoEfectivo', est: 'Pendiente de pago', f: '28/10/2026 22:40', comp: '—' },
  { id: 'PED-2026-0409', cli: 'Marisol Cáceres Luna', tipo: 'Persona', items: [['QH-110', 1]], total: 95, medio: 'Tarjeta', est: 'Rechazado', f: '28/10/2026 09:15', comp: '—' },
  { id: 'PED-2026-0408', cli: 'Transportes Mantaro S.A.C.', tipo: 'Empresa', items: [['QH-104', 10]], total: 990, medio: 'Tarjeta', est: 'Pagado', f: '27/10/2026 16:30', comp: 'F001-000086' },
  { id: 'PED-2026-0405', cli: 'Pedro Alarcón Vega', tipo: 'Persona', items: [['QH-112', 1]], total: 60, medio: 'PagoEfectivo', est: 'Expirado', f: '24/10/2026 19:05', comp: '—' },
  { id: 'PED-2026-0401', cli: 'Rosa Huertas Pinedo', tipo: 'Persona', items: [['QH-101', 1]], total: 315, medio: 'Tarjeta', est: 'Reembolsado', f: '20/10/2026 10:48', comp: 'B001-000219' },
  { id: 'PED-2026-0377', cli: 'Luz Marina Quispe Ortiz', tipo: 'Persona', items: [['QH-110', 1]], total: 95, medio: 'Tarjeta', est: 'Pagado', f: '02/09/2026 21:10', comp: 'B001-000188' },
];
const PED_CLS = { Pagado: 'ok', 'Pendiente de pago': 'warn', Rechazado: 'bad', Expirado: 'na', Reembolsado: 'na' };
const CUPOS = [
  { emp: 'Minera Cerro Azul S.A.C.', ruc: '20605512340', curso: 'QH-107', comp: 15, asig: ['Carlos Mendoza Ríos', 'Andrea Salinas Poma', 'Diego Paredes Inga', 'Jorge Lazo Pérez', 'Ana Ruiz Tello', 'Iván Ccopa Mayta', 'Nora Vílchez Paz', 'Saúl Tito Quispe', 'Elsa Poma Rivas', 'Raúl Inca Soto', 'Mery Luna Arce'], vence: '31/03/2027' },
  { emp: 'Transportes Mantaro S.A.C.', ruc: '20610325891', curso: 'QH-104', comp: 10, asig: ['Víctor Huamán Torres', 'Miguel Ángel Soto Luna', 'Julio Vega Ríos', 'Pilar Mamani Coa', 'Aldo Ríos Lara', 'Gina Paz Torres', 'Hugo Lima Soto', 'Rita Coa Vera', 'Omar Ticona Luna', 'Edith Ramos Paz'], vence: '27/01/2027' },
  { emp: 'Panificadora Don Lucho E.I.R.L.', ruc: '20554418721', curso: 'QH-112', comp: 5, asig: ['Marco Antonio Lira Peña', 'Lucía Paz Quispe'], vence: '30/06/2027' },
];
const COMPROBANTES = [
  ['B001-000231', 'Boleta', 'Luz Marina Quispe Ortiz', 'DNI 45781236', 'PED-2026-0412', 99, '29/10/2026', 'Aceptado'],
  ['F001-000087', 'Factura', 'Minera Cerro Azul S.A.C.', 'RUC 20605512340', 'PED-2026-0411', 1950, '29/10/2026', 'Aceptado'],
  ['F001-000086', 'Factura', 'Transportes Mantaro S.A.C.', 'RUC 20610325891', 'PED-2026-0408', 990, '27/10/2026', 'Pendiente'],
  ['B001-000219', 'Boleta', 'Rosa Huertas Pinedo', 'DNI 46102983', 'PED-2026-0401', 315, '20/10/2026', 'Anulado'],
  ['B001-000214', 'Boleta', 'Fiorella Campos Vera', 'DNI 72983014', 'PED-2026-0396', 120, '18/10/2026', 'Rechazado'],
];
const PASARELA = { prov: 'Culqi', prueba: true, medios: { tarjeta: true, yape: true, pe: true } };

/* ---------- VTA: pantalla del módulo ---------- */
R.VTA = (root) => {
  let t = 0;
  const emp = empresaActual();
  const ok = !soloLectura();
  root.innerHTML = encMod('VTA', 'Vende los cursos del aula a personas y empresas. Los precios los define ' + esc(emp.corto) + '; el pago entra por la pasarela y la matrícula es automática.', '<a class="btn" href="#pub-cursos">' + ic('eye', 16) + 'Ver catálogo público</a><a class="btn" href="#aul-recomendaciones">' + ic('spark', 16) + 'Recomendaciones</a>' + btnExport()) +
    (PASARELA.prueba ? '<div class="note warn" style="margin-bottom:14px">' + ic('warn') + '<div><b>Pasarela en modo prueba</b> (<span id="vta-prov">' + PASARELA.prov + '</span>): los pagos son de prueba y no se cobra dinero real.</div></div>' : '') +
    '<div class="grid g4" style="margin-bottom:16px">' + kpi('Ventas de octubre', '<span style="color:var(--ok)">' + sol(9870) + '</span>', 'Pagos confirmados') + kpi('Pedidos pagados', '<span style="color:var(--brand)">58</span>', 'Este mes') + kpi('Pendientes de pago', '<span style="color:var(--amber-ink)">' + PEDIDOS.filter((p) => p.est === 'Pendiente de pago').length + '</span>', 'PagoEfectivo por pagar') + kpi('Cupos de empresas', '<span style="color:var(--teal)">' + CUPOS.reduce((a, c) => a + c.comp, 0) + '</span>', CUPOS.reduce((a, c) => a + c.asig.length, 0) + ' asignados') + '</div>' +
    tabs('vta', ['Cursos a la venta', 'Pedidos', 'Cupos para empresas', 'Pasarela', 'Comprobantes', 'Reportes de ventas'], 0) + '<div id="vta-b"></div>';
  const b = $('#vta-b', root);
  const pint = () => {
    if (t === 0) {
      const of = ofertasEmp();
      b.innerHTML = (ok ? '<div class="row" style="justify-content:flex-end;margin-bottom:12px"><button class="btn pri" id="of-n">' + ic('plus', 16) + 'Poner un curso a la venta</button></div>' : '') + '<div id="of-l"></div><h3 style="margin:18px 0 10px">Cupones</h3>' + tabla([{ l: 'Código', f: (r) => '<span class="mono">' + r[0] + '</span>' }, { l: 'Descuento', k: 1 }, { l: 'Aplica a', k: 2 }, { l: 'Usos', k: 3 }, { l: 'Estado', f: (r) => chip(r[4]) }], CUPONES);
      pantallaLista($('#of-l', b), of.map((o) => [cursoBy(o.id).n, o.precio, o.promo, o.modal, o.vend, o.estado, o.id]), [{ l: 'Curso', f: (r) => '<b>' + esc(r[0]) + '</b><div class="tiny muted">' + r[6] + ' · ' + cursoBy(r[6]).horas + ' h</div>' }, { l: 'Precio', f: (r) => r[2] != null ? '<s class="muted small">' + sol(r[1]) + '</s> <b>' + sol(r[2]) + '</b>' : '<b>' + sol(r[1]) + '</b>' }, { l: 'Modalidad', k: 3 }, { l: 'Vendidos', k: 4 }, { l: 'Estado', f: (r) => chip(r[5], r[5] === 'Pausado' ? 'warn' : null) }], [[5, 'Estado']], (r) => {
        const o = of.find((x) => x.id === r[6]);
        cajon(r[0], 'Ficha de venta · ' + o.id, dl([['Precio', '<b>' + sol(o.precio) + '</b>'], ['Precio promocional', o.promo != null ? sol(o.promo) : '—'], ['Modalidad', esc(o.modal)], ['Vendidos', String(o.vend)], ['Estado', chip(o.estado)], ['Matrícula', 'Automática al confirmarse el pago'], ['Certificado', cursoBy(o.id).verif ? 'Verificable por código y QR' : 'No verificable']]) + '<p class="small">' + esc(o.desc) + '</p>' + nota('El precio lo define la empresa. Puedes venderlo a personas o como cupos para empresas.'),
          ok ? [{ l: 'Cerrar' }, { l: o.estado === 'Publicado' ? 'Pausar venta' : 'Publicar', fn: () => { o.estado = o.estado === 'Publicado' ? 'Pausado' : 'Publicado'; toast('Curso ' + (o.estado === 'Publicado' ? 'publicado en el catálogo' : 'pausado')); pint(); } }, { l: 'Editar precio', cls: 'pri', fn: () => { setTimeout(() => modal('Precio de «' + r[0] + '»', '<div class="grid g2">' + campo('op-p', 'Precio (S/)', 'number', String(o.precio)) + campo('op-pr', 'Precio promocional (S/)', 'number', o.promo != null ? String(o.promo) : '') + '</div><p class="tiny muted">Incluye IGV. Lo verán los compradores en el catálogo.</p>', [{ l: 'Cancelar' }, { l: 'Guardar', cls: 'pri', fn: (ov) => { o.precio = Math.max(0, +$('#op-p', ov).value || 0); const pr = $('#op-pr', ov).value; o.promo = pr === '' ? null : +pr; toast('Precio actualizado'); pint(); } }]), 30); } }] : [{ l: 'Cerrar' }]);
      });
      const n = $('#of-n', b); if (n) n.onclick = () => { const disp = cursosEmp().filter((c) => !of.some((o) => o.id === c.id)); if (!disp.length) { toast('Todos los cursos del aula ya están a la venta', 'warn'); return; } modal('Poner un curso a la venta', campo('on-c', 'Curso del aula', 'select', disp[0].n, disp.map((c) => c.n)) + '<div class="grid g2">' + campo('on-p', 'Precio (S/)', 'number', '80') + campo('on-m', 'Modalidad', 'select', 'Virtual autoguiado', ['Virtual autoguiado', 'Virtual en vivo', 'Virtual + práctica presencial']) + '</div>', [{ l: 'Cancelar' }, { l: 'Guardar como borrador', cls: 'pri', fn: (ov) => { const c = disp.find((x) => x.n === $('#on-c', ov).value); of.push({ id: c.id, precio: +$('#on-p', ov).value || 0, promo: null, modal: $('#on-m', ov).value, vend: 0, estado: 'Borrador', desc: 'Curso de ' + c.n + '.', aprende: [], cat: c.cat }); toast('Curso agregado a la venta como borrador'); pint(); } }]); };
    } else if (t === 1) {
      const lst = S.empresa === 'qhaliy' ? PEDIDOS : [];
      if (!lst.length) { b.innerHTML = '<div class="card">' + vacio('Aún no hay pedidos', 'Los pedidos aparecen cuando alguien compra en el catálogo público.') + '</div>'; return; }
      pantallaLista(b, lst.map((p) => [p.id, p.cli, p.tipo, p.items.map((i) => cursoBy(i[0]).n + (i[1] > 1 ? ' × ' + i[1] + ' cupos' : '')).join(', '), p.total, p.medio, p.est, p.f]), [{ l: 'Pedido', f: (r) => '<span class="mono">' + r[0] + '</span>' }, { l: 'Comprador', f: (r) => '<b>' + esc(r[1]) + '</b><div class="tiny muted">' + r[2] + '</div>' }, { l: 'Cursos', k: 3 }, { l: 'Total', f: (r) => '<b class="num">' + sol(r[4]) + '</b>' }, { l: 'Medio de pago', k: 5 }, { l: 'Estado', f: (r) => chip(r[6], PED_CLS[r[6]]) }, { l: 'Fecha', k: 7 }], [[6, 'Estado'], [5, 'Medio de pago'], [2, 'Tipo']], (r) => fichaPedido(PEDIDOS.find((p) => p.id === r[0]), pint));
    } else if (t === 2) {
      const lst = S.empresa === 'qhaliy' ? CUPOS : [];
      b.innerHTML = nota('Las empresas compran cupos (licencias) y los asignan a sus trabajadores. Cada asignación matricula al trabajador en el curso.', '', 'building') + '<div style="height:12px"></div>' + (lst.length ? tabla([{ l: 'Empresa compradora', f: (c) => '<b>' + esc(c.emp) + '</b><div class="tiny muted">RUC ' + c.ruc + '</div>' }, { l: 'Curso', f: (c) => esc(cursoBy(c.curso).n) }, { l: 'Comprados', f: (c) => String(c.comp) }, { l: 'Usados', f: (c) => '<div style="min-width:120px"><div class="row between small"><span>' + c.asig.length + ' de ' + c.comp + '</span></div>' + barra(Math.round(c.asig.length * 100 / c.comp), c.asig.length >= c.comp ? 'warn' : 'ok') + '</div>' }, { l: 'Vencen', f: (c) => c.vence }, { l: 'Estado', f: (c) => chip(c.asig.length >= c.comp ? 'Agotado' : 'Vigente', c.asig.length >= c.comp ? 'warn' : 'ok') }], lst, { click: 1 }) : '<div class="card">' + vacio('Sin cupos vendidos', 'Cuando una empresa compre cupos aparecerán aquí.') + '</div>');
      bindTabla(b, lst, (c) => fichaCupos(c, pint));
    } else if (t === 3) {
      const provs = [['Culqi', 'Tarjetas, Yape y billeteras'], ['Niubiz', 'Tarjetas y QR'], ['Izipay', 'Tarjetas y QR'], ['Mercado Pago', 'Tarjetas y saldo']];
      b.innerHTML = '<div class="grid g-main" style="align-items:start"><div class="stack"><div class="card stack" style="gap:12px"><div class="ch" style="margin:0"><h3>Proveedor de pagos</h3>' + chip('Por definir', 'warn') + '</div><p class="small muted">EscudoSST trabaja con una interfaz común; la empresa elige uno. En la demostración puedes cambiarlo.</p><div class="grid g2">' +
        provs.map((p) => '<button class="prov ' + (PASARELA.prov === p[0] ? 'sel' : '') + '" data-prov="' + p[0] + '"' + (ok ? '' : ' disabled') + '><b>' + p[0] + '</b><span class="small muted">' + p[1] + '</span>' + (PASARELA.prov === p[0] ? chip('Elegido', 'ok') : '') + '</button>').join('') + '</div></div>' +
        '<div class="card stack" style="gap:12px"><h3>Credenciales · ' + PASARELA.prov + '</h3>' + sw('pg-test', PASARELA.prueba, 'Modo prueba', 'Usa las llaves de prueba: no se cobra dinero real', !ok) +
        dl([['Llave pública', '<span class="mono">' + (PASARELA.prueba ? 'pk_test' : 'pk_live') + '_••••••••••3f9a</span>'], ['Llave secreta', 'Guardada como secreto <span class="tiny muted mono">(ref. vault://qhaliy/pagos/secret)</span>'], ['Secreto del webhook', 'Guardado como secreto · se usa para verificar la firma']]) +
        '<div class="field"><span>URL del webhook (pégala en el panel de ' + PASARELA.prov + ')</span><div class="linkbox"><code id="wh">https://api.escudosst.com/webhooks/pagos/' + PASARELA.prov.toLowerCase().replace(' ', '-') + '/qhaliy</code><button class="btn sm" id="wh-c">Copiar</button></div></div>' +
        (ok ? '<div class="row"><button class="btn" id="pg-k">Reemplazar llaves</button><button class="btn" id="pg-p">Probar conexión</button><button class="btn pri" id="pg-g">Guardar</button></div>' : '') + '</div></div>' +
        '<div class="stack"><div class="card stack" style="gap:10px"><h3>Medios de pago</h3>' + sw('pm-t', PASARELA.medios.tarjeta, 'Tarjeta de crédito o débito', 'Formulario seguro de la pasarela', !ok) + sw('pm-y', PASARELA.medios.yape, 'Yape (vía pasarela)', 'Celular y código de aprobación', !ok) + sw('pm-e', PASARELA.medios.pe, 'PagoEfectivo', 'Código CIP para banca móvil o agentes', !ok) + '</div>' +
        nota('<b>Seguridad de pagos.</b> EscudoSST nunca guarda datos de tarjeta: solo el token y la referencia de la pasarela. Cada webhook se verifica por firma y se procesa una sola vez (idempotente por id del evento).', '', 'shield') + '</div></div>' +
        '<h3 style="margin:18px 0 10px">Últimos eventos del webhook</h3>' + tabla([{ l: 'Evento', f: (r) => '<span class="mono">' + r[0] + '</span>' }, { l: 'Pedido', k: 1 }, { l: 'Recibido', k: 2 }, { l: 'Firma', f: (r) => chip(r[3], r[3] === 'Verificada' ? 'ok' : 'bad') }, { l: 'Resultado', f: (r) => chip(r[4], r[4] === 'Procesado' ? 'ok' : r[4] === 'Rechazado' ? 'bad' : 'na') }], [['charge.succeeded', 'PED-2026-0412', '29/10/2026 20:14:08', 'Verificada', 'Procesado'], ['charge.succeeded', 'PED-2026-0412', '29/10/2026 20:14:39', 'Verificada', 'Duplicado ignorado'], ['charge.succeeded', 'PED-2026-0411', '29/10/2026 11:02:51', 'Verificada', 'Procesado'], ['charge.failed', 'PED-2026-0409', '28/10/2026 09:15:12', 'Verificada', 'Procesado'], ['charge.succeeded', '—', '27/10/2026 03:12:40', 'Inválida', 'Rechazado']]);
      $$('[data-prov]', b).forEach((x) => x.onclick = () => { PASARELA.prov = x.dataset.prov; const vp = $('#vta-prov', root); if (vp) vp.textContent = PASARELA.prov; toast('Proveedor elegido: ' + PASARELA.prov + ' (modo prueba)'); pint(); });
      $('#wh-c', b).onclick = () => { try { navigator.clipboard.writeText($('#wh', b).textContent); } catch (e) { /* sin portapapeles */ } toast('URL del webhook copiada'); };
      const tst = $('#pg-test', b); if (tst) tst.onchange = (e) => { PASARELA.prueba = e.target.checked; toast(PASARELA.prueba ? 'Modo prueba activado' : 'Modo producción: los pagos serán reales'); };
      [['pm-t', 'tarjeta'], ['pm-y', 'yape'], ['pm-e', 'pe']].forEach(([id, k]) => { $('#' + id, b).onchange = (e) => { PASARELA.medios[k] = e.target.checked; }; });
      const pk = $('#pg-k', b); if (pk) pk.onclick = () => modal('Reemplazar llaves de ' + PASARELA.prov, campo('pk-p', 'Llave pública', 'text', '') + campo('pk-s', 'Llave secreta', 'password', '') + '<p class="tiny muted">La llave secreta se envía directo al gestor de secretos; nadie podrá volver a verla, ni el equipo de EscudoSST.</p>', [{ l: 'Cancelar' }, { l: 'Guardar como secreto', cls: 'pri', fn: () => toast('Llaves guardadas como secreto') }]);
      const pp = $('#pg-p', b); if (pp) pp.onclick = () => toast('Conexión correcta con ' + PASARELA.prov + ' (modo prueba)');
      const pg = $('#pg-g', b); if (pg) pg.onclick = () => toast('Configuración de la pasarela guardada');
      $('#pg-test', b);
    } else if (t === 4) {
      const lst = S.empresa === 'qhaliy' ? COMPROBANTES : [];
      b.innerHTML = '<div class="note warn" style="margin-bottom:12px">' + ic('info') + '<div><b>Proveedor de facturación electrónica: por definir.</b> El estado ante SUNAT que ves aquí es simulado.</div></div><div id="cb-l"></div>';
      pantallaLista($('#cb-l', b), lst, [{ l: 'Comprobante', f: (r) => '<span class="mono">' + r[0] + '</span>' }, { l: 'Tipo', k: 1 }, { l: 'Cliente', f: (r) => '<b>' + esc(r[2]) + '</b><div class="tiny muted">' + r[3] + '</div>' }, { l: 'Pedido', k: 4 }, { l: 'Importe', f: (r) => '<b class="num">' + sol(r[5]) + '</b>' }, { l: 'Emitido', k: 6 }, { l: 'Estado SUNAT', f: (r) => chip(r[7]) }], [[1, 'Tipo'], [7, 'Estado SUNAT']], (r) => cajon(r[1] + ' electrónica ' + r[0], esc(r[2]), '<div class="paper"><div class="ptit">' + esc(empresaActual().nombre).toUpperCase() + '<br>RUC ' + empresaActual().ruc + '<br>' + r[1].toUpperCase() + ' DE VENTA ELECTRÓNICA ' + r[0] + '</div><p>Cliente: ' + esc(r[2]) + ' · ' + r[3] + '<br>Fecha: ' + r[6] + ' · Pedido ' + r[4] + '</p><table><thead><tr><th>Descripción</th><th>Importe</th></tr></thead><tbody><tr><td>' + esc((PEDIDOS.find((p) => p.id === r[4]) || { items: [['QH-104', 1]] }).items.map((i) => cursoBy(i[0]).n).join(', ')) + '</td><td>' + sol(r[5]) + '</td></tr><tr><td><b>Total (IGV incluido)</b></td><td><b>' + sol(r[5]) + '</b></td></tr></tbody></table><p style="margin-top:8px">Representación impresa simulada.</p></div>' + dl([['Estado SUNAT', chip(r[7])], ['Proveedor', 'Por definir']]), [{ l: 'Cerrar' }, { l: 'Descargar PDF y XML', cls: 'pri', fn: () => toast('PDF y XML descargados (simulado)') }]));
    } else {
      b.innerHTML = '<div class="grid g4" style="margin-bottom:14px">' + kpi('Ventas del año', '<span style="color:var(--ok)">' + sol(71240) + '</span>') + kpi('Ticket promedio', '<span style="color:var(--brand)">' + sol(170.2) + '</span>') + kpi('Del carrito al pago', '<span style="color:var(--teal)">62%</span>', 'Carritos que terminan en pago') + kpi('Ventas a empresas', '<span style="color:var(--orange)">38%</span>', 'Por cupos') + '</div>' +
        '<div class="grid g2"><div class="card"><h3>Ventas por mes (miles de S/)</h3>' + lineChart([{ n: 'Ventas 2026', c: 'var(--ok)', v: [4.1, 5.2, 6.8, 6.1, 7.4, 7.9, 6.6, 8.3, 8.9, 9.9], area: 1 }], ['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O'], { aria: 'Ventas por mes', fmt: (v) => dec1(v) }) + '</div>' +
        '<div class="card"><h3>Medios de pago</h3><div class="stack" style="margin-top:12px">' + [['Tarjeta', 54, 'var(--brand)'], ['Yape (vía pasarela)', 31, 'var(--teal)'], ['PagoEfectivo', 15, 'var(--orange)']].map((x) => '<div><div class="row between small"><span>' + x[0] + '</span><b class="num">' + x[1] + '%</b></div><div class="bar"><i style="width:' + x[1] + '%;background:' + x[2] + '"></i></div></div>').join('') + '</div></div></div><h3 style="margin:18px 0 10px">Cursos más vendidos</h3>' +
        tabla([{ l: 'Curso', f: (o) => '<b>' + esc(cursoBy(o.id).n) + '</b>' }, { l: 'Vendidos', f: (o) => String(o.vend) }, { l: 'Ingresos', f: (o) => '<b class="num">' + sol(o.vend * precioOf(o)) + '</b>' }, { l: 'Aprobados', f: (o) => cursoBy(o.id).mat ? Math.round(cursoBy(o.id).aprob * 100 / cursoBy(o.id).mat) + '%' : '—' }], OFERTAS.filter((o) => o.vend).sort((a, z) => z.vend - a.vend));
    }
  };
  bindTabs(root, 'vta', (i) => { t = i; $$('[data-tabs="vta"] button', root).forEach((x, j) => { x.classList.toggle('on', i === j); x.setAttribute('aria-selected', i === j); }); pint(); });
  bindExport(root);
  pint();
};
function fichaPedido(p, done) {
  const pasos = { Pagado: ['Pedido creado', 'Pago confirmado por webhook (firma verificada)', 'Matrícula automática', 'Comprobante emitido ' + p.comp], 'Pendiente de pago': ['Pedido creado', 'Código CIP generado · esperando el pago'], Rechazado: ['Pedido creado', 'Pago rechazado por el banco emisor'], Expirado: ['Pedido creado', 'Código CIP vencido sin pago'], Reembolsado: ['Pedido creado', 'Pago confirmado', 'Matrícula automática', 'Reembolso a solicitud del cliente · matrícula retirada'] }[p.est];
  cajon(p.id, esc(p.cli) + ' · ' + p.tipo, tabla([{ l: 'Curso', f: (i) => esc(cursoBy(i[0]).n) }, { l: 'Cantidad', f: (i) => i[1] > 1 ? i[1] + ' cupos' : '1' }, { l: 'Importe', f: (i) => sol(p.total) }], p.items, { noResp: 1 }) +
    dl([['Total', '<b>' + sol(p.total) + '</b>'], ['Medio de pago', esc(p.medio)], ['Estado', chip(p.est, PED_CLS[p.est])], ['Referencia de la pasarela', '<span class="mono">' + (p.est === 'Pendiente de pago' ? 'CIP 4829 1735' : 'chr_test_••••' + p.id.slice(-4)) + '</span>'], ['Comprobante', p.comp]]) +
    '<div class="timeline">' + pasos.map((x, i) => '<div class="ev">' + esc(x) + '<span>' + p.f.split(' ')[0] + ' ' + ['', '', '', ''][i] + '</span></div>').join('') + '</div>' + nota('No se guardan datos de tarjeta: solo el token y la referencia de la pasarela.', '', 'shield'),
  p.est === 'Pendiente de pago' ? [{ l: 'Cerrar' }, { l: '(Demostración) Simular pago recibido', cls: 'pri', fn: () => { p.est = 'Pagado'; p.comp = 'B001-000233'; toast('Pago recibido: matrícula automática y boleta emitida'); done(); } }] : p.est === 'Pagado' ? [{ l: 'Cerrar' }, { l: 'Reembolsar', cls: 'danger', fn: () => { p.est = 'Reembolsado'; toast('Reembolso registrado; se retiró la matrícula'); done(); } }] : [{ l: 'Cerrar' }]);
}
function fichaCupos(c, done) {
  const libres = c.comp - c.asig.length;
  cajon(c.emp, esc(cursoBy(c.curso).n) + ' · ' + c.asig.length + ' de ' + c.comp + ' cupos usados', barra(Math.round(c.asig.length * 100 / c.comp)) + '<div class="list">' + c.asig.map((n, i) => '<div class="li"><span class="av" style="width:30px;height:30px;font-size:.72rem">' + iniciales(n) + '</span><div class="lm"><b>' + esc(n) + '</b><span>' + (i % 3 === 0 ? 'Aprobado' : i % 3 === 1 ? 'En curso' : 'Sin iniciar') + '</span></div></div>').join('') + '</div>' + nota('La empresa compradora también puede asignar sus cupos desde su propio acceso.'),
  [{ l: 'Cerrar' }, { l: 'Asignar trabajadores (' + libres + ' libres)', cls: 'pri', fn: () => {
    if (!libres) { toast('No quedan cupos libres', 'warn'); return false; }
    setTimeout(() => modal('Asignar cupos · ' + c.emp, '<p class="small muted">Quedan ' + libres + ' cupos. Pega los DNI (uno por línea) o marca de la lista.</p><div class="checklist">' + ['Teresa Guzmán Rojas', 'Alonso Pacheco Lira', 'Wendy Silva Cano', 'Bruno Arana Vela'].map((n, i) => '<label for="ca-' + i + '"><input type="checkbox" id="ca-' + i + '" data-n="' + n + '"' + (i < 2 ? ' checked' : '') + '><span><b>' + n + '</b></span></label>').join('') + '</div>' + campo('ca-d', 'DNI adicionales', 'textarea', ''), [{ l: 'Cancelar' }, { l: 'Asignar', cls: 'pri', fn: (ov) => { const sel = $$('[data-n]:checked', ov).map((x) => x.dataset.n).slice(0, libres); if (!sel.length) { toast('Elige al menos un trabajador', 'warn'); return false; } c.asig.push(...sel); toast(sel.length + ' cupo(s) asignado(s); recibieron su acceso por correo'); done(); } }]), 30);
  } }]);
}

/* ---------- Catálogo público con carrito y pago ---------- */
const CARRITO = [];
let tiendaPaso = 'catalogo'; let tiendaFicha = null; let tiendaCupon = null; let tiendaPedido = null;
const ALUMNO = { cursos: ['QH-104', 'QH-110'] };
R['pub-cursos'] = (root) => {
  const e = EMPRESAS.find((x) => x.id === 'qhaliy');
  const of = () => OFERTAS.filter((o) => o.estado === 'Publicado');
  const nItems = () => CARRITO.reduce((a, x) => a + x.q, 0);
  const sub = () => CARRITO.reduce((a, x) => a + precioOf(ofBy(x.id)) * x.q, 0);
  const desc = () => tiendaCupon ? Math.round(sub() * 0.1 * 100) / 100 : 0;
  const tot = () => sub() - desc();
  let filtro = 'Todos';
  const card = (o) => { const c = cursoBy(o.id); return '<div class="offer"><span class="of-band" style="background:' + c.color + '">' + ic('book', 26) + '<span class="cc-h">' + c.horas + ' h</span></span><div class="of-b"><span class="tiny muted">' + esc(o.cat) + '</span><b class="of-t">' + esc(c.n) + '</b><span class="small muted">' + esc(o.modal) + ' · certificado verificable</span><div class="of-p">' + (o.promo != null ? '<s class="muted small">' + sol(o.precio) + '</s> ' : '') + '<b>' + sol(precioOf(o)) + '</b></div><div class="row"><button class="btn sm" data-ficha="' + o.id + '">Ver ficha</button><button class="btn sm pri" data-add="' + o.id + '">' + ic('cart', 14) + 'Agregar</button></div></div></div>'; };
  const interes = (ex) => { const l = of().filter((o) => !ex.includes(o.id)).slice(0, 3); return l.length ? '<div class="card"><h3>' + ic('spark', 18) + ' También te puede interesar</h3><p class="small muted" style="margin:4px 0 12px">Elegidos por personas que compraron cursos parecidos.</p><div class="grid g3">' + l.map(card).join('') + '</div></div>' : ''; };
  const pint = () => {
    let h = '';
    if (tiendaPaso === 'catalogo') {
      const cats = ['Todos', 'Alto riesgo', 'Gestión SST'];
      h = '<div class="st-hero"><h1>Cursos de Seguridad y Salud en el Trabajo</h1><p class="muted">Virtuales, con certificado verificable por código y QR. Compra para ti o cupos para tu empresa.</p></div>' +
        '<div class="row" style="margin-bottom:12px">' + seg('stf', cats, cats.indexOf(filtro)) + '</div><div class="grid g3">' + of().filter((o) => filtro === 'Todos' || o.cat === filtro).map(card).join('') + '</div>';
    } else if (tiendaPaso === 'ficha') {
      const o = ofBy(tiendaFicha); const c = cursoBy(o.id);
      h = '<button class="btn sm ghost" data-go="catalogo">‹ Catálogo</button><div class="grid g-main" style="align-items:start"><div class="stack"><div><span class="tiny muted">' + esc(o.cat) + ' · ' + c.horas + ' horas · ' + esc(o.modal) + '</span><h1>' + esc(c.n) + '</h1><p style="margin-top:6px">' + esc(o.desc) + '</p></div>' +
        '<div class="card"><h3>Qué aprenderás</h3><ul class="small">' + o.aprende.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul></div>' +
        '<div class="card"><h3>Contenido</h3><div class="list" style="margin-top:6px">' + secciones(c).map((s, i) => '<div class="li"><span class="secn">' + (i + 1) + '</span><div class="lm"><b>' + esc(s.t) + '</b><span>' + s.it.map((x) => TIPO[x.y][1]).join(' · ') + '</span></div></div>').join('') + '</div></div>' +
        '<div class="card"><h3>Capacitador</h3><div class="row" style="margin-top:8px"><span class="av">' + iniciales(c.cap) + '</span><div><b>' + esc(c.cap) + '</b><div class="small muted">Instituto Qhaliy</div></div></div></div></div>' +
        '<div class="card stack st-buy"><div class="of-p big">' + (o.promo != null ? '<s class="muted small">' + sol(o.precio) + '</s> ' : '') + '<b>' + sol(precioOf(o)) + '</b></div><span class="tiny muted">IGV incluido · precio definido por el Instituto Qhaliy</span><button class="btn pri lg" data-add="' + o.id + '">' + ic('cart', 18) + 'Agregar al carrito</button>' +
        '<div class="field"><span>¿Para tu empresa? Compra cupos</span><div class="row"><input class="input" id="st-q" type="number" min="2" value="10" style="width:90px" aria-label="Cantidad de cupos"><button class="btn" data-cupos="' + o.id + '">Agregar cupos</button></div></div>' +
        '<ul class="small muted" style="margin:0;padding-left:18px"><li>Matrícula automática al pagar</li><li>Certificado con código y QR</li><li>Acceso desde el celular</li></ul></div></div>' + interes([o.id]);
    } else if (tiendaPaso === 'carrito') {
      h = '<button class="btn sm ghost" data-go="catalogo">‹ Seguir comprando</button><h1>Tu carrito</h1>' + (CARRITO.length ? '<div class="grid g-main" style="align-items:start"><div class="card"><div class="list">' + CARRITO.map((x, i) => { const o = ofBy(x.id); return '<div class="li"><span class="reco-b" style="background:' + cursoBy(o.id).color + '">' + ic('book', 16) + '</span><div class="lm"><b>' + esc(cursoBy(o.id).n) + '</b><span>' + (x.q > 1 ? x.q + ' cupos para empresa · ' : '') + sol(precioOf(o)) + ' c/u</span></div><b class="num">' + sol(precioOf(o) * x.q) + '</b><button class="iconbtn" data-rm="' + i + '" aria-label="Quitar">' + ic('trash', 16) + '</button></div>'; }).join('') + '</div></div>' +
        '<div class="card stack">' + resumen() + '<div class="row"><input class="input" id="st-cup" placeholder="Cupón" value="' + (tiendaCupon || '') + '" style="flex:1;min-width:0" aria-label="Cupón"><button class="btn" id="st-ap">Aplicar</button></div><span class="tiny muted">Prueba con EMPRESA10.</span><button class="btn pri lg" data-go="pago">Continuar al pago</button></div></div>' + interes(CARRITO.map((x) => x.id)) : '<div class="card">' + vacio('Tu carrito está vacío', 'Agrega un curso del catálogo.', '<button class="btn pri" data-go="catalogo">Ver cursos</button>') + '</div>');
    } else if (tiendaPaso === 'pago') {
      const empresa = CARRITO.some((x) => x.q > 1);
      h = '<button class="btn sm ghost" data-go="carrito">‹ Volver al carrito</button><h1>Pago</h1><div class="grid g-main" style="align-items:start"><div class="stack"><div class="card stack" style="gap:10px"><h3>Datos del comprador</h3>' + seg('st-tp', ['Persona', 'Empresa'], empresa ? 1 : 0) + '<div class="grid g2" id="st-dat"></div></div>' +
        '<div class="card stack" style="gap:10px"><h3>Medio de pago</h3>' + seg('st-mp', ['Tarjeta', 'Yape', 'PagoEfectivo'], 0) + '<div id="st-mpf" class="stack" style="gap:10px"></div></div></div>' +
        '<div class="card stack">' + resumen() + '<label class="row small" for="st-ter"><input type="checkbox" id="st-ter" checked> Acepto los términos y la política de privacidad del instituto</label><button class="btn pri lg" id="st-pay">Pagar ' + sol(tot()) + '</button><span class="tiny muted">' + ic('lock', 12) + ' Pago procesado por la pasarela en modo prueba.</span></div></div>';
    } else if (tiendaPaso === 'pendiente') {
      h = '<div class="card stack st-ok" style="align-items:center;text-align:center"><span class="okb warn">' + ic('clock', 40) + '</span><h1>Tu código de pago está listo</h1><div class="cip mono">CIP 4829 1735</div><p class="small muted">Págalo en tu banca móvil o en un agente hasta el 31/10/2026 23:59. Te matricularemos apenas se confirme el pago.</p><button class="btn pri" id="st-sim">(Demostración) Simular pago en agente</button></div>';
    } else {
      const p = tiendaPedido;
      h = '<div class="card stack st-ok" style="align-items:center;text-align:center"><span class="okb">' + ic('check', 40) + '</span><h1>¡Pago confirmado!</h1><p>Pedido <b class="mono">' + p.id + '</b> · ' + sol(p.total) + '</p>' +
        (p.tipo === 'Empresa' ? '<p>Se crearon <b>' + p.items.reduce((a, i) => a + i[1], 0) + ' cupos</b> para tu empresa. Asígnalos a tus trabajadores desde tu acceso.</p>' : '<div class="card flat" style="text-align:left;width:100%;max-width:440px"><b>Te matriculamos automáticamente en:</b><ul class="small">' + p.items.map((i) => '<li>' + esc(cursoBy(i[0]).n) + '</li>').join('') + '</ul></div>') +
        '<p class="small muted">Te enviamos tu ' + (p.tipo === 'Empresa' ? 'factura' : 'boleta') + ' electrónica <b>' + p.comp + '</b> por correo (proveedor por definir · simulado).</p><div class="row" style="justify-content:center">' + (p.tipo === 'Persona' ? '<button class="btn pri" id="st-mis">Ir a mis cursos</button>' : '') + '<button class="btn" data-go="catalogo">Seguir viendo cursos</button></div></div>' + interes(p.items.map((i) => i[0]));
    }
    root.innerHTML = '<div class="storewrap"><div class="publicnote"><span>' + ic('eye', 16) + ' Página pública simulada: catálogo de cursos a la venta del Instituto Qhaliy (cliente de EscudoSST). Los precios los define el instituto.</span><a class="btn sm" href="#' + (S.role === 'external_learner' ? 'alumno-cursos' : 'm-vta') + '">Volver al sistema</a></div>' +
      '<header class="st-head"><span class="cert-logo" style="background:#0E7A70">IQ</span><div style="flex:1;min-width:0"><b style="font-family:var(--font-d)">' + esc(e.corto) + '</b><div class="tiny muted">Cursos de seguridad ocupacional · con tecnología EscudoSST</div></div><button class="btn" data-go="carrito" id="st-cart" aria-label="Carrito">' + ic('cart', 18) + '<span class="num">' + nItems() + '</span></button></header>' + h + '</div>';
    bindTienda();
  };
  const resumen = () => '<h3>Resumen</h3>' + dl([['Subtotal', sol(sub())], ['Descuento', tiendaCupon ? '− ' + sol(desc()) + ' (' + tiendaCupon + ')' : '—'], ['Total', '<b class="num" style="font-size:1.2rem">' + sol(tot()) + '</b>']]) + '<span class="tiny muted">Precios con IGV incluido.</span>';
  const agregar = (id, q) => { const x = CARRITO.find((y) => y.id === id && (y.q > 1) === (q > 1)); if (x) x.q = q > 1 ? q : 1; else CARRITO.push({ id, q }); toast(q > 1 ? q + ' cupos agregados al carrito' : 'Curso agregado al carrito'); };
  const bindTienda = () => {
    $$('[data-go]', root).forEach((x) => x.onclick = () => { tiendaPaso = x.dataset.go; pint(); window.scrollTo(0, 0); });
    $$('[data-ficha]', root).forEach((x) => x.onclick = () => { tiendaFicha = x.dataset.ficha; tiendaPaso = 'ficha'; pint(); window.scrollTo(0, 0); });
    $$('[data-add]', root).forEach((x) => x.onclick = () => { agregar(x.dataset.add, 1); pint(); });
    $$('[data-cupos]', root).forEach((x) => x.onclick = () => { agregar(x.dataset.cupos, Math.max(2, +$('#st-q', root).value || 2)); pint(); });
    $$('[data-rm]', root).forEach((x) => x.onclick = () => { CARRITO.splice(+x.dataset.rm, 1); pint(); });
    bindSeg(root, 'stf', (i) => { filtro = ['Todos', 'Alto riesgo', 'Gestión SST'][i]; pint(); });
    const ap = $('#st-ap', root); if (ap) ap.onclick = () => { const v = $('#st-cup', root).value.trim().toUpperCase(); if (v === 'EMPRESA10') { tiendaCupon = v; toast('Cupón aplicado: 10 % de descuento'); } else { tiendaCupon = null; toast('Cupón no válido o vencido', 'warn'); } pint(); };
    if (tiendaPaso === 'pago') {
      let tp = CARRITO.some((x) => x.q > 1) ? 1 : 0; let mp = 0;
      const dat = () => { $('#st-dat', root).innerHTML = tp ? campo('sd-ruc', 'RUC', 'text', '20605512340') + campo('sd-rs', 'Razón social', 'text', 'Minera Cerro Azul S.A.C.') + campo('sd-co', 'Correo de facturación', 'email', 'compras@cerroazul.pe') + '<p class="tiny muted" style="align-self:end">Se emitirá factura. Los cupos se asignan después del pago.</p>' : campo('sd-n', 'Nombres y apellidos', 'text', 'Luz Marina Quispe Ortiz') + campo('sd-d', 'DNI', 'text', '45781236') + campo('sd-co', 'Correo', 'email', 'luzmarina.quispe@correo.pe') + campo('sd-ce', 'Celular', 'tel', '987 654 321'); };
      const mpf = () => { $('#st-mpf', root).innerHTML = [
        '<div class="grid g2">' + campo('pt-n', 'Número de tarjeta', 'text', '4111 1111 1111 1111') + campo('pt-t', 'Titular', 'text', 'LUZ M QUISPE') + campo('pt-v', 'Vence (MM/AA)', 'text', '12/28') + campo('pt-c', 'CVV', 'password', '123') + '</div>' + nota('Este formulario es de la pasarela: EscudoSST no ve ni guarda los datos de tu tarjeta. Tarjeta de prueba.', '', 'lock'),
        '<div class="grid g2">' + campo('py-c', 'Celular con Yape', 'tel', '987 654 321') + campo('py-a', 'Código de aprobación', 'text', '123456') + '</div><p class="tiny muted">Yape vía pasarela: el código de aprobación lo ves en tu app de Yape.</p>',
        '<p class="small">Generaremos un código CIP para pagar en banca móvil, agentes o bodegas. Te matriculamos cuando se confirme el pago.</p>',
      ][mp]; $('#st-pay', root).textContent = mp === 2 ? 'Generar código de pago' : 'Pagar ' + sol(tot()); };
      bindSeg(root, 'st-tp', (i) => { tp = i; $$('[data-seg="st-tp"] button', root).forEach((x, j) => x.classList.toggle('on', i === j)); dat(); });
      bindSeg(root, 'st-mp', (i) => { mp = i; $$('[data-seg="st-mp"] button', root).forEach((x, j) => x.classList.toggle('on', i === j)); mpf(); });
      dat(); mpf();
      $('#st-pay', root).onclick = (ev) => {
        if (!$('#st-ter', root).checked) { toast('Acepta los términos para continuar', 'warn'); return; }
        const b = ev.currentTarget; b.disabled = true; b.textContent = 'Procesando…';
        const cli = tp ? $('#sd-rs', root).value : $('#sd-n', root).value;
        setTimeout(() => {
          const num = 413 + PEDIDOS.filter((p) => +p.id.slice(-4) >= 413).length;
          tiendaPedido = { id: 'PED-2026-' + String(num).padStart(4, '0'), cli, tipo: tp ? 'Empresa' : 'Persona', items: CARRITO.map((x) => [x.id, x.q]), total: tot(), medio: ['Tarjeta', 'Yape (vía pasarela)', 'PagoEfectivo'][mp], est: mp === 2 ? 'Pendiente de pago' : 'Pagado', f: '30/10/2026 09:45', comp: mp === 2 ? '—' : (tp ? 'F001-0000' + (88 + PEDIDOS.length - 8) : 'B001-000' + (232 + PEDIDOS.length - 8)) };
          PEDIDOS.unshift(tiendaPedido);
          if (mp === 2) { tiendaPaso = 'pendiente'; } else confirmar();
          pint(); window.scrollTo(0, 0);
        }, 700);
      };
    }
    const sim = $('#st-sim', root); if (sim) sim.onclick = () => { tiendaPedido.est = 'Pagado'; tiendaPedido.comp = 'B001-000' + (232 + PEDIDOS.length - 8); confirmar(); pint(); };
    const mis = $('#st-mis', root); if (mis) mis.onclick = () => irComo('external_learner', 'alumno-cursos');
  };
  const confirmar = () => {
    const p = tiendaPedido;
    if (p.tipo === 'Persona') p.items.forEach((i) => { if (!ALUMNO.cursos.includes(i[0])) ALUMNO.cursos.push(i[0]); });
    else CUPOS.unshift({ emp: p.cli, ruc: '20605512340', curso: p.items[0][0], comp: p.items[0][1], asig: [], vence: '30/10/2027' });
    CARRITO.length = 0; tiendaCupon = null; tiendaPaso = 'ok';
  };
  if (tiendaPaso === 'ok' || tiendaPaso === 'pendiente') { if (!tiendaPedido) tiendaPaso = 'catalogo'; }
  pint();
};

/* ---------- Portal del participante externo ---------- */
function guardAlumno(root) { const m = modBy('VTA'); const a = !planIncluye(m) ? 'plan' : !featActiva('VTA') ? 'empresa' : 'ok'; if (a === 'ok') return false; bloqueado(root, m, a); return true; }
let alumnoAbierto = null;
const RECO_ALUMNO = [['QH-107', 'Complementa «Trabajos en altura», que estás cursando', 'link', 'Ver curso'], ['QH-101', 'Quienes aprobaron «IPERC paso a paso» siguieron con este curso', 'spark', 'Ver curso']];
R['alumno-cursos'] = (root) => {
  if (guardAlumno(root)) return;
  const enc = encabezado(S.role === 'external_learner' ? 'Hola, Luz Marina' : 'Portal del participante', 'Tus cursos del Instituto Qhaliy. Avanza desde la computadora o el celular.', '<a class="btn" href="#pub-cursos">' + ic('cart', 16) + 'Ver catálogo</a>', [['Mis cursos']]) + (S.role !== 'external_learner' ? nota('Estás viendo el portal de Luz Marina Quispe (participante externo). Elige «Participante externo» en «Ver como» para ver su menú.', 'warn') + '<div style="height:12px"></div>' : '');
  const c0 = alumnoAbierto && cursoBy(alumnoAbierto);
  if (c0) { root.innerHTML = enc + '<div class="card" style="max-width:780px" id="al-st"></div>'; estudioCurso($('#al-st', root), c0, { alVolver: () => { alumnoAbierto = null; R['alumno-cursos'](root); }, persona: ['Luz Marina Quispe Ortiz', '45781236', 'Participante externo'] }); return; }
  const cs = ALUMNO.cursos.map(cursoBy);
  root.innerHTML = enc + '<div class="grid g-main" style="align-items:start"><div class="grid g2">' + cs.map((c) => {
    const st = estAl(c); const it = []; secciones(c).forEach((s, si) => s.it.forEach((x, ii) => it.push(si + '-' + ii)));
    const av = Math.round(it.filter((k) => st.hechos.has(k)).length * 100 / it.length); const apr = st.nota != null && st.nota >= c.min;
    return '<div class="card stack" style="gap:10px"><span class="cc-band" style="background:' + c.color + ';border-radius:8px">' + ic('book', 22) + '<span class="cc-code">' + c.id + '</span><span class="cc-h">' + c.horas + ' h</span></span><b class="cc-t">' + esc(c.n) + '</b><div class="row between small"><span>' + (apr ? 'Aprobado · ' + notaTxt(c.escala, st.nota) : av + '% completado') + '</span>' + chip(apr ? 'Aprobado' : av ? 'En curso' : 'Sin iniciar', apr ? 'ok' : av ? 'warn' : 'na') + '</div>' + barra(av, apr ? 'ok' : 'info') + '<button class="btn ' + (apr ? '' : 'pri') + '" data-al="' + c.id + '">' + (apr ? 'Repasar' : av ? 'Continuar' : 'Empezar') + '</button></div>';
  }).join('') + '</div><div class="stack">' + recoBloque(RECO_ALUMNO, false, 'Según tus cursos y tu historial') + '<div class="card"><h3>Mis certificados</h3><p class="small muted" style="margin-top:4px">' + CERTIFICADOS.filter((x) => x.emp === 'qhaliy' && (x.ext === 0 || (x.persona && x.persona[0] === 'Luz Marina Quispe Ortiz'))).length + ' certificado(s)</p><a class="btn sm" href="#alumno-certificados" style="margin-top:8px">Ver certificados</a></div></div></div>';
  $$('[data-al]', root).forEach((b) => b.onclick = () => { alumnoAbierto = b.dataset.al; R['alumno-cursos'](root); window.scrollTo(0, 0); });
  $$('[data-reco]', root).forEach((b) => b.onclick = () => { tiendaFicha = b.dataset.reco; tiendaPaso = 'ficha'; location.hash = 'pub-cursos'; });
};
// Estado inicial de Luz Marina: avance en «Trabajos en altura» y aprobado «IPERC paso a paso»
(function () { const a = estAl(cursoBy('QH-104')); a.comp = true; a.hechos.add('0-0'); a.hechos.add('0-1'); a.act = 11; const b = estAl(cursoBy('QH-110')); b.comp = true; secciones(cursoBy('QH-110')).forEach((s, si) => s.it.forEach((x, ii) => b.hechos.add(si + '-' + ii))); b.nota = 0.84; b.intentos = 1; b.act = 342; b.cert = 'CERT-2026-000131'; }());
R['alumno-certificados'] = (root) => {
  if (guardAlumno(root)) return;
  const mis = CERTIFICADOS.filter((x) => x.emp === 'qhaliy' && (x.ext === 0 || (x.persona && x.persona[0] === 'Luz Marina Quispe Ortiz')));
  root.innerHTML = encabezado('Mis certificados', 'Descárgalos o compártelos: cualquiera puede verificarlos con el código o el QR.', '', [['Mis certificados']]) +
    (mis.length ? '<div class="grid g2">' + mis.map((r) => '<div class="card stack"><div class="cert-mini">' + certHTML(r) + '</div><div class="row between"><span class="mono small">' + r.cod + '</span>' + chip(r.est, CERT_CLS[r.est]) + '</div><div class="row"><button class="btn" data-pdf>' + ic('down', 16) + 'Descargar PDF</button>' + (r.est === 'Validado' ? '<a class="btn pri" href="#pub-certificado" data-ver="' + r.cod + '">' + ic('shield', 16) + 'Verificar</a>' : '') + '</div></div>').join('') + '</div>' : '<div class="card">' + vacio('Aún no tienes certificados', 'Aprueba un curso para obtener el tuyo.') + '</div>');
  $$('[data-pdf]', root).forEach((b) => b.onclick = () => toast('PDF descargado (simulado)'));
  $$('[data-ver]', root).forEach((b) => b.onclick = () => { window.__certCodigo = b.dataset.ver; });
};
R['alumno-compras'] = (root) => {
  if (guardAlumno(root)) return;
  const mis = PEDIDOS.filter((p) => p.cli === 'Luz Marina Quispe Ortiz');
  root.innerHTML = encabezado('Mis compras', 'Tus pedidos y comprobantes electrónicos.', '<a class="btn pri" href="#pub-cursos">' + ic('cart', 16) + 'Comprar otro curso</a>', [['Mis compras']]) +
    tabla([{ l: 'Pedido', f: (p) => '<span class="mono">' + p.id + '</span>' }, { l: 'Curso', f: (p) => esc(p.items.map((i) => cursoBy(i[0]).n).join(', ')) }, { l: 'Total', f: (p) => '<b class="num">' + sol(p.total) + '</b>' }, { l: 'Medio', f: (p) => esc(p.medio) }, { l: 'Fecha', f: (p) => p.f }, { l: 'Estado', f: (p) => chip(p.est, PED_CLS[p.est]) }, { l: 'Comprobante', f: (p) => p.comp !== '—' ? '<button class="btn sm" data-cp="' + p.comp + '">' + ic('down', 14) + p.comp + '</button>' : '—' }], mis) +
    '<div style="height:12px"></div>' + nota('Tus datos de tarjeta nunca se guardan: el pago lo procesa la pasarela del instituto.', '', 'lock');
  $$('[data-cp]', root).forEach((b) => b.onclick = () => toast('Comprobante ' + b.dataset.cp + ' descargado (simulado)'));
};
