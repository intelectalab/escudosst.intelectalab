# 08 · API REST

> Generado por `tools/api/generar_openapi.py` a partir de esta plantilla (`tools/api/guia_api.md`). Edita la plantilla, no este archivo.

El contrato completo está en [`api/openapi.yaml`](../api/openapi.yaml) (OpenAPI 3.0.3): 905 rutas, 1459 operaciones y 1324 esquemas. Los tipos del frontend se generan desde ese archivo (ver `02-convenciones.md`). Esta guía explica las reglas comunes; el detalle de cada operación está en el YAML.

## 1. Principios

- Base: `/api/v1` en el mismo dominio de la aplicación. API pública para integraciones de terceros: `/api/public/v1` (ver §13).
- Rutas en inglés, `kebab-case` y plural, derivadas del nombre de la tabla: `iperc_rows` → `/iperc-rows`. Las tablas hijas se listan y crean bajo su padre (`/iperc-versions/{id}/iperc-rows`, donde `{id}` es el del padre) y se ven, editan y borran por su propia ruta (`/iperc-rows/{id}`).
- Acciones de dominio y transiciones de estado como `POST /<recurso>/{id}/<verbo>` (`/work-permits/{id}/suspend`). Nunca se cambia `status` con `PATCH`.
- Cada operación declara:
  - `operationId` único, `tags` = código del módulo, `summary` y `description` en español.
  - `x-module`, `x-plan` y `x-phase` según `spec/catalogo.json`.
  - `x-permission`: permiso `module.resource.action` (módulo en minúsculas, `resource` = tabla). Valores especiales: `public` (sin sesión) y `authenticated` (cualquier usuario con sesión, sin permiso de módulo).
  - `x-internal: true` en el panel del dueño de la plataforma (SUS y catálogos globales).
  - `x-sensitive: true` y `x-database: med` en operaciones sobre datos médicos.
- Formato: JSON UTF-8. Instantes ISO 8601 con desfase (`2026-03-15T08:30:00-05:00`); días `YYYY-MM-DD`; montos como cadena decimal en soles (nunca `float`).
- Identificadores UUID v7.

## 2. Autenticación

| Paso | Operación | Notas |
|---|---|---|
| Ingreso | `POST /auth/login` | Correo + contraseña. Si el rol exige MFA responde `mfa_required=true` con `mfa_challenge_id` |
| Segundo factor | `POST /auth/mfa/verify` | TOTP, código por correo o código de recuperación. Devuelve el token |
| Alta de MFA | `POST /auth/mfa/setup` → `POST /auth/mfa/confirm` | Devuelve códigos de recuperación (una sola vez) |
| Trabajador | `POST /auth/worker/activate`, `POST /auth/worker/pin-login` | DNI + código de activación (con captcha), luego PIN de 6 dígitos ligado al dispositivo |
| Re-autenticación médica | `POST /auth/reauth` | Ver §9 |
| Salida | `POST /auth/logout` | Revoca el token y cualquier token médico |
| Recuperación | `POST /auth/password/forgot`, `POST /auth/password/reset` | Respuesta idéntica exista o no el correo |

- Esquema `bearerAuth`: token de **Laravel Sanctum** en `Authorization: Bearer <token>`. La web de primera parte puede usar la cookie de sesión de Sanctum con token CSRF; la PWA y las integraciones usan Bearer.
- MFA obligatorio para `platform_owner`, `platform_support`, `account_admin`, `consultant`, `occupational_doctor` y `health_staff`.
- Perfil y permisos: `GET /me`, `PATCH /me`, `GET /me/permissions` (permisos efectivos en la empresa actual, ya recortados por plan y alcance), `GET /me/modules` (60 módulos con modo `enabled` / `read_only` / `locked` / `disabled_by_company`), `GET /me/sessions`.

## 3. Contexto de empresa

- Toda operación sobre datos de una empresa exige la cabecera **`X-Company-Id`** (UUID).
- El middleware `ResolveCompany` valida que el usuario tenga una membresía activa en esa empresa y aplica sus alcances (`membership_scopes` por sede/área); luego `SetTenantContext` fija `SET LOCAL app.company_id` y RLS filtra todo.
- `POST /me/switch-company` cambia la empresa activa, recarga permisos y feature flags e **invalida el token médico**. Después el cliente envía el nuevo `X-Company-Id`.
- Un registro de otra empresa responde **404**, nunca 403.
- Operaciones sin `X-Company-Id`: autenticación, `/me` y `/me/companies`, panel interno (`/internal/...`), cuenta (`/account/...`), panel de consultora (`/consultancy/...`, `/client-invitations`, `/consultant-assignments`, que trabajan sobre la cuenta) y endpoints públicos.

### Plan y feature flags
- `FeatureGate` verifica que el módulo de la operación (`x-module`) esté habilitado para la empresa. Si no: `403 module_not_in_plan` (con `module` y `required_plan` en el error). Si el plan bajó: lecturas permitidas, escrituras `403 module_read_only`. Nunca se borran datos.
- `x-plan` indica el plan mínimo (`E`, `P`, `C`), complemento (`+`), consultora (`K`) o interno (`I`).
- **Activación por empresa:** AUL (aula virtual) y VTA (venta de cursos) vienen en el plan Profesional, pero la empresa puede activarlos o desactivarlos con `PUT /company-modules/{AUL|VTA}` (`sus.feature_flags.update`, cuerpo `company_enabled`). VTA viene desactivado y exige AUL activo. Un módulo incluido pero desactivado tiene modo `disabled_by_company` (origen `company_setting`) y responde `403 module_not_in_plan` con `mode=disabled_by_company`; no se borran datos.

## 4. Versionado

- Versión mayor en la ruta: `/api/v1`. Un cambio incompatible crea `/api/v2`; `v1` se mantiene durante un periodo de convivencia definido por el dueño del producto.
- Cambios compatibles (nuevos campos, nuevas rutas, nuevos valores de filtro) no cambian la versión. Los clientes deben ignorar campos desconocidos.
- Los valores de `enum` de estado pueden crecer; el cliente muestra un valor desconocido como texto.
- `info.version` del YAML sigue SemVer y se actualiza con cada cambio del contrato.

## 5. Listas, filtros, orden y paginación

- Paginación **por cursor**: `?limit=50&cursor=<opaco>`. `limit` entre 1 y 200 (por defecto 50).
- Respuesta: `{ "data": [...], "meta": { "next_cursor", "prev_cursor", "limit", "has_more" } }`. No hay total exacto en listas grandes.
- Orden: `?sort=-created_at,name` (`-` = descendente). Solo campos indexados; otro campo → 422.
- Búsqueda: `?q=texto`. Filtros por columna (`status`, `site_id`, `area_id`, `worker_id`, `year`, …) aceptan varios valores separados por coma.
- `?updated_since=<instante>` devuelve también los borrados lógicos (útil para cachés y la PWA).
- Los registros borrados (`deleted_at`) no aparecen en listas normales.

## 6. Escrituras y concurrencia

- Crear: `POST` a la colección → `201` con el registro.
- Actualizar: `PATCH /<recurso>/{id}` parcial. Enviar `If-Match: <ETag>`; si otro usuario cambió el registro → `409 conflict`.
- Borrar: `DELETE` = **borrado lógico** (`deleted_at`). Si el estado no lo permite → `409 invalid_transition`.
- Tablas inalterables (`audit_logs`, `sensitive_access_logs`, `signatures`, `credential_verifications`, `access_events`, `election_ballots`, `document_acknowledgements`, `notification_deliveries`): solo listar y crear; no hay `PATCH` ni `DELETE`.
- Transiciones: `POST /<recurso>/{id}/<verbo>` con `{ comment, reason, signature_id }`. Transición no permitida → `409 invalid_transition` (máquinas de estado en `06-maquinas-de-estado.md`). Las reglas de segregación (aprobar IPERC, PETAR, verificar cierre de acción) responden 409 cuando se incumplen.

## 7. Errores

Formato `application/problem+json` (RFC 9457 simplificado):

```json
{
  "type": "module_not_in_plan",
  "title": "El módulo no está incluido en tu plan",
  "status": 403,
  "detail": "Matriz de requisitos legales requiere el plan Profesional.",
  "module": "MRL",
  "required_plan": "P",
  "trace_id": "01JB8Z6Q3E4K7N9R2T5V8X0Y1Z"
}
```

| HTTP | `type` | Cuándo |
|---|---|---|
| 400 | `bad_request` | JSON mal formado, firma de webhook ausente |
| 401 | `unauthenticated` | Sin token o token vencido |
| 401 | `mfa_required` | Falta completar el segundo factor |
| 401 | `reauth_required` | Lectura médica sin `X-Reauth-Token` válido |
| 403 | `forbidden` | Sin permiso para la acción |
| 403 | `module_not_in_plan` | Módulo no contratado |
| 403 | `module_read_only` | Plan bajado: módulo en solo lectura |
| 403 | `captcha_failed` | Captcha inválido en endpoint público |
| 403 | `plan_limit_reached` | Se superó un límite del plan (p. ej. trabajadores) |
| 404 | `not_found` | No existe o es de otra empresa |
| 409 | `invalid_transition` | Transición de estado no permitida |
| 409 | `conflict` | `If-Match` no coincide, duplicado, segregación de funciones |
| 409 | `already_voted` | Segundo voto en la misma elección |
| 422 | `validation_failed` | Errores por campo en `errors` |
| 429 | `rate_limited` | Límite de uso; ver `Retry-After` |

- Nunca se devuelven trazas, SQL ni datos de otra empresa. `trace_id` permite buscar el caso en los logs.
- Los mensajes (`title`, `detail`, `errors`) están en español (Perú).

## 8. Idempotencia

- Cabecera **`Idempotency-Key`** (UUID v7 recomendado, hasta 100 caracteres) en todos los `POST` de creación y en las acciones que generan trabajo (expedientes, registros, constancias, cargas masivas).
- **Obligatoria** en `POST /sync/batches`, en el voto (`POST /elections/{id}/ballots`) y en la respuesta de encuesta psicosocial.
- La API guarda clave + usuario + empresa + hash del cuerpo. Repetir la misma petición devuelve la respuesta original (código `idempotency_replay` en el registro interno). La misma clave con otro cuerpo → `409 conflict`.
- Vigencia de las claves: parámetro.

## 9. Datos médicos

- Las tablas `db: med` del catálogo (EMO, VMD, DES, VUL) viven en la base `escudo_medical` y se exponen bajo **`/medical/...`** (`/medical/medical-exams`, `/medical/surveillance-cases`, `/medical/clinical-notes`, `/medical/medical-leave-details`, `/medical/vulnerability-records`…).
- Flujo:
  1. `GET /medical/medical-exams/{id}` sin token → `401 reauth_required`.
  2. `POST /auth/reauth` con la clave adicional (o TOTP; el trabajador, su PIN) y el motivo → `reauth_token` de corta vigencia ligado a la sesión.
  3. Repetir con **`X-Reauth-Token`**. La API verifica rol, alcance y token, lee de la base médica y escribe en `sensitive_access_logs`.
- Permisos médicos: `<mod>.<tabla>.read_sensitive` (p. ej. `emo.medical_exams.read_sensitive`). Solo `occupational_doctor` (completo) y `health_staff` (limitado). Ni administradores ni soporte.
- Respuestas con `Cache-Control: no-store`. Archivos médicos solo por URL firmada del bucket médico, con marca de agua y registro (`GET /medical/medical-exam-files/{id}/download`).
- La base principal solo expone `fitness_status` (apto / apto con restricciones / no apto / pendiente + vigencia) en `/fitness-status`; la restricción va redactada en términos laborales, nunca el diagnóstico. El médico la emite con `POST /medical/medical-exams/{id}/fitness`.
- El trabajador ve sus resultados en `GET /me/medical-results` tras re-autenticarse con PIN; ve quién accedió a sus datos en `GET /me/sensitive-accesses`.
- Ningún dato médico pasa por la sincronización offline, la IA, webhooks, correo, WhatsApp, la verificación de fotocheck ni el expediente SUNAFIL.

## 10. Endpoints públicos y su seguridad

| Endpoint | Uso | Controles |
|---|---|---|
| `GET /public/report-points/{token}` | Datos del punto de reporte del QR | Token opaco; límite por IP y token |
| `POST /public/report-points/{token}/reports` | Reporte de acto/condición sin login | `X-Captcha-Token`, límite por IP y por punto, `Idempotency-Key`, validación estricta, foto solo por URL firmada (imagen, tamaño máximo) |
| `GET /public/report-points/{token}/reports/{trackingCode}` | Seguimiento del reporte | Sin datos personales |
| `GET`/`POST /webhooks/whatsapp` | Verificación y mensajes entrantes del BSP | Firma `X-Hub-Signature-256`, idempotencia por `message_id`, opt-in, límite de uso, proceso en cola |
| `POST /webhooks/email` | Rebotes y entregas de Elastic Email | Firma del emisor |
| `GET /public/credentials/{token}` | Verificación de fotocheck por QR | Devuelve solo nombre, foto, empresa, puesto, vigencia y estado (HABILITADO / NO HABILITADO / No válida) con semáforo de requisitos. **Nunca datos médicos** (solo «Aptitud médica: vigente / no vigente»). Registra cada consulta en `credential_verifications` |
| `GET /public/dossiers/{token}` y `/download` | Expediente SUNAFIL compartido | Vencimiento, máximo de vistas, código opcional (`X-Share-Code`), revocable, registro de accesos |
| `GET /public/certificates/{code}` | Verificación de certificado por código (CAP) | Código aleatorio no secuencial (no el correlativo); devuelve estado, número, nombre, documento enmascarado, curso, horas, fechas y empresa. Nunca correo, teléfono, documento completo ni nota. Si el curso no es verificable públicamente responde «no público». Registra cada consulta en `certificate_verifications` (inalterable). Límite por IP y global |
| `GET /public/catalog/{slug}/courses` y `/{offerSlug}` | Catálogo público de cursos a la venta (VTA) | Solo ofertas publicadas y visibles de empresas con VTA activo; sin datos personales ni ids internos; límite por IP |
| `POST /public/catalog/{slug}/coupons/validate` | Validar cupón | `X-Captcha-Token`, límite estricto por IP; respuesta indistinguible para cupones inexistentes |
| `POST /public/catalog/{slug}/orders` | Crear pedido sin sesión | `X-Captcha-Token`, `Idempotency-Key` obligatoria, consentimiento Ley 29733, totales e IGV calculados en el servidor; devuelve `checkout_token` (hash en la base) |
| `GET /public/orders/{token}`, `POST /public/orders/{token}/payment` | Estado y pago del pedido | Token opaco; el pago recibe solo el token de un solo uso de la pasarela, **nunca datos de tarjeta**; `Idempotency-Key` obligatoria |
| `POST /webhooks/payments/{provider}/{gateway_id}` | Notificaciones de Culqi, Niubiz, Izipay o Mercado Pago | Firma verificada con el secreto (referencia al gestor de secretos), proceso único por (pasarela, id externo) en `payment_transactions` (inalterable), responde 200 de inmediato y procesa en cola: pedido pagado → matrícula automática, cupos de licencia y comprobante |
| `GET /public/site/settings`, `/public/site/pages?slug=`, `/public/site/menus/{location}`, `/public/site/faqs`, `/public/site/products`, `/public/site/testimonials` | Sitio web de EscudoSST (WEB) | Solo contenido publicado (copia publicada de la página, FAQ publicadas, productos visibles, testimonios aprobados); borrador o archivada = 404; caché pública corta |
| `GET /public/site/pricing` | Planes, complementos y comparativa por módulo | `price_reference` o «Solicitar cotización» si es nulo; sale de la configuración de planes |
| `GET /public/site/sitemap.xml`, `/public/site/robots.txt`, `/public/site/redirects/resolve` | SEO | Solo páginas publicadas e indexables; rutas `noindex` bloqueadas; redirecciones 301/302 |
| `GET /public/blog/latest` | Últimos artículos del blog en WordPress | Proxy con caché de la API REST pública de WordPress (`/wp-json/wp/v2/posts`); con el blog caído devuelve la copia en caché o lista vacía; no hay blog propio |
| `GET /public/legal/{docType}`, `POST /public/legal-acceptances`, `POST /public/cookie-consents` | Políticas, aceptación en el registro autoservicio y cookies | Solo la versión vigente publicada; aceptación con captcha e `Idempotency-Key`; registros inalterables con IP como hash |
| `POST /public/consumer-complaints`, `GET /public/consumer-complaints/{code}` | Libro de Reclamaciones de EscudoSST | `X-Captcha-Token`, `Idempotency-Key` obligatoria (repetir devuelve la misma hoja), límite por IP; consulta con código + `X-Tracking-Key` (de la constancia) sin datos personales; 404 idéntico |
| `POST /public/demo-requests` | Formularios de demo, contacto y cotización | Consentimiento de privacidad, `X-Captcha-Token`, `Idempotency-Key`, límite por IP |
| `GET /public/tools/fine-calculator/parameters`, `POST /public/tools/fine-calculator` | Calculadora referencial de multas SUNAFIL | Parámetros legales vigentes `to_confirm` (`web.uit_value`, `web.fine_table_*`, `web.fine_correction_discount_pct`); cálculo en el servidor con copia de los parámetros usados; `parameters_pending` mientras la tabla no tenga valores; aviso «referencial, no es asesoría legal» |
| `GET`/`POST /public/tools/self-assessment` | Autodiagnóstico Ley 29783 | Solo el cuestionario publicado (sin pesos), rotulado «a validar» con `to_validate`; puntaje y nivel calculados en el servidor; exige correo para el informe PDF |
| `GET /public/resources`, `POST /public/resources/{slug}/download` | Biblioteca de recursos | Solo publicados, sin `file_id` en el listado; descarga con URL firmada corta; gated exige datos (lead) |
| `GET /public/success-stories` | Casos de éxito | Solo publicados con consentimiento escrito o rotulados «Ejemplo»; nunca el archivo de consentimiento |
| `GET /public/help/categories`, `/public/help/articles`, `/public/help/articles/{slug}`, `POST …/{slug}/feedback`; `GET /me/help?screen_code=` (con sesión) | Centro de ayuda y ayuda contextual | Solo publicados de categorías activas; relacionados sin borradores; «¿Te sirvió?» inalterable |
| `POST /public/partners/apply` | Programa de partners | Lead `partner`; una solicitud pendiente por correo |
| `POST /public/newsletter/subscribe · confirm · unsubscribe`, `POST /public/status/subscribe` | Boletín y avisos del estado | Doble confirmación; tokens aleatorios guardados solo como sha256; baja con un clic idempotente; respuesta idéntica exista o no el correo |
| `GET /public/subprocessors`, `GET /public/status` | Centro de confianza y estado del servicio | Subencargados activos (`to_validate`); componentes e incidentes publicados con sus actualizaciones |
| `GET /public/demo/slots`, `POST /public/demo/book` | Agenda pública de demos (v1.5) | Horarios libres sin datos del anfitrión (reglas, bloqueos, feriados, reuniones ocupadas, aviso mínimo); reserva con consentimiento de privacidad y comercial aparte, `X-Captcha-Token` e `Idempotency-Key`; crea solicitud, lead y reunión propuesta |
| `GET /public/demo/manage/{token}`, `POST …/reschedule`, `POST …/cancel` | Gestión de la cita por enlace seguro | Token opaco (en la base solo su sha256); sin datos del visitante; enlace de reunión solo si está confirmada; los POST con captcha e `Idempotency-Key` |
| `POST /public/events` | Métricas propias | Solo con consentimiento de cookies analíticas vigente del `visitor_id` (si no, 403 y no se guarda); sin IP, navegador ni datos personales; `session_hash` rotado a diario |
| `GET /public/chatbot/config`, `POST /public/chatbot/conversations`, `POST …/{id}/messages`, `POST …/{id}/lead` | Chatbot del sitio | Intenciones publicadas, nunca el secreto de IA; solo la sesión del widget escribe en su conversación; en modo IA solo contenido publicado; lead con privacidad obligatoria, captcha e `Idempotency-Key` |
| `GET /public/campaigns/{slug}`, `GET /public/popups?path=` | Landings de campaña y ventanas emergentes (v1.5-b) | Campaña activa dentro de su vigencia, `noindex` por defecto, sin datos internos; popups nunca en Libro de Reclamaciones, políticas ni formularios, con tope `web.popup_max_per_visit` |
| `GET /public/integrations`, `GET /public/updates`, `GET /public/updates/{slug}` | Integraciones y novedades | Solo publicadas; estado veraz (disponible, beta, planificada) |
| `GET /public/webinars`, `GET /public/webinars/{slug}`, `POST /public/webinars/{slug}/register`, `POST /public/webinars/manage/{token}/cancel` | Webinars | Sin enlace de reunión; grabación solo si se informó a ponente y asistentes; inscripción con consentimiento, cupo e idempotente por persona; token de cancelación como hash |
| `GET /public/reviews` | Reseñas de Google | Reseñas reales en caché vigente (expiración según el proveedor), con atribución y sin editar; filtro solo por calificación mínima y cantidad |
| `GET`/`POST /public/tools/savings-calculator` | Calculadora de ahorro frente a Excel y papel | Supuestos visibles («estimación referencial, no garantizada»); sin supuestos definidos `assumptions_pending`; copia de supuestos; lead si deja correo |

Todos los `POST` de captación exigen `X-Captcha-Token` e `Idempotency-Key` y tienen límite por IP; crean o actualizan un lead (`marketing_leads`) con origen, UTM y consentimiento comercial **separado** del de privacidad (Ley 29733).
| `POST /auth/login`, `/auth/worker/activate`, `/auth/password/*` | Ingreso | Bloqueo por intentos con espera creciente; captcha en activación |

Reglas comunes:
- Tokens aleatorios de 128 bits o más, opacos, guardados como hash y revocables.
- Respuesta **idéntica** (404) para token inexistente, vencido o revocado, salvo el «No válida» de fotocheck.
- Límites por IP, por token y globales en Redis (valores como parámetro).
- Sin cabecera `X-Company-Id`: la empresa se deduce del token.

## 11. Límites de uso

- Límites por usuario, por token, por IP y por empresa, en Redis. Valores como parámetro (y por plan en la API pública).
- Respuesta `429 rate_limited` con `Retry-After`, `RateLimit-Limit` y `RateLimit-Remaining`.
- Límites del plan (trabajadores, sedes, usuarios, puntos QR, solicitudes IA) no bloquean lo existente: impiden crear más (`403 plan_limit_reached`).
- Lotes de sincronización: hasta 500 operaciones por lote.

## 12. Procesos asíncronos y archivos

- Generación de expediente SUNAFIL, registros obligatorios, actas, constancias, carga masiva, autodiagnóstico, sugerencias IA y activación de paquetes sectoriales responden **`202 Accepted`** con `{ id, status, status_url }` y cabecera `Location`. El estado se consulta con `GET` del recurso y además llega una notificación.
- Expediente SUNAFIL: `POST /inspection-dossiers` → estados `queued` → `building` → `ready` / `ready_with_gaps` / `failed`; `GET /inspection-dossiers/{id}/download`; `POST /inspection-dossiers/{id}/share-links` (link con vencimiento); `POST /dossier-share-links/{id}/revoke`.
- Archivos: `POST /files/upload-urls` (metadatos + URL firmada PUT a B2) → el cliente sube → `POST /files/{id}/confirm` (tamaño, tipo real, SHA-256; antivirus en cola) → el `file_id` se usa en los registros. Descarga con `GET /files/{id}/download-url` (URL firmada de corta duración). Nunca binarios en la API salvo plantillas Excel.
- La tabla de metadatos de archivos es de soporte transversal (`x-support-table: true`); su nombre definitivo lo fija el esquema de base de datos.

## 13. Sincronización offline (OFF)

- `POST /sync/batches` con `Idempotency-Key` del lote y `idempotency_key` por operación (`entity_type`, `op`, `base_version`, `payload`, `client_at`).
- Resultado por operación: `applied`, `replayed` (ya aplicada: devuelve el resultado previo), `conflict` (la `base_version` no es la vigente: se registra en `sync_conflicts` y **no se pisa**) o `rejected` (con su `Problem`). Incluye URLs firmadas para las fotos pendientes.
- `GET /sync/changes?since=<marca>&device_id=...` devuelve los cambios del servidor con `next_since` y `has_more`.
- Conflictos: `GET /sync-conflicts`, `POST /sync-conflicts/{id}/resolve`. Dispositivos: `/devices`, `POST /devices/{id}/revoke`.

## 14. Asistente IA (IAA)

- `POST /ai/requests` (`iperc_suggestion`, `investigation_guide`, `document_reading`) → 202. Entrada minimizada, sin datos médicos.
- El resultado queda en `ai_suggestions` con estado `pending`. **Nunca se aplica sin aprobación humana**: `POST /ai-suggestions/{id}/accept` (total o parcial, con ajustes) crea o actualiza los registros; `POST /ai-suggestions/{id}/reject` no aplica nada.

## 15. Webhooks salientes y clientes API (módulo API, Corporativo)

- Clientes API: `/api-clients` (el secreto se muestra una sola vez; `POST /api-clients/{id}/rotate-secret`). Autenticación client credentials con alcances y empresa fija para la API pública `/api/public/v1` (documentada en una sección propia cuando se implemente la fase 4).
- Webhooks: `/webhooks` con eventos `worker.created`, `credential.status_changed`, `sst_event.registered`, `corrective_action.overdue`, `training.completed`.
- Cada entrega va firmada con HMAC-SHA256 en la cabecera `X-EscudoSST-Signature: t=<epoch>,v1=<hex>` sobre `<t>.<cuerpo>`; incluye `X-EscudoSST-Event` y `X-EscudoSST-Delivery` (id único para deduplicar). Reintentos con espera creciente; registro en `webhook_deliveries` (`GET /webhooks/{id}/webhook-deliveries`, `POST /webhook-deliveries/{id}/redeliver`, `POST /webhooks/{id}/test`).
- Ningún webhook ni API pública lleva datos médicos (solo `fitness_status`).

## 16. Panel interno y consultora

- Panel del dueño (`x-internal: true`, rutas `/internal/...`): planes y módulos por plan, complementos, suscripciones (cambiar plan, suspender, reactivar, cancelar), comprobantes, pagos, feature flags, catálogos globales (normas, paquetes sectoriales). No expone datos de negocio de las empresas.
- El administrador de cuenta ve su suscripción y comprobantes en `/account/...`. Los precios salen de la configuración de planes y se muestran «Por definir» mientras no se carguen.
- Consultora (CON): `GET /consultancy/companies` (panel multi-cliente), `/client-invitations` (invitar empresa, reenviar, cancelar; la empresa acepta con `POST /client-invitations/accept`), `/consultant-assignments`, `GET`/`PUT /consultancy/white-label` (marca blanca).
- Paquetes sectoriales (PSE): `GET /sector-packs`, `POST /sector-packs/{id}/activate`.
- Sitio web (WEB, `x-internal`, rutas `/internal/...`, permisos `web.*` del Dueño de la plataforma; Soporte solo lee y atiende demos): `GET`/`PATCH /internal/site-settings`; CRUD de `site-pages` (con `publish`/`archive`), `site-menus`, `site-products`, `site-testimonials` (`approve`/`hide`), `site-faqs`, `site-redirects`, `legal-documents` y sus versiones (`approve`, `publish` que dispara re-aceptación); bandeja `consumer-complaints` con `start`, `assign`, `acknowledge`, `extend`, `answer`, `close` (permiso `web.consumer_complaints.respond`) y `GET /internal/reports/consumer-complaints?from=&to=` (`export`); `demo-requests` con `status`, `assign` y `export`. Captación: `marketing-leads` (bandeja con `assign`, `status` y `GET /internal/marketing-leads/export`; incluye demos y contacto), `fine-calculations` y `assessment-submissions` (lectura), `assessment-questionnaires` con sus `assessment-questions` y `publish`, `resource-items` (+ `resource-downloads`), `success-stories` (`approve`, `publish`, `hide`), `help-categories`, `help-articles` (`publish`, `archive`; + `help-article-feedback`), `partner-applications` (`approve` crea el código de referido, `reject`), `referral-codes`, `referrals`, `newsletter-subscribers`, `newsletter-issues` (`send`), `subprocessors`, `status-components`, `status-incidents` (`publish`, `POST /internal/status-incidents/{id}/updates`). Soporte atiende leads, ayuda e incidentes (no exporta leads ni publica ayuda). El usuario con sesión consulta y acepta las políticas pendientes en `GET`/`POST /me/legal-acceptances`.
- Sitio web v1.5 (`spec/brief-web-v15.md`): `GET /internal/site-metrics?from=&to=` (agregados: visitas, fuentes, páginas, conversiones por formulario y CTA, tasa, leads, demos pendientes, reclamos por vencer, campañas) y `GET /internal/site-metrics/export` (permiso especial `web.site_analytics.export`, solo el dueño); `site-analytics-daily` y `site-events` (lectura); `site-media` (CRUD, `POST /internal/site-media/upload-url`, `GET /internal/site-media/{id}/usages`; reemplazar = cambiar `file_id` del mismo registro); calendario: `GET`/`PATCH /internal/demo-booking-settings`, `demo-hosts` (`POST /internal/demo-hosts/{id}/calendar/authorize`, `connect-calendar` con el código OAuth —solo se guarda la referencia al token—, `disconnect-calendar`) con sus `demo-availability-rules`, `demo-blackouts`, `demo-meetings` (`assign` → `web.demo_meetings.assign`, `confirm` → `web.demo_meetings.confirm`, `complete`, `cancel`; dos confirmadas del mismo anfitrión que se cruzan: 409); chatbot: `GET`/`PATCH /internal/chatbot-settings`, `chatbot-intents` (`publish` → `web.chatbot_intents.publish`, `archive`), `chatbot-conversations` con sus `chatbot-messages` (lectura); CRM: `crm-connections` (`test`, `activate`, `pause` → `web.crm_connections.manage`) y `crm-sync-events` (`retry`); portafolio: `success-stories/{id}/success-story-media`. v1.5-b: `campaigns` (`activate`, `pause`, `finish` → `web.campaigns.publish`), `site-popups` (`activate`, `pause` → `web.site_popups.publish`), `site-integrations`, `product-updates` (`publish`, `archive`), `webinars` (`publish`, `start`, `finish`, `cancel` → `web.webinars.publish`) con `webinar-registrations` (`attendance`), `review-sources` (`fetch`, `activate`, `pause` → `web.review_sources.manage`) con `review-snapshots` (lectura), `GET`/`PATCH /internal/savings-calculator-settings` (+ `publish`) y `savings-calculations` (lectura). Soporte atiende calendario (reglas, bloqueos, reuniones, asignar y confirmar), chatbot (intenciones sin publicar, conversaciones) y medios, lee métricas y asistencias; no gestiona el CRM ni exporta métricas. Con sesión: `GET /me/product-updates` (novedades `show_in_app`).

## 17. Acciones de dominio destacadas

| Flujo | Operaciones |
|---|---|
| Carga masiva de trabajadores | `GET /bulk-imports/template`, `POST /bulk-imports` (202), `GET /bulk-imports/{id}/preview`, `POST /bulk-imports/{id}/confirm`, `GET /bulk-imports/{id}/errors.xlsx` |
| Documentos | `POST /documents/{id}/document-versions`, `POST /document-versions/{id}/submit · return · approve · publish · retire`, `POST /document-versions/{id}/acknowledgement-requests`, `POST /document-versions/{id}/document-acknowledgements` |
| Firma | `POST /signature-requests/{id}/sign`, `GET /signatures/{id}/verification` |
| Elección del comité | `POST /elections/{id}/convene · open-nominations · close-nominations · open-voting · close-voting · publish-results`, `POST /elections/{id}/candidates/apply`, `POST /elections/{id}/ballots` (una sola vez), `GET /elections/{id}/results`, `POST /elections/{id}/generate-minutes` |
| IPERC | `POST /iperc-matrices/{id}/new-version`, `POST /iperc-versions/{id}/submit · approve · publish` |
| Inspección | `POST /inspections/{id}/start`, `PUT /inspections/{id}/answers`, `POST /inspections/{id}/findings/generate`, `POST /findings/{id}/corrective-actions` |
| Fotocheck | `POST /credentials/{id}/issue · revoke`, `GET /public/credentials/{token}` |
| Accidentes | `POST /sst-events`, `POST /sst-events/{id}/register` (calcula plazos de notificación desde parámetros), `GET /sst-events/{id}/notification-deadlines`, `POST /sst-events/{id}/investigation` |
| PETAR | `POST /work-permits/{id}/submit · approve · reject · open · suspend · resume · close` |
| Registros obligatorios | `GET /registers`, `POST /register-exports` (R01–R09, PDF o Excel, 202), `GET /register-exports/{id}/download` |
| Modo SUNAFIL | `POST /self-diagnostics`, `POST /inspection-dossiers`, `GET /inspection-dossiers/{id}/download`, `POST /inspection-dossiers/{id}/share-links`, `POST /dossier-share-links/{id}/revoke` |
| Estadísticas y tablero | `POST /statistics/compute`, `GET /statistics/indices`, `GET /dashboard/summary`, `GET /dashboards/{id}/data` |
| Certificados (CAP) | `POST /course-enrollments/{id}/certificate`, `POST /training-certificates/{id}/validate · void`, `GET /training-certificates/{id}/download`, `GET /public/certificates/{code}`, `GET /me/certificates` |
| Matriz por cargo (CAP) | `/position-training-requirements`, `GET /position-training-requirements/compliance`, `POST /course-enrollments/bulk-by-position` (202) |
| Aula virtual (AUL) | `POST /courses/{id}/publish · archive`, `/courses/{id}/course-sections`, `/courses/{id}/course-lessons`, `POST /question-banks/{id}/import` (Excel, 202), `POST /question-banks/{id}/random-exam`, `POST /course-enrollments/{id}/quiz-attempts/start`, `POST /quiz-attempts/{id}/submit · grade`, `POST /course-enrollments/{id}/commitment`, `GET /courses/{id}/progress-report` |
| Tiempo de permanencia (AUL) | `POST /learning/heartbeat`: latido idempotente por (matrícula, `client_session_id`, `seq`) solo con interacción o video visible; el servidor recorta deltas imposibles y marca sesiones anómalas. Las horas del certificado exigen `min_active_time_pct` del curso |
| Moodle opcional (AUL) | `/lms-connections`, `POST /lms-connections/{id}/test · sync`, `POST /course-enrollments/{id}/launch` (única implementación de `LmsProvider`: Moodle) |
| Recomendaciones (AUL/VTA) | `/recommendation-rules`, `POST /recommendation-rules/{id}/run` (job, 202), `POST /course-recommendations/manual` (persona, cargo, área o empresa compradora), `GET /me/recommendations`, `POST /course-recommendations/{id}/view · click · dismiss`, `GET /reports/recommendations/conversion`. Ninguna regla usa datos médicos |
| Venta de cursos (VTA) | `/course-offers` (`publish · pause`), `/coupons`, `/orders` (`void`), `POST /orders/{id}/payment`, `/payment-gateways` (`test`), `/course-licenses` y `POST /course-licenses/{id}/assign`, `POST /license-assignments/{id}/revoke`, `POST /orders/{id}/sales-documents` y `POST /sales-documents/{id}/credit-note` (proveedor por definir), `GET /reports/sales`, `GET /me/orders` |
| Notificaciones del usuario | `GET /me/notifications`, `GET /me/notifications/unread-count`, `POST /me/notifications/{id}/read`, `PUT /me/notification-preferences` |

## 18. Resumen por módulo

Rutas de colección principales por módulo (las rutas `/{id}` y las transiciones están en el YAML).

| Módulo | Nombre | Plan | Fase | Operaciones | Rutas principales |
|---|---|---|---|---|---|
| TEN | Empresas, sedes y áreas | Esencial | 0 | 28 | `/accounts`<br>`/areas`<br>`/companies`<br>`/positions`<br>`/sites`<br>`/work-shifts` |
| USR | Usuarios, roles y permisos | Esencial | 0 | 39 | `/auth/login`<br>`/auth/logout`<br>`/auth/mfa/confirm`<br>`/auth/mfa/recovery-codes`<br>`/auth/mfa/setup`<br>`/auth/mfa/verify` |
| TRB | Trabajadores y legajo | Esencial | 0 | 24 | `/bulk-imports`<br>`/bulk-imports/template`<br>`/me/worker`<br>`/workers`<br>`/bulk-imports/{id}/bulk-import-rows`<br>`/bulk-imports/{id}/confirm` |
| DOC | Gestión documental | Esencial | 0 | 27 | `/document-categories`<br>`/documents`<br>`/files/upload-urls`<br>`/me/documents`<br>`/document-versions/{id}/acknowledgement-requests`<br>`/document-versions/{id}/document-acknowledgements` |
| NOT | Alertas y notificaciones | Esencial | 0 | 14 | `/me/notification-preferences`<br>`/me/notifications`<br>`/me/notifications/read-all`<br>`/me/notifications/unread-count`<br>`/notification-deliveries`<br>`/notification-rules` |
| DSH | Dashboard e indicadores | Esencial | 0 | 18 | `/dashboard/summary`<br>`/dashboards`<br>`/kpi-definitions`<br>`/kpi-snapshots`<br>`/dashboards/{id}/dashboard-widgets`<br>`/dashboards/{id}/data` |
| FIR | Firma electrónica de registros | Profesional | 0 | 9 | `/me/signature-requests`<br>`/signature-requests`<br>`/signatures`<br>`/signature-requests/{id}/sign`<br>`/signatures/{id}/verification` |
| AUD | Bitácora de auditoría | Esencial | 0 | 4 | `/audit-logs`<br>`/me/sensitive-accesses`<br>`/medical/access-log`<br>`/sensitive-access-logs` |
| SUS | Suscripciones y facturación | Interno | 0 | 36 | `/account/invoices`<br>`/account/subscription`<br>`/account/subscription/addons`<br>`/internal/addons`<br>`/internal/feature-flags`<br>`/internal/invoices` |
| WEB | Sitio web público y CMS | Interno | 1 | 338 | `/internal/assessment-questionnaires`<br>`/internal/assessment-submissions`<br>`/internal/campaigns`<br>`/internal/chatbot-conversations`<br>`/internal/chatbot-intents`<br>`/internal/chatbot-settings` |
| LBA | Diagnóstico de línea base | Esencial | 1 | 17 | `/baseline-assessments`<br>`/baseline-gaps`<br>`/baseline-assessments/{id}/baseline-items`<br>`/baseline-assessments/{id}/generate-gaps`<br>`/baseline-assessments/{id}/items`<br>`/baseline-gaps/{id}/corrective-actions` |
| POL | Política y objetivos SST | Esencial | 1 | 18 | `/sst-objectives`<br>`/sst-policies`<br>`/sst-objectives/{id}/objective-targets` |
| MRL | Matriz de requisitos legales | Profesional | 2 | 12 | `/internal/legal-norms`<br>`/legal-norms`<br>`/legal-requirements`<br>`/legal-requirements/{id}/requirement-evaluations` |
| PLA | Plan Anual SST | Esencial | 1 | 12 | `/annual-plans`<br>`/annual-plans/{id}/annual-plan-items` |
| PAS | Programa Anual SST (PASST) | Esencial | 1 | 19 | `/annual-programs`<br>`/annual-programs/{id}/program-activities`<br>`/program-activities/{id}/activity-executions`<br>`/program-activities/{id}/reschedule` |
| PCA | Programa anual de capacitación | Esencial | 1 | 12 | `/training-programs`<br>`/training-programs/{id}/training-program-items` |
| RIS | RISST | Profesional | 2 | 12 | `/risst-versions`<br>`/risst-versions/{id}/deliveries/bulk`<br>`/risst-versions/{id}/risst-deliveries` |
| COM | Comité o Supervisor SST | Esencial | 1 | 50 | `/committees`<br>`/elections`<br>`/committee-meetings/{id}/generate-minutes`<br>`/committee-meetings/{id}/meeting-agreements`<br>`/committees/{id}/committee-meetings`<br>`/committees/{id}/committee-members` |
| PRE | Presupuesto SST | Corporativo | 4 | 16 | `/budgets`<br>`/budget-lines/{id}/budget-executions`<br>`/budgets/{id}/budget-lines` |
| IPC | Matriz IPERC | Esencial | 1 | 34 | `/hazard-catalog`<br>`/iperc-matrices`<br>`/risk-criteria`<br>`/iperc-matrices/{id}/iperc-versions`<br>`/iperc-matrices/{id}/new-version`<br>`/iperc-rows/{id}/iperc-controls` |
| MAP | Mapa de riesgos | Profesional | 2 | 11 | `/risk-maps`<br>`/risk-maps/{id}/risk-map-markers` |
| MAG | Monitoreo de agentes | Profesional | 2 | 26 | `/agent-types`<br>`/exposure-limits`<br>`/monitoring-campaigns`<br>`/monitoring-points`<br>`/monitoring-campaigns/{id}/measurements` |
| PSI | Riesgos ergonómicos y psicosociales | Corporativo | 4 | 14 | `/ergonomic-assessments`<br>`/psychosocial-surveys`<br>`/psychosocial-surveys/{id}/responses`<br>`/psychosocial-surveys/{id}/results` |
| PET | PETS y procedimientos | Corporativo | 4 | 13 | `/procedures`<br>`/procedures/{id}/procedure-versions` |
| MOC | Gestión del cambio | Complemento | 5 | 12 | `/change-requests`<br>`/change-requests/{id}/change-reviews` |
| INS | Inspecciones | Esencial | 1 | 30 | `/findings`<br>`/inspection-templates`<br>`/inspections`<br>`/findings/{id}/corrective-actions`<br>`/findings/{id}/dismiss`<br>`/findings/{id}/resolve` |
| ACT | Actos y condiciones subestándar | Esencial | 1 | 20 | `/me/substandard-reports`<br>`/report-points`<br>`/substandard-reports`<br>`/webhooks/whatsapp`<br>`/public/report-points/{token}/reports`<br>`/report-points/{id}/qr` |
| CH5 | Charlas de 5 minutos | Profesional | 2 | 9 | `/safety-talks`<br>`/safety-talks/{id}/talk-attendances` |
| ATS | ATS | Profesional | 2 | 14 | `/ats-forms`<br>`/ats-forms/{id}/ats-participants`<br>`/ats-forms/{id}/ats-steps` |
| PTR | PETAR y permisos de alto riesgo | Corporativo | 4 | 22 | `/permit-types`<br>`/work-permits`<br>`/work-permits/{id}/permit-approvals`<br>`/work-permits/{id}/permit-checks` |
| OBS | Observación de conducta segura | Corporativo | 4 | 10 | `/behavior-observations`<br>`/behavior-observations/{id}/behavior-observation-items` |
| EPP | EPP | Esencial | 1 | 18 | `/me/ppe-deliveries`<br>`/ppe-deliveries`<br>`/ppe-items`<br>`/ppe-position-requirements`<br>`/ppe-stock-movements` |
| EQE | Equipos de emergencia y botiquines | Profesional | 2 | 18 | `/emergency-equipment`<br>`/first-aid-kits`<br>`/emergency-equipment/{id}/equipment-checks`<br>`/first-aid-kits/{id}/first-aid-kit-items` |
| SIM | Simulacros y brigadas | Profesional | 2 | 17 | `/brigades`<br>`/drills`<br>`/brigades/{id}/brigade-members`<br>`/drills/{id}/drill-participants` |
| CAP | Capacitaciones e inducciones | Esencial | 1 | 40 | `/certificate-templates`<br>`/me/certificates`<br>`/me/trainings`<br>`/position-training-requirements`<br>`/position-training-requirements/compliance`<br>`/training-certificates` |
| AUL | Aula virtual SST | Profesional | 2 | 83 | `/course-enrollments`<br>`/course-enrollments/bulk-by-position`<br>`/course-recommendations`<br>`/course-recommendations/manual`<br>`/courses`<br>`/grading-scales` |
| FOT | Fotocheck SST con QR | Profesional | 2 | 22 | `/credential-types`<br>`/credentials`<br>`/me/credential`<br>`/credential-types/{id}/credential-requirements`<br>`/credentials/{id}/block`<br>`/credentials/{id}/credential-verifications` |
| EMO | EMOs y aptitud | Profesional | 2 | 19 | `/fitness-status`<br>`/me/medical-results`<br>`/medical-exam-orders`<br>`/medical/medical-exams`<br>`/medical/medical-exam-files/{id}/download`<br>`/medical/medical-exams/{id}/fitness` |
| VMD | Vigilancia médica | Corporativo | 4 | 14 | `/medical/surveillance-cases`<br>`/medical/surveillance-programs`<br>`/medical/surveillance-cases/{id}/clinical-notes` |
| DES | Descansos médicos y ausentismo | Corporativo | 4 | 9 | `/absences`<br>`/medical/medical-leave-details` |
| VUL | Grupos vulnerables | Corporativo | 4 | 10 | `/medical/vulnerability-records`<br>`/work-adjustments` |
| VTA | Venta de cursos y pagos | Profesional | 2 | 52 | `/coupons`<br>`/course-licenses`<br>`/course-offers`<br>`/external-learners`<br>`/me/orders`<br>`/orders` |
| ACC | Accidentes, incidentes y enfermedades | Esencial | 1 | 20 | `/sst-events`<br>`/sst-events/{id}/corrective-actions`<br>`/sst-events/{id}/discard`<br>`/sst-events/{id}/event-notifications`<br>`/sst-events/{id}/event-people`<br>`/sst-events/{id}/notification-deadlines` |
| INV | Investigación de causas | Profesional | 2 | 17 | `/investigations`<br>`/investigations/{id}/corrective-actions`<br>`/investigations/{id}/investigation-causes`<br>`/investigations/{id}/investigation-team`<br>`/investigations/{id}/start-analysis`<br>`/sst-events/{id}/investigation` |
| ACR | Acciones correctivas | Esencial | 1 | 14 | `/corrective-actions`<br>`/corrective-actions/{id}/action-evidences`<br>`/corrective-actions/{id}/reject-verification`<br>`/corrective-actions/{id}/submit-verification`<br>`/corrective-actions/{id}/verify` |
| AUI | Auditorías | Profesional | 2 | 17 | `/audits`<br>`/audit-findings/{id}/corrective-actions`<br>`/audits/{id}/audit-checklist-items`<br>`/audits/{id}/audit-findings` |
| EST | Estadísticas SST | Esencial | 1 | 9 | `/man-hours`<br>`/monthly-statistics`<br>`/statistics/compute`<br>`/statistics/indices` |
| RVD | Revisión por la dirección | Corporativo | 4 | 11 | `/management-reviews`<br>`/management-reviews/{id}/management-review-outputs` |
| REG | Registros obligatorios | Esencial | 1 | 5 | `/register-exports`<br>`/registers`<br>`/register-exports/{id}/download` |
| CTR | Acreditación de contratistas | Corporativo | 4 | 30 | `/contractor-accreditations`<br>`/contractor-requirements`<br>`/contractors`<br>`/contractor-accreditations/{id}/accredit`<br>`/contractor-accreditations/{id}/observe`<br>`/contractor-accreditations/{id}/start-review` |
| SCT | Control de SCTR | Profesional | 2 | 10 | `/sctr-policies`<br>`/sctr-policies/{id}/sctr-coverages` |
| ING | Ingreso de terceros por QR | Corporativo | 4 | 7 | `/access-events`<br>`/access-points`<br>`/access-points/{id}/scans` |
| HOM | Homologación de proveedores | Complemento | 5 | 11 | `/vendor-evaluation-templates`<br>`/vendor-evaluations` |
| SUN | Modo Inspección SUNAFIL | Profesional | 2 | 15 | `/inspection-dossiers`<br>`/self-diagnostics`<br>`/inspection-dossiers/{id}/dossier-items`<br>`/inspection-dossiers/{id}/dossier-share-links`<br>`/inspection-dossiers/{id}/download`<br>`/inspection-dossiers/{id}/retry` |
| OFF | App móvil offline | Complemento | 3 | 10 | `/devices`<br>`/sync-conflicts`<br>`/sync/batches`<br>`/sync/changes`<br>`/sync-conflicts/{id}/resolve` |
| IAA | Asistente IA | Complemento | 3 | 7 | `/ai-suggestions`<br>`/ai/requests`<br>`/ai-suggestions/{id}/accept` |
| CON | Modo consultora y marca blanca | Consultora | 3 | 15 | `/client-invitations`<br>`/client-invitations/accept`<br>`/client-invitations/decline`<br>`/consultancy/companies`<br>`/consultancy/white-label`<br>`/consultant-assignments` |
| PSE | Paquetes sectoriales | Complemento | 3 | 8 | `/company-sector-packs`<br>`/internal/sector-packs`<br>`/sector-packs`<br>`/company-sector-packs/{id}/deactivate`<br>`/internal/sector-packs/{id}/items`<br>`/sector-packs/{id}/activate` |
| ISO | Sistema integrado ISO | Complemento | 5 | 8 | `/iso-evidences`<br>`/iso-standards`<br>`/iso-standards/{id}/iso-clauses` |
| AMB | Medio ambiente (SSOMA) | Complemento | 5 | 10 | `/environmental-aspects`<br>`/waste-records` |
| API | API e integraciones | Corporativo | 4 | 13 | `/api-clients`<br>`/webhooks`<br>`/api-clients/{id}/rotate-secret`<br>`/webhook-deliveries/{id}/redeliver`<br>`/webhooks/{id}/test`<br>`/webhooks/{id}/webhook-deliveries` |

## 19. Mantenimiento del contrato

- Generar: `python3 tools/api/generar_openapi.py` (regenera `api/openapi.yaml` y esta guía).
- Validar: `python3 tools/api/validar_openapi.py` (también lo corre `make verify`). Comprueba la especificación con `openapi-spec-validator`, que cada tabla del catálogo tenga al menos una operación o esté en `api/excepciones.yaml`, que todo `x-permission` siga `module.resource.action` con módulo y tabla válidos, que no haya `operationId` repetidos y que `x-module`, `x-plan` y `x-phase` coincidan con el catálogo.
- Las columnas de los esquemas salen de la sección «tablas» de `tools/modulos/datos/*.txt`. Cuando exista `database/schema_main.sql`, la fuente de verdad del modelo es el esquema: si difiere, se corrige el generador.
