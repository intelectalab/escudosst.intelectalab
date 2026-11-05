# 04 · Modelo de datos

Fuente: `spec/catalogo.json` (61 módulos, 277 tablas: 270 en la base principal y 7 en la médica). El SQL ejecutable está en `database/schema_main.sql` y `database/schema_medical.sql`, generados por `tools/db/generar.py` desde la definición compacta `tools/db/modelo_main.txt` y `tools/db/modelo_medical.txt`. **Para cambiar el modelo se edita la definición y se regenera; no se editan los `.sql` a mano.** En el desarrollo, cada cambio se traduce a una migración de Laravel en `database/migrations/main` o `database/migrations/medical` (ver `02-convenciones.md` §6).

Verificación: `bash tools/db/verificar_esquema.sh` levanta un PostgreSQL temporal, carga ambas bases, las semillas y las pruebas de aislamiento. Detalle y conteos en `database/README.md`.

## 1. Visión general

| Base | Dónde | Contenido | Tablas |
|---|---|---|---|
| `escudo_main` | Contabo (VPS, disco LUKS) | Todo lo que no es dato médico: estructura, SST, documentos, registros, firmas, suscripciones, catálogos, sitio web público (WEB) | 270 del catálogo + 7 agregadas = 277 |
| `escudo_medical` | DigitalOcean Managed PostgreSQL (cifrada en reposo) | Solo lo marcado `db: med`: EMO, vigilancia médica, detalle de descansos, grupos vulnerables | 7 del catálogo + 1 agregada = 8 |

Principios:

1. **Aislamiento por empresa.** La unidad de aislamiento es la empresa (`companies.id` = `company_id`). Toda tabla de negocio lleva `company_id NOT NULL`, RLS habilitado **y forzado** y una política `rls_<tabla>_company` con `company_id = NULLIF(current_setting('app.company_id', true), '')::uuid`. Si la API no fija el contexto, la expresión es NULL y no pasa ninguna fila.
2. **Integridad entre empresas.** Cada tabla de empresa tiene `UNIQUE (company_id, id)`; las referencias entre tablas de empresa son FK compuestas `(company_id, x_id) → (company_id, id)`. Es imposible enlazar filas de dos empresas aunque la aplicación falle.
3. **Catálogos.** Tres tipos: globales sin `company_id` (normas, planes, permisos, tipos de agente, paquetes sectoriales, ISO); **mixtos** (`company_id` nulo = fila de plataforma, con valor = fila propia de la empresa: peligros, criterios de riesgo, plantillas de inspección, tipos de permiso, categorías documentales, KPI, reglas de notificación, parámetros legales, cursos de catálogo); de cuenta (`accounts`, `subscriptions`, `invoices`, `white_label_settings`) aislados por `app.account_id`; y **de plataforma** (las 59 tablas del sitio web WEB —14 del sitio y CMS + 20 de herramientas de captación + 15 de v1.5 + 10 de v1.5-b—, sin `company_id`): RLS forzado con política solo para `escudo_platform`, sin ningún privilegio para `escudo_app`; lo público sale por funciones `SECURITY DEFINER` que devuelven solo contenido publicado (§3.10).
4. **Columnas estándar.** `id` UUID v7 (`uuidv7()` propia en PL/pgSQL, compatible con 16 y 17), `company_id`, `created_at`, `created_by`, `updated_at`, `updated_by`, `deleted_at` (borrado lógico). Las tablas inalterables no tienen `updated_*` ni `deleted_at`.
5. **Estados explícitos.** Cada columna `status` tiene un `CHECK ... IN (...)` con los estados de `06-maquinas-de-estado.md`. Las transiciones las controla la clase `<Entidad>StateMachine`; la base garantiza los valores válidos y las condiciones mínimas de cada estado (motivo obligatorio al anular, verificador al cerrar, etc.).
6. **Archivos fuera de la base.** `files` guarda metadatos (bucket, clave, tipo, tamaño, SHA-256, estado de antivirus); el objeto vive en Backblaze B2. Las tablas referencian `*_file_id`. Los archivos médicos viven en la base médica (`medical_exam_files`) y en el bucket médico.
7. **Valores legales como parámetros.** Plazos, factores, periodicidades y umbrales están en `legal_parameters` con vigencia (`valid_from`/`valid_to`), fuente y `to_confirm = true` hasta que un especialista los confirme. Las tablas guardan la **clave** del parámetro usado (p. ej. `event_notifications.legal_parameter_key`) y, cuando el valor afecta un cálculo histórico, una copia (`monthly_statistics.parameters_snapshot`).

## 2. Diagrama de alto nivel

```mermaid
flowchart LR
  subgraph G1[1 · Núcleo]
    ACC[accounts] --> CO[companies]
    CO --> SI[sites] --> AR[areas]
    CO --> PO[positions]
    CO --> WK[workers]
    US[users] --> MB[memberships] --> CO
    CO --> FF[feature_flags]
    SUB[subscriptions] --> CO
    DOCS[documents · document_versions]
    SIG[signatures · signature_requests]
    AUD[audit_logs · sensitive_access_logs]
    FI[files]
  end
  subgraph G2[2 · Planificación]
    LBA[baseline_*] --> ACR
    POL[sst_policies · sst_objectives]
    PLA[annual_plans] --> PAS[annual_programs · program_activities]
    COMM[committees · elections · committee_meetings]
    MRL[legal_norms · legal_requirements]
  end
  subgraph G3[3 · Riesgos]
    IPC[iperc_matrices → iperc_versions → iperc_rows → iperc_controls]
    MAG[monitoring_campaigns → measurements]
    PET[procedures · procedure_versions]
  end
  subgraph G4[4 · Campo]
    INS[inspections → findings]
    ACT[substandard_reports]
    PTR[work_permits · permit_approvals]
    EPP[ppe_deliveries]
  end
  subgraph G5[5 · Personas y salud]
    CAP[trainings → training_attendances]
    FOT[credentials → credential_verifications]
    EMO[medical_exam_orders · fitness_status]
  end
  subgraph G6[6 · Eventos y mejora]
    EV[sst_events → investigations]
    ACR[corrective_actions]
    EST[man_hours · monthly_statistics]
    REG[register_exports]
  end
  subgraph G7[7 · Contratistas]
    CTR[contractors → contractor_workers]
    SCT[sctr_policies → sctr_coverages]
    ING[access_events]
  end
  subgraph G8[8 · Diferenciadores]
    SUN[inspection_dossiers → dossier_share_links]
    OFF[devices → sync_batches → sync_conflicts]
    IAA[ai_requests → ai_suggestions]
  end
  subgraph MED[(escudo_medical)]
    ME[medical_exams · medical_exam_files]
    VM[surveillance_* · clinical_notes]
    ML[medical_leave_details · vulnerability_records]
    MAL[medical_access_logs]
  end
  WK --> CAP & EPP & FOT & EMO
  INS & ACT & EV & IPC --> ACR
  EMO -. fitness_status (solo estado) .- ME
  WK -. worker_id sin FK .- MED
  IAA -. sugerencia pendiente .-> IPC & EV
  OFF -. idempotency_key .-> INS & ACT & PTR & EPP
  CTR --> PTR & ING & FOT
```

Relaciones transversales importantes:

| Desde | Hacia | Cómo |
|---|---|---|
| Inspecciones, hallazgos, reportes, eventos, investigaciones, auditorías, línea base, requisitos legales, acuerdos | `corrective_actions` | `source_type` + `source_id` y, en sentido inverso, `*.corrective_action_id` |
| Cualquier registro firmable | `signature_requests` / `signatures` | `subject_type` + `subject_id` + `document_hash` |
| Cualquier registro | `audit_logs` | `entity_type` + `entity_id`, encadenado por hash |
| Registros sincronizables | `sync_batches`, `devices` | `idempotency_key`, `device_id`, `client_created_at`, `version` |
| IPERC e investigación | `ai_suggestions` | `ai_assisted` + `ai_suggestion_id` (CHECK: si es asistido, la sugerencia es obligatoria) |
| `medical_exam_orders`, `absences`, `work_adjustments` | base médica | `medical_exam_ref`, `medical_leave_ref`, `vulnerability_ref` (UUID sin FK) |

## 3. Reglas de integridad

### 3.1 Aislamiento y roles de base de datos

| Rol | Uso | BYPASSRLS | Notas |
|---|---|---|---|
| `escudo_owner` / `escudo_med_owner` | Dueño de los objetos; ejecuta migraciones | No | `FORCE ROW LEVEL SECURITY` también lo alcanza |
| `escudo_app` / `escudo_med_app` | Rol de la API y las colas | No | Usuario LOGIN real se crea en despliegue con `IN ROLE escudo_app` |
| `escudo_platform` | Panel del dueño del SaaS (SUS y WEB) | No | Escribe catálogos globales y las tablas del sitio web; ve cuentas, empresas y suscripciones, nunca datos de negocio de empresas |
| `escudo_definer` | Solo dueño de funciones `SECURITY DEFINER` | Sí, NOLOGIN | Nadie inicia sesión con él. Sus funciones están acotadas y revisadas |

Contexto que fija el middleware `SetTenantContext` con `SET LOCAL`: `app.company_id`, `app.user_id`, `app.account_id` (panel de cuenta y consultora), `app.contractor_id` (portal de contratistas: política restrictiva adicional en `contractors`, `contractor_workers`, `contractor_documents`, `contractor_accreditations`, `sctr_policies`) y, solo en la base médica, `app.reauth_session_id` (política restrictiva: sin re-autenticación no se lee ninguna tabla clínica).

Políticas especiales:

| Tabla | Regla |
|---|---|
| `companies` | La empresa activa, las empresas de la cuenta activa y las gestionadas por la consultora activa |
| `users` | Identidad global: el propio usuario y los miembros de la empresa activa. El login usa `app_auth_lookup_user(email)` |
| `roles`, `role_permissions` | Roles del sistema (`account_id` NULL) visibles a todos; roles personalizados por cuenta |
| `sessions_mfa` | Solo las sesiones del propio usuario |
| `audit_logs` | La empresa activa lee e inserta lo suyo; la plataforma solo eventos de plataforma y sesiones de soporte |
| Catálogos mixtos | Lectura: lo propio + lo global. Escritura: solo lo propio. La plataforma escribe solo lo global. `courses`, `course_sections`, `course_lessons` y `grading_scales` globales solo visibles si AUL está habilitado y activo en la empresa (`app_module_enabled('AUL')`) |
| Referencias a catálogos mixtos | Trigger `trg_check_hybrid_ref`: la fila referida debe ser global o de la misma empresa (la FK simple no lo garantiza) |

### 3.2 Funciones `SECURITY DEFINER` (todas en `escudo_main`)

| Función | Para qué | Qué expone |
|---|---|---|
| `public_verify_credential(token)` | Verificación pública del fotocheck por QR | Resultado, número, nombre, puesto, empresa, vencimiento y códigos de requisito faltante. Nunca datos médicos ni documento. Registra `credential_verifications` |
| `public_report_point(token)` / `public_submit_report(...)` | Reporte de actos y condiciones por QR sin cuenta | Empresa, sede, área. Inserción idempotente por `idempotency_key` |
| `public_open_dossier(token)` | Link compartido del expediente SUNAFIL | Índice del expediente; valida vencimiento, revocación y límite de vistas; audita cada vista |
| `app_auth_lookup_user(email)` / `app_my_memberships()` | Login y selector de empresa | Datos mínimos de autenticación y membresías del propio usuario |
| `seed_company_defaults(company_id)` | Alta de empresa | Ver §3.5 |
| `refresh_feature_flags(company_id)` | Recalcular módulos habilitados | Ver §3.6 |
| `trg_audit_logs_chain()` / `audit_logs_verify_chain(company_id)` | Cadena de hash de la bitácora | Ver §3.4 |
| `public_verify_certificate(code)` | Verificación pública de certificado (CAP) por `verification_code` aleatorio | Estado, número, nombre, documento enmascarado, curso, horas, fechas y empresa; «no público» si el curso no lo permite. Nunca correo, teléfono, documento completo ni nota. Registra `certificate_verifications` |
| `public_course_catalog(slug)` | Catálogo público de cursos a la venta (VTA) | Ofertas publicadas y visibles de la empresa con VTA activo; sin datos personales ni ids internos |
| `payment_gateway_webhook_context(gateway_id, provider)` | Webhook de pasarela | Empresa, modo y **referencia** al secreto de firma para verificarla y fijar `app.company_id`; nunca secretos en claro |
| `set_company_module_enabled(module, enabled)` | Activación por empresa de AUL y VTA | Ver §3.6 |
| `public_site_settings()`, `public_site_page(slug)`, `public_site_menu(location)`, `public_site_faqs(...)`, `public_site_products()`, `public_site_testimonials()`, `public_pricing()`, `public_sitemap()`, `public_noindex_paths()`, `public_resolve_redirect(path)`, `public_legal_document(doc_type)` | Sitio público (WEB), solo lectura | Solo contenido publicado: copia publicada de la página (`published_snapshot`), FAQ publicadas, productos visibles, testimonios aprobados, versión legal vigente; planes con `price_reference` o «Solicitar cotización». Nunca borradores |
| `public_record_cookie_consent(...)`, `public_record_legal_acceptance(...)`, `app_pending_legal_acceptances()` | Cookies y aceptación de políticas (WEB) | Inserción inalterable; solo se acepta la versión vigente publicada; pendientes de (re)aceptación del usuario de la sesión |
| `public_submit_complaint(...)`, `public_complaint_status(code, key)`, `public_create_demo_request(...)` | Libro de Reclamaciones y formularios (WEB) | Registro idempotente con correlativo anual, plazo y conservación por parámetro; estado por código + clave sin datos personales. La demo también crea o actualiza su lead |
| `public_resources(...)`, `public_success_stories(...)`, `public_help_categories()`, `public_help_articles(...)`, `public_help_article(slug)`, `public_subprocessors()`, `public_service_status()`, `public_self_assessment()`, `public_fine_calculator_parameters()` | Captación (WEB), solo lectura | Recursos publicados sin `file_id`; casos publicados (con consentimiento escrito o «Ejemplo») sin archivo de consentimiento; ayuda publicada de categorías activas con relacionados publicados; subencargados activos; componentes visibles e incidentes publicados; cuestionario publicado sin pesos; parámetros legales vigentes de la calculadora |
| `public_calculate_fine(...)`, `public_submit_self_assessment(...)`, `public_download_resource(...)`, `public_help_feedback(...)`, `public_apply_partner(...)`, `public_newsletter_subscribe(...)`, `public_newsletter_confirm(token)`, `public_newsletter_unsubscribe(token)`, `public_status_subscribe(...)` | Formularios de captación (WEB) | Inserción acotada e idempotente; crean o actualizan el lead (`web_upsert_lead`, no ejecutable por la app); calculadora con copia de los parámetros vigentes; tokens del boletín comparados por `sha256`; respuesta idéntica exista o no el correo |
| `public_demo_slots(from, to)`, `public_demo_book(...)`, `public_demo_manage(token)`, `public_demo_reschedule(token, starts_at)`, `public_demo_cancel(token, reason)` | Calendario de demos (WEB v1.5) | Horarios libres sin anfitrión ni datos personales (reglas, bloqueos, feriados, reuniones ocupadas, aviso mínimo, horizonte); reserva idempotente que crea solicitud, lead y reunión propuesta; gestión solo con el hash del token |
| `public_record_event(visitor_id, event)` | Métricas propias (WEB v1.5) | Inserta solo si el último consentimiento de cookies del visitante acepta analítica (si no, 42501); sin IP, navegador ni lead |
| `public_chatbot_config()`, `public_chatbot_start(...)`, `public_chatbot_message(...)`, `public_chatbot_record_ai_reply(...)`, `public_chatbot_lead(...)` | Chatbot (WEB v1.5) | Intenciones publicadas, nunca el secreto de IA; solo la sesión que abrió la conversación escribe; lead con privacidad obligatoria |
| `public_campaign_page(slug)`, `public_site_popups(path, device)`, `public_site_integrations()`, `public_product_updates(...)`, `public_product_update(slug)`, `public_webinars()`, `public_webinar(slug)`, `public_reviews()`, `public_savings_calculator()` | WEB v1.5-b, solo lectura | Landing de campaña publicada y vigente; popups activos fuera de reclamaciones, políticas y formularios; integraciones y novedades publicadas; webinars sin enlace de reunión; reseñas en caché vigente; supuestos visibles |
| `public_webinar_register(...)`, `public_webinar_cancel(token)`, `public_calculate_savings(...)` | Formularios WEB v1.5-b | Inscripción idempotente por persona con cupo bajo candado y token como hash; cálculo de ahorro con copia de supuestos e idempotencia |

Los tokens públicos se guardan como `sha256` (`token_hash`) y solo se muestran los últimos 4 caracteres (`token_last4`).

### 3.3 Tablas inalterables

`audit_logs`, `sensitive_access_logs`, `signatures`, `credential_verifications`, `access_events`, `election_ballots`, `document_acknowledgements`, `notification_deliveries`, `certificate_verifications` (consultas públicas de certificados), `payment_transactions` (eventos de pago de la pasarela; cada cambio de estado es una fila nueva, única por `(payment_gateway_id, external_id)`), las ocho de WEB `legal_acceptances`, `cookie_consents`, `complaint_events`, `resource_downloads`, `help_article_feedback`, `status_incident_updates`, `site_events` y `chatbot_messages` (18 en la base principal; las dos últimas particionadas por mes) y, en la base médica, `medical_access_logs`. Además, `consumer_complaints` no se borra (trigger que rechaza `DELETE` y `TRUNCATE`, sin `deleted_at`) y sus datos registrados no se modifican.

- Trigger por sentencia `BEFORE UPDATE OR DELETE` y `BEFORE TRUNCATE` que lanza error para **cualquier** rol, incluido el dueño.
- `REVOKE UPDATE, DELETE, TRUNCATE` al rol de aplicación; la política RLS solo permite `SELECT` e `INSERT`.
- Las correcciones se registran como filas nuevas (una entrega de notificación que pasa a `delivered` es otra fila de `notification_deliveries`; una nota clínica corregida es otra nota con `amended_note_id`).
- `election_ballots` no tiene `worker_id`: el voto se identifica con `voter_token_hash` (HMAC con llave fuera de la base) para impedir el doble voto sin poder ligar voto y persona. `CHECK` exige exactamente una opción (candidato, blanco o nulo).

### 3.4 Bitácora encadenada (`audit_logs`)

- Particionada por mes (`PARTITION BY RANGE (occurred_at)`), con partición por defecto y `audit_logs_ensure_partition(mes)` para que un job cree los meses por adelantado.
- Una cadena por empresa (y una para eventos de plataforma): `chain_seq` correlativo, `prev_hash` = `row_hash` de la fila anterior, `row_hash = sha256(prev_hash || contenido canónico)`. Lo calcula el trigger `trg_audit_logs_chain` con un candado por empresa; la aplicación no puede fijarlos.
- `audit_logs_verify_chain(company_id)` recorre la cadena y devuelve cada fila con secuencia discontinua, `prev_hash` que no coincide o contenido alterado. La prueba de aislamiento demuestra que detecta una manipulación hecha desactivando triggers como superusuario.
- `changes` guarda antes/después con campos sensibles enmascarados (la aplicación enmascara antes de insertar).

### 3.5 Alta de empresa: `seed_company_defaults(company_id)`

Idempotente. Crea, si no existen: sede principal `SEDE-01` y área `GEN`; una copia propia del criterio de riesgo por defecto (método de índices R.M. 050-2013-TR) para que la empresa lo ajuste sin tocar el global; el tipo de fotocheck `FOTOCHECK-SST` con tres requisitos base (inducción, aptitud EMO vigente, SCTR); tableros por defecto para Jefe SST, gerencia, comité y jefe de área con los KPI globales; los `feature_flags` desde la suscripción (AUL activo y VTA desactivado por defecto dentro del plan); y un evento en `audit_logs`. La llama la API después de crear la empresa y la suscripción.

### 3.6 Planes y feature flags

`modules` (60, agregada) es el catálogo de módulos; `plans` + `plan_modules` (E: 22, P: 39, C: 51 acumulativos, K: CON) y `addons` (7 complementos: MOC, OFF, IAA, PSE, ISO, AMB, HOM). `refresh_feature_flags(company_id)` calcula por módulo `enabled`, `read_only` o `locked`: lo contratado (plan o complemento activo) queda `enabled` (o `read_only` si la suscripción está en solo lectura); lo que deja de estar contratado pasa a `read_only` con `source = 'downgrade'` (nunca se borra); las excepciones manuales `override` vigentes se respetan. Los precios (`price_reference`) quedan en NULL: «Por definir».

**Activación por empresa.** `feature_flags.company_enabled` (por defecto verdadero) permite que la empresa desactive AUL o VTA aunque su plan los incluya (`CHECK company_enabled OR module_code IN ('AUL','VTA')`). Si el plan lo incluye y la empresa lo desactivó, el flag queda en `mode = 'disabled_by_company'` con `source = 'company_setting'` (`CHECK mode <> 'disabled_by_company' OR NOT company_enabled`); `app_module_enabled()` solo deja pasar `enabled` y `read_only`. VTA exige AUL activo. `refresh_feature_flags` fija `company_enabled` solo al crear el flag (VTA en falso) y luego respeta la decisión de la empresa. La aplicación solo la cambia mediante `set_company_module_enabled()` (audita el cambio); el trigger `trg_feature_flags_guard` impide que `escudo_app` modifique `mode`, `source` o la vigencia.

### 3.7 Offline e IA

- Tablas sincronizables (marcadas «offline» en el anexo): `idempotency_key` única por empresa, `device_id`, `client_created_at`, `sync_batch_id` y `version`. El trigger `trg_bump_version` incrementa `version` en cada UPDATE y rechaza con `40001` una versión desfasada; la API registra entonces un `sync_conflicts` en vez de pisar.
- `sync_batches` es idempotente por `(company_id, idempotency_key)` y por `batch_uuid`.
- `ai_requests` tiene `CHECK (contains_medical_data = false)` e `input_redacted`. Toda salida es una `ai_suggestions` en `pending`; solo una persona la acepta (`reviewed_by` obligatorio para cualquier estado distinto de pendiente o vencido). `iperc_rows`, `investigations` e `investigation_causes` guardan `ai_assisted` y `ai_suggestion_id`.

### 3.8 Aula virtual, certificados y venta de cursos (CAP, AUL, VTA)

- **Certificados** (`training_certificates`): de una capacitación o de un curso, para trabajador, trabajador de contratista o participante externo (exactamente uno). Código legible `certificate_code` por empresa `PREFIJO-AÑO-CORRELATIVO` (p. ej. `CERT-2026-000142`, prefijo de la plantilla) y `verification_code` aleatorio único en la plataforma para la consulta pública (no se puede enumerar). Estados `pending_validation → validated → voided/expired`; validar exige quién y cuándo, anular exige quién, cuándo y motivo. Plantillas en `certificate_templates` (global por defecto + propias con logo, firmas y campos variables).
- **Matriz por cargo** (`position_training_requirements`): puesto → capacitación o curso (exactamente uno), obligatorio o sugerido, renovación en meses y matrícula automática al asignar el puesto.
- **Portal de cursos**: `courses` (interno, a la venta o ambos; recorrido secuencial, escala, nota mínima, intentos, compromiso obligatorio u opcional, horas, vigencia y verificación pública del certificado), `course_sections`, `course_lessons` (texto, video en Vimeo con id y % mínimo de visualización, documento, enlace https o evaluación sorteada de un banco), `question_banks` (generales o del curso) y `questions` (opción única, múltiple, verdadero/falso, respuesta corta; importables desde Excel vía `bulk_imports`), `grading_scales` (globales 0-20, 0-10, 0-100, AD/A/B/C, A/B/C/D y propias).
- **Matrícula** (`course_enrollments`): trabajador, trabajador de contratista o participante externo (`CHECK` de exactamente uno); origen manual, por cargo, compra (exige línea del pedido), licencia (exige asignación), autoinscripción o Moodle; una matrícula vigente por persona y curso.
- **Tiempo de permanencia real** (`learning_activity_sessions`): una fila por sesión del cliente, única por `(enrollment_id, client_session_id)`; los latidos suman `active_seconds` solo con interacción o video visible, `last_heartbeat_seq` hace idempotente cada latido y un `CHECK` impide más segundos activos que el tiempo transcurrido. La suma va a `course_enrollments.active_seconds_total`.
- **Capacitador**: `trainer_permissions` (globales o por curso; por defecto sube materiales y no matricula) y `trainer_availability` (horario semanal y fechas bloqueadas). Crear cursos es solo del administrador.
- **Moodle** (`lms_connections`): único proveedor `moodle`, URL https y token solo como referencia al gestor de secretos.
- **Recomendaciones** (`recommendation_rules`, `course_recommendations`): reglas de brechas de la matriz, vencimientos, rutas, complementarios, hallazgos, accidentes del área, compradores similares o manual; destinatario trabajador, participante externo o empresa compradora (exactamente uno); embudo `suggested → sent → viewed → clicked → converted/dismissed` con marcas de tiempo; una conversión exige `converted_enrollment_id` o `converted_order_id`; `dedupe_key` única mientras la recomendación está activa. Ninguna regla usa datos médicos.
- **Venta** (VTA): `course_offers` (precio en PEN definido por la empresa, oferta ≤ lista, cupo, días de acceso, visible en el catálogo público), `coupons`, `orders` (persona o empresa con RUC, totales con IGV `total = subtotal + igv_total`, `igv_rate` desde el parámetro legal `igv_rate` marcado «a confirmar», `idempotency_key` única), `order_items`, `payment_gateways` (Culqi, Niubiz, Izipay o Mercado Pago; llaves y secreto del webhook solo como referencia `esquema://…` al gestor de secretos, nunca en claro), `payment_transactions` (inalterable; `CHECK` que rechaza `card_number`, `pan`, `cvv` en el payload; solo `card_last4` y token de la pasarela), `course_licenses` y `license_assignments` (cupos ≤ comprados), `sales_documents` (boleta, factura con RUC, notas con comprobante relacionado; estado SUNAT y XML/CDR/PDF como `files`; proveedor por definir) y `external_learners` (documento y teléfono cifrados, HMAC del documento, consentimiento Ley 29733 obligatorio).
- **Catálogo público**: `companies.catalog_slug` identifica la página pública de venta de la empresa.

### 3.10 Sitio web público y CMS (WEB, interno)

- **Tablas de plataforma** (sin `company_id`, tipo `platform` en `modelo_main.txt`): `site_settings` (fila única: titular «GRUPO DE SOLUCIONES CREATIVAS SAC», RUC, domicilio por completar, dominio `escudosst.com` editable, logo como `file_id`, contactos, responsable del Libro de Reclamaciones, WhatsApp (`whatsapp_number`, mensaje general; cada página tiene `site_pages.whatsapp_message`), `blog_url` del WordPress externo y `show_latest_blog_posts`, videos de portada, barra de anuncio, `trust_badges_json`, `interactive_demo_url`, redes, analítica y colores), `site_pages` (bloques, SEO, `published_snapshot`), `site_menus`, `site_products`, `site_testimonials` (aprobado exige consentimiento o `is_example`), `site_faqs`, `site_redirects` (301/302), `legal_documents`, `legal_document_versions`, `legal_acceptances`, `cookie_consents`, `consumer_complaints`, `complaint_events`, `demo_requests` (`request_type` demo/contact/quote). El blog es un WordPress aparte: no hay tablas de blog.
- **Publicar = copiar.** `web_publish_page()` copia título, bloques y SEO a `published_snapshot`; lo público lee solo esa copia, así que editar una página publicada no cambia la web hasta volver a publicar.
- **Políticas versionadas.** Una versión publicada no se edita (trigger `trg_legal_document_versions_lock`; solo `published → superseded`). `web_publish_legal_version()` exige versión aprobada, guarda el hash del contenido, reemplaza la anterior y, con `requires_reacceptance`, la exige a todos (`app_pending_legal_acceptances()`). Las semillas crean los 6 documentos con una versión 1 en borrador «Por redactar y validar con abogado» (sin texto legal).
- **Libro de Reclamaciones.** Código `LR-AAAA-NNNNNN` correlativo anual (candado por año), copia de los datos del proveedor, consumidor y apoderado cifrados en la aplicación, `response_due_on` con `add_business_days()` y el parámetro `web.complaint_response_days`, prórroga única con motivo y tope `web.complaint_extension_days`, `retain_until` con `web.complaint_retention_years` (todos `to_confirm`). Trigger `trg_consumer_complaints_guard`: datos registrados fijos, transiciones `received → in_progress → extended → answered → closed`, respuesta no editable, cerrada no cambia. Cada acción deja un evento inalterable (`web_complaint_action()`). La consulta pública exige código + clave de seguimiento (`tracking_key_hash`), porque el correlativo es predecible.

- **Herramientas de captación** (`spec/brief-web-extras.md`, 20 tablas de plataforma más): `marketing_leads` (un lead por correo en minúsculas; `source` fijo y `last_source`; UTM; `privacy_consent` + `privacy_version_id`; `marketing_consent` **separado** con `marketing_consent_at`/`marketing_consent_withdrawn_at`; `retain_until` por `web.lead_retention_months`; la demo y el contacto también llegan a esta bandeja vía `demo_request_id`), `fine_calculations` (rango en UIT y soles con `parameters_snapshot_json`: UIT, tabla y descuento vigentes con su `valid_from` y `to_confirm`), `assessment_questionnaires` (uno publicado a la vez, `to_validate`, umbrales de nivel configurables; opción `noversion`: `version` es de negocio), `assessment_questions` (fijas al publicarse: trigger `trg_assessment_questions_lock`), `assessment_submissions`, `resource_items` (publicado exige archivo), `resource_downloads`, `success_stories` (aprobado/publicado exige `consent_file_id` o `is_example`), `help_categories`, `help_articles` (`screen_code` para la ayuda contextual, búsqueda de texto completo en español), `help_article_feedback`, `partner_applications` (una pendiente por lead), `referral_codes` (`code` único, solo para partner aprobado: `trg_referral_codes_guard`; recompensa `reward_rule_json` sin montos), `referrals` (un referido por lead o cuenta), `newsletter_subscribers` (correo único; `confirm_token_hash` y `unsubscribe_token_hash` = sha256, nunca el token; tema `status` para los avisos de /estado), `newsletter_issues` (enviada no se edita salvo métricas: `trg_newsletter_issues_lock`), `subprocessors` (país ISO, finalidad, datos, base de transferencia, `to_validate`), `status_components`, `status_incidents` (sale en /estado solo con `published_at`), `status_incident_updates`.
- **Calculadora sin cifras inventadas.** `web.uit_value` (referencial 2026), `web.fine_table_micro|small|non_mype` (JSON `{unit, rows: [{workers_from, workers_to, minor|serious|very_serious: {min, max}}]}`, sembradas **vacías**), `web.fine_correction_discount_pct` (`por_definir`) y `web.lead_retention_months`, todos `to_confirm`. Mientras falte la UIT o la celda aplicable, `public_calculate_fine` responde `parameters_pending` y no guarda nada.
- **Acciones del panel** (corren como `escudo_platform`, no las ejecuta `escudo_app`): `web_publish_questionnaire`, `web_success_story_action` (approve/publish/hide), `web_publish_help_article`, `web_review_partner` (aprobar crea el código), `web_register_referral`, `web_send_newsletter_issue` / `web_newsletter_issue_sent`, `web_publish_incident`, `web_add_incident_update`.
- **v1.5** (`spec/brief-web-v15.md`, 15 tablas más): `site_media` (B2, `alt_text` obligatorio en imágenes; reemplazar = cambiar `file_id` de la misma fila; `web_media_usages()` dice dónde se usa), `site_analytics_daily` (UNIQUE día, ruta, fuente, dispositivo; lo llena `web_rollup_site_analytics(day)`), `site_events` (inalterable, particionada por mes; solo con consentimiento analítico; `session_hash` rotado a diario; `source` y `device` los deduce la API —columnas agregadas al brief para poder agregar—), `demo_booking_settings` y `chatbot_settings` (fila única), `demo_hosts` (calendario OAuth solo como `calendar_secret_ref`), `demo_availability_rules`, `demo_blackouts` (host NULL = todos), `demo_meetings` (`manage_token_hash` sha256; **dos reuniones confirmadas o reprogramadas del mismo anfitrión no se cruzan**: trigger `trg_demo_meetings_no_overlap` con candado por anfitrión y `tstzrange &&`, en lugar de `EXCLUDE USING gist`, que exigiría la extensión `btree_gist`), `chatbot_intents`, `chatbot_conversations`, `chatbot_messages` (inalterable, particionada por mes; `occurred_at` agregado como clave de partición), `crm_connections` (`entities_json` ⊆ lead, demo, reunión, partner, suscriptor: el Libro de Reclamaciones no es sincronizable), `crm_sync_events` (`idempotency_key` UNIQUE), `success_story_media`; `success_stories` suma `project_year` y `modules_json`; `site_settings` suma `content_blocked_terms`, `comparison_blocked_terms` (`web_publish_page()` rechaza publicar con esos términos), `assessment_level_thresholds`, `newsletter_confirm_token_validity` (horas; la confirmación vence) y `status_history_days` (historial de /estado).
- **Cola del CRM.** `trg_crm_enqueue` (AFTER INSERT/UPDATE en `marketing_leads`, `demo_requests`, `demo_meetings`, `partner_applications`, `newsletter_subscribers`) encola un envío por cada conexión **activa** que sincroniza la entidad, con clave `conexión:entidad:id:acción[:versión]` y `ON CONFLICT DO NOTHING` (varias actualizaciones en la misma versión = un envío). `consumer_complaints` no tiene el trigger. El worker registra el resultado con `web_crm_event_result()` (reintento exponencial) y el panel reintenta con `web_crm_retry_event()`.
- **v1.5-b** (10 tablas más): `campaigns` (landing `site_pages.page_type = campaign`, `noindex` por defecto, solo por `public_campaign_page` dentro de la vigencia), `site_popups`, `site_integrations`, `product_updates`, `webinars` (grabación pública exige `recording_informed` —columna agregada— y el medio), `webinar_registrations` (UNIQUE webinar + lead; `manage_token_hash`), `review_sources` y `review_snapshots` (caché con `expires_at` = mínimo entre `cache_ttl_hours` y `web.review_cache_max_hours`; se reemplaza completa con `web_store_review_snapshots()`, no se edita ni se elige a mano), `savings_calculator_settings` (fila única; supuestos sembrados vacíos) y `savings_calculations` (copia de supuestos, idempotente). `marketing_leads.source` suma `savings_calculator`, `webinar`, `campaign`, `popup`.
- **Acciones del panel v1.5** (solo `escudo_platform`): `web_demo_meeting_assign|confirm|complete`, `web_site_metrics(from, to)`, `web_rollup_site_analytics`, `web_media_usages`, `web_publish_chatbot_intent`, `web_crm_event_result`, `web_crm_retry_event`, `web_crm_connection_tested`, `web_campaign_action`, `web_popup_action`, `web_publish_product_update`, `web_webinar_action`, `web_store_review_snapshots`; `web_drop_expired_partitions(tabla, meses)` solo para `escudo_owner` (mantenimiento).

### 3.9 Otras reglas en base de datos (selección)

| Regla | Dónde |
|---|---|
| RUC peruano (11 dígitos, prefijo 10/15/17/20), único en la plataforma | `companies`, `contractors` |
| Aprobador ≠ elaborador | `document_versions`, `sst_policies`, `iperc_versions`, `procedure_versions` |
| Verificador del cierre ≠ responsable; estado `verified` exige verificador y fecha | `corrective_actions` |
| Una sola versión vigente | índices únicos parciales `WHERE status = 'current'` |
| Una aptitud vigente por trabajador; «apto con restricciones» exige restricción laboral | `fitness_status` |
| PETAR: vigencia válida, suspensión con motivo, cierre con área segura; cada aprobación decidida tiene aprobador y fecha | `work_permits`, `permit_approvals` |
| Reporte anónimo sin datos del reportante; reporte por QR con punto | `substandard_reports` |
| Link compartido: vistas ≤ máximo, revocación con fecha | `dossier_share_links` |
| Trabajador propio XOR trabajador de contratista | asistencias, credenciales, SCTR |
| Exactamente un participante (trabajador, contratista o externo) | `training_certificates`, `course_enrollments`; exactamente un destinatario en `course_recommendations` |
| Cálculos generados | `iperc_rows.risk_score = probability_value × severity_value`, `residual_score`, `absences.days`, `environmental_aspects.significance_score`, `workers.full_name` |

## 4. Separación de la base médica

| Dato | Base principal | Base médica |
|---|---|---|
| Programación del EMO, clínica, asistencia, estado del flujo | `medical_exam_orders` | — |
| Aptitud (apto / con restricciones / no apto / pendiente + vigencia + restricción laboral) | `fitness_status` | copia de origen en `medical_exams.fitness_result` |
| Resultados, diagnósticos, recomendaciones, archivos del EMO | — | `medical_exams`, `medical_exam_files` |
| Vigilancia médica y notas clínicas | — | `surveillance_programs`, `surveillance_cases`, `clinical_notes` |
| Fechas, días y tipo general del descanso | `absences` | — |
| CITT, diagnóstico, médico que emite | — | `medical_leave_details` |
| Adecuación del puesto (en términos laborales) | `work_adjustments` | — |
| Condición de vulnerabilidad y su detalle | — | `vulnerability_records` |
| Enfermedad ocupacional en el registro de eventos | `event_people.medical_record_ref` (sin nombre de la enfermedad) | caso/EMO |
| Registro de accesos | `sensitive_access_logs` | `medical_access_logs` |

Reglas:
- Sin FK entre bases: `worker_id`, `company_id` y `*_ref` son UUID que la aplicación valida contra la base principal antes de escribir.
- RLS por `company_id` en las 8 tablas y política **restrictiva** que exige `app.reauth_session_id` en las 7 tablas clínicas: aunque la API fije la empresa, sin re-autenticación vigente no se lee nada.
- `med_log_access(...)` inserta en `medical_access_logs` y rechaza el acceso si falta la re-autenticación. La API registra además en `sensitive_access_logs` (base principal).
- Solo `occupational_doctor` (completo) y `health_staff` (limitado) tienen permisos sobre tablas médicas; el trabajador solo lee sus resultados de EMO con PIN. La semilla de permisos lo verifica.

## 5. Cifrado de campos

Capas: disco LUKS (principal), cifrado en reposo del proveedor (médica), TLS `verify-full` hacia la base médica, SSE-B2 en archivos, respaldos cifrados con llave propia. Además, la aplicación cifra columnas con Laravel encrypted casts (AES-256-GCM). En la base estas columnas son `text`/`jsonb` con el texto cifrado y `COMMENT ON COLUMN` empieza con `[CIFRADO EN APLICACIÓN]`.

| Base | Llave | Columnas cifradas en la aplicación |
|---|---|---|
| Principal | llave de campos de la base principal | `users.phone_e164`, `users.document_number`, `users.mfa_secret`, `users.mfa_recovery_codes`; `workers.document_number`, `phone_e164`, `personal_email`, `address`, `emergency_contact_name`, `emergency_contact_phone`; `contractor_workers.document_number`, `phone_e164`; `contractors.contact_email`, `contact_phone`; `companies.legal_rep_document`; `arco_requests.requester_document`, `requester_contact`; `substandard_reports.reporter_contact`; `dossier_share_links.recipient_email`; `devices.push_token`; `webhooks.secret` |
| Médica | **llave médica** (distinta) | `medical_exams.results_summary`, `diagnoses`, `recommendations`; `medical_exam_files.original_name`; `surveillance_cases.reason`, `closure_reason`; `clinical_notes.subjective`, `objective`, `assessment`, `plan`, `diagnoses`; `medical_leave_details.citt_number`, `issuing_doctor_name`, `diagnosis_code`, `diagnosis_description`; `vulnerability_records.details`, `conadis_registration`, `risk_assessment` |

Búsqueda sobre campos cifrados: `document_number_hash` (HMAC-SHA-256 con llave propia) permite búsqueda exacta y unicidad por empresa; `document_last4` permite mostrar enmascarado. No se cifra lo que debe filtrarse u ordenarse (nombres, fechas, estados).

Secretos de terceros: `lms_connections.credentials_secret_ref` y `api_clients.secret_hash` no guardan el secreto en claro (referencia al gestor de secretos o hash). Contraseñas, PIN y clave de re-autenticación: Argon2id (`*_hash`).

## 6. Retención y borrado

| Dato | Regla | Soporte en el modelo |
|---|---|---|
| Registros obligatorios SST | Plazos por tipo de registro, parámetros `records_retention_*` (to_confirm) | `document_categories.retention_param_key`, `files.retention_until` |
| Historia clínica ocupacional | Parámetro `medical_records_retention_years` (to_confirm) | `medical_exam_files.retention_until` en la base médica |
| Bitácoras inalterables | Parámetro de plataforma; se eliminan particiones completas vencidas (`DROP` de partición mensual de `audit_logs`, operación de mantenimiento fuera del rol de aplicación) | particiones mensuales |
| Datos operativos | Borrado lógico (`deleted_at`); los índices únicos ignoran filas borradas | todas las tablas no inalterables |
| Bajar de plan | Nada se borra; módulos en `read_only` | `feature_flags.source = 'downgrade'` |
| Empresa cancelada | Solo lectura y exportación durante la retención; luego borrado físico por job | `companies.data_retention_until`, `accounts.data_retention_until` |
| Derechos ARCO | Cancelación = anonimización o bloqueo, salvo datos con obligación legal de conservación | `arco_requests` (plazo con `legal_parameter_key`), `worker_consents` |
| Archivos | El job de borrado elimina el objeto en B2 y marca `files.status = 'deleted'` | `files` |
| Libro de Reclamaciones (WEB) | No se elimina; se conserva al menos `web.complaint_retention_years` (to_confirm) | `consumer_complaints.retain_until`, `complaint_events` inalterable |
| Aceptaciones y consentimientos de cookies (WEB) | Inalterables; evidencia de qué versión aceptó quién | `legal_acceptances`, `cookie_consents` |
| Eventos analíticos y mensajes del chatbot (WEB v1.5) | Inalterables fila a fila; se descartan particiones mensuales vencidas según `web.analytics_event_retention_months` y `web.chatbot_retention_months` (to_confirm) | `site_events`, `chatbot_messages` particionadas; `web_drop_expired_partitions()` (escudo_owner) |
| Reseñas de Google (WEB v1.5-b) | Caché con expiración según el proveedor (`web.review_cache_max_hours`); vencidas no se muestran y se reemplazan | `review_snapshots.expires_at` |

El borrado físico lo hace un job con un rol de mantenimiento (no `escudo_app`), registra en `audit_logs` qué se borró sin el contenido y nunca toca tablas inalterables fila por fila.

## 7. Tablas agregadas (fuera del catálogo)

| Tabla | Base | Módulo | Motivo |
|---|---|---|---|
| `modules` | principal | SUS | Catálogo de los 61 módulos; FK de `plan_modules`, `addons`, `feature_flags` y `permissions` |
| `support_access_grants` | principal | USR | Autorización temporal del administrador de cuenta para soporte (`includes_medical` siempre falso) |
| `worker_consents` | principal | TRB | Consentimiento o toma de conocimiento (Ley 29733), con versión del texto |
| `arco_requests` | principal | TRB | Solicitudes ARCO con plazo por parámetro |
| `files` | principal | DOC | Metadatos de archivos en B2 |
| `legal_parameters` | principal | MRL | Parámetros legales con vigencia y `to_confirm` (global + override por empresa) |
| `holidays` | principal | MRL | Feriados para plazos en días hábiles |
| `medical_access_logs` | médica | AUD | Registro inalterable de accesos dentro de la base médica |

## 8. Anexo: tablas por módulo

`[cifrado]` = cifrado en la aplicación. Las columnas estándar (`id`, `company_id`, auditoría, borrado lógico y, en tablas offline, `idempotency_key`, `device_id`, `client_created_at`, `sync_batch_id`, `version`) se omiten. Columnas completas, tipos y restricciones en los `.sql` o en `database/catalogs/model.json`.

<!-- MODULOS:INICIO -->
<!-- Generado por tools/db/generar_doc.py desde database/catalogs/model.json; no editar a mano. -->

### Grupo 1 · Núcleo de la plataforma

#### TEN · 1.1 Empresas, sedes y áreas (plan Esencial, fase 0)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `accounts` | principal | especial | Cuenta suscriptora (empresa directa, grupo o consultora); agrupa empresas y paga suscripciones | `name`, `account_type` (direct/consultancy/group), `tax_id_type` (RUC/DNI/CE/OTHER), `tax_id`, `legal_name`, `billing_email`, `billing_phone`, `billing_address`, `country_code`, `status` (active/suspended/cancelled/archived), `white_label_enabled`, `trial_used_at`, `cancelled_at`, `data_retention_until`, `notes` |
| `companies` | principal | especial | Empresa empleadora con RUC; unidad de aislamiento (company_id = companies.id) | `account_id`, `managed_by_account_id`, `ruc`, `legal_name`, `trade_name`, `ciiu_code`, `economic_sector`, `risk_level` (low/medium/high/very_high), `headcount_declared`, `headcount_active`, `sst_model` (committee/supervisor), `fiscal_address`, `ubigeo`, `district`, `province`, `department`, … |
| `sites` | principal | empresa | Sede, planta, obra, tienda o almacén de la empresa | `code`, `name`, `site_type` (plant/office/project/store/warehouse/mine/field/clinic/other), `address`, `ubigeo`, `district`, `province`, `department`, `latitude`, `longitude`, `is_main`, `is_temporary`, `starts_on`, `ends_on`, `headcount_estimated`, `status` (active/inactive/closed) |
| `areas` | principal | empresa | Área o línea dentro de una sede (árbol jerárquico) | `site_id`, `parent_area_id`, `code`, `name`, `manager_worker_id`, `depth`, `status` (active/inactive) |
| `positions` | principal | empresa | Puesto de trabajo: unidad para IPERC, EPP, EMO y capacitaciones | `area_id`, `code`, `name`, `description`, `functions`, `occupational_group`, `is_critical`, `requires_emo`, `emo_protocol_code`, `emo_periodicity_param`, `status` (active/inactive) |
| `work_shifts` | principal | empresa | Turnos de trabajo para horas-hombre y programación | `site_id`, `code`, `name`, `start_time`, `end_time`, `hours_per_day`, `days_pattern`, `is_night`, `status` (active/inactive) |

Reglas en base de datos: `headcount_declared IS NULL OR headcount_declared >= 0`; `catalog_slug IS NULL OR catalog_slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'`; `NOT is_temporary OR starts_on IS NOT NULL`; `ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on`; `latitude IS NULL OR latitude BETWEEN -90 AND 90`; `longitude IS NULL OR longitude BETWEEN -180 AND 180`.

#### USR · 1.2 Usuarios, roles y permisos (plan Esencial, fase 0)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `users` | principal | especial | Identidad global de una persona; puede tener membresías en varias empresas | `email`, `email_verified_at`, `phone_e164` [cifrado], `full_name`, `password_hash`, `document_type` (DNI/CE/PTP/PASSPORT/OTHER), `document_number` [cifrado], `document_number_hash`, `locale`, `timezone`, `status` (invited/active/locked/disabled), `last_login_at`, `failed_login_count`, `locked_until`, `mfa_enabled`, `mfa_secret` [cifrado], … |
| `roles` | principal | especial | Roles: 17 del sistema (account_id NULL) y personalizados por cuenta | `account_id`, `code`, `name`, `description`, `base_role_code`, `scope_level` (platform/account/company/scoped/own), `is_system`, `is_medical`, `requires_mfa`, `is_temporary` |
| `permissions` | principal | catálogo global | Catálogo de permisos module.resource.action | `code`, `module_code`, `resource`, `action`, `is_sensitive`, `is_special`, `description` |
| `role_permissions` | principal | especial | Permisos asignados a un rol (account_id NULL en roles del sistema) | `account_id`, `role_id`, `permission_id` |
| `memberships` | principal | empresa | Pertenencia usuario–empresa con un rol; temporal para auditor externo y soporte | `user_id`, `role_id`, `worker_id`, `status` (invited/active/suspended/expired/revoked), `invited_by`, `invited_at`, `accepted_at`, `valid_from`, `expires_at`, `is_support_session`, `support_access_grant_id`, `audit_period_from`, `audit_period_to`, `allowed_modules`, `last_access_at` |
| `membership_scopes` | principal | empresa | Limita una membresía a sedes, áreas, módulos o una contratista | `membership_id`, `scope_type` (site/area/module/contractor), `site_id`, `area_id`, `contractor_id`, `module_code` |
| `sessions_mfa` | principal | especial | Sesiones, factores MFA y re-autenticaciones (token médico de corta vida) | `user_id`, `token_hash`, `device_label`, `ip`, `user_agent`, `mfa_method` (totp/email_otp/recovery_code/pin/none), `mfa_verified_at`, `reauth_at`, `reauth_expires_at`, `reauth_purpose` (medical/sensitive_export/signature/security_change), `last_seen_at`, `expires_at`, `revoked_at`, `revoked_reason` |
| `support_access_grants` | principal | empresa · agregada | Autorización temporal del administrador de cuenta para que soporte de plataforma acceda a la empresa | `support_user_id`, `requested_by`, `authorized_by`, `reason`, `ticket_reference`, `access_level` (read_only/read_write), `includes_medical`, `status` (requested/authorized/rejected/active/expired/revoked), `requested_at`, `authorized_at`, `starts_at`, `expires_at`, `revoked_at`, `revoked_by`, `membership_id` |

Reglas en base de datos: `NOT is_support_session OR (expires_at IS NOT NULL AND support_access_grant_id IS NOT NULL)`; `audit_period_to IS NULL OR audit_period_from IS NULL OR audit_period_to >= audit_period_from`; `(scope_type = 'site' AND site_id IS NOT NULL) OR (scope_type = 'area' AND area_id IS NOT NULL) OR (scope_type = 'module' AND module_code IS NOT NULL) OR (scope_type = 'contractor' AND contractor_id IS NOT NULL)`; `reauth_expires_at IS NULL OR reauth_at IS NOT NULL`; `starts_at IS NULL OR expires_at > starts_at`; `status NOT IN ('authorized','active') OR authorized_by IS NOT NULL`.

#### TRB · 1.3 Trabajadores y legajo (plan Esencial, fase 0)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `workers` | principal | empresa | Ficha única del trabajador (legajo SST) | `user_id`, `document_type` (DNI/CE/PTP/PASSPORT/OTHER), `document_number` [cifrado], `document_number_hash`, `document_last4`, `first_name`, `paternal_last_name`, `maternal_last_name`, `full_name`, `birth_on`, `sex` (M/F/X), `nationality`, `phone_e164` [cifrado], `personal_email` [cifrado], `address` [cifrado], `ubigeo`, … |
| `worker_assignments` | principal | empresa | Historial de asignación del trabajador a sede, área, puesto y turno | `worker_id`, `site_id`, `area_id`, `position_id`, `work_shift_id`, `starts_on`, `ends_on`, `is_primary`, `change_reason` |
| `worker_documents` | principal | empresa | Documentos del legajo SST del trabajador con vigencia | `worker_id`, `document_kind` (id_document/contract/data_consent/delivery_receipt/induction_record/license/certificate/sctr_certificate/other), `title`, `file_id`, `issued_on`, `expires_on`, `source_module`, `source_id`, `status` (valid/expiring/expired/replaced), `notes` |
| `worker_consents` | principal | empresa · agregada | Consentimiento o toma de conocimiento del trabajador (Ley 29733), incluido datos de salud | `worker_id`, `consent_type` (data_processing/health_data/image_use/whatsapp/cross_border_transfer/other), `text_version`, `text_document_version_id`, `text_sha256`, `granted`, `granted_at`, `method` (app_signature/paper_scanned/in_person_assisted/web), `signature_id`, `file_id`, `ip`, `revoked_at`, `revocation_reason` |
| `arco_requests` | principal | empresa · agregada | Solicitudes de derechos ARCO (Ley 29733) de trabajadores, con plazo según parámetro | `worker_id`, `requester_name`, `requester_document` [cifrado], `requester_contact` [cifrado], `request_type` (access/rectification/cancellation/opposition/portability), `channel` (app/email/in_person/letter/other), `received_at`, `description`, `legal_parameter_key`, `due_on`, `status` (received/in_review/extension_requested/resolved/rejected/withdrawn), `assigned_to`, `extension_reason`, `resolution`, `resolved_at`, `response_file_id` |
| `bulk_imports` | principal | empresa | Carga masiva desde Excel (trabajadores y otros) | `file_id`, `import_type` (workers/assignments/trainings/man_hours/ppe_deliveries/questions/external_learners/position_training_requirements/other), `template_version`, `mode` (create/upsert/terminate), `status` (uploaded/validating/validated/validated_with_errors/importing/completed/failed), `total_rows`, `ok_rows`, `error_rows`, `skipped_rows`, `options`, `started_by`, `started_at`, `finished_at`, `error_report_file_id`, `error_message` |
| `bulk_import_rows` | principal | empresa | Resultado de validación e importación por fila | `bulk_import_id`, `row_number`, `payload`, `status` (pending/ok/error/skipped), `errors`, `worker_id` |

Reglas en base de datos: `termination_on IS NULL OR hire_on IS NULL OR termination_on >= hire_on`; `status <> 'terminated' OR termination_on IS NOT NULL`; `ends_on IS NULL OR ends_on >= starts_on`; `revoked_at IS NULL OR revoked_at >= granted_at`; `status NOT IN ('resolved','rejected') OR resolved_at IS NOT NULL`.

#### DOC · 1.4 Gestión documental (plan Esencial, fase 0)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `files` | principal | empresa · agregada, offline | Metadatos de archivos guardados en Backblaze B2 (nunca binarios en la base) | `bucket`, `object_key`, `original_name`, `mime_type`, `size_bytes`, `sha256`, `module_code`, `entity_type`, `entity_id`, `status` (pending_upload/uploaded/scanning/available/quarantined/deleted), `scanned_at`, `scan_result`, `exif_stripped`, `latitude`, `longitude`, `captured_at`, … |
| `document_categories` | principal | mixta (global + empresa) | Tipología documental con reglas por defecto (global o propia de la empresa) | `parent_id`, `code`, `name`, `requires_approval_by` (sst/committee/management/none), `default_validity_months`, `review_period_months`, `retention_param_key`, `requires_ack`, `is_mandatory_register`, `sort_order` |
| `documents` | principal | empresa | Documento lógico versionado | `category_id`, `code`, `title`, `description`, `owner_user_id`, `scope_type` (company/site/area/position), `site_id`, `area_id`, `position_id`, `current_version_id`, `status` (draft/active/obsolete/archived), `next_review_on`, `is_public_to_workers`, `tags` |
| `document_versions` | principal | empresa | Versión de un documento con su máquina de estados (borrador → vigente → obsoleto) | `document_id`, `version_number`, `version_label`, `file_id`, `content_html`, `sha256`, `change_summary`, `status` (draft/in_review/approved/current/obsolete), `submitted_by`, `submitted_at`, `reviewed_by`, `review_comments`, `approved_by`, `approved_at`, `effective_on`, `effective_until`, … |
| `document_acknowledgements` | principal | empresa · inalterable · offline | Cargo de entrega o lectura de una versión documental (inalterable) | `document_version_id`, `worker_id`, `user_id`, `method` (app_signature/scanned_paper/qr/in_person_assisted), `signature_id`, `acknowledged_at`, `ip`, `user_agent` |

Reglas en base de datos: `size_bytes >= 0`; `approved_by IS NULL OR submitted_by IS NULL OR approved_by <> submitted_by`; `status NOT IN ('approved','current') OR approved_at IS NOT NULL`.

#### NOT · 1.5 Alertas y notificaciones (plan Esencial, fase 0)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `notification_rules` | principal | mixta (global + empresa) | Regla de alerta por evento y anticipación (global o de la empresa) | `event_code`, `module_code`, `name`, `recipients_spec`, `channels`, `lead_days`, `repeat_every_days`, `max_repeats`, `quiet_hours_start`, `quiet_hours_end`, `template_code`, `priority` (low/normal/high/critical), `is_active` |
| `notifications` | principal | empresa | Notificación generada para un usuario o trabajador (sin datos sensibles en el cuerpo) | `rule_id`, `event_code`, `subject_type`, `subject_id`, `recipient_user_id`, `recipient_worker_id`, `title`, `body`, `link`, `priority` (low/normal/high/critical), `scheduled_for`, `due_at`, `read_at`, `dismissed_at`, `dedupe_key` |
| `notification_deliveries` | principal | empresa · inalterable | Intento de entrega por canal (inalterable; cada cambio de estado es una fila nueva) | `notification_id`, `channel` (email/whatsapp/in_app/push/sms), `provider`, `provider_message_id`, `status` (queued/sent/delivered/read/failed/bounced/suppressed), `attempt`, `error_code`, `error_message`, `attempted_at` |
| `user_notification_prefs` | principal | empresa | Preferencias de notificación del usuario por canal y categoría | `user_id`, `channel` (email/whatsapp/in_app/push/sms), `event_category`, `enabled`, `digest_mode` (immediate/daily/weekly) |

Reglas en base de datos: `recipient_user_id IS NOT NULL OR recipient_worker_id IS NOT NULL`.

#### DSH · 1.6 Dashboard e indicadores (plan Esencial, fase 0)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `kpi_definitions` | principal | mixta (global + empresa) | Indicador declarativo (global o propio) | `code`, `name`, `description`, `module_code`, `formula_key`, `unit`, `direction` (higher_better/lower_better/target), `green_threshold`, `amber_threshold`, `granularity` (daily/monthly/quarterly/yearly), `required_feature`, `legal_parameter_key`, `is_active` |
| `kpi_snapshots` | principal | empresa | Valor calculado de un indicador por periodo y ámbito | `kpi_definition_id`, `period_start`, `period_end`, `site_id`, `area_id`, `value`, `numerator`, `denominator`, `status_color` (green/amber/red/grey), `details`, `computed_at` |
| `dashboards` | principal | empresa | Tablero por rol o personalizado | `name`, `audience_role`, `owner_user_id`, `is_default`, `layout` |
| `dashboard_widgets` | principal | empresa | Widget de un tablero | `dashboard_id`, `kpi_definition_id`, `widget_type` (card/line/bar/traffic_light/list/table/gauge), `title`, `filters`, `position`, `width`, `height` |

Reglas en base de datos: `period_end >= period_start`.

#### FIR · 1.7 Firma electrónica de registros (plan Profesional, fase 0)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `signature_requests` | principal | empresa | Solicitud de firma sobre un registro (hash del documento a firmar) | `subject_type`, `subject_id`, `subject_module`, `document_hash`, `signer_user_id`, `signer_worker_id`, `role_in_document` (attendee/responsible/approver/reviewer/worker/witness/committee_member/doctor), `methods_allowed`, `sequence_order`, `status` (pending/signed/declined/expired/cancelled), `expires_at`, `requested_by`, `reminder_count`, `declined_reason`, `signed_at` |
| `signatures` | principal | empresa · inalterable · offline | Evidencia de firma electrónica (inalterable) | `signature_request_id`, `subject_type`, `subject_id`, `signer_user_id`, `signer_worker_id`, `signer_name`, `signer_document_masked`, `method` (stroke/pin/otp/assisted_in_person/scanned), `stroke_file_id`, `document_hash`, `evidence_hash`, `evidence`, `otp_channel` (email/whatsapp/sms), `ip`, `user_agent`, `geo_lat`, … |

Reglas en base de datos: `signer_user_id IS NOT NULL OR signer_worker_id IS NOT NULL`; `signer_user_id IS NOT NULL OR signer_worker_id IS NOT NULL`.

#### AUD · 1.8 Bitácora de auditoría (plan Esencial, fase 0)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `audit_logs` | principal | especial · inalterable | Bitácora de auditoría encadenada por hash por empresa (inalterable); company_id NULL = evento de plataforma | `company_id`, `chain_seq`, `occurred_at`, `actor_user_id`, `actor_role`, `actor_type` (user/system/api_client/public/support), `is_support_session`, `action`, `module_code`, `entity_type`, `entity_id`, `changes`, `reason`, `ip`, `user_agent`, `request_id`, … |
| `sensitive_access_logs` | principal | empresa · inalterable | Registro de accesos a datos sensibles y médicos (inalterable) | `user_id`, `role_code`, `worker_id`, `resource` (medical_exam/medical_file/clinical_note/leave_detail/vulnerability_record/surveillance_case/medical_export/worker_sensitive_data), `resource_id`, `action` (read/download/export/create/update/print), `purpose`, `reauth_session_id`, `result` (granted/denied), `deny_reason`, `ip`, `user_agent`, `occurred_at` |
| `medical_access_logs` | **médica** | empresa · inalterable · agregada | Registro de accesos de la propia base médica (inalterable), espejo de sensitive_access_logs | `user_id`, `role_code`, `reauth_session_id`, `worker_id`, `resource` (medical_exam/medical_file/clinical_note/leave_detail/vulnerability_record/surveillance_case/surveillance_program/medical_export), `resource_id`, `action` (read/download/export/create/update/print), `purpose`, `ip`, `occurred_at` |

#### SUS · 1.9 Suscripciones y facturación (plan Interno, fase 0)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `modules` | principal | catálogo global · agregada | Catálogo de los 60 módulos (código, grupo, plan, fase) para planes, permisos y feature flags | `code`, `number`, `name`, `group_number`, `plan_code` (E/P/C/+/K/I), `phase`, `origin`, `solves`, `is_addon` |
| `plans` | principal | catálogo global | Planes comerciales (precio nulo = «Por definir») | `code` (E/P/C/K/I), `name`, `description`, `sort_order`, `includes_plan_codes`, `max_companies`, `max_workers`, `max_sites`, `max_management_users`, `storage_gb`, `ai_requests_month`, `qr_report_points`, `price_reference`, `currency`, `billing_periods`, `is_public`, … |
| `plan_modules` | principal | catálogo global | Módulos incluidos en cada plan | `plan_id`, `module_code`, `inclusion` (native/inherited) |
| `addons` | principal | catálogo global | Complementos contratables con cualquier plan | `code`, `name`, `description`, `module_code`, `is_per_sector`, `available_in_v1`, `price_reference`, `currency`, `is_active` |
| `subscriptions` | principal | cuenta | Suscripción de una empresa (o de la cuenta consultora, plan K) a un plan | `account_id`, `plan_id`, `payer` (company_account/consultancy), `payer_account_id`, `status` (trialing/active/past_due/read_only/pending_cancellation/cancelled/trial_expired), `billing_period` (monthly/annual), `started_at`, `trial_ends_at`, `current_period_start`, `current_period_end`, `grace_until`, `cancel_at`, `cancelled_at`, `cancel_reason`, `managed_companies`, `external_ref` |
| `subscription_addons` | principal | cuenta | Complementos de una suscripción (PSE por paquete sectorial) | `account_id`, `subscription_id`, `addon_id`, `sector_pack_id`, `quantity`, `status` (pending/active/ended), `started_at`, `ended_at` |
| `invoices` | principal | cuenta | Comprobantes electrónicos emitidos al suscriptor | `account_id`, `subscription_id`, `document_type` (invoice/receipt/credit_note/debit_note), `series`, `number`, `issue_on`, `due_on`, `customer_tax_id`, `customer_name`, `currency`, `subtotal`, `tax`, `total`, `lines`, `status` (draft/issued/overdue/paid/voided/refunded), `sunat_status` (pending/accepted/observed/rejected/not_applicable), … |
| `payments` | principal | cuenta | Pagos recibidos | `account_id`, `invoice_id`, `method` (card/transfer/deposit/yape/plin/other), `gateway`, `reference`, `amount`, `currency`, `paid_at`, `status` (pending/confirmed/failed/refunded), `gateway_payload` |
| `feature_flags` | principal | empresa | Habilitación efectiva por empresa y módulo (plan + complementos + excepciones) | `module_code`, `mode` (enabled/read_only/locked/disabled_by_company), `source` (plan/addon/override/trial/downgrade/core/company_setting), `reason`, `valid_from`, `valid_until`, `set_by`, `company_enabled`, `toggled_by`, `toggled_at` |

Reglas en base de datos: `company_enabled OR module_code IN ('AUL', 'VTA')`; `mode <> 'disabled_by_company' OR NOT company_enabled`.

#### WEB · 1.10 Sitio web público y CMS (plan Interno, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `site_settings` | principal | plataforma (interna, sin acceso de la app) | Configuración única del sitio público: titular y datos legales editables, contacto, responsable del Libro de Reclamaciones, redes, analítica y marca | `singleton`, `legal_name`, `trade_name`, `ruc`, `legal_address`, `primary_domain`, `contact_email`, `sales_email`, `support_email`, `privacy_email`, `contact_phone`, `whatsapp_number`, `whatsapp_default_message`, `blog_url`, `show_latest_blog_posts`, `latest_blog_posts_count`, … |
| `site_pages` | principal | plataforma (interna, sin acceso de la app) | Página del sitio por bloques (portada, producto, sector, planes, nosotros, contacto, landing, legal) con SEO; borrador → publicada → archivada | `slug`, `page_type`, `title`, `summary`, `blocks`, `status` (draft/published/archived), `published_snapshot`, `published_at`, `published_by`, `archived_at`, `meta_title`, `meta_description`, `canonical_url`, `og_image_file_id`, `noindex`, `structured_data`, … |
| `site_menus` | principal | plataforma (interna, sin acceso de la app) | Menú del sitio por ubicación (cabecera, pie, pie legal, móvil) con ítems ordenados | `location` (header/footer/footer_legal/mobile), `name`, `items`, `is_active` |
| `site_products` | principal | plataforma (interna, sin acceso de la app) | Otros productos de GRUPO DE SOLUCIONES CREATIVAS que se muestran en la web (nombre, descripción, logo, enlace) | `name`, `description`, `logo_file_id`, `url`, `sort_order`, `is_visible`, `description_editable` |
| `site_testimonials` | principal | plataforma (interna, sin acceso de la app) | Comentario o testimonio de cliente, con consentimiento de publicación y aprobación (pendiente → aprobado / oculto) | `author_name`, `author_role`, `company_name`, `quote`, `rating`, `photo_file_id`, `publication_consent`, `consent_evidence`, `is_example`, `status` (pending/approved/hidden), `approved_by`, `approved_at`, `sort_order` |
| `site_faqs` | principal | plataforma (interna, sin acceso de la app) | Pregunta frecuente por tema (general o de una página); se publica en FAQPage | `page_id`, `topic` (general/plans/security/data_protection/product/billing/support), `question`, `answer`, `sort_order`, `status` (draft/published/archived) |
| `site_redirects` | principal | plataforma (interna, sin acceso de la app) | Redirección del sitio: 301 permanente o 302 temporal | `source_path`, `target_url`, `status_code`, `is_active`, `hit_count`, `last_hit_at`, `notes` |
| `legal_documents` | principal | plataforma (interna, sin acceso de la app) | Documento legal del sitio (términos, privacidad, cookies, encargo de tratamiento, SLA, información del Libro de Reclamaciones) y su versión vigente | `doc_type` (terms/privacy/cookies/dpa/sla/complaints_info), `title`, `slug`, `current_version_id`, `requires_acceptance_on_signup`, `applies_to` (all/customers/visitors), `is_active` |
| `legal_document_versions` | principal | plataforma (interna, sin acceso de la app) | Versión de un documento legal; una vez publicada no se edita (un cambio es otra versión) | `legal_document_id`, `version_number`, `version_label`, `content`, `content_sha256`, `summary_of_changes`, `status` (draft/approved/published/superseded), `effective_from`, `requires_reacceptance`, `legal_reviewed_by`, `approved_by`, `approved_at`, `published_at`, `published_by`, `superseded_at` |
| `legal_acceptances` | principal | plataforma (interna, sin acceso de la app) · inalterable | Aceptación de una versión de documento legal (registro, re-aceptación o checkout); inalterable | `legal_document_version_id`, `legal_document_id`, `doc_type` (terms/privacy/cookies/dpa/sla/complaints_info), `version_label`, `user_id`, `email` [cifrado], `email_hash`, `account_id`, `context` (signup/reacceptance/checkout), `accepted_at`, `ip_hash`, `user_agent` |
| `cookie_consents` | principal | plataforma (interna, sin acceso de la app) · inalterable | Preferencias de cookies de un visitante anónimo por categoría; cada cambio es una fila nueva (inalterable) | `visitor_id`, `categories`, `action` (accept_all/reject_all/custom/withdraw), `policy_version_id`, `policy_version_label`, `user_id`, `consented_at`, `ip_hash`, `user_agent` |
| `consumer_complaints` | principal | plataforma (interna, sin acceso de la app) · sin borrado | Hoja del Libro de Reclamaciones virtual de EscudoSST (Ley 29571, D.S. 011-2011-PCM); no se elimina; distinto del de las empresas clientes | `complaint_code`, `complaint_year`, `sequence_number`, `filed_at`, `tracking_key_hash`, `provider_legal_name`, `provider_ruc`, `provider_address`, `provider_snapshot`, `consumer_full_name` [cifrado], `consumer_document_type` (DNI/CE/PASSPORT/RUC/OTHER), `consumer_document_number` [cifrado], `consumer_address` [cifrado], `consumer_email` [cifrado], `consumer_phone` [cifrado], `is_minor`, … |
| `complaint_events` | principal | plataforma (interna, sin acceso de la app) · inalterable | Historial inalterable de cada hoja de reclamación (registro, constancia, asignación, prórroga, respuesta, cierre) | `consumer_complaint_id`, `event_type`, `from_status`, `to_status`, `reason`, `details`, `actor_type` (user/consumer/system), `actor_user_id`, `occurred_at` |
| `demo_requests` | principal | plataforma (interna, sin acceso de la app) | Solicitud desde los formularios del sitio («Agenda una demo», contacto, cotización) con estado y responsable comercial | `request_type` (demo/contact/quote), `full_name`, `company_name`, `ruc`, `job_title`, `email`, `phone`, `headcount`, `economic_sector`, `interest_plan_code` (E/P/C/K), `message`, `privacy_consent`, `privacy_version_id`, `marketing_consent`, `source_page`, `utm`, … |
| `marketing_leads` | principal | plataforma (interna, sin acceso de la app) | Lead de los formularios de captación (calculadora, autodiagnóstico, recursos, partners, boletín, demo, contacto) con origen, UTM, consentimiento comercial separado, estado y responsable | `email`, `full_name`, `company_name`, `ruc`, `phone`, `sector`, `workers_range` (1-10/11-20/21-50/51-100/101-250/251-500/501-1000/1000+), `source`, `last_source`, `last_activity_at`, `utm_source`, `utm_medium`, `utm_campaign`, `privacy_consent`, `privacy_version_id`, `marketing_consent`, … |
| `fine_calculations` | principal | plataforma (interna, sin acceso de la app) | Cálculo referencial de multa SUNAFIL hecho en la web, con copia de los parámetros legales vigentes usados (no es asesoría legal) | `lead_id`, `company_size` (micro/small/non_mype), `affected_workers`, `severity` (minor/serious/very_serious), `infraction_code`, `uit_value`, `result_min_uit`, `result_max_uit`, `result_min_pen`, `result_max_pen`, `parameters_snapshot_json`, `source_page`, `ip_hash` |
| `assessment_questionnaires` | principal | plataforma (interna, sin acceso de la app) | Cuestionario del autodiagnóstico gratuito Ley 29783 (versionado; uno publicado a la vez) | `title`, `description`, `status` (draft/published/archived), `to_validate`, `level_thresholds_json`, `published_at`, `published_by`, `archived_at` |
| `assessment_questions` | principal | plataforma (interna, sin acceso de la app) | Pregunta del autodiagnóstico por sección, con peso y referencia legal | `questionnaire_id`, `section`, `question_text`, `help_text`, `weight`, `answer_type` (yes_no/yes_partial_no), `sort_order`, `legal_reference` |
| `assessment_submissions` | principal | plataforma (interna, sin acceso de la app) | Respuesta de un visitante al autodiagnóstico: puntaje total y por sección, nivel e informe PDF | `questionnaire_id`, `lead_id`, `answers_json`, `score_total`, `score_by_section_json`, `level` (low/medium/high), `report_file_id`, `report_sent_at`, `source_page`, `ip_hash` |
| `resource_items` | principal | plataforma (interna, sin acceso de la app) | Recurso descargable de la biblioteca (plantillas y formatos SST); libre o a cambio de datos (gated); archivo en B2 con URL firmada | `title`, `slug`, `description`, `resource_type` (template/guide/checklist/format), `sector`, `file_id`, `cover_file_id`, `is_gated`, `status` (draft/published/archived), `published_at`, `download_count`, `sort_order`, `seo_title`, `seo_description` |
| `resource_downloads` | principal | plataforma (interna, sin acceso de la app) · inalterable | Descarga registrada de un recurso (inalterable) | `resource_id`, `lead_id`, `ip_hash`, `downloaded_at` |
| `success_stories` | principal | plataforma (interna, sin acceso de la app) | Caso de éxito con sector, reto, solución y resultados; solo con consentimiento escrito del cliente (archivo) y aprobación; «Ejemplo» mientras no haya reales | `slug`, `title`, `client_name`, `sector`, `logo_file_id`, `challenge`, `solution`, `results_json`, `quote`, `project_year`, `modules_json`, `consent_file_id`, `is_example`, `status` (draft/approved/published/hidden), `approved_by`, `approved_at`, … |
| `help_categories` | principal | plataforma (interna, sin acceso de la app) | Categoría del centro de ayuda | `name`, `slug`, `icon`, `description`, `sort_order`, `status` (active/hidden) |
| `help_articles` | principal | plataforma (interna, sin acceso de la app) | Artículo del centro de ayuda (texto, video, relacionados) con ayuda contextual por pantalla (screen_code) | `category_id`, `title`, `slug`, `body`, `video_url`, `screen_code`, `related_article_ids`, `status` (draft/published/archived), `published_at`, `published_by`, `helpful_count`, `not_helpful_count`, `sort_order`, `seo_title`, `seo_description` |
| `help_article_feedback` | principal | plataforma (interna, sin acceso de la app) · inalterable | Voto «¿Te sirvió?» de un artículo de ayuda (inalterable) | `article_id`, `was_helpful`, `comment`, `account_id`, `user_id`, `ip_hash` |
| `partner_applications` | principal | plataforma (interna, sin acceso de la app) | Solicitud al programa de partners (consultoras, independientes, institutos) con revisión | `lead_id`, `partner_type` (consultancy/independent/institute), `company_name`, `ruc`, `website`, `message`, `status` (pending/approved/rejected), `reviewed_by`, `reviewed_at`, `rejection_reason`, `notes`, `ip_hash` |
| `referral_codes` | principal | plataforma (interna, sin acceso de la app) | Código de referido de un partner aprobado; la recompensa es una regla configurable (sin montos sembrados) | `partner_application_id`, `code`, `reward_rule_json`, `is_active`, `deactivated_at` |
| `referrals` | principal | plataforma (interna, sin acceso de la app) | Referido de un partner: registrado → en prueba → cliente (o cancelado) | `referral_code_id`, `lead_id`, `account_id`, `status` (registered/trial/customer/cancelled), `converted_at`, `cancelled_reason` |
| `newsletter_subscribers` | principal | plataforma (interna, sin acceso de la app) | Suscriptor del boletín normativo SST y de los avisos del estado del servicio (doble confirmación, baja con un clic; tokens solo como hash) | `email`, `topics_json`, `status` (pending/confirmed/unsubscribed), `confirm_token_hash`, `confirm_sent_at`, `unsubscribe_token_hash`, `confirmed_at`, `unsubscribed_at`, `lead_id`, `consent_ip_hash` |
| `newsletter_issues` | principal | plataforma (interna, sin acceso de la app) | Edición del boletín redactada en el panel y enviada por Elastic Email (proveedor detrás de interfaz) con métricas básicas | `subject`, `slug`, `preheader`, `body`, `topics_json`, `status` (draft/scheduled/sent), `scheduled_at`, `sent_at`, `sent_by`, `provider_campaign_id`, `recipients_count`, `opens_count`, `clicks_count`, `unsubscribes_count` |
| `subprocessors` | principal | plataforma (interna, sin acceso de la app) | Subencargado del tratamiento (Ley 29733): proveedor, finalidad, país y datos tratados; transferencia internacional | `name`, `purpose`, `country`, `data_categories`, `transfer_basis`, `website`, `to_validate`, `sort_order`, `status` (active/inactive) |
| `status_components` | principal | plataforma (interna, sin acceso de la app) | Componente de la página de estado del servicio (app, API, base médica, correo, WhatsApp, pasarela) | `name`, `code`, `description`, `current_status` (operational/degraded/partial_outage/major_outage/maintenance), `sort_order`, `is_visible` |
| `status_incidents` | principal | plataforma (interna, sin acceso de la app) | Incidente o mantenimiento del servicio con componentes afectados; sale en /estado solo al publicarse | `title`, `impact` (minor/major/critical/maintenance), `status` (investigating/identified/monitoring/resolved), `started_at`, `resolved_at`, `component_ids_json`, `published_at`, `published_by` |
| `status_incident_updates` | principal | plataforma (interna, sin acceso de la app) · inalterable | Actualización publicada de un incidente (inalterable) | `incident_id`, `status` (investigating/identified/monitoring/resolved), `message`, `published_at` |
| `site_media` | principal | plataforma (interna, sin acceso de la app) | Biblioteca de medios del sitio (imágenes, videos, documentos, íconos en B2): texto alternativo obligatorio en imágenes; reemplazar el archivo conserva el id (no rompe enlaces) | `file_id`, `kind` (image/video/document/icon), `title`, `alt_text`, `width`, `height`, `size_bytes`, `folder`, `tags_json`, `is_example`, `usage_count` |
| `site_analytics_daily` | principal | plataforma (interna, sin acceso de la app) | Métricas propias agregadas por día, ruta, fuente y dispositivo (solo de visitantes con consentimiento analítico; sin IP ni huellas) | `day`, `path`, `source` (organic/direct/social/referral/campaign/email), `device` (desktop/mobile/tablet), `visits`, `unique_visitors`, `avg_seconds`, `bounces` |
| `site_events` | principal | plataforma (interna, sin acceso de la app) · inalterable | Evento analítico del sitio (vista, CTA, formulario, WhatsApp, demo, calculadoras, chatbot, popups, webinars, reseñas, campaña); solo con consentimiento analítico, sin IP ni datos personales; inalterable, particionado por mes (la conservación se aplica descartando particiones) | `occurred_at`, `event_type`, `path`, `element_key`, `utm_source`, `utm_medium`, `utm_campaign`, `source` (organic/direct/social/referral/campaign/email), `device` (desktop/mobile/tablet), `session_hash`, `lead_id` |
| `demo_booking_settings` | principal | plataforma (interna, sin acceso de la app) | Ajustes únicos de la agenda pública de demos: activación, duración y margen configurables, aviso mínimo, horizonte, proveedor de reunión y recordatorios | `singleton`, `public_booking_enabled`, `duration_minutes`, `buffer_minutes`, `min_notice_hours`, `max_days_ahead`, `default_meeting_provider` (meet/teams/phone), `reminder_24h`, `reminder_1h` |
| `demo_hosts` | principal | plataforma (interna, sin acceso de la app) | Anfitrión de demos (asesor comercial o especialista SST) con zona horaria, calendario conectado por OAuth (secreto como referencia) y tope diario | `user_id`, `display_name`, `email`, `color`, `timezone`, `calendar_provider` (google/microsoft/none), `calendar_secret_ref`, `is_active`, `max_per_day` |
| `demo_availability_rules` | principal | plataforma (interna, sin acceso de la app) | Disponibilidad semanal de un anfitrión (día ISO 1 = lunes … 7 = domingo; horas en su zona horaria) | `host_id`, `weekday`, `start_time`, `end_time` |
| `demo_blackouts` | principal | plataforma (interna, sin acceso de la app) | Bloqueo de agenda (vacaciones, evento interno); host_id NULL = todos los anfitriones. Los feriados nacionales salen de holidays | `host_id`, `starts_at`, `ends_at`, `reason` |
| `demo_meetings` | principal | plataforma (interna, sin acceso de la app) | Reunión de demo agendada (propuesta → confirmada con enlace → completada / no asistió; reprogramar o cancelar con enlace seguro). Dos reuniones confirmadas del mismo anfitrión no se cruzan | `demo_request_id`, `host_id`, `starts_at`, `ends_at`, `status` (proposed/confirmed/rescheduled/cancelled/completed/no_show), `meeting_provider` (meet/teams/phone), `meeting_url`, `calendar_event_ref`, `manage_token_hash`, `confirmed_at`, `reminder_24h_sent_at`, `reminder_1h_sent_at`, `cancel_reason`, `outcome` (interested/not_interested/follow_up/converted), `outcome_notes`, `recording_consent` |
| `chatbot_settings` | principal | plataforma (interna, sin acceso de la app) | Ajustes únicos del chatbot del sitio: activación, modo guiado o IA (solo contenido publicado), saludo, horario, derivación y pedido de datos | `singleton`, `enabled`, `mode` (guided/ai), `greeting`, `avatar_media_id`, `business_hours_json`, `offline_message`, `handoff_whatsapp`, `ask_lead_after_messages`, `ai_provider_secret_ref` |
| `chatbot_intents` | principal | plataforma (interna, sin acceso de la app) | Intención del chatbot guiado: frases disparadoras, respuesta y botón (demo, WhatsApp, calculadora, autodiagnóstico, enlace); borrador → publicada → archivada | `name`, `trigger_phrases_json`, `answer`, `cta_type` (none/demo/whatsapp/calculator/assessment/link), `cta_url`, `faq_id`, `help_article_id`, `sort_order`, `status` (draft/published/archived) |
| `chatbot_conversations` | principal | plataforma (interna, sin acceso de la app) | Conversación del chatbot (sin IP; session_hash rotado a diario); lead solo con consentimiento; conservación por parámetro | `session_hash`, `started_at`, `ended_at`, `path`, `lead_id`, `handoff` (none/whatsapp/form), `rating`, `message_count` |
| `chatbot_messages` | principal | plataforma (interna, sin acceso de la app) · inalterable | Mensaje de una conversación del chatbot (visitante o bot; used_ai si lo redactó la IA con contenido publicado); inalterable, particionado por mes | `conversation_id`, `sender` (visitor/bot), `body`, `intent_id`, `used_ai`, `occurred_at` |
| `crm_connections` | principal | plataforma (interna, sin acceso de la app) | Conexión con el CRM («Kallpa CRM» propio o webhook genérico): URL, secreto como referencia, entidades a sincronizar y mapeo de campos. El Libro de Reclamaciones nunca se envía | `provider` (kallpa/generic_webhook), `name`, `base_url`, `secret_ref`, `status` (active/paused/error), `entities_json`, `field_mapping_json`, `last_sync_at`, `last_error` |
| `crm_sync_events` | principal | plataforma (interna, sin acceso de la app) | Cola de envíos al CRM con reintentos e idempotencia (una fila por entidad, acción y versión) | `connection_id`, `entity_type` (marketing_lead/demo_request/demo_meeting/partner_application/newsletter_subscriber), `entity_id`, `action` (create/update), `status` (pending/sent/failed/skipped), `attempts`, `next_attempt_at`, `external_id`, `response_code`, `last_error` |
| `success_story_media` | principal | plataforma (interna, sin acceso de la app) | Galería del caso de éxito (portafolio): imágenes de la biblioteca de medios con leyenda y orden | `success_story_id`, `media_id`, `caption`, `sort_order` |
| `campaigns` | principal | plataforma (interna, sin acceso de la app) | Campaña de anuncios (Google Ads, Meta Ads, LinkedIn, correo) con landing /l/{slug}, formulario, UTM por defecto y vigencia; métricas por utm_campaign | `name`, `channel` (google_ads/meta_ads/linkedin/email/other), `utm_source`, `utm_medium`, `utm_campaign`, `landing_page_id`, `form_type` (demo/quote/contact/resource/assessment/calculator), `starts_on`, `ends_on`, `status` (draft/active/paused/finished), `goal` |
| `site_popups` | principal | plataforma (interna, sin acceso de la app) | Ventana emergente (intención de salida, % de scroll o tiempo en página) con oferta, páginas, dispositivo y frecuencia por visitante; nunca en Libro de Reclamaciones, políticas ni formularios | `name`, `trigger_type` (exit_intent/scroll_percent/time_on_page), `trigger_value`, `pages_json`, `excluded_pages_json`, `devices` (all/desktop/mobile), `offer_type` (fine_calculator/savings_calculator/self_assessment/resource/demo/newsletter/webinar), `title`, `body`, `cta_label`, `image_media_id`, `frequency_days`, `status` (draft/active/paused), `starts_at`, `ends_at` |
| `site_integrations` | principal | plataforma (interna, sin acceso de la app) | Integración mostrada en /integraciones con estado veraz (disponible, beta, planificada) | `name`, `slug`, `category` (messaging/lms/payments/crm/calendar/email/storage/ai/analytics), `description`, `logo_media_id`, `availability` (available/beta/planned), `docs_url`, `sort_order`, `status` (draft/published/hidden) |
| `product_updates` | principal | plataforma (interna, sin acceso de la app) | Novedad del producto (nuevo, mejora, corrección) con módulos; se publica en /novedades, opcionalmente se avisa a suscriptores y se muestra en la app | `title`, `slug`, `body`, `category` (new/improvement/fix), `modules_json`, `status` (draft/published/archived), `published_at`, `notify_subscribers`, `show_in_app` |
| `webinars` | principal | plataforma (interna, sin acceso de la app) | Webinar (próximo o grabado) con ponente, cupos, enlace de reunión (solo para inscritos) y grabación publicada solo si se informó a ponente y asistentes | `title`, `slug`, `description`, `speaker_name`, `speaker_title`, `starts_at`, `duration_minutes`, `meeting_provider` (meet/teams/zoom/other), `meeting_url`, `capacity`, `status` (draft/published/live/finished/cancelled), `recording_media_id`, `recording_public`, `recording_informed`, `cover_media_id` |
| `webinar_registrations` | principal | plataforma (interna, sin acceso de la app) | Inscripción a un webinar (con consentimiento vía lead), estado, asistencia y recordatorios; token de gestión solo como hash | `webinar_id`, `lead_id`, `status` (registered/cancelled/attended/no_show), `manage_token_hash`, `reminder_24h_sent_at`, `reminder_1h_sent_at`, `registered_at` |
| `review_sources` | principal | plataforma (interna, sin acceso de la app) | Fuente de reseñas reales (perfil de Google Business por la API oficial): filtro solo por calificación mínima y cantidad; caché según el proveedor | `provider` (google), `place_id`, `api_secret_ref`, `min_rating`, `max_to_show`, `cache_ttl_hours`, `last_fetched_at`, `status` (active/paused/error), `last_error` |
| `review_snapshots` | principal | plataforma (interna, sin acceso de la app) | Copia en caché de una reseña tal como la entrega el proveedor (sin editar el texto), con atribución y expiración | `review_source_id`, `author_name`, `author_photo_url`, `rating`, `body`, `review_published_at`, `provider_review_url`, `fetched_at`, `expires_at` |
| `savings_calculator_settings` | principal | plataforma (interna, sin acceso de la app) | Supuestos únicos de la calculadora de ahorro frente a Excel y papel, editables y visibles («estimación referencial, no garantizada») | `singleton`, `assumptions_json`, `disclaimer`, `status` (draft/published), `to_validate` |
| `savings_calculations` | principal | plataforma (interna, sin acceso de la app) | Cálculo de ahorro hecho en la web, con copia de los supuestos usados; resultado por correo a cambio de datos (lead) | `lead_id`, `inputs_json`, `assumptions_snapshot_json`, `result_hours_month`, `result_cost_month` |

Agrupación: **Sitio y CMS** (`site_settings`, `site_pages`, `site_menus`, `site_products`, `site_testimonials`, `site_faqs`, `site_redirects`) · **Políticas, cookies y Libro de Reclamaciones** (`legal_documents`, `legal_document_versions`, `legal_acceptances`, `cookie_consents`, `consumer_complaints`, `complaint_events`) · **Bandeja de leads y demos** (`marketing_leads`, `demo_requests`) · **Calculadora de multas SUNAFIL** (`fine_calculations`) · **Autodiagnóstico Ley 29783** (`assessment_questionnaires`, `assessment_questions`, `assessment_submissions`) · **Biblioteca de recursos** (`resource_items`, `resource_downloads`) · **Casos de éxito** (`success_stories`) · **Centro de ayuda** (`help_categories`, `help_articles`, `help_article_feedback`) · **Partners y referidos** (`partner_applications`, `referral_codes`, `referrals`) · **Boletín normativo** (`newsletter_subscribers`, `newsletter_issues`) · **Centro de confianza y estado del servicio** (`subprocessors`, `status_components`, `status_incidents`, `status_incident_updates`) · **Biblioteca de medios y portafolio** (`site_media`, `success_story_media`) · **Métricas propias** (`site_analytics_daily`, `site_events`) · **Calendario de demos** (`demo_booking_settings`, `demo_hosts`, `demo_availability_rules`, `demo_blackouts`, `demo_meetings`) · **Chatbot del sitio** (`chatbot_settings`, `chatbot_intents`, `chatbot_conversations`, `chatbot_messages`) · **Conexión con el CRM** (`crm_connections`, `crm_sync_events`) · **Campañas y ventanas emergentes** (`campaigns`, `site_popups`) · **Integraciones y novedades** (`site_integrations`, `product_updates`) · **Webinars** (`webinars`, `webinar_registrations`) · **Reseñas de Google** (`review_sources`, `review_snapshots`) · **Calculadora de ahorro** (`savings_calculator_settings`, `savings_calculations`).

59 tablas de plataforma; inalterables: `legal_acceptances`, `cookie_consents`, `complaint_events`, `resource_downloads`, `help_article_feedback`, `status_incident_updates`, `site_events`, `chatbot_messages`.

Reglas en base de datos: `primary_domain IS NULL OR primary_domain ~ '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'`; `whatsapp_number IS NULL OR whatsapp_number ~ '^[0-9]{8,15}$'`; `interactive_demo_url IS NULL OR interactive_demo_url ~ '^https://'`; `blog_url IS NULL OR blog_url ~ '^https://'`; `status <> 'published' OR (published_at IS NOT NULL AND published_snapshot IS NOT NULL)`; `meta_title IS NULL OR length(meta_title) <= 70`.

### Grupo 2 · Planificación del sistema de gestión

#### LBA · 2.1 Diagnóstico de línea base (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `baseline_assessments` | principal | empresa | Diagnóstico de línea base (lista de verificación R.M. 050-2013-TR) | `title`, `reference_norm`, `assessment_on`, `site_id`, `evaluator_user_id`, `evaluator_name`, `is_initial`, `status` (draft/in_progress/completed/approved/archived), `total_items`, `compliant_items`, `score_pct`, `approved_by`, `approved_at`, `report_file_id` |
| `baseline_items` | principal | empresa | Ítem de la lista de verificación de línea base | `assessment_id`, `lineament_code`, `lineament_name`, `item_code`, `question`, `legal_basis`, `result` (compliant/partial/non_compliant/not_applicable/pending), `score`, `evidence_notes`, `evidence_file_id`, `sort_order` |
| `baseline_gaps` | principal | empresa | Brecha detectada en la línea base y su cierre | `assessment_id`, `baseline_item_id`, `description`, `priority` (low/medium/high/critical), `target_module`, `proposed_action`, `corrective_action_id`, `responsible_user_id`, `due_on`, `status` (open/in_progress/closed/accepted_risk), `closed_at` |

Reglas en base de datos: `score_pct IS NULL OR score_pct BETWEEN 0 AND 100`.

#### POL · 2.2 Política y objetivos SST (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `sst_policies` | principal | empresa | Política SST versionada, aprobada por gerencia y difundida | `version_number`, `title`, `content_html`, `file_id`, `status` (draft/in_review/approved/current/obsolete), `prepared_by`, `approved_by`, `approved_at`, `signature_request_id`, `effective_on`, `review_due_on`, `published_at`, `dissemination_channels`, `obsoleted_at` |
| `sst_objectives` | principal | empresa | Objetivos SST medibles vinculados a la política | `policy_id`, `year`, `code`, `title`, `description`, `indicator`, `baseline_value`, `target_value`, `unit`, `responsible_user_id`, `status` (draft/active/achieved/not_achieved/cancelled) |
| `objective_targets` | principal | empresa | Metas por periodo de un objetivo y su medición | `objective_id`, `period_label`, `target_on`, `target_value`, `actual_value`, `measured_at`, `achievement_pct`, `status` (pending/on_track/at_risk/achieved/missed), `notes` |

Reglas en base de datos: `approved_by IS NULL OR prepared_by IS NULL OR approved_by <> prepared_by`.

#### MRL · 2.3 Matriz de requisitos legales (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `legal_norms` | principal | catálogo global | Catálogo de normas legales peruanas (SST y relacionadas) | `norm_type` (law/supreme_decree/ministerial_resolution/technical_standard/ordinance/directive/other), `number`, `title`, `issuing_entity`, `published_on`, `effective_on`, `repealed_on`, `scope` (general/sector), `sector`, `summary`, `official_url`, `status` (current/amended/repealed) |
| `legal_parameters` | principal | mixta (global + empresa) · agregada | Parámetros legales con vigencia (plazos, factores, periodicidades, umbrales); company_id NULL = valor de plataforma | `key`, `value`, `value_type`, `unit`, `description`, `legal_norm_id`, `legal_reference`, `source`, `sector`, `valid_from`, `valid_to`, `to_confirm`, `confirmed_by`, `confirmed_at` |
| `holidays` | principal | catálogo global · agregada | Calendario de feriados para plazos en días hábiles | `holiday_on`, `name`, `scope` (national/regional), `region_ubigeo`, `is_non_working`, `to_confirm` |
| `legal_requirements` | principal | empresa | Requisito legal aplicable a la empresa (matriz de requisitos legales) | `legal_norm_id`, `article`, `requirement`, `applicability`, `applies`, `site_id`, `area_id`, `responsible_user_id`, `evidence_expected`, `frequency`, `next_evaluation_on`, `status` (active/not_applicable/repealed) |
| `requirement_evaluations` | principal | empresa | Evaluación periódica del cumplimiento de un requisito legal | `legal_requirement_id`, `evaluated_on`, `evaluator_user_id`, `result` (compliant/partial/non_compliant/not_applicable), `evidence_notes`, `evidence_file_id`, `corrective_action_id`, `next_evaluation_on` |

Reglas en base de datos: `valid_to IS NULL OR valid_to >= valid_from`; `NOT to_confirm OR confirmed_at IS NULL`.

#### PLA · 2.4 Plan Anual SST (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `annual_plans` | principal | empresa | Plan Anual SST: objetivos, metas y recursos | `year`, `title`, `objectives_summary`, `resources_text`, `budget_id`, `status` (draft/in_review/approved/current/closed), `committee_meeting_id`, `approved_by`, `approved_at`, `file_id` |
| `annual_plan_items` | principal | empresa | Línea del Plan Anual (objetivo, meta, indicador, recursos) | `annual_plan_id`, `objective_id`, `code`, `description`, `goal`, `indicator`, `resources`, `responsible_user_id`, `starts_on`, `ends_on`, `sort_order` |

#### PAS · 2.5 Programa Anual SST (PASST) (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `annual_programs` | principal | empresa | Programa Anual SST (PASST) | `year`, `annual_plan_id`, `title`, `status` (draft/committee_review/approved/in_execution/closed), `committee_meeting_id`, `approved_by`, `approved_at`, `progress_pct`, `closed_at` |
| `program_activities` | principal | empresa | Actividad del PASST con responsable, meses y avance | `program_id`, `annual_plan_item_id`, `objective_id`, `code`, `title`, `description`, `activity_type`, `linked_module`, `responsible_user_id`, `planned_months`, `starts_on`, `ends_on`, `frequency`, `goal_quantity`, `executed_quantity`, `budget_line_id`, … |
| `activity_executions` | principal | empresa | Ejecución registrada de una actividad del PASST con evidencia | `activity_id`, `executed_on`, `quantity`, `description`, `evidence_file_id`, `source_module`, `source_id`, `recorded_by`, `verified_by`, `verified_at` |

Reglas en base de datos: `status <> 'cancelled' OR cancel_reason IS NOT NULL`.

#### PCA · 2.6 Programa anual de capacitación (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `training_programs` | principal | empresa | Programa anual de capacitación aprobado por el comité | `year`, `title`, `annual_program_id`, `status` (draft/committee_review/approved/in_execution/closed), `committee_meeting_id`, `approved_at`, `min_trainings_param`, `notes` |
| `training_program_items` | principal | empresa | Tema programado en el programa anual de capacitación | `training_program_id`, `topic`, `training_type` (induction/specific/refresher/drill_training/awareness/legal_mandatory/other), `is_mandatory_legal`, `target_audience`, `position_id`, `planned_month`, `duration_minutes`, `modality` (in_person/virtual/blended), `provider`, `responsible_user_id`, `training_id`, `status` (planned/scheduled/done/cancelled) |

Reglas en base de datos: `planned_month IS NULL OR planned_month BETWEEN 1 AND 12`.

#### RIS · 2.7 RISST (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `risst_versions` | principal | empresa | Versión del Reglamento Interno de SST | `version_number`, `title`, `content_html`, `file_id`, `change_summary`, `status` (draft/in_review/approved/current/obsolete), `prepared_by`, `committee_meeting_id`, `approved_by`, `approved_at`, `effective_on`, `obsoleted_at` |
| `risst_deliveries` | principal | empresa · offline | Cargo de entrega del RISST a cada trabajador | `risst_version_id`, `worker_id`, `delivered_on`, `method` (app_signature/physical_signed/email/in_person), `signature_id`, `acknowledgement_file_id`, `delivered_by`, `status` (pending/delivered/signed) |

#### COM · 2.8 Comité o Supervisor SST (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `committees` | principal | empresa | Comité SST paritario, subcomité o Supervisor SST con su periodo de mandato | `committee_type` (committee/subcommittee/supervisor), `site_id`, `name`, `period_start_on`, `period_end_on`, `installation_on`, `installation_minutes_file_id`, `members_required`, `status` (forming/active/ended), `notes` |
| `committee_members` | principal | empresa | Integrante del comité (titular o suplente, por representación) | `committee_id`, `worker_id`, `representation` (employer/workers/supervisor), `member_type` (titular/alternate), `role_in_committee` (president/secretary/member/observer), `election_id`, `designation_reference`, `starts_on`, `ends_on`, `status` (active/replaced/resigned/ended), `training_completed` |
| `elections` | principal | empresa | Elección electrónica de representantes de los trabajadores | `committee_id`, `title`, `period_label`, `status` (convened/nominations_open/nominations_closed/voting_open/voting_closed/results_published/annulled/cancelled), `convened_at`, `nominations_open_at`, `nominations_close_at`, `voting_opens_at`, `voting_closes_at`, `seats_titular`, `seats_alternate`, `electoral_board`, `quorum_param_key`, `allow_blank`, `eligible_voters`, `votes_cast`, … |
| `election_candidates` | principal | empresa | Candidato inscrito en una elección | `election_id`, `worker_id`, `list_name`, `nominated_at`, `status` (nominated/accepted/rejected/withdrawn/elected_titular/elected_alternate/not_elected), `rejection_reason`, `votes_count` |
| `election_ballots` | principal | empresa · inalterable | Voto emitido (inalterable y anónimo: sin worker_id; voter_token_hash = HMAC con llave fuera de la base) | `election_id`, `voter_token_hash`, `candidate_id`, `is_blank`, `is_null_vote`, `channel` (app/web/kiosk/paper_transcribed), `cast_at`, `ballot_hash` |
| `committee_meetings` | principal | empresa | Reunión del comité con acta | `committee_id`, `meeting_type` (ordinary/extraordinary/installation), `number`, `scheduled_at`, `held_at`, `location`, `modality` (in_person/virtual/mixed), `quorum_reached`, `attendance`, `agenda`, `minutes_html`, `minutes_file_id`, `signature_request_id`, `status` (scheduled/held/minutes_draft/minutes_signed/cancelled) |
| `meeting_agreements` | principal | empresa | Acuerdo de reunión del comité con responsable y plazo | `meeting_id`, `number`, `description`, `responsible_user_id`, `responsible_worker_id`, `due_on`, `status` (agreed/in_progress/fulfilled/overdue/cancelled), `fulfilled_at`, `evidence_file_id`, `corrective_action_id` |

Reglas en base de datos: `voting_closes_at IS NULL OR voting_opens_at IS NULL OR voting_closes_at > voting_opens_at`; `(candidate_id IS NOT NULL)::int + is_blank::int + is_null_vote::int = 1`.

#### PRE · 2.9 Presupuesto SST (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `budgets` | principal | empresa | Presupuesto SST anual | `year`, `title`, `currency`, `total_planned`, `total_executed`, `status` (draft/in_review/approved/in_execution/closed), `approved_by`, `approved_at` |
| `budget_lines` | principal | empresa | Partida del presupuesto SST | `budget_id`, `category`, `description`, `program_activity_id`, `site_id`, `cost_center`, `planned_amount`, `executed_amount` |
| `budget_executions` | principal | empresa | Gasto ejecutado contra una partida | `budget_line_id`, `executed_on`, `amount`, `description`, `supplier_name`, `document_reference`, `file_id`, `recorded_by` |

Reglas en base de datos: `planned_amount >= 0`; `amount <> 0`.

### Grupo 3 · Gestión de riesgos

#### IPC · 3.1 Matriz IPERC (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `hazard_catalog` | principal | mixta (global + empresa) | Catálogo de peligros y riesgos (base global de la plataforma o propio de la empresa) | `code`, `hazard_family`, `hazard`, `risk`, `possible_consequence`, `typical_controls`, `sector`, `source`, `is_active` |
| `risk_criteria` | principal | mixta (global + empresa) | Criterios de evaluación de riesgo configurables (probabilidad × severidad, bandas de nivel) | `code`, `name`, `version_number`, `method` (pxs/pxs_index), `probability_scale`, `severity_scale`, `probability_index_factors`, `level_bands`, `significant_from_level`, `is_default`, `status` (draft/active/retired) |
| `iperc_matrices` | principal | empresa | Matriz IPERC por sede, área, puesto o proceso | `code`, `title`, `site_id`, `area_id`, `position_id`, `process`, `scope_description`, `risk_criteria_id`, `current_version_id`, `sector_pack_item_id`, `next_review_on`, `status` (active/archived) |
| `iperc_versions` | principal | empresa | Versión de la matriz IPERC con aprobación (aprobador distinto del elaborador) | `iperc_matrix_id`, `version_number`, `status` (draft/in_review/approved/current/under_update/obsolete), `change_reason`, `participants`, `criteria_snapshot`, `prepared_by`, `prepared_at`, `reviewed_by`, `approved_by`, `approved_at`, `effective_on`, `obsoleted_at`, `file_id`, `signature_request_id` |
| `iperc_rows` | principal | empresa · offline | Fila IPERC: proceso, actividad, tarea, peligro, riesgo, evaluación inicial y residual | `iperc_version_id`, `row_number`, `process`, `activity`, `task`, `is_routine`, `position_id`, `hazard_catalog_id`, `hazard`, `risk`, `consequence`, `exposed_workers`, `probability_value`, `probability_detail`, `severity_value`, `risk_score`, … |
| `iperc_controls` | principal | empresa | Control existente o propuesto por jerarquía de controles | `iperc_row_id`, `hierarchy` (elimination/substitution/engineering/administrative/signage/ppe), `description`, `is_existing`, `responsible_user_id`, `due_on`, `status` (existing/proposed/implemented/verified/cancelled), `corrective_action_id`, `ppe_item_id`, `procedure_id`, `training_id` |

Reglas en base de datos: `approved_by IS NULL OR prepared_by IS NULL OR approved_by <> prepared_by`; `NOT ai_assisted OR ai_suggestion_id IS NOT NULL`.

#### MAP · 3.2 Mapa de riesgos (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `risk_maps` | principal | empresa | Mapa de riesgos sobre plano de la sede | `site_id`, `area_id`, `title`, `floor_label`, `version_number`, `plan_file_id`, `image_width`, `image_height`, `status` (draft/in_review/approved/current/obsolete), `approved_by`, `approved_at`, `effective_on` |
| `risk_map_markers` | principal | empresa | Pictograma ubicado en el plano y vinculado al IPERC o a un equipo | `risk_map_id`, `marker_type` (hazard/mandatory/prohibition/warning/evacuation/emergency_equipment/first_aid/assembly_point/fire), `pictogram_code`, `x_pct`, `y_pct`, `label`, `iperc_row_id`, `emergency_equipment_id`, `notes` |

#### MAG · 3.3 Monitoreo de agentes (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `agent_types` | principal | catálogo global | Tipos de agentes ocupacionales (físicos, químicos, biológicos, ergonómicos, psicosociales) | `code`, `family` (physical/chemical/biological/ergonomic/psychosocial), `name`, `default_unit`, `measurement_method`, `reference_norm`, `description` |
| `exposure_limits` | principal | mixta (global + empresa) | Límites permisibles por agente (globales de la norma u otros que la empresa adopte) | `agent_type_id`, `substance_name`, `cas_number`, `limit_type` (TWA/STEL/CEILING/DOSE/ACTION_LEVEL/OTHER), `limit_value`, `unit`, `exposure_hours`, `legal_norm_id`, `legal_reference`, `valid_from`, `valid_to`, `to_confirm` |
| `monitoring_points` | principal | empresa | Punto de monitoreo de un agente en la sede | `site_id`, `area_id`, `position_id`, `agent_type_id`, `code`, `name`, `description`, `latitude`, `longitude`, `status` (active/inactive) |
| `monitoring_campaigns` | principal | empresa | Campaña de monitoreo ocupacional (propia o de laboratorio externo) | `title`, `year`, `provider_name`, `provider_ruc`, `laboratory_accreditation`, `starts_on`, `ends_on`, `program_activity_id`, `responsible_user_id`, `report_file_id`, `status` (planned/in_progress/completed/report_received/closed) |
| `measurements` | principal | empresa · offline | Medición frente al límite permisible | `campaign_id`, `monitoring_point_id`, `agent_type_id`, `exposure_limit_id`, `worker_id`, `measured_at`, `duration_minutes`, `value`, `unit`, `equipment`, `equipment_calibration_on`, `result` (within_limit/action_level/exceeds_limit/not_evaluable), `corrective_action_id`, `notes` |

#### PSI · 3.4 Riesgos ergonómicos y psicosociales (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `ergonomic_assessments` | principal | empresa | Evaluación ergonómica por puesto o trabajador (R.M. 375-2008-TR) | `position_id`, `worker_id`, `area_id`, `method` (REBA/RULA/OWAS/NIOSH/OCRA/checklist_rm375/other), `assessed_on`, `assessor_user_id`, `score`, `risk_level` (low/medium/high/very_high), `findings`, `recommendations`, `file_id`, `corrective_action_id`, `status` (draft/completed/reviewed) |
| `psychosocial_surveys` | principal | empresa | Encuesta de riesgo psicosocial (anónima por defecto) | `title`, `instrument` (istas21/copsoq/custom), `instrument_version`, `starts_on`, `ends_on`, `is_anonymous`, `min_responses_param`, `target_scope`, `questions`, `results`, `report_file_id`, `status` (draft/open/closed/reported) |
| `psychosocial_responses` | principal | empresa | Respuesta a encuesta psicosocial (sin worker_id cuando es anónima) | `survey_id`, `respondent_token_hash`, `worker_id`, `site_id`, `area_id`, `answers`, `demographics`, `submitted_at` |

#### PET · 3.5 PETS y procedimientos (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `procedures` | principal | empresa | PETS o procedimiento por tarea crítica | `code`, `title`, `procedure_type` (pets/standard/instruction/emergency_plan/other), `site_id`, `area_id`, `position_id`, `permit_type_id`, `is_critical_task`, `current_version_id`, `next_review_on`, `status` (active/archived) |
| `procedure_versions` | principal | empresa | Versión del procedimiento con aprobación | `procedure_id`, `version_number`, `content_html`, `file_id`, `required_ppe`, `participants`, `change_summary`, `status` (draft/in_review/approved/current/obsolete), `prepared_by`, `reviewed_by`, `approved_by`, `approved_at`, `effective_on`, `obsoleted_at` |

Reglas en base de datos: `approved_by IS NULL OR prepared_by IS NULL OR approved_by <> prepared_by`.

#### MOC · 3.6 Gestión del cambio (plan Complemento, fase 5)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `change_requests` | principal | empresa | Solicitud de cambio con evaluación de riesgos previa | `code`, `title`, `change_type` (process/equipment/material/organization/legal/facility/personnel/other), `is_temporary`, `description`, `justification`, `site_id`, `area_id`, `requested_by`, `requested_on`, `planned_implementation_on`, `risk_assessment`, `iperc_version_id`, `status` (draft/submitted/under_review/approved/rejected/implemented/closed/cancelled), `decision_at`, `implemented_at`, … |
| `change_reviews` | principal | empresa | Revisión y decisión sobre una solicitud de cambio | `change_request_id`, `reviewer_user_id`, `review_area`, `decision` (approve/approve_with_conditions/reject/request_info), `comments`, `conditions`, `reviewed_at` |

### Grupo 4 · Operación en campo

#### INS · 4.1 Inspecciones (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `inspection_templates` | principal | mixta (global + empresa) | Plantilla de inspección (global, de paquete sectorial o propia) | `code`, `name`, `inspection_type`, `sector`, `sector_pack_id`, `version_number`, `description`, `scoring_method` (pass_fail/weighted/none), `requires_signature`, `status` (draft/active/retired) |
| `inspection_template_items` | principal | mixta (global + empresa) | Pregunta o ítem de una plantilla de inspección | `template_id`, `section`, `item_code`, `question`, `answer_type` (yes_no_na/compliant_noncompliant/scale_1_5/numeric/text/photo), `weight`, `is_critical`, `requires_photo_on_fail`, `legal_reference`, `sort_order` |
| `inspections` | principal | empresa · offline | Inspección interna (registro obligatorio) con checklist, fotos y ubicación | `template_id`, `code`, `title`, `inspection_type`, `site_id`, `area_id`, `scheduled_on`, `started_at`, `completed_at`, `inspector_user_id`, `inspector_worker_id`, `committee_participation`, `participants`, `score_pct`, `latitude`, `longitude`, … |
| `inspection_answers` | principal | empresa · offline | Respuesta a un ítem de inspección | `inspection_id`, `template_item_id`, `question_snapshot`, `answer`, `numeric_value`, `is_compliant`, `is_critical`, `comment`, `photo_file_id` |
| `findings` | principal | empresa · offline | Hallazgo de inspección (o de otra fuente) que puede generar acción correctiva | `source_type` (inspection/observation/report/other), `inspection_id`, `inspection_answer_id`, `finding_type` (unsafe_act/unsafe_condition/non_compliance/observation/opportunity), `description`, `severity` (low/medium/high/critical), `site_id`, `area_id`, `photo_file_id`, `latitude`, `longitude`, `status` (open/action_assigned/resolved/dismissed), `dismissed_reason`, `corrective_action_id` |

Reglas en base de datos: `completed_at IS NULL OR started_at IS NULL OR completed_at >= started_at`; `status <> 'dismissed' OR dismissed_reason IS NOT NULL`.

#### ACT · 4.2 Actos y condiciones subestándar (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `report_points` | principal | empresa | Punto de reporte con QR (token opaco guardado como hash) | `code`, `name`, `site_id`, `area_id`, `location_description`, `token_hash`, `token_last4`, `qr_file_id`, `whatsapp_keyword`, `allow_anonymous`, `is_active`, `report_count`, `last_used_at`, `revoked_at` |
| `substandard_reports` | principal | empresa · offline | Reporte de acto o condición subestándar (origen QR, WhatsApp, app o web) | `code`, `report_type` (unsafe_act/unsafe_condition/near_miss/suggestion), `origin` (qr/whatsapp/app/web), `report_point_id`, `site_id`, `area_id`, `contractor_id`, `description`, `location_text`, `latitude`, `longitude`, `photo_file_id`, `occurred_at`, `reported_at`, `is_anonymous`, `reporter_worker_id`, … |

Reglas en base de datos: `NOT is_anonymous OR (reporter_worker_id IS NULL AND reporter_user_id IS NULL AND reporter_contact IS NULL)`; `origin <> 'qr' OR report_point_id IS NOT NULL`; `status <> 'rejected' OR rejection_reason IS NOT NULL`.

#### CH5 · 4.3 Charlas de 5 minutos (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `safety_talks` | principal | empresa · offline | Charla de 5 minutos | `topic`, `content`, `talk_on`, `started_at`, `site_id`, `area_id`, `work_shift_id`, `speaker_user_id`, `speaker_worker_id`, `duration_minutes`, `material_document_id`, `photo_file_id`, `latitude`, `longitude`, `status` (scheduled/held/cancelled) |
| `talk_attendances` | principal | empresa · offline | Asistencia a charla con firma | `safety_talk_id`, `worker_id`, `contractor_worker_id`, `attended_at`, `method` (signature/qr_scan/list), `signature_id` |

Reglas en base de datos: `(worker_id IS NOT NULL) <> (contractor_worker_id IS NOT NULL)`.

#### ATS · 4.4 ATS (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `ats_forms` | principal | empresa · offline | Análisis de Trabajo Seguro antes de la tarea | `code`, `task`, `site_id`, `area_id`, `work_location`, `performed_on`, `supervisor_user_id`, `leader_worker_id`, `contractor_id`, `work_permit_id`, `procedure_id`, `required_ppe`, `tools_equipment`, `latitude`, `longitude`, `started_at`, … |
| `ats_steps` | principal | empresa · offline | Paso de la tarea con peligros, riesgos y controles | `ats_form_id`, `step_number`, `description`, `hazards`, `risks`, `controls`, `responsible` |
| `ats_participants` | principal | empresa · offline | Participante del ATS con firma | `ats_form_id`, `worker_id`, `contractor_worker_id`, `role` (leader/executor/supervisor/watcher), `signature_id`, `signed_at` |

Reglas en base de datos: `(worker_id IS NOT NULL) <> (contractor_worker_id IS NOT NULL)`.

#### PTR · 4.5 PETAR y permisos de alto riesgo (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `permit_types` | principal | mixta (global + empresa) | Tipo de permiso de alto riesgo (altura, caliente, espacio confinado, eléctrico…) con checklist y cadena de aprobación | `code`, `name`, `category` (work_at_height/hot_work/confined_space/electrical/excavation/lifting/lockout_tagout/chemical/other), `validity_hours`, `requires_ats`, `requires_gas_test`, `checklist`, `approval_chain`, `required_credentials`, `legal_reference`, `is_active` |
| `work_permits` | principal | empresa · offline | PETAR / permiso de trabajo de alto riesgo con aprobaciones y cierre | `code`, `permit_type_id`, `site_id`, `area_id`, `work_description`, `location_text`, `latitude`, `longitude`, `contractor_id`, `executor_leader_worker_id`, `executor_leader_contractor_worker_id`, `crew`, `requested_by`, `valid_from`, `valid_until`, `ats_form_id`, … |
| `permit_checks` | principal | empresa · offline | Verificación del checklist del permiso por fase | `work_permit_id`, `check_phase` (pre_start/during/closure), `item_code`, `question`, `answer` (yes/no/na), `value`, `comment`, `photo_file_id`, `checked_by`, `checked_at` |
| `permit_approvals` | principal | empresa · offline | Paso de aprobación del permiso (ningún aprobador es el ejecutor) | `work_permit_id`, `step_order`, `approver_role`, `approver_user_id`, `decision` (pending/approved/rejected), `decided_at`, `comments`, `signature_id` |

Reglas en base de datos: `status <> 'suspended' OR suspended_reason IS NOT NULL`; `status <> 'closed' OR (closed_at IS NOT NULL AND area_left_safe IS NOT NULL)`; `decision = 'pending' OR (decided_at IS NOT NULL AND approver_user_id IS NOT NULL)`.

#### OBS · 4.6 Observación de conducta segura (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `behavior_observations` | principal | empresa · offline | Observación de conducta segura (SBC) | `observed_on`, `observer_user_id`, `observer_worker_id`, `site_id`, `area_id`, `contractor_id`, `task_observed`, `observed_worker_count`, `observed_worker_id`, `feedback_given`, `safe_count`, `at_risk_count`, `comments`, `status` (draft/submitted/reviewed) |
| `behavior_observation_items` | principal | empresa · offline | Conducta observada por categoría | `observation_id`, `category` (ppe/body_position/tools/procedures/housekeeping/communication/other), `behavior`, `result` (safe/at_risk/not_observed), `comment`, `corrective_action_id` |

#### EPP · 4.7 EPP (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `ppe_items` | principal | empresa | Catálogo de EPP de la empresa | `code`, `name`, `category` (head/eye_face/hearing/respiratory/hand/foot/body/fall_protection/other), `brand`, `model`, `technical_standard`, `size_options`, `useful_life_days`, `unit`, `min_stock`, `requires_training`, `photo_file_id`, `is_active` |
| `ppe_position_requirements` | principal | empresa | EPP obligatorio por puesto | `position_id`, `ppe_item_id`, `quantity`, `replacement_days`, `is_mandatory`, `conditions` |
| `ppe_stock_movements` | principal | empresa · offline | Movimiento de stock de EPP por sede | `ppe_item_id`, `site_id`, `movement_type` (in/out/adjustment/return/transfer/disposal), `quantity`, `size`, `unit_cost`, `moved_at`, `reference`, `supplier_name`, `ppe_delivery_id`, `notes` |
| `ppe_deliveries` | principal | empresa · offline | Entrega de EPP al trabajador con firma y vencimiento | `worker_id`, `ppe_item_id`, `site_id`, `quantity`, `size`, `delivered_at`, `delivery_reason` (initial/replacement/loss/damage/other), `expires_on`, `delivered_by`, `signature_id`, `returned_at`, `return_condition`, `status` (delivered/returned/replaced/lost) |

Reglas en base de datos: `quantity <> 0`.

#### EQE · 4.8 Equipos de emergencia y botiquines (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `emergency_equipment` | principal | empresa | Equipo de emergencia (extintores, luces, alarmas, lavaojos…) | `code`, `equipment_type`, `site_id`, `area_id`, `location_text`, `specs`, `brand`, `serial_number`, `installed_on`, `last_maintenance_on`, `next_maintenance_on`, `expires_on`, `latitude`, `longitude`, `status` (operational/needs_maintenance/out_of_service/retired) |
| `equipment_checks` | principal | empresa · offline | Inspección o mantenimiento de un equipo de emergencia | `equipment_id`, `check_type` (monthly_inspection/maintenance/recharge/hydrostatic_test/other), `checked_at`, `result` (ok/observed/failed), `checklist`, `checked_by`, `provider_name`, `certificate_file_id`, `next_due_on`, `comments` |
| `first_aid_kits` | principal | empresa | Botiquín de primeros auxilios | `code`, `site_id`, `area_id`, `location_text`, `kit_type` (A/B/C/vehicle/other), `responsible_worker_id`, `last_checked_on`, `status` (complete/incomplete/expired_items/retired) |
| `first_aid_kit_items` | principal | empresa | Contenido del botiquín con vencimientos | `kit_id`, `item_name`, `required_quantity`, `current_quantity`, `unit`, `lot`, `expires_on` |

Reglas en base de datos: `current_quantity >= 0`.

#### SIM · 4.9 Simulacros y brigadas (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `drills` | principal | empresa | Simulacro programado y ejecutado (registro obligatorio) | `code`, `title`, `drill_type` (earthquake/fire/evacuation/first_aid/chemical_spill/rescue/tsunami/other), `site_id`, `scheduled_at`, `executed_at`, `scenario`, `objectives`, `is_announced`, `evacuation_time_seconds`, `participants_count`, `observers`, `evaluation`, `result` (satisfactory/needs_improvement/unsatisfactory), `external_entity`, `program_activity_id`, … |
| `drill_participants` | principal | empresa · offline | Participación en simulacro | `drill_id`, `worker_id`, `contractor_worker_id`, `role` (participant/brigadist/observer/evaluator), `attended`, `signature_id` |
| `brigades` | principal | empresa | Brigada de emergencia por sede | `site_id`, `brigade_type` (evacuation/first_aid/firefighting/rescue/communications/general), `name`, `leader_worker_id`, `starts_on`, `ends_on`, `status` (active/inactive) |
| `brigade_members` | principal | empresa | Integrante de brigada | `brigade_id`, `worker_id`, `role` (leader/deputy/member), `starts_on`, `ends_on`, `training_id`, `status` (active/inactive) |

Reglas en base de datos: `(worker_id IS NOT NULL) <> (contractor_worker_id IS NOT NULL)`.

### Grupo 5 · Personas y salud ocupacional

#### CAP · 5.1 Capacitaciones e inducciones (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `trainings` | principal | empresa | Capacitación, inducción o entrenamiento (registro obligatorio) | `code`, `title`, `training_type` (induction/specific/refresher/drill_training/awareness/legal_mandatory/other), `topic`, `description`, `is_legal_mandatory`, `modality` (in_person/virtual/blended), `duration_minutes`, `provider_type` (internal/external), `provider_name`, `provider_ruc`, `instructor_name`, `passing_score`, `certificate_validity_months`, `training_program_item_id`, `course_id`, … |
| `training_sessions` | principal | empresa · offline | Sesión (fecha y lugar) de una capacitación | `training_id`, `session_number`, `starts_at`, `ends_at`, `site_id`, `location`, `virtual_url`, `instructor_user_id`, `instructor_name`, `capacity`, `status` (scheduled/held/cancelled) |
| `training_attendances` | principal | empresa · offline | Asistencia a una sesión con firma y horas | `session_id`, `worker_id`, `contractor_worker_id`, `attended`, `check_in_at`, `check_in_method` (signature/qr/list/virtual), `signature_id`, `hours` |
| `training_evaluations` | principal | empresa · offline | Evaluación (entrada, salida, práctica o satisfacción) | `training_id`, `session_id`, `worker_id`, `contractor_worker_id`, `evaluation_type` (pre/post/practical/satisfaction), `score`, `max_score`, `passed`, `answers`, `evaluated_at`, `quiz_attempt_id` |
| `training_certificates` | principal | empresa | Certificado o constancia de una capacitación (CAP) o de un curso del aula (AUL), con validación, anulación y verificación pública por código | `training_id`, `course_id`, `course_enrollment_id`, `worker_id`, `contractor_worker_id`, `external_learner_id`, `certificate_template_id`, `certificate_code`, `verification_code`, `holder_name`, `holder_document_masked`, `course_title`, `issued_on`, `expires_on`, `hours`, `score`, … |
| `certificate_templates` | principal | mixta (global + empresa) | Plantilla de certificado: global por defecto de la plataforma (company_id NULL) o diseño propio de la empresa | `code`, `name`, `description`, `orientation` (landscape/portrait), `paper_size` (A4/letter), `layout`, `body_template`, `variable_fields`, `logo_file_id`, `background_file_id`, `signatory_name`, `signatory_title`, `signature_file_id`, `second_signatory_name`, `second_signatory_title`, `second_signature_file_id`, … |
| `certificate_verifications` | principal | empresa · inalterable | Consulta pública de un certificado por código (sin sesión), inalterable | `certificate_id`, `queried_code`, `verified_at`, `channel` (public_web/public_qr/app), `result` (valid/expired/voided/pending_validation/not_public), `response_snapshot`, `ip_hash`, `user_agent` |
| `position_training_requirements` | principal | empresa | Matriz de capacitación por cargo: capacitación o curso obligatorio o sugerido por puesto, con renovación | `position_id`, `training_id`, `course_id`, `requirement_level` (mandatory/suggested), `renewal_months`, `due_within_days`, `applies_to_contractors`, `auto_enroll`, `legal_parameter_key`, `notes`, `is_active` |

Reglas en base de datos: `ends_at IS NULL OR ends_at > starts_at`; `(worker_id IS NOT NULL) <> (contractor_worker_id IS NOT NULL)`; `(worker_id IS NOT NULL) <> (contractor_worker_id IS NOT NULL)`; `training_id IS NOT NULL OR course_id IS NOT NULL`; `((worker_id IS NOT NULL)::int + (contractor_worker_id IS NOT NULL)::int + (external_learner_id IS NOT NULL)::int) = 1`; `status <> 'validated' OR (validated_by IS NOT NULL AND validated_at IS NOT NULL)`.

#### AUL · 5.2 Aula virtual SST (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `courses` | principal | mixta (global + empresa) | Curso del portal propio de cursos: interno o a la venta, enlazado a Moodle (opcional) o del catálogo SST estándar de EscudoSST (company_id NULL) | `code`, `title`, `summary`, `description`, `objectives`, `target_audience`, `category`, `source` (internal/external/catalog), `lms_connection_id`, `external_course_id`, `catalog_course_code`, `cover_file_id`, `promo_video_provider` (vimeo), `promo_video_id`, `sector_pack_id`, `modality` (internal/for_sale/both), … |
| `course_sections` | principal | mixta (global + empresa) | Sección (unidad o módulo) de un curso que agrupa lecciones | `course_id`, `section_number`, `title`, `description`, `sort_order`, `is_visible` |
| `course_lessons` | principal | mixta (global + empresa) | Lección del curso: texto, video en Vimeo, documento, enlace o evaluación (company_id NULL en cursos del catálogo) | `course_id`, `section_id`, `lesson_number`, `title`, `lesson_type` (text/video/document/link/exam), `content_html`, `file_id`, `video_provider` (vimeo), `video_external_id`, `external_url`, `duration_minutes`, `is_required`, `min_view_pct`, `question_bank_id`, `exam_config`, `passing_score`, … |
| `course_enrollments` | principal | empresa | Matrícula en un curso de un trabajador, trabajador de contratista o participante externo | `course_id`, `worker_id`, `contractor_worker_id`, `external_learner_id`, `user_id`, `source` (manual/position_assignment/purchase/license/self_enrollment/lms_sync), `order_item_id`, `license_assignment_id`, `position_training_requirement_id`, `enrolled_by`, `enrolled_at`, `access_until`, `due_on`, `started_at`, `completed_at`, `progress_pct`, … |
| `quiz_attempts` | principal | empresa · offline | Intento de evaluación en línea (propio, sorteado desde bancos, o nota importada de Moodle) | `enrollment_id`, `lesson_id`, `source` (internal/external), `external_attempt_id`, `attempt_number`, `question_set`, `started_at`, `submitted_at`, `expires_at`, `time_spent_seconds`, `score`, `max_score`, `grade_label`, `passed`, `answers`, `graded_by`, … |
| `question_banks` | principal | empresa | Banco de preguntas: general de la empresa (course_id NULL) o propio de un curso | `course_id`, `code`, `name`, `description`, `topic`, `status` (active/archived) |
| `questions` | principal | empresa | Pregunta de un banco (opción única, múltiple, verdadero/falso o respuesta corta) con puntaje y retroalimentación | `question_bank_id`, `code`, `question_type` (single_choice/multiple_choice/true_false/short_answer), `statement`, `statement_file_id`, `options`, `accepted_answers`, `points`, `difficulty` (easy/medium/hard), `feedback_correct`, `feedback_incorrect`, `shuffle_options`, `bulk_import_id`, `status` (draft/active/archived) |
| `grading_scales` | principal | mixta (global + empresa) | Escala de calificación: numérica (0-20, 0-10, 0-100) o de letras (AD/A/B/C, A/B/C/D), global por defecto o propia | `code`, `name`, `scale_type` (numeric/letter), `min_value`, `max_value`, `passing_value`, `passing_label`, `bands`, `decimals`, `is_default`, `status` (active/archived) |
| `trainer_permissions` | principal | empresa | Permisos del capacitador, globales en la empresa (course_id NULL) o por curso; los configura el administrador | `trainer_user_id`, `course_id`, `can_upload_materials`, `can_edit_content`, `can_edit_assessments`, `can_manage_question_banks`, `can_grade`, `can_enroll`, `can_view_reports`, `valid_from`, `valid_until`, `granted_by`, `notes` |
| `trainer_availability` | principal | empresa | Horario semanal de disponibilidad del capacitador y fechas bloqueadas | `trainer_user_id`, `kind` (weekly/blocked/extra), `weekday`, `start_time`, `end_time`, `date_from`, `date_to`, `site_id`, `modality` (in_person/virtual/both), `reason` |
| `learning_activity_sessions` | principal | empresa | Sesión de actividad real en el aula (latidos con interacción y video), base del tiempo de permanencia acreditable | `enrollment_id`, `lesson_id`, `client_session_id`, `started_at`, `last_heartbeat_at`, `ended_at`, `active_seconds`, `video_seconds`, `video_max_position_seconds`, `interactions_count`, `heartbeats_count`, `last_heartbeat_seq`, `device_type` (desktop/mobile/tablet/other), `user_agent`, `ip_hash`, `end_reason` (closed/idle_timeout/completed/superseded), … |
| `completion_commitments` | principal | empresa | Compromiso de culminación aceptado por el participante (obligatorio si el curso lo activa) | `enrollment_id`, `course_id`, `commitment_text_snapshot`, `text_version`, `accepted_at`, `accepted_by_user_id`, `signature_id`, `ip_hash`, `user_agent` |
| `lms_connections` | principal | empresa | Conexión opcional con el Moodle del cliente por API (única implementación de LmsProvider); credenciales solo como referencia al gestor de secretos | `provider` (moodle), `name`, `base_url`, `credentials_secret_ref`, `moodle_service_name`, `sync_direction` (import/export/both), `sync_enrollments`, `sync_grades`, `sso_enabled`, `settings`, `status` (pending/active/error/disabled), `last_tested_at`, `last_sync_at`, `last_sync_status` (ok/partial/failed), `last_error` |
| `recommendation_rules` | principal | empresa | Regla automática de recomendación de cursos configurable por la empresa (nunca usa datos médicos) | `code`, `name`, `description`, `rule_type` (matrix_gap/certificate_expiring/learning_path_next/complementary/inspection_finding/incident_related/similar_buyers/manual), `parameters`, `audience` (workers/external_learners/buyer_companies), `course_ids`, `channels`, `max_sends_per_person`, `send_period_days`, `requires_consent`, `priority`, `is_active`, `last_run_at`, `last_run_status` (ok/partial/failed), `last_run_count` |
| `course_recommendations` | principal | empresa | Recomendación de un curso a un trabajador, participante externo o empresa compradora, con su embudo de conversión | `course_id`, `course_offer_id`, `worker_id`, `external_learner_id`, `buyer_company_ruc`, `buyer_company_name`, `rule_id`, `source` (rule/manual/ai), `recommended_by`, `target_scope` (person/position/area/buyer_company), `target_position_id`, `target_area_id`, `reason_code`, `message`, `ai_suggestion_id`, `channel` (in_app/email/whatsapp), … |

Reglas en base de datos: `source <> 'external' OR (lms_connection_id IS NOT NULL AND external_course_id IS NOT NULL)`; `source <> 'catalog' OR catalog_course_code IS NOT NULL`; `max_attempts IS NULL OR max_attempts > 0`; `hours IS NULL OR hours > 0`; `certificate_validity_months IS NULL OR certificate_validity_months > 0`; `min_active_time_pct IS NULL OR min_active_time_pct BETWEEN 0 AND 100`.

#### FOT · 5.3 Fotocheck SST con QR (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `credential_types` | principal | empresa | Tipo de fotocheck SST (diseño, vigencia, a quién aplica) | `code`, `name`, `description`, `validity_months`, `design_template`, `shows_photo`, `shows_position`, `applies_to` (workers/contractor_workers/both), `is_active` |
| `credential_requirements` | principal | empresa | Requisito para que la credencial esté habilitada (capacitación, aptitud vigente, SCTR, inducción…) | `credential_type_id`, `requirement_type` (training/emo_fitness/sctr/induction/document/ppe_delivery/accreditation), `training_id`, `worker_document_kind`, `position_id`, `description`, `is_blocking`, `grace_days` |
| `credentials` | principal | empresa | Fotocheck SST con token QR verificable (estado calculado) | `credential_type_id`, `worker_id`, `contractor_worker_id`, `credential_number`, `token_hash`, `token_last4`, `qr_payload_version`, `issued_at`, `issued_by`, `valid_from`, `expires_on`, `status` (draft/issued/active/blocked/expired/revoked), `blocking_reasons`, `last_evaluated_at`, `revoked_at`, `revoked_reason`, … |
| `credential_verifications` | principal | empresa · inalterable · offline | Verificación de un fotocheck (pública por QR, app o portería), inalterable | `credential_id`, `verified_at`, `channel` (public_qr/app_scan/access_point), `access_point_id`, `verifier_user_id`, `result` (enabled/not_enabled/invalid/revoked/expired), `response_snapshot`, `ip_hash`, `user_agent`, `latitude`, `longitude` |

Reglas en base de datos: `(worker_id IS NOT NULL) <> (contractor_worker_id IS NOT NULL)`; `status <> 'revoked' OR revoked_reason IS NOT NULL`.

#### EMO · 5.4 EMOs y aptitud (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `medical_exam_orders` | principal | empresa | Orden y programación de EMO (sin resultados; los resultados viven en la base médica) | `worker_id`, `exam_type` (pre_employment/periodic/exit/return_to_work/position_change/other), `position_id`, `protocol_code`, `clinic_name`, `clinic_ruc`, `scheduled_at`, `attended_at`, `status` (scheduled/attended/no_show/results_received/fitness_issued/cancelled), `cancel_reason`, `requested_by`, `order_file_id`, `medical_exam_ref` |
| `fitness_status` | principal | empresa | Aptitud vigente del trabajador (apto / con restricciones / no apto / pendiente); nunca diagnósticos | `worker_id`, `medical_exam_order_id`, `status` (pending/fit/fit_with_restrictions/unfit/expired), `valid_from`, `valid_until`, `work_restrictions`, `issued_by`, `issued_at`, `is_current` |
| `medical_exams` | **médica** | empresa | Resultado de EMO (preocupacional, periódico, de retiro…) con aptitud y restricciones; acceso solo médico | `worker_id`, `medical_exam_order_ref`, `exam_type` (pre_employment/periodic/exit/return_to_work/position_change/other), `exam_on`, `clinic_name`, `clinic_ruc`, `examining_doctor_name`, `examining_doctor_cmp`, `protocol_code`, `position_text`, `exams_performed`, `results_summary` [cifrado], `diagnoses` [cifrado], `recommendations` [cifrado], `fitness_result` (pending/fit/fit_with_restrictions/unfit), `work_restrictions`, … |
| `medical_exam_files` | **médica** | empresa | Archivo del EMO (informe, resultados, certificado) en el bucket médico de B2 | `medical_exam_id`, `worker_id`, `file_kind` (full_report/lab_result/imaging/audiometry/spirometry/certificate/consent/other), `bucket`, `object_key`, `original_name` [cifrado], `mime_type`, `size_bytes`, `sha256`, `status` (pending_upload/uploaded/scanning/available/quarantined/deleted), `uploaded_by_user_id`, `retention_until` |

Reglas en base de datos: `status <> 'cancelled' OR cancel_reason IS NOT NULL`; `status <> 'fit_with_restrictions' OR work_restrictions IS NOT NULL`; `valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from`; `fitness_result <> 'fit_with_restrictions' OR work_restrictions IS NOT NULL`; `status <> 'annulled' OR annulled_reason IS NOT NULL`; `size_bytes >= 0`.

#### VMD · 5.5 Vigilancia médica (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `surveillance_programs` | **médica** | empresa | Programa de vigilancia médica ocupacional (por agente o riesgo) | `code`, `name`, `target_risk`, `agent_type_code`, `description`, `protocol`, `target_positions`, `responsible_user_id`, `starts_on`, `ends_on`, `status` (draft/active/closed) |
| `surveillance_cases` | **médica** | empresa | Caso en vigilancia médica de un trabajador | `program_id`, `worker_id`, `opened_on`, `reason` [cifrado], `risk_classification` (low/medium/high), `next_control_on`, `closed_on`, `closure_reason` [cifrado], `assigned_doctor_user_id`, `delegated_to_user_ids`, `status` (open/in_follow_up/closed) |
| `clinical_notes` | **médica** | empresa | Nota clínica ocupacional (cifrada con llave médica); solo médico o delegado del caso | `surveillance_case_id`, `worker_id`, `note_type` (consultation/follow_up/evaluation/referral/other), `noted_at`, `author_user_id`, `subjective` [cifrado], `objective` [cifrado], `assessment` [cifrado], `plan` [cifrado], `diagnoses` [cifrado], `is_confidential`, `amended_note_id` |

#### DES · 5.6 Descansos médicos y ausentismo (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `absences` | principal | empresa | Ausencia o descanso médico (solo fechas, días y tipo general; el detalle va a la base médica) | `worker_id`, `absence_type` (medical_leave/occupational_accident/occupational_disease/maternity/paternity/permission/unjustified/other), `starts_on`, `ends_on`, `days`, `is_work_related`, `sst_event_id`, `certificate_received`, `medical_leave_ref`, `registered_by`, `status` (registered/validated/closed/cancelled) |
| `medical_leave_details` | **médica** | empresa | Detalle médico del descanso (diagnóstico, CITT); la base principal solo guarda fechas en absences | `absence_ref`, `worker_id`, `citt_number` [cifrado], `issuing_entity` (essalud/eps/minsa/private_clinic/other), `issuing_doctor_name` [cifrado], `issuing_doctor_cmp`, `diagnosis_code` [cifrado], `diagnosis_description` [cifrado], `is_work_related`, `starts_on`, `ends_on`, `validated_by_user_id`, `validated_at` |

Reglas en base de datos: `ends_on >= starts_on`; `ends_on >= starts_on`.

#### VUL · 5.7 Grupos vulnerables (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `work_adjustments` | principal | empresa | Adecuación del puesto por condición de vulnerabilidad (sin la condición; esta vive en la base médica) | `worker_id`, `vulnerability_ref`, `adjustment_category` (workload/schedule/position_change/ergonomic/environment/equipment/other), `description`, `new_position_id`, `starts_on`, `ends_on`, `approved_by`, `status` (proposed/active/ended/cancelled) |
| `vulnerability_records` | **médica** | empresa | Condición de vulnerabilidad (gestación, menor de edad, discapacidad…) con medidas; acceso solo médico | `worker_id`, `vulnerability_type` (pregnancy/lactation/minor/disability/chronic_condition/elderly/other), `details` [cifrado], `conadis_registration` [cifrado], `starts_on`, `expected_end_on`, `ends_on`, `risk_assessment` [cifrado], `recommended_measures`, `work_adjustment_ref`, `status` (active/ended) |

Reglas en base de datos: `ends_on IS NULL OR ends_on >= starts_on`; `ends_on IS NULL OR ends_on >= starts_on`.

#### VTA · 5.8 Venta de cursos y pagos (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `external_learners` | principal | empresa | Participante externo (no trabajador): compra cursos o recibe un cupo de una empresa compradora | `user_id`, `document_type` (DNI/CE/PTP/PASSPORT/OTHER), `document_number` [cifrado], `document_number_hash`, `document_last4`, `first_name`, `last_name`, `email`, `phone_e164` [cifrado], `buyer_company_ruc`, `buyer_company_name`, `data_consent_at`, `consent_text_version`, `marketing_consent`, `marketing_consent_at`, `source` (purchase/manual/import/license), … |
| `course_offers` | principal | empresa | Curso a la venta: precio en soles (lo define la empresa), vigencia del acceso, cupo y visibilidad en el catálogo público | `course_id`, `slug`, `title`, `short_description`, `currency`, `list_price`, `sale_price`, `price_includes_igv`, `igv_affectation` (taxed/exempt/unaffected), `sale_starts_at`, `sale_ends_at`, `access_days`, `seats_total`, `seats_sold`, `allows_company_purchase`, `min_company_seats`, … |
| `coupons` | principal | empresa | Cupón de descuento (porcentaje o monto fijo) con vigencia y límite de usos | `code`, `description`, `discount_type` (percent/fixed), `discount_value`, `currency`, `applies_to` (all/offers), `course_offer_ids`, `min_order_total`, `max_redemptions`, `max_redemptions_per_buyer`, `redemptions_count`, `valid_from`, `valid_until`, `is_active` |
| `orders` | principal | empresa | Pedido de compra de cursos (persona o empresa compradora con RUC), totales con IGV e idempotencia | `order_number`, `buyer_type` (person/company), `external_learner_id`, `buyer_document_type` (DNI/CE/PTP/PASSPORT/RUC/OTHER), `buyer_document_number` [cifrado], `buyer_name`, `buyer_email`, `buyer_phone` [cifrado], `buyer_ruc`, `buyer_legal_name`, `buyer_fiscal_address`, `billing_document_type` (receipt/invoice), `coupon_id`, `payment_gateway_id`, `currency`, `subtotal`, … |
| `order_items` | principal | empresa | Línea del pedido: curso individual o cupos (licencias) para una empresa | `order_id`, `course_offer_id`, `course_id`, `description`, `item_type` (individual/license), `quantity`, `unit_price`, `discount_amount`, `subtotal`, `igv_amount`, `total`, `course_license_id` |
| `payment_gateways` | principal | empresa | Pasarela de pagos de la empresa (Culqi, Niubiz, Izipay o Mercado Pago); llaves y secreto de webhook solo como referencia al gestor de secretos | `provider` (culqi/niubiz/izipay/mercadopago), `name`, `mode` (test/production), `public_key`, `secret_key_ref`, `webhook_secret_ref`, `merchant_id`, `allowed_methods`, `settings`, `is_active`, `is_default`, `last_tested_at`, `last_test_status` (ok/failed) |
| `payment_transactions` | principal | empresa · inalterable | Evento de pago recibido de la pasarela (webhook firmado o respuesta de la API), inalterable; único por (pasarela, id externo) | `order_id`, `payment_gateway_id`, `provider` (culqi/niubiz/izipay/mercadopago), `external_id`, `provider_reference`, `kind` (charge/refund/chargeback), `status` (pending/succeeded/failed/refunded/cancelled), `amount`, `currency`, `method` (card/yape/plin/pagoefectivo/transfer/other), `card_brand`, `card_last4`, `gateway_token_ref`, `cip_code`, `payload`, `payload_sha256`, … |
| `course_licenses` | principal | empresa | Cupos (licencias) de un curso comprados por una empresa para asignarlos a su personal | `order_item_id`, `course_id`, `course_offer_id`, `buyer_ruc`, `buyer_legal_name`, `buyer_contact_email`, `manager_user_id`, `license_code`, `seats_purchased`, `seats_used`, `access_days`, `expires_at`, `status` (active/exhausted/expired/voided) |
| `license_assignments` | principal | empresa | Asignación de un cupo de licencia a un participante externo (genera su matrícula) | `course_license_id`, `external_learner_id`, `assigned_by`, `assigned_at`, `enrollment_id`, `status` (assigned/consumed/revoked), `revoked_at`, `revoke_reason` |
| `sales_documents` | principal | empresa | Comprobante electrónico de la venta (boleta, factura, nota de crédito o débito); proveedor de emisión por definir | `order_id`, `document_type` (receipt/invoice/credit_note/debit_note), `series`, `number`, `issue_date`, `related_document_id`, `note_reason_code`, `customer_document_type` (DNI/CE/RUC/PASSPORT/OTHER), `customer_document_number` [cifrado], `customer_name`, `customer_address`, `currency`, `taxable_amount`, `igv_amount`, `total`, `lines`, … |

Reglas en base de datos: `buyer_company_ruc IS NULL OR buyer_company_ruc ~ '^(10\|15\|17\|20)[0-9]{9}$'`; `NOT marketing_consent OR marketing_consent_at IS NOT NULL`; `list_price >= 0 AND (sale_price IS NULL OR (sale_price >= 0 AND sale_price <= list_price))`; `seats_total IS NULL OR (seats_total > 0 AND seats_sold <= seats_total)`; `seats_sold >= 0`; `access_days IS NULL OR access_days > 0`.

### Grupo 6 · Eventos y mejora continua

#### ACC · 6.1 Accidentes, incidentes y enfermedades (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `sst_events` | principal | empresa · offline | Accidente, incidente peligroso, incidente o enfermedad ocupacional (registro y notificación en plazo) | `code`, `event_type` (fatal_accident/disabling_accident/minor_accident/dangerous_incident/incident/occupational_disease), `origin` (app/web/whatsapp/qr), `occurred_at`, `reported_at`, `site_id`, `area_id`, `work_shift_id`, `exact_location`, `latitude`, `longitude`, `description`, `activity_at_time`, `hours_worked_before`, `is_contractor_event`, `contractor_id`, … |
| `event_people` | principal | empresa · offline | Persona afectada, involucrada o testigo del evento con datos del registro R.M. 050-2013-TR | `event_id`, `worker_id`, `contractor_worker_id`, `external_person_name`, `person_role` (injured/affected/ill/witness/involved), `age_at_event`, `seniority_months`, `position_text`, `injury_type`, `body_part`, `injury_nature`, `lost_days`, `citt_days`, `disability_type` (none/total_temporary/partial_temporary/partial_permanent/total_permanent), `medical_attention_place`, `is_fatal`, … |
| `event_notifications` | principal | empresa | Notificación a la autoridad (MTPE/SAT, SUNAFIL, aseguradora) con plazo y alerta de vencimiento | `event_id`, `authority` (mtpe_sat/sunafil/essalud/eps/insurer_sctr/osinergmin/other), `notification_type` (initial/update/final), `legal_parameter_key`, `deadline_at`, `notified_at`, `notification_number`, `channel` (sat_online/in_person/email/other), `receipt_file_id`, `notified_by`, `status` (pending/sent/acknowledged/not_required), `notification_overdue`, `notes` |

Reglas en base de datos: `reported_at >= occurred_at - interval '1 day'`; `status <> 'discarded' OR discarded_reason IS NOT NULL`; `NOT is_contractor_event OR contractor_id IS NOT NULL`; `worker_id IS NOT NULL OR contractor_worker_id IS NOT NULL OR external_person_name IS NOT NULL`; `status NOT IN ('sent','acknowledged') OR notified_at IS NOT NULL`.

#### INV · 6.2 Investigación de causas (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `investigations` | principal | empresa | Investigación del evento (árbol de causas, SCAT…) con aprobación del comité | `event_id`, `code`, `method` (cause_tree/scat/five_whys/fishbone/other), `started_on`, `due_on`, `summary`, `immediate_causes`, `basic_causes`, `conclusions`, `lessons_learned`, `report_file_id`, `committee_meeting_id`, `approved_by`, `approved_at`, `ai_assisted`, `ai_suggestion_id`, … |
| `investigation_team` | principal | empresa | Integrante del equipo investigador | `investigation_id`, `worker_id`, `user_id`, `external_name`, `role` (leader/member/committee_representative/worker_representative/expert/witness), `signature_id` |
| `investigation_causes` | principal | empresa | Causa identificada (árbol: inmediata, básica, raíz, falta de control) | `investigation_id`, `parent_cause_id`, `cause_level` (immediate/basic/root/lack_of_control), `cause_category` (unsafe_act/unsafe_condition/personal_factor/job_factor/management_system), `scat_code`, `description`, `sort_order`, `corrective_action_id`, `ai_suggestion_id` |

Reglas en base de datos: `NOT ai_assisted OR ai_suggestion_id IS NOT NULL`; `worker_id IS NOT NULL OR user_id IS NOT NULL OR external_name IS NOT NULL`.

#### ACR · 6.3 Acciones correctivas (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `corrective_actions` | principal | empresa | Acción correctiva o preventiva con responsable, plazo y verificación de cierre | `code`, `source_type`, `source_id`, `action_type` (corrective/preventive/improvement), `control_hierarchy` (elimination/substitution/engineering/administrative/signage/ppe), `title`, `description`, `site_id`, `area_id`, `responsible_user_id`, `responsible_worker_id`, `contractor_id`, `due_on`, `priority` (low/medium/high/critical), `status` (open/in_progress/pending_verification/verified/overdue/cancelled), `completed_at`, … |
| `action_evidences` | principal | empresa · offline | Evidencia de ejecución de una acción correctiva | `corrective_action_id`, `evidence_type` (photo/document/record/other), `description`, `file_id`, `submitted_by`, `submitted_at` |

Reglas en base de datos: `responsible_user_id IS NOT NULL OR responsible_worker_id IS NOT NULL OR contractor_id IS NOT NULL`; `verified_by IS NULL OR responsible_user_id IS NULL OR verified_by <> responsible_user_id`; `status <> 'verified' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL)`; `status <> 'cancelled' OR cancel_reason IS NOT NULL`.

#### AUI · 6.4 Auditorías (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `audits` | principal | empresa | Auditoría interna o externa del SG-SST (registro obligatorio) | `code`, `audit_type` (internal/external/legal_mandatory/certification/client/supplier), `standard` (ley_29783/iso_45001/iso_9001/iso_14001/integrated/other), `scope`, `criteria`, `site_id`, `starts_on`, `ends_on`, `lead_auditor_name`, `auditor_registration`, `audit_firm`, `team`, `external_auditor_membership_id`, `report_file_id`, `conclusions`, `status` (planned/in_progress/report_draft/completed/closed/cancelled) |
| `audit_checklist_items` | principal | empresa | Ítem verificado en la auditoría | `audit_id`, `clause_code`, `requirement`, `result` (conforming/minor_nc/major_nc/observation/opportunity/not_applicable/pending), `evidence`, `file_id`, `sort_order` |
| `audit_findings` | principal | empresa | Hallazgo de auditoría (no conformidad, observación, oportunidad) | `audit_id`, `checklist_item_id`, `finding_type` (major_nc/minor_nc/observation/opportunity), `clause_code`, `description`, `root_cause`, `due_on`, `corrective_action_id`, `status` (open/action_assigned/resolved/dismissed) |

#### EST · 6.5 Estadísticas SST (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `man_hours` | principal | empresa | Horas-hombre trabajadas por mes y sede (base de los índices) | `period_year`, `period_month`, `site_id`, `worker_count`, `contractor_worker_count`, `hours_worked`, `contractor_hours`, `source` (manual/import/payroll_api/calculated), `bulk_import_id`, `notes` |
| `monthly_statistics` | principal | empresa | Estadística mensual: índices de frecuencia, gravedad y accidentabilidad (factor por parámetro) | `period_year`, `period_month`, `site_id`, `worker_count`, `hours_worked`, `accidents_fatal`, `accidents_disabling`, `accidents_minor`, `dangerous_incidents`, `incidents`, `occupational_diseases`, `lost_days`, `frequency_index`, `severity_index`, `accident_rate_index`, `frequency_factor`, … |

Reglas en base de datos: `hours_worked >= 0`.

#### RVD · 6.6 Revisión por la dirección (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `management_reviews` | principal | empresa | Revisión por la dirección (informe anual a gerencia) | `year`, `review_on`, `title`, `inputs`, `participants`, `conclusions`, `policy_changes_needed`, `report_file_id`, `approved_by`, `approved_at`, `status` (planned/in_progress/completed/closed) |
| `management_review_outputs` | principal | empresa | Decisión o salida de la revisión por la dirección | `review_id`, `output_type` (decision/improvement/resource/objective_change/policy_change/other), `description`, `responsible_user_id`, `due_on`, `corrective_action_id`, `status` (open/in_progress/done/cancelled) |

#### REG · 6.7 Registros obligatorios (plan Esencial, fase 1)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `register_exports` | principal | empresa | Exportación de los registros obligatorios en formato R.M. 050-2013-TR | `register_type`, `period_from`, `period_to`, `site_id`, `format` (pdf/xlsx/zip), `template_version`, `filters`, `status` (queued/generating/ready/failed/expired), `file_id`, `sha256`, `rows_count`, `requested_by`, `requested_at`, `generated_at`, `error_message` |

Reglas en base de datos: `period_to >= period_from`.

### Grupo 7 · Contratistas y terceros

#### CTR · 7.1 Acreditación de contratistas (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `contractors` | principal | empresa | Empresa contratista o proveedora | `ruc`, `legal_name`, `trade_name`, `contact_name`, `contact_email` [cifrado], `contact_phone` [cifrado], `services_description`, `risk_level` (low/medium/high/very_high), `status` (active/suspended/blocked/inactive) |
| `contractor_workers` | principal | empresa | Trabajador de contratista | `contractor_id`, `document_type` (DNI/CE/PTP/PASSPORT/OTHER), `document_number` [cifrado], `document_number_hash`, `document_last4`, `first_name`, `paternal_last_name`, `maternal_last_name`, `position_text`, `phone_e164` [cifrado], `photo_file_id`, `emo_fitness` (fit/fit_with_restrictions/unfit/pending/unknown), `emo_valid_until`, `access_enabled`, `status` (active/inactive/blocked) |
| `contractor_requirements` | principal | empresa | Requisito documental exigido a contratistas o a sus trabajadores | `applies_to` (company/worker), `requirement_code`, `name`, `description`, `document_kind`, `is_mandatory`, `validity_months`, `risk_levels`, `service_types`, `is_active` |
| `contractor_documents` | principal | empresa | Documento presentado por la contratista con revisión y vigencia | `contractor_id`, `contractor_worker_id`, `requirement_id`, `document_kind`, `file_id`, `issued_on`, `expires_on`, `uploaded_by`, `reviewed_by`, `reviewed_at`, `review_comments`, `status` (pending/submitted/approved/observed/rejected/expired) |
| `contractor_accreditations` | principal | empresa | Acreditación de la contratista para un servicio y sede | `contractor_id`, `contract_reference`, `service_description`, `site_id`, `starts_on`, `ends_on`, `expires_on`, `status` (draft/submitted/under_review/observed/accredited/expiring/expired/suspended/rejected), `submitted_at`, `reviewed_by`, `reviewed_at`, `approved_by`, `approved_at`, `observations`, `rejection_reason`, `suspended_reason` |

Reglas en base de datos: `approved_by IS NULL OR reviewed_by IS NULL OR approved_by <> reviewed_by`.

#### SCT · 7.2 Control de SCTR (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `sctr_policies` | principal | empresa | Póliza SCTR (salud y pensión) propia o de contratista | `contractor_id`, `coverage_kind` (health/pension), `insurer_type` (essalud/eps/private_insurer/onp), `insurer_name`, `insurer_ruc`, `policy_number`, `starts_on`, `ends_on`, `file_id`, `status` (active/expiring/expired/cancelled) |
| `sctr_coverages` | principal | empresa | Cobertura SCTR de un trabajador en una póliza | `sctr_policy_id`, `worker_id`, `contractor_worker_id`, `covered_from`, `covered_until`, `constancy_number`, `file_id`, `status` (covered/not_covered/expired) |

Reglas en base de datos: `ends_on >= starts_on`; `(worker_id IS NOT NULL) <> (contractor_worker_id IS NOT NULL)`; `covered_until >= covered_from`.

#### ING · 7.3 Ingreso de terceros por QR (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `access_points` | principal | empresa | Punto de control de acceso (portería, torniquete) | `site_id`, `code`, `name`, `point_type` (main_gate/vehicle_gate/turnstile/area_access/other), `requires_credential`, `allows_visitors`, `latitude`, `longitude`, `is_active` |
| `access_events` | principal | empresa · inalterable · offline | Evento de ingreso o salida (habilitado / no habilitado), inalterable | `access_point_id`, `occurred_at`, `direction` (in/out), `subject_type` (worker/contractor_worker/visitor/vehicle), `worker_id`, `contractor_worker_id`, `credential_id`, `visitor_name`, `visitor_document_masked`, `result` (granted/denied/overridden), `deny_reasons`, `guard_user_id`, `override_reason` |

Reglas en base de datos: `result <> 'overridden' OR override_reason IS NOT NULL`.

#### HOM · 7.4 Homologación de proveedores (plan Complemento, fase 5)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `vendor_evaluation_templates` | principal | mixta (global + empresa) | Plantilla de homologación de proveedores con criterios ponderados | `code`, `name`, `criteria`, `passing_score`, `version_number`, `status` (draft/active/retired) |
| `vendor_evaluations` | principal | empresa | Evaluación y calificación de un proveedor | `template_id`, `contractor_id`, `vendor_ruc`, `vendor_name`, `evaluated_on`, `evaluator_user_id`, `scores`, `total_score`, `rating` (approved/conditionally_approved/rejected), `valid_until`, `file_id`, `status` (draft/completed/approved/expired) |

### Grupo 8 · Diferenciadores e integraciones

#### SUN · 8.1 Modo Inspección SUNAFIL (plan Profesional, fase 2)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `self_diagnostics` | principal | empresa | Autodiagnóstico frente a los criterios de inspección de SUNAFIL | `title`, `diagnostic_on`, `checklist_version`, `items`, `score_pct`, `critical_gaps`, `generated_actions`, `performed_by`, `status` (draft/completed) |
| `inspection_dossiers` | principal | empresa | Expediente para inspección SUNAFIL por trabajador y periodo | `title`, `request_type` (sunafil_inspection/self_review/client_audit/other), `inspection_order_number`, `inspector_name`, `requirement_file_id`, `appearance_at`, `period_from`, `period_to`, `site_id`, `scope_modules`, `scope`, `status` (queued/building/ready/ready_with_gaps/failed/archived), `gaps`, `built_at`, `zip_file_id`, `index_file_id`, … |
| `dossier_items` | principal | empresa | Documento o registro incluido (o faltante) en el expediente | `dossier_id`, `worker_id`, `section_code`, `register_type`, `title`, `source_module`, `source_type`, `source_id`, `file_id`, `status` (included/missing/expired/not_applicable), `gap_description`, `sort_order` |
| `dossier_share_links` | principal | empresa | Link compartido del expediente con vencimiento, límite de vistas y revocación | `dossier_id`, `token_hash`, `token_last4`, `recipient_name`, `recipient_email` [cifrado], `purpose`, `expires_at`, `max_views`, `view_count`, `allow_download`, `password_hash`, `status` (active/expired/exhausted/revoked), `revoked_at`, `revoked_by`, `last_accessed_at` |

Reglas en base de datos: `period_to >= period_from`; `max_views IS NULL OR view_count <= max_views`; `status <> 'revoked' OR revoked_at IS NOT NULL`.

#### OFF · 8.2 App móvil offline (plan Complemento, fase 3)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `devices` | principal | empresa | Dispositivo registrado para uso offline (revocable) | `user_id`, `worker_id`, `device_uid`, `platform` (android/ios/web_pwa/windows/other), `model`, `app_version`, `push_token` [cifrado], `public_key`, `pin_bound`, `registered_at`, `last_sync_at`, `status` (active/revoked/lost/wiped), `revoked_at`, `revoked_by`, `revoke_reason` |
| `sync_batches` | principal | empresa | Lote de sincronización recibido desde un dispositivo (idempotente) | `user_id`, `batch_uuid`, `received_at`, `processed_at`, `app_version`, `payload_sha256`, `payload_file_id`, `operations_count`, `applied_count`, `conflict_count`, `rejected_count`, `error_summary`, `status` (received/processing/applied/partially_applied/rejected) |
| `sync_conflicts` | principal | empresa | Conflicto de sincronización registrado (no se pisa; se resuelve) | `operation_idempotency_key`, `entity_type`, `entity_id`, `operation` (create/update/delete), `client_version`, `server_version`, `client_payload`, `server_snapshot`, `conflict_type` (version_mismatch/deleted_on_server/validation_failed/permission_denied/duplicate/state_transition), `resolution` (pending/client_wins/server_wins/merged/discarded), `resolved_by`, `resolved_at`, `resolution_notes` |

Reglas en base de datos: `resolution = 'pending' OR resolved_at IS NOT NULL`.

#### IAA · 8.3 Asistente IA (plan Complemento, fase 3)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `ai_requests` | principal | empresa | Solicitud al asistente IA (entrada redactada, sin datos médicos) | `user_id`, `feature` (iperc_suggestion/investigation_guide/document_reading/training_quiz/report_classification/other), `module_code`, `subject_type`, `subject_id`, `provider`, `model`, `prompt_template_code`, `prompt_version`, `input_redacted`, `contains_medical_data`, `input_summary`, `input_tokens`, `output_tokens`, `latency_ms`, `quota_period`, … |
| `ai_suggestions` | principal | empresa | Sugerencia de la IA pendiente de revisión humana (la IA nunca aplica cambios) | `ai_request_id`, `suggestion_type`, `target_type`, `target_id`, `payload`, `rationale`, `confidence`, `status` (pending/accepted/partially_accepted/rejected/expired), `reviewed_by`, `reviewed_at`, `review_notes`, `applied_entity_type`, `applied_entity_ids`, `expires_at` |

Reglas en base de datos: `status IN ('pending','expired') OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)`; `confidence IS NULL OR confidence BETWEEN 0 AND 1`.

#### CON · 8.4 Modo consultora y marca blanca (plan Consultora, fase 3)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `white_label_settings` | principal | especial | Marca blanca de la cuenta consultora (logo, colores, dominio, remitente) | `account_id`, `brand_name`, `logo_file_key`, `primary_color`, `secondary_color`, `subdomain`, `custom_domain`, `domain_verified_at`, `email_sender_name`, `email_sender_address`, `email_domain_verified_at`, `show_powered_by`, `pdf_footer_text`, `status` (draft/active/disabled) |
| `consultant_assignments` | principal | empresa | Consultor asignado a una empresa cliente | `consultancy_account_id`, `consultant_user_id`, `membership_id`, `is_lead`, `starts_on`, `ends_on`, `status` (active/ended/revoked), `notes` |
| `client_invitations` | principal | especial | Invitación de la consultora a una empresa con cuenta propia (company_id = empresa invitada) | `company_id`, `consultancy_account_id`, `target_ruc`, `invited_email`, `token_hash`, `message`, `access_level` (full_management/read_only), `status` (sent/accepted/declined/expired/revoked/cancelled), `sent_at`, `expires_at`, `responded_at`, `responded_by`, `revoked_at` |

Reglas en base de datos: `primary_color IS NULL OR primary_color ~ '^#[0-9A-Fa-f]{6}$'`; `secondary_color IS NULL OR secondary_color ~ '^#[0-9A-Fa-f]{6}$'`.

#### PSE · 8.5 Paquetes sectoriales (plan Complemento, fase 3)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `sector_packs` | principal | catálogo global | Paquete sectorial (construcción, minería, agroindustria, manufactura, retail, oficinas, clínicas) | `code`, `name`, `sector` (construction/mining/agroindustry/manufacturing/retail/office/clinic), `description`, `legal_references`, `version_number`, `status` (draft/published/retired) |
| `sector_pack_items` | principal | catálogo global | Elemento del paquete (peligros, plantillas, permisos, temas de capacitación, requisitos) | `sector_pack_id`, `item_type` (hazard/inspection_template/permit_type/training_topic/document_template/legal_requirement/ppe_item/kpi/credential_requirement), `code`, `name`, `payload`, `sort_order` |
| `company_sector_packs` | principal | empresa | Paquete sectorial aplicado a la empresa | `sector_pack_id`, `pack_version`, `applied_at`, `applied_by`, `items_applied`, `status` (active/removed) |

#### ISO · 8.6 Sistema integrado ISO (plan Complemento, fase 5)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `iso_standards` | principal | catálogo global | Normas ISO del sistema integrado (45001, 9001, 14001) | `code` (ISO45001/ISO9001/ISO14001), `name`, `version_year`, `description` |
| `iso_clauses` | principal | catálogo global | Cláusula o requisito de la norma ISO con mapeo a módulos | `iso_standard_id`, `clause_code`, `title`, `parent_clause_code`, `description`, `mapped_modules`, `sort_order` |
| `iso_evidences` | principal | empresa | Evidencia de cumplimiento de una cláusula ISO en la empresa | `iso_clause_id`, `description`, `source_module`, `source_type`, `source_id`, `document_id`, `file_id`, `responsible_user_id`, `last_reviewed_on`, `status` (not_started/in_progress/implemented/verified/not_applicable) |

#### AMB · 8.7 Medio ambiente (SSOMA) (plan Complemento, fase 5)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `environmental_aspects` | principal | empresa | Aspecto e impacto ambiental (matriz de aspectos) | `site_id`, `area_id`, `activity`, `aspect`, `impact`, `condition` (normal/abnormal/emergency), `legal_requirement_id`, `severity_value`, `probability_value`, `significance_score`, `is_significant`, `controls`, `status` (active/obsolete) |
| `waste_records` | principal | empresa | Registro de generación y disposición de residuos | `site_id`, `waste_type` (municipal/non_hazardous/hazardous/recyclable/electronic/biocontaminated/other), `description`, `quantity`, `unit` (kg/t/m3/l/units), `generated_on`, `disposal_method`, `eo_rs_name`, `eo_rs_ruc`, `manifest_number`, `manifest_file_id`, `status` (stored/transferred/disposed) |

#### API · 8.8 API e integraciones (plan Corporativo, fase 4)

| Tabla | Base | Tipo | Propósito | Columnas clave |
|---|---|---|---|---|
| `api_clients` | principal | empresa | Cliente de API para integraciones (planillas, ERP, aulas virtuales) | `name`, `client_key`, `secret_hash`, `scopes`, `allowed_ips`, `rate_limit_per_minute`, `expires_at`, `last_used_at`, `status` (active/revoked) |
| `webhooks` | principal | empresa | Suscripción de webhook saliente a eventos | `api_client_id`, `name`, `target_url`, `event_codes`, `secret` [cifrado], `is_active`, `failure_count`, `disabled_at` |
| `webhook_deliveries` | principal | empresa | Intento de entrega de un webhook (sin datos sensibles en el cuerpo) | `webhook_id`, `event_code`, `event_id`, `payload_sha256`, `request_body`, `attempt`, `response_status`, `response_ms`, `status` (pending/delivered/failed/abandoned), `next_retry_at`, `delivered_at`, `error` |

<!-- MODULOS:FIN -->
