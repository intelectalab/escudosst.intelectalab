# M-INV · 6.2 Investigación de causas

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `INV` |
| Número | 6.2 |
| Nombre | Investigación de causas |
| Grupo | 6. Eventos y mejora continua |
| Plan | P · Profesional |
| Fase | 2 · Plan Profesional |
| Origen | Ley |
| Resuelve | Árbol de causas o SCAT |
| Tablas del catálogo | `investigations`, `investigation_team`, `investigation_causes` |
| Dependencias | [ACC](M-ACC.md) (Accidentes, incidentes y enfermedades), [ACR](M-ACR.md) (Acciones correctivas), [COM](M-COM.md) (Comité o Supervisor SST), [TRB](M-TRB.md) (Trabajadores y legajo) |
| Prefijo de permisos | `inv.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Investigar accidentes, incidentes peligrosos y enfermedades ocupacionales con un equipo designado, usando metodologías estructuradas (árbol de causas, SCAT, 5 porqués), identificando causas inmediatas y básicas y definiendo acciones.

**Problema que resuelve.** Las investigaciones concluyen «falta de atención del trabajador» y no identifican causas de gestión; no hay evidencia de participación del comité ni acciones vinculadas.

## 3. Base normativa

- Ley 29783, art. 58: investigación de accidentes, enfermedades e incidentes para determinar causas y aplicar medidas.
- D.S. 005-2012-TR, art. 88–90: objetivos de la investigación y su comunicación; art. 42 literal h: participación del comité.
- R.M. 050-2013-TR: formato del registro de accidentes con causas básicas e inmediatas.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Abre la investigación, designa equipo, aprueba el informe | `inv.investigations.create`, `inv.investigations.approve`, `inv.investigation_team.create` |
| Miembro del Comité SST (`committee_member`) | Integra el equipo y firma el informe | `inv.investigation_team.view`, `inv.investigation_causes.create` |
| Jefe de área / línea (`area_lead`) | Integra el equipo y aporta información | `inv.investigation_causes.create` |
| Supervisor SST (`sst_supervisor`) | Lidera investigaciones en su sede | `inv.investigations.update`, `inv.investigation_causes.create` |
| Gerencia (`management`) | Consulta y aprueba acciones de alto impacto | `inv.investigations.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `investigations` | principal | Investigación | company_id, event_id, method (árbol de causas, SCAT, 5 porqués, ICAM), started_on, due_on, leader_user_id, chronology, interviews (jsonb), evidence_file_ids, conclusions, report_file_id, status, approved_at |
| `investigation_team` | principal | Integrante del equipo | investigation_id, worker_id, role (líder, representante del comité, jefe de área, especialista, trabajador), signature_id |
| `investigation_causes` | principal | Causa identificada | investigation_id, cause_level (inmediata: acto/condición; básica: factor personal/factor de trabajo; falta de control), parent_cause_id, code (taxonomía SCAT si aplica), description, corrective_action_ids |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-INV-01 | Investigaciones | Lista | Evento, método, líder, vencimiento, estado |
| SCR-INV-02 | Investigación | Espacio de trabajo | Cronología; entrevistas; evidencias; equipo con firmas |
| SCR-INV-03 | Árbol de causas | Diagrama interactivo | Nodos de hechos encadenados; editor visual |
| SCR-INV-04 | SCAT | Asistente | Tipo de contacto → causas inmediatas → causas básicas → necesidades de control, con códigos |
| SCR-INV-05 | Acciones | Medidas | Por cada causa básica, acción en ACR con responsable y fecha |
| SCR-INV-06 | Informe | PDF | Generar informe con firmas del equipo |

## 7. Flujos principales

1. Al clasificar un evento que requiere investigación, se crea la investigación con `due_on` = ocurrencia + `investigation_days` (parámetro).
2. El Jefe SST designa equipo (incluye representante del comité cuando existe).
3. El equipo registra cronología, entrevistas y evidencias; aplica el método.
4. Por cada causa básica se definen acciones en [ACR](M-ACR.md).
5. Se aprueba el informe con firmas; el evento avanza.

## 8. Reglas de negocio

- **RN-INV-01.** Toda investigación debe identificar al menos una causa inmediata y una causa básica.
- **RN-INV-02.** Cada causa básica debe tener al menos una acción correctiva vinculada.
- **RN-INV-03.** En empresas con comité, el equipo debe incluir al menos un miembro del comité; con supervisor, al Supervisor SST.
- **RN-INV-04.** `due_on` se calcula con `investigation_days` (parámetro, a confirmar); si vence, la investigación queda «vencida».
- **RN-INV-05.** El informe aprobado y firmado es inmutable; nuevos hallazgos se agregan como adenda.
- **RN-INV-06.** La conclusión no puede limitarse a causas atribuidas al trabajador si no hay causas de gestión (validación: al menos una causa de nivel básico «factor de trabajo» o «falta de control», o justificación).
- **RN-INV-07.** Las entrevistas no registran datos de salud.
- **RN-INV-08.** La IA ([IAA](M-IAA.md)) puede sugerir causas como `ai_suggestions` pendientes; nunca se incorporan sin aceptación.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| investigations | open → in_analysis → report_draft → approved → closed; open → overdue | `closed` cuando todas sus acciones se cierran |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Designación en equipo de investigación | Integrantes | App, correo | Inmediato |
| Investigación por vencer | Líder | App | `investigation_notice_days` antes de `due_on` |
| Informe aprobado | Comité, gerencia | App | Inmediato |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Informe de investigación (PDF) con firmas; causas por categoría (Pareto); aporte al registro de accidentes ([REG](M-REG.md)) y al expediente [SUN](M-SUN.md).

## 12. Integración con otros módulos

**Consume:**

- [ACC](M-ACC.md), [COM](M-COM.md), [TRB](M-TRB.md), [IAA](M-IAA.md).

**Produce:**

- Acciones en [ACR](M-ACR.md); causas para [EST](M-EST.md) y [RVD](M-RVD.md).

## 13. Criterios de aceptación clave

1. **Dado** una investigación sin causa básica, **cuando** se envía a aprobación, **entonces** se bloquea.
2. **Dado** una causa básica sin acción, **cuando** se envía, **entonces** se bloquea.
3. **Dado** una empresa con comité y equipo sin miembro del comité, **cuando** se aprueba, **entonces** se bloquea.
4. **Dado** un informe aprobado, **cuando** se edita, **entonces** se exige adenda.
5. **Dado** todas las acciones cerradas, **cuando** se procesa, **entonces** la investigación queda cerrada.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Peritajes técnicos externos.

**Preguntas abiertas:**

- ¿Taxonomía SCAT con licencia o propia?
