/* Aula virtual (AUL): cursos por dentro, banco de preguntas, panel del capacitador y estudio del curso (trabajador / participante). */

/* ---------- Escalas de calificación (nota interna 0 a 1) ---------- */
const ESCALAS = {
  '0-20': { n: 'Vigesimal (0 a 20)', mins: [0.55, 0.65, 0.7, 0.75] },
  '0-10': { n: 'De 0 a 10', mins: [0.6, 0.7, 0.8] },
  '0-100': { n: 'De 0 a 100', mins: [0.6, 0.7, 0.8] },
  AD: { n: 'Letras AD / A / B / C', mins: [0.9, 0.7, 0.55], letras: [['AD', 0.95], ['A', 0.8], ['B', 0.62], ['C', 0.4]] },
  ABCD: { n: 'Letras A / B / C / D', mins: [0.85, 0.7, 0.55], letras: [['A', 0.92], ['B', 0.77], ['C', 0.6], ['D', 0.4]] },
};
const dec1 = (x) => String(Math.round(x * 10) / 10).replace('.', ',');
function notaTxt(sc, r) {
  if (r == null) return '—';
  if (sc === '0-20') return dec1(r * 20);
  if (sc === '0-10') return dec1(r * 10);
  if (sc === '0-100') return String(Math.round(r * 100));
  if (sc === 'AD') return r >= 0.9 ? 'AD' : r >= 0.7 ? 'A' : r >= 0.55 ? 'B' : 'C';
  return r >= 0.85 ? 'A' : r >= 0.7 ? 'B' : r >= 0.55 ? 'C' : 'D';
}
const escNum = (sc) => ['0-20', '0-10', '0-100'].includes(sc);
const notaDe = (c, r) => '<b class="num">' + notaTxt(c.escala, r) + '</b>' + (r != null && escNum(c.escala) ? '<span class="tiny muted">/' + c.escala.split('-')[1] + '</span>' : '');
const minTxt = (c) => notaTxt(c.escala, c.min) + (escNum(c.escala) ? ' de ' + c.escala.split('-')[1] : '');
const hm = (m) => m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') + ' min';
const enmascarar = (dni) => dni.slice(0, 2) + '•••' + dni.slice(-3);

/* ---------- Datos de demostración del aula ---------- */
const TIPO = { texto: ['text', 'Texto'], video: ['play', 'Video'], doc: ['file', 'Documento'], enlace: ['link', 'Enlace'], eval: ['quiz', 'Evaluación'] };
const CAPACITADOR = 'Raúl Paredes Ugaz';
const CURSOS = [
  { id: 'CUR-014', emp: 'andinas', n: 'Trabajos en caliente', cat: 'Alto riesgo', horas: 4, cap: CAPACITADOR, escala: '0-20', min: 0.7, sec: true, noSkip: true, comp: 'Obligatorio', verif: true, estado: 'Publicado', color: '#E2453C', mat: 28, aprob: 17, cargos: ['Soldador', 'Mecánico de mantenimiento'],
    secciones: [
      { t: 'Antes de empezar', it: [{ y: 'texto', n: 'Bienvenida y objetivos del curso', m: 'Lectura · 5 min', o: 1 }, { y: 'video', n: '¿Qué es un trabajo en caliente?', m: 'Vimeo · 8 min', o: 1 }] },
      { t: 'Peligros y controles', it: [{ y: 'video', n: 'Incendio, explosión y humos metálicos', m: 'Vimeo · 12 min', o: 1 }, { y: 'doc', n: 'PETS-MNT-007 Trabajos en caliente', m: 'PDF · 1,4 MB', o: 1 }, { y: 'enlace', n: 'Hoja de datos de seguridad del acetileno', m: 'Enlace externo', o: 0 }] },
      { t: 'Permiso de trabajo', it: [{ y: 'texto', n: 'Cómo llenar el PETAR de trabajo en caliente', m: 'Lectura · 10 min', o: 1 }, { y: 'doc', n: 'Formato PETAR en blanco', m: 'PDF · 320 KB', o: 0 }, { y: 'video', n: 'Vigía contra incendios: funciones', m: 'Vimeo · 9 min', o: 1 }] },
      { t: 'Evaluación', it: [{ y: 'eval', n: 'Evaluación final', m: '10 preguntas al azar · 3 intentos', o: 1 }] },
    ],
    evals: [{ n: 'Evaluación final', bancos: ['BG-01', 'BC-014'], np: 10, azarP: true, azarO: true, intentos: 3, tiempo: '20 min', retro: 'Al terminar' }, { n: 'Prueba de entrada (diagnóstica)', bancos: ['BC-014'], np: 5, azarP: true, azarO: false, intentos: 1, tiempo: 'Sin límite', retro: 'No mostrar', diag: true }] },
  { id: 'CUR-011', emp: 'andinas', n: 'Trabajo seguro con prensas', cat: 'Operación', horas: 3, cap: 'Gabriela Montoya Díaz', escala: '0-20', min: 0.7, sec: true, noSkip: true, comp: 'Obligatorio', verif: true, estado: 'Publicado', color: '#1F7FD1', mat: 32, aprob: 19, cargos: ['Operador de prensa troqueladora', 'Supervisor de producción'],
    secciones: [
      { t: 'Introducción', it: [{ y: 'texto', n: 'Objetivos y reglas del curso', m: 'Lectura · 4 min', o: 1 }, { y: 'video', n: 'Partes de la prensa troqueladora', m: 'Vimeo · 7 min', o: 1 }] },
      { t: 'Trabajo seguro', it: [{ y: 'video', n: 'Guardas y mando a dos manos', m: 'Vimeo · 10 min', o: 1 }, { y: 'doc', n: 'Procedimiento de cambio de matrices', m: 'PDF · 860 KB', o: 1 }] },
      { t: 'Evaluación', it: [{ y: 'eval', n: 'Evaluación final', m: '3 preguntas al azar · 3 intentos', o: 1 }] },
    ],
    evals: [{ n: 'Evaluación final', bancos: ['BC-011', 'BG-02'], np: 3, azarP: true, azarO: true, intentos: 3, tiempo: '15 min', retro: 'Al terminar' }] },
  { id: 'CUR-003', emp: 'andinas', n: 'Inducción SST general', cat: 'Inducción', horas: 2, cap: 'Gabriela Montoya Díaz', escala: 'AD', min: 0.7, sec: true, comp: 'Obligatorio', verif: false, estado: 'Publicado', color: '#22A35A', mat: 140, aprob: 131 },
  { id: 'CUR-017', emp: 'andinas', n: 'Bloqueo y etiquetado (LOTO)', cat: 'Alto riesgo', horas: 3, cap: CAPACITADOR, escala: '0-100', min: 0.7, sec: false, comp: 'Opcional', verif: true, estado: 'Publicado', color: '#F08A24', mat: 14, aprob: 9, cargos: ['Técnico electricista', 'Mecánico de mantenimiento'] },
  { id: 'CUR-008', emp: 'andinas', n: 'Uso y cuidado de EPP', cat: 'General', horas: 1, cap: 'Gabriela Montoya Díaz', escala: '0-10', min: 0.7, sec: false, comp: 'Desactivado', verif: true, estado: 'Publicado', color: '#1FB5A3', mat: 96, aprob: 88 },
  { id: 'CUR-019', emp: 'andinas', n: 'Operación segura de montacargas', cat: 'Operación', horas: 4, cap: CAPACITADOR, escala: 'ABCD', min: 0.7, sec: true, comp: 'Opcional', verif: true, estado: 'Borrador', color: '#C99A06', mat: 0, aprob: 0, cargos: ['Operador de montacargas'] },
  { id: 'QH-101', emp: 'qhaliy', n: 'Supervisor de Seguridad y Salud en el Trabajo', cat: 'Gestión SST', horas: 24, cap: CAPACITADOR, escala: '0-20', min: 0.7, sec: true, noSkip: true, comp: 'Obligatorio', verif: true, estado: 'Publicado', color: '#0D3B6E', mat: 212, aprob: 164 },
  { id: 'QH-104', emp: 'qhaliy', n: 'Trabajos en altura', cat: 'Alto riesgo', horas: 8, cap: CAPACITADOR, escala: '0-20', min: 0.7, sec: true, noSkip: true, comp: 'Obligatorio', verif: true, estado: 'Publicado', color: '#E2453C', mat: 146, aprob: 118 },
  { id: 'QH-107', emp: 'qhaliy', n: 'Espacios confinados', cat: 'Alto riesgo', horas: 8, cap: 'Maribel Sotomayor Quiroz', escala: '0-20', min: 0.7, sec: true, comp: 'Obligatorio', verif: true, estado: 'Publicado', color: '#F08A24', mat: 58, aprob: 41 },
  { id: 'QH-110', emp: 'qhaliy', n: 'IPERC paso a paso', cat: 'Gestión SST', horas: 6, cap: 'Maribel Sotomayor Quiroz', escala: '0-100', min: 0.7, sec: false, comp: 'Opcional', verif: true, estado: 'Publicado', color: '#1F7FD1', mat: 97, aprob: 80 },
  { id: 'QH-112', emp: 'qhaliy', n: 'Primeros auxilios básicos', cat: 'Emergencias', horas: 4, cap: CAPACITADOR, escala: '0-20', min: 0.65, sec: false, comp: 'Opcional', verif: true, estado: 'Publicado', color: '#22A35A', mat: 73, aprob: 66 },
  { id: 'QH-115', emp: 'qhaliy', n: 'Comité SST: funciones y elección', cat: 'Gestión SST', horas: 4, cap: 'Maribel Sotomayor Quiroz', escala: 'AD', min: 0.7, sec: false, comp: 'Desactivado', verif: false, estado: 'Borrador', color: '#1FB5A3', mat: 0, aprob: 0 },
];
const cursoBy = (id) => CURSOS.find((c) => c.id === id);
const empAula = () => S.empresa === 'qhaliy' ? 'qhaliy' : 'andinas';
const cursosEmp = () => CURSOS.filter((c) => c.emp === empAula());
const cursosVisibles = () => S.role === 'trainer' ? cursosEmp().filter((c) => c.cap === CAPACITADOR) : cursosEmp();
const CURSO_TRAB = 'CUR-011'; // curso que Carmen estudia en la app
function secciones(c) {
  if (!c.secciones) c.secciones = [
    { t: 'Introducción', it: [{ y: 'texto', n: 'Objetivos del curso', m: 'Lectura · 5 min', o: 1 }, { y: 'video', n: 'Presentación del curso', m: 'Vimeo · 6 min', o: 1 }] },
    { t: 'Contenido principal', it: [{ y: 'video', n: c.n + ': conceptos clave', m: 'Vimeo · 14 min', o: 1 }, { y: 'doc', n: 'Material de lectura', m: 'PDF · 2,1 MB', o: 1 }, { y: 'enlace', n: 'Norma de referencia', m: 'Enlace externo', o: 0 }] },
    { t: 'Evaluación', it: [{ y: 'eval', n: 'Evaluación final', m: '10 preguntas al azar · 3 intentos', o: 1 }] },
  ];
  if (!c.evals) c.evals = [{ n: 'Evaluación final', bancos: ['BG-01'], np: 10, azarP: true, azarO: true, intentos: 3, tiempo: '20 min', retro: 'Al terminar' }];
  return c.secciones;
}

const BANCOS = [
  { id: 'BG-01', n: 'Seguridad general SST', amb: 'General', curso: '—', np: 48, tipos: 'Opción única, verdadero o falso', est: 'Activo' },
  { id: 'BG-02', n: 'Uso y cuidado de EPP', amb: 'General', curso: '—', np: 26, tipos: 'Opción única, opción múltiple', est: 'Activo' },
  { id: 'BG-03', n: 'Respuesta ante emergencias', amb: 'General', curso: '—', np: 31, tipos: 'Opción única', est: 'Activo' },
  { id: 'BC-014', n: 'Trabajos en caliente', amb: 'Del curso', curso: 'CUR-014', np: 22, tipos: 'Opción única, verdadero o falso', est: 'Activo' },
  { id: 'BC-011', n: 'Prensas y guardas', amb: 'Del curso', curso: 'CUR-011', np: 15, tipos: 'Opción única, verdadero o falso', est: 'Activo' },
  { id: 'BC-017', n: 'Bloqueo y etiquetado', amb: 'Del curso', curso: 'CUR-017', np: 18, tipos: 'Opción única, opción múltiple', est: 'Borrador' },
];
const bancoBy = (id) => BANCOS.find((b) => b.id === id);
const PREGUNTAS = {
  'BC-011': [
    { q: '¿Qué debes hacer antes de cambiar una matriz de la prensa?', t: 'Opción única', ops: ['Aplicar bloqueo y etiquetado de energías', 'Bajar la velocidad de la prensa', 'Avisar por radio al supervisor', 'Retirar la guarda para tener espacio'], ok: 0, d: 'Media' },
    { q: 'Si una guarda de la prensa está dañada, ¿qué haces?', t: 'Opción única', ops: ['Sigo trabajando con más cuidado', 'Detengo la prensa y lo reporto', 'La reparo yo mismo con cinta'], ok: 1, d: 'Baja' },
    { q: 'Verdadero o falso: el mando a dos manos se puede puentear si la producción está atrasada.', t: 'Verdadero o falso', ops: ['Verdadero', 'Falso'], ok: 1, d: 'Baja' },
    { q: '¿Qué EPP es obligatorio en la línea de prensas?', t: 'Opción única', ops: ['Protector auditivo, lentes y guantes anticorte', 'Solo casco', 'Arnés de cuerpo entero'], ok: 0, d: 'Media' },
  ],
  'BC-014': [
    { q: '¿Quién permanece en la zona durante el trabajo en caliente y después de terminarlo?', t: 'Opción única', ops: ['El vigía contra incendios', 'El supervisor de producción', 'Cualquier trabajador del área', 'Nadie, basta con el extintor'], ok: 0, d: 'Media' },
    { q: '¿Qué documento debe estar firmado antes de iniciar?', t: 'Opción única', ops: ['El PETAR de trabajo en caliente', 'La orden de compra', 'El parte diario de producción'], ok: 0, d: 'Baja' },
    { q: 'Verdadero o falso: se puede soldar cerca de material combustible si se cubre con cartón.', t: 'Verdadero o falso', ops: ['Verdadero', 'Falso'], ok: 1, d: 'Baja' },
  ],
};
const PREG_GENERICAS = [
  { q: '¿Quién es responsable de reportar una condición insegura?', t: 'Opción única', ops: ['Todo trabajador que la detecte', 'Solo el área SST', 'Solo el supervisor'], ok: 0, d: 'Baja' },
  { q: '¿Qué indica una señal de color azul?', t: 'Opción única', ops: ['Obligación', 'Prohibición', 'Advertencia'], ok: 0, d: 'Media' },
  { q: 'Verdadero o falso: el EPP reemplaza a los controles de ingeniería.', t: 'Verdadero o falso', ops: ['Verdadero', 'Falso'], ok: 1, d: 'Media' },
];
const preguntasBanco = (id) => PREGUNTAS[id] || PREG_GENERICAS;
const preguntasCurso = (c) => PREGUNTAS['BC-' + c.id.split('-')[1]] ? PREGUNTAS['BC-' + c.id.split('-')[1]].slice(0, 3) : PREG_GENERICAS;

// Permisos del capacitador (los define el administrador). Por defecto sube materiales y no matricula.
const PERM_CAP = { contenido: true, evaluaciones: true, calificar: true, matricular: false, reportes: true, certificados: false };
const PERM_TXT = { contenido: ['Subir y editar materiales', 'Textos, videos, documentos y enlaces del curso'], evaluaciones: ['Crear y configurar evaluaciones', 'Elegir bancos, intentos y nota mínima'], calificar: ['Calificar y modificar notas', 'Siempre con motivo; queda en la bitácora'], matricular: ['Matricular participantes', 'Por defecto lo hace el administrador'], reportes: ['Ver reportes del curso', 'Avance, permanencia y aprobados'], certificados: ['Solicitar validación de certificados', 'La validación final es del administrador'] };
const puedeA = (k) => { if (soloLectura()) return false; if (S.role === 'trainer') return !!PERM_CAP[k]; return ['account_admin', 'sst_manager', 'consultant', 'hr', 'sst_supervisor'].includes(S.role); };
const adminAula = () => ['account_admin', 'sst_manager', 'consultant', 'hr'].includes(S.role);

const PERS_EXT = [['Luz Marina Quispe Ortiz', '45781236', 'Particular'], ['Jhonatan Rivera Salas', '72019384', 'Particular'], ['Carlos Mendoza Ríos', '44120398', 'Minera Cerro Azul S.A.C.'], ['Andrea Salinas Poma', '71203984', 'Minera Cerro Azul S.A.C.'], ['Víctor Huamán Torres', '43982017', 'Transportes Mantaro S.A.C.'], ['Elena Ramos Cueva', '70398124', 'Particular'], ['Miguel Ángel Soto Luna', '42091873', 'Transportes Mantaro S.A.C.'], ['Rosa Huertas Pinedo', '46102983', 'Particular'], ['Diego Paredes Inga', '73018294', 'Minera Cerro Azul S.A.C.'], ['Fiorella Campos Vera', '72983014', 'Particular'], ['Marco Antonio Lira Peña', '41029384', 'Panificadora Don Lucho E.I.R.L.'], ['Sofía Delgado Arce', '74019283', 'Particular']];
function participantes(c) {
  if (c._p) return c._p;
  const seed = +c.id.replace(/\D/g, '');
  let base;
  if (c.emp === 'qhaliy') base = PERS_EXT.map((p, i) => ({ id: 'x' + i, nombre: p[0], dni: p[1], puesto: p[2] }));
  else { const pref = W.filter((w) => (c.cargos || []).includes(w.puesto)); base = pref.concat(W.filter((w) => !pref.includes(w) && w.id !== 10)); }
  c._p = base.slice(0, Math.min(c.mat, 12)).map((w, i) => {
    const h = (seed * 37 + i * 53) % 100;
    let av = 0; let nota = null; let est = 'Sin iniciar'; let int = 0;
    if (h < 45) { av = 100; nota = Math.min(0.98, c.min + ((h * 7) % 25) / 100); est = 'Aprobado'; int = 1 + (h % 2); }
    else if (h < 55) { av = 100; nota = Math.max(0.3, c.min - 0.06 - (h % 10) / 100); est = 'Desaprobado'; int = 2; }
    else if (h < 88) { av = 20 + (h * 3) % 70; est = 'En curso'; }
    const act = Math.round(c.horas * 60 * av / 100 * (0.85 + (h % 20) / 100));
    const abierto = Math.round(act * (1.15 + (h % 30) / 100));
    const comp = c.comp === 'Desactivado' ? '—' : est === 'Sin iniciar' ? 'Pendiente' : (c.comp === 'Obligatorio' || h % 3 === 0) ? 'Aceptado' : '—';
    return { w, av, nota, est, int, act, abierto, comp, ult: est === 'Sin iniciar' ? '—' : fmtF(new Date(2026, 9, 30 - (h % 12))), yo: (c.id === CURSO_TRAB && w.id === 3) || (c.emp === 'qhaliy' && i === 0) };
  });
  return c._p;
}
/* Estado del estudiante que se demuestra (Carmen en CUR-011, Luz Marina en los cursos de Qhaliy) */
const estAl = (c) => c.est || (c.est = { hechos: new Set(), comp: false, nota: null, intentos: 0, cert: null, act: 0 });
function filaP(c, p) {
  if (!p.yo || !c.est) return p;
  const it = []; secciones(c).forEach((s, si) => s.it.forEach((x, ii) => it.push(si + '-' + ii)));
  const st = c.est; const av = Math.round(it.filter((k) => st.hechos.has(k)).length * 100 / it.length);
  return Object.assign({}, p, { av, nota: st.nota, est: st.nota != null ? (st.nota >= c.min ? 'Aprobado' : 'Desaprobado') : av ? 'En curso' : 'Sin iniciar', int: st.intentos, act: st.act, abierto: Math.round(st.act * 1.2), comp: st.comp ? 'Aceptado' : c.comp === 'Desactivado' ? '—' : 'Pendiente', ult: av ? '30/10/2026' : '—' });
}
const estCls = { Aprobado: 'ok', Desaprobado: 'bad', 'En curso': 'warn', 'Sin iniciar': 'na' };

/* ---------- Componentes ---------- */
const sw = (id, on, l, d, dis) => '<label class="swr" for="' + id + '"><span class="swt"><b>' + esc(l) + '</b>' + (d ? '<span class="small muted">' + d + '</span>' : '') + '</span><input type="checkbox" class="switch" role="switch" id="' + id + '"' + (on ? ' checked' : '') + (dis ? ' disabled' : '') + '></label>';
function ccard(c) {
  const pa = c.mat ? Math.round(c.aprob * 100 / c.mat) : 0;
  return '<button class="ccard" data-curso="' + c.id + '"><span class="cc-band" style="background:' + c.color + '">' + ic('book', 22) + '<span class="cc-code">' + c.id + '</span><span class="cc-h">' + c.horas + ' h</span></span>' +
    '<span class="cc-body"><span class="cc-t">' + esc(c.n) + '</span><span class="small muted">' + esc(c.cap) + '</span><span class="tiny muted">' + esc(ESCALAS[c.escala].n) + ' · mínimo ' + esc(minTxt(c)) + '</span>' +
    '<span class="row between small"><span>' + c.mat + ' matriculados</span><b class="num">' + pa + '% aprobados</b></span>' + barra(pa) + '<span class="row" style="gap:6px">' + chip(c.estado) + (c.sec ? chip('Secuencial', 'info') : '') + (c.verif ? chip('Verificable', 'info') : '') + '</span></span></button>';
}
const notaPermanencia = () => nota('<b>Tiempo de permanencia real.</b> Se mide por actividad: lectura con desplazamiento, reproducción del video, respuestas y clics dentro del curso. Una ventana abierta sin interacción no suma: tras ' + param('5 min sin actividad', 'Parámetro configurable por la empresa') + ' se pausa el conteo. Así se acreditan las horas de capacitación.', '', 'clock');
function videoModal(titulo, noSkip) {
  modal(titulo, '<div class="vplayer" id="vp"><button class="vplay" id="vp-b" aria-label="Reproducir">' + ic('play', 34) + '</button><div class="vbar"><i id="vp-i"></i></div><span class="vtag">Video alojado en Vimeo · simulado</span></div>' + (noSkip ? '<p class="tiny muted">Este curso no permite adelantar los videos.</p>' : ''), [{ l: 'Cerrar', cls: 'pri' }], { wide: true, mount: (ov) => { $('#vp-b', ov).onclick = (e) => { e.currentTarget.classList.add('on'); $('#vp-i', ov).style.width = '100%'; }; } });
}

/* ---------- AUL: lista de cursos ---------- */
R.AUL = (root, m, sub) => {
  const c = sub && cursoBy(sub);
  if (c && cursosEmp().includes(c)) return cursoPage(root, c);
  const cs = cursosVisibles();
  let filtro = 0;
  const emp = empresaActual();
  root.innerHTML = encMod('AUL', 'Portal propio de cursos de ' + esc(emp.corto) + '. Todo se trabaja dentro del curso: contenido, participantes, calificaciones, evaluaciones y configuración.',
    '<a class="btn" href="#aul-banco">' + ic('file', 16) + 'Banco de preguntas</a>' + (adminAula() ? '<button class="btn" id="aul-moodle">' + ic('link', 16) + 'Moodle (opcional)</button><button class="btn" id="aul-caps">' + ic('people', 16) + 'Capacitadores</button>' : '') + (adminAula() ? '<button class="btn pri" id="aul-nuevo">' + ic('plus', 16) + 'Nuevo curso</button>' : '')) +
    (S.role === 'trainer' ? nota('Ves solo los cursos que tienes asignados. Crear cursos es solo del administrador.', '', 'user') + '<div style="height:12px"></div>' : '') +
    (soloLectura() ? nota('Acceso de solo lectura al aula virtual.', 'warn', 'lock') + '<div style="height:12px"></div>' : '') +
    '<div class="grid g4" style="margin-bottom:16px">' + kpi('Cursos publicados', '<span style="color:var(--brand)">' + cs.filter((x) => x.estado === 'Publicado').length + '</span>', cs.filter((x) => x.estado === 'Borrador').length + ' en borrador') + kpi('Matrículas activas', '<span style="color:var(--teal)">' + cs.reduce((a, x) => a + x.mat - x.aprob, 0) + '</span>', 'En curso o sin iniciar') + kpi('Aprobados', '<span style="color:var(--ok)">' + cs.reduce((a, x) => a + x.aprob, 0) + '</span>', 'Generan constancia en Capacitaciones') + kpi('Horas acreditadas (mes)', '<span style="color:var(--orange)">' + (empAula() === 'qhaliy' ? '1 284' : '412') + '</span>', 'Por tiempo de permanencia real') + '</div>' +
    '<div class="row between" style="margin-bottom:12px">' + seg('aulf', ['Todos (' + cs.length + ')', 'Publicados', 'Borradores'], 0) + '<span class="small muted">Toca un curso para abrirlo</span></div>' +
    '<div class="grid g3" id="aul-list"></div><div style="height:14px"></div>' + notaPermanencia();
  const pint = () => { const l = cs.filter((x) => filtro === 0 || (filtro === 1 ? x.estado === 'Publicado' : x.estado === 'Borrador')); $('#aul-list', root).innerHTML = l.length ? l.map(ccard).join('') : '<div class="card" style="grid-column:1/-1">' + vacio('Sin cursos en este filtro', 'Cambia el filtro o crea un curso nuevo.') + '</div>'; $$('[data-curso]', root).forEach((b) => b.onclick = () => { location.hash = 'm-aul/' + b.dataset.curso; }); };
  bindSeg(root, 'aulf', (i) => { filtro = i; $$('[data-seg="aulf"] button', root).forEach((b, j) => b.classList.toggle('on', i === j)); pint(); });
  pint();
  const nv = $('#aul-nuevo', root); if (nv) nv.onclick = () => modal('Nuevo curso', '<p class="small muted">Primero los datos y la configuración; al guardar se abre el curso para subir contenidos y matricular.</p><div class="grid g2">' + campo('nc-n', 'Nombre del curso', 'text', 'Espacios confinados para vigías') + campo('nc-cat', 'Categoría', 'select', 'Alto riesgo', ['Inducción', 'General', 'Operación', 'Alto riesgo', 'Emergencias']) + campo('nc-h', 'Horas a acreditar', 'number', '4') + campo('nc-cap', 'Capacitador', 'select', CAPACITADOR, [CAPACITADOR, 'Gabriela Montoya Díaz']) + campo('nc-e', 'Escala de calificación', 'select', ESCALAS['0-20'].n, Object.values(ESCALAS).map((x) => x.n)) + campo('nc-c', 'Compromiso de culminación', 'select', 'Obligatorio', ['Obligatorio', 'Opcional', 'Desactivado']) + '</div>', [{ l: 'Cancelar' }, { l: 'Crear y abrir', cls: 'pri', fn: (ov) => { const n = $('#nc-n', ov).value.trim() || 'Curso nuevo'; const sc = Object.keys(ESCALAS).find((k) => ESCALAS[k].n === $('#nc-e', ov).value); const id = (empAula() === 'qhaliy' ? 'QH-' : 'CUR-') + (120 + CURSOS.length); CURSOS.push({ id, emp: empAula(), n, cat: $('#nc-cat', ov).value, horas: +$('#nc-h', ov).value || 1, cap: $('#nc-cap', ov).value, escala: sc, min: 0.7, sec: true, comp: $('#nc-c', ov).value, verif: true, estado: 'Borrador', color: '#6B7684', mat: 0, aprob: 0, secciones: [{ t: 'Sección 1', it: [] }], evals: [] }); toast('Curso creado como borrador'); location.hash = 'm-aul/' + id; } }]);
  const mo = $('#aul-moodle', root); if (mo) mo.onclick = () => modalMoodle();
  const cp = $('#aul-caps', root); if (cp) cp.onclick = () => modal('Capacitadores', tabla([{ l: 'Capacitador', f: (r) => '<b>' + esc(r[0]) + '</b><div class="tiny muted">' + esc(r[1]) + '</div>' }, { l: 'Cursos', k: 2 }, { l: 'Disponibilidad', k: 3 }, { l: 'Matricula', f: (r) => chip(r[4] ? 'Sí' : 'No', r[4] ? 'ok' : 'na') }], [[CAPACITADOR, 'Externo · ingeniero de seguridad', String(CURSOS.filter((x) => x.cap === CAPACITADOR && x.emp === empAula()).length), 'Lun, mié y vie por la mañana', PERM_CAP.matricular], ['Gabriela Montoya Díaz', 'Interna · supervisora SST de campo', String(CURSOS.filter((x) => x.cap === 'Gabriela Montoya Díaz').length), 'Mar y jue por la tarde', PERM_CAP.matricular]]) + nota('Los permisos del capacitador se cambian en la configuración de cada curso («Permisos del capacitador»). Su disponibilidad la registra él mismo.'), [{ l: 'Ver panel del capacitador', fn: () => { location.hash = 'capacitador'; } }, { l: 'Cerrar', cls: 'pri' }], { wide: true });
};
function modalMoodle() {
  modal('Conexión con Moodle (opcional)', nota('Solo para la empresa que prefiera seguir con su propio Moodle. El aula de EscudoSST funciona sin él. La conexión sincroniza matrículas y notas por API.', '', 'info') +
    sw('mo-on', false, 'Conectar con Moodle', 'Desactivado: los cursos viven solo en el aula de EscudoSST.') +
    '<div class="grid g2">' + campo('mo-url', 'Dirección del Moodle', 'text', 'https://campus.andinasdelsur.pe') + campo('mo-tok', 'Token de servicio web', 'password', 'secreto') + '</div>' +
    nota('El token se guarda como referencia a un secreto, nunca en texto plano. A Moodle solo se envían nombre, documento, correo y puesto; nunca datos médicos.', '', 'shield'), [{ l: 'Cancelar' }, { l: 'Probar conexión', fn: () => { toast('Conexión de prueba correcta (simulada)'); return false; } }, { l: 'Guardar', cls: 'pri', fn: (ov) => toast($('#mo-on', ov).checked ? 'Conexión con Moodle activada (simulada)' : 'Configuración guardada · Moodle sigue desactivado') }]);
}

/* ---------- AUL: el curso por dentro ---------- */
let curTab = 0; let curEdit = false; let curUlt = null;
function cursoPage(root, c) {
  if (curUlt !== c.id) { curTab = 0; curEdit = false; curUlt = c.id; }
  secciones(c);
  const ps = () => participantes(c).map((p) => filaP(c, p));
  const prom = (f) => { const l = ps(); return l.length ? Math.round(l.reduce((a, p) => a + f(p), 0) / l.length) : 0; };
  root.innerHTML = encabezado(c.n, '<span class="row" style="gap:6px;display:inline-flex">' + chip(c.id, 'info') + chip(c.cat, 'na') + chip(c.horas + ' h', 'na') + chip(ESCALAS[c.escala].n + ' · mínimo ' + minTxt(c), 'na') + chip(c.cap, 'na') + chip(c.estado) + '</span>',
    '<a class="btn" href="#m-aul">‹ Cursos</a><button class="btn" id="cur-prev">' + ic('eye', 16) + 'Vista del participante</button>', [[CAT.grupos[5]], ['Aula virtual', 'm-aul'], [c.n]]) +
    '<div class="grid g4" style="margin-bottom:14px">' + kpi('Matriculados', '<span style="color:var(--brand)">' + c.mat + '</span>', 'Se muestran ' + ps().length + ' en la demostración') + kpi('Avance promedio', '<span style="color:var(--teal)">' + prom((p) => p.av) + '%</span>') + kpi('Aprobados', '<span style="color:var(--ok)">' + c.aprob + '</span>', c.mat ? Math.round(c.aprob * 100 / c.mat) + '% de los matriculados' : 'Sin matrículas') + kpi('Permanencia promedio', '<span style="color:var(--orange)">' + hm(prom((p) => p.act)) + '</span>', 'Tiempo activo real') + '</div>' +
    tabs('cur', ['Contenido', 'Participantes (' + c.mat + ')', 'Calificaciones', 'Evaluaciones', 'Configuración'], curTab) + '<div id="cur-body"></div>';
  bindTabs(root, 'cur', (i) => { curTab = i; $$('[data-tabs="cur"] button', root).forEach((b, j) => { b.classList.toggle('on', i === j); b.setAttribute('aria-selected', i === j); }); pintTab(); });
  $('#cur-prev', root).onclick = () => { const host = modal('Vista del participante · ' + c.n, '<div id="prev-host"></div>', [{ l: 'Cerrar', cls: 'pri' }], { wide: true }); estudioCurso($('#prev-host', host), c, { preview: true }); };
  const body = () => $('#cur-body', root);
  const pintTab = () => [tabContenido, tabParticipantes, tabCalificaciones, tabEvaluaciones, tabConfig][curTab](body(), c, () => cursoPage(root, c));
  pintTab();
}
function tabContenido(b, c) {
  const edit = puedeA('contenido');
  const secs = secciones(c);
  let paso = 0;
  b.innerHTML = (S.role === 'trainer' && !edit ? nota('El administrador no te habilitó la edición de contenidos. Vista de solo lectura.', 'warn', 'lock') : '') +
    '<div class="row between" style="margin-bottom:12px"><div class="note" style="flex:1 1 320px">' + ic('info') + '<div>' + (c.sec ? '<b>Recorrido secuencial:</b> el participante desbloquea los elementos en orden; la evaluación se abre al completar los obligatorios.' : '<b>Recorrido libre:</b> el participante elige el orden.') + (c.noSkip ? ' Los videos no se pueden adelantar.' : '') + '</div></div>' +
    (edit ? '<button class="btn ' + (curEdit ? 'pri' : '') + '" id="ct-edit" aria-pressed="' + curEdit + '">' + ic('pen', 16) + (curEdit ? 'Terminar edición' : 'Modo edición') + '</button>' : '') + '</div>' +
    (secs.length ? '' : '<div class="card">' + vacio('Este curso aún no tiene contenido', 'Activa el modo edición para agregar la primera sección.') + '</div>') +
    secs.map((s, si) => '<div class="secc"><div class="secc-h"><span class="secn">SECCIÓN ' + (si + 1) + '</span><h3>' + esc(s.t) + '</h3>' + (curEdit ? '<button class="iconbtn" data-sren="' + si + '" aria-label="Renombrar sección">' + ic('pen', 16) + '</button><button class="btn sm" data-add="' + si + '">' + ic('plus', 14) + 'Agregar elemento</button>' : '<span class="tiny muted">' + s.it.length + ' elemento(s)</span>') + '</div>' +
      (s.it.length ? s.it.map((x, ii) => { paso++; return '<div class="citem"><span class="ci-ic t-' + x.y + '">' + ic(TIPO[x.y][0], 18) + '</span><div class="ci-m"><b>' + esc(x.n) + '</b><span>' + TIPO[x.y][1] + ' · ' + esc(x.m) + '</span></div>' + (c.sec ? '<span class="tiny muted ci-p">Paso ' + paso + '</span>' : '') + (x.o ? chip('Obligatorio', 'info') : chip('Opcional', 'na')) +
        (x.y === 'video' && !curEdit ? '<button class="btn sm ghost" data-ver="' + si + '-' + ii + '">' + ic('play', 14) + 'Ver</button>' : '') + (x.y === 'eval' && !curEdit ? '<button class="btn sm ghost" data-goeval>Configurar</button>' : '') +
        (curEdit ? '<button class="iconbtn" data-up="' + si + '-' + ii + '" aria-label="Subir"' + (ii === 0 ? ' disabled' : '') + '>' + ic('up', 16) + '</button><button class="iconbtn" data-del="' + si + '-' + ii + '" aria-label="Eliminar">' + ic('trash', 16) + '</button>' : '') + '</div>'; }).join('') : '<div class="empty" style="padding:14px">Sección vacía.</div>') + '</div>').join('') +
    (curEdit ? '<button class="btn pri" id="ct-sec">' + ic('plus', 16) + 'Agregar sección</button>' : '');
  const re = () => tabContenido(b, c);
  const e = $('#ct-edit', b); if (e) e.onclick = () => { curEdit = !curEdit; re(); };
  $$('[data-ver]', b).forEach((x) => x.onclick = () => { const [si, ii] = x.dataset.ver.split('-').map(Number); videoModal(secs[si].it[ii].n, c.noSkip); });
  $$('[data-goeval]', b).forEach((x) => x.onclick = () => { curTab = 3; $$('[data-tabs="cur"] button')[3].click(); });
  $$('[data-up]', b).forEach((x) => x.onclick = () => { const [si, ii] = x.dataset.up.split('-').map(Number); const l = secs[si].it; [l[ii - 1], l[ii]] = [l[ii], l[ii - 1]]; re(); });
  $$('[data-del]', b).forEach((x) => x.onclick = () => { const [si, ii] = x.dataset.del.split('-').map(Number); const it = secs[si].it[ii]; modal('Eliminar elemento', '<p>¿Eliminar <b>' + esc(it.n) + '</b>? El avance ya registrado de los participantes se conserva.</p>', [{ l: 'Cancelar' }, { l: 'Eliminar', cls: 'danger', fn: () => { secs[si].it.splice(ii, 1); toast('Elemento eliminado'); re(); } }]); });
  $$('[data-sren]', b).forEach((x) => x.onclick = () => { const s = secs[+x.dataset.sren]; modal('Renombrar sección', campo('sr-n', 'Nombre de la sección', 'text', s.t), [{ l: 'Cancelar' }, { l: 'Guardar', cls: 'pri', fn: (ov) => { s.t = $('#sr-n', ov).value.trim() || s.t; re(); } }]); });
  $$('[data-add]', b).forEach((x) => x.onclick = () => agregarElemento(c, +x.dataset.add, re));
  const ns = $('#ct-sec', b); if (ns) ns.onclick = () => { secs.splice(Math.max(0, secs.length - 1), 0, { t: 'Nueva sección', it: [] }); toast('Sección agregada antes de la evaluación'); re(); };
}
function agregarElemento(c, si, done) {
  let tipo = 'video';
  const campos = () => ({
    texto: campo('ae-n', 'Título', 'text', 'Lectura complementaria') + campo('ae-x', 'Contenido', 'textarea', 'Escribe aquí el texto que leerá el participante.'),
    video: campo('ae-n', 'Título', 'text', 'Demostración en planta') + campo('ae-u', 'Enlace del video en Vimeo', 'text', 'https://vimeo.com/000000000') + '<p class="tiny muted">El video queda alojado en la cuenta de Vimeo de la empresa; EscudoSST mide la reproducción real.</p>',
    doc: campo('ae-n', 'Título', 'text', 'Hoja de seguridad') + '<div class="dz" style="padding:14px">' + ic('upload', 26) + '<span class="small">PDF, Word o PowerPoint</span><button class="btn sm" type="button" onclick="toast(\'Archivo adjuntado (simulado)\')">Elegir archivo</button></div>',
    enlace: campo('ae-n', 'Título', 'text', 'Norma técnica de referencia') + campo('ae-u', 'Dirección (URL)', 'text', 'https://'),
    eval: campo('ae-n', 'Título', 'text', 'Evaluación de la sección') + '<p class="small muted">Se crea con el banco general y 10 preguntas al azar. Ajústala luego en la pestaña «Evaluaciones».</p>',
  }[tipo]);
  const ov = modal('Agregar elemento a «' + secciones(c)[si].t + '»', '<div class="seg" data-seg="ae">' + Object.keys(TIPO).map((k) => '<button class="' + (k === tipo ? 'on' : '') + '" data-t="' + k + '">' + TIPO[k][1] + '</button>').join('') + '</div><div id="ae-c" class="stack" style="gap:10px">' + campos() + '</div><label class="row small" for="ae-o"><input type="checkbox" id="ae-o" checked> Obligatorio para avanzar</label>', [{ l: 'Cancelar' }, { l: 'Agregar', cls: 'pri', fn: (o) => {
    const n = $('#ae-n', o).value.trim(); if (!n) { toast('Escribe un título', 'warn'); return false; }
    secciones(c)[si].it.push({ y: tipo, n, m: { texto: 'Lectura · 5 min', video: 'Vimeo · 6 min', doc: 'PDF', enlace: 'Enlace externo', eval: '10 preguntas al azar · 3 intentos' }[tipo], o: $('#ae-o', o).checked ? 1 : 0 });
    if (tipo === 'eval') c.evals.push({ n, bancos: ['BG-01'], np: 10, azarP: true, azarO: true, intentos: 3, tiempo: '20 min', retro: 'Al terminar' });
    toast(TIPO[tipo][1] + ' agregado al curso'); done();
  } }]);
  $$('[data-t]', ov).forEach((x) => x.onclick = () => { tipo = x.dataset.t; $$('[data-t]', ov).forEach((y) => y.classList.toggle('on', y === x)); $('#ae-c', ov).innerHTML = campos(); });
}
function tabParticipantes(b, c, rehacer) {
  const ok = puedeA('matricular');
  const rows = participantes(c).map((p) => filaP(c, p));
  b.innerHTML = (!ok ? nota(S.role === 'trainer' ? 'La matrícula la hace el administrador (permiso «Matricular participantes» desactivado).' : 'Acceso de solo lectura.', '', 'lock') + '<div style="height:10px"></div>' : '') +
    '<div class="row" style="justify-content:flex-end;margin-bottom:12px">' + (ok ? '<button class="btn pri" id="pa-man">' + ic('plus', 16) + 'Matricular participantes</button><button class="btn" id="pa-cargo">' + ic('people', 16) + 'Matricular por cargo</button>' : '') + btnExport() + '</div><div id="pa-l"></div><div style="height:12px"></div>' + notaPermanencia();
  pantallaLista($('#pa-l', b), rows.map((p, i) => [p.w.nombre, p.w.puesto, p.av, p.nota, p.act, p.comp, p.est, p.ult, i]), [
    { l: 'Participante', f: (r) => '<b>' + esc(r[0]) + '</b>' + (rows[r[8]].yo ? ' ' + chip('Demo', 'info') : '') + '<div class="tiny muted">' + esc(r[1]) + '</div>' },
    { l: 'Avance', f: (r) => '<div style="min-width:110px">' + barra(r[2]) + '<span class="tiny">' + r[2] + '%</span></div>' },
    { l: 'Nota', f: (r) => notaDe(c, r[3]) },
    { l: 'Permanencia', f: (r) => '<span class="num">' + hm(r[4]) + '</span>' },
    { l: 'Compromiso', f: (r) => r[5] === '—' ? '<span class="muted">—</span>' : chip(r[5], r[5] === 'Aceptado' ? 'ok' : 'warn') },
    { l: 'Estado', f: (r) => chip(r[6], estCls[r[6]]) },
    { l: 'Último acceso', k: 7 },
  ], [[6, 'Estado'], [5, 'Compromiso']], (r) => fichaParticipante(c, rows[r[8]]));
  bindExport(b);
  const man = $('#pa-man', b); if (man) man.onclick = () => {
    const ya = new Set(participantes(c).map((p) => p.w.id));
    const lista = (c.emp === 'qhaliy' ? [] : W.filter((w) => !ya.has(w.id) && w.id !== 10)).slice(0, 14);
    modal('Matricular en «' + c.n + '»', '<input class="input" id="mm-q" type="search" placeholder="Buscar por nombre o DNI" aria-label="Buscar">' +
      (lista.length ? '<div class="checklist" id="mm-l">' + lista.map((w) => '<label for="mm-' + w.id + '"><input type="checkbox" id="mm-' + w.id + '" data-w="' + w.id + '"><span><b>' + esc(w.nombre) + '</b><span class="tiny muted"> · DNI ' + w.dni + ' · ' + esc(w.puesto) + '</span></span></label>').join('') + '</div>' : nota('En un instituto, los participantes llegan por la venta de cursos o por cupos de empresas.')) +
      '<div class="grid g2">' + campo('mm-f', 'Fecha límite', 'date', '2026-11-30') + '<label class="row small" for="mm-n" style="align-self:end"><input type="checkbox" id="mm-n" checked> Avisar por correo y WhatsApp</label></div>', [{ l: 'Cancelar' }, { l: 'Matricular', cls: 'pri', fn: (ov) => {
      const sel = $$('[data-w]:checked', ov).map((x) => wById(+x.dataset.w)); if (!sel.length) { toast('Marca al menos un trabajador', 'warn'); return false; }
      sel.forEach((w) => participantes(c).push({ w, av: 0, nota: null, est: 'Sin iniciar', int: 0, act: 0, abierto: 0, comp: c.comp === 'Desactivado' ? '—' : 'Pendiente', ult: '—' }));
      c.mat += sel.length; toast(sel.length + ' trabajador(es) matriculado(s); recibieron el aviso'); rehacer();
    } }], { mount: (ov) => { const q = $('#mm-q', ov); q.oninput = () => $$('#mm-l label', ov).forEach((l) => { l.style.display = l.textContent.toLowerCase().includes(q.value.toLowerCase()) ? '' : 'none'; }); } });
  };
  const cg = $('#pa-cargo', b); if (cg) cg.onclick = () => {
    const calc = (p) => { const ws = W.filter((w) => w.puesto === p); const ya = participantes(c).filter((x) => x.w.puesto === p).length; const tot = (PUESTOS.find((x) => x[0] === p) || [0, 0, ws.length])[2]; return [tot, ya, Math.max(0, tot - ya)]; };
    const ini = (c.cargos || [PUESTOS[0][0]])[0];
    const ov = modal('Matricular por cargo', '<p class="small muted">Matricula a todos los trabajadores activos de un cargo. Útil para cumplir la matriz de capacitación.</p>' + campo('mc-p', 'Cargo', 'select', ini, PUESTOS.map((p) => p[0])) + '<div id="mc-r"></div><label class="row small" for="mc-auto"><input type="checkbox" id="mc-auto" checked> Matricular automáticamente a los nuevos ingresos de este cargo</label>', [{ l: 'Cancelar' }, { l: 'Matricular', cls: 'pri', fn: (o) => { const [, , n] = calc($('#mc-p', o).value); c.mat += n; toast(n + ' trabajador(es) de «' + $('#mc-p', o).value + '» matriculado(s)'); rehacer(); } }]);
    const pr = () => { const [t, y, n] = calc($('#mc-p', ov).value); $('#mc-r', ov).innerHTML = '<div class="grid g3">' + kpi('Con el cargo', String(t)) + kpi('Ya matriculados', String(y)) + kpi('Se matricularán', '<span style="color:var(--brand)">' + n + '</span>') + '</div>'; };
    $('#mc-p', ov).onchange = pr; pr();
  };
}
function fichaParticipante(c, p) {
  const ses = p.act ? [[p.ult + ' 09:12 – 09:48', 31, 36], ['27/10/2026 18:05 – 18:40', 28, 35], ['24/10/2026 12:20 – 13:30', Math.max(5, p.act - 59), 70]] : [];
  cajon(p.w.nombre, esc(p.w.puesto) + ' · ' + esc(c.n), dl([['Documento', 'DNI ' + enmascarar(p.w.dni)], ['Avance', p.av + '%'], ['Nota', notaDe(c, p.nota)], ['Intentos', p.int + ' de ' + ((c.evals || [])[0] || { intentos: 3 }).intentos], ['Estado', chip(p.est, estCls[p.est])], ['Compromiso', p.comp], ['Tiempo activo', '<b>' + hm(p.act) + '</b> <span class="tiny muted">de ' + hm(p.abierto) + ' con la ventana abierta</span>']]) +
    '<div><h4 style="margin-bottom:8px">Sesiones de actividad</h4>' + (ses.length ? '<div class="timeline">' + ses.map((s) => '<div class="ev">' + s[0] + '<span>' + s[1] + ' min activos de ' + s[2] + ' min abiertos</span></div>').join('') + '</div>' : '<p class="small muted">Aún no inicia el curso.</p>') + '</div>' + notaPermanencia(),
  [{ l: 'Cerrar' }, { l: 'Enviar recordatorio', cls: 'pri', fn: () => toast('Recordatorio enviado por WhatsApp y correo') }]);
}
function tabCalificaciones(b, c, rehacer) {
  const rows = participantes(c).map((p, i) => [filaP(c, p), i]);
  const ok = puedeA('calificar');
  b.innerHTML = '<div class="note" style="margin-bottom:12px">' + ic('award') + '<div>Escala del curso: <b>' + esc(ESCALAS[c.escala].n) + '</b> · nota mínima para aprobar: <b>' + esc(minTxt(c)) + '</b>. Se cambia en «Configuración».</div></div>' +
    tabla([{ l: 'Participante', f: (r) => '<b>' + esc(r[0].w.nombre) + '</b><div class="tiny muted">' + esc(r[0].w.puesto) + '</div>' }, { l: 'Nota', f: (r) => notaDe(c, r[0].nota) }, { l: 'Estado', f: (r) => chip(r[0].est, estCls[r[0].est]) }, { l: 'Intentos', f: (r) => r[0].int + ' de ' + ((c.evals || [])[0] || { intentos: 3 }).intentos }, { l: 'Permanencia', f: (r) => hm(r[0].act) }, { l: 'Acción', f: (r) => ok && !r[0].yo ? '<button class="btn sm" data-nota="' + r[1] + '">' + ic('pen', 14) + 'Modificar nota</button>' : '<span class="tiny muted">—</span>' }], rows) +
    '<p class="tiny muted" style="margin-top:8px">Todo cambio de nota pide un motivo y queda en la bitácora de auditoría con el valor anterior y el nuevo.</p>';
  $$('[data-nota]', b).forEach((x) => x.onclick = () => {
    const p = participantes(c)[+x.dataset.nota]; const E = ESCALAS[c.escala];
    const inp = E.letras ? campo('mn-v', 'Nota', 'select', notaTxt(c.escala, p.nota || 0.6), E.letras.map((l) => l[0])) : campo('mn-v', 'Nota (' + E.n + ')', 'number', p.nota == null ? '' : notaTxt(c.escala, p.nota).replace(',', '.'));
    modal('Modificar nota · ' + p.w.nombre, inp + campo('mn-m', 'Motivo del cambio', 'text', '') + '<p class="tiny muted">Nota actual: ' + notaTxt(c.escala, p.nota) + '.</p>', [{ l: 'Cancelar' }, { l: 'Guardar nota', cls: 'pri', fn: (ov) => {
      const v = $('#mn-v', ov).value; const mo = $('#mn-m', ov).value.trim();
      let r; if (E.letras) r = E.letras.find((l) => l[0] === v)[1]; else { const mx = +c.escala.split('-')[1]; r = parseFloat(String(v).replace(',', '.')) / mx; if (!(r >= 0 && r <= 1)) { toast('La nota debe estar entre 0 y ' + mx, 'warn'); return false; } }
      if (!mo) { toast('Escribe el motivo del cambio', 'warn'); return false; }
      const ant = notaTxt(c.escala, p.nota); p.nota = r; p.av = 100; p.est = r >= c.min ? 'Aprobado' : 'Desaprobado'; toast('Nota actualizada: ' + ant + ' → ' + notaTxt(c.escala, r) + ' · ' + p.est); tabCalificaciones(b, c, rehacer);
    } }]);
  });
}
function tabEvaluaciones(b, c) {
  const ok = puedeA('evaluaciones');
  const ev = c.evals || [];
  b.innerHTML = (ok ? '<div class="row" style="justify-content:flex-end;margin-bottom:12px"><a class="btn" href="#aul-banco">' + ic('file', 16) + 'Banco de preguntas</a><button class="btn pri" id="ev-n">' + ic('plus', 16) + 'Nueva evaluación</button></div>' : '') +
    (ev.length ? '<div class="grid g2">' + ev.map((e, i) => '<div class="card stack" style="gap:10px"><div class="row between"><h3>' + esc(e.n) + '</h3>' + (e.diag ? chip('No califica', 'na') : chip('Calificada', 'info')) + '</div>' +
      '<div class="row" style="gap:6px">' + e.bancos.map((id) => { const bk = bancoBy(id) || { n: id, np: 0 }; return '<span class="pill-plan">' + esc(bk.n) + ' · ' + bk.np + '</span>'; }).join('') + '</div>' +
      dl([['Preguntas', e.np + (e.azarP ? ' al azar de ' + e.bancos.reduce((a, id) => a + ((bancoBy(id) || {}).np || 0), 0) : ' fijas')], ['Opciones', e.azarO ? 'En orden al azar' : 'En orden fijo'], ['Intentos', String(e.intentos)], ['Nota mínima', e.diag ? 'No aplica' : esc(minTxt(c))], ['Tiempo límite', esc(e.tiempo)], ['Retroalimentación', esc(e.retro)]]) +
      '<div class="row">' + (ok ? '<button class="btn sm pri" data-cfg="' + i + '">Configurar</button>' : '') + '<button class="btn sm" data-pv="' + i + '">' + ic('eye', 14) + 'Vista previa</button></div></div>').join('') + '</div>' : '<div class="card">' + vacio('Sin evaluaciones', 'Agrega una evaluación desde el contenido o con «Nueva evaluación».') + '</div>');
  const re = () => tabEvaluaciones(b, c);
  const n = $('#ev-n', b); if (n) n.onclick = () => { c.evals.push({ n: 'Evaluación ' + (ev.length + 1), bancos: ['BG-01'], np: 10, azarP: true, azarO: true, intentos: 3, tiempo: '20 min', retro: 'Al terminar' }); toast('Evaluación creada; configúrala'); re(); };
  $$('[data-pv]', b).forEach((x) => x.onclick = () => { const qs = preguntasCurso(c).slice(0, 2); modal('Vista previa · ' + ev[+x.dataset.pv].n, qs.map((q, i) => '<div class="card flat"><b>' + (i + 1) + '. ' + esc(q.q) + '</b><div class="stack" style="gap:6px;margin-top:8px">' + q.ops.map((o) => '<label class="row small"><input type="radio" name="pv' + i + '"> ' + esc(o) + '</label>').join('') + '</div></div>').join('') + '<p class="tiny muted">Las preguntas y las opciones cambian de orden en cada intento.</p>', [{ l: 'Cerrar', cls: 'pri' }]); });
  $$('[data-cfg]', b).forEach((x) => x.onclick = () => {
    const e = ev[+x.dataset.cfg];
    const bks = BANCOS.filter((bk) => bk.amb === 'General' || bk.curso === c.id);
    modal('Configurar «' + e.n + '»', '<div><span class="small" style="font-weight:700;color:var(--muted)">Bancos de preguntas</span><div class="checklist">' + bks.map((bk) => '<label for="eb-' + bk.id + '"><input type="checkbox" id="eb-' + bk.id + '" data-bk="' + bk.id + '"' + (e.bancos.includes(bk.id) ? ' checked' : '') + '><span><b>' + esc(bk.n) + '</b><span class="tiny muted"> · ' + bk.amb + ' · ' + bk.np + ' preguntas</span></span></label>').join('') + '</div></div>' +
      '<div class="grid g2">' + campo('ec-np', 'Número de preguntas', 'number', String(e.np)) + campo('ec-int', 'Intentos permitidos', 'select', String(e.intentos), ['1', '2', '3', '4', '5']) + campo('ec-t', 'Tiempo límite', 'select', e.tiempo, ['Sin límite', '15 min', '20 min', '30 min', '45 min']) + campo('ec-r', 'Retroalimentación', 'select', e.retro, ['Al terminar', 'Al cerrar el curso', 'No mostrar']) + '</div>' +
      sw('ec-ap', e.azarP, 'Preguntas al azar', 'Cada participante recibe una selección distinta del banco') + sw('ec-ao', e.azarO, 'Opciones al azar', 'Cambia el orden de las alternativas en cada intento') +
      '<p class="small muted">Nota mínima para aprobar: <b>' + esc(minTxt(c)) + '</b> (según la escala del curso, en «Configuración»).</p>', [{ l: 'Cancelar' }, { l: 'Guardar', cls: 'pri', fn: (ov) => {
      const sel = $$('[data-bk]:checked', ov).map((y) => y.dataset.bk); if (!sel.length) { toast('Elige al menos un banco', 'warn'); return false; }
      Object.assign(e, { bancos: sel, np: Math.max(1, +$('#ec-np', ov).value || 1), intentos: +$('#ec-int', ov).value, tiempo: $('#ec-t', ov).value, retro: $('#ec-r', ov).value, azarP: $('#ec-ap', ov).checked, azarO: $('#ec-ao', ov).checked });
      toast('Evaluación actualizada'); re();
    } }], { wide: true });
  });
}
function tabConfig(b, c, rehacer) {
  const ok = puedeA('contenido') && S.role !== 'trainer';
  const adm = adminAula();
  const E = ESCALAS[c.escala];
  b.innerHTML = (!ok ? nota(S.role === 'trainer' ? 'La configuración del curso la cambia el administrador. Tú la ves en modo lectura.' : 'Acceso de solo lectura.', '', 'lock') + '<div style="height:12px"></div>' : '') +
    '<div class="grid g2" id="cfg">' +
    '<div class="card stack" style="gap:12px"><h3>Calificación</h3>' + campo('cf-e', 'Escala de calificación', 'select', E.n, Object.values(ESCALAS).map((x) => x.n)) + campo('cf-m', 'Nota mínima para aprobar', 'select', notaTxt(c.escala, c.min), E.mins.map((x) => notaTxt(c.escala, x))) + '<p class="tiny muted">Escalas disponibles: 0-20, 0-10, 0-100 o letras (AD/A/B/C o A/B/C/D). Las notas ya registradas se convierten a la nueva escala.</p></div>' +
    '<div class="card stack" style="gap:12px"><h3>Recorrido</h3>' + sw('cf-sec', c.sec, 'Recorrido secuencial', 'Desbloquea los elementos en orden') + sw('cf-ns', !!c.noSkip, 'Videos sin adelantar', 'El participante no puede saltar partes del video') +
    '<div class="field"><span>Compromiso de culminación</span>' + seg('cf-comp', ['Obligatorio', 'Opcional', 'Desactivado'], ['Obligatorio', 'Opcional', 'Desactivado'].indexOf(c.comp)) + '<p class="tiny muted">Obligatorio: debe aceptarlo antes de empezar. Opcional: el participante decide.</p></div></div>' +
    '<div class="card stack" style="gap:12px"><h3>Certificado</h3>' + sw('cf-cert', true, 'Emite certificado al aprobar', 'Pasa por validación del administrador') + sw('cf-ver', c.verif, 'Verificable públicamente por código', 'Cualquiera puede comprobarlo con el código o el QR') + '<div class="grid g2">' + campo('cf-pl', 'Plantilla', 'select', 'Clásica', ['Clásica', 'Moderna']) + campo('cf-vi', 'Vigencia', 'select', '12 meses', ['12 meses', '24 meses', 'Sin vencimiento']) + '</div><a class="small" href="#m-cap" id="cf-plt">Ver plantilla y certificados en Capacitaciones</a></div>' +
    '<div class="card stack" style="gap:10px"><h3>Permisos del capacitador</h3><p class="small muted">Aplican a ' + esc(c.cap) + ' y a todo capacitador del aula. Crear cursos es solo del administrador.</p>' + Object.keys(PERM_CAP).map((k) => sw('pc-' + k, PERM_CAP[k], PERM_TXT[k][0], PERM_TXT[k][1], !adm)).join('') + '</div>' +
    '<div class="card stack" style="gap:12px;grid-column:1/-1"><h3>Conexión con Moodle (opcional)</h3>' + sw('cf-mo', false, 'Sincronizar este curso con el Moodle de la empresa', 'Solo si la empresa prefiere seguir con su Moodle: matrículas y notas por API') + '<div class="grid g2" id="cf-mo-f" style="display:none">' + campo('cf-mo-id', 'Identificador del curso en Moodle', 'text', '') + campo('cf-mo-s', 'Sincronizar', 'select', 'Matrículas y notas', ['Matrículas y notas', 'Solo notas']) + '</div><p class="tiny muted">La conexión general (dirección y token como secreto) se configura en «Moodle (opcional)», en la lista de cursos.</p></div>' +
    '</div>' + (ok ? '<div class="row" style="justify-content:flex-end;margin-top:14px"><button class="btn pri" id="cf-g">Guardar configuración</button></div>' : '');
  if (!ok) $$('#cfg input, #cfg select, #cfg .seg button', b).forEach((x) => { if (!x.id || !x.id.startsWith('pc-') || !adm) x.disabled = true; });
  let comp = c.comp;
  bindSeg(b, 'cf-comp', (i) => { comp = ['Obligatorio', 'Opcional', 'Desactivado'][i]; $$('[data-seg="cf-comp"] button', b).forEach((x, j) => x.classList.toggle('on', i === j)); });
  $('#cf-e', b).onchange = (e) => { const k = Object.keys(ESCALAS).find((x) => ESCALAS[x].n === e.target.value); const sel = $('#cf-m', b); const mins = ESCALAS[k].mins; sel.innerHTML = mins.map((x) => '<option' + (x === 0.7 ? ' selected' : '') + '>' + notaTxt(k, x) + '</option>').join(''); if (!mins.includes(0.7)) sel.selectedIndex = 1; };
  $('#cf-mo', b).onchange = (e) => { $('#cf-mo-f', b).style.display = e.target.checked ? '' : 'none'; };
  Object.keys(PERM_CAP).forEach((k) => { const x = $('#pc-' + k, b); x.onchange = () => { PERM_CAP[k] = x.checked; toast('Permiso del capacitador ' + (x.checked ? 'activado' : 'desactivado') + ': ' + PERM_TXT[k][0]); }; });
  const g = $('#cf-g', b); if (g) g.onclick = () => {
    const k = Object.keys(ESCALAS).find((x) => ESCALAS[x].n === $('#cf-e', b).value);
    const mi = ESCALAS[k].mins.find((x) => notaTxt(k, x) === $('#cf-m', b).value) || 0.7;
    Object.assign(c, { escala: k, min: mi, sec: $('#cf-sec', b).checked, noSkip: $('#cf-ns', b).checked, comp, verif: $('#cf-ver', b).checked });
    toast('Configuración del curso guardada'); rehacer();
  };
}

/* ---------- Banco de preguntas ---------- */
R['aul-banco'] = (root) => {
  if (guardMod(root, 'AUL')) return;
  let t = 0;
  const ok = puedeA('evaluaciones');
  root.innerHTML = encabezado('Banco de preguntas', 'Bancos generales (reutilizables en cualquier curso) y bancos propios de cada curso. Las evaluaciones toman preguntas al azar de uno o más bancos.',
    (ok ? '<button class="btn" id="bq-imp">' + ic('upload', 16) + 'Importar desde Excel</button><button class="btn pri" id="bq-n">' + ic('plus', 16) + 'Nuevo banco</button>' : '<span class="chip info nodot">Solo lectura</span>'), [[CAT.grupos[5]], ['Aula virtual', 'm-aul'], ['Banco de preguntas']]) +
    '<div class="grid g4" style="margin-bottom:16px" id="bq-k"></div>' + tabs('bq', ['Bancos generales', 'Bancos de cursos'], 0) + '<div id="bq-b"></div>';
  const pint = () => {
    $('#bq-k', root).innerHTML = kpi('Bancos', '<span style="color:var(--brand)">' + BANCOS.length + '</span>') + kpi('Preguntas', '<span style="color:var(--teal)">' + BANCOS.reduce((a, x) => a + x.np, 0) + '</span>') + kpi('Usadas en evaluaciones', '<span style="color:var(--ok)">' + BANCOS.filter((x) => CURSOS.some((c) => (c.evals || []).some((e) => e.bancos.includes(x.id)))).length + ' bancos</span>') + kpi('Tipos de pregunta', '<span style="color:var(--orange)">3</span>', 'Opción única, múltiple y V/F');
    const rows = BANCOS.filter((x) => (t === 0 ? x.amb === 'General' : x.amb !== 'General'));
    pantallaLista($('#bq-b', root), rows.map((x) => [x.n, x.id, x.curso === '—' ? '—' : (cursoBy(x.curso) || {}).n || x.curso, x.np, x.tipos, x.est]), [{ l: 'Banco', f: (r) => '<b>' + esc(r[0]) + '</b><div class="tiny muted">' + r[1] + '</div>' }, { l: 'Curso', k: 2 }, { l: 'Preguntas', k: 3 }, { l: 'Tipos', k: 4 }, { l: 'Estado', f: (r) => chip(r[5]) }], [[5, 'Estado']], (r) => fichaBanco(bancoBy(r[1])));
  };
  bindTabs(root, 'bq', (i) => { t = i; $$('[data-tabs="bq"] button', root).forEach((x, j) => { x.classList.toggle('on', i === j); x.setAttribute('aria-selected', i === j); }); pint(); });
  pint();
  const n = $('#bq-n', root); if (n) n.onclick = () => modal('Nuevo banco', campo('nb-n', 'Nombre', 'text', 'Ergonomía en oficina') + campo('nb-a', 'Ámbito', 'select', 'General', ['General', 'Del curso']), [{ l: 'Cancelar' }, { l: 'Crear', cls: 'pri', fn: (ov) => { BANCOS.push({ id: 'BG-0' + (BANCOS.length + 1), n: $('#nb-n', ov).value || 'Banco nuevo', amb: $('#nb-a', ov).value, curso: '—', np: 0, tipos: '—', est: 'Borrador' }); toast('Banco creado'); pint(); } }]);
  const im = $('#bq-imp', root); if (im) im.onclick = () => importarExcel(pint);
};
function fichaBanco(bk) {
  const qs = preguntasBanco(bk.id);
  cajon(bk.n, bk.amb + ' · ' + bk.np + ' preguntas', '<p class="small muted">Se muestran ' + qs.length + ' preguntas de ejemplo.</p>' + qs.map((q, i) => '<div class="card flat stack" style="gap:6px"><div class="row between"><b>' + (i + 1) + '. ' + esc(q.q) + '</b></div><div class="row" style="gap:6px">' + chip(q.t, 'info') + chip('Dificultad ' + q.d.toLowerCase(), 'na') + '</div><ul class="qops">' + q.ops.map((o, j) => '<li class="' + (j === q.ok ? 'ok' : '') + '">' + (j === q.ok ? ic('check', 14) : '<i></i>') + esc(o) + '</li>').join('') + '</ul></div>').join(''),
  puedeA('evaluaciones') ? [{ l: 'Cerrar' }, { l: 'Agregar pregunta', cls: 'pri', fn: () => { bk.np++; toast('Pregunta agregada (simulado)'); } }] : [{ l: 'Cerrar' }]);
}
function importarExcel(done) {
  modal('Importar preguntas desde Excel', '<div class="dz">' + ic('upload', 36) + '<b>Arrastra tu archivo Excel aquí</b><span class="small">Columnas: pregunta, tipo, opción A–E, respuesta correcta, dificultad</span><div class="row"><button class="btn" type="button" onclick="toast(\'Plantilla descargada (simulado)\')">Descargar plantilla</button><button class="btn pri" type="button" id="ix-up">Simular carga de archivo</button></div></div>' + campo('ix-b', 'Banco de destino', 'select', BANCOS[0].n, BANCOS.map((x) => x.n)), [{ l: 'Cancelar' }], {
    mount: (ov) => {
      $('#ix-up', ov).onclick = () => {
        const bk = BANCOS.find((x) => x.n === $('#ix-b', ov).value);
        const filas = [['2', '¿Qué color identifica la señal de evacuación?', 'Opción única', 'B', 'Válida'], ['3', 'El extintor PQS sirve para fuegos A, B y C', 'Verdadero o falso', 'V', 'Válida'], ['4', '¿Cada cuánto se inspecciona el arnés?', 'Opción única', 'C', 'Válida'], ['5', 'Elige los EPP para soldar', 'Opción múltiple', 'A, C', 'Válida'], ['6', '¿Qué es un acto subestándar?', 'Opción única', 'A', 'Válida'], ['7', '¿Quién firma el PETAR?', 'Opción única', '', 'Falta la respuesta correcta'], ['8', 'La guarda puede retirarse con la máquina detenida', 'Verdadero o falso', 'F', 'Válida'], ['9', '¿Qué hacer ante un derrame?', 'Opción única', 'D', 'Válida'], ['10', 'Describe el procedimiento de bloqueo', 'Abierta', '—', 'Tipo «Abierta» no admitido'], ['11', '¿Qué indica el color rojo?', 'Opción única', 'A', 'Válida'], ['12', '¿Dónde se ubica el punto de reunión?', 'Opción única', 'B', 'Válida'], ['13', 'El casco tiene fecha de vencimiento', 'Verdadero o falso', 'V', 'Válida']];
        const ok = filas.filter((f) => f[4] === 'Válida').length;
        modal('Revisión del archivo · preguntas_sst.xlsx', '<div class="row" style="gap:8px">' + chip(ok + ' válidas', 'ok') + chip((filas.length - ok) + ' con errores', 'bad') + '</div>' + tabla([{ l: 'Fila', k: 0 }, { l: 'Pregunta', k: 1 }, { l: 'Tipo', k: 2 }, { l: 'Correcta', k: 3 }, { l: 'Resultado', f: (r) => chip(r[4], r[4] === 'Válida' ? 'ok' : 'bad') }], filas) + '<p class="tiny muted">Las filas con error no se importan; corrígelas en el Excel y vuelve a cargarlo.</p>', [{ l: 'Cancelar' }, { l: 'Importar ' + ok + ' preguntas válidas', cls: 'pri', fn: () => { bk.np += ok; toast(ok + ' preguntas importadas a «' + bk.n + '»'); done(); } }], { wide: true });
      };
    },
  });
}

/* ---------- Panel del capacitador ---------- */
const DISPO = { dias: ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'], franjas: [['Mañana', '08:00 – 12:00'], ['Tarde', '14:00 – 18:00'], ['Noche', '19:00 – 22:00']], on: new Set(['0-0', '2-0', '4-0', '1-1', '3-1', '0-2', '2-2']) };
const BLOQUEOS = [['14/11/2026', 'Auditoría en obra (fuera de Lima)'], ['24/12/2026 – 26/12/2026', 'Vacaciones']];
const POR_CALIFICAR = [['Teodoro Cusi Achahui', 'CUR-014', 'Práctica: llenado del PETAR', '29/10/2026'], ['Héctor Llanos Bustamante', 'CUR-014', 'Práctica: llenado del PETAR', '29/10/2026'], ['Wálter Inga Chuquimango', 'CUR-017', 'Tarea: secuencia de bloqueo del tablero TG-2', '28/10/2026'], ['Edwin Chávez Ramos', 'CUR-017', 'Tarea: secuencia de bloqueo del tablero TG-2', '27/10/2026']];
R.capacitador = (root) => {
  if (guardMod(root, 'AUL')) return;
  let t = 0;
  const mis = CURSOS.filter((c) => c.cap === CAPACITADOR && c.emp === empAula());
  root.innerHTML = encabezado(S.role === 'trainer' ? 'Hola, Raúl' : 'Panel del capacitador', 'Tus cursos asignados, lo que tienes por calificar y tu disponibilidad semanal.', '', [['Panel del capacitador']]) +
    (S.role !== 'trainer' ? nota('Estás viendo el panel de ' + CAPACITADOR + '. Elige «Capacitador» en «Ver como» para ver su menú propio.', 'warn') + '<div style="height:12px"></div>' : '') +
    '<div class="grid g4" style="margin-bottom:16px">' + kpi('Mis cursos', '<span style="color:var(--brand)">' + mis.length + '</span>') + kpi('Participantes', '<span style="color:var(--teal)">' + mis.reduce((a, c) => a + c.mat, 0) + '</span>') + kpi('Por calificar', '<span style="color:var(--orange)" id="cp-pc">' + POR_CALIFICAR.length + '</span>') + kpi('Horas dictadas (mes)', '<span style="color:var(--ok)">18</span>', 'Sesiones en vivo y presenciales') + '</div>' +
    tabs('cpt', ['Mis cursos', 'Por calificar', 'Mi disponibilidad', 'Mis permisos'], 0) + '<div id="cp-b"></div>';
  const b = $('#cp-b', root);
  const pint = () => {
    if (t === 0) { b.innerHTML = '<div class="grid g3">' + mis.map(ccard).join('') + '</div>'; $$('[data-curso]', b).forEach((x) => x.onclick = () => { location.hash = 'm-aul/' + x.dataset.curso; }); }
    else if (t === 1) {
      b.innerHTML = POR_CALIFICAR.length ? tabla([{ l: 'Participante', f: (r) => '<b>' + esc(r[0]) + '</b>' }, { l: 'Curso', f: (r) => esc(cursoBy(r[1]).n) }, { l: 'Actividad', k: 2 }, { l: 'Enviado', k: 3 }, { l: 'Acción', f: (r) => PERM_CAP.calificar || adminAula() ? '<button class="btn sm pri" data-cal="' + POR_CALIFICAR.indexOf(r) + '">Calificar</button>' : chip('Sin permiso', 'na') }], POR_CALIFICAR) : '<div class="card">' + vacio('Nada por calificar', 'Te avisaremos cuando un participante envíe una actividad.') + '</div>';
      $$('[data-cal]', b).forEach((x) => x.onclick = () => { const r = POR_CALIFICAR[+x.dataset.cal]; const c = cursoBy(r[1]); const E = ESCALAS[c.escala]; modal('Calificar · ' + r[0], '<p class="small"><b>' + esc(r[2]) + '</b> · ' + esc(c.n) + '</p><div class="photo" style="width:100%;height:120px">' + fotoSim(3) + '</div>' + (E.letras ? campo('ca-v', 'Nota', 'select', E.letras[1][0], E.letras.map((l) => l[0])) : campo('ca-v', 'Nota (' + E.n + ')', 'number', c.escala === '0-100' ? '85' : '16')) + campo('ca-c', 'Comentario para el participante', 'textarea', 'Buen trabajo. Revisa la verificación del extintor antes de iniciar.'), [{ l: 'Cancelar' }, { l: 'Guardar nota', cls: 'pri', fn: (ov) => { POR_CALIFICAR.splice(+x.dataset.cal, 1); toast('Nota ' + $('#ca-v', ov).value + ' registrada para ' + r[0]); $('#cp-pc', root).textContent = POR_CALIFICAR.length; pint(); } }]); });
    } else if (t === 2) {
      b.innerHTML = '<div class="grid g-main"><div class="card"><div class="ch"><h3>Disponibilidad semanal</h3><span class="small muted">Toca una franja para cambiarla</span></div><div class="avail" role="grid" aria-label="Disponibilidad semanal"><span></span>' + DISPO.dias.map((d) => '<span class="ah">' + d + '</span>').join('') +
        DISPO.franjas.map((f, fi) => '<span class="al"><b>' + f[0] + '</b><span class="tiny muted">' + f[1] + '</span></span>' + DISPO.dias.map((d, di) => { const k = di + '-' + fi; const on = DISPO.on.has(k); return '<button class="ac ' + (on ? 'on' : '') + '" data-av="' + k + '" aria-pressed="' + on + '" aria-label="' + d + ' ' + f[0] + (on ? ' disponible' : ' no disponible') + '">' + (on ? ic('check', 16) : '') + '</button>'; }).join('')).join('') + '</div>' +
        '<div class="gauge-legend" style="margin-top:10px"><span><i class="sem ok"></i>Disponible</span><span><i class="sem na"></i>No disponible</span></div></div>' +
        '<div class="card"><div class="ch"><h3>Fechas bloqueadas</h3><button class="btn sm" id="bl-n">' + ic('plus', 14) + 'Agregar</button></div><div class="list">' + (BLOQUEOS.length ? BLOQUEOS.map((x, i) => '<div class="li">' + ic('cal') + '<div class="lm"><b>' + esc(x[0]) + '</b><span>' + esc(x[1]) + '</span></div><button class="iconbtn" data-bl="' + i + '" aria-label="Quitar">' + ic('x', 16) + '</button></div>').join('') : '<p class="small muted">Sin fechas bloqueadas.</p>') + '</div></div></div>' +
        '<div style="height:12px"></div>' + nota('El administrador ve tu disponibilidad al programar sesiones en vivo o presenciales. Las fechas bloqueadas no se pueden asignar.') + '<div class="row" style="justify-content:flex-end;margin-top:12px"><button class="btn pri" id="av-g">Guardar disponibilidad</button></div>';
      $$('[data-av]', b).forEach((x) => x.onclick = () => { const k = x.dataset.av; if (DISPO.on.has(k)) DISPO.on.delete(k); else DISPO.on.add(k); pint(); });
      $$('[data-bl]', b).forEach((x) => x.onclick = () => { BLOQUEOS.splice(+x.dataset.bl, 1); pint(); });
      $('#bl-n', b).onclick = () => modal('Bloquear fecha', '<div class="grid g2">' + campo('bl-f', 'Fecha', 'date', '2026-11-20') + campo('bl-m', 'Motivo', 'text', 'Capacitación en otra sede') + '</div>', [{ l: 'Cancelar' }, { l: 'Bloquear', cls: 'pri', fn: (ov) => { const v = $('#bl-f', ov).value.split('-'); BLOQUEOS.push([v.length === 3 ? v[2] + '/' + v[1] + '/' + v[0] : v.join(''), $('#bl-m', ov).value || 'Sin motivo']); toast('Fecha bloqueada'); pint(); } }]);
      $('#av-g', b).onclick = () => toast('Disponibilidad guardada: ' + DISPO.on.size + ' franjas por semana');
    } else {
      b.innerHTML = '<div class="card"><h3>Lo que puedes hacer en el aula</h3><div class="list" style="margin-top:8px">' + Object.keys(PERM_CAP).map((k) => '<div class="li">' + sem(PERM_CAP[k] ? 'ok' : 'na') + '<div class="lm"><b>' + PERM_TXT[k][0] + '</b><span>' + PERM_TXT[k][1] + '</span></div>' + chip(PERM_CAP[k] ? 'Permitido' : 'No permitido', PERM_CAP[k] ? 'ok' : 'na') + '</div>').join('') + '</div></div><div style="height:12px"></div>' + nota('Los permisos los define el administrador del aula. Crear cursos es siempre del administrador.', '', 'lock');
    }
  };
  bindTabs(root, 'cpt', (i) => { t = i; $$('[data-tabs="cpt"] button', root).forEach((x, j) => { x.classList.toggle('on', i === j); x.setAttribute('aria-selected', i === j); }); pint(); });
  pint();
};

/* ---------- Estudio del curso (trabajador en la app, participante externo o vista previa) ---------- */
function estudioCurso(host, c, opt = {}) {
  const st = opt.preview ? { hechos: new Set(), comp: false, nota: null, intentos: 0, cert: null, act: 0 } : estAl(c);
  const secs = secciones(c);
  const items = []; secs.forEach((s, si) => s.it.forEach((x, ii) => items.push([si + '-' + ii, x])));
  const ev = (c.evals || [])[0] || { intentos: 3 };
  let abierto = null; let quiz = null;
  const hecho = (k) => st.hechos.has(k);
  const pct = () => Math.round(items.filter(([k]) => hecho(k)).length * 100 / items.length);
  const disponible = (idx) => !(c.comp === 'Obligatorio' && !st.comp) && (!c.sec || items.slice(0, idx).every(([k, x]) => hecho(k) || !x.o));
  const minDe = (x) => +((x.m.match(/(\d+) min/) || [0, 3])[1]);
  const completar = (k, x) => { if (!hecho(k)) { st.hechos.add(k); st.act += minDe(x); } };
  const persona = opt.persona || (c.emp === 'qhaliy' ? ['Luz Marina Quispe Ortiz', '45781236', 'Participante externo'] : [W[2].nombre, W[2].dni, W[2].puesto]);
  const pint = () => {
    let h = '';
    if (opt.preview) h += nota('Vista previa: así ve el curso un participante. Nada de lo que hagas aquí se guarda.', 'warn', 'eye');
    if (abierto !== null) { h += visor(); host.innerHTML = '<div class="study">' + h + '</div>'; bindVisor(); return; }
    h += (opt.alVolver ? '<button class="btn sm ghost" id="es-back" style="align-self:flex-start">‹ ' + esc(opt.volverTxt || 'Mis cursos') + '</button>' : '') +
      '<div class="es-hero" style="--cc:' + c.color + '"><span class="es-ic">' + ic('book', 22) + '</span><div><h2>' + esc(c.n) + '</h2><span class="small muted">' + esc(c.cap) + ' · ' + c.horas + ' h · nota mínima ' + esc(minTxt(c)) + '</span></div></div>' +
      '<div class="card stack" style="gap:8px"><div class="row between small"><b>' + pct() + '% completado</b><span class="muted">' + ic('clock', 14) + ' Tiempo activo: <b>' + hm(st.act) + '</b></span></div>' + barra(pct(), pct() === 100 ? 'ok' : 'info') + '<span class="tiny muted">El tiempo solo cuenta mientras lees, ves el video o respondes.</span></div>';
    if (c.comp !== 'Desactivado' && !st.comp) h += '<div class="card commit ' + (c.comp === 'Obligatorio' ? 'req' : '') + '"><div class="row" style="gap:10px;align-items:flex-start">' + ic('pen', 22) + '<div style="flex:1;min-width:0"><b>Compromiso de culminación' + (c.comp === 'Obligatorio' ? ' (obligatorio)' : ' (opcional)') + '</b><p class="small">Me comprometo a completar «' + esc(c.n) + '» y rendir la evaluación antes del 20/11/2026.</p></div></div><button class="btn pri" id="es-comp">Acepto el compromiso</button>' + (c.comp === 'Obligatorio' ? '<span class="tiny muted">Debes aceptarlo para empezar el curso.</span>' : '') + '</div>';
    else if (st.comp) h += '<div class="row small" style="color:var(--ok)">' + ic('check', 16) + '<b>Compromiso aceptado</b></div>';
    let idx = 0;
    h += secs.map((s, si) => '<div class="es-sec"><span class="secn">SECCIÓN ' + (si + 1) + '</span><h3>' + esc(s.t) + '</h3>' + s.it.map((x, ii) => {
      const k = si + '-' + ii; const d = disponible(idx); idx++; const ok = hecho(k);
      return '<button class="es-it ' + (ok ? 'done' : d ? 'cur' : 'lk') + '" data-it="' + k + '"' + (!d && !ok ? ' disabled' : '') + '><span class="es-st">' + ic(ok ? 'check' : d ? TIPO[x.y][0] : 'lock', 18) + '</span><span class="es-tx"><b>' + esc(x.n) + '</b><span>' + TIPO[x.y][1] + ' · ' + esc(x.m) + '</span></span>' + (ok ? '<span class="tiny" style="color:var(--ok)">Hecho</span>' : d ? ic('right', 16) : '') + '</button>';
    }).join('') + '</div>').join('');
    if (st.cert) h += certAlumno();
    host.innerHTML = '<div class="study">' + h + '</div>';
    const bk = $('#es-back', host); if (bk) bk.onclick = opt.alVolver;
    const cm = $('#es-comp', host); if (cm) cm.onclick = () => modal('Compromiso de culminación', '<p>Me comprometo a completar el curso <b>' + esc(c.n) + '</b>, revisar todo su contenido y rendir la evaluación antes del <b>20/11/2026</b>.</p><p class="tiny muted">Se registra la fecha, la hora y el dispositivo. Tu jefe directo verá el compromiso.</p>', [{ l: 'Ahora no' }, { l: 'Acepto', cls: 'pri', fn: () => { st.comp = true; toast('Compromiso registrado'); pint(); } }]);
    $$('[data-it]', host).forEach((b) => b.onclick = () => { abierto = b.dataset.it; quiz = null; pint(); arriba(); });
    const vc = $('#es-cert', host); if (vc) vc.onclick = () => verCertAlumno();
    const va = $('#es-val', host); if (va) va.onclick = () => { const r = certBy(st.cert); r.est = 'Validado'; r.valida = c.emp === 'qhaliy' ? 'Maribel Sotomayor Quiroz' : 'Rocío Villanueva Tapia'; toast('El administrador validó el certificado'); pint(); };
  };
  const arriba = () => { const sc = host.closest('.pc, .modal'); if (sc) sc.scrollTop = 0; else if (host.getBoundingClientRect().top < 0) host.scrollIntoView(); };
  const elem = () => { const [si, ii] = abierto.split('-').map(Number); return secs[si].it[ii]; };
  const visor = () => {
    const x = elem(); const k = abierto;
    let h = '<button class="btn sm ghost" id="vi-back" style="align-self:flex-start">‹ Volver al curso</button><div><span class="tiny muted">' + TIPO[x.y][1] + '</span><h2>' + esc(x.n) + '</h2></div>';
    if (x.y === 'texto') h += '<div class="card reading"><p>En este tema revisamos los controles que te protegen en tu puesto. Lee con atención: al final hay preguntas sobre estos puntos.</p><ul><li>Identifica los peligros antes de empezar.</li><li>Verifica que las guardas y dispositivos estén completos.</li><li>Si algo no está bien, detén el trabajo y repórtalo.</li></ul><p>Recuerda: nadie te puede obligar a trabajar en condiciones inseguras.</p></div><button class="btn pri lg" id="vi-ok">Marcar como leído</button>';
    if (x.y === 'video') h += '<div class="vplayer" id="vp"><button class="vplay" id="vp-b" aria-label="Reproducir">' + ic('play', 34) + '</button><div class="vbar"><i id="vp-i"></i></div><span class="vtag">Video en Vimeo · simulado</span></div>' + (c.noSkip ? '<p class="tiny muted">Este video no se puede adelantar.</p>' : '') + '<button class="btn pri lg" id="vi-ok"' + (hecho(k) ? '' : ' disabled') + '>' + (hecho(k) ? 'Video completado · Continuar' : 'Termina el video para continuar') + '</button>';
    if (x.y === 'doc') h += '<div class="card stack" style="align-items:center;text-align:center">' + ic('file', 40) + '<b>' + esc(x.n) + '</b><span class="small muted">' + esc(x.m) + '</span><button class="btn" id="vi-doc">Abrir documento</button></div><button class="btn pri lg" id="vi-ok">Ya lo revisé</button>';
    if (x.y === 'enlace') h += '<div class="card stack">' + ic('link', 28) + '<p class="small">Este material está en una página externa.</p><button class="btn" id="vi-doc">Abrir enlace</button></div><button class="btn pri lg" id="vi-ok">Listo, continuar</button>';
    if (x.y === 'eval') h += visorEval();
    return h;
  };
  const visorEval = () => {
    const qs = preguntasCurso(c);
    const rest = ev.intentos - st.intentos;
    if (!quiz) {
      if (st.nota != null && st.nota >= c.min) return '<div class="card result ok">' + ic('check', 34) + '<h3>Aprobaste con ' + notaTxt(c.escala, st.nota) + (escNum(c.escala) ? ' de ' + c.escala.split('-')[1] : '') + '</h3><p class="small">Ya rendiste esta evaluación.</p></div>';
      return '<div class="card stack">' + dl([['Preguntas', qs.length + ' al azar'], ['Nota mínima', esc(minTxt(c))], ['Intentos restantes', rest + ' de ' + ev.intentos], ['Tiempo límite', esc(ev.tiempo || 'Sin límite')]]) + '</div>' + (st.nota != null ? nota('Último intento: ' + notaTxt(c.escala, st.nota) + '. No alcanzó la nota mínima.', 'warn') : '') + (rest > 0 ? '<button class="btn pri lg" id="qz-ini">Empezar evaluación</button>' : nota('Ya no te quedan intentos. Comunícate con tu capacitador.', 'bad'));
    }
    if (quiz.fin) {
      const okk = quiz.r >= c.min;
      return '<div class="card result ' + (okk ? 'ok' : 'bad') + '">' + ic(okk ? 'award' : 'x', 40) + '<h3>' + (okk ? '¡Aprobaste!' : 'No alcanzaste la nota mínima') + '</h3><div class="big num">' + notaTxt(c.escala, quiz.r) + (escNum(c.escala) ? '<span class="small muted"> / ' + c.escala.split('-')[1] + '</span>' : '') + '</div><p class="small">' + quiz.bien + ' de ' + qs.length + ' respuestas correctas.</p></div>' +
        (okk ? (opt.preview ? '' : '<button class="btn pri lg" id="qz-cert">Ver mi certificado</button>') : (ev.intentos - st.intentos > 0 ? '<button class="btn pri lg" id="qz-ini">Intentar de nuevo (' + (ev.intentos - st.intentos) + ' restantes)</button>' : nota('Ya no te quedan intentos.', 'bad')));
    }
    const q = qs[quiz.i];
    return '<div class="row between small"><b>Pregunta ' + (quiz.i + 1) + ' de ' + qs.length + '</b><span class="muted">' + esc(ev.tiempo || '') + '</span></div>' + barra(Math.round(quiz.i * 100 / qs.length), 'info') + '<div class="card"><b>' + esc(q.q) + '</b><div class="stack" style="gap:8px;margin-top:12px">' + q.ops.map((o, j) => '<button class="opt ' + (quiz.resp[quiz.i] === j ? 'sel' : '') + '" data-op="' + j + '">' + esc(o) + '</button>').join('') + '</div></div><button class="btn pri lg" id="qz-next"' + (quiz.resp[quiz.i] == null ? ' disabled' : '') + '>' + (quiz.i === qs.length - 1 ? 'Enviar respuestas' : 'Siguiente') + '</button>';
  };
  const bindVisor = () => {
    const x = elem(); const k = abierto;
    $('#vi-back', host).onclick = () => { abierto = null; quiz = null; pint(); arriba(); };
    const vo = $('#vi-ok', host); if (vo) vo.onclick = () => { completar(k, x); toast('Avance guardado'); abierto = null; pint(); };
    const vd = $('#vi-doc', host); if (vd) vd.onclick = () => toast('Documento abierto en otra pestaña (simulado)');
    const vb = $('#vp-b', host); if (vb) vb.onclick = () => { vb.classList.add('on'); $('#vp-i', host).style.width = '100%'; setTimeout(() => { completar(k, x); const b = $('#vi-ok', host); if (b) { b.disabled = false; b.textContent = 'Video completado · Continuar'; } }, 900); };
    const qi = $('#qz-ini', host); if (qi) qi.onclick = () => { quiz = { i: 0, resp: [] }; pint(); };
    $$('[data-op]', host).forEach((b) => b.onclick = () => { quiz.resp[quiz.i] = +b.dataset.op; pint(); });
    const qn = $('#qz-next', host); if (qn) qn.onclick = () => {
      const qs = preguntasCurso(c);
      if (quiz.i < qs.length - 1) { quiz.i++; pint(); return; }
      const bien = qs.filter((q, i) => quiz.resp[i] === q.ok).length;
      quiz.fin = true; quiz.bien = bien; quiz.r = Math.round(bien / qs.length * 100) / 100 * 0.95 + (bien === qs.length ? 0.05 : 0);
      st.intentos++; st.nota = Math.max(st.nota || 0, quiz.r); st.act += 6;
      if (quiz.r >= c.min) { completar(k, x); if (!opt.preview) emitirCertAlumno(c, st, persona); }
      pint();
    };
    const qc = $('#qz-cert', host); if (qc) qc.onclick = () => { abierto = null; quiz = null; pint(); setTimeout(() => verCertAlumno(), 30); };
  };
  const certAlumno = () => { const r = certBy(st.cert); return '<div class="card stack" style="gap:8px;border-color:var(--ok)"><div class="row" style="gap:10px">' + ic('award', 26) + '<div style="flex:1;min-width:0"><b>Tu certificado</b><div class="small muted">' + r.cod + '</div></div>' + chip(r.est, CERT_CLS[r.est]) + '</div>' + (r.est === 'En validación' ? '<p class="small">El administrador debe validarlo antes de entregártelo.</p><button class="btn sm" id="es-val">(Demostración) El administrador lo valida</button>' : '') + '<button class="btn pri" id="es-cert">Ver certificado</button></div>'; };
  const verCertAlumno = () => { const r = certBy(st.cert); modal('Certificado ' + r.cod, certHTML(r) + (r.est === 'Validado' ? '<div class="row"><button class="btn" onclick="toast(\'PDF descargado (simulado)\')">' + ic('down', 16) + 'Descargar PDF</button><a class="btn pri" href="#pub-certificado" onclick="window.__certCodigo=\'' + r.cod + '\'">' + ic('shield', 16) + 'Verificar en línea</a></div>' : nota('En validación: podrás descargarlo cuando el administrador lo valide.', 'warn')), [{ l: 'Cerrar' }], { wide: true }); };
  pint();
}

/* ---------- Recomendación de cursos (AUL, también la usa VTA) ---------- */
const REGLAS_REC = [
  { k: 'matriz', n: 'Brechas de la matriz por cargo', d: 'Cursos obligatorios del cargo que la persona aún no aprueba', on: true, env: 46, conv: 31 },
  { k: 'vence', n: 'Certificados por vencer', d: 'Recapacitación antes de que venza el certificado', on: true, env: 18, conv: 12, dias: '30 días antes' },
  { k: 'ruta', n: 'Siguiente curso de la ruta', d: 'Al aprobar un curso, el siguiente de su ruta de aprendizaje', on: true, env: 22, conv: 9 },
  { k: 'compl', n: 'Cursos complementarios', d: 'Relacionados con el curso aprobado', on: false, env: 0, conv: 0 },
  { k: 'hallazgo', n: 'Ligados a hallazgos o accidentes del área', d: 'Tras un hallazgo de inspección o un accidente, al personal del área', on: true, env: 9, conv: 6 },
  { k: 'similar', n: 'Comprados por perfiles similares', d: 'Para participantes externos y empresas compradoras (venta de cursos)', on: true, env: 64, conv: 11, vta: true },
];
const REC_LISTA = [
  { dest: 'Ricardo Espinoza Lazo', tipo: 'Persona', curso: 'CUR-017', motivo: 'Brecha de la matriz: obligatorio para Mecánico de mantenimiento', origen: 'Regla automática', canal: 'App y WhatsApp', est: 'Enviada', f: '30/10/2026' },
  { dest: 'Soldador (10 personas)', tipo: 'Cargo', curso: 'CUR-014', motivo: 'Hallazgo INS-2026-221: trabajos en caliente sin vigía', origen: 'Rocío Villanueva', canal: 'App', est: 'Vista', f: '29/10/2026' },
  { dest: 'Luis Alberto Huanca Flores', tipo: 'Persona', curso: 'CUR-008', motivo: 'Tu certificado de Uso y cuidado de EPP vence en 20 días', origen: 'Regla automática', canal: 'App y correo', est: 'Clic', f: '28/10/2026' },
  { dest: 'Logística y almacén (22)', tipo: 'Área', curso: 'CUR-019', motivo: 'Accidente ACC-2026-012 con montacargas en el área', origen: 'Regla automática', canal: 'App', est: 'Matriculado', f: '22/10/2026' },
  { dest: 'Carmen Rosa Mamani Condori', tipo: 'Persona', curso: 'CUR-017', motivo: 'Siguiente curso de tu ruta «Operador de prensa»', origen: 'Regla automática', canal: 'App', est: 'Sugerida', f: '30/10/2026' },
  { dest: 'Minera Cerro Azul S.A.C.', tipo: 'Empresa compradora', curso: 'QH-104', motivo: 'Empresas de minería como la tuya también compraron este curso', origen: 'Regla automática', canal: 'Correo', est: 'Comprado', f: '15/10/2026', emp: 'qhaliy' },
  { dest: 'Luz Marina Quispe Ortiz', tipo: 'Persona', curso: 'QH-107', motivo: 'Complementa «Trabajos en altura», que estás cursando', origen: 'Maribel Sotomayor', canal: 'Correo', est: 'Vista', f: '27/10/2026', emp: 'qhaliy' },
  { dest: 'Kevin Alexander Sánchez Paredes', tipo: 'Persona', curso: 'CUR-003', motivo: 'Inducción pendiente para tu cargo', origen: 'Regla automática', canal: 'WhatsApp', est: 'Descartada', f: '20/10/2026' },
];
const REC_CLS = { Sugerida: 'na', Enviada: 'info', Vista: 'info', Clic: 'warn', Matriculado: 'ok', Comprado: 'ok', Descartada: 'na' };
/* Bloque «Recomendados para ti» (app del trabajador y portal del participante) */
function recoBloque(lista, compacto, sub) {
  return '<div class="reco ' + (compacto ? 'compacto' : '') + '"><div class="row between"><h3>' + ic('spark', 18) + ' Recomendados para ti</h3><span class="tiny muted">' + (sub || 'Según tu cargo y tu historial') + '</span></div>' +
    lista.map((r, i) => { const c = cursoBy(r[0]); return '<div class="reco-it"><span class="reco-b" style="background:' + c.color + '">' + ic('book', 16) + '</span><div class="reco-m"><b>' + esc(c.n) + '</b><span class="reco-why">' + ic(r[2] || 'info', 13) + esc(r[1]) + '</span></div><button class="btn sm ' + (i === 0 ? 'pri' : '') + '" data-reco="' + c.id + '">' + esc(r[3] || 'Ver curso') + '</button></div>'; }).join('') + '</div>';
}
R['aul-recomendaciones'] = (root) => {
  if (guardMod(root, 'AUL')) return;
  const emp = empAula();
  const lista = () => REC_LISTA.filter((r) => (r.emp || 'andinas') === emp);
  const ok = puedeA('contenido');
  let t = 0;
  root.innerHTML = encabezado('Recomendación de cursos', 'Reglas automáticas y recomendaciones manuales para trabajadores' + (featActiva('VTA') ? ', participantes externos y empresas compradoras' : '') + '. Cada recomendación registra si se vio, se abrió y terminó en matrícula o compra. Nunca usa datos médicos.',
    (ok ? '<button class="btn pri" id="rc-n">' + ic('spark', 16) + 'Recomendar curso</button>' : ''), [[CAT.grupos[5]], ['Aula virtual', 'm-aul'], ['Recomendaciones']]) +
    '<div class="grid g4" style="margin-bottom:6px">' + kpi('Enviadas (mes)', '<span style="color:var(--brand)">159</span>', 'Automáticas y manuales') + kpi('Vistas', '<span style="color:var(--teal)">118</span>', '74% de las enviadas') + kpi('Clics', '<span style="color:var(--orange)">83</span>', '52% de las enviadas') + kpi(featActiva('VTA') ? 'Matriculados o comprados' : 'Matriculados', '<span style="color:var(--ok)">69</span>', '43% de conversión') + '</div>' +
    '<div class="funnel" aria-label="Embudo de conversión">' + [['Enviadas', 159, 'var(--brand)'], ['Vistas', 118, 'var(--teal)'], ['Clics', 83, 'var(--orange)'], ['Matrícula o compra', 69, 'var(--ok)']].map((x) => '<div class="fu"><span class="small">' + x[0] + '</span><div class="bar"><i style="width:' + Math.round(x[1] * 100 / 159) + '%;background:' + x[2] + '"></i></div><b class="num">' + x[1] + '</b></div>').join('') + '</div>' +
    tabs('rec', ['Reglas automáticas', 'Recomendaciones enviadas'], 0) + '<div id="rc-b"></div>';
  const b = $('#rc-b', root);
  const pint = () => {
    if (t === 0) {
      b.innerHTML = '<div class="grid g2">' + REGLAS_REC.filter((r) => !r.vta || featActiva('VTA')).map((r) => '<div class="card stack" style="gap:8px">' + sw('rr-' + r.k, r.on, r.n, r.d, !ok) + '<div class="row small muted" style="gap:14px"><span>Enviadas: <b class="num" style="color:var(--fg)">' + r.env + '</b></span><span>Convertidas: <b class="num" style="color:var(--ok)">' + r.conv + '</b></span>' + (r.dias ? '<span>Aviso: ' + param(r.dias, 'Parámetro configurable por la empresa') + '</span>' : '') + '</div></div>').join('') + '</div>' +
        (S.addons.has('IAA') ? '<div class="card" style="margin-top:14px"><div class="ch"><h3>' + ic('spark', 18) + ' Sugerencias del asistente IA</h3>' + chip('Requiere aprobación', 'warn') + '</div><div class="list"><div class="li">' + sem('warn') + '<div class="lm"><b>«Espacios confinados» para Mantenimiento (8 personas)</b><span>Tres órdenes de trabajo en el tanque de barniz este mes</span></div><button class="btn sm" data-ia="0">Descartar</button><button class="btn sm pri" data-ia="1">Aprobar y enviar</button></div></div><p class="tiny muted">La IA solo sugiere: una persona aprueba antes de enviar. No se envían datos médicos al proveedor.</p></div>' : '') +
        '<div style="height:12px"></div>' + nota('Los envíos por correo o WhatsApp requieren consentimiento y respetan un límite de ' + param('2 recomendaciones por semana', 'Parámetro configurable por la empresa') + ' por persona.', '', 'shield');
      REGLAS_REC.forEach((r) => { const x = $('#rr-' + r.k, b); if (x) x.onchange = () => { r.on = x.checked; toast('Regla «' + r.n + '» ' + (r.on ? 'activada' : 'desactivada')); }; });
      $$('[data-ia]', b).forEach((x) => x.onclick = () => { toast(x.dataset.ia === '1' ? 'Sugerencia aprobada y enviada a 8 personas' : 'Sugerencia descartada'); x.closest('.li').remove(); });
    } else {
      pantallaLista(b, lista().map((r) => [r.dest, r.tipo, cursoBy(r.curso).n, r.motivo, r.origen, r.canal, r.est, r.f]), [{ l: 'Destinatario', f: (r) => '<b>' + esc(r[0]) + '</b><div class="tiny muted">' + r[1] + '</div>' }, { l: 'Curso', k: 2 }, { l: 'Motivo', f: (r) => '<span class="small">' + esc(r[3]) + '</span>' }, { l: 'Origen', k: 4 }, { l: 'Canal', k: 5 }, { l: 'Estado', f: (r) => chip(r[6], REC_CLS[r[6]]) }, { l: 'Fecha', k: 7 }], [[6, 'Estado'], [1, 'Tipo']], (r) => cajon(r[2], 'Para ' + esc(r[0]), dl([['Tipo de destinatario', r[1]], ['Motivo', esc(r[3])], ['Origen', esc(r[4])], ['Canal', esc(r[5])], ['Estado', chip(r[6], REC_CLS[r[6]])], ['Fecha', r[7]]]) + '<div class="timeline"><div class="ev">Sugerida<span>' + r[7] + ' 06:00</span></div>' + (['Sugerida', 'Descartada'].includes(r[6]) ? '' : '<div class="ev">Enviada por ' + esc(r[5].toLowerCase()) + '<span>' + r[7] + ' 08:00</span></div>') + (['Vista', 'Clic', 'Matriculado', 'Comprado'].includes(r[6]) ? '<div class="ev">Vista<span>' + r[7] + ' 12:41</span></div>' : '') + (['Clic', 'Matriculado', 'Comprado'].includes(r[6]) ? '<div class="ev">Abrió el curso<span>' + r[7] + ' 12:42</span></div>' : '') + (['Matriculado', 'Comprado'].includes(r[6]) ? '<div class="ev">' + r[6] + '<span>' + r[7] + ' 12:50</span></div>' : '') + '</div>', [{ l: 'Cerrar' }]));
    }
  };
  bindTabs(root, 'rec', (i) => { t = i; $$('[data-tabs="rec"] button', root).forEach((x, j) => { x.classList.toggle('on', i === j); x.setAttribute('aria-selected', i === j); }); pint(); });
  pint();
  const n = $('#rc-n', root); if (n) n.onclick = () => modalRecomendar(() => { t = 1; $$('[data-tabs="rec"] button', root)[1].click(); });
};
function modalRecomendar(done, cursoIni) {
  const tipos = ['Persona', 'Cargo', 'Área'].concat(featActiva('VTA') ? ['Empresa compradora'] : []);
  let tipo = 'Persona';
  const opciones = () => ({ Persona: empAula() === 'qhaliy' ? PERS_EXT.map((p) => p[0]) : W.map((w) => w.nombre), Cargo: PUESTOS.map((p) => p[0] + ' (' + p[2] + ')'), 'Área': AREAS.map((a) => a[0] + ' (' + a[2] + ')'), 'Empresa compradora': ['Minera Cerro Azul S.A.C.', 'Transportes Mantaro S.A.C.', 'Panificadora Don Lucho E.I.R.L.'] }[tipo]);
  const cs = cursosEmp().filter((c) => c.estado === 'Publicado');
  const ov = modal('Recomendar curso', campo('rm-c', 'Curso', 'select', (cursoIni || cs[0]).n, cs.map((c) => c.n)) +
    '<div class="field"><span>Recomendar a</span><div class="seg" data-seg="rm-t">' + tipos.map((x, i) => '<button class="' + (i ? '' : 'on') + '" data-tp="' + x + '">' + x + '</button>').join('') + '</div></div><div id="rm-d">' + campo('rm-dest', 'Destinatario', 'select', '', opciones()) + '</div>' +
    campo('rm-m', 'Mensaje', 'textarea', 'Te recomiendo este curso: te ayudará a trabajar más seguro en tu puesto.') +
    '<div class="field"><span>Canal</span><div class="row">' + [['app', 'App / portal', 1], ['correo', 'Correo', 1], ['wa', 'WhatsApp', 0]].map((x) => '<label class="row small" for="rm-' + x[0] + '"><input type="checkbox" id="rm-' + x[0] + '"' + (x[2] ? ' checked' : '') + '> ' + x[1] + '</label>').join('') + '</div><p class="tiny muted">Correo y WhatsApp solo llegan a quien dio su consentimiento.</p></div>',
  [{ l: 'Cancelar' }, { l: 'Enviar recomendación', cls: 'pri', fn: (o) => {
    const canal = [['app', 'App'], ['correo', 'Correo'], ['wa', 'WhatsApp']].filter((x) => $('#rm-' + x[0], o).checked).map((x) => x[1]).join(' y ');
    if (!canal) { toast('Elige al menos un canal', 'warn'); return false; }
    const c = cs.find((x) => x.n === $('#rm-c', o).value);
    REC_LISTA.unshift({ dest: $('#rm-dest', o).value, tipo, curso: c.id, motivo: $('#rm-m', o).value.slice(0, 80), origen: USUARIOS[S.role][0], canal, est: 'Enviada', f: '30/10/2026', emp: empAula() === 'qhaliy' ? 'qhaliy' : undefined });
    toast('Recomendación enviada por ' + canal.toLowerCase()); if (done) done();
  } }]);
  $$('[data-tp]', ov).forEach((x) => x.onclick = () => { tipo = x.dataset.tp; $$('[data-tp]', ov).forEach((y) => y.classList.toggle('on', y === x)); $('#rm-d', ov).innerHTML = campo('rm-dest', 'Destinatario', 'select', '', opciones()); });
}
