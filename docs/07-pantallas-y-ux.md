# 07 · Pantallas y UX

Referencia visual: `prototipo/`. Si el prototipo contradice este documento o el `M-<COD>.md` del módulo, mandan los documentos. El detalle de campos y reglas de cada pantalla está en `docs/modulos/M-<COD>.md`.

## 1. Sistema de diseño

Estilo: el del wireframe aprobado por el dueño del producto — menú lateral azul marino, fondo gris claro, tarjetas blancas planas, indicadores con números grandes de color y gráficos en azul, amarillo, turquesa y naranja. Amigable e intuitivo, menú lateral adaptable, nada de menú superior rígido.

### 1.1 Color (tokens)

| Token | Uso | Valor sugerido |
|---|---|---|
| `--color-primary` | Botones principales, enlaces «Detalles», gráficos de barras | Azul `#1F7FD1` |
| `--color-primary-dark` | Menú lateral | Azul marino `#0D3B6E` (activo: fondo blanco con texto azul marino) |
| `--color-primary-soft` | Fondos de selección, chips | Azul claro `#E3EFFB` |
| `--color-bg` | Fondo de página / tarjetas | Gris claro `#EEF1F5` / tarjetas blancas `#FFFFFF` con borde `#E0E5EB` |
| `--color-accent` | Alertas de seguridad, barras de avance | Amarillo `#F4B90F` |
| `--color-ok` | Semáforo verde: vigente / cumple | `#22A35A` |
| `--color-warn` | Semáforo amarillo: por vencer / en proceso | `#F4B90F` |
| `--color-danger` | Semáforo rojo: vencido / no cumple | `#E2453C` |
| `--color-orange` | Indicadores y gráficos (pendientes, sin asignar) | `#F08A24` |
| `--color-teal` | Indicadores y gráficos (series secundarias) | `#1FB5A3` |
| `--color-na` | Semáforo gris: no aplica | `#8A94A3` |
| `--color-text` | Texto | `#262B33` (secundario `#6B7684`) |

Reglas:
- El color **nunca** es la única señal. El semáforo siempre va con texto o icono («Vigente», «Vence en 5 días», «Vencido»).
- Contraste mínimo WCAG AA.
- Marca blanca (CON) cambia `--color-primary*` y el logo. El semáforo y el ámbar de seguridad no se cambian.
- Modo oscuro: no en V1 para la aplicación. El sitio web público (WEB) sí ofrece modo claro y oscuro (ver §1.4).

### 1.2 Tipografía y tamaños
- Sans serif legible (Inter o similar). Base 16 px; tablas 14 px mínimo.
- Botones: 40 px de alto en gestión; **48 px mínimo** en la vista del trabajador y campo.
- Iconos con etiqueta. Sin iconos solos en acciones importantes.

### 1.3 Componentes base
Botón, campo, selector con búsqueda, fecha (dd/mm/aaaa), tabla con filtros, tarjeta, chip de estado, semáforo, pestañas, cajón lateral, modal, asistente por pasos, carga de archivos, captura de foto, firma, línea de tiempo, gráfico, estado vacío, aviso de plan bloqueado, toast.

Librería: componentes propios sobre una base accesible (Radix o shadcn/ui) con Tailwind. Íconos: Lucide.

### 1.4 Sitio web público (versión marketing)

El sitio público (módulo WEB, `docs/modulos/M-WEB.md`) usa la misma paleta en versión marketing:

- **Más aire:** secciones con espaciado amplio, ancho de lectura limitado, titulares grandes y una idea por bloque; cada sección termina en un llamado a la acción claro («Prueba gratis», «Agenda una demo»).
- **Ilustraciones SVG propias** con la paleta (azul marino, azul, amarillo de seguridad, turquesa); **sin fotos de personas reales** ni bancos de imágenes. SVG optimizados, con `title`/texto alternativo, y decorativos con `aria-hidden`.
- **Móvil primero:** se diseña desde 360 px; menú en cajón; tablas comparativas que en móvil pasan a tarjetas por plan con acordeón por grupo de módulos; formularios en una columna con botones de 48 px.
- **Modo claro y oscuro** con tokens redefinidos (`prefers-color-scheme` y selector manual); el semáforo y el amarillo de seguridad conservan su significado en ambos.
- **Accesible:** contraste AA en ambos modos, foco visible, navegación por teclado, «Saltar al contenido», jerarquía de encabezados correcta, formularios con etiquetas y errores anunciados; el banner de cookies no bloquea el lector de pantalla y ofrece «Aceptar» y «Rechazar» con el mismo peso.
- **Rendimiento:** Core Web Vitals como criterio de aceptación (umbrales parametrizados); fuentes con `font-display: swap`, imágenes con tamaños declarados, sin scripts no necesarios antes del consentimiento.
- **Estructura de portada** (referencia sell-saas.com; aspecto, menú completo del kit y correcciones obligatorias en §1.5): barra de anuncio; cabecera con logo editable, menú «Productos» desplegable, «Cómo funciona», «Planes», «Iniciar sesión», «Agenda una demo» y «Prueba gratis» (primario); héroe con ventajas en viñetas; videos (miniatura con botón de reproducir); bloques de módulos alternando texto y **maqueta visual de la pantalla** (SVG o captura estilizada del producto con datos ficticios) con «Probar gratis» y «Ver demo»; cómo funciona en pasos numerados; planes y precios; demostración; formulario; otros productos en tarjetas con logo; FAQ en acordeón; testimonios (los de demo con rótulo «ejemplo» visible); sellos de confianza solo verificados; CTA final a todo el ancho.
- **Botón flotante de WhatsApp:** abajo a la derecha, 56 px, con etiqueta accesible («Escríbenos por WhatsApp»), por encima del contenido pero sin tapar el banner de cookies ni la barra de CTA en móvil; el mensaje cambia por página.
- **Últimos artículos del blog (WordPress):** tarjetas con imagen, título, fecha y «Leer en el blog» (abre el blog externo); si no hay datos, el bloque no se muestra.
- **Pie en todas las páginas:** logo, datos del titular desde `site_settings`, enlaces a políticas, preferencias de cookies, redes, otros productos y **Libro de Reclamaciones** (con su ícono reconocible).
- **Precios:** los que estén configurados en los planes o «Solicitar cotización»; nunca un monto escrito en la plantilla.

### 1.5 Sistema visual del sitio: diseño Stitch corregido

El dueño entregó un diseño hecho en Google Stitch (`spec/diseno-stitch/`: landing de escritorio y móvil, resumen del panel, solicitudes de demo con calendario, modal para confirmar demo y confirmación con sala de reunión; sistema «Compliance Shield» en `DESIGN.md`). **La landing y el panel «Sitio web» deben parecerse a ese diseño, con las correcciones de esta sección.** Las capturas son referencia visual; sus textos no se copian. Aplica al sitio público y al panel del dueño; la aplicación de empresas sigue con §1.1 a §1.3.

**Tokens del sitio** (CSS custom properties con prefijo `--site-`, redefinidos en modo oscuro):

| Token | Valor | Uso |
|---|---|---|
| `--site-navy` | `#0D3B6E` | Azul institucional: cabecera del panel, franjas, botón secundario institucional |
| `--site-navy-title` | `#00254D` | Titulares sobre fondo claro |
| `--site-action` | `#1F7FD1` (hover `#176BB3`) | Solo elementos interactivos: CTA primario, enlaces, foco, paginación activa |
| `--site-bg` / `--site-surface` | `#EEF1F5` / `#F7FAFE` | Fondo de página y de secciones alternas |
| `--site-card` | `#FFFFFF` | Tarjetas; borde `1px solid rgba(13,59,110,.06)` |
| `--site-amber` | `#F4B90F` | Advertencias, franja diagonal de seguridad, «por vencer» |
| `--site-green` | `#22A35A` | Cumple, confirmado, WhatsApp |
| `--site-red` | `#E2453C` | Vencido, crítico, destructivo |
| `--site-teal` | `#1FB5A3` | Métricas y series secundarias |
| `--site-text` / `--site-text-2` | `#262B33` / `#6B7684` | Texto principal y secundario |
| `--site-border-input` | `#D5DCE5` | Borde de campos |

| Elemento | Regla |
|---|---|
| Tipografía | Lexend en titulares (display 44/52 px escritorio, 32/40 px móvil; títulos 32, 24, 20 y 16 px) y Atkinson Hyperlegible Next en textos y controles (18, 15 y 13 px; etiquetas 14 y 12 px). Fuentes **alojadas en el proyecto** (`next/font` o archivos propios con `font-display: swap`), nunca cargadas de un CDN en producción. Mayúsculas solo en etiquetas pequeñas de estado |
| Rejilla | 12 columnas desde 1024 px (margen 2 rem, canal 1.5 rem, ancho máximo 1360 px); 8 columnas en tableta; 4 en móvil (margen y canal 1 rem); ritmo de 8 px |
| Elevación | Nivel 0 sin sombra; tarjetas `0 4px 20px -2px rgba(13,59,110,.08)`; desplegables y hover `0 8px 24px -4px rgba(13,59,110,.12)` con subida de 2 px; modales `0 16px 40px -8px rgba(13,59,110,.20)` con fondo `rgba(13,59,110,.45)` y desenfoque de 4 px |
| Formas | Tarjetas 12 px; botones y campos 8 a 10 px; chips 6 px; píldoras solo en avatares y contadores |
| Botones | Primario `--site-action` con texto blanco, `12px 24px`, activo `scale(.98)`, foco con anillo de 2 px desplazado 2 px; institucional `--site-navy`; contorno; destructivo `--site-red` |
| Campos | 44 px de alto (48 px en móvil), borde `--site-border-input`, foco `--site-action` con halo `0 0 0 3px rgba(31,127,209,.15)`; casillas de 18 px |
| Alertas | Tarjeta con borde izquierdo de 4 px del color del estado (ámbar, rojo, verde) y siempre texto o ícono; nunca solo color |
| Chips | 24 px de alto: cumple (verde 12 % de fondo, texto `#187A42`), advertencia (ámbar, texto `#8A6704`), crítico (rojo, texto `#B3261E`) |
| Franja de seguridad | Diagonal ámbar sobre azul institucional en el borde de banners destacados (sin cuenta regresiva ni urgencia falsa) |
| Íconos | SVG propios del proyecto (o un set SVG empaquetado en el código); nunca una fuente de íconos externa |
| Imágenes | Ilustraciones y capturas propias desde la biblioteca de medios (SCR-WEB-65); datos de ejemplo ficticios y rotulados «Ejemplo»; nunca URLs temporales de Stitch |
| Modo oscuro | Mismos tokens redefinidos (`prefers-color-scheme` y selector manual); el ámbar y el semáforo conservan su significado |

**Correcciones obligatorias al diseño de Stitch** (detalle y cómo se hacen cumplir en `M-WEB.md` §7 «Lineamientos de contenido y diseño»):
1. **Sellos veraces.** Fuera «Plataforma homologada Ley 29783», «ISO 27001», «SLA 99.9%», «AES-256» como certificación, «Real-time audit», «¡Auditoría SUNAFIL aprobada!», «Cero multas», «blindar» y «Protección continua 24/7». Solo los sellos del kit: pensado para la Ley 29783, datos según Ley 29733, datos cifrados en la nube, soporte desde Perú.
2. **Sin tiempos prometidos** («Tiempo estimado 2/5/10 minutos», «Implementación en horas», «Configuración guiada en 15 min», «sesión de 25 minutos»); la duración de la demo sale del panel.
3. **Sin condiciones comerciales no decididas** («Prueba gratis 14 días», «Sin tarjeta de crédito», «Sin permanencia», «Soporte prioritario CIP», «Descuento anual», «Facturación semestral»); precio editable del panel o «Solicitar cotización».
4. **Planes del kit:** Esencial, Profesional («Recomendado»), Corporativo y **Consultora** (no «Consultor»); rangos y módulos desde la configuración de planes, rotulados «referencial» mientras no se confirmen.
5. **Fechas actuales:** nada de «SUNAFIL 2025» ni «Nov 2025»; el blog muestra las fechas reales de WordPress.
6. **Sin empresas reales** como ejemplo (p. ej. nombres de clínicas o empresas existentes): datos ficticios marcados «Ejemplo».
7. **Normas por sector** solo como referencia «a validar» y solo si están en `11`; fuera referencias dudosas.
8. **Registro con RUC:** «Regístrate con tu RUC: completa razón social y dirección con tu RUC» en lugar de «Conexión directa con SUNAT».
9. **Demo:** privacidad obligatoria y consentimiento comercial aparte sin marcar; grabación solo con consentimiento expreso (fuera «grabación automática activada»).
10. **Menú y pie del kit:** Productos, Cómo funciona, Planes, Sectores, Consultoras, Recursos, Blog (WordPress), Contacto; pie con Libro de Reclamaciones, políticas, preferencias de cookies, «Otros productos» y datos legales de GRUPO DE SOLUCIONES CREATIVAS SAC; barra de anuncio y aviso de cookies.
11. **Panel con un solo nombre:** «EscudoSST · Panel de la plataforma» (sin «Enterprise Hub» ni «Backoffice B2B»); anfitriones como asesores comerciales o especialistas SST (sin afirmar «ingenieros colegiados»); plazos del Libro de Reclamaciones desde parámetros.
12. **Técnicas:** sin desbordes horizontales en móvil (Stitch desborda a 390 px; se prueba de 320 a 390 px), cabecera fija con `scroll-margin-top` para que no tape las anclas, botones de WhatsApp y chatbot que no tapan contenido, íconos SVG propios, imágenes propias, sin Tailwind por CDN en producción (estilos compilados en el proyecto).

**Pantallas de Stitch → pantallas del kit:** landing de escritorio y móvil → SCR-WEB-01; resumen del panel → SCR-WEB-19 y SCR-WEB-50; solicitudes de demo y calendario → SCR-WEB-26 y SCR-WEB-54; modal confirmar demo → SCR-WEB-55; confirmación con sala de reunión → SCR-WEB-52.

## 2. Navegación

- **Cabecera:** logo (o marca blanca), selector de empresa (si tiene varias), buscador global, campana de notificaciones, ayuda, avatar.
- **Menú lateral** por grupos del catálogo, colapsable:
  1. Inicio (dashboard)
  2. Planificación (LBA, POL, MRL, PLA, PAS, PCA, RIS, COM, PRE)
  3. Riesgos (IPC, MAP, MAG, PSI, PET, MOC)
  4. Operación en campo (INS, ACT, CH5, ATS, PTR, OBS, EPP, EQE, SIM)
  5. Personas y salud (CAP, AUL, FOT, EMO, VMD, DES, VUL, VTA)
  6. Eventos y mejora (ACC, INV, ACR, AUI, EST, RVD, REG)
  7. Contratistas (CTR, SCT, ING, HOM)
  8. Modo SUNAFIL (SUN) — destacado
  9. Herramientas (IAA, PSE, ISO, AMB, OFF)
  10. Configuración (TEN, USR, TRB, DOC, NOT, FIR, AUD, API, SUS)
  - WEB no aparece en el menú de las empresas: vive en el panel del dueño (`(owner)`, «Sitio web») y en el sitio público.
- Solo aparecen módulos con permiso. Los no contratados aparecen con candado (ver §3.7) si el rol podría usarlos. AUL y VTA desactivados por la empresa (`disabled_by_company`) no aparecen ni muestran candado.
- **Móvil:** el menú lateral pasa a cajón (hamburguesa). Barra inferior con 4 accesos según rol.
- Migas de pan en fichas. Título de página = nombre de la pantalla.

## 3. Patrones

### 3.1 Lista con filtros
- Barra superior: búsqueda, filtros frecuentes como chips (sede, estado, responsable, periodo), «Más filtros» en cajón.
- Filtros en la URL (compartibles). Se recuerda el último filtro por usuario.
- Tabla en escritorio; tarjetas en móvil.
- Acciones: «Nuevo» (primario), exportar Excel/PDF, acciones masivas cuando apliquen.
- Paginación por cursor. Columna de estado con chip.

### 3.2 Ficha
- Cabecera: título, chip de estado, acciones según estado y permiso (las transiciones de `06` como botones).
- Pestañas: Resumen · Detalle · Evidencias · Firmas · Historial.
- Historial: línea de tiempo desde `audit_logs`.
- Panel lateral con vínculos (acciones correctivas, documentos, trabajadores relacionados).

### 3.3 Asistentes
- Pasos numerados con progreso. Guardado automático como borrador.
- «Atrás» nunca pierde datos. Resumen final antes de confirmar.
- Usos: línea base, elección del comité, carga masiva, PETAR, expediente SUNAFIL, onboarding, investigación.

### 3.4 Firma
- Firma simple con evidencias (ver `09`): trazo en pantalla o confirmación con clave/PIN, más datos de contexto.
- Muestra qué se firma (resumen o documento) antes de firmar.
- Firma en lote para el trabajador (varios cargos pendientes) con confirmación explícita por ítem.
- Después de firmar: comprobante descargable.

### 3.5 Carga de evidencias
- Arrastrar y soltar en escritorio; cámara directa en móvil.
- Vista previa, progreso, reintento. Tipos y tamaño permitidos visibles.
- Fotos pendientes en offline con indicador «por subir».
- Archivos médicos con aviso «Dato sensible: solo personal de salud».

### 3.6 Estados vacíos
Cada lista vacía explica para qué sirve y qué hacer:
- Título claro, una frase de por qué importa (con referencia legal si aplica), botón principal, enlace a ayuda y, si hay PSE o IAA, «Usar plantilla» o «Sugerir con IA».

### 3.7 Bloqueado por plan
- Módulo no contratado: pantalla con el nombre, qué resuelve, plan que lo incluye, botón «Ver planes» (AC) o «Pedir al administrador» (otros roles). Sin precio (o «S/ —»).
- Solo lectura por baja de plan: banda ámbar superior «Este módulo está en solo lectura. Tus datos se conservan.». Botones de edición ocultos.
- Desactivado por la empresa (AUL, VTA): el administrador de cuenta lo ve en «Módulos opcionales por empresa» con el interruptor y el efecto; si hay datos previos, se ven en solo lectura desde ahí.

### 3.8 Otros
- Confirmación para acciones destructivas o irreversibles con texto de lo que pasará.
- Toasts para éxito; errores en línea junto al campo.
- Indicador de conexión y de sincronización en la PWA.
- Fechas relativas con fecha exacta al pasar el cursor («Vence en 3 días · 12/11/2026»).

## 4. Inventario de pantallas

Identificador: `SCR-<COD>-NN`. Las historias del backlog y los `M-<COD>.md` usan estos identificadores.

Total: 347 pantallas en 61 módulos en este inventario resumido. El detalle de cada pantalla (propósito y elementos) está en `docs/modulos/M-<COD>.md`; para CAP, AUL, VTA y WEB este inventario usa los mismos identificadores que su especificación.

### TEN · Empresas, sedes y áreas (Esencial, fase 0)

| ID | Pantalla |
|---|---|
| SCR-TEN-01 | Datos de la empresa (RUC, razón social, actividad, logo) |
| SCR-TEN-02 | Lista de sedes |
| SCR-TEN-03 | Ficha de sede con áreas |
| SCR-TEN-04 | Lista de puestos |
| SCR-TEN-05 | Ficha de puesto (riesgos, EPP, EMO y capacitaciones requeridas) |
| SCR-TEN-06 | Turnos de trabajo |

### USR · Usuarios, roles y permisos (Esencial, fase 0)

| ID | Pantalla |
|---|---|
| SCR-USR-01 | Lista de usuarios |
| SCR-USR-02 | Invitar usuario |
| SCR-USR-03 | Ficha de usuario: membresías y alcances |
| SCR-USR-04 | Roles y permisos (lectura de matriz) |
| SCR-USR-05 | Mi perfil y seguridad (MFA, clave adicional, sesiones) |
| SCR-USR-06 | Selector de empresa |

### TRB · Trabajadores y legajo (Esencial, fase 0)

| ID | Pantalla |
|---|---|
| SCR-TRB-01 | Lista de trabajadores |
| SCR-TRB-02 | Ficha del trabajador (legajo, puesto, semáforo de requisitos) |
| SCR-TRB-03 | Nuevo / editar trabajador |
| SCR-TRB-04 | Carga masiva: subir plantilla |
| SCR-TRB-05 | Carga masiva: validación y vista previa |
| SCR-TRB-06 | Carga masiva: resultado |
| SCR-TRB-07 | Documentos del trabajador |

### DOC · Gestión documental (Esencial, fase 0)

| ID | Pantalla |
|---|---|
| SCR-DOC-01 | Biblioteca de documentos |
| SCR-DOC-02 | Ficha del documento con versiones |
| SCR-DOC-03 | Editor de versión y envío a revisión |
| SCR-DOC-04 | Bandeja de revisión y aprobación |
| SCR-DOC-05 | Cargos de entrega y lectura |

### NOT · Alertas y notificaciones (Esencial, fase 0)

| ID | Pantalla |
|---|---|
| SCR-NOT-01 | Centro de notificaciones |
| SCR-NOT-02 | Reglas de alerta |
| SCR-NOT-03 | Preferencias de notificación del usuario |
| SCR-NOT-04 | Historial de envíos (correo y WhatsApp) |

### DSH · Dashboard e indicadores (Esencial, fase 0)

| ID | Pantalla |
|---|---|
| SCR-DSH-01 | Dashboard de la empresa |
| SCR-DSH-02 | Dashboard por sede |
| SCR-DSH-03 | Configurar widgets |
| SCR-DSH-04 | Detalle de indicador |

### FIR · Firma electrónica de registros (Profesional, fase 0)

| ID | Pantalla |
|---|---|
| SCR-FIR-01 | Bandeja de firmas pendientes |
| SCR-FIR-02 | Pantalla de firma |
| SCR-FIR-03 | Comprobante de firma y evidencias |

### AUD · Bitácora de auditoría (Esencial, fase 0)

| ID | Pantalla |
|---|---|
| SCR-AUD-01 | Bitácora de auditoría |
| SCR-AUD-02 | Registro de accesos sensibles |
| SCR-AUD-03 | Detalle de evento |

### SUS · Suscripciones y facturación (Interno, fase 0)

| ID | Pantalla |
|---|---|
| SCR-SUS-01 | Mi suscripción (cliente) |
| SCR-SUS-02 | Cambiar plan y complementos |
| SCR-SUS-03 | Facturas y pagos (cliente) |
| SCR-SUS-04 | Panel del dueño: cuentas y empresas |
| SCR-SUS-05 | Panel del dueño: suscripciones |
| SCR-SUS-06 | Panel del dueño: facturación |
| SCR-SUS-07 | Panel del dueño: planes, complementos y límites |
| SCR-SUS-08 | Panel del dueño: catálogos globales y parámetros legales |
| SCR-SUS-09 | Panel del dueño: métricas |
| SCR-SUS-10 | Panel del dueño: accesos de soporte |
| SCR-SUS-11 | Módulos opcionales por empresa: aula virtual (AUL) y venta de cursos (VTA) |

### WEB · Sitio web público y CMS (Interno, fase 1)

Mismos identificadores que `docs/modulos/M-WEB.md`. Las pantallas SCR-WEB-01 a SCR-WEB-18 son públicas (sin sesión, sitio web); SCR-WEB-19 a SCR-WEB-27 son del panel del dueño. Herramientas de captación: SCR-WEB-28 a SCR-WEB-38 son públicas (salvo SCR-WEB-34, ayuda contextual dentro de la aplicación, con sesión) y SCR-WEB-39 a SCR-WEB-49 son del panel del dueño. Sitio web v1.5 y v1.5-b (diseño Stitch corregido, métricas, calendario, chatbot, CRM, medios, redes, campañas, ventanas emergentes, integraciones, novedades, webinars, reseñas y calculadora de ahorro): SCR-WEB-50 a SCR-WEB-83; SCR-WEB-19 pasa a ser el resumen con métricas.

| ID | Pantalla |
|---|---|
| SCR-WEB-01 | Portada (pública) |
| SCR-WEB-02 | Producto y módulos (pública) |
| SCR-WEB-03 | Diferenciador (pública, una por cada uno de los 7) |
| SCR-WEB-04 | Página por sector (pública) |
| SCR-WEB-05 | Cómo funciona (pública) |
| SCR-WEB-06 | Planes y comparativa (pública) |
| SCR-WEB-07 | Complementos (pública) |
| SCR-WEB-08 | Para consultoras (pública) |
| SCR-WEB-09 | Casos de éxito (pública, `/casos`) |
| SCR-WEB-10 | Demostración (pública) |
| SCR-WEB-11 | Preguntas frecuentes (pública) |
| SCR-WEB-12 | Formularios de demo, contacto y cotización (pública) |
| SCR-WEB-13 | Botón flotante de WhatsApp (público) |
| SCR-WEB-14 | Registro autoservicio e Iniciar sesión (pública) |
| SCR-WEB-15 | Documento legal (pública) |
| SCR-WEB-16 | Banner y centro de preferencias de cookies (público) |
| SCR-WEB-17 | Libro de Reclamaciones: hoja (pública) |
| SCR-WEB-18 | Libro de Reclamaciones: seguimiento (pública) |
| SCR-WEB-19 | Resumen del sitio con métricas (dueño, `#m-web/resumen`) |
| SCR-WEB-20 | Editor de páginas por bloques (dueño) |
| SCR-WEB-21 | Marca, menú, ajustes y datos legales (dueño) |
| SCR-WEB-22 | Planes visibles y precios (dueño) |
| SCR-WEB-23 | Otros productos, testimonios y preguntas frecuentes (dueño) |
| SCR-WEB-24 | Políticas y versiones (dueño) |
| SCR-WEB-25 | Bandeja del Libro de Reclamaciones (dueño) |
| SCR-WEB-26 | Solicitudes de demo, contacto y cotización (dueño) |
| SCR-WEB-27 | Redirecciones y SEO técnico (dueño) |
| SCR-WEB-28 | Calculadora de multas SUNAFIL (pública, `/herramientas/calculadora-multas`) |
| SCR-WEB-29 | Autodiagnóstico Ley 29783 (pública, `/herramientas/autodiagnostico`) |
| SCR-WEB-30 | Biblioteca de recursos (pública, `/recursos`) |
| SCR-WEB-31 | Comparativa «EscudoSST vs Excel y papel» (pública, `/comparativa`) |
| SCR-WEB-32 | Centro de ayuda (pública, `/ayuda`) |
| SCR-WEB-33 | Artículo de ayuda (pública, `/ayuda/{slug}`) |
| SCR-WEB-34 | Ayuda contextual en la aplicación (autenticada) |
| SCR-WEB-35 | Programa de partners y referidos (pública, `/partners`) |
| SCR-WEB-36 | Boletín normativo SST (pública, `/boletin`) |
| SCR-WEB-37 | Centro de confianza (pública, `/confianza`) |
| SCR-WEB-38 | Estado del servicio (pública, `/estado`) |
| SCR-WEB-39 | Bandeja de leads (dueño) |
| SCR-WEB-40 | Ficha de lead (dueño) |
| SCR-WEB-41 | Calculadora: parámetros y cálculos (dueño) |
| SCR-WEB-42 | Editor del autodiagnóstico (dueño) |
| SCR-WEB-43 | Recursos descargables (dueño) |
| SCR-WEB-44 | Casos de éxito (dueño) |
| SCR-WEB-45 | Centro de ayuda: categorías y artículos (dueño) |
| SCR-WEB-46 | Partners y referidos (dueño) |
| SCR-WEB-47 | Boletín: suscriptores y ediciones (dueño) |
| SCR-WEB-48 | Subencargados (dueño) |
| SCR-WEB-49 | Estado del servicio: componentes e incidentes (dueño) |
| SCR-WEB-50 | Métricas del sitio: detalle y exportación (dueño) |
| SCR-WEB-51 | Agenda pública de demos (pública, `/demo/agendar`) |
| SCR-WEB-52 | Confirmación de la demo (pública) |
| SCR-WEB-53 | Gestionar mi cita (pública, `/demo/gestionar/{token}`) |
| SCR-WEB-54 | Calendario de demos (dueño, `#m-web/calendario`) |
| SCR-WEB-55 | Confirmar demo y asignar anfitrión (dueño, modal) |
| SCR-WEB-56 | Ficha de la reunión y resultado (dueño) |
| SCR-WEB-57 | Anfitriones, disponibilidad y bloqueos (dueño) |
| SCR-WEB-58 | Ajustes de la agenda (dueño) |
| SCR-WEB-59 | Widget del chatbot (público) |
| SCR-WEB-60 | Chatbot: ajustes (dueño, `#m-web/chatbot`) |
| SCR-WEB-61 | Chatbot: intenciones (dueño) |
| SCR-WEB-62 | Chatbot: conversaciones (dueño) |
| SCR-WEB-63 | Conexión con CRM (dueño, `#m-web/crm`) |
| SCR-WEB-64 | Cola de envíos al CRM (dueño) |
| SCR-WEB-65 | Biblioteca de medios (dueño, `#m-web/medios`) |
| SCR-WEB-66 | Selector de medios (dueño, componente) |
| SCR-WEB-67 | Redes sociales (dueño) |
| SCR-WEB-68 | Lineamientos de contenido (dueño) |
| SCR-WEB-69 | Landing de campaña (pública, `/l/{slug}`) |
| SCR-WEB-70 | Campañas (dueño) |
| SCR-WEB-71 | Ventana emergente (pública) |
| SCR-WEB-72 | Ventanas emergentes (dueño) |
| SCR-WEB-73 | Integraciones (pública, `/integraciones`) |
| SCR-WEB-74 | Integraciones del sitio (dueño) |
| SCR-WEB-75 | Novedades del producto (pública, `/novedades`) |
| SCR-WEB-76 | Novedades en la aplicación (autenticada) |
| SCR-WEB-77 | Novedades (dueño) |
| SCR-WEB-78 | Webinars (pública, `/webinars` y `/webinars/{slug}`) |
| SCR-WEB-79 | Webinars e inscripciones (dueño) |
| SCR-WEB-80 | Reseñas de Google (pública, bloque) |
| SCR-WEB-81 | Fuente de reseñas (dueño) |
| SCR-WEB-82 | Calculadora de ahorro (pública, `/herramientas/calculadora-ahorro`) |
| SCR-WEB-83 | Calculadora de ahorro: supuestos (dueño) |

### LBA · Diagnóstico de línea base (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-LBA-01 | Lista de diagnósticos |
| SCR-LBA-02 | Asistente de línea base |
| SCR-LBA-03 | Resultado: puntaje por lineamiento |
| SCR-LBA-04 | Brechas y plan de cierre |

### POL · Política y objetivos SST (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-POL-01 | Política SST vigente y versiones |
| SCR-POL-02 | Editor de política |
| SCR-POL-03 | Objetivos SST |
| SCR-POL-04 | Metas e indicadores por objetivo |

### MRL · Matriz de requisitos legales (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-MRL-01 | Matriz de requisitos legales |
| SCR-MRL-02 | Catálogo de normas |
| SCR-MRL-03 | Ficha del requisito |
| SCR-MRL-04 | Evaluación de cumplimiento |

### PLA · Plan Anual SST (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-PLA-01 | Planes anuales |
| SCR-PLA-02 | Ficha del plan anual |
| SCR-PLA-03 | Editor de ítems del plan |

### PAS · Programa Anual SST (PASST) (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-PAS-01 | Programas anuales |
| SCR-PAS-02 | Ficha del PASST (cronograma y % de avance) |
| SCR-PAS-03 | Ficha de actividad |
| SCR-PAS-04 | Registrar ejecución con evidencia |
| SCR-PAS-05 | Vista calendario |

### PCA · Programa anual de capacitación (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-PCA-01 | Programa de capacitación |
| SCR-PCA-02 | Ficha del programa |
| SCR-PCA-03 | Editor de ítems (tema, público, mes) |

### RIS · RISST (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-RIS-01 | Versiones del RISST |
| SCR-RIS-02 | Editor de versión |
| SCR-RIS-03 | Cargos de entrega por trabajador |

### COM · Comité o Supervisor SST (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-COM-01 | Comité o Supervisor SST vigente |
| SCR-COM-02 | Miembros |
| SCR-COM-03 | Elecciones |
| SCR-COM-04 | Asistente de elección |
| SCR-COM-05 | Cédula de votación (trabajador) |
| SCR-COM-06 | Resultados de elección |
| SCR-COM-07 | Reuniones |
| SCR-COM-08 | Acta de reunión |
| SCR-COM-09 | Acuerdos y seguimiento |

### PRE · Presupuesto SST (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-PRE-01 | Presupuestos |
| SCR-PRE-02 | Ficha del presupuesto |
| SCR-PRE-03 | Ejecución por partida |

### IPC · Matriz IPERC (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-IPC-01 | Lista de matrices IPERC |
| SCR-IPC-02 | Ficha de matriz con versiones |
| SCR-IPC-03 | Editor de filas IPERC |
| SCR-IPC-04 | Fila: evaluación y controles |
| SCR-IPC-05 | Catálogo de peligros |
| SCR-IPC-06 | Criterios de evaluación |
| SCR-IPC-07 | Comparar versiones |

### MAP · Mapa de riesgos (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-MAP-01 | Lista de mapas |
| SCR-MAP-02 | Editor de mapa (plano y pictogramas) |
| SCR-MAP-03 | Vista de mapa publicada |

### MAG · Monitoreo de agentes (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-MAG-01 | Puntos de monitoreo |
| SCR-MAG-02 | Campañas |
| SCR-MAG-03 | Registro de mediciones |
| SCR-MAG-04 | Resultados frente a límites |

### PSI · Riesgos ergonómicos y psicosociales (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-PSI-01 | Evaluaciones ergonómicas |
| SCR-PSI-02 | Ficha de evaluación |
| SCR-PSI-03 | Encuestas psicosociales |
| SCR-PSI-04 | Responder encuesta (trabajador) |
| SCR-PSI-05 | Resultados agregados |

### PET · PETS y procedimientos (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-PET-01 | Procedimientos |
| SCR-PET-02 | Ficha con versiones |
| SCR-PET-03 | Editor de procedimiento |

### MOC · Gestión del cambio (Complemento, fase 5)

| ID | Pantalla |
|---|---|
| SCR-MOC-01 | Solicitudes de cambio |
| SCR-MOC-02 | Ficha de cambio |
| SCR-MOC-03 | Revisión de riesgos del cambio |

### INS · Inspecciones (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-INS-01 | Programa de inspecciones |
| SCR-INS-02 | Plantillas de checklist |
| SCR-INS-03 | Editor de plantilla |
| SCR-INS-04 | Ejecutar inspección (campo) |
| SCR-INS-05 | Ficha de inspección |
| SCR-INS-06 | Hallazgos |

### ACT · Actos y condiciones subestándar (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-ACT-01 | Bandeja de reportes |
| SCR-ACT-02 | Ficha del reporte |
| SCR-ACT-03 | Puntos de reporte QR |
| SCR-ACT-04 | Imprimir QR del punto |
| SCR-ACT-05 | Reportar (trabajador) |

### CH5 · Charlas de 5 minutos (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-CH5-01 | Charlas |
| SCR-CH5-02 | Nueva charla |
| SCR-CH5-03 | Registrar asistencia con firma |

### ATS · ATS (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-ATS-01 | Lista de ATS |
| SCR-ATS-02 | Llenar ATS (campo) |
| SCR-ATS-03 | Ficha de ATS |

### PTR · PETAR y permisos de alto riesgo (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-PTR-01 | Permisos de trabajo |
| SCR-PTR-02 | Tipos de permiso y cadenas de aprobación |
| SCR-PTR-03 | Solicitar PETAR |
| SCR-PTR-04 | Aprobar PETAR |
| SCR-PTR-05 | Verificación e inicio en campo |
| SCR-PTR-06 | Ficha del PETAR |

### OBS · Observación de conducta segura (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-OBS-01 | Observaciones |
| SCR-OBS-02 | Registrar observación (campo) |
| SCR-OBS-03 | Tendencias por conducta |

### EPP · EPP (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-EPP-01 | Catálogo de EPP |
| SCR-EPP-02 | EPP por puesto |
| SCR-EPP-03 | Stock y movimientos |
| SCR-EPP-04 | Registrar entrega con firma |
| SCR-EPP-05 | Entregas por trabajador |
| SCR-EPP-06 | Vencimientos de EPP |

### EQE · Equipos de emergencia y botiquines (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-EQE-01 | Equipos de emergencia |
| SCR-EQE-02 | Ficha del equipo |
| SCR-EQE-03 | Registrar revisión |
| SCR-EQE-04 | Botiquines e insumos |

### SIM · Simulacros y brigadas (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-SIM-01 | Simulacros |
| SCR-SIM-02 | Ficha del simulacro |
| SCR-SIM-03 | Brigadas |
| SCR-SIM-04 | Ficha de brigada |

### CAP · Capacitaciones e inducciones (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-CAP-01 | Panel de capacitación |
| SCR-CAP-02 | Capacitaciones |
| SCR-CAP-03 | Ficha de capacitación |
| SCR-CAP-04 | Programar sesión |
| SCR-CAP-05 | Registrar asistencia (móvil) |
| SCR-CAP-06 | Evaluación de sesión |
| SCR-CAP-07 | Matriz de capacitación por cargo |
| SCR-CAP-08 | Cumplimiento de la matriz |
| SCR-CAP-09 | Control de vencimientos |
| SCR-CAP-10 | Certificados |
| SCR-CAP-11 | Plantillas de certificado |
| SCR-CAP-12 | Verificación pública de certificado |
| SCR-CAP-13 | Configuración de verificación |
| SCR-CAP-14 | Reportes de capacitación |
| SCR-CAP-15 | Mis capacitaciones (app del trabajador) |

### AUL · Aula virtual SST (Profesional, fase 2; activable por empresa)

| ID | Pantalla |
|---|---|
| SCR-AUL-01 | Panel del aula |
| SCR-AUL-02 | Cursos |
| SCR-AUL-03 | Nuevo curso |
| SCR-AUL-04 | Curso · Contenido |
| SCR-AUL-05 | Curso · Participantes |
| SCR-AUL-06 | Curso · Calificaciones |
| SCR-AUL-07 | Curso · Evaluaciones |
| SCR-AUL-08 | Curso · Configuración |
| SCR-AUL-09 | Banco de preguntas |
| SCR-AUL-10 | Escalas de calificación |
| SCR-AUL-11 | Capacitadores |
| SCR-AUL-12 | Mi disponibilidad (capacitador) |
| SCR-AUL-13 | Mis cursos (capacitador) |
| SCR-AUL-14 | Tiempo de permanencia |
| SCR-AUL-15 | Reglas de recomendación |
| SCR-AUL-16 | Recomendar curso |
| SCR-AUL-17 | Bandeja de recomendaciones por aprobar |
| SCR-AUL-18 | Conversión de recomendaciones |
| SCR-AUL-19 | Conexión con Moodle (opcional) |
| SCR-AUL-20 | Mis cursos (app del trabajador y portal del participante externo) |
| SCR-AUL-21 | Recomendados para ti (app del trabajador y portal del participante externo) |
| SCR-AUL-22 | Reproductor del curso |
| SCR-AUL-23 | Rendir evaluación |

### FOT · Fotocheck SST con QR (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-FOT-01 | Credenciales |
| SCR-FOT-02 | Tipos de credencial y requisitos |
| SCR-FOT-03 | Emitir e imprimir fotocheck |
| SCR-FOT-04 | Ficha de credencial |
| SCR-FOT-05 | Mi fotocheck (trabajador) |
| SCR-FOT-06 | Escáner de QR (supervisor) |

### EMO · EMOs y aptitud (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-EMO-01 | Programación de EMO |
| SCR-EMO-02 | Ficha de orden de examen |
| SCR-EMO-03 | Resultados y aptitud (médico, re-autenticación) |
| SCR-EMO-04 | Aptitud por trabajador (RR. HH.) |
| SCR-EMO-05 | Vencimientos de EMO |

### VMD · Vigilancia médica (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-VMD-01 | Programas de vigilancia |
| SCR-VMD-02 | Casos en vigilancia |
| SCR-VMD-03 | Ficha clínica ocupacional (médico) |

### DES · Descansos médicos y ausentismo (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-DES-01 | Ausentismo |
| SCR-DES-02 | Registrar descanso médico |
| SCR-DES-03 | Detalle médico (re-autenticación) |
| SCR-DES-04 | Indicadores de ausentismo |

### VUL · Grupos vulnerables (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-VUL-01 | Registros de vulnerabilidad (médico) |
| SCR-VUL-02 | Adecuaciones de puesto |

### VTA · Venta de cursos y pagos (Profesional, fase 2; activable por empresa, desactivado por defecto)

| ID | Pantalla |
|---|---|
| SCR-VTA-01 | Panel de ventas |
| SCR-VTA-02 | Ofertas de cursos |
| SCR-VTA-03 | Ficha de oferta |
| SCR-VTA-04 | Cupones |
| SCR-VTA-05 | Pedidos |
| SCR-VTA-06 | Registrar pago manual |
| SCR-VTA-07 | Reembolsos |
| SCR-VTA-08 | Cupos corporativos |
| SCR-VTA-09 | Participantes externos |
| SCR-VTA-10 | Pasarela de pagos |
| SCR-VTA-11 | Comprobantes electrónicos |
| SCR-VTA-12 | Catálogo público de cursos |
| SCR-VTA-13 | Recomendados en el catálogo público |
| SCR-VTA-14 | Campañas de recomendación |
| SCR-VTA-15 | Ficha pública del curso |
| SCR-VTA-16 | Carrito y pago |
| SCR-VTA-17 | Resultado del pago |
| SCR-VTA-18 | Portal del participante externo |
| SCR-VTA-19 | Asignar cupos (representante de empresa compradora) |
| SCR-VTA-20 | Reportes de ventas |

### ACC · Accidentes, incidentes y enfermedades (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-ACC-01 | Eventos SST |
| SCR-ACC-02 | Reportar evento |
| SCR-ACC-03 | Ficha del evento |
| SCR-ACC-04 | Registrar notificación |

### INV · Investigación de causas (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-INV-01 | Investigaciones |
| SCR-INV-02 | Ficha de investigación |
| SCR-INV-03 | Análisis de causas (árbol o SCAT) |
| SCR-INV-04 | Investigación guiada (IA) |

### ACR · Acciones correctivas (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-ACR-01 | Acciones correctivas |
| SCR-ACR-02 | Ficha de acción |
| SCR-ACR-03 | Mis acciones |
| SCR-ACR-04 | Verificación de cierre |

### AUI · Auditorías (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-AUI-01 | Programa de auditorías |
| SCR-AUI-02 | Ficha de auditoría |
| SCR-AUI-03 | Checklist de auditoría |
| SCR-AUI-04 | Hallazgos de auditoría |

### EST · Estadísticas SST (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-EST-01 | Horas-hombre mensuales |
| SCR-EST-02 | Estadísticas mensuales e índices |
| SCR-EST-03 | Gráficos y comparativos |

### RVD · Revisión por la dirección (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-RVD-01 | Revisiones por la dirección |
| SCR-RVD-02 | Ficha de revisión |
| SCR-RVD-03 | Salidas y compromisos |

### REG · Registros obligatorios (Esencial, fase 1)

| ID | Pantalla |
|---|---|
| SCR-REG-01 | Los 9 registros |
| SCR-REG-02 | Generar registro (periodo y formato) |
| SCR-REG-03 | Historial de exportaciones |

### CTR · Acreditación de contratistas (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-CTR-01 | Contratistas |
| SCR-CTR-02 | Ficha del contratista |
| SCR-CTR-03 | Requisitos por tipo de servicio |
| SCR-CTR-04 | Revisión de documentos |
| SCR-CTR-05 | Portal del contratista: inicio |
| SCR-CTR-06 | Portal: documentos |
| SCR-CTR-07 | Portal: trabajadores |

### SCT · Control de SCTR (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-SCT-01 | Pólizas SCTR |
| SCR-SCT-02 | Ficha de póliza |
| SCR-SCT-03 | Coberturas por trabajador |

### ING · Ingreso de terceros por QR (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-ING-01 | Puntos de acceso |
| SCR-ING-02 | Portería: escanear QR |
| SCR-ING-03 | Historial de ingresos |

### HOM · Homologación de proveedores (Complemento, fase 5)

| ID | Pantalla |
|---|---|
| SCR-HOM-01 | Plantillas de evaluación |
| SCR-HOM-02 | Evaluaciones de proveedores |
| SCR-HOM-03 | Ficha de evaluación |

### SUN · Modo Inspección SUNAFIL (Profesional, fase 2)

| ID | Pantalla |
|---|---|
| SCR-SUN-01 | Modo SUNAFIL: inicio |
| SCR-SUN-02 | Autodiagnóstico |
| SCR-SUN-03 | Generar expediente |
| SCR-SUN-04 | Ficha del expediente (ítems y faltantes) |
| SCR-SUN-05 | Links compartidos |
| SCR-SUN-06 | Vista pública del expediente (inspector) |

### OFF · App móvil offline (Complemento, fase 3)

| ID | Pantalla |
|---|---|
| SCR-OFF-01 | Dispositivos registrados |
| SCR-OFF-02 | Estado de sincronización (PWA) |
| SCR-OFF-03 | Conflictos de sincronización |

### IAA · Asistente IA (Complemento, fase 3)

| ID | Pantalla |
|---|---|
| SCR-IAA-01 | Bandeja de sugerencias IA |
| SCR-IAA-02 | Revisar sugerencia |
| SCR-IAA-03 | Uso de IA y cupo |

### CON · Modo consultora y marca blanca (Consultora, fase 3)

| ID | Pantalla |
|---|---|
| SCR-CON-01 | Panel multi-cliente |
| SCR-CON-02 | Ficha del cliente |
| SCR-CON-03 | Agregar o invitar cliente |
| SCR-CON-04 | Asignación de consultores |
| SCR-CON-05 | Marca blanca |

### PSE · Paquetes sectoriales (Complemento, fase 3)

| ID | Pantalla |
|---|---|
| SCR-PSE-01 | Catálogo de paquetes sectoriales |
| SCR-PSE-02 | Ficha del paquete |
| SCR-PSE-03 | Aplicar paquete a la empresa |

### ISO · Sistema integrado ISO (Complemento, fase 5)

| ID | Pantalla |
|---|---|
| SCR-ISO-01 | Normas y cláusulas |
| SCR-ISO-02 | Matriz de evidencias por cláusula |

### AMB · Medio ambiente (SSOMA) (Complemento, fase 5)

| ID | Pantalla |
|---|---|
| SCR-AMB-01 | Aspectos ambientales |
| SCR-AMB-02 | Registro de residuos |

### API · API e integraciones (Corporativo, fase 4)

| ID | Pantalla |
|---|---|
| SCR-API-01 | Clientes de API y llaves |
| SCR-API-02 | Webhooks |
| SCR-API-03 | Historial de entregas de webhooks |

## 5. Vistas especiales

### 5.1 Panel consultora (SCR-CON-01)
- Tabla de empresas cliente: RUC, plan, % de cumplimiento (línea base y PASST), vencimientos en los próximos días (parámetro), eventos abiertos, acciones vencidas, último acceso.
- Semáforo por empresa. Orden por riesgo.
- Clic en una empresa = cambia el contexto a esa empresa.
- Filtros por consultor asignado y por plan.

### 5.2 App del trabajador (PWA)
Inicio con tarjetas grandes:
1. **Mi fotocheck** (QR a pantalla completa, estado habilitado/no habilitado).
2. **Pendientes** (firmas, lecturas, cursos, encuestas).
3. **Reportar** (acto o condición, con foto).
4. **Mis capacitaciones**, certificados y **Mis cursos** del aula (si la empresa tiene AUL activo), con el bloque **Recomendados para ti**.
5. **Mi EPP**.
6. **Mis resultados médicos** (re-autenticación).
7. **Elección del comité** (cuando hay votación abierta).

Acceso: DNI + código de activación o enlace por WhatsApp/correo; luego PIN. Texto en tú, frases cortas, botones de 48 px.

### 5.3 Verificación pública de fotocheck (`/v/{token}`)
- Sin sesión. Pantalla única.
- Gran banda verde «HABILITADO» o roja «NO HABILITADO».
- Foto, nombres, empresa, puesto, credencial y vigencia.
- Lista de requisitos con semáforo (capacitaciones, SCTR, inducción, «Aptitud médica: vigente/no vigente»). Nunca diagnóstico ni restricción médica.
- Hora de la consulta. Marca de la empresa o consultora.

### 5.3.1 Verificación pública de certificado (SCR-CAP-12)
- Sin sesión. Página por empresa con su marca: campo «Código del certificado» y lectura de QR.
- Resultado con banda de color y texto: **VÁLIDO**, **VENCIDO**, **ANULADO** (con fecha) o «No encontrado o no verificable públicamente» (misma respuesta para ambos casos).
- Muestra nombre del titular, curso o tema, horas, fecha de emisión, vigencia y empresa emisora. Documento de identidad enmascarado o ausente; nunca datos de salud.
- Límite de consultas por IP; cada consulta queda registrada.

### 5.3.2 Aula virtual: reproductor del curso (SCR-AUL-22)
- Índice de secciones a la izquierda (en móvil, hoja inferior) con candado en las actividades bloqueadas por el recorrido secuencial.
- Contenido a pantalla completa: texto, video de Vimeo embebido, documento, enlace o evaluación.
- Indicador discreto de permanencia acumulada y de lo que falta para completar («Te faltan 12 min de actividad»); aviso de pausa por inactividad.
- Compromiso de culminación como paso previo cuando el curso lo exige.
- Botones grandes en móvil; funciona en celulares de gama media.

### 5.3.3 Recomendados para ti (SCR-AUL-21)
- En la app del trabajador, en el portal del participante externo y en el catálogo público con sesión.
- Tarjetas de curso con imagen, horas y **motivo visible** («Tu certificado de trabajo en altura vence pronto», «Lo exige tu cargo», «Complementa el curso que aprobaste»).
- Acciones: «Matricularme» (trabajador, si el curso lo permite) o «Ver curso y comprar» (participante externo); «No me interesa» con motivo opcional.
- Nunca menciona datos de otras personas ni datos médicos.

### 5.3.4 Catálogo público de cursos (SCR-VTA-12, SCR-VTA-13 y SCR-VTA-15 a SCR-VTA-17)
- Sin sesión, con la marca de la empresa vendedora (o de la consultora con marca blanca). URL con el identificador corto de la empresa.
- Vitrina con búsqueda y filtros; ficha con temario, capacitador, horas, certificado verificable, **precio definido por la empresa**, condiciones y política de reembolso.
- Carrito y pago: datos de comprobante (boleta o factura con RUC), aceptación de términos y aviso de privacidad, consentimiento de publicidad opcional y separado; formulario de la pasarela embebido. EscudoSST nunca muestra ni recibe datos de tarjeta.
- Resultado: «Pagado» con acceso al curso, «Pendiente de confirmación» o «Rechazado» con reintento.
- Con la venta desactivada, la URL responde «no disponible».

### 5.3.5 Portal del participante externo (SCR-VTA-18 y SCR-VTA-19)
- Inicio con Mis cursos (avance y «Continuar»), Recomendados para ti, Mis certificados, Mis pedidos y comprobantes, Mis datos y consentimientos (ARCO, baja de publicidad).
- El representante de una empresa compradora ve además «Mis cupos»: disponibles, asignar por documento y correo o Excel, liberar un cupo no iniciado, avance y certificados de sus asignados.
- Texto en tú, igual que la app del trabajador; sin acceso a ningún módulo SST de la empresa vendedora.

### 5.4 Reporte público por QR (`/r/{token}`)
- Sin sesión. Sede y área precargadas por el QR.
- Paso 1: ¿Qué viste? (acto / condición) con iconos grandes. Paso 2: descripción (texto o dictado) y foto opcional. Paso 3: anónimo o con nombre/DNI.
- Captcha discreto. Confirmación con código de seguimiento.
- Funciona en celulares de gama baja y redes lentas (página ligera).

### 5.5 Modo SUNAFIL (SUN)
- Acceso destacado en el menú con color ámbar.
- Inicio: semáforo por materia inspeccionable (registros, IPERC, capacitación, EMO, EPP, comité, RISST, SCTR).
- Botón principal: «Generar expediente» → asistente: trabajadores (uno, varios, por área), periodo, materias.
- Ficha del expediente: ítems encontrados y faltantes con enlace directo para corregir.
- Compartir: link con vencimiento y código opcional; vista pública de solo lectura y descarga.

### 5.6 Panel del dueño (SUS)
- Aplicación separada `(owner)` con su propio menú: Cuentas, Suscripciones, Facturación, Planes, Catálogos, Métricas, Soporte, Salud.
- Nunca muestra datos de negocio de una empresa. Para soporte, requiere acceso autorizado (ver `05` §4.6).

### 5.7 Sitio web público y su CMS (WEB, SCR-WEB-01 a SCR-WEB-83)
- **Públicas (`(site)`):** portada, producto, 7 diferenciadores, sectores, cómo funciona, planes y comparativa, complementos, consultoras, casos, demostración, preguntas frecuentes, formularios de demo, contacto y cotización, botón flotante de WhatsApp, accesos a «Prueba gratis» e «Iniciar sesión», documentos legales, banner de cookies y Libro de Reclamaciones (hoja y seguimiento). El blog es externo (WordPress) y solo se enlaza y resume. Lineamientos de diseño en §1.4.
- **Hoja de reclamación (SCR-WEB-17):** formulario en pasos cortos (proveedor, consumidor, apoderado si es menor, bien contratado, tipo con su definición, detalle y pedido); al enviar, constancia en pantalla con el código grande, botón «Descargar PDF» y aviso de que se envió por correo.
- **Panel del dueño (`(owner)` → «Sitio web»):** resumen, editor por bloques con vista previa móvil/escritorio, pestaña SEO y mensaje de WhatsApp por página, marca (logo claro y oscuro, favicon), menú, ajustes y datos legales (incluidos dominio, URL del blog, demo interactiva y sellos), planes visibles, otros productos, testimonios con aprobación y FAQ, políticas y versiones, bandeja de reclamos con semáforo de plazo (verde en plazo, amarillo por vencer, rojo vencido, gris cerrado, siempre con texto: «Vence en N días hábiles»), solicitudes de demo y redirecciones.
- **Herramientas de captación (SCR-WEB-28 a SCR-WEB-38):** en el menú público, entrada «Herramientas» con calculadora de multas, autodiagnóstico y recursos; «Recursos» agrupa también casos, comparativa, centro de ayuda y boletín; el pie enlaza a partners, centro de confianza y estado del servicio. Todos los formularios siguen el mismo patrón: datos mínimos, casilla de privacidad obligatoria con enlace a la versión vigente, casilla de comunicaciones comerciales aparte, desmarcada y con texto propio, captcha y botón con estado de envío (evita dobles envíos; la `Idempotency-Key` se genera al abrir el formulario).
- **Calculadora (SCR-WEB-28):** tres pasos en una sola vista (tamaño, trabajadores afectados, gravedad con ejemplos); el rango aparece al instante con números grandes en UIT y soles, la UIT y su fecha, el escenario con subsanación y el aviso «Resultado referencial; no es asesoría legal» siempre visible; si algún parámetro está por confirmar, distintivo «Valores a confirmar». El detalle completo se pide por correo.
- **Autodiagnóstico (SCR-WEB-29):** una sección por paso con barra de avance; resultado con medidor del nivel (bajo en rojo, medio en amarillo, alto en verde, siempre con texto) y barras por sección; rótulo «Cuestionario en validación por especialista SST» mientras corresponda.
- **Ayuda contextual (SCR-WEB-34):** botón «Ayuda» (icono de interrogación con texto) en la cabecera de cada pantalla de la aplicación; panel lateral que no tapa el formulario en escritorio y hoja completa en móvil; usa el código `SCR-*` de la pantalla abierta.
- **Estado del servicio (SCR-WEB-38):** estado general en una franja superior, componentes con icono y texto («Operativo», «Degradado», «Caída parcial», «Caída total», «Mantenimiento»), incidentes con línea de tiempo; página ligera servida desde caché propia.
- **Bandeja de leads (SCR-WEB-39/40):** mismo patrón de tabla que la bandeja de solicitudes, con chip de origen, indicador de consentimiento comercial (sí/no con texto) y línea de tiempo en la ficha; la exportación solo aparece con el permiso.
- **Sistema visual (v1.5):** sitio y panel «Sitio web» siguen §1.5 (diseño Stitch corregido). El panel se titula siempre «EscudoSST · Panel de la plataforma» y su menú «Sitio web» agrega Resumen (`#m-web/resumen`), Calendario (`#m-web/calendario`), Chatbot (`#m-web/chatbot`), CRM (`#m-web/crm`), Medios (`#m-web/medios`), Campañas, Ventanas emergentes, Integraciones, Novedades, Webinars, Reseñas y Calculadora de ahorro.
- **Resumen (SCR-WEB-19):** fila de tarjetas de métrica con número grande y variación; gráfico de visitas; fuentes en barras horizontales; tabla de páginas más vistas; conversiones por CTA; tarjetas de pendientes con borde izquierdo de color (demos por confirmar, reclamos por vencer con «Vence en N días hábiles»); recuadro fijo «El embudo comercial se mide en el CRM».
- **Agenda pública (SCR-WEB-51 a 53):** selector de día en tira horizontal desplazable (sin desborde de la página) y horarios en botones de 48 px; resumen fijo abajo en móvil; confirmación con ícono verde, fecha grande, botón «Agregar a mi calendario» y enlace «Reprogramar o cancelar».
- **Calendario del panel (SCR-WEB-54 y 55):** vistas mes/semana/día/lista con colores por anfitrión y texto del estado; el modal de confirmación es un diálogo de nivel 3 con foco atrapado.
- **Chatbot (SCR-WEB-59):** botón flotante a la izquierda del de WhatsApp (o apilado, sin superponerse), panel de 380 px en escritorio y hoja completa en móvil; botones de intención como chips; mensajes de IA con enlace «Fuente».
- **Biblioteca de medios (SCR-WEB-65 y 66):** cuadrícula con miniatura, tipo, tamaño y aviso rojo si falta texto alternativo; el selector abre como cajón lateral desde cualquier editor.
- **Ventana emergente (SCR-WEB-71):** diálogo centrado (hoja inferior en móvil que no cubre más de la mitad de la pantalla), botón cerrar visible de 44 px, «No, gracias» como enlace; nunca a pantalla completa ni con cuenta regresiva.
- **Reseñas (SCR-WEB-80):** tarjetas con estrellas y texto íntegro (con «Leer más» que expande, sin recortar el sentido) y la atribución del proveedor visible.
- **Integraciones, novedades, webinars y calculadora de ahorro (SCR-WEB-73 a 83):** integraciones con chip de estado con texto («Disponible», «Beta», «Planificada»); novedades con chip de categoría y contador de no leídas en la app; webinars con fecha grande, cupos con texto y aviso de grabación; la calculadora de ahorro muestra los supuestos junto al resultado y el aviso «Estimación referencial, no garantizada» siempre visible.
- **«Otros productos»:** tarjetas de Plannio, GestionaHub, Edunova, Yachay Campus, Qhatu POS, Ayni HR y TaskFlow; sin botón de enlace mientras su URL esté vacía; en el panel, distintivo «descripción editable».

## 6. Accesibilidad

- WCAG 2.2 AA como objetivo.
- Navegación completa con teclado; foco visible.
- Etiquetas en todos los campos; errores asociados al campo (`aria-describedby`).
- Contraste AA; el semáforo siempre con texto.
- Tamaño táctil mínimo 44 px (48 px en trabajador).
- Textos ampliables al 200 % sin perder contenido.
- Imágenes con texto alternativo; pictogramas del mapa de riesgos con nombre.
- Firma: alternativa a trazo (confirmación con PIN) para personas que no pueden trazar.
- Pruebas automáticas con axe en CI (ver `12`).

## 7. Textos

- Español de Perú, claro y directo. Frases cortas.
- Vocabulario del rubro: IPERC, PETAR, ATS, EMO, SCTR, RISST, comité, SUNAFIL. Sin anglicismos innecesarios.
- Botones con verbo: «Guardar», «Enviar a revisión», «Aprobar», «Firmar».
- Gestión: usted neutro o impersonal. Vista del trabajador: tú.
- Mensajes de error que dicen qué pasó y qué hacer: «El DNI ya está registrado en esta empresa. Busca al trabajador en la lista.».
- Referencias legales breves en ayudas: «Obligatorio según art. 33 del D.S. 005-2012-TR».
- Nunca prometer cumplimiento legal absoluto («le ayuda a cumplir», no «garantiza»).
- Montos: «S/ —» o «Por definir» mientras no haya precios de los planes; en el sitio web público, «Solicitar cotización» si el plan no tiene precio publicado. Los precios de los cursos de VTA los define cada empresa y se muestran tal como ella los configura («precio definido por la empresa»).
