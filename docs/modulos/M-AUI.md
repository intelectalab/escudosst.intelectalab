# M-AUI · 6.4 Auditorías

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `AUI` |
| Número | 6.4 |
| Nombre | Auditorías |
| Grupo | 6. Eventos y mejora continua |
| Plan | P · Profesional |
| Fase | 2 · Plan Profesional |
| Origen | Ley |
| Resuelve | Internas y externas, con hallazgos |
| Tablas del catálogo | `audits`, `audit_checklist_items`, `audit_findings` |
| Dependencias | [ACR](M-ACR.md) (Acciones correctivas), [DOC](M-DOC.md) (Gestión documental), [MRL](M-MRL.md) (Matriz de requisitos legales) |
| Prefijo de permisos | `aui.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Planificar y ejecutar auditorías internas y externas del SG-SST con programa anual, listas de verificación, hallazgos clasificados (no conformidad mayor/menor, observación, oportunidad de mejora) y su cierre.

**Problema que resuelve.** La auditoría del SG-SST es obligatoria y se realiza por auditores registrados; las empresas no preparan evidencias ni siguen las no conformidades.

## 3. Base normativa

- Ley 29783, art. 43 y D.S. 005-2012-TR, art. 109 (modificado): auditorías periódicas del SG-SST por auditores independientes registrados en el MTPE (periodicidad parámetro `external_audit_years`, a confirmar).
- D.S. 005-2012-TR, art. 33 literal h: registro de auditorías.
- R.M. 050-2013-TR: formato del registro de auditorías.
- ISO 45001, cláusula 9.2.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Programa auditorías, prepara evidencias, gestiona hallazgos | `aui.audits.create`, `aui.audits.update`, `aui.audit_findings.update` |
| Auditor externo (`external_auditor`) | Ejecuta la auditoría asignada, registra resultados y hallazgos | `aui.audit_checklist_items.update`, `aui.audit_findings.create` |
| Miembro del Comité SST (`committee_member`) | Consulta resultados | `aui.audits.view` |
| Gerencia (`management`) | Recibe el informe | `aui.audits.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `audits` | principal | Auditoría | company_id, audit_type (interna, externa legal, certificación, cliente), standard (Ley 29783, ISO 45001), scope, sites, planned_on, executed_from, executed_to, lead_auditor_name, auditor_registry_number, auditor_user_id, status, report_file_id |
| `audit_checklist_items` | principal | Ítem auditado | audit_id, clause_or_requirement, question, result (conforme, no conforme, observación, NA), evidence_seen, linked_records (jsonb) |
| `audit_findings` | principal | Hallazgo | audit_id, checklist_item_id, finding_type (NC mayor, NC menor, observación, oportunidad de mejora), description, requirement, corrective_action_id, status |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-AUI-01 | Programa de auditorías | Calendario anual | Auditorías por tipo con estado |
| SCR-AUI-02 | Preparar auditoría | Evidencias | Lista de requisitos con enlaces a registros del sistema (sale del autodiagnóstico [SUN](M-SUN.md)) |
| SCR-AUI-03 | Ejecutar auditoría | Checklist | Ítems por cláusula; resultado; evidencia |
| SCR-AUI-04 | Hallazgos | Seguimiento | Por tipo con acción y estado |

## 7. Flujos principales

1. Se programa la auditoría; si es externa, se crea una membership temporal `external_auditor` acotada al periodo y módulos.
2. El auditor ejecuta el checklist y registra hallazgos.
3. Las NC generan acciones en [ACR](M-ACR.md); la auditoría se cierra con informe.

## 8. Reglas de negocio

- **RN-AUI-01.** Una auditoría externa legal registra número de registro del auditor ante el MTPE.
- **RN-AUI-02.** El acceso del auditor externo vence al terminar la auditoría + `auditor_access_grace_days`.
- **RN-AUI-03.** Toda NC (mayor o menor) exige acción correctiva.
- **RN-AUI-04.** La auditoría se cierra con informe adjunto; el informe es inmutable.
- **RN-AUI-05.** Se alerta cuando se acerca el periodo legal de auditoría externa sin auditoría programada.
- **RN-AUI-06.** Las observaciones y oportunidades de mejora pueden cerrarse sin acción, con justificación.
- **RN-AUI-07.** Los ítems del checklist se precargan desde la plantilla del estándar y permiten vincular registros del sistema como evidencia.
- **RN-AUI-08.** El auditor externo solo ve los módulos y periodo indicados en su membership.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| audits | planned → in_progress → report_pending → closed; planned → cancelled | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Auditoría programada próxima | Jefe SST | App, correo | `audit_notice_days` antes |
| Auditoría externa legal pendiente | Jefe SST, gerencia | Correo | `external_audit_notice_days` antes de cumplirse el periodo |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Registro de auditorías (formato referencial R.M. 050-2013-TR) en [REG](M-REG.md); informe; hallazgos por cláusula; aporte al expediente [SUN](M-SUN.md).

## 12. Integración con otros módulos

**Consume:**

- [MRL](M-MRL.md), [SUN](M-SUN.md) (autodiagnóstico), [DOC](M-DOC.md).

**Produce:**

- Acciones en [ACR](M-ACR.md); insumo para [RVD](M-RVD.md).

## 13. Criterios de aceptación clave

1. **Dado** una NC menor sin acción, **cuando** se intenta cerrar la auditoría, **entonces** se bloquea.
2. **Dado** un auditor externo con acceso vencido, **cuando** intenta entrar, **entonces** se rechaza.
3. **Dado** una auditoría externa legal sin número de registro, **cuando** se guarda, **entonces** se exige.
4. **Dado** un informe adjunto, **cuando** se cierra, **entonces** queda inmutable.
5. **Dado** un auditor con acceso a CAP y EMO no, **cuando** intenta ver aptitud, **entonces** se rechaza.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Registro de auditores en el MTPE.

**Preguntas abiertas:**

- ¿Periodicidad legal vigente de la auditoría externa? A confirmar.
