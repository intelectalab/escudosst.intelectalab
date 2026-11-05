# M-PET · 3.5 PETS y procedimientos

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `PET` |
| Número | 3.5 |
| Nombre | PETS y procedimientos |
| Grupo | 3. Gestión de riesgos |
| Plan | C · Corporativo |
| Fase | 4 · Plan Corporativo |
| Origen | Mercado (campo) |
| Resuelve | Procedimientos escritos por tarea crítica |
| Tablas del catálogo | `procedures`, `procedure_versions` |
| Dependencias | [DOC](M-DOC.md) (Gestión documental), [IPC](M-IPC.md) (Matriz IPERC), [CAP](M-CAP.md) (Capacitaciones e inducciones) |
| Prefijo de permisos | `pet.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Gestionar los Procedimientos Escritos de Trabajo Seguro (PETS) por tarea crítica, versionados, aprobados, vinculados al IPERC y difundidos con capacitación.

**Problema que resuelve.** Las tareas críticas se ejecutan sin procedimiento o con uno desactualizado; en campo nadie sabe cuál es la versión vigente.

## 3. Base normativa

- D.S. 005-2012-TR, art. 32 y 77: procedimientos como parte de la documentación y de los controles administrativos.
- D.S. 024-2016-EM, art. 7 y Anexo 4 en minería (formato de PETS) vía [PSE](M-PSE.md).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Crea procedimientos y aprueba versiones | `pet.procedures.create`, `pet.procedure_versions.create`, `pet.procedure_versions.approve` |
| Jefe de área / línea (`area_lead`) | Elabora PETS de su área | `pet.procedures.create`, `pet.procedure_versions.create` |
| Trabajador (`worker`) | Consulta PETS de su puesto | `pet.procedures.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `procedures` | principal | Procedimiento lógico | company_id, code, title, area_id, task_name, is_critical, iperc_row_ids, position_ids, status, current_version_id |
| `procedure_versions` | principal | Versión | procedure_id, version_label, steps (jsonb: paso, riesgo, control), required_ppe, required_training_ids, document_version_id, status, approved_by, effective_from |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-PET-01 | Procedimientos | Lista | Código, tarea, área, versión vigente |
| SCR-PET-02 | Editor de PETS | Pasos | Tabla paso–riesgo–control; EPP y capacitaciones requeridas; vínculo a filas IPERC |
| SCR-PET-03 | PETS en campo (móvil) | Consulta | Pasos grandes con íconos; QR en el equipo/zona que abre el PETS |

## 7. Flujos principales

1. Se redacta el PETS desde la tarea crítica del IPERC; se aprueban pasos y controles.
2. Al aprobarse, se crea capacitación obligatoria para los puestos vinculados.

## 8. Reglas de negocio

- **RN-PET-01.** Toda tarea marcada crítica en el IPERC debe tener un PETS vigente; si no, aparece como brecha.
- **RN-PET-02.** Solo una versión vigente; una versión aprobada es inmutable.
- **RN-PET-03.** La aprobación de una versión genera capacitación pendiente para los puestos vinculados en [CAP](M-CAP.md).
- **RN-PET-04.** Cada paso con riesgo significativo debe tener control asociado.
- **RN-PET-05.** La revisión del PETS se exige al revisarse la fila IPERC vinculada.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| procedure_versions | draft → in_review → approved → effective → superseded | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| PETS aprobado | Trabajadores de los puestos vinculados | App | Al aprobar |
| Tarea crítica sin PETS | Jefe SST | App | Al detectarlo |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- PETS en PDF (formato sectorial si aplica); lista maestra de PETS.

## 12. Integración con otros módulos

**Consume:**

- [IPC](M-IPC.md), [DOC](M-DOC.md).

**Produce:**

- Controles administrativos para [IPC](M-IPC.md), [ATS](M-ATS.md), [PTR](M-PTR.md); capacitaciones en [CAP](M-CAP.md).

## 13. Criterios de aceptación clave

1. **Dado** una tarea crítica sin PETS, **cuando** corre el autodiagnóstico, **entonces** aparece como brecha.
2. **Dado** un PETS aprobado, **cuando** entra en vigor, **entonces** los trabajadores de los puestos vinculados tienen capacitación pendiente.
3. **Dado** una versión vigente, **cuando** se edita, **entonces** se crea una nueva versión.
4. **Dado** un QR de PETS, **cuando** se escanea en campo, **entonces** se abre la versión vigente.
5. **Dado** un paso con riesgo significativo sin control, **cuando** se envía a aprobación, **entonces** se bloquea.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Instructivos de calidad o producción.

**Preguntas abiertas:**

- ¿Se requiere firma de todos los ejecutantes al aprobar un PETS?
