# M-MRL · 2.3 Matriz de requisitos legales

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `MRL` |
| Número | 2.3 |
| Nombre | Matriz de requisitos legales |
| Grupo | 2. Planificación del sistema de gestión |
| Plan | P · Profesional |
| Fase | 2 · Plan Profesional |
| Origen | Ley / ISO |
| Resuelve | Normas aplicables y su cumplimiento |
| Tablas del catálogo | `legal_norms`, `legal_requirements`, `requirement_evaluations` |
| Dependencias | [TEN](M-TEN.md) (Empresas, sedes y áreas), [DOC](M-DOC.md) (Gestión documental), [ACR](M-ACR.md) (Acciones correctivas) |
| Prefijo de permisos | `mrl.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Mantener la matriz de requisitos legales SST aplicables a la empresa, con su evaluación periódica de cumplimiento y evidencias, a partir de un catálogo global de normas actualizado por EscudoSST.

**Problema que resuelve.** Las empresas no saben qué normas les aplican además de la Ley 29783 (sectoriales, DS de ergonomía, agentes), ni cuándo cambian; las auditorías ISO y SUNAFIL piden evidencia del cumplimiento de cada requisito.

## 3. Base normativa

- Ley 29783, art. 37 y D.S. 005-2012-TR, art. 77: identificación de la legislación vigente aplicable en la línea base.
- ISO 45001:2018, cláusula 6.1.3 (determinación de requisitos legales) y 9.1.2 (evaluación del cumplimiento).
- Catálogo inicial: Ley 29783, Ley 30222, D.S. 005-2012-TR, R.M. 050-2013-TR, R.M. 375-2008-TR (ergonomía), D.S. 024-2016-EM, Norma G.050, D.S. 003-98-SA (SCTR), Ley 29733 (lista a validar por especialista).
- Frecuencia de evaluación: parámetro `legal_evaluation_months` (a confirmar).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Dueño de la plataforma (`platform_owner`) | Mantiene el catálogo global de normas y sus artículos | `mrl.legal_norms.create`, `mrl.legal_norms.update` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Selecciona requisitos aplicables, asigna responsables, evalúa cumplimiento | `mrl.legal_requirements.create`, `mrl.legal_requirements.update`, `mrl.requirement_evaluations.create` |
| Consultor SST (`consultant`) | Gestiona la matriz de la empresa cliente | `mrl.legal_requirements.update`, `mrl.requirement_evaluations.create` |
| Jefe de área / línea (`area_lead`) | Aporta evidencia de requisitos asignados | `mrl.requirement_evaluations.create` |
| Gerencia (`management`) | Consulta el nivel de cumplimiento | `mrl.legal_requirements.view` |
| Auditor externo (`external_auditor`) | Consulta matriz y evaluaciones | `mrl.legal_requirements.view`, `mrl.requirement_evaluations.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `legal_norms` | principal | Catálogo global de normas (sin company_id) | code, norm_type (ley, DS, RM, NTP, norma técnica), number, title, issuer, published_on, effective_on, status (vigente/derogada/modificada), sectors, url_official, replaced_by_id |
| `legal_requirements` | principal | Requisito aplicable a la empresa | company_id, legal_norm_id, article, requirement_text, applies_to (empresa/sede/proceso), site_id, responsible_worker_id, evidence_expected, is_applicable, applicability_reason |
| `requirement_evaluations` | principal | Evaluación de cumplimiento | requirement_id, evaluated_on, result (cumple/parcial/no cumple/no aplica), evidence_file_ids, linked_records (jsonb), evaluator_user_id, corrective_action_id |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-MRL-01 | Catálogo de normas | Buscar normas | Filtros por tipo, sector, estado; marca «nueva/modificada» desde la última revisión |
| SCR-MRL-02 | Matriz de requisitos | Requisitos de la empresa | Tabla norma, artículo, requisito, responsable, último resultado con semáforo, próxima evaluación |
| SCR-MRL-03 | Agregar requisitos | Selección desde catálogo | Asistente por sector que sugiere normas; selección por artículo; justificación de no aplicabilidad |
| SCR-MRL-04 | Evaluar requisito | Registrar cumplimiento | Resultado, evidencia (archivo o enlace a registros del sistema), crear acción si no cumple |
| SCR-MRL-05 | Novedades normativas | Cambios en el catálogo | Lista de normas nuevas o modificadas que afectan a requisitos de la empresa; botón «revisar» |

## 7. Flujos principales

1. EscudoSST publica o actualiza una norma en el catálogo global.
2. Si la norma afecta a requisitos de una empresa (por sector o por norma modificada), se genera una alerta de revisión.
3. El Jefe SST agrega o actualiza requisitos y asigna responsables.
4. Según la periodicidad, cada requisito se evalúa; los incumplimientos crean acciones en [ACR](M-ACR.md).

## 8. Reglas de negocio

- **RN-MRL-01.** `legal_norms` es un catálogo global sin `company_id`; las empresas no lo editan.
- **RN-MRL-02.** Todo requisito marcado «no aplicable» exige `applicability_reason`.
- **RN-MRL-03.** Cada requisito aplicable tiene un responsable y una fecha de próxima evaluación calculada con `legal_evaluation_months`.
- **RN-MRL-04.** Una evaluación «parcial» o «no cumple» exige crear o vincular una acción correctiva.
- **RN-MRL-05.** Cuando una norma pasa a «derogada» o «modificada», los requisitos vinculados se marcan «requiere revisión» y no cuentan como cumplidos hasta revisarse.
- **RN-MRL-06.** El % de cumplimiento legal = requisitos aplicables con última evaluación «cumple» / requisitos aplicables evaluados.
- **RN-MRL-07.** Las evaluaciones son históricas e inmutables una vez guardadas; una corrección crea otra evaluación.
- **RN-MRL-08.** La evidencia puede ser un registro interno (p. ej. capacitación, EMO); el enlace se valida contra la misma empresa.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| legal_requirements | active → needs_review → active; active → not_applicable | `needs_review` automático por cambio normativo |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Norma nueva o modificada que afecta a la empresa | Jefe SST | App, correo | Al publicar en el catálogo |
| Evaluación de requisito por vencer | Responsable | App | `legal_eval_notice_days` antes |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Matriz de requisitos legales con último resultado (Excel/PDF).
- Informe de evaluación de cumplimiento legal del periodo (evidencia para auditoría ISO 45001 y SUNAFIL).

## 12. Integración con otros módulos

**Consume:**

- Registros de otros módulos como evidencia; [ACR](M-ACR.md) para acciones.

**Produce:**

- KPI de cumplimiento legal para [DSH](M-DSH.md) y [RVD](M-RVD.md); insumo del autodiagnóstico [SUN](M-SUN.md).

## 13. Criterios de aceptación clave

1. **Dado** un requisito marcado «no aplicable» sin justificación, **cuando** se guarda, **entonces** el sistema lo exige.
2. **Dado** una norma del catálogo pasa a «modificada», **cuando** se publica, **entonces** los requisitos vinculados quedan en «requiere revisión» y se alerta al Jefe SST.
3. **Dado** una evaluación «no cumple», **cuando** se guarda, **entonces** se obliga a crear o vincular una acción correctiva.
4. **Dado** 10 requisitos aplicables con 8 «cumple», **cuando** se calcula el KPI, **entonces** el valor es 80 %.
5. **Dado** un usuario de empresa, **cuando** intenta editar una norma del catálogo global, **entonces** no tiene permiso.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Asesoría legal o interpretación de normas.
- Seguimiento de normas no relacionadas con SST.

**Preguntas abiertas:**

- ¿Quién valida el contenido del catálogo global de normas en EscudoSST (especialista contratado)?
