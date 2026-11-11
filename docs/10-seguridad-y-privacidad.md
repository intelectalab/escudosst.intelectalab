# 10 · Seguridad y privacidad

> **Aviso:** este documento traduce requisitos legales a decisiones técnicas. No es asesoría legal. **Debe revisarlo un abogado especialista en protección de datos personales** antes de la puesta en producción, en particular las secciones 1 y 11. Los plazos legales aquí mencionados son parámetros a confirmar.

## 1. Marco legal de datos personales

Normas: **Ley 29733**, Ley de Protección de Datos Personales, y su nuevo Reglamento, **D.S. 016-2024-JUS** (vigente desde el 30/03/2025). Autoridad: ANPD (Autoridad Nacional de Protección de Datos Personales, MINJUSDH).

### 1.1 Roles
| Actor | Rol legal |
|---|---|
| Empresa cliente (empleador) | **Titular del banco de datos / responsable del tratamiento** de los datos de sus trabajadores |
| GRUPO DE SOLUCIONES CREATIVAS (EscudoSST) | **Encargado del tratamiento** de los datos de los clientes. Responsable solo de los datos de sus propios usuarios y clientes (facturación, cuentas) |
| Consultora (plan K) | Encargada o subencargada según su contrato con la empresa cliente |
| Empresa que vende cursos (VTA) | **Titular del banco de datos / responsable** de los datos de sus participantes externos y compradores |
| GRUPO DE SOLUCIONES CREATIVAS SAC en el sitio web (WEB) | **Responsable** de los datos de visitantes, solicitantes de demo, consumidores que registran reclamos y usuarios que aceptan sus políticas |
| Contabo, DigitalOcean, Backblaze, Elastic Email, BSP de WhatsApp, proveedor de IA, Vimeo, proveedor de comprobantes | **Subencargados** |
| Pasarela de pagos elegida por la empresa | Contratada por la empresa vendedora; trata los datos de pago bajo su propia responsabilidad (PCI DSS). EscudoSST no recibe datos de tarjeta |

Consecuencias:
- Contrato de encargo de tratamiento con cada cliente (términos del servicio + anexo de tratamiento de datos) que lista subencargados y ubicación.
- EscudoSST trata los datos solo según instrucciones del cliente y para prestar el servicio.
- Lista pública de subencargados; aviso a clientes antes de agregar uno nuevo.

### 1.2 Consentimiento y base legal
- Datos de trabajadores para cumplir la Ley 29783: la base es una obligación legal del empleador. La empresa informa a sus trabajadores (cláusula informativa).
- Datos de salud (EMO, vigilancia, descansos, vulnerabilidad) son **datos sensibles**. Requieren tratamiento con medidas reforzadas; si el tratamiento excede lo que exige la ley SST, consentimiento expreso y por escrito. La plataforma permite registrar el consentimiento por trabajador (firma electrónica) y la cláusula informativa versionada (DOC).
- Al activar su acceso, el trabajador ve y acepta (o toma conocimiento de) la cláusula informativa de su empresa y la política de privacidad de la plataforma.
- WhatsApp: opt-in explícito (ver `09`).
- Reporte anónimo: no se recolecta identidad; IP solo para límites de uso, sin asociarla al reporte en la vista del cliente.
- **Participantes externos (VTA):** antes de registrarse o comprar aceptan el aviso de privacidad **de la empresa vendedora** (versionado: `privacy_notice_version`, `privacy_accepted_at`). El consentimiento para publicidad y recomendaciones por correo o WhatsApp es **separado, opcional y revocable** (`marketing_consent`); sin él solo ven recomendaciones dentro de su portal. Su documento y teléfono se guardan cifrados. No son trabajadores: no entran en `workers` ni en indicadores SST.
- **Representante de empresa compradora:** al asignar cupos declara que informó a sus trabajadores; las personas asignadas aceptan el aviso de privacidad en su primer ingreso.
- **Sitio web público (WEB):**
  - **Políticas versionadas:** Términos y condiciones, Política de privacidad y protección de datos, Política de cookies, Contrato de encargo de tratamiento (anexo para clientes) y Acuerdo de nivel de servicio viven en `legal_documents` / `legal_document_versions`. Una versión publicada no se edita; si la nueva exige **re-aceptación**, el usuario (o el administrador de cuenta, para Encargo y SLA) no continúa sin aceptarla. Cada aceptación queda en `legal_acceptances` (**inalterable**) con versión, `content_hash`, fecha y hora, IP, navegador y canal. Textos a redactar y validar con abogado.
  - **Formularios (demo, contacto, cotización, reclamo):** aviso de privacidad visible antes de enviar, aceptación registrada con su versión, y consentimiento de comunicaciones comerciales **separado, opcional y desmarcado**. Solo se piden los datos necesarios (el RUC es opcional en la demo).
  - **Cookies por categorías:** necesarias (siempre activas), preferencias, analítica y marketing. Ningún script no necesario se carga antes del consentimiento, tampoco los reproductores de YouTube o Vimeo que fijen cookies (se muestra la miniatura hasta aceptar); «Rechazar» tiene el mismo peso que «Aceptar»; la elección se guarda en `cookie_consents` con un identificador anónimo, la versión de la política y la IP en hash; se puede cambiar o retirar desde el pie y se vuelve a pedir al cambiar la política o al vencer `cookie_consent_validity` (parámetro). Proveedor de analítica por definir, preferentemente sin transferir identificadores a terceros.
  - **Testimonios:** solo se publican con consentimiento registrado de la persona (y de la empresa si se muestra su nombre o logo) y aprobación del dueño; el retiro del consentimiento los despublica de inmediato. Los de la demo se rotulan «ejemplo».
  - **WhatsApp y blog externo:** el botón flotante abre WhatsApp sin enviar datos del visitante; el bloque de últimos artículos lee WordPress desde el servidor, por lo que el navegador del visitante no contacta al blog hasta que pulsa un enlace.
  - **Libro de Reclamaciones:** los datos del consumidor (y del apoderado si es menor) se usan solo para atender el reclamo y para el reporte a la autoridad; no se usan para publicidad.
- **Recomendación de cursos:** usa solo cargo, área, sede, cursos, certificados, matrículas, compras, hallazgos de inspección y accidentes del área sin datos de salud del afectado. **Nunca datos médicos** ni `fitness_status`. Las sugerencias de IA requieren aprobación humana. Cada mensaje incluye cómo darse de baja y hay límite de envíos por persona.
- **Permanencia en el aula:** el registro de actividad (latidos) se informa como medio para acreditar horas de capacitación; solo mide interacción dentro del reproductor del curso, sin seguimiento fuera del aula ni captura de pantalla o cámara.

### 1.3 Flujo transfronterizo
Los servidores están **fuera del Perú** (Contabo, DigitalOcean, Backblaze, Elastic Email, BSP, IA, Vimeo y, según se elijan, pasarela y proveedor de comprobantes).
- Se documenta cada destino: proveedor, país/región, datos, finalidad, garantías (cláusulas contractuales).
- **Comunicación a la ANPD** del flujo transfronterizo según el reglamento (responsable de hacerlo, formato y oportunidad: a confirmar por el abogado). EscudoSST prepara la ficha técnica para sus clientes.
- Regiones elegidas para minimizar dispersión (región por definir en cada proveedor, ver `13`).
- **Lista pública de subencargados** (módulo WEB, `subprocessors`, publicada en el centro de confianza `/confianza`): por cada proveedor, nombre, finalidad, país, categorías de datos tratados y base de la transferencia internacional (por ejemplo, cláusulas contractuales). Es la misma información que respalda el Contrato de encargo de tratamiento y la Política de privacidad; publicar un cambio avisa a los administradores de cuenta. Solo se listan proveedores realmente contratados; los que estén «por definir» no aparecen hasta contratarse.

### 1.4 Derechos ARCO (y portabilidad si aplica)
- Los ejerce el trabajador ante su empleador (titular del banco). EscudoSST da las herramientas:
  - **Acceso:** el trabajador ve sus datos en la PWA; el empleador exporta la ficha completa del trabajador.
  - **Rectificación:** edición por RR. HH. con auditoría.
  - **Cancelación:** borrado o anonimización, salvo datos que la ley obliga a conservar (registros SST, EMO): se bloquean y se borran al vencer la retención.
  - **Oposición:** según caso; se registra.
- Registro de solicitudes ARCO por empresa con plazos de respuesta como parámetro.
- Si un trabajador escribe directo a EscudoSST, se deriva a su empleador.
- Los participantes externos ejercen ARCO ante la empresa vendedora desde su portal (acceso, rectificación, cancelación, baja de publicidad); los comprobantes y pedidos se conservan por la obligación tributaria (retención: parámetro, validar con contador).

### 1.5 Brechas de seguridad
1. Detección (alertas, reporte de un cliente o proveedor).
2. Contención y evaluación: datos y empresas afectadas, si incluye datos sensibles.
3. Aviso al cliente (responsable) sin demora indebida, con datos para que notifique.
4. Apoyo a la notificación a la ANPD y a los titulares según el reglamento (plazo: parámetro a confirmar).
5. Registro de la brecha, causa, medidas.
6. Revisión posterior y acciones correctivas.

Se mantiene un plan de respuesta con responsables y un simulacro periódico.

### 1.6 Medidas de seguridad exigidas
El reglamento exige medidas técnicas, organizativas y legales proporcionales, y documentarlas. Este documento + `15` + registros de acceso son parte de ese documento de seguridad.

### 1.7 Herramientas de captación y leads (WEB)
EscudoSST (GRUPO DE SOLUCIONES CREATIVAS SAC) es **titular** del banco de datos de leads y suscriptores; no es encargado de ningún cliente para estos datos.
- **Un lead por correo** (`marketing_leads`), alimentado por la calculadora de multas, el autodiagnóstico, los recursos con registro, la solicitud de partner, el boletín y las solicitudes de demo o contacto. Se piden solo los datos necesarios para entregar lo solicitado; ninguna herramienta pide datos de trabajadores identificables ni datos de salud.
- **Consentimiento comercial separado:** la aceptación de la Política de privacidad (obligatoria, con su versión) es distinta de la casilla de comunicaciones comerciales, que es **opcional, desmarcada y revocable** y se guarda con fecha (`marketing_consent`, `marketing_consent_at`). Sin ella, el lead solo recibe lo que pidió. La suscripción al boletín es consentimiento específico para el boletín y sus temas, con **doble confirmación** y **baja con un clic** (`List-Unsubscribe`); no habilita otros envíos publicitarios.
- **Tokens del boletín** (confirmación y baja) aleatorios y guardados solo como hash; las descargas de recursos guardan la IP en hash; las métricas del boletín (aperturas y clics) se guardan solo agregadas por edición y se informan en la Política de privacidad.
- **Bandeja de leads:** solo el Dueño de la plataforma asigna y exporta; soporte ve solo los leads asignados; cada exportación queda en `audit_logs`. Un lead se puede anonimizar a pedido (ARCO) desde su ficha.
- **Partners y referidos:** los datos del referido que ve el partner se limitan al estado del referido (sin datos SST de la empresa referida).
- **Casos de éxito:** autorización escrita del cliente adjunta (`consent_file_id`) para nombre, logo, cifras y cita; el retiro lo oculta de inmediato. Los ejemplos se rotulan «Ejemplo».
- **Centro de confianza y estado del servicio:** solo describen medidas implementadas; la página de estado nunca muestra datos de clientes.
- **Conservación:** los leads sin conversión se anonimizan al cumplir `web.lead_retention_months` (P-62 de `11`, a confirmar) sin nueva interacción; ver §9.

### 1.8 Sitio web v1.5 y v1.5-b: métricas, agenda, chatbot, CRM, ventanas emergentes y reseñas (WEB)
- **Métricas sin IP ni huellas:** `site_events` y `site_analytics_daily` no guardan IP, user agent completo, resolución de pantalla ni ningún dato para huella del navegador. `session_hash` es un hash de un identificador aleatorio en cookie propia con una **sal diaria** que se descarta al rotar; no permite seguir a una persona entre días. `lead_id` solo se asocia cuando el mismo envío creó un lead con su consentimiento.
- **Consentimiento analítico:** sin la categoría analítica aceptada no se envía ningún evento ni se carga GA4 ni Tag Manager; Meta Pixel exige la categoría de marketing. Revocar detiene la carga y borra las cookies propias. El banner sigue la regla de «rechazar tan fácil como aceptar».
- **Chatbot sin datos personales a la IA:** se minimiza el texto (correos, teléfonos, DNI, RUC y nombres detectados se retiran) antes de llamar al proveedor; el formulario de lead nunca se envía a la IA; el proveedor debe garantizar por contrato que no entrena con los datos (DA-44). Pedir datos en el chatbot exige privacidad y casilla comercial aparte.
- **Grabación solo con consentimiento:** las demos se graban solo si el visitante marcó `recording_consent` (P-65); los webinars, solo si la ficha y la inscripción lo informaron antes; nunca grabación automática.
- **Tokens de gestión de cita e inscripción como hash:** `demo_meetings.manage_token_hash` y `webinar_registrations.manage_token_hash` guardan solo el hash de un token aleatorio de 128 bits o más; el token caduca al cancelar o al pasar la hora de inicio; respuesta idéntica ante token inexistente o vencido.
- **CRM:** el envío es una comunicación a un encargado o a un sistema propio; solo datos comerciales del lead; el Libro de Reclamaciones nunca se envía; el webhook se firma con HMAC y los secretos son referencias.
- **Calendarios:** OAuth con alcances mínimos por anfitrión; tokens en el gestor de secretos.
- **Ventanas emergentes accesibles y no intrusivas:** diálogo con rol y nombre accesibles, foco inicial en el diálogo y devuelto al cerrar, cierre con Esc, botón visible y «No, gracias»; no tapan más de la mitad de la pantalla en móvil; frecuencia limitada (`frequency_days`, P-67); nunca en el Libro de Reclamaciones, las políticas, los formularios ni el checkout; no usan textos que culpen al visitante por cerrar.
- **Reseñas reales sin edición:** solo reseñas del perfil real en Google, con atribución, texto íntegro y sin selección manual; caché limitada (P-66); nunca reseñas inventadas ni testimonios presentados como reseñas (Ley 29571, publicidad veraz).
- **Calculadora de ahorro:** resultado rotulado «Estimación referencial, no garantizada» con supuestos visibles; no pide datos de trabajadores identificables.
- **Biblioteca de medios:** las imágenes con personas reales exigen autorización; las capturas usan datos ficticios rotulados «Ejemplo».

## 2. Capas de cifrado

| Capa | Dónde | Cómo |
|---|---|---|
| En tránsito | Navegador ↔ nginx; API ↔ base médica; API ↔ B2, Elastic Email, BSP, IA | TLS 1.2+ (preferente 1.3). HSTS. `verify-full` a la base médica |
| Disco del servidor | Contabo | LUKS en el volumen de datos (PostgreSQL, Redis persistente, temporales) |
| Campos sensibles en base principal | `escudo_main` | Laravel encrypted casts (AES-256-GCM) para: número de documento (con columna hash para búsqueda), celular, correo personal del trabajador, dirección, secretos de API/webhooks, tokens de terceros |
| Base médica | DigitalOcean | Cifrado en reposo del proveedor + cifrado de aplicación para notas clínicas y diagnósticos, con llave distinta |
| Archivos | B2 | SSE-B2; bucket médico separado |
| Respaldos | B2 (bucket de respaldos) | Cifrados del lado del cliente (age o GPG) antes de subir |
| Contraseñas | Base principal | Argon2id |

## 3. Gestión de llaves

- Llaves fuera del servidor de datos, en un gestor de secretos (proveedor por definir, ver `13`).
- Al arrancar, los contenedores obtienen las llaves con una identidad de corta vida; no se escriben en disco ni en `.env` del repositorio.
- Llaves separadas: `APP_KEY` (sesiones), llave de campos de base principal, llave médica, llave de respaldos, secretos de proveedores.
- Rotación: soportada por diseño (Laravel `APP_PREVIOUS_KEYS`; re-cifrado por cola). Periodicidad: parámetro.
- Llave de respaldos: copia offline custodiada por dos personas del dueño del producto.
- Acceso al gestor solo para el rol de despliegue y dos administradores nombrados.

## 4. Re-autenticación y `sensitive_access_logs`

- Toda lectura de dato médico (base médica o bucket médico) exige re-autenticación: clave adicional del usuario (distinta de la de acceso) o MFA.
- Emite un token médico ligado a la sesión, de corta vigencia (parámetro). Se invalida al cerrar sesión o cambiar de empresa.
- Cada lectura, descarga, exportación o escritura médica registra en `sensitive_access_logs` (inalterable): `user_id`, `company_id`, `worker_id`, recurso, acción, motivo (opcional u obligatorio según configuración), IP, user-agent, fecha.
- El trabajador también se re-autentica (PIN) para ver sus resultados.
- Reporte de accesos sensibles visible para el médico y el administrador de cuenta (quién accedió, no el contenido).
- Alertas por patrones anómalos (muchos trabajadores distintos en poco tiempo).

## 5. Autenticación, MFA, contraseñas y sesiones

| Tema | Regla |
|---|---|
| MFA | TOTP. Obligatorio para: `platform_owner`, `platform_support`, `account_admin`, `occupational_doctor`, `health_staff`, `consultant`. Recomendado al resto. Códigos de recuperación de un solo uso |
| Contraseñas | Mínimo 12 caracteres; verificación contra listas de contraseñas filtradas; sin reglas de composición forzadas; Argon2id |
| Trabajador | DNI + código de activación de un solo uso; luego PIN de 6 dígitos ligado al dispositivo + sesión larga renovable (detalle en §5.1) |
| Bloqueo | Por intentos fallidos con espera creciente (por cuenta y por IP) |
| Recuperación | Enlace de un solo uso con vencimiento; avisa al usuario por correo |
| Sesiones | Cookies `HttpOnly`, `Secure`, `SameSite=Lax`. Inactividad: parámetro (más corto para roles médicos). Lista de sesiones activas con cierre remoto |
| Dispositivos offline | Registrados en `devices`; revocables (ver `16`) |
| SSO | No en V1 |

### 5.1 App del trabajador: activación y PIN (implementado)

- **Activación.** El responsable SST o RR. HH. (permiso `trb.workers.update`) la genera desde la ficha 360° del trabajador: código de 8 dígitos y enlace `/mi/activar?t=…` (48 caracteres), de un solo uso, con vencimiento `escudo.worker_app.activation_hours` (72 h por defecto). El código solo vale junto con el DNI del trabajador. Se envían por correo (si el trabajador tiene correo personal) y por WhatsApp (solo con la API en vivo y si el trabajador aceptó el canal); siempre se muestran para imprimirlos. Se guardan en la caché solo por su huella SHA-256; la huella de la activación vigente queda en `workers.activation_code_hash`, así que generar una nueva anula la anterior. Un DNI ajeno, un código usado, reemplazado o vencido responden lo mismo (410).
- **Cuenta.** Al activar se crea (si no existe) el usuario con rol `worker` y la membresía ligada a la ficha (`memberships.worker_id`). Sin correo propio se usa una dirección técnica del dominio reservado `.invalid`, que nunca recibe mensajes.
- **PIN.** De 6 números; se rechazan los repetidos y los seguidos (111111, 123456). Se guarda con hash (`workers.pin_hash`, el mismo algoritmo de las contraseñas); nunca en claro ni en el navegador.
- **Equipo.** Al activar, el equipo recibe un secreto aleatorio de 48 caracteres que solo se entrega esa vez; en la base queda su SHA-256 (`devices.credential_hash`, dispositivo `web_pwa` con `pin_bound`). Para ingresar se envían empresa, id del equipo, su secreto, DNI y PIN: sin el secreto del equipo el PIN no sirve. Revocar el dispositivo (M-OFF) lo deja sin acceso; un código nuevo permite activar otro equipo (por ejemplo, si olvidó su PIN) y reemplaza el PIN.
- **Bloqueo.** Tras `escudo.worker_app.pin_max_attempts` (5) PIN equivocados seguidos, el PIN se bloquea `pin_lock_minutes` (15) minutos. El contador es por trabajador y cuenta el ingreso, las firmas y la re-autenticación médica; vive en la caché para que no se pierda aunque la petición se revierta. Un DNI ajeno en el ingreso cuenta como intento fallido y responde igual que un PIN equivocado.
- **MFA.** El rol `worker` no exige MFA (docs/05). El ingreso con PIN solo se permite si la persona no tiene otros roles en ninguna empresa, ni MFA activo, ni es del equipo de la plataforma: en ese caso entra con correo o DNI y contraseña. Así el PIN nunca abre accesos de gestión.
- **Firmas.** Las firmas desde la app (solicitudes de FIR, cargos de documentos y del RISST, recepción de EPP) usan el método `pin` con evidencia completa en `signatures` (huella del registro, IP, navegador, canal `worker_app`).
- **Datos médicos.** «Mis resultados médicos» exige el PIN (o la contraseña) y emite una clave médica de corta vida ligada al usuario, la empresa y el trabajador; solo devuelve los exámenes del propio trabajador ya revisados por el médico. Cada lectura y descarga queda en `sensitive_access_logs` (rol `worker`) y en el registro de accesos de la base médica.

## 6. OWASP (Top 10 y ASVS nivel 2 como referencia)

| Riesgo | Control |
|---|---|
| Control de acceso roto | Policies por permiso, RLS forzado, 404 para recursos de otra empresa, pruebas de aislamiento obligatorias |
| Fallas criptográficas | §2 y §3 |
| Inyección | Eloquent/consultas parametrizadas; prohibido SQL concatenado; validación con Form Requests |
| Diseño inseguro | Revisión de amenazas por módulo sensible (EMO, FOT, ACT, SUN, OFF) |
| Configuración | Contenedores sin root, cabeceras de seguridad (CSP estricta, X-Frame-Options, Referrer-Policy), debug apagado en producción |
| Componentes vulnerables | Dependabot/Renovate; `composer audit` y `npm audit` en CI |
| Autenticación | §5 |
| Integridad | Firmas de webhooks entrantes; hash de firmas; imágenes Docker fijadas por digest |
| Registro y monitoreo | `audit_logs`, logs estructurados, alertas (ver `15`) |
| SSRF | Sin URLs arbitrarias del usuario; servicio PDF sin salida a internet |
| XSS | React escapa por defecto; prohibido `dangerouslySetInnerHTML` salvo HTML saneado (editor de documentos) |
| CSRF | Sanctum con cookies + token CSRF |
| Subida de archivos | Lista blanca de tipos, verificación de magic bytes, tamaño máximo, re-codificación de imágenes, antivirus |

Prueba de penetración externa antes de producción y luego periódica (parámetro).

## 6.1 Datos de pago (VTA)

- **Jamás se almacenan, registran ni transmiten por el servidor datos de tarjeta** (número, vencimiento, código de seguridad). El formulario o la página de la pasarela tokeniza en el navegador; el servidor solo recibe un token de un solo uso para crear el cargo y guarda la referencia externa del cargo, el tipo de medio y el estado.
- La página de pago carga el script de la pasarela solo desde su dominio oficial, con CSP estricta; no se inyectan scripts de terceros en el checkout.
- Llaves secretas de la pasarela y secretos de webhook solo como **referencia a secreto** en el gestor; nunca en la base, en logs ni en el repositorio. Rotación documentada.
- Los logs y las colas filtran campos con forma de tarjeta (PAN) por defensa en profundidad; una prueba automática verifica que no aparezcan (ver `12`).
- Los montos y estados se confirman solo por webhook firmado o consulta a la pasarela; nunca por parámetros de la URL de retorno.
- Los reembolsos y las matrículas manuales quedan en `audit_logs` con usuario.

## 7. Endpoints públicos

Endpoints: sitio web público (WEB: contenido publicado, preferencias de cookies, hoja y seguimiento del Libro de Reclamaciones, solicitud de demo, calculadora de multas, autodiagnóstico, descarga de recursos, centro de ayuda y «¿Te sirvió?», solicitud de partner, boletín, subencargados y estado del servicio), verificación de fotocheck, reporte por QR, webhook de WhatsApp, webhooks de proveedores, **webhook de pagos** (`/api/v1/webhooks/payments/{gateway_id}`), vista pública del expediente SUNAFIL, activación del trabajador, **verificación pública de certificados**, **catálogo público de cursos y checkout**.

| Control | Regla |
|---|---|
| Límites de uso | Por IP, por token y global (Redis). Valores como parámetro |
| Tokens | Aleatorios de 128 bits o más, opacos, guardados como hash; revocables |
| Captcha | En reporte por QR y activación (proveedor por definir; preferir uno respetuoso de privacidad) |
| Datos mínimos | Solo lo especificado en el módulo. Fotocheck: nunca datos médicos |
| Enumeración | Respuestas idénticas para token inexistente y revocado (salvo el mensaje «No válida» especificado en FOT) |
| Firma | Webhooks verifican firma del emisor. Pagos: firma con el secreto de la empresa y pasarela; firma inválida = rechazo registrado sin efectos; idempotencia por id de evento y por estado; reintentos repetidos con firma inválida generan alerta |
| Registro | Cada acceso a expediente compartido, cada verificación de fotocheck y cada verificación de certificado (`certificate_verifications`) se registra |
| Certificados | Verificación desactivada por defecto; por empresa y por curso; código no enumerable; respuesta idéntica para inexistente y no verificable; documento enmascarado o ausente |
| Catálogo y checkout | Límite por IP y protección contra automatización; no exponen datos de participantes |
| Sitio web (WEB) | Formularios de demo y de reclamo con captcha (proveedor por definir) y límite por IP; el seguimiento de un reclamo exige código + documento del consumidor (o token de la constancia guardado como hash) y responde igual ante código inexistente o documento erróneo; contenido de solo lectura cacheado |
| Sitio web v1.5 y v1.5-b (WEB) | `POST /public/demo/book`, `POST /public/webinars/{slug}/register`, `POST /public/tools/savings-calculator` y `POST /public/chatbot/conversations/{id}/lead` con captcha, límite por IP e `Idempotency-Key`; `POST /public/events` solo con consentimiento analítico, sin datos personales y con límite por IP; los mensajes del chatbot con límite por conversación e IP y tamaño máximo; la gestión de citas e inscripciones usa tokens guardados como hash; `GET /public/demo/slots` no revela nombres de otros visitantes ni citas; `GET /public/reviews` sirve solo la copia vigente |
| Herramientas de captación (WEB) | Todos los POST (calculadora, autodiagnóstico, descarga de recursos, «¿Te sirvió?», partners, boletín y avisos de estado) con captcha, límite por IP e `Idempotency-Key`; la respuesta no revela si un correo ya existía; la confirmación y la baja del boletín usan tokens de un solo uso guardados como hash; los recursos se entregan solo por URL firmada de corta duración; `/estado` se sirve desde caché propia |

## 8. Respaldos y restauración

- Base principal: respaldo completo diario + WAL continuo (recuperación a un punto en el tiempo). Base médica: respaldos del proveedor + volcado lógico propio.
- Todo cifrado antes de salir del servidor; guardado en el bucket de respaldos con bloqueo de objetos (inmutable) durante la retención.
- **Restauración probada** periódicamente en un entorno aislado, con registro del resultado (ver `15`).
- Los respaldos de la base médica y de la principal usan llaves distintas.

## 9. Retención y borrado

| Dato | Retención | Nota |
|---|---|---|
| Registros SST obligatorios (accidentes, enfermedades, incidentes, EMO, monitoreo, etc.) | Parámetro por registro (la normativa fija plazos distintos; a confirmar) | Ver `11` |
| Historia clínica ocupacional | Parámetro (a confirmar) | Base médica |
| `audit_logs`, `sensitive_access_logs` | Parámetro | Inalterables |
| Empresa cancelada | Solo lectura y exportación durante un periodo (parámetro); luego borrado de datos operativos | Los datos con obligación de conservación se entregan al cliente en la exportación final |
| Respaldos | Rotación por parámetro | El borrado en producción llega a los respaldos cuando estos expiran |
| Logs técnicos | Corta (parámetro) | Sin datos personales |
| Hojas del Libro de Reclamaciones (`consumer_complaints`, `complaint_events`) | Parámetro `complaint_retention_years` (referencia: 5 años desde el registro; a confirmar con abogado) | **No se pueden eliminar** ni editar sus datos originales; `retention_until` se calcula al registrar; vencido el plazo solo se anonimizan con aprobación del dueño. Excluidas del job de borrado general |
| Aceptaciones legales (`legal_acceptances`) | Mientras dure la relación y el plazo de prescripción (parámetro, a confirmar) | Inalterables |
| Consentimientos de cookies | `cookie_consent_validity` y luego se purgan | Identificador anónimo e IP en hash |
| Solicitudes de demo, contacto o cotización no convertidas | Parámetro (a confirmar) | Luego se anonimizan; se respeta la baja de comunicaciones comerciales |
| Leads sin conversión (`marketing_leads` y sus cálculos, autodiagnósticos y solicitudes de partner) | `web.lead_retention_months` desde la última interacción (P-62, a confirmar con abogado) | Se anonimizan (correo, nombre, teléfono, RUC); se conservan los conteos agregados; los convertidos pasan a los datos de la cuenta |
| Suscriptores del boletín dados de baja | Solo el correo en hash para no reenviar (parámetro, a confirmar) | Los `pending` sin confirmar se depuran al vencer el token |
| Descargas de recursos y votos «¿Te sirvió?» | Parámetro | Inalterables; sin IP en claro |
| Informes PDF del autodiagnóstico | Parámetro | Se borran de B2 al anonimizar el lead |
| Eventos de métricas (`site_events`) | `web.analytics_event_retention_months` (P-63, a confirmar) | Inalterables; al vencer se eliminan y queda solo `site_analytics_daily` agregado |
| Conversaciones del chatbot (`chatbot_conversations`, `chatbot_messages`) | `web.chatbot_retention_months` (P-64, a confirmar) | Mensajes inalterables; al vencer se eliminan; el lead creado sigue su propia conservación (P-62) |
| Reuniones de demo e inscripciones a webinars | Como las solicitudes y leads no convertidos | Al anonimizar se borran nombres, correos y notas; los tokens ya son solo hash |
| Copias de reseñas (`review_snapshots`) | `cache_ttl_hours`, con tope `web.review_cache_max_hours` (P-66) | Se borran al vencer |
| Envíos al CRM (`crm_sync_events`) | Parámetro | Solo metadatos del envío, sin copia del cuerpo enviado |

Borrado: job programado con registro de qué se borró (sin el contenido). Borrado de archivos en B2 incluido.

## 10. Organización

- Principio de mínimo privilegio en personas y servicios.
- Acceso a producción solo para administradores nombrados, con MFA y registro.
- Acuerdos de confidencialidad con todo el personal y practicantes con acceso.
- Datos reales nunca en desarrollo ni pruebas (ver `12`).
- Capacitación anual en protección de datos para el equipo.

## 11. Puntos que debe confirmar el abogado

1. Rol de EscudoSST (encargado) y de la consultora frente a la empresa cliente.
2. Base legal y necesidad de consentimiento expreso para cada tratamiento de datos de salud.
3. Responsable, contenido y oportunidad de la comunicación del flujo transfronterizo a la ANPD.
4. Plazos de notificación de brechas a la ANPD y a titulares.
5. Plazos de respuesta ARCO.
6. Inscripción de bancos de datos y quién la hace.
7. Plazos de conservación de registros SST y de historia clínica ocupacional.
8. Validez de la firma simple con evidencias para cada tipo de registro.
9. Tratamiento de datos de menores (trabajadores adolescentes) y personas con discapacidad (VUL).
10. Tratamiento de imágenes (fotos de fotocheck y evidencias con personas).
11. Aviso de privacidad y consentimiento de publicidad de participantes externos (VTA) y envío de recomendaciones de cursos por correo y WhatsApp.
12. Compras de cursos por menores de edad.
13. Libro de Reclamaciones virtual cuando la empresa vende cursos a consumidores (Ley 29571). Es distinto del Libro de Reclamaciones de EscudoSST (módulo WEB).
14. Registro de actividad para medir permanencia en el aula: finalidad e información al titular.
15. Textos de Términos y condiciones, Política de privacidad, Política de cookies, Contrato de encargo de tratamiento y SLA del sitio web (WEB), y cuándo un cambio exige re-aceptación.
16. Libro de Reclamaciones de EscudoSST: contenido de la hoja, plazo de respuesta y prórroga, conservación, formato del reporte para Indecopi (ver `11`).
17. Banner de cookies: categorías, vigencia del consentimiento y proveedor de analítica compatible.
18. Modelo de consentimiento para publicar testimonios (nombre, cargo, logo de la empresa) y veracidad de sellos de confianza (Ley 29571).
19. Texto de la casilla de comunicaciones comerciales de los formularios de captación y del boletín, y si la doble confirmación basta como evidencia del consentimiento.
20. Plazo de conservación de los leads sin conversión (P-62) y de los suscriptores dados de baja.
21. Contenido mínimo de la lista pública de subencargados y forma de avisar sus cambios a los clientes (Contrato de encargo de tratamiento); transferencia internacional de cada proveedor.
22. Modelo de autorización escrita del cliente para publicar casos de éxito (nombre, logo, cifras y cita).
23. Aviso «referencial, no es asesoría legal» de la calculadora de multas y del autodiagnóstico.
24. Consentimiento de cookies analíticas y de marketing para métricas propias, GA4, Tag Manager y Meta Pixel; plazo de conservación de eventos (P-63).
25. Información y consentimiento para grabar demos y webinars (P-65) y para guardar conversaciones del chatbot (P-64).
26. Envío de leads a Kallpa CRM o a un CRM externo: rol de cada parte y lista de subencargados; proveedor de IA del chatbot y proveedor de calendario como encargados con transferencia internacional.
27. Uso de reseñas de Google en el sitio (atribución y caché, P-66) y aviso de la calculadora de ahorro («estimación referencial, no garantizada»).
