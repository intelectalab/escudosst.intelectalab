# database · Modelo de datos de EscudoSST

Dos bases PostgreSQL (cargan en 16; pensadas para 17):

| Archivo | Base | Dónde corre |
|---|---|---|
| `schema_main.sql` | `escudo_main` | Contabo (VPS, disco LUKS) |
| `schema_medical.sql` | `escudo_medical` | DigitalOcean Managed PostgreSQL |

Documento explicativo: [`docs/04-modelo-de-datos.md`](../docs/04-modelo-de-datos.md).

> **Los `.sql` y los JSON de `catalogs/` se generan; no se editan a mano.** Se edita la definición y se regenera:
>
> | Fuente | Generador | Produce |
> |---|---|---|
> | `tools/db/modelo_main.txt`, `tools/db/modelo_medical.txt`, `tools/db/sql/*.sql` | `python3 tools/db/generar.py` | `schema_main.sql`, `schema_medical.sql`, `catalogs/model.json` |
> | `spec/catalogo.json`, `docs/05-roles-y-permisos.md` §3–4, datos en el script | `python3 tools/db/generar_semillas.py` | `seeds/*.sql`, `catalogs/permissions.json`, `catalogs/roles.json`, `catalogs/role_permissions.json` |
> | `catalogs/model.json` | `python3 tools/db/generar_doc.py` | anexo por módulo de `docs/04-modelo-de-datos.md` |
>
> `tools/db/verificar_esquema.sh` ejecuta los tres generadores antes de verificar.

## Contenido

```
database/
├── schema_main.sql            base principal: tablas, índices, FKs, triggers, funciones, RLS, privilegios
├── schema_medical.sql         base médica
├── seeds/
│   ├── 01_modulos_planes.sql      61 módulos, planes E/P/C/K, plan_modules, 7 complementos
│   ├── 02_roles_permisos.sql      17 roles, 1258 permisos, matriz rol → permiso
│   ├── 03_parametros_legales.sql  18 normas, 54 parámetros legales (to_confirm; incluye 19 web.*: Libro de Reclamaciones, sitio, captación y v1.5), feriados 2026
│   ├── 04_catalogos_sst.sql       criterios de riesgo, peligros, agentes y límites, PETAR, plantillas de inspección,
│   │                                categorías documentales, KPI, reglas de notificación, paquetes sectoriales, ISO,
│   │                                escalas de calificación y plantilla de certificado globales
│   └── 05_sitio_web.sql           WEB: site_settings inicial, 6 documentos legales con versión 1 en borrador (sin texto legal),
│                                    7 otros productos («descripción editable», sin URL), 6 componentes de estado, autodiagnóstico
│                                    v1 en borrador (to_validate, 9 preguntas de ejemplo) y 4 subencargados a validar; v1.5: lineamientos de
│                                    contenido (expresiones bloqueadas), agenda de demos desactivada, chatbot desactivado en modo guiado
│                                    con 4 intenciones de ejemplo en borrador, conexión «Kallpa CRM» en pausa sin URL ni secreto y
│                                    calculadora de ahorro en borrador con supuestos vacíos
├── catalogs/
│   ├── permissions.json       permisos module.resource.action (código, módulo, recurso, acción, sensible)
│   ├── roles.json             17 roles con alcance, MFA obligatorio, rol médico, temporal
│   ├── role_permissions.json  matriz rol → lista de permisos (para el seeder de Laravel y las pruebas de permisos)
│   └── model.json             modelo normalizado (tablas, columnas, tipos, valores de estado, cifrado)
└── tests/
    └── pruebas_aislamiento.sql
```

## Cómo cargar

Requisitos: PostgreSQL 16 o 17 (`gen_random_uuid()` es nativo; no se necesita ninguna extensión). La primera carga crea los roles de base de datos, así que debe hacerla un usuario con `CREATEROLE` (en DigitalOcean, el usuario `doadmin`).

```bash
createdb escudo_main && createdb escudo_medical
psql -v ON_ERROR_STOP=1 -d escudo_main    -f database/schema_main.sql
for f in database/seeds/*.sql; do psql -v ON_ERROR_STOP=1 -d escudo_main -f "$f"; done   # idempotentes
psql -v ON_ERROR_STOP=1 -d escudo_medical -f database/schema_medical.sql

# Usuarios LOGIN reales (en despliegue, contraseñas desde el gestor de secretos):
#   CREATE ROLE escudo_api LOGIN PASSWORD '…' IN ROLE escudo_app;              -- escudo_main
#   CREATE ROLE escudo_owner_login LOGIN PASSWORD '…' IN ROLE escudo_owner;    -- migraciones
#   CREATE ROLE escudo_med_api LOGIN PASSWORD '…' IN ROLE escudo_med_app;      -- escudo_medical
```

Al dar de alta una empresa la API ejecuta, en la misma transacción que crea `companies` y su `subscriptions`:

```sql
SELECT seed_company_defaults('<company_id>');
```

En cada petición, el middleware `SetTenantContext` fija `SET LOCAL app.company_id`, `app.user_id` (y, según el caso, `app.account_id`, `app.contractor_id`; en la base médica también `app.reauth_session_id`).

## Verificación

```bash
bash tools/db/verificar_esquema.sh            # o: make verify (lo invoca tools/verificar_kit.py)
PG_BIN=/usr/lib/postgresql/17/bin bash tools/db/verificar_esquema.sh   # con PostgreSQL 17 si está instalado
KEEP_CLUSTER=1 bash tools/db/verificar_esquema.sh                       # deja el servidor corriendo para inspección
```

El script regenera todo, levanta un cluster temporal (`initdb` en el directorio de trabajo, puerto alto, socket local, sin sudo; si corre como root ejecuta el servidor con un usuario no root existente), carga ambas bases y las semillas, vuelve a cargar las semillas para probar idempotencia, comprueba conteos y ejecuta `tests/pruebas_aislamiento.sql`.

**Último resultado (PostgreSQL 16.15, 01/11/2026): todo en verde, 472 comprobaciones de aislamiento e inalterabilidad superadas.**

## Conteos

| | `escudo_main` | `escudo_medical` |
|---|---|---|
| Tablas | 277 (270 del catálogo + 7 agregadas) | 8 (7 del catálogo + 1 agregada) |
| Con RLS habilitado **y forzado** | 265 | 8 |
| Sin RLS (catálogos globales de solo lectura para la app) | 12: `addons`, `agent_types`, `holidays`, `iso_clauses`, `iso_standards`, `legal_norms`, `modules`, `permissions`, `plan_modules`, `plans`, `sector_pack_items`, `sector_packs` | 0 |
| Políticas RLS | 360 | 16 (incluye 7 restrictivas de re-autenticación) |
| Claves foráneas | 1 506 (502 compuestas `(company_id, id)`) | 4 internas (compuestas); ninguna hacia la base principal |
| Índices | 1 567 | — |
| Tablas inalterables (trigger + REVOKE) | 18: `audit_logs`, `sensitive_access_logs`, `signatures`, `credential_verifications`, `access_events`, `election_ballots`, `document_acknowledgements`, `notification_deliveries`, `certificate_verifications`, `payment_transactions` y las WEB `legal_acceptances`, `cookie_consents`, `complaint_events`, `resource_downloads`, `help_article_feedback`, `status_incident_updates`, `site_events`, `chatbot_messages` (las dos últimas particionadas por mes, como `audit_logs`) | 1: `medical_access_logs` |
| Funciones `SECURITY DEFINER` | 73 (endpoints públicos —fotocheck, reporte por QR, expediente, certificado, catálogo de venta, sitio web, Libro de Reclamaciones, herramientas de captación, agenda de demos, métricas con consentimiento, chatbot, campañas, popups, integraciones, novedades, webinars, reseñas y calculadora de ahorro—, contexto del webhook de pago, login, selector de empresa, alta de empresa, feature flags y activación por empresa, cadena de auditoría, verificación de catálogos mixtos) | 0 |
| Triggers | 375 | — |

Las tablas del catálogo en el total: 277 (`spec/catalogo.json` con el módulo WEB 1.10: 59 tablas de plataforma sin `company_id`, 14 del sitio y CMS + 20 de herramientas de captación de `spec/brief-web-extras.md` + 15 de v1.5 y 10 de v1.5-b de `spec/brief-web-v15.md`; antes, `spec/catalogo.json` 1.2: CAP suma certificados con plantilla, verificación pública y matriz por cargo; AUL pasa a portal propio de cursos con recomendaciones; módulo nuevo VTA con 10 tablas).

Semillas:

| Semilla | Contenido |
|---|---|
| Módulos y planes | 61 módulos (WEB interno, fuera de los planes); plan_modules E = 22, P = 39, C = 51 (acumulativos, `inclusion` native/inherited; AUL y VTA en Profesional y Corporativo), K = CON; 7 complementos (MOC, OFF, IAA, PSE, ISO, AMB, HOM; OFF, IAA, PSE disponibles en V1). `price_reference` NULL: «Por definir» |
| Roles | 17 roles del sistema (`account_id` NULL) con `scope_level`, `requires_mfa` (dueño, soporte, admin. de cuenta, médico, personal de salud, consultor), `is_medical`, `is_temporary`. `trainer` (alcance acotado) y `external_learner` (lo suyo) |
| Permisos | 1258 con convención `module.resource.action`. Acciones: create 283, read 283, update 265, delete 264, export 31, approve 29, publish 18, close 15, sign 13, read_sensitive 11, generate 10, revoke 10, verify 8, assign 3, import 3, sync 3, manage 2, share 2, confirm 1, respond 1, send 1, track 1, vote 1. WEB (249 permisos; v1.5 suma 10 especiales: `web.demo_meetings.assign`/`confirm`, `web.chatbot_intents.publish`, `web.crm_connections.manage`, `web.site_analytics.export` —recurso sin tabla, ligado a `site_analytics_daily`/`site_events`, solo `platform_owner`—, `web.campaigns.publish`, `web.site_popups.publish`, `web.product_updates.publish`, `web.webinars.publish`, `web.review_sources.manage`; `platform_support` atiende calendario, chatbot y medios, registra asistencia a webinars y lee métricas, sin gestionar el CRM ni exportar métricas; además 8 especiales de captación: `web.marketing_leads.assign`/`export`, `web.assessment_questionnaires.publish`, `web.success_stories.approve`, `web.help_articles.publish`, `web.partner_applications.approve`, `web.newsletter_issues.send`, `web.status_incidents.publish`): `web.*` completos para `platform_owner`, lectura y atención de demos, leads (actualizar y asignar, no exportar), ayuda (crear y editar artículos, no publicar) e incidentes (crear, actualizar, publicar y agregar actualizaciones) para `platform_support`, aceptación de políticas para usuarios y formularios públicos para `external_learner`. Las tablas inalterables solo tienen read/create (+ especiales) |
| Matriz rol → permiso | 5 337 asignaciones, derivadas de la matriz rol × módulo de `docs/05-roles-y-permisos.md` (— L P G A; si falta la fila VTA se usa `MATRIX_FALLBACK` del script) y de las reglas §4. `trainer` y `external_learner` tienen listas explícitas (`EXPLICIT_ROLE_PERMS`): el capacitador sube materiales, gestiona bancos y evaluaciones y califica, no crea cursos y su alcance real lo acota `trainer_permissions` (matricular solo con `can_enroll`); el participante externo ve y hace solo lo suyo y, si administra una licencia, asigna sus cupos. Además: datos médicos solo para médico (completo), personal de salud (limitado) y trabajador (sus EMO); `psychosocial_responses` nunca se leen individualmente; votos no legibles; auditor externo solo read/export; dueño y soporte sin datos de negocio |
| Parámetros legales | 54 parámetros con vigencia (v1.5 suma `web.analytics_event_retention_months` 13, `web.chatbot_retention_months` 12, `web.demo_recording_requires_consent` true, `web.review_cache_max_hours` 720 y `web.popup_max_per_visit` 1, P-63 a P-67, referenciales), todos `to_confirm = true` (incluye `igv_rate` para la venta de cursos): umbral de 20 trabajadores para Comité y RISST, integrantes y mandato del comité, capacitaciones mínimas anuales, plazos de notificación (mortales e incidentes peligrosos, enfermedades ocupacionales, no mortales), factores de IF/IG/IA (incluida variante minera), periodicidad de EMO, retención de registros e historia clínica, auditoría externa, plazos ARCO, comunicación de brechas a la ANPD, límites ergonómicos, altura, Libro de Reclamaciones y cookies (`web.complaint_response_days` 15 días hábiles, `web.complaint_extension_days`, `web.complaint_retention_years` 5, `web.complaint_warning_days`, P-53 a P-56). 17 normas de referencia (suma Ley 29571 y D.S. 011-2011-PCM) y 16 feriados 2026 |
| Catálogo SST | 2 criterios de riesgo (método de índices R.M. 050-2013-TR por defecto, y matriz 5 × 5); 58 peligros y riesgos en 13 familias; 21 tipos de agente y 5 límites de referencia; 7 tipos de PETAR con checklist y cadena de aprobación; 6 plantillas de inspección (35 ítems); 16 categorías documentales; 15 KPI; 12 reglas de notificación; 7 paquetes sectoriales; 3 normas ISO con 7 cláusulas mapeadas a módulos; 5 escalas de calificación globales (0-20 por defecto, 0-10, 0-100, AD/A/B/C, A/B/C/D) con nota aprobatoria de referencia que cada empresa ajusta; plantilla de certificado global por defecto `CERT-DEFAULT`. Sin precios de cursos |

## Tablas agregadas (fuera del catálogo)

| Tabla | Base | Módulo | Motivo |
|---|---|---|---|
| `modules` | principal | SUS | Catálogo de los 61 módulos; destino de FK de `plan_modules`, `addons`, `feature_flags`, `permissions` |
| `support_access_grants` | principal | USR | Autorización temporal del administrador de cuenta para que soporte acceda (vencimiento obligatorio; `includes_medical` siempre falso) |
| `worker_consents` | principal | TRB | Consentimiento / toma de conocimiento del trabajador (Ley 29733), incluido datos de salud, con versión del texto |
| `arco_requests` | principal | TRB | Solicitudes de derechos ARCO con estado y plazo por parámetro |
| `files` | principal | DOC | Metadatos de archivos en Backblaze B2 (nunca binarios en la base) |
| `legal_parameters` | principal | MRL | Parámetros legales con vigencia, fuente y `to_confirm` (global + override por empresa) |
| `holidays` | principal | MRL | Feriados para plazos en días hábiles |
| `medical_access_logs` | médica | AUD | Registro inalterable de accesos dentro de la base médica |

Decisiones WEB v1.5 fuera del texto del brief: `site_events.source`/`device` (para agregar por fuente y dispositivo sin guardar referrer ni navegador), `chatbot_messages.occurred_at` (clave de partición), `webinars.recording_informed` (requisito para publicar la grabación); `site_events` y `chatbot_messages` particionadas por mes para aplicar su conservación sin romper la inalterabilidad; el no-cruce de reuniones es un trigger con candado por anfitrión (la exclusión `EXCLUDE USING gist` con `uuid` exigiría `btree_gist`); `site_settings` suma `content_blocked_terms`, `comparison_blocked_terms`, `assessment_level_thresholds`, `newsletter_confirm_token_validity` y `status_history_days` (ajustes que cita la spec de M-WEB); los leads del chatbot entran con origen `contact` (el brief no agrega `chatbot` a `marketing_leads.source`).

Columnas pedidas por el backlog/docs: `idempotency_key`, `device_id`, `client_created_at`, `sync_batch_id` y `version` en todas las tablas sincronizables offline; `ai_assisted` + `ai_suggestion_id` en `iperc_rows` e `investigations` (y `ai_suggestion_id` en `investigation_causes`); `notification_overdue` en `event_notifications` y `sst_events`. Decisión del 01/11/2026 aplicada: AUL es portal propio (plan Profesional) con secciones, lecciones de texto/video Vimeo/documento/enlace/evaluación, bancos y preguntas, escalas, permisos y disponibilidad del capacitador, tiempo de permanencia por latidos, compromiso de culminación, recomendaciones y Moodle como única conexión opcional (`lms_connections.provider` solo `moodle`); CAP suma certificados validables/anulables con plantilla y verificación pública, y matriz por cargo; VTA nuevo. Columnas agregadas fuera de tablas nuevas: `feature_flags.company_enabled`/`toggled_by`/`toggled_at`, modo `disabled_by_company` y origen `company_setting` (activación por empresa de AUL y VTA), `companies.catalog_slug` (catálogo público), `bulk_imports.import_type` admite `questions`, `external_learners`, `position_training_requirements`; `roles.base_role_code` admite `trainer` y `external_learner`.

## Alineación con las especificaciones de módulo (M-SUS, M-CAP, M-AUL, M-VTA)

Nombres de la especificación usados tal cual: `feature_flags.mode = disabled_by_company`, `source = company_setting`, `company_enabled`; `training_certificates.certificate_code`, `public_verification_enabled`, `score_label`, `course_enrollment_id`, `validated_by/at`, `voided_by/at`, `void_reason`; `courses.public_verification_enabled`, `sequential`, `hours`; `course_enrollments.source`, `active_seconds_total`, `final_score_label`, `access_until`; `course_recommendations.source`, `buyer_company_ruc`, `converted_enrollment_id`, `converted_order_id`, `dedupe_key`, `reason_code`, `sent_at`/`viewed_at`/`clicked_at`/`converted_at`/`dismissed_at`; `payment_transactions.provider_reference`, `idempotency_key`; `lms_connections.credentials_secret_ref`, `sso_enabled`.

Diferencias que había entre especificación y modelo. **Resueltas el 01/11/2026:** las especificaciones de módulo (`tools/modulos/datos/`) ya usan los nombres del modelo. La tabla queda como registro de por qué el modelo eligió cada nombre:

| Especificación | Modelo | Motivo |
|---|---|---|
| `payment_transactions.processed_event_ids` (arreglo en una fila mutable) | una fila **inalterable** por evento, `UNIQUE (payment_gateway_id, external_id)`; `provider_reference` = id del cargo | La regla 16 exige procesar una sola vez por id externo; con una fila por evento la idempotencia la garantiza la base (`ON CONFLICT DO NOTHING`) y no hay UPDATE sobre pagos. El estado vigente vive en `orders.status` |
| `training_certificates.verification_token_hash` | `verification_code` aleatorio (12–32 caracteres, único en la plataforma) | La consulta pública es «por código» que la persona escribe; el código no es secuencial (no se enumera) y `certificate_code` sigue siendo el correlativo legible |
| `training_certificates.template_id`, `holder_type` | `certificate_template_id`; el tipo se deduce de cuál de `worker_id` / `contractor_worker_id` / `external_learner_id` está lleno (CHECK de exactamente uno) | Convención `<tabla>_id` del kit; evita un dato redundante |
| `certificate_verifications.certificate_id` nulo si el código no existe, `code_queried_hash` | `certificate_id` NOT NULL, `queried_code` | La tabla es de empresa (RLS); un código inexistente no tiene empresa. Esas consultas se cubren con el límite de uso |
| `courses.min_active_minutes`, `requires_commitment`, `enrollment_mode` | `min_active_time_pct`, `completion_commitment` (none/optional/mandatory), `modality` (internal/for_sale/both) | Porcentaje sobre las horas del curso; el compromiso puede ser opcional u obligatorio |
| `course_recommendations.recipient_type`, `offer_id`, `position_id`, `area_id` | `target_scope`, `course_offer_id`, `target_position_id`, `target_area_id` | Siempre una fila por destinatario (trabajador, participante o empresa); el alcance indica cómo se generó |
| `recommendation_rules.target_course_ids`, `source_filters`, `max_per_person_param`, `status` | `course_ids`, `parameters`, `max_sends_per_person` + `send_period_days`, `is_active` | Valores de configuración de la empresa, no parámetros legales |
| `course_offers.price`, `seat_limit`, `allows_corporate_licenses`, `visibility` | `list_price` + `sale_price`, `seats_total`, `allows_company_purchase`, `is_public` | Precio de lista y de oferta pedidos en la tarea |
| `orders.buyer_external_learner_id`, `billing_doc_number`, `tax_total`, `cancelled_reason` | `external_learner_id`, `buyer_document_number`, `igv_total`, `void_reason` (estado `voided`) | IGV explícito; estados pendiente/pagado/fallido/anulado/reembolsado |
| `order_items.offer_id`, `line_total`, `discount` | `course_offer_id`, `total` (+ `subtotal`, `igv_amount`), `discount_amount` | Base imponible e IGV por línea |
| `payment_gateways.environment`, `enabled_methods`, `status` | `mode` (test/production), `allowed_methods`, `is_active` | — |
| `course_licenses.quantity`, `assigned_count`, `buyer_representative_id` | `seats_purchased`, `seats_used`, `manager_user_id` | — |
| `external_learners.first_names`, `last_names`, `phone`, `employer_ruc`, `privacy_accepted_at` | `first_name`, `last_name`, `phone_e164`, `buyer_company_ruc`, `data_consent_at` | Convención de `workers` / `users` |
| `questions.bank_id`, tipo «relacionar» | `question_bank_id`; tipos single_choice/multiple_choice/true_false/short_answer | Tipos pedidos por el dueño |
| `trainer_permissions.trainer_user_id` nulo = valores por defecto | `trainer_user_id` NOT NULL | Los valores por defecto son los `DEFAULT` de la tabla |
| `position_training_requirements.is_mandatory` | `requirement_level` (mandatory/suggested) y `course_id` además de `training_id` | Matriz con curso u otra capacitación |

## Qué se verificó

En un cluster PostgreSQL 16.15 temporal:

- Ambos esquemas cargan sin errores; las 277 tablas del catálogo existen, cada una solo en su base (`fitness_status` solo en la principal).
- Toda tabla con `company_id` tiene RLS habilitado y forzado, y ninguna tabla con RLS queda sin política. `escudo_app`, `escudo_owner` y `escudo_med_app` sin BYPASSRLS ni superusuario.
- Aislamiento (empresas ALFA y BETA): BETA no ve trabajadores, aptitudes, fotochecks, eventos, firmas, bitácora, empresas ni sedes de ALFA; no puede actualizar filas de ALFA, ni insertar con su `company_id` (WITH CHECK), ni enlazar un trabajador de ALFA (FK compuesta), ni usar un criterio de riesgo propio de ALFA (trigger de catálogo mixto). Sin `app.company_id` no se ve nada, ni siquiera con el rol dueño (FORCE).
- La app no escribe catálogos globales ni suscripciones sin contexto de cuenta.
- `seed_company_defaults` crea sede, área, criterio, fotocheck y tableros, es idempotente y calcula los flags: Profesional = 39 módulos por plan y 38 efectivos (VTA desactivado por defecto, AUL activo), PETAR bloqueado; Esencial = 22 (AUL bloqueado). VTA nace `disabled_by_company` (`company_setting`). La app no puede cambiar `mode` de un flag ni desactivar módulos distintos de AUL/VTA; `set_company_module_enabled` activa VTA (pasa a `enabled`), no deja desactivar AUL con VTA activo, y un nuevo cálculo de flags respeta la decisión.
- Las 18 tablas inalterables (y `medical_access_logs`) rechazan UPDATE, DELETE y TRUNCATE, incluso como superusuario.
- WEB: las 14 tablas de plataforma tienen RLS forzado, ninguna con `company_id` (salvo la referencia en `legal_acceptances`) y sin privilegios para `escudo_app`; la app no lee borradores ni escribe; las funciones públicas devuelven solo lo publicado (página por copia publicada, FAQ, menú, productos visibles, testimonios aprobados, documento legal vigente, sitemap sin `noindex`, planes con «Solicitar cotización»); versión legal publicada no se edita y la nueva dispara re-aceptación; `legal_acceptances`, `cookie_consents` y `complaint_events` inalterables; `consumer_complaints` no se borra (ni como superusuario), sus datos no cambian, correlativo anual, plazo en días hábiles con feriados, prórroga con motivo y tope, hoja cerrada fija, consulta por código + clave sin datos personales; demo con consentimiento e idempotente.
- WEB captación (20 tablas): `escudo_app` no lee ni escribe ninguna ni ejecuta `web_upsert_lead`, `web_subscribe` ni las acciones del panel; semillas (7 productos «descripción editable» sin URL, 6 componentes operativos, autodiagnóstico en borrador `to_validate`, 4 subencargados a validar, 6 parámetros `to_confirm`, 8 permisos especiales; `platform_support` sin exportar leads); las funciones públicas solo devuelven lo publicado (recursos sin `file_id`, casos publicados con consentimiento o «Ejemplo» sin archivo de consentimiento, ayuda publicada de categorías activas y relacionados sin borradores, subencargados activos, incidentes publicados con sus actualizaciones, cuestionario publicado sin pesos); recurso gated exige lead; descargas, votos y formularios idempotentes; un lead por correo con origen fijo, último origen, consentimiento comercial separado que no se presume y conservación por parámetro; la demo entra a la bandeja de leads; autodiagnóstico con puntaje por sección y nivel, preguntas fijas al publicar; calculadora sin tabla cargada responde «parámetros pendientes» y con tabla vigente calcula UIT y soles y guarda la copia de parámetros con `to_confirm`; partner pendiente único, código de referido único (en mayúsculas) solo para partner aprobado, un referido por lead; boletín con doble confirmación de un solo uso, tokens solo como sha256, baja con un clic solo con el hash de su token, respuesta idéntica para correos ya suscritos; edición enviada no se edita; `resource_downloads`, `help_article_feedback` y `status_incident_updates` rechazan UPDATE, DELETE y TRUNCATE (también como superusuario).
- WEB v1.5 y v1.5-b (25 tablas): `escudo_app` no lee ninguna ni ejecuta `web_demo_free_slots`, `web_site_metrics`, `web_crm_retry_event` ni las acciones del panel; semillas (agenda y chatbot desactivados, 4 intenciones en borrador, «Kallpa CRM» en pausa sin URL, calculadora de ahorro sin supuestos, 5 parámetros `to_confirm`, 10 permisos especiales; exportar métricas solo el dueño; soporte sin CRM ni exportación); horarios libres según reglas sin la hora bloqueada, sin feriado, sin horas reservadas y sin datos del anfitrión; reserva idempotente que crea solicitud, lead y reunión propuesta; hora tomada o bloqueada `slot_unavailable`; token de cita solo como sha256 (gestión con otro token: `invalid`); reprogramar solo a hora libre; confirmar por Meet exige enlace; dos reuniones confirmadas del mismo anfitrión que se cruzan rechazadas (`23P01`), también al confirmar una propuesta; completar exige resultado; cancelar libera la hora; evento sin consentimiento analítico (o tras rechazar cookies) rechazado, `session_hash` validado, agregado diario idempotente (UNIQUE), métricas con conversiones, tasa y campañas; `site_events` y `chatbot_messages` rechazan UPDATE/DELETE/TRUNCATE (también al superusuario); chatbot guiado solo con intenciones publicadas, sin secretos, solo la sesión que abrió la conversación y lead con privacidad; CRM: conexión activa exige URL y secreto, reclamos no sincronizables ni encolados, lead y demo encolados solo para la conexión activa, varias actualizaciones de la misma versión = un envío, `idempotency_key` única, fallo → reintento → enviado; imagen sin `alt_text` rechazada, usos del medio y reemplazo sin romper la galería; landing de campaña `noindex`, solo vigente y por `/l/{slug}`, término no veraz bloquea la publicación; popups nunca en reclamaciones ni políticas; integraciones y novedades solo publicadas; webinar sin enlace público, grabación solo informada, inscripción idempotente con cupo y token como hash; reseñas filtradas por calificación mínima y ocultas al vencer; calculadora de ahorro sin supuestos no calcula, con supuestos guarda la copia y es idempotente.
- CAP/AUL/VTA: aislamiento de las 23 tablas nuevas entre ALFA y BETA (lectura, actualización, inserción con otro `company_id`, FK compuestas y catálogo mixto de cursos); BETA sin AUL no ve escalas globales; matrícula y certificado con exactamente un participante; una matrícula vigente por persona y curso; compra exige línea de pedido; latido de permanencia idempotente y sin segundos imposibles; lección de video exige id de Vimeo; respuesta corta exige respuestas aceptadas; Moodle como único proveedor; recomendación con exactamente un destinatario, sin duplicados activos (`dedupe_key`) y conversión con matrícula o pedido; oferta ≤ lista; pedido idempotente y con `total = base + IGV`; llave de pasarela en claro rechazada; webhook repetido no duplica (`ON CONFLICT DO NOTHING` por `(gateway, external_id)`), webhook sin firma rechazado, payload con número de tarjeta rechazado; cupos ≤ comprados; factura exige RUC.
- Verificación pública de certificado por código: válido, código inexistente o mal formado «no válido», sin correo, teléfono, documento completo ni nota; cada consulta registrada. Catálogo público solo con VTA activo y sin ids internos. Contexto del webhook resuelve la empresa por pasarela y proveedor.
- Cadena de hash de `audit_logs` íntegra y manipulación detectada por `audit_logs_verify_chain`.
- Verificación pública de fotocheck: «habilitado», token falso «no válido», sin datos médicos ni documento, cada verificación registrada. Reporte por QR idempotente.
- Offline: `idempotency_key` duplicada rechazada; `version` se incrementa y una versión desfasada da conflicto `40001`.
- Reglas: aprobador de IPERC ≠ elaborador; fila IPERC asistida por IA exige `ai_suggestion_id`.
- Base médica: BETA no ve ni inserta datos de ALFA; sin `app.reauth_session_id` no se lee ni lo propio; sin contexto no se ve nada; sin FK hacia otras bases.
- Semillas idempotentes (segunda carga sin errores) y con las invariantes: 17 roles, E:22 P:39 C:51 K:1, parámetros `to_confirm`, permisos médicos solo en roles médicos.

## No verificado

- **PostgreSQL 17**: en este entorno solo están los binarios de 16. El SQL evita lo que cambió en 17/18 (función `uuidv7()` propia en `public`; `NULLS NOT DISTINCT` existe desde 15). Debe correrse `PG_BIN=/usr/lib/postgresql/17/bin bash tools/db/verificar_esquema.sh` en CI.
- **DigitalOcean Managed PostgreSQL**: no se probó la creación de roles con `doadmin` ni las restricciones del servicio gestionado.
- El cifrado de campos ocurre en la aplicación (Laravel); aquí solo se documenta en `COMMENT ON COLUMN` y en `docs/04`.
- Rendimiento con volumen real (particiones de `audit_logs`, índices) no medido.
- Los valores legales sembrados son referenciales y **todos requieren confirmación de un especialista SST/legal** antes de producción.
