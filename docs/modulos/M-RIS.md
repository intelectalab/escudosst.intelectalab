# M-RIS · 2.7 RISST

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `RIS` |
| Número | 2.7 |
| Nombre | RISST |
| Grupo | 2. Planificación del sistema de gestión |
| Plan | P · Profesional |
| Fase | 2 · Plan Profesional |
| Origen | Ley (20+ trabajadores) |
| Resuelve | Versiones y cargo de entrega al trabajador |
| Tablas del catálogo | `risst_versions`, `risst_deliveries` |
| Dependencias | [DOC](M-DOC.md) (Gestión documental), [FIR](M-FIR.md) (Firma electrónica de registros), [COM](M-COM.md) (Comité o Supervisor SST), [TRB](M-TRB.md) (Trabajadores y legajo) |
| Prefijo de permisos | `ris.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Elaborar, aprobar y versionar el Reglamento Interno de SST (RISST) y controlar la entrega de un ejemplar a cada trabajador (incluidos los de contratistas cuando corresponda) con cargo firmado.

**Problema que resuelve.** Las empresas con 20 o más trabajadores deben tener RISST, pero el cargo de entrega se pierde o no existe para el personal nuevo; SUNAFIL multa por no acreditar la entrega.

## 3. Base normativa

- Ley 29783, art. 34: RISST obligatorio para empresas con 20 o más trabajadores (umbral como parámetro `committee_threshold`).
- D.S. 005-2012-TR, art. 74: contenido mínimo del RISST (objetivos y alcance, liderazgo, organización, estándares de seguridad, preparación y respuesta a emergencias).
- Ley 29783, art. 35 literal a y D.S. 005-2012-TR, art. 75: entrega de copia a cada trabajador (física o electrónica) y a contratistas/intermediadores que presten servicios.
- D.S. 005-2012-TR, art. 42 literal d: el comité aprueba el RISST.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Redacta versiones, gestiona aprobación y entregas | `ris.risst_versions.create`, `ris.risst_versions.update`, `ris.risst_deliveries.create`, `ris.risst_deliveries.view` |
| Miembro del Comité SST (`committee_member`) | Aprueba el RISST en sesión | `ris.risst_versions.approve` |
| Recursos Humanos (`hr`) | Entrega el RISST en el ingreso de personal | `ris.risst_deliveries.create` |
| Trabajador (`worker`) | Recibe y firma el cargo de entrega | `ris.risst_deliveries.view` |
| Administrador de contratista (`contractor_admin`) | Descarga el RISST para su personal y registra cargos | `ris.risst_deliveries.create` |
| Auditor externo (`external_auditor`) | Consulta versiones y cargos | `ris.risst_versions.view`, `ris.risst_deliveries.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `risst_versions` | principal | Versión del RISST | company_id, version_label, content_outline (jsonb con secciones mínimas del art. 74), document_version_id, status, committee_meeting_id, approved_at, effective_from |
| `risst_deliveries` | principal | Entrega a un trabajador (cargo) | company_id, risst_version_id, worker_id, contractor_worker_id, delivery_method (electrónica/física), delivered_on, signature_id, scanned_file_id, delivered_by |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-RIS-01 | RISST | Vigente y versiones | Versión vigente, acta de aprobación, % de entregas sobre trabajadores activos |
| SCR-RIS-02 | Editor de estructura | Secciones mínimas | Lista de verificación del art. 74 con estado por sección; carga del documento |
| SCR-RIS-03 | Entregas | Control de cargos | Tabla de trabajadores con estado entregado/pendiente; entrega masiva electrónica; registro de cargo físico escaneado |
| SCR-RIS-04 | RISST (app del trabajador) | Leer y firmar | Índice navegable; botón «Recibí el RISST» |

## 7. Flujos principales

1. El Jefe SST carga la nueva versión y completa la lista de secciones mínimas.
2. Se presenta al comité; el acuerdo aprobatorio se vincula y la versión pasa a vigente.
3. Se lanza la entrega electrónica a todos los trabajadores activos; los que no tienen app reciben entrega física y se escanea el cargo.
4. Cada nuevo trabajador recibe automáticamente el RISST vigente como pendiente en su ingreso.

## 8. Reglas de negocio

- **RN-RIS-01.** El módulo se activa como obligatorio cuando `sst_model = committee`; en empresas menores se muestra como recomendado.
- **RN-RIS-02.** No se aprueba una versión sin todas las secciones mínimas del art. 74 marcadas.
- **RN-RIS-03.** La aprobación exige un acuerdo de sesión del comité vinculado.
- **RN-RIS-04.** Una nueva versión vigente genera entrega pendiente para todos los trabajadores activos.
- **RN-RIS-05.** La entrega electrónica requiere firma del trabajador ([FIR](M-FIR.md)); la física requiere el cargo escaneado.
- **RN-RIS-06.** Todo trabajador con más de `risst_delivery_grace_days` desde su ingreso sin cargo aparece como incumplimiento en el autodiagnóstico [SUN](M-SUN.md).
- **RN-RIS-07.** Las entregas son inalterables una vez firmadas; un error se anula con motivo y nueva entrega.
- **RN-RIS-08.** Los trabajadores de contratistas reciben el RISST mediante el portal del contratista cuando [CTR](M-CTR.md) está habilitado.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| risst_versions | draft → submitted → approved → effective → obsolete | |
| risst_deliveries | pending → delivered; delivered → voided | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| RISST pendiente de recepción | Trabajador | App, WhatsApp | Al publicar; recordatorio `ack_reminder_days` |
| Trabajadores sin cargo superan el plazo de gracia | Jefe SST, RR.HH. | App, correo | Al detectarlo |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- RISST en PDF con acta de aprobación.
- Relación de cargos de entrega por trabajador y versión (evidencia directa para SUNAFIL); aporte al legajo y al expediente [SUN](M-SUN.md).

## 12. Integración con otros módulos

**Consume:**

- [DOC](M-DOC.md), [FIR](M-FIR.md), [COM](M-COM.md), [TRB](M-TRB.md), [CTR](M-CTR.md).

**Produce:**

- Cargos para el legajo del trabajador y el expediente SUNAFIL; KPI «% RISST entregado».

## 13. Criterios de aceptación clave

1. **Dado** una versión sin la sección de preparación ante emergencias, **cuando** se envía a aprobación, **entonces** se bloquea.
2. **Dado** una nueva versión vigente con 80 trabajadores activos, **cuando** entra en vigor, **entonces** se crean 80 entregas pendientes.
3. **Dado** un trabajador ingresado hace más días que la gracia sin cargo, **cuando** corre el autodiagnóstico, **entonces** aparece como brecha.
4. **Dado** una entrega física, **cuando** se registra sin escaneo del cargo, **entonces** el sistema lo rechaza.
5. **Dado** un trabajador, **cuando** firma la recepción en la app, **entonces** la entrega queda «entregada» con la evidencia de firma.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Redacción automática del RISST (puede sugerirse con [IAA](M-IAA.md)).

**Preguntas abiertas:**

- ¿Se necesita registrar la entrega a visitantes?
