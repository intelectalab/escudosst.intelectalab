# M-PCA · 2.6 Programa anual de capacitación

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `PCA` |
| Número | 2.6 |
| Nombre | Programa anual de capacitación |
| Grupo | 2. Planificación del sistema de gestión |
| Plan | E · Esencial |
| Fase | 1 · Plan Esencial |
| Origen | Ley |
| Resuelve | Plan aprobado por comité |
| Tablas del catálogo | `training_programs`, `training_program_items` |
| Dependencias | [CAP](M-CAP.md) (Capacitaciones e inducciones), [COM](M-COM.md) (Comité o Supervisor SST), [POL](M-POL.md) (Política y objetivos SST) |
| Prefijo de permisos | `pca.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Formular y aprobar el Programa Anual de Capacitación SST con temas obligatorios por puesto, público objetivo, mes y modalidad, y medir su cumplimiento con las capacitaciones realmente ejecutadas.

**Problema que resuelve.** Las empresas capacitan de forma reactiva y no pueden probar las capacitaciones mínimas exigidas (al menos 4 al año según reglamento) ni que el programa fue aprobado por el comité.

## 3. Base normativa

- Ley 29783, art. 35 literal b y art. 49 literal g: capacitación al momento de la contratación, durante el desempeño, ante cambios de funciones o tecnología.
- D.S. 005-2012-TR, art. 27: mínimo de capacitaciones anuales en horario de trabajo (parámetro `min_trainings_per_year`, valor de referencia 4, a confirmar).
- D.S. 005-2012-TR, art. 42 literal c: el comité aprueba el programa anual de capacitación.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Formula el programa y sus ítems | `pca.training_programs.create`, `pca.training_programs.update`, `pca.training_program_items.create`, `pca.training_program_items.update` |
| Miembro del Comité SST (`committee_member`) | Aprueba el programa | `pca.training_programs.approve` |
| Supervisor SST (`sst_supervisor`) | Aprueba en empresas sin comité | `pca.training_programs.approve` |
| Recursos Humanos (`hr`) | Consulta y coordina la programación | `pca.training_programs.view` |
| Gerencia (`management`) | Consulta el avance | `pca.training_programs.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `training_programs` | principal | Programa anual de capacitación | company_id, year, version, status, approved_at, committee_meeting_id, document_version_id |
| `training_program_items` | principal | Tema programado | program_id, topic, legal_basis, is_mandatory, target_positions (array), target_areas, planned_month, modality (presencial/virtual/mixta), provider_type (interno/externo), min_hours, requires_evaluation, training_id (vínculo a CAP) |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-PCA-01 | Programa de capacitación | Vista anual | Tabla temas × meses; público objetivo; % de cumplimiento por tema y global |
| SCR-PCA-02 | Agregar tema | Detalle | Tema, base legal, obligatoriedad, puestos objetivo, mes, modalidad, duración mínima |
| SCR-PCA-03 | Matriz puesto × tema | Cobertura | Cuadrícula de puestos vs. temas obligatorios con conteo de trabajadores |
| SCR-PCA-04 | Aprobación | Envío al comité | PDF, sesión del comité, firma |

## 7. Flujos principales

1. El Jefe SST crea el programa del año; el sistema propone temas mínimos (inducción, IPERC del puesto, uso de EPP, respuesta a emergencias, RISST) y temas sectoriales si hay [PSE](M-PSE.md).
2. Asigna puestos objetivo, mes y modalidad.
3. El comité aprueba en sesión; el programa queda vigente.
4. Cada ítem se ejecuta creando una capacitación en [CAP](M-CAP.md) vinculada; el cumplimiento se calcula con las asistencias aprobadas.

## 8. Reglas de negocio

- **RN-PCA-01.** El programa debe contener al menos `min_trainings_per_year` temas en horario de trabajo para que pueda enviarse a aprobación.
- **RN-PCA-02.** Cada ítem obligatorio debe tener al menos un puesto o área objetivo.
- **RN-PCA-03.** El cumplimiento de un ítem = trabajadores objetivo con asistencia aprobada / trabajadores objetivo activos en el periodo.
- **RN-PCA-04.** Un ítem se considera ejecutado en el mes cuando existe una capacitación de [CAP](M-CAP.md) vinculada con al menos una sesión realizada.
- **RN-PCA-05.** La aprobación exige acuerdo de comité o firma del supervisor según `sst_model`.
- **RN-PCA-06.** Modificar el programa aprobado requiere nueva versión aprobada.
- **RN-PCA-07.** Los temas obligatorios por puesto alimentan los requisitos del fotocheck ([FOT](M-FOT.md)) y la matriz de brechas de capacitación.
- **RN-PCA-08.** Un tema programado en un mes ya cerrado sin capacitación vinculada queda «no ejecutado» y solo puede reprogramarse dentro del mismo año con motivo registrado.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| training_programs | draft → submitted → approved → closed | Nueva versión vuelve a draft |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Tema programado del mes sin capacitación creada | Jefe SST | App, correo | `training_plan_notice_days` antes del mes |
| Programa enviado a aprobación | Comité o supervisor | App | Inmediato |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Programa anual de capacitación en PDF con acta.
- Cumplimiento por tema, puesto y trabajador (Excel); aporte al registro de inducción, capacitación, entrenamiento y simulacros en [REG](M-REG.md) y al expediente [SUN](M-SUN.md).

## 12. Integración con otros módulos

**Consume:**

- [CAP](M-CAP.md): asistencias; [TEN](M-TEN.md): puestos; [COM](M-COM.md): acuerdos.

**Produce:**

- Temas obligatorios por puesto para [CAP](M-CAP.md) y [FOT](M-FOT.md); programa vinculado en [PLA](M-PLA.md).

## 13. Criterios de aceptación clave

1. **Dado** un programa con 3 temas y `min_trainings_per_year` = 4, **cuando** se envía a aprobación, **entonces** el sistema lo bloquea.
2. **Dado** un tema obligatorio para el puesto Operario con 30 operarios activos y 24 con asistencia aprobada, **cuando** se calcula, **entonces** el cumplimiento es 80 %.
3. **Dado** un ítem de marzo, **cuando** se crea una capacitación en CAP vinculada con sesión realizada en marzo, **entonces** el ítem queda ejecutado.
4. **Dado** un programa aprobado, **cuando** se agrega un tema, **entonces** se exige crear una nueva versión.
5. **Dado** una empresa con supervisor, **cuando** se aprueba el programa, **entonces** basta la firma del supervisor.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Gestión de proveedores de capacitación y sus costos.

**Preguntas abiertas:**

- ¿El mínimo de 4 capacitaciones aplica por trabajador o por empresa? A confirmar por especialista.
