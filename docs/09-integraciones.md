# 09 · Integraciones

Todo proveedor externo vive detrás de una interfaz en `app/Core/Contracts` (ver `01-arquitectura.md` §2.1). Credenciales en el gestor de secretos, nunca en el repositorio. Cada integración tiene un modo `fake` para desarrollo y pruebas.

## 1. Backblaze B2 (almacenamiento)

Interfaz: `FileStorage`. API compatible con S3.

| Aspecto | Regla |
|---|---|
| Buckets | `escudosst-<env>-files` (general), `escudosst-<env>-medical` (solo archivos de tablas `db: med`), `escudosst-<env>-backups` (respaldos) |
| Cifrado | SSE-B2 activado por defecto en los tres buckets. Respaldos además cifrados antes de subir (ver `15`) |
| Visibilidad | Todos privados. Sin acceso público |
| Subida | La API emite URL firmada de subida (PUT) con tipo y tamaño máximo. El cliente sube directo. Luego `POST /files/{id}/confirm`: la API verifica existencia, tamaño, tipo real y SHA-256 |
| Descarga | URL firmada de corta duración (parámetro; menor en el bucket médico) tras validar permiso. Nunca se guardan URLs firmadas |
| Claves | `<company_id>/<module>/<entity_id>/<uuid>.<ext>`. Sin nombres ni DNI |
| Llaves de aplicación B2 | Una por bucket, con permisos mínimos. La del bucket médico solo la usa el código del módulo médico |
| Ciclo de vida | Versiones de objetos activadas en respaldos; borrado según retención (ver `10`) |
| Antivirus | Escaneo en cola antes de marcar `available` (herramienta por definir) |
| Archivos médicos | Toda descarga registra en `sensitive_access_logs` |

## 2. Elastic Email (correo transaccional)

Interfaz: `MailChannel`.

| Aspecto | Regla |
|---|---|
| Dominio remitente | Subdominio dedicado de escudosst.com (p. ej. `notificaciones.escudosst.com`; dominio decidido en DA-05, editable en `site_settings`) con SPF, DKIM y DMARC |
| Marca blanca | La consultora puede verificar su propio dominio remitente; si no, sale con el de EscudoSST y su nombre comercial |
| Plantillas | Versionadas en el repositorio (Blade → HTML), no en el panel del proveedor. Envío como HTML ya renderizado |
| Tipos | Invitación, verificación de correo, recuperación de clave, alerta de vencimiento, resumen diario/semanal, aprobación pendiente, expediente listo, aviso de suscripción, aviso de acceso de soporte; aula y venta de cursos: matrícula, certificado emitido o anulado, pedido pagado o rechazado, cupo asignado, comprobante, reembolso y recomendaciones de cursos |
| Recomendaciones de cursos | A participantes externos solo con `marketing_consent` vigente; límite de envíos por persona y periodo; enlace de baja en cada mensaje |
| Contenido prohibido | Datos médicos, diagnósticos, restricciones médicas, DNI completo. Los correos enlazan a la app |
| Rebotes y quejas | Webhook de Elastic Email → `notification_deliveries` (inalterable) con estado. Rebote duro: se marca el correo como inválido y se avisa al administrador |
| Envío | Siempre por cola. Reintentos con espera creciente |
| Bajas | Alertas operativas configurables en preferencias; correos de seguridad no se pueden desactivar |

### 2.1 Boletín normativo SST y avisos de estado (módulo WEB)

Usa la misma interfaz `MailChannel` (Elastic Email), con un **flujo de envío masivo separado** del transaccional para no afectar la entrega de alertas.

| Aspecto | Regla |
|---|---|
| Remitente | Subdominio propio para el boletín (p. ej. `boletin.escudosst.com`, editable) con SPF, DKIM y DMARC, distinto del de notificaciones; reputación separada |
| Doble confirmación | La suscripción (`POST /public/newsletter/subscribe` o `/public/status/subscribe`) crea `newsletter_subscribers` en `pending` y envía un correo transaccional con enlace de confirmación de un solo uso (token aleatorio; en la base solo su hash; vigencia `newsletter_confirm_token_validity`). Solo `POST /public/newsletter/confirm` con el token vigente pasa a `confirmed` |
| Baja con un clic | Cada envío lleva enlace de baja con token propio y los encabezados `List-Unsubscribe` y `List-Unsubscribe-Post` (RFC 8058); `POST /public/newsletter/unsubscribe` deja `unsubscribed` sin iniciar sesión. Las bajas y quejas que informe Elastic Email por webhook también marcan `unsubscribed` |
| Lista | La gestiona EscudoSST en `newsletter_subscribers` (fuente de verdad), no en listas del proveedor; al enviar, se pasan solo correo y variables de personalización necesarias |
| Envío de ediciones | `newsletter_issues` en `scheduled` o enviada a mano (`web.newsletter_issues.send`) → trabajo en cola que envía por lotes a los `confirmed` con temas en común; idempotencia por edición y suscriptor (un reintento no duplica); al terminar fija `sent_at` y `recipients_count` |
| Métricas | Aperturas y clics por webhook o consulta a Elastic Email, guardados solo agregados por edición (`opens_count`, `clicks_count`); sin perfil individual de lectura |
| Avisos de estado | Mismo flujo con el tema `service_status`; cada actualización publicada de un incidente genera un envío a esos suscriptores |
| Contenido prohibido | Datos de clientes, datos médicos o personales de terceros |
| Pruebas | Implementación `fake` de `MailChannel` que guarda los envíos; envío de prueba a un correo del dueño antes de programar |

Otros correos transaccionales de WEB: resultado completo de la calculadora de multas, informe del autodiagnóstico (enlace firmado, no adjunto), enlace de recurso con registro, acuse y decisión de partner, aviso de cambio en la lista de subencargados.

## 3. WhatsApp (Business API vía BSP)

Interfaz: `MessagingChannel`. BSP: **por definir** (ver `13`).

| Aspecto | Regla |
|---|---|
| Usos v1 | Alertas de vencimiento y pendientes; enlace de activación de la PWA; reporte de actos/condiciones (conversación guiada); aviso de PETAR por aprobar; matrícula y recordatorios del aula; recomendaciones de cursos (con opt-in y, en participantes externos, consentimiento de publicidad) |
| Plantillas | Aprobadas por Meta, registradas en `notification_rules` con su nombre y variables. Sin datos médicos ni sensibles en variables |
| Opt-in | Obligatorio antes de enviar. Se registra fecha, canal y texto aceptado. Opt-out con «BAJA» o desde preferencias |
| Número | Uno por plataforma en V1. Número propio por consultora: decisión futura |
| Webhook entrante | `POST /api/v1/webhooks/whatsapp`: verifica firma del BSP, idempotencia por id de mensaje, límite de uso |
| Reporte por WhatsApp | El número identifica al trabajador si está registrado y con opt-in; si no, reporte anónimo vinculado al punto (por código del QR) |
| Ventana de 24 h | Fuera de la ventana solo plantillas aprobadas |
| Registro | Cada envío en `notification_deliveries` |

## 4. DigitalOcean Managed PostgreSQL (base médica)

| Aspecto | Regla |
|---|---|
| Conexión | Conexión Laravel `medical`, separada de `main` |
| TLS | `sslmode=verify-full` con el certificado CA del clúster |
| Red | Lista de IP permitidas (Trusted Sources): solo las IP de Contabo de producción (y la de pruebas en su propio clúster) |
| Usuarios | `escudo_med_app` (sin BYPASSRLS, sin ser dueño); `escudo_med_migrator` solo para migraciones en el despliegue |
| RLS | Igual que la principal, con `app.company_id` |
| Cifrado | En reposo por el proveedor. Campos especialmente sensibles (notas clínicas) además con cifrado de aplicación con llave distinta a la de la base principal |
| Pool | Conexiones limitadas; pool del proveedor en modo transacción con `SET LOCAL` |
| Respaldos | Los del proveedor + volcado lógico cifrado propio al bucket de respaldos (ver `15`) |
| Caída | Si la base médica no responde, los módulos médicos muestran «Temporalmente no disponible». El resto del sistema sigue (la base principal tiene `fitness_status`) |

## 5. Firma electrónica

Interfaz: `SignatureProvider`. Módulo FIR.

### v1: firma simple con evidencias
Cada firma crea un registro inalterable en `signatures` con:
- Firmante (`user_id` o `worker_id`), rol con que firma, documento/registro firmado.
- Hash SHA-256 del contenido firmado (PDF o JSON canónico).
- Método: trazo (imagen en B2), PIN, o confirmación con sesión + MFA.
- Fecha y hora (servidor), IP, user-agent, geolocalización si el módulo la pide y el usuario la permite, id de dispositivo (offline).
- Para firmas offline: hora del dispositivo y hora de recepción.

Se genera un comprobante PDF con estos datos. Cualquier cambio posterior del registro invalida la firma (hash distinto) y exige nueva firma.

### Firma digital con certificado
**Decisión abierta** (ver `13`). Opciones: proveedor acreditado en el Perú (IOFE/INDECOPI) o firma con DNIe. La interfaz ya lo permite.

## 6. Exportación PDF y Excel

| Formato | Herramienta | Reglas |
|---|---|---|
| PDF | Servicio interno Chromium (`PdfRenderer`) desde plantillas HTML | Registros obligatorios en formato referencial de la R.M. 050-2013-TR. Encabezado con datos del empleador (RUC, razón social, actividad, dirección, número de trabajadores). Pie con fecha de generación, usuario y código de verificación. Marca blanca si aplica |
| Excel | PhpSpreadsheet (streaming para listas grandes) | Listas con los filtros aplicados. Fechas como fecha real, no texto |
| ZIP | Worker | Expediente SUNAFIL, exportación total de la empresa |

- Exportaciones grandes: por cola; aviso cuando están listas; enlace firmado con vencimiento.
- Exportaciones con datos médicos: solo el médico, desde el bucket médico, con marca de agua y registro.

### 6.1 Informe PDF del autodiagnóstico (módulo WEB)

- Al recibir `POST /public/tools/self-assessment`, el servidor calcula puntajes y nivel, guarda `assessment_submissions` y encola la generación del informe en el mismo `PdfRenderer` (plantilla HTML versionada en el repositorio, con el logo vigente de `site_settings`).
- Contenido: datos de la empresa declarados, fecha, versión del cuestionario, puntaje total, puntaje por sección en gráfico, nivel, recomendaciones por sección, aviso «Resultado referencial; no constituye asesoría legal», rótulo «Cuestionario en validación por especialista SST» si `to_validate`, y CTA a demo. Sin datos de trabajadores.
- El PDF se guarda en B2 (bucket principal, cifrado del lado del servidor) y se enlaza en `report_file_id`; el correo lleva una URL firmada de corta duración, nunca el archivo adjunto ni la URL del bucket.
- La generación es idempotente por envío: un reintento de la cola no crea otro archivo ni otro correo.
- El mismo mecanismo genera, si el dueño lo activa, el resumen PDF del resultado de la calculadora de multas.

### 6.2 Captcha de los formularios públicos

Interfaz `CaptchaVerifier` (proveedor por definir, DA-13), usada por todos los POST públicos de WEB: demo, contacto, cotización, Libro de Reclamaciones y herramientas de captación. Implementación `fake` en desarrollo y pruebas; la verificación se hace en el servidor antes de cualquier escritura.

## 7. API pública y webhooks (módulo API, plan Corporativo)

| Aspecto | Regla |
|---|---|
| Autenticación | Client credentials (OAuth2) por `api_clients`, con alcances (`workers:read`, `trainings:write`…) y empresa fija |
| Versionado | `/api/public/v1`. Cambios incompatibles = nueva versión |
| Límites | Por cliente y por minuto (parámetro por plan) |
| Recursos v1 | Trabajadores (alta/baja/cambio de puesto), capacitaciones y asistencias, credenciales (estado), eventos SST (lectura), acciones correctivas (lectura) |
| Prohibido | Datos médicos por API pública. Solo `fitness_status` |
| Webhooks | Eventos: `worker.created`, `credential.status_changed`, `sst_event.registered`, `corrective_action.overdue`, `training.completed`. Firma HMAC en cabecera, reintentos con espera creciente, registro en `webhook_deliveries` |
| Contrato | En `api/openapi.yaml` (sección pública) |

## 8. Importación desde planillas

- **Fuente:** Excel con la plantilla de EscudoSST. La empresa la llena con lo que exporta de su sistema de planillas o de T-Registro.
- **Sin integración directa con T-Registro ni con SUNAT en v1.**
- Columnas mínimas: tipo y número de documento, nombres, apellidos, fecha de ingreso, sede, área, puesto. Opcionales: correo, celular, fecha de nacimiento, sexo, régimen, fecha de cese.
- Clave de coincidencia: tipo + número de documento dentro de la empresa.
- Flujo: máquina de estados de carga masiva (`06` §23). Validación por fila, vista previa y confirmación.
- Bajas: una fila con fecha de cese da de baja al trabajador y dispara revocación de fotocheck.
- Plan Corporativo: la misma operación por API pública.
- Consulta de RUC para onboarding: fuente por definir (ver `13`).

## 9. IA (proveedor LLM)

Interfaz: `AiProvider`. Proveedor **por definir** (ver `13`). Reglas completas en `17-asistente-ia.md`.

- Llamadas solo desde workers (nunca desde el navegador).
- Entrada minimizada y sin datos médicos ni personales innecesarios; seudónimos cuando hace falta referirse a personas.
- Respuesta en JSON validado contra esquema; si no valida, se descarta.
- Toda solicitud en `ai_requests` (sin el texto completo si contiene datos de personas; ver `17`).
- El proveedor elegido debe garantizar por contrato que no entrena con los datos y su ubicación de procesamiento se declara en el flujo transfronterizo (ver `10`).
- Lectura de documentos de contratistas: OCR/visión del mismo proveedor o servicio aparte, detrás de la misma interfaz.

## 10. Video de cursos: Vimeo (módulo AUL)

Interfaz: `VideoProvider`. Implementación: **Vimeo**. Cuenta: **por definir** si es una cuenta de EscudoSST compartida o la cuenta propia de cada empresa (ver `13`).

| Aspecto | Regla |
|---|---|
| Qué se guarda | Solo `video_external_id`, duración y estado en `course_lessons`. Nunca el archivo en la base ni en B2 |
| Subida | Desde el editor del curso, directo a Vimeo (subida reanudable); la API recibe el identificador al terminar |
| Privacidad | Videos ocultos en Vimeo y con reproducción embebida restringida al dominio de EscudoSST (o al dominio de marca blanca verificado) |
| Reproductor | Reproductor embebido con su API de eventos (reproducción, pausa, avance) para medir segundos realmente reproducidos; adelantar no suma |
| Datos personales | Ninguno se envía a Vimeo: el reproductor no recibe nombre, correo ni documento del participante |
| Credencial | Token de la cuenta como referencia a secreto |
| Caída | Si Vimeo no responde, la actividad de video muestra «Temporalmente no disponible»; el resto del curso sigue |

## 11. Moodle por API (opcional, módulo AUL)

**El aula virtual de EscudoSST es un portal propio de cursos y no depende de otra plataforma.** La conexión con Moodle es **opcional**, solo para el cliente que prefiera seguir con su Moodle. No se integran otras plataformas de cursos (ver `13`).

- **Interfaz `LmsProvider`** con una sola implementación: **Moodle** (Web Services REST con token de servicio; LTI 1.3 o enlace firmado para el inicio de sesión único, si la instalación lo permite). Funciones: listar cursos, matricular, leer avance y notas.
- **Conexión por empresa** en `lms_connections` (`provider = moodle`); URL solo `https`; la credencial se guarda como referencia a un secreto, nunca en la base.
- **Sincronización** programada (y por evento si la instalación lo permite); idempotente por `external_enrollment_id` y `external_attempt_id`. Toda aprobación se convierte en asistencia y certificado en CAP, lo que actualiza la matriz por cargo, el fotocheck y el registro de capacitación.
- **Datos enviados:** nombre, documento, correo y cargo del trabajador. Nunca datos médicos.
- Tras `lms_sync_failure_threshold` fallas seguidas la conexión pasa a `error` y se avisa.

## 12. Pasarelas de pago (módulo VTA)

Interfaz: `PaymentGateway`. Proveedor: **por definir** entre Culqi, Niubiz, Izipay y Mercado Pago (ver `13`). Cada empresa que vende cursos configura **su propia cuenta** de pasarela; el dinero va a la empresa, no a EscudoSST.

| Aspecto | Regla |
|---|---|
| Datos de tarjeta | **Nunca** pasan por EscudoSST: el formulario o la página de la pasarela tokeniza en el navegador; el servidor recibe un token de un solo uso y guarda solo la referencia del cargo. Alcance PCI mínimo |
| Yape | Se cobra a través de la pasarela elegida, no con integración directa |
| Llaves | Llave pública como dato; llave secreta y secreto del webhook como referencia a secreto (`secret_key_ref`, `webhook_secret_ref`) |
| Creación del cargo | Con `idempotency_key` por pedido e intento: reintentar no cobra dos veces |
| Webhook | `POST /api/v1/webhooks/payments/{gateway_id}`: verifica la firma con el secreto de esa empresa; firma inválida = rechazo y registro sin efectos; idempotencia por id de evento (la restricción única por (pasarela, id externo) de `payment_transactions`) y por estado (no retrocede) |
| Confirmación | El pago se da por confirmado solo por webhook o por consulta a la pasarela; nunca por la redirección del navegador |
| Conciliación | Job que consulta los pedidos pendientes sin webhook (`vta_reconcile_after_minutes`) con las mismas reglas |
| Reembolsos | Por API de la pasarela contra el cargo original; total o parcial |
| Entornos | Pruebas y producción separados por empresa; modo `fake` (simulador) en desarrollo y CI |

## 13. Comprobantes electrónicos de la venta de cursos (módulo VTA)

Interfaz: `ElectronicInvoiceProvider`. Proveedor (OSE/PSE): **por definir** (ver `13`). **Validar con contador** el tratamiento del IGV de los cursos, las series y los casos de boleta y factura.

- Los comprobantes son **de la empresa vendedora** (su RUC, sus series), no de EscudoSST. Son distintos de los comprobantes de la suscripción de EscudoSST (`invoices` de SUS, interfaz `InvoiceIssuer`), aunque pueden usar el mismo tipo de proveedor.
- Boleta o factura según los datos del comprador; nota de crédito en reembolsos.
- Se guardan PDF, XML y CDR en B2; estado del proveedor en `sales_documents`; reintentos ante errores de envío.
- Implementación inicial manual (carga del comprobante emitido fuera) mientras no se elija proveedor.

## 14. Calendario y reuniones de demo: Google Calendar / Meet y Microsoft 365 / Teams (módulo WEB)

Interfaz: `CalendarProvider` (implementaciones `google`, `microsoft` y `fake`). Proveedor o proveedores: **por definir** (DA-43).

- **Conexión por OAuth 2.0 por anfitrión** (`demo_hosts.calendar_provider`): alcances mínimos para leer disponibilidad (libre/ocupado) y crear, mover y cancelar eventos propios; nunca leer el contenido de otros eventos. El token de actualización se guarda en el gestor de secretos y la tabla solo tiene `calendar_secret_ref`.
- **Disponibilidad:** horarios libres = reglas semanales (`demo_availability_rules`) − bloqueos (`demo_blackouts`) − eventos ocupados del calendario conectado − citas existentes ± margen; en America/Lima.
- **Eventos:** al confirmar se crea el evento con el visitante como invitado; Google Meet o Microsoft Teams generan el enlace (`meeting_url`, `calendar_event_ref`); con `phone` no hay enlace. Reprogramar o cancelar actualiza el mismo evento.
- **Grabación:** la integración nunca activa la grabación automática; solo se informa al anfitrión que puede grabar si `recording_consent = true` (P-65).
- **Fallos:** si el token vence o se revoca, el anfitrión queda «sin sincronización», sus citas siguen en EscudoSST y el panel avisa; las operaciones se reintentan en cola.
- **Archivo .ics** en el correo de confirmación para cualquier calendario del visitante.
- Webinars (`webinars.meeting_provider`: meet, teams, zoom u otro) usan el enlace configurado; integración automática con el proveedor de webinars por definir (DA-50).

## 15. CRM: Kallpa CRM y webhook genérico (módulo WEB)

Interfaz: `CrmConnector` (implementaciones `kallpa`, `generic_webhook` y `fake`). Kallpa CRM es el CRM propio en construcción; su contrato de API está **por definir** (DA-45).

| Aspecto | Regla |
|---|---|
| Entidades | `marketing_lead`, `demo_request`, `demo_meeting`, `partner_application`, `newsletter_subscriber`, según `entities_json`. **El Libro de Reclamaciones nunca se envía** |
| Mapeo | `field_mapping_json` origen → destino; siempre incluye el id del lead y los UTM para unir la conversión del sitio con el embudo del CRM |
| Cola | `crm_sync_events` con `idempotency_key` única; trabajador en Redis; reintentos con espera creciente hasta un máximo configurable; luego `failed` y alerta |
| Kallpa CRM | Credencial de API como referencia (`secret_ref`); `POST` de creación o actualización con la `idempotency_key` en la cabecera `Idempotency-Key`; el `external_id` devuelto se guarda |
| Webhook genérico | `POST` JSON a `base_url` con cabeceras `X-Escudo-Event`, `X-Escudo-Timestamp`, `X-Escudo-Idempotency-Key` y `X-Escudo-Signature: sha256=<HMAC-SHA256(secreto, timestamp + "." + cuerpo)>`; el receptor debe rechazar marcas de tiempo antiguas; cualquier 2xx es éxito |
| Probar conexión | Envía un evento de prueba sin datos personales y muestra el código de respuesta |
| Datos | Solo datos comerciales del lead (nombre, correo, teléfono, empresa, RUC, sector, tamaño, origen, UTM, consentimiento comercial con fecha); nunca datos médicos ni de trabajadores de clientes |
| Encargado | El CRM externo o Kallpa (si se opera aparte) figura en la lista de subencargados cuando corresponda |

## 16. Proveedor de IA del chatbot (módulo WEB)

Usa la interfaz `AiProvider` (§9) con un caso de uso propio `ChatbotAnswer`; proveedor **por definir** (DA-44). El modo IA está desactivado mientras no haya proveedor ni secreto (`chatbot_settings.ai_provider_secret_ref`).
- **Solo contenido publicado:** el contexto enviado es la pregunta del visitante más las preguntas frecuentes (`site_faqs`) y los artículos de ayuda (`help_articles`) publicados que coinciden; la respuesta debe citar la fuente. Si no hay fuente suficiente, el bot no responde con IA y ofrece WhatsApp, formulario o demo.
- **Sin datos personales:** antes de la llamada se quitan correos, teléfonos, DNI, RUC y nombres propios detectados; el formulario de lead nunca pasa por la IA; no se envía `session_hash` ni identificadores.
- Las respuestas no prometen precios, plazos ni resultados; un filtro de salida aplica `content_blocked_terms`.
- El mensaje queda en `chatbot_messages` con `used_ai = true`; el uso se mide sin guardar el texto en `ai_requests` más allá de lo necesario (ver `17`).
- Es una respuesta informativa sobre contenido ya aprobado por una persona; no crea ni modifica registros de negocio (la regla «la IA solo sugiere» sigue vigente para la aplicación).

## 17. Analítica del sitio: métricas propias, GA4, Tag Manager y Meta Pixel (módulo WEB)

- **Métricas propias** (por defecto): `POST /public/events` → `site_events` → agregación diaria en `site_analytics_daily`. Solo con consentimiento de la categoría analítica; sin IP ni huella; `session_hash` con sal diaria.
- **Opcionales:** IDs de Google Analytics 4, Google Tag Manager, Meta Pixel y la verificación de Google Search Console en `site_settings.analytics_ids`. GA4 y Tag Manager se cargan solo con consentimiento analítico; Meta Pixel solo con consentimiento de marketing; la meta de Search Console no fija cookies. Uso a decidir (DA-32 y DA-46).
- Revocar el consentimiento deja de cargar los scripts en la siguiente página y borra las cookies propias de analítica.
- Reparto con el CRM: la web mide tráfico y conversión; el CRM, embudo y ventas.

## 18. Reseñas de Google: API de Google Business Profile / Places (módulo WEB)

Interfaz: `ReviewsProvider` (implementaciones `google` y `fake`). API concreta y cuenta: **por definir** (DA-51).
- Lectura desde una tarea programada en el servidor con `review_sources.api_secret_ref`; nunca desde el navegador.
- **Atribución obligatoria** («Reseñas de Google» y el logotipo o texto que exijan los términos) y enlace a cada reseña (`provider_review_url`); nombre y foto del autor tal como los entrega el proveedor.
- **Sin edición:** el texto se muestra íntegro; no hay selección manual; solo los filtros `min_rating` y `max_to_show`.
- **Caché limitada:** `review_snapshots.expires_at` = `fetched_at` + `cache_ttl_hours`, con tope en `web.review_cache_max_hours` (P-66, según los términos vigentes del proveedor); las copias vencidas no se muestran y se borran.
- Si la API falla, `review_sources` pasa a `error` y el bloque se oculta cuando vence la copia.

## 19. Ventanas emergentes, novedades y calculadora de ahorro (módulo WEB)

Sin proveedor externo: las ventanas emergentes se sirven desde `GET /public/popups?path=`; las novedades con aviso se envían por Elastic Email (§2.1) a suscriptores confirmados; la calculadora de ahorro calcula en el servidor con los supuestos publicados y envía el resultado por correo.
