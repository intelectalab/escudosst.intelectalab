# M-PLA · 2.4 Plan Anual SST

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `PLA` |
| Número | 2.4 |
| Nombre | Plan Anual SST |
| Grupo | 2. Planificación del sistema de gestión |
| Plan | E · Esencial |
| Fase | 1 · Plan Esencial |
| Origen | Ley |
| Resuelve | Objetivos, metas y recursos |
| Tablas del catálogo | `annual_plans`, `annual_plan_items` |
| Dependencias | [POL](M-POL.md) (Política y objetivos SST), [LBA](M-LBA.md) (Diagnóstico de línea base), [PAS](M-PAS.md) (Programa Anual SST (PASST)), [PCA](M-PCA.md) (Programa anual de capacitación), [COM](M-COM.md) (Comité o Supervisor SST) |
| Prefijo de permisos | `pla.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Formular el Plan Anual de SST que integra objetivos, metas, recursos y los programas (PASST y capacitación), con aprobación del Comité o Supervisor SST.

**Problema que resuelve.** El Plan Anual suele ser un documento genérico desligado del diagnóstico y de los objetivos; no muestra recursos ni responsables, y no se aprueba formalmente en acta, lo que SUNAFIL observa.

## 3. Base normativa

- Ley 29783, art. 38–39 y D.S. 005-2012-TR, art. 32 y 79–80: planificación del SG-SST y Plan Anual como documento obligatorio.
- D.S. 005-2012-TR, art. 42, literal c: el Comité SST aprueba el Plan Anual (y el Programa Anual).
- R.M. 050-2013-TR: guía referencial para elaborar el Plan Anual.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Formula el plan, sus componentes y recursos | `pla.annual_plans.create`, `pla.annual_plans.update`, `pla.annual_plan_items.create`, `pla.annual_plan_items.update` |
| Consultor SST (`consultant`) | Formula el plan de la empresa cliente | `pla.annual_plans.create`, `pla.annual_plan_items.create` |
| Miembro del Comité SST (`committee_member`) | Revisa y aprueba el plan en sesión | `pla.annual_plans.approve`, `pla.annual_plans.view` |
| Supervisor SST (`sst_supervisor`) | Aprueba el plan en empresas sin comité | `pla.annual_plans.approve` |
| Gerencia (`management`) | Consulta y asigna recursos | `pla.annual_plans.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `annual_plans` | principal | Plan Anual por año | company_id, year, version, title, scope, introduction, baseline_assessment_id, policy_id, status, committee_meeting_id, approved_at, document_version_id |
| `annual_plan_items` | principal | Componente del plan | annual_plan_id, item_type (objetivo, meta, recurso humano, recurso material, recurso financiero sin montos, programa, indicador), reference_type, reference_id, description, responsible_worker_id, order |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-PLA-01 | Planes anuales | Historial por año | Tarjetas por año con estado y fecha de aprobación |
| SCR-PLA-02 | Formular plan | Asistente por secciones | Alcance, diagnóstico (desde LBA), política y objetivos (desde POL), recursos, programas vinculados (PASST, PCA), indicadores |
| SCR-PLA-03 | Revisión y aprobación | Envío al comité | Vista previa del PDF; selección de la sesión del comité donde se aprueba; firma |
| SCR-PLA-04 | Plan aprobado | Consulta | PDF, versión, acta vinculada, enlaces a programas |

## 7. Flujos principales

1. El Jefe SST crea el plan del año; el sistema precarga diagnóstico vigente, política y objetivos.
2. Vincula el PASST y el Programa de capacitación del mismo año (deben existir en borrador o aprobados).
3. Registra recursos (humanos, materiales y financieros descritos sin montos si no hay [PRE](M-PRE.md)).
4. Envía a aprobación; el comité lo aprueba en una sesión ([COM](M-COM.md)) y el acuerdo queda vinculado; en empresas con supervisor, aprueba el supervisor con firma.
5. Se publica como documento en [DOC](M-DOC.md).

## 8. Reglas de negocio

- **RN-PLA-01.** Solo un plan aprobado por año y empresa; las modificaciones se hacen con una nueva versión que también requiere aprobación.
- **RN-PLA-02.** Para enviar a aprobación el plan debe vincular un diagnóstico de línea base aprobado, la política vigente, al menos un objetivo y el PASST del mismo año.
- **RN-PLA-03.** En empresas con `sst_model = committee`, la aprobación exige vincular un acuerdo de sesión del comité con quórum; con `supervisor`, la firma del Supervisor SST.
- **RN-PLA-04.** El plan aprobado es inmutable; el documento PDF se genera y versiona en [DOC](M-DOC.md).
- **RN-PLA-05.** El año del plan debe coincidir con el año de los programas vinculados.
- **RN-PLA-06.** Si existe [PRE](M-PRE.md) contratado, los recursos financieros se enlazan al presupuesto; si no, se describen sin montos.
- **RN-PLA-07.** Cada componente del plan de tipo objetivo o programa debe referenciar un registro existente de la misma empresa y año (`reference_type`, `reference_id`); no se aceptan referencias a versiones obsoletas.
- **RN-PLA-08.** Al cerrar el año, el plan pasa a `closed` y su informe de cumplimiento se genera con el avance final del PASST y de los objetivos; ningún dato del plan cerrado puede modificarse.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| annual_plans | draft → submitted → approved → closed; submitted → draft | `closed` al terminar el año; nueva versión vuelve a draft |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Plan enviado a aprobación | Miembros del comité o supervisor | App, correo | Inmediato |
| Año sin plan aprobado | Jefe SST, gerencia | App, correo | `plan_missing_notice_days` antes del inicio del año |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Plan Anual SST en PDF (estructura referencial R.M. 050-2013-TR) con acta de aprobación.
- Aporte al expediente [SUN](M-SUN.md): plan aprobado y acta.

## 12. Integración con otros módulos

**Consume:**

- [LBA](M-LBA.md), [POL](M-POL.md), [PAS](M-PAS.md), [PCA](M-PCA.md), [COM](M-COM.md), [PRE](M-PRE.md).

**Produce:**

- Documento rector del año para [RVD](M-RVD.md) y [DSH](M-DSH.md).

## 13. Criterios de aceptación clave

1. **Dado** un plan sin diagnóstico de línea base aprobado, **cuando** se envía a aprobación, **entonces** el sistema lo bloquea indicando el requisito.
2. **Dado** una empresa con comité, **cuando** se aprueba el plan sin acuerdo de sesión vinculado, **entonces** la acción se rechaza.
3. **Dado** un plan 2027 aprobado, **cuando** se intenta crear otro plan 2027, **entonces** solo se permite una nueva versión del existente.
4. **Dado** un plan aprobado, **cuando** se descarga el PDF, **entonces** incluye política, objetivos, programas vinculados y referencia al acta.
5. **Dado** un PASST del año 2026, **cuando** se vincula a un plan 2027, **entonces** el sistema lo rechaza.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Montos y presupuesto detallado (se gestiona en [PRE](M-PRE.md)).

**Preguntas abiertas:**

- ¿Se requiere también la aprobación de gerencia además del comité?
