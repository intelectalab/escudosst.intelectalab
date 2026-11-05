# 05 · Roles y permisos

Fuente de roles: `spec/catalogo.json` (17 roles). Permisos con formato `module.resource.action` (p. ej. `ipc.iperc_rows.update`). El detalle fino por recurso está en cada `docs/modulos/M-<COD>.md`; esta matriz es el nivel máximo por módulo.

## 1. Los 17 roles

| Abrev. | Código | Rol | Ámbito | Descripción |
|---|---|---|---|---|
| PO | `platform_owner` | Dueño de la plataforma | Plataforma | Planes, cuentas, cobros, catálogos globales. No ve datos de empresas salvo acceso de soporte autorizado y registrado |
| PS | `platform_support` | Soporte de la plataforma | Plataforma | Atiende tickets. Acceso temporal a una empresa solo con autorización del administrador de cuenta |
| AC | `account_admin` | Administrador de cuenta | Cuenta | Dueño de la suscripción: usuarios, plan, facturación, empresas |
| CO | `consultant` | Consultor SST | Empresas asignadas | Gestiona varias empresas cliente desde el panel multi-cliente |
| JS | `sst_manager` | Jefe SST / Responsable del SG-SST | Empresa | Gestiona todo el SG-SST |
| SS | `sst_supervisor` | Supervisor SST | Empresa o sede | Figura legal con menos de 20 trabajadores, o supervisor de campo |
| CM | `committee_member` | Miembro del Comité SST | Empresa | Aprueba programas, revisa actas, acuerdos, inspecciones y accidentes |
| GE | `management` | Gerencia | Empresa | Indicadores, revisión por la dirección, aprobación de política y presupuesto |
| JA | `area_lead` | Jefe de área / línea | Sede/área | Inspecciones, ATS, charlas, PETAR, cierra acciones de su área |
| MO | `occupational_doctor` | Médico ocupacional | Empresa | Único con acceso completo a datos médicos, con clave adicional |
| PE | `health_staff` | Personal de salud | Empresa | Agenda EMO y descansos; acceso médico limitado |
| RH | `hr` | Recursos Humanos | Empresa | Trabajadores, puestos, ausentismo. Ve aptitud, no diagnósticos |
| TR | `worker` | Trabajador | Lo suyo | PWA: firma, capacitaciones, fotocheck, reportes, EPP, sus resultados médicos |
| CT | `contractor_admin` | Administrador de contratista | Su contratista | Portal: documentos, trabajadores, SCTR, solicitud de acreditación |
| AE | `external_auditor` | Auditor externo | Periodo y módulos | Lectura temporal acotada, con vencimiento |
| CP | `trainer` | Capacitador | Cursos y sesiones asignados | Dicta y gestiona los cursos que se le asignan (contenidos, evaluaciones, calificaciones); matricula solo si el administrador lo permite; registra su disponibilidad |
| PX | `external_learner` | Participante externo | Lo suyo (y sus cupos asignados si representa a una empresa compradora) | No es trabajador de la empresa: compra o recibe un curso, lo cursa, rinde evaluaciones y descarga su certificado |

Nota: en esta tabla `PE` es Personal de salud; el Participante externo usa `PX`.

## 2. Niveles de la matriz

| Símbolo | Nivel | Significa |
|---|---|---|
| — | Sin acceso | El módulo no aparece en el menú. La API responde 404/403 |
| L | Lectura | Ver y exportar. No crea ni modifica |
| P | Propio | Solo registros propios o donde participa (trabajador: los suyos; contratista: los de su empresa; jefe de área: los de su alcance para firma/participación). Puede crear lo que el módulo permite al rol (reportes, firmas, documentos propios) |
| G | Gestión | Crear, editar, enviar a revisión, cerrar dentro de su alcance |
| A | Aprueba | Gestión + aprobar, publicar, declarar vigente o rechazar |

Reglas generales:
- La matriz se limita además por **plan** (feature flags) y por **alcance** (`membership_scopes`).
- Un rol con «G» en un módulo de aprobación no aprueba lo que él mismo creó cuando el módulo exige segregación (IPERC, PETAR, investigación, acreditación).
- El dueño de la plataforma y soporte tienen «—» en módulos de negocio: su acceso de soporte es una membresía temporal separada (ver §4).
- El auditor externo tiene «L» como máximo, y solo en los módulos y el periodo que le habilitan.

## 3. Matriz rol × módulo

Abreviaturas de columnas según §1.

| Módulo | PO | PS | AC | CO | JS | SS | CM | GE | JA | MO | PE | RH | TR | CT | AE | CP | PX |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| TEN | — | — | G | G | G | L | L | L | L | L | L | G | — | — | L | — | — |
| USR | — | — | G | G | G | L | — | — | — | — | — | L | — | — | — | — | — |
| TRB | — | — | L | G | G | G | — | L | L | L | L | G | P | — | L | — | — |
| DOC | — | — | L | G | A | G | L | L | L | L | L | L | P | P | L | — | — |
| NOT | — | — | G | G | G | G | P | P | P | P | P | P | P | P | — | P | P |
| DSH | — | — | L | G | G | G | L | L | L | L | L | L | — | — | L | — | — |
| FIR | — | — | — | G | G | G | P | P | P | P | P | P | P | P | L | P | — |
| AUD | L | L | L | L | L | — | — | — | — | — | — | — | — | — | L | — | — |
| SUS | G | L | G | L | — | — | — | — | — | — | — | — | — | — | — | — | — |
| WEB | A | G | P | — | — | — | — | — | — | — | — | — | P | — | — | — | P |
| LBA | — | — | L | G | G | G | L | L | L | — | — | L | — | — | L | — | — |
| POL | — | — | L | G | G | G | L | A | L | — | — | L | P | — | L | — | — |
| MRL | — | — | L | G | G | G | L | L | — | — | — | L | — | — | L | — | — |
| PLA | — | — | L | G | G | G | A | A | L | — | — | L | — | — | L | — | — |
| PAS | — | — | L | G | G | G | A | L | P | — | — | L | — | — | L | — | — |
| PCA | — | — | L | G | G | G | A | L | L | — | — | G | — | — | L | — | — |
| RIS | — | — | L | G | G | G | A | A | L | — | — | G | P | — | L | — | — |
| COM | — | — | L | G | G | G | G | L | L | — | — | L | P | — | L | — | — |
| PRE | — | — | L | G | G | G | L | A | — | — | — | — | — | — | L | — | — |
| IPC | — | — | L | G | A | G | L | L | G | — | — | L | P | — | L | — | — |
| MAP | — | — | L | G | G | G | L | L | L | — | — | L | P | — | L | — | — |
| MAG | — | — | L | G | G | G | L | L | L | L | — | L | — | — | L | — | — |
| PSI | — | — | — | G | G | G | L | L | L | G | L | L | P | — | L | — | — |
| PET | — | — | L | G | A | G | L | L | G | — | — | L | P | — | L | — | — |
| MOC | — | — | L | G | A | G | L | A | G | — | — | L | — | — | L | — | — |
| INS | — | — | L | G | G | G | G | L | G | — | — | L | — | — | L | — | — |
| ACT | — | — | L | G | G | G | L | L | G | — | — | L | P | P | L | — | — |
| CH5 | — | — | L | G | G | G | L | L | G | — | — | L | P | — | L | — | — |
| ATS | — | — | L | G | G | G | L | L | G | — | — | L | P | P | L | — | — |
| PTR | — | — | L | G | A | A | L | L | A | — | — | L | P | P | L | — | — |
| OBS | — | — | L | G | G | G | G | L | G | — | — | L | — | — | L | — | — |
| EPP | — | — | L | G | G | G | L | L | G | — | — | L | P | — | L | — | — |
| EQE | — | — | L | G | G | G | L | L | G | — | — | L | — | — | L | — | — |
| SIM | — | — | L | G | G | G | L | L | G | — | — | L | P | — | L | — | — |
| CAP | — | — | L | G | G | G | L | L | L | — | — | G | P | P | L | P | P |
| AUL | — | — | G | G | A | G | L | L | L | — | — | G | P | L | L | G | P |
| FOT | — | — | L | G | A | G | L | L | L | — | — | G | P | P | L | — | — |
| EMO | — | — | L | L | L | L | — | — | L | G | G | L | P | — | L | — | — |
| VMD | — | — | — | — | — | — | — | — | — | G | L | — | P | — | — | — | — |
| DES | — | — | — | L | L | — | — | L | L | G | G | G | P | — | — | — | — |
| VUL | — | — | — | — | L | — | — | — | L | G | L | L | — | — | — | — | — |
| VTA | — | — | A | L | G | — | — | L | — | — | — | — | — | — | — | L | P |
| ACC | — | — | L | G | G | G | L | L | G | L | L | L | P | P | L | — | — |
| INV | — | — | L | G | A | G | A | L | G | — | — | L | — | — | L | — | — |
| ACR | — | — | L | G | G | G | L | L | G | — | — | L | — | P | L | — | — |
| AUI | — | — | L | G | G | G | L | L | L | — | — | L | — | — | L | — | — |
| EST | — | — | L | G | G | G | L | L | L | — | — | G | — | — | L | — | — |
| RVD | — | — | L | G | G | G | L | A | — | — | — | — | — | — | L | — | — |
| REG | — | — | L | G | G | G | L | L | L | — | — | L | — | — | L | — | — |
| CTR | — | — | L | G | A | G | L | L | L | — | — | — | — | P | L | — | — |
| SCT | — | — | L | G | G | G | L | L | L | — | — | G | — | P | L | — | — |
| ING | — | — | L | G | G | G | L | L | G | — | — | L | — | P | L | — | — |
| HOM | — | — | L | G | A | G | L | L | L | — | — | — | — | P | L | — | — |
| SUN | — | — | L | G | G | G | — | L | — | — | — | L | — | — | — | — | — |
| OFF | — | — | G | G | G | G | L | L | G | — | — | L | P | — | — | — | — |
| IAA | — | — | G | G | G | G | — | — | G | — | — | — | — | — | — | — | — |
| CON | L | — | G | G | L | — | — | — | — | — | — | — | — | — | — | — | — |
| PSE | G | — | L | G | G | G | — | L | — | — | — | — | — | — | L | — | — |
| ISO | — | — | L | G | G | G | L | A | L | — | — | L | — | — | L | — | — |
| AMB | — | — | L | G | G | G | L | L | G | — | — | L | — | — | L | — | — |
| API | — | — | G | L | L | — | — | — | — | — | — | — | — | — | — | — | — |

Notas de la matriz:
- **EMO (L) para AC, JS, SS, CO, RH, JA, AE:** solo programación, vigencia y `fitness_status` (base principal). Nunca `medical_exams` ni `medical_exam_files`.
- **DES (L/G) para RH, JS, GE, JA, CO:** solo `absences` (fechas, días, tipo general). El detalle `medical_leave_details` es solo MO y PE.
- **VUL (L) para RH, JS, JA:** solo `work_adjustments` (la adecuación del puesto). `vulnerability_records` es solo MO.
- **PSI:** RH ve resultados agregados por área con mínimo de respuestas (parámetro) para proteger el anonimato. Nunca respuestas individuales.
- **FOT (A) para JS:** emite y revoca credenciales.
- **PTR (A):** cada tipo de permiso define su cadena de aprobadores; la matriz solo dice quién puede estar en ella.
- **ACT:** cualquier persona puede reportar por QR o WhatsApp sin cuenta; eso no es un rol.
- **AUL (A) para JS:** el Jefe SST actúa como administrador del aula: es el único que crea, publica y versiona cursos. **CP (G)** solo en sus cursos asignados y según `trainer_permissions`.
- **AUL y VTA** se limitan además por la activación por empresa (`disabled_by_company`, ver `03` §7.1): con el módulo desactivado, nadie lo ve en el menú.
- **VTA (A) para AC:** activa la venta, configura la pasarela y los comprobantes. **JS (G):** ofertas, cupones, cupos, matrícula manual y reembolsos. **PX (P):** sus pedidos y comprobantes; si representa a una empresa compradora, asigna sus cupos.
- **CAP (P) para PX:** solo descarga sus certificados; no cuenta en la matriz por cargo ni en indicadores legales.
- **Verificación pública de certificados y catálogo público de cursos:** cualquier persona sin cuenta; no es un rol.
- **WEB (A) para PO:** administra el sitio y su CMS, publica páginas, artículos y versiones legales, responde y prorroga reclamos y exporta el reporte para Indecopi. **PS (G):** atiende las solicitudes de demo asignadas, carga testimonios para aprobación y lee reclamos para preparar la respuesta; no responde, no prorroga ni cierra. **AC (P):** acepta en nombre de la cuenta el Encargo de tratamiento y el SLA, ve su historial y puede enviar un testimonio de su empresa con consentimiento. **TR (P):** representa a todo usuario autenticado, que acepta las versiones vigentes de Términos y Privacidad al iniciar sesión. **PX (P):** representa al visitante o consumidor sin cuenta (el catálogo no tiene rol «visitante»): cookies, hoja de reclamación, seguimiento por código, solicitud de demo y herramientas de captación (calculadora, autodiagnóstico, recursos, «¿Te sirvió?», partners, boletín y avisos de estado), por endpoints públicos sin sesión. **Todo usuario autenticado** consulta la ayuda contextual de su pantalla. Detalle en §4.11.

## 4. Reglas especiales

### 4.1 Datos médicos
- Solo `occupational_doctor` (completo) y `health_staff` (limitado: agenda, registro de descansos, carga de archivos; sin notas clínicas de vigilancia salvo que el médico lo delegue por caso) leen la base médica.
- Toda lectura exige **re-autenticación** (clave adicional o MFA) y genera un token médico de corta vigencia ligado a la sesión (parámetro).
- Toda lectura y escritura queda en `sensitive_access_logs` (base principal: quién, qué trabajador, qué recurso, motivo, IP) y en el registro de acceso de la base médica.
- Ni `account_admin`, ni `sst_manager`, ni `platform_owner`, ni `platform_support` leen datos médicos. No existe un «superusuario» médico.
- Exportaciones médicas: solo el médico, con marca de agua con su nombre y fecha.

### 4.2 Recursos Humanos
- Ve `fitness_status`: apto / apto con restricciones / no apto / pendiente + vigencia.
- En «apto con restricciones» ve la restricción laboral redactada por el médico en términos de trabajo («no levantar más de X kg»), nunca el diagnóstico.

### 4.3 Trabajador
- Ve solo lo suyo: sus capacitaciones, constancias, EPP entregados, fotocheck, firmas pendientes, reportes que hizo, sus resultados de EMO (con re-autenticación), documentos que le toca leer y firmar, charlas, simulacros.
- Puede reportar actos y condiciones (también de forma anónima).
- Puede votar en la elección del comité.
- No ve datos de otros trabajadores.

### 4.4 Administrador de contratista
- Solo su empresa contratista (`contractors.id`) y sus trabajadores (`contractor_workers`).
- No ve trabajadores ni datos de la empresa principal, salvo los requisitos que debe cumplir y las acciones correctivas asignadas a su empresa.
- Su RLS es doble: `company_id` de la empresa principal + filtro por `contractor_id` en la aplicación y en la política.

### 4.5 Auditor externo
- Membresía temporal con `expires_at` obligatorio.
- `membership_scopes` define módulos y periodo (fechas desde/hasta de los registros visibles).
- Solo lectura. Sin datos médicos. Exportaciones con marca de agua.
- Al vencer, el scheduler la desactiva y avisa al jefe SST.

### 4.6 Soporte de plataforma
- Sin acceso por defecto.
- Acceso solo con autorización del administrador de cuenta: membresía temporal, solo lectura por defecto, alcance a una empresa, vencimiento obligatorio.
- Sin datos médicos, nunca.
- Cada acción queda en `audit_logs` con marca `support_session`. El cliente ve en su bitácora quién entró y qué hizo.

### 4.7 Consultor
- Accede solo a las empresas de sus `consultant_assignments`.
- En cada empresa actúa como `consultant` con permisos de gestión similares a `sst_manager`, sin aprobar lo que la ley reserva a la empresa (política: gerencia; programas: comité).
- Sin datos médicos.

### 4.8 Capacitador
- Ve solo los cursos y sesiones que tiene asignados y sus participantes.
- Permisos por defecto (configurables por el administrador del aula en `trainer_permissions`, por empresa, por capacitador y por curso): sube y edita contenidos, gestiona preguntas de sus cursos, califica y ve avance y permanencia; **no matricula** ni da de baja. Crear cursos es solo del administrador del aula.
- Registra su disponibilidad semanal; no valida ni anula certificados.
- Puede recomendar manualmente sus cursos asignados.
- Sin datos médicos.

### 4.9 Participante externo
- Cuenta propia en el portal del participante de la empresa vendedora; no es trabajador (no aparece en `workers`).
- Ve solo sus cursos, certificados, pedidos, comprobantes, datos y consentimientos.
- Si representa a una empresa compradora, ve además a las personas a las que asignó cupos (avance, nota, certificado) y nada más.
- Puede ejercer sus derechos ARCO y revocar el consentimiento de publicidad desde su portal.
- Solo existe en empresas con VTA activo.

### 4.11 Sitio web público y CMS (WEB)
- Las tablas de WEB son globales (sin `company_id`): ningún rol de empresa las administra. Solo `platform_owner` escribe contenido, ajustes legales, versiones de políticas y respuestas a reclamos.
- Permisos del módulo (prefijo `web.`):

| Permiso | PO | PS | AC | Usuario autenticado | Visitante (público) |
|---|---|---|---|---|---|
| `web.site_settings.update`, `web.site_menus.update`, `web.site_redirects.update` | Sí | — | — | — | — |
| `web.site_pages.create` / `.update` / `.publish` | Sí | — | — | — | — |
| `web.site_products.update`, `web.site_faqs.update` | Sí | — | — | — | — |
| `web.site_testimonials.create` | Sí | Sí | Sí (testimonio de su empresa, con consentimiento) | — | — |
| `web.site_testimonials.update` / `.approve` | Sí | — | — | — | — |
| `web.legal_documents.update`, `web.legal_document_versions.create` / `.publish` | Sí | — | — | — | — |
| `web.legal_acceptances.create` | — | — | Sí | Sí | Sí (en formularios y registro) |
| `web.legal_acceptances.view`, `web.cookie_consents.view` | Sí | — | Sí (solo su cuenta, aceptaciones) | — | — |
| `web.cookie_consents.create` | — | — | — | — | Sí |
| `web.consumer_complaints.create`, `web.consumer_complaints.track` | — | — | — | — | Sí (con límite de uso y captcha) |
| `web.consumer_complaints.view`, `web.complaint_events.create` | Sí | Sí | — | — | — |
| `web.consumer_complaints.update` / `.respond` / `.export` | Sí | — | — | — | — |
| `web.demo_requests.create` | — | — | — | — | Sí |
| `web.demo_requests.view` / `.update` | Sí | Sí (asignadas) | — | — | — |
| `web.demo_requests.assign` | Sí | — | — | — | — |
| `web.marketing_leads.create`, `web.fine_calculations.create`, `web.assessment_submissions.create`, `web.resource_downloads.create`, `web.partner_applications.create`, `web.newsletter_subscribers.create` / `.update` (confirmar y darse de baja) | — | — | — | — | Sí (captcha, límite por IP e `Idempotency-Key`) |
| `web.marketing_leads.read` / `.update` | Sí | Sí (asignados) | — | — | — |
| **`web.marketing_leads.assign`** (especial) | Sí | — | — | — | — |
| **`web.marketing_leads.export`** (especial) | Sí | — | — | — | — |
| `web.fine_calculations.read`, `web.assessment_submissions.read`, `web.resource_downloads.read`, `web.newsletter_subscribers.read`, `web.referrals.read` / `.update` | Sí | — | — | — | — |
| `web.assessment_questionnaires.create` / `.update`, `web.assessment_questions.create` / `.update` / `.delete` | Sí | — | — | — | — |
| **`web.assessment_questionnaires.publish`** (especial) | Sí | — | — | — | — |
| `web.resource_items.create` / `.update`, `web.help_categories.create` / `.update`, `web.referral_codes.create` / `.update`, `web.subprocessors.create` / `.update`, `web.status_components.create` / `.update` | Sí | — | — | — | — |
| `web.success_stories.create` | Sí | Sí | — | — | — |
| `web.success_stories.update` | Sí | — | — | — | — |
| **`web.success_stories.approve`** (especial) | Sí | — | — | — | — |
| `web.help_articles.create` / `.update` | Sí | Sí (redacta) | — | — | — |
| **`web.help_articles.publish`** (especial) | Sí | — | — | — | — |
| `web.help_articles.read` (ayuda contextual `GET /me/help`) | — | — | Sí | Sí | Sí (centro de ayuda público, solo publicados) |
| `web.help_article_feedback.create` | — | — | Sí | Sí | Sí |
| `web.help_article_feedback.read`, `web.partner_applications.read` | Sí | Sí | — | — | — |
| **`web.partner_applications.approve`** (especial) | Sí | — | — | — | — |
| `web.newsletter_issues.create` / `.update` | Sí | — | — | — | — |
| **`web.newsletter_issues.send`** (especial) | Sí | — | — | — | — |
| `web.status_incidents.create` / `.update`, `web.status_incident_updates.create` | Sí | Sí (en borrador) | — | — | — |
| **`web.status_incidents.publish`** (especial) | Sí | — | — | — | — |
| `web.site_analytics_daily.read` (resumen y métricas) | Sí | Sí (sin exportar) | — | — | — |
| `web.site_events.read` | Sí | — | — | — | — |
| **`web.site_analytics.export`** (especial; agrupa `site_analytics_daily` y `site_events`) | Sí | — | — | — | — |
| `web.site_events.create` (`POST /public/events`) | — | — | — | — | Sí (solo con consentimiento analítico) |
| `web.demo_booking_settings.update`, `web.demo_hosts.create` / `.update` | Sí | — | — | — | — |
| `web.demo_availability_rules.create` / `.update`, `web.demo_blackouts.create` / `.update` | Sí | Sí (los suyos como anfitrión) | — | — | — |
| `web.demo_availability_rules.delete`, `web.demo_blackouts.delete` | Sí | — | — | — | — |
| `web.demo_meetings.create` / `.update` (reservar, reprogramar o cancelar con el enlace) | — | — | — | — | Sí (captcha, límite por IP e `Idempotency-Key`; token de gestión) |
| `web.demo_meetings.read` / `.update` | Sí | Sí (asignadas) | — | — | — |
| **`web.demo_meetings.assign`** (especial) | Sí | — | — | — | — |
| **`web.demo_meetings.confirm`** (especial) | Sí | Sí (asignadas) | — | — | — |
| `web.chatbot_settings.update` | Sí | — | — | — | — |
| `web.chatbot_intents.create` / `.update` | Sí | Sí (borradores) | — | — | — |
| **`web.chatbot_intents.publish`** (especial) | Sí | — | — | — | — |
| `web.chatbot_conversations.read`, `web.chatbot_messages.read` | Sí | Sí | — | — | — |
| `web.chatbot_conversations.create`, `web.chatbot_messages.create` (widget) | — | — | — | — | Sí (límite por IP) |
| `web.crm_connections.read`, `web.crm_sync_events.read` / `.update` (reintentar, descartar) | Sí | — | — | — | — |
| **`web.crm_connections.manage`** (especial) | Sí | — | — | — | — |
| `web.site_media.create` | Sí | Sí | — | — | — |
| `web.site_media.update` / `.delete`, `web.success_story_media.create` / `.update` / `.delete` | Sí | — | — | — | — |
| `web.campaigns.create` / `.update`, `web.site_popups.create` / `.update`, `web.site_integrations.create` / `.update` | Sí | — | — | — | — |
| **`web.campaigns.publish`**, **`web.site_popups.publish`** (especiales) | Sí | — | — | — | — |
| `web.product_updates.create` / `.update`, `web.webinars.create` / `.update`, `web.webinar_registrations.read` / `.update` (asistencia) | Sí | Sí (borradores y asistencia) | — | — | — |
| **`web.product_updates.publish`**, **`web.webinars.publish`** (especiales) | Sí | — | — | — | — |
| `web.product_updates.read` (`GET /me/product-updates`) | — | — | Sí | Sí | Sí (`/novedades`, solo publicadas) |
| `web.webinar_registrations.create` / `.update` (inscribirse y cancelar con el enlace), `web.savings_calculations.create` | — | — | — | — | Sí (captcha, límite por IP e `Idempotency-Key`) |
| `web.review_sources.read`, `web.review_snapshots.read`, `web.savings_calculator_settings.update`, `web.savings_calculations.read` | Sí | — | — | — | — |
| **`web.review_sources.manage`** (especial) | Sí | — | — | — | — |

- Nadie, ni el dueño, puede eliminar una hoja de reclamación, un evento de su historial ni una aceptación legal; las correcciones se agregan como eventos.
- El acceso del dueño al CMS no le da acceso a datos SST de las empresas (§4.6 sigue vigente).
- **Permisos especiales de las herramientas de captación (8):** `web.marketing_leads.assign`, `web.marketing_leads.export`, `web.assessment_questionnaires.publish`, `web.success_stories.approve`, `web.help_articles.publish`, `web.partner_applications.approve`, `web.newsletter_issues.send` y `web.status_incidents.publish`. Por defecto solo los tiene `platform_owner`; soporte prepara (leads asignados, casos, artículos, incidentes en borrador) pero no asigna, exporta, aprueba, publica ni envía. Quién puede publicar incidentes fuera de horario queda en `13` (DA-39).
- Las tablas inalterables `resource_downloads`, `help_article_feedback` y `status_incident_updates` no tienen permiso de edición ni de borrado para ningún rol.
- **Permisos especiales del sitio web v1.5 (5):** `web.demo_meetings.assign`, `web.demo_meetings.confirm`, `web.chatbot_intents.publish`, `web.crm_connections.manage` y `web.site_analytics.export` (este último es un recurso lógico que agrupa `site_analytics_daily` y `site_events`). **v1.5-b (5):** `web.campaigns.publish`, `web.site_popups.publish`, `web.product_updates.publish`, `web.webinars.publish` y `web.review_sources.manage`.
- **Soporte en v1.5:** `platform_support` **puede atender el calendario** (ve las demos, confirma, reprograma y registra el resultado de las que tiene asignadas, y mantiene su disponibilidad y bloqueos) **y el chatbot** (redacta intenciones en borrador y lee conversaciones), pero **no gestiona el CRM** (no ve ni edita la conexión, el mapeo ni la cola), no asigna demos, no publica intenciones ni exporta métricas. En v1.5-b redacta novedades y webinars en borrador y marca asistencia; no publica.
- Las tablas inalterables `site_events` y `chatbot_messages` no tienen permiso de edición ni de borrado para ningún rol; solo la tarea de conservación las depura al vencer P-63 y P-64.

### 4.10 Segregación de funciones
| Acción | Regla |
|---|---|
| Aprobar IPERC | Aprobador ≠ quien elaboró la versión |
| Aprobar PETAR | Ningún aprobador es el ejecutor |
| Cerrar acción correctiva | Verifica cierre alguien distinto del responsable |
| Aprobar acreditación de contratista | Distinto de quien la revisó, si hay doble revisión configurada |
| Elección del comité | Nadie ve votos individuales; solo resultados al cierre |
| Reembolso de una venta | Lo registra el administrador de ventas; si la empresa lo configura, lo confirma otra persona |
| Anular un certificado | Solo el administrador (Jefe SST); el capacitador no anula |
| Responder o prorrogar un reclamo del Libro de Reclamaciones | Solo el Dueño de la plataforma; soporte prepara pero no responde |
| Publicar un testimonio | Lo carga soporte o el administrador de cuenta con consentimiento; lo aprueba solo el Dueño de la plataforma |
| Publicar un caso de éxito, un artículo de ayuda o un incidente | Lo prepara soporte; lo aprueba o publica solo el Dueño de la plataforma |
| Exportar leads o enviar el boletín | Solo el Dueño de la plataforma; queda en `audit_logs` |
