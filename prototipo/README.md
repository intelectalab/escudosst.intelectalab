# Prototipo navegable · EscudoSST

Referencia visual del producto. **Si algo del prototipo contradice a `spec/` o `docs/modulos/`, manda el documento.** Todos los datos (personas, DNI, RUC, cifras) son inventados. No hay precios: todo dice «por definir».

## Cómo abrirlo
- `prototipo/index.html` es un solo archivo autocontenido (estilos y scripts dentro; solo carga las fuentes de Google Fonts y, sin internet, usa fuentes del sistema). Se publica como Artifact o se abre con cualquier navegador.
- Fuente editable en `prototipo/src/`; para regenerar el HTML: `python3 tools/prototipo/build.py` (necesita `pip install qrcode` para dibujar los QR).
- Prueba automática: `python3 tools/prototipo/recorrer.py` (Playwright + Chromium): 61 módulos, 17 roles × 3 planes, flujos de aula y venta, AUL/VTA activos y desactivados, sitio público y panel «Sitio web» con sus flujos, los 9 extras de captación con sus flujos y la ayuda contextual, 1366 y 400 px, claro y oscuro, y el sitio v1.5 (diseño Stitch corregido, calendario de demos, chatbot, CRM, medios, portafolio, redes, campañas, ventanas emergentes, integraciones, novedades, webinars, reseñas y calculadora de ahorro) con sus flujos. `--solo-v15` corre solo el sitio, el panel y los flujos v1.5. Capturas v1.5 en la carpeta de `--capturas-v15`. Capturas en `tools/prototipo/capturas/`; las de aula y venta en la carpeta de `--capturas-aula`, las del sitio web en la de `--capturas-web` y las de los extras en la de `--capturas-extras`.

## Cómo se usa
- **Barra oscura superior (demostración)**: «Sitio público» abre la web comercial de EscudoSST (`#web`). «Ver como» cambia entre los 17 roles; «Plan» (Esencial / Profesional / Corporativo); «Complementos» (OFF, IAA, PSE, MOC, ISO, AMB, HOM); «Empresa»: para el Consultor SST, sus 4 clientes; para los demás roles de empresa, **Industrias Andinas** (aula activa, venta desactivada) o **Instituto Qhaliy** (aula y venta activas); «Tema» claro / oscuro / automático. En móvil se abre con «Opciones de demostración».
- El menú lateral se agrupa en los 8 grupos del catálogo, se colapsa con el botón ☰ y en móvil es un cajón. Los módulos que el plan no incluye se ven con candado y abren la pantalla «Disponible en el plan X». AUL y VTA, si el plan los incluye pero la empresa los apagó, se ven con la etiqueta «Desactivado» y abren «Desactivado por la empresa» (distinto del bloqueo por plan), con botón para activarlos.
- **Activación por empresa**: en «Empresas, sedes y áreas» (`#m-ten`), tarjeta «Módulos de la empresa» con los interruptores «Aula virtual» y «Venta de cursos y pagos». Apagar el aula apaga también la venta; la venta viene desactivada por defecto. En plan Esencial ambos aparecen bloqueados por plan.
- Búsqueda global (tecla `/`): módulos, trabajadores (por nombre o DNI) y acciones correctivas. Campana: centro de notificaciones.
- La elección de rol, plan y tema se recuerda en el navegador si este lo permite; el prototipo funciona igual sin eso.

## Recorridos recomendados (pantalla `#inicio`)
1. **Jefa SST** (Rocío Villanueva): panel → IPERC (editar, enviar, aprobar) → Inspecciones (ejecutar, «No cumple» → acción) → Modo Inspección SUNAFIL (autodiagnóstico, expediente, enlace, vista del inspector) → Accidentes (registrar, cuenta regresiva, árbol de causas).
2. **Trabajadora** (Carmen Mamani): app en marco de teléfono → firmar RISST y EPP → fotocheck → reportar → mi salud (con clave) → votar.
3. **Consultora** (Renzo Cárdenas, Prevencia Consultores SST): marca blanca verde azulado, 4 empresas cliente con planes distintos; tocar una fila cambia de empresa y de plan.
4. **Médica ocupacional** (Dra. Sandra Medina): EMO → abrir un trabajador → re-autenticación y aviso de registro. Comparar con «Recursos Humanos», que ve «Acceso restringido».
5. **Aula virtual y venta de cursos** (nuevo): Jefa SST → Aula virtual → curso «Trabajos en caliente» con pestañas Contenido (modo edición), Participantes (matrícula manual o por cargo, tiempo de permanencia), Calificaciones (escala del curso), Evaluaciones (bancos, azar, intentos) y Configuración (escala, secuencial, compromiso, certificado verificable, permisos del capacitador, Moodle opcional) → Banco de preguntas (importar Excel) → Recomendaciones → Capacitaciones (matriz por cargo, certificados, plantilla) → verificación pública del certificado. Desde la misma tarjeta: «Como Capacitador», «Como Trabajadora» (pestaña Cursos de la app), «Instituto con venta» (VTA en Qhaliy) y «Como Participante externo».
6. **Dueño del SaaS**: panel de la plataforma (cuentas, planes y complementos, pruebas, salud) → registro autoservicio (RUC, plan, Excel con errores por fila, línea base).
7. **Sitio público y su panel** (nuevo): `#web` → bloques de módulos con maqueta y «Ver demo» (abre el módulo en el prototipo) → planes → Libro de Reclamaciones. Luego «Panel «Sitio web»» en `#inicio` (rol Dueño de la plataforma) → escribe un precio en «Planes y precios» y vuelve a `#web-planes`; responde la hoja en «Libro de Reclamaciones»; cambia el domicilio o sube un logo en «Datos legales y marca».

8. **Extras de captación** (nuevo, `spec/brief-web-extras.md`): portada → «Herramientas gratuitas» → Calculadora de multas (No MYPE, 35 trabajadores, grave → rango en UIT y soles «referencial, a confirmar» → recibe el detalle) → Autodiagnóstico (6 secciones → puntaje, nivel → «Informe enviado») → Recursos (descarga con datos) → Boletín (confirmar y darse de baja) → Partners (solicitud). Luego, como Dueño de la plataforma: «Bandeja de leads» (llegó todo, con origen y consentimiento comercial), aprobar el partner (código de referido), cambiar la UIT en «Calculadora de multas», publicar un incidente en «Estado del servicio» y verlo en `#web-estado`, aprobar el caso «Fundo agroexportador de Ica» (adjuntar consentimiento → Aprobar → Publicar) y verlo en `#web-casos`. Dentro de la app, el botón **«Ayuda»** de la cabecera abre los artículos de la pantalla actual (por ejemplo en `#m-ipc`).

## Datos de demostración
- Empresa principal: **Industrias Andinas del Sur S.A.C.**, RUC 20518472632 (inventado, dígito verificador válido), 140 trabajadores, sedes Planta Ate, Almacén Callao y Oficina San Isidro, 10 áreas, 10 puestos, comité paritario de 6 miembros. 40 trabajadores con ficha.
- Consultora: **Prevencia Consultores SST** con 4 clientes: Industrias Andinas (Profesional + OFF, IAA, PSE), Constructora Huaylas (Corporativo), AgroExport Valle de Chincha (Esencial, Supervisor SST), Clínica Santa Rosa de Lima (Profesional + IAA, sin aula: solo capacitación presencial).
- Instituto: **Instituto Qhaliy de Seguridad Ocupacional S.A.C.**, RUC 20611748290 (inventado), plan Profesional con aula y venta activas. Vende 6 cursos con precios definidos por el instituto (no son precios de EscudoSST), tiene pedidos, cupos de empresas compradoras, pasarela en modo prueba y comprobantes con estado SUNAT simulado.
- Aula de Industrias Andinas: 6 cursos (escalas 0-20, 0-10, 0-100, AD/A/B/C y A/B/C/D), capacitador **Raúl Paredes Ugaz** (externo) y Gabriela Montoya (interna). Participante externo demo: **Luz Marina Quispe Ortiz**. Certificados de ejemplo: `CERT-2026-000142` (válido), `CERT-2026-000097` (anulado), `CERT-2026-000155` (en validación).

## Páginas públicas (sin inicio de sesión)
| Ruta | Qué muestra |
|---|---|
| `#pub-reporte` | Reporte por QR del punto «Almacén Callao – Zona de despacho»: tipo, foto, descripción, anónimo opcional |
| `#pub-verificar` | Verificación de fotocheck: escaneo simulado → vigente / con alerta / vencida / revocada, sin datos médicos |
| `#sun-inspector` | Lo que ve el inspector con el enlace del expediente: índice y documentos en solo lectura |
| `#pub-certificado` | Verificación de certificado por código (o QR): válido / anulado / en validación / no existe; sin datos médicos ni de contacto |
| `#pub-cursos` | Catálogo de cursos a la venta del Instituto Qhaliy: ficha, «También te puede interesar», carrito con cupón (`EMPRESA10`), pago simulado con tarjeta, Yape vía pasarela o PagoEfectivo (código CIP), confirmación con matrícula automática; compra de cupos para empresas |

## Sitio web público (módulo WEB, interno)
La web comercial de EscudoSST. Se ve sin iniciar sesión, con su propia cabecera y pie; la barra de demostración sigue arriba. Dominio: **escudosst.com** (editable). Cada vista actualiza `document.title`, `meta description`, Open Graph y `robots` (noindex) con lo que se edite en el panel.

| Ruta | Qué muestra |
|---|---|
| `#web` | Portada: barra de anuncio, menú con «Productos», «Sectores» y «Recursos» desplegables, héroe con 3 ventajas e ilustración propia, sellos de confianza (solo lo cierto: Ley 29783, Ley 29733, cifrado, soporte en Perú), herramientas gratuitas (calculadora, autodiagnóstico, recursos, boletín), 2 videos (miniatura dibujada y reproductor simulado), 8 módulos en tarjetas con mini-indicador y botones «Ver demo» / «Ver pantalla» (maqueta en modal), módulos por grupo, cómo funciona (4 pasos), planes, sectores, demostración (agenda con día y hora + demo interactiva), comentarios rotulados «Ejemplo», últimos artículos del blog (WordPress, de ejemplo), casos de éxito («Ejemplo»), otros productos de GRUPO DE SOLUCIONES CREATIVAS (7, rotulados «Descripción editable»), preguntas frecuentes, CTA final, aviso de cookies y botón flotante de WhatsApp |
| `#web/<sección>` | Portada desplazada a una sección: `como-funciona`, `videos`, `planes`, `demo`, `productos`, `m-sunafil`, `m-iperc`, `m-fotocheck`, `m-reportes`, `m-aula`, `m-consultora`, `m-offline`, `m-ia` |
| `#web-producto` | Los 7 diferenciadores y los 59 módulos contratables por grupo |
| `#web-planes` | Esencial, Profesional (Recomendado), Corporativo y Consultora; mensual/anual; **precio editable** («Solicitar cotización» si no hay precio); comparativa plegable por grupo (check, candado, «Complemento», «Plan Consultora»); complementos; preguntas de facturación |
| `#web-sectores`, `#web-sector-construccion`, `-mineria`, `-manufactura`, `-agroindustria`, `-oficinas` | Plantilla común: dolores, módulos clave, normativa en términos generales, ejemplo ilustrativo y CTA |
| `#web-consultoras` | Modo consultora y marca blanca; precio por empresa gestionada |
| `#web-contacto`, `/contacto`, `/cotizacion` | Tres formularios con validación (RUC con dígito verificador, correo, celular), consentimiento, captcha simulado y confirmación; lo enviado llega a «Solicitudes de demo» |
| `#web-legal-terminos`, `-privacidad`, `-cookies`, `-encargo`, `-sla` | Políticas con índice lateral, versión vigente, fecha e historial. Solo estructura: «Texto por redactar y validar con abogado» |
| `#web-reclamaciones` | Libro de Reclamaciones: proveedor (de los datos legales), consumidor, apoderado si es menor, bien, monto, reclamo o queja con su definición, detalle, pedido y aceptación → hoja con código (`LR-2026-000031`…), fecha, hora y aviso de constancia; consulta de estado por código |

### Extras de captación y confianza (módulo WEB, `spec/brief-web-extras.md`)
Desplegable **«Recursos»** en el menú (y en el cajón móvil), columna «Recursos» en el pie y bloque **«Herramientas gratuitas»** en la portada (`#web/herramientas`) con CTA a la calculadora y al autodiagnóstico; bloque «Casos de éxito» antes de los comentarios. Todos los formularios crean o actualizan un **lead** (por correo) con origen y UTM; el **consentimiento comercial** es una casilla aparte y sin marcar; captcha simulado e `Idempotency-Key` visible en la ficha del lead.

| Ruta | Qué muestra |
|---|---|
| `#web-calculadora` | Calculadora de multas SUNAFIL: tamaño (micro, pequeña, no MYPE), trabajadores afectados, gravedad o infracción de ejemplo → rango en **UIT y soles** (máximo del tramo y mínimo con descuento por subsanación), parámetros con vigencia marcados «a confirmar», aviso «referencial, no es asesoría legal», tablas plegables y «Recibe el detalle por correo» |
| `#web-autodiagnostico` | Autodiagnóstico Ley 29783 por secciones con barra de avance, puntaje total y por sección, nivel (bajo/medio/alto), formulario para el informe PDF → «Informe enviado»; etiqueta «A validar por especialista SST» mientras el panel no la quite |
| `#web-recursos` | Biblioteca con filtros por tipo, sector y búsqueda; descarga libre o con datos (modal con lead), URL firmada simulada |
| `#web-casos` | Casos publicados con reto, solución, resultados y cita, rotulados «Ejemplo» |
| `#web-comparativa` | «EscudoSST vs Excel y papel» tema por tema, sin nombrar competidores (filas editables en el panel) |
| `#web-ayuda`, `/c-<categoría>`, `/a-<artículo>` | Centro de ayuda: buscador, categorías, más útiles, artículo con video simulado, pasos, «¿Te sirvió?» (con comentario si no) y relacionados |
| `#web-partners` | Programa para consultoras, independientes e institutos: beneficios, cómo funciona (registrado → en prueba → cliente), recompensa «por definir» y solicitud |
| `#web-boletin`, `#web-boletin/baja` | Suscripción con temas → «Revisa tu correo para confirmar» → confirmación simulada; ediciones anteriores; baja con un clic |
| `#web-confianza` | Seguridad, Ley 29733 y derechos ARCO, **tabla de subencargados** (proveedor, finalidad, país, datos, transferencia), documentos y resumen del estado |
| `#web-estado` | Estado general, componentes (app, API, base médica, correo, WhatsApp, pasarela), incidentes en curso e historial con sus actualizaciones, suscripción a avisos |

«Otros productos» muestra los 7 productos de GRUPO DE SOLUCIONES CREATIVAS (Plannio, GestionaHub, Edunova, Yachay Campus, Qhatu POS, Ayni HR, TaskFlow) con la etiqueta «Descripción editable» y el enlace vacío («Enlace por definir»).

El blog vive en **WordPress, aparte**: el menú «Blog» enlaza a la URL configurada (por defecto `blog.escudosst.com`) y no hay editor de blog propio.

## Panel «Sitio web» (rol Dueño de la plataforma, `#m-web/<sección>`)
Páginas y SEO (estado, editor por bloques simulado, título, descripción con contador, URL, imagen para redes, noindex, vista previa de Google y de la tarjeta para redes; la portada se reordena y edita en vivo), Menú, **Planes y precios** (precio por plan y periodo, límites, visible, destacado, «más IGV», precios de complementos y vista previa), Videos (YouTube/Vimeo), Otros productos (logo, color, enlace), Testimonios (aprobar u ocultar), Preguntas frecuentes, Blog (URL de WordPress y «Mostrar últimos artículos»), WhatsApp (número y mensaje por página), **Políticas** (publicar versión con «requiere re-aceptación», historial, registro de aceptaciones), **Libro de Reclamaciones** (bandeja con plazo en semáforo en días hábiles, responder, prorrogar con motivo, historial inalterable, reporte para Indecopi), Solicitudes de demo (estado y responsable), Redirecciones 301 y **Datos legales y marca** (razón social, RUC, domicilio, dominio, contactos, responsable del Libro, plazos como parámetros, redes, **subir logo** o logo de ejemplo, barra de anuncio). Todo vive en memoria mientras la página está abierta.

**Captación y confianza** (segundo grupo de pestañas del panel): **Bandeja de leads** (incluye las solicitudes de demo y contacto; filtros por origen y estado, búsqueda, estado y responsable editables, ficha con actividad, consentimiento comercial con fecha, exportar CSV/Excel), **Calculadora de multas** (UIT, descuento y conservación de leads como parámetros con vigencia «a confirmar»; tablas `web.fine_table_*` editables; últimos cálculos), **Autodiagnóstico** (secciones, preguntas, pesos y referencia legal en borrador, «a validar por especialista», publicar versión; respuestas recibidas), **Recursos** (pide datos o no, estado, descargas y registro inalterable), **Casos de éxito** (adjuntar consentimiento → aprobar → publicar u ocultar), **Centro de ayuda** (artículos con código de pantalla para la ayuda contextual, categorías, opiniones), **Partners y referidos** (aprobar o rechazar, código de referido, regla de recompensa por definir, referidos y su estado), **Boletín** (ediciones: redactar, programar, enviar; métricas; suscriptores con doble confirmación y bajas), **Subencargados** y **Estado del servicio** (estado de cada componente, publicar incidente y agregar actualizaciones inalterables). La página «EscudoSST vs Excel y papel» se edita en Páginas (tipo comparison). Los permisos especiales nuevos aparecen junto a cada acción.

**Ayuda contextual**: botón «Ayuda» en la cabecera de la app (solo icono en móvil). Abre un cajón con los artículos publicados cuyo código de pantalla coincide con la pantalla actual (`screen_code`, por ejemplo IPC, INS, SUN, CAP, AUL), los más consultados, el artículo dentro del cajón con «¿Te sirvió?» y el enlace al centro de ayuda.

### Sitio v1.5: diseño Stitch corregido y funciones nuevas (`spec/brief-web-v15.md`)
**Lenguaje visual** tomado de `spec/diseno-stitch/` («Compliance Shield»): títulos #00254D, acción #1F7FD1, fondo #F7FAFE/#EEF1F5, tarjetas blancas de radio 12 px con sombra azulada, rótulos en mayúsculas sobre cada sección, bordes izquierdos de 4 px en alertas, franja diagonal ámbar (héroe, CTA final, pie), cabecera translúcida, Lexend + Atkinson Hyperlegible (Next). Íconos SVG propios; sin imágenes externas ni Tailwind. Modo oscuro con los mismos componentes.

**Portada** (orden de Stitch): héroe con la tarjeta «Modo Inspección SUNAFIL» (empresa ficticia, % del expediente, 8 registros obligatorios, rotulada «Ejemplo») → 4 sellos veraces → videos → 8 módulos en tarjetas con mini-indicador ilustrativo, «Ver demo» y «Ver pantalla» (maqueta en modal) → todos los módulos → cómo funciona (4 pasos, «Regístrate con tu RUC») → planes → 6 herramientas gratuitas → sectores con normas de referencia «a validar» (de `docs/11`) → agenda de demo + demo interactiva → casos → comentarios → **Reseñas de Google** (atribución, filtro por calificación y cantidad, rotuladas «Ejemplo») → otros productos → blog → FAQ → CTA final.

**Correcciones aplicadas a Stitch**: sin «homologada», ISO 27001, SLA 99.9 %, AES-256 como sello, «Real-time audit», «Auditoría aprobada», «Cero multas», «Protección 24/7», tiempos prometidos, «14 días gratis», «sin tarjeta», descuentos, «Plan Consultor» (es Consultora), rangos inventados, fechas 2025, empresas reales, normas dudosas, «conexión directa con SUNAT» ni grabación automática. Consentimiento comercial aparte y sin marcar en todos los formularios; grabación solo con consentimiento expreso; anfitriones como «asesores comerciales / especialistas SST» con nombres de ejemplo.

| Ruta | Qué muestra |
|---|---|
| `#web/demo` | Agenda pública: días y horas **libres según anfitriones, bloqueos, duración, margen, anticipación y reuniones ya tomadas** → confirmación con enlace de reunión y «Reprogramar o cancelar» |
| `#web-demo/<token>` | Gestionar la reserva (enlace seguro): estado, enlace, reprogramar con horarios libres o cancelar; noindex |
| Widget «Asistente» (todas las páginas) | Chatbot guiado (o IA solo con contenido publicado): intenciones con botones (demo, WhatsApp, calculadora, enlace), horario, pide datos con consentimiento y crea el lead `chatbot` |
| Ventana emergente | Por intención de salida, una vez por visita, se cierra con Esc; nunca en Libro de Reclamaciones, políticas, formularios ni landings |
| `#web-l/<slug>` | Landing de campaña: noindex, UTM de la campaña, formulario → lead `campaign` |
| `#web-integraciones` | Integraciones con estado veraz (Disponible / Beta / Planificada) |
| `#web-novedades` | Novedades del producto por categoría y módulo; también en la app (botón «Novedades» de la cabecera) |
| `#web-webinars` | Próximos con cupos e inscripción con consentimiento (lead `webinar`); grabados solo si se informó a ponente y asistentes |
| `#web-calculadora-ahorro` | Horas (y soles si se indica el costo) frente a Excel y papel, supuestos visibles, «estimación referencial, no garantizada», detalle por correo (lead `savings_calculator`) |

**Panel «Sitio web»** (ahora abre en **Resumen**; secciones agrupadas en General, Sitio, Agenda y CRM, Captación y confianza, Producto):
- **Resumen** (`#m-web/resumen`): visitas y visitantes (30 días, gráfico), fuentes, dispositivos, páginas más vistas, conversiones por formulario y CTA, tasa de conversión, leads del mes, demos pendientes, reclamos por vencer con semáforo, campañas con sus métricas, editor SEO con vista de Google, nota «qué mide el sitio y qué mide el CRM» e IDs de GA4/GTM/Meta Pixel/Search Console (solo con consentimiento). Datos de ejemplo + eventos de la sesión.
- **Calendario de demos**: mes, semana, día y lista; filtro por anfitrión con colores; solicitudes entrantes; modal «Confirmar demo y asignar anfitrión» (disponibilidad de cada uno, sala Meet/Teams/teléfono, grabación sin marcar) → «Demo confirmada» con enlace y recordatorios 24 h y 1 h; reprogramar, cancelar y resultado; anfitriones con horario semanal y conexión de calendario (referencia a secreto); bloqueos; reglas de agendamiento.
- **Chatbot**: activar, modo guiado/IA, saludo, horario, mensaje fuera de horario, derivación a WhatsApp, intenciones (publicar) y conversaciones.
- **Conexión con CRM**: Kallpa CRM o webhook, URL, llave como referencia a secreto, probar conexión, entidades, mapeo, cola con reintentos, errores e idempotencia; el Libro de Reclamaciones nunca se envía.
- **Medios**: subir (texto alternativo obligatorio), carpetas, filtros, dónde se usa, reemplazar sin romper enlaces; el portafolio y el logo eligen de aquí.
- **Portafolio**: año, módulos usados y galería de cada caso; se ve en `#web-casos`.
- **Redes sociales**: Facebook, Instagram, LinkedIn, TikTok, YouTube y X con íconos en el pie y en contacto.
- **Campañas**, **Ventanas emergentes**, **Calculadora de ahorro**, **Webinars** (inscritos, asistencia, grabación), **Reseñas de Google** (conexión, calificación mínima, cantidad, caché; sin elegir reseñas a mano), **Novedades** e **Integraciones**.

## Otras pantallas especiales
| Ruta | Pantalla |
|---|---|
| `#inicio` | Recorrido del prototipo, acceso al sitio público y mapa de los 61 módulos |
| `#app-trabajador` | App del trabajador en marco de teléfono; pestaña «Cursos»: estudiar con candados, aceptar el compromiso, rendir la evaluación, certificado y «Recomendados para ti» |
| `#registro` | Registro autoservicio en 4 pasos |
| `#m-aul/CUR-014` | Un curso por dentro (cualquier código de curso) |
| `#aul-banco` | Banco de preguntas: bancos generales y de curso, importación desde Excel con errores por fila |
| `#aul-recomendaciones` | Recomendación de cursos: reglas automáticas, sugerencias de IA (con IAA), «Recomendar curso» a persona, cargo, área o empresa compradora, y conversión (enviadas, vistas, clics, matriculados o comprados) |
| `#capacitador` | Panel del capacitador: mis cursos, por calificar, disponibilidad semanal y fechas bloqueadas, mis permisos |
| `#alumno-cursos`, `#alumno-certificados`, `#alumno-compras` | Portal del participante externo con «Recomendados para ti» |

## Mapa de pantallas (61 módulos)
Con el rol sugerido, plan Corporativo + todos los complementos y AUL/VTA activados, todas abren sin candado.

| Código | Módulo | Plan | Ruta | Rol sugerido | Pantalla |
|---|---|---|---|---|---|
| TEN | Empresas, sedes y áreas | Esencial | `#m-ten` | Jefe SST | Lista con filtros + ficha lateral + formulario; «Módulos de la empresa» (activar AUL y VTA) |
| USR | Usuarios, roles y permisos | Esencial | `#m-usr` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| TRB | Trabajadores y legajo | Esencial | `#m-trb` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| DOC | Gestión documental | Esencial | `#m-doc` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| NOT | Alertas y notificaciones | Esencial | `#m-not` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| DSH | Dashboard e indicadores | Esencial | `#m-dsh` | Jefe SST | Panel a medida (Jefe SST); como Gerencia, panel ejecutivo |
| FIR | Firma electrónica de registros | Profesional | `#m-fir` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| AUD | Bitácora de auditoría | Esencial | `#m-aud` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| SUS | Suscripciones y facturación | Interno | `#m-sus` | Dueño de la plataforma | A medida (rol Dueño de la plataforma): cuentas, planes, pruebas, salud |
| WEB | Sitio web público y CMS | Interno | `#m-web` | Dueño de la plataforma | A medida: panel «Sitio web» (Resumen + 14 secciones + 10 de captación y confianza + 13 de v1.5); sitio público `#web`, 10 páginas de captación y 6 de v1.5 |
| LBA | Diagnóstico de línea base | Esencial | `#m-lba` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| POL | Política y objetivos SST | Esencial | `#m-pol` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| MRL | Matriz de requisitos legales | Profesional | `#m-mrl` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| PLA | Plan Anual SST | Esencial | `#m-pla` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| PAS | Programa Anual SST (PASST) | Esencial | `#m-pas` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| PCA | Programa anual de capacitación | Esencial | `#m-pca` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| RIS | RISST | Profesional | `#m-ris` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| COM | Comité o Supervisor SST | Esencial | `#m-com` | Jefe SST | A medida: comité, elección electrónica (5 etapas), actas |
| PRE | Presupuesto SST | Corporativo | `#m-pre` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| IPC | Matriz IPERC | Esencial | `#m-ipc` | Jefe SST | A medida: filas editables, versiones, enviar → aprobar |
| MAP | Mapa de riesgos | Profesional | `#m-map` | Jefe SST | A medida: plano SVG con pictogramas |
| MAG | Monitoreo de agentes | Profesional | `#m-mag` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| PSI | Riesgos ergonómicos y psicosociales | Corporativo | `#m-psi` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| PET | PETS y procedimientos | Corporativo | `#m-pet` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| MOC | Gestión del cambio | Complemento | `#m-moc` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| INS | Inspecciones | Esencial | `#m-ins` | Jefe SST | A medida: checklist en marco de teléfono, foto, hallazgo → acción; banda «sin conexión» con OFF |
| ACT | Actos y condiciones subestándar | Esencial | `#m-act` | Jefe SST | A medida: bandeja, puntos QR, simulación de WhatsApp; página pública `#pub-reporte` |
| CH5 | Charlas de 5 minutos | Profesional | `#m-ch5` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| ATS | ATS | Profesional | `#m-ats` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| PTR | PETAR y permisos de alto riesgo | Corporativo | `#m-ptr` | Jefe SST | A medida: requisitos de ejecutantes, aprobación en cadena, suspender/cerrar |
| OBS | Observación de conducta segura | Corporativo | `#m-obs` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| EPP | EPP | Esencial | `#m-epp` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| EQE | Equipos de emergencia y botiquines | Profesional | `#m-eqe` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| SIM | Simulacros y brigadas | Profesional | `#m-sim` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| CAP | Capacitaciones e inducciones | Esencial | `#m-cap` | Jefe SST | A medida: capacitaciones, matriz por cargo con semáforo, certificados (validar/anular), plantilla con QR; verificación `#pub-certificado` |
| AUL | Aula virtual SST | Profesional | `#m-aul` | Jefe SST | A medida: cursos; curso por dentro (contenido, participantes, calificaciones, evaluaciones, configuración); banco de preguntas; recomendaciones; Moodle opcional |
| FOT | Fotocheck SST con QR | Profesional | `#m-fot` | Jefe SST | A medida: requisitos en semáforo, credencial con QR; verificación pública `#pub-verificar` |
| EMO | EMOs y aptitud | Profesional | `#m-emo` | Jefe SST | A medida: aptitud; como Médico ocupacional pide re-autenticación |
| VMD | Vigilancia médica | Corporativo | `#m-vmd` | Médico ocupacional | Lista con filtros + ficha lateral + formulario |
| DES | Descansos médicos y ausentismo | Corporativo | `#m-des` | Médico ocupacional | Lista con filtros + ficha lateral + formulario |
| VUL | Grupos vulnerables | Corporativo | `#m-vul` | Médico ocupacional | Lista con filtros + ficha lateral + formulario |
| VTA | Venta de cursos y pagos | Profesional | `#m-vta` | Jefe SST (empresa Instituto Qhaliy) | A medida: cursos a la venta y cupones, pedidos, cupos para empresas, pasarela, comprobantes, reportes; catálogo público `#pub-cursos` |
| ACC | Accidentes, incidentes y enfermedades | Esencial | `#m-acc` | Jefe SST | A medida: registro en 4 pasos, cuenta regresiva, árbol de causas |
| INV | Investigación de causas | Profesional | `#m-inv` | Jefe SST | A medida: árbol de causas y equipo |
| ACR | Acciones correctivas | Esencial | `#m-acr` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| AUI | Auditorías | Profesional | `#m-aui` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| EST | Estadísticas SST | Esencial | `#m-est` | Jefe SST | Lista + gráfico de índices |
| RVD | Revisión por la dirección | Corporativo | `#m-rvd` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| REG | Registros obligatorios | Esencial | `#m-reg` | Jefe SST | A medida: 9 registros, periodo, vista previa del formato, PDF/Excel |
| CTR | Acreditación de contratistas | Corporativo | `#m-ctr` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| SCT | Control de SCTR | Profesional | `#m-sct` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| ING | Ingreso de terceros por QR | Corporativo | `#m-ing` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| HOM | Homologación de proveedores | Complemento | `#m-hom` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| SUN | Modo Inspección SUNAFIL | Profesional | `#m-sun` | Jefe SST | A medida: autodiagnóstico, expediente con progreso, enlace; vista del inspector `#sun-inspector` |
| OFF | App móvil offline | Complemento | `#m-off` | Jefe SST | Dispositivos y conflictos de sincronización |
| IAA | Asistente IA | Complemento | `#m-iaa` | Jefe SST | A medida: sugerencias IPERC aceptar/editar/descartar; investigación guiada |
| CON | Modo consultora y marca blanca | Consultora | `#m-con` | Consultor SST | A medida: tabla de clientes; como Consultor SST aplica marca blanca |
| PSE | Paquetes sectoriales | Complemento | `#m-pse` | Jefe SST | A medida: catálogo de 7 sectores y activación |
| ISO | Sistema integrado ISO | Complemento | `#m-iso` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| AMB | Medio ambiente (SSOMA) | Complemento | `#m-amb` | Jefe SST | Lista con filtros + ficha lateral + formulario |
| API | API e integraciones | Corporativo | `#m-api` | Jefe SST | Lista con filtros + ficha lateral + formulario |

## Qué es simulado
- No hay servidor: nada se guarda ni se envía. Los cambios (filas de IPERC, votos, acciones creadas) viven solo mientras la página está abierta.
- Descargas PDF/Excel, envíos por correo/WhatsApp, firma, fotos, carga de Excel, búsqueda en SUNAT, sincronización offline y sugerencias de IA muestran el resultado esperado con un aviso, sin hacerlo de verdad.
- El QR del fotocheck y del cartel se dibujan al construir el HTML (no apuntan a un servicio real).
- Extras de captación: los correos (detalle de la multa, informe PDF, confirmación y baja del boletín, avisos de estado, ediciones por Elastic Email), las descargas con URL firmada y los videos de ayuda son simulados. Las tablas de multas, la UIT y el descuento por subsanación son **valores de ejemplo con vigencia, a confirmar por especialista legal**; nunca se presentan como oficiales. El cuestionario inicial está «a validar por especialista SST». Casos, partners, leads y suscriptores son de ejemplo.
- Sitio v1.5: métricas, reseñas, anfitriones, campañas y conversaciones son de ejemplo; enlaces de Meet/Teams, invitaciones, recordatorios, conexión de calendario y de Google, envíos al CRM y respuestas de IA son simulados. Todo vive en memoria (sin localStorage).
- Sitio web: formularios, captcha, WhatsApp, videos, envío de constancias, subida de imágenes para redes, artículos del blog y reporte para Indecopi son simulados. Los precios de EscudoSST no están definidos: todo dice «Solicitar cotización» salvo lo que se escriba en el panel durante la demo. Comentarios y otros productos son de ejemplo y van rotulados. Los textos legales son solo estructura.
- Valores legales (plazo de notificación, factores de índices, límites permisibles, umbrales) aparecen subrayados en ámbar: son **parámetros con vigencia, a confirmar por especialista SST/legal**.
- Los montos del módulo Presupuesto aparecen como «S/ —».
- Venta de cursos: pasarela, Yape, PagoEfectivo, webhooks y comprobantes electrónicos son simulados. Nunca se muestran ni guardan datos de tarjeta; las llaves aparecen enmascaradas o como referencia a un secreto. Los precios de los cursos son datos de la empresa demo, no precios de EscudoSST.
- Aula virtual: los videos «de Vimeo» son un reproductor simulado; el tiempo de permanencia y las sesiones de actividad son datos de ejemplo. El QR del certificado se dibuja al construir el HTML.

## Estructura del código
- `src/styles.css`: tokens de color (claro/oscuro, marca blanca) y componentes.
- `src/data.js`: datos de demostración. `src/app.js`: estado, permisos por rol y plan, menú, búsqueda, notificaciones, modales, toasts y el renderizador genérico `pantalla()`.
- `src/m-aula.js` (aula virtual, curso por dentro, banco de preguntas, capacitador, estudio del curso, recomendaciones), `src/m-cap.js` (capacitaciones, matriz por cargo, certificados y verificación pública), `src/m-ventas.js` (venta de cursos, catálogo público con pago, portal del participante externo).
- Sitio v1.5: `src/m-web-v15-datos.js` (estado `V15`: métricas, anfitriones, reuniones, chatbot, CRM, medios, campañas, ventanas, integraciones, novedades, webinars, reseñas, ahorro), `src/m-web-v15.js` (portada Stitch, agenda con horarios libres, gestión de la reserva, chatbot, ventana emergente, páginas nuevas), `src/m-web-v15-panel.js` (14 secciones nuevas del panel) y `src/web-v15.css` (tokens y componentes «Compliance Shield», también para el panel).
- `src/m-web-datos.js` (estado editable del sitio: ajustes legales, planes y precios, menú, bloques, políticas, reclamos, demos, videos, productos, testimonios, WhatsApp), `src/m-web.js` (sitio público, ilustraciones SVG, maquetas de pantalla, formularios, Libro de Reclamaciones, SEO), `src/m-web-panel.js` (panel «Sitio web»), `src/web.css` (estilos del sitio y del panel). Extras de captación: `src/m-web-extras-datos.js` (estado `WX`: leads, parámetros y tablas de multas, cuestionario, recursos, casos, ayuda, partners, boletín, subencargados, estado; 7 productos), `src/m-web-extras.js` (10 páginas públicas, bloques de la portada, ayuda contextual), `src/m-web-extras-panel.js` (10 secciones del panel y filas de la comparativa) y `src/web-extras.css`.
- `src/m-inicio.js` (recorrido y dashboards), `src/m-campo.js` (IPERC, inspecciones, reportes, PETAR), `src/m-personas.js` (fotocheck, EMO, comité, accidentes, investigación, registros), `src/m-diferenciadores.js` (SUNAFIL, consultora, app del trabajador, IA, paquetes, offline, panel del dueño, registro), `src/m-modulos.js` (resto de módulos por configuración).
- La visibilidad por rol está en `ROL_MODULOS` (`data.js`) y es solo ilustrativa: la matriz de permisos real es la de `docs/` y la base de datos.
