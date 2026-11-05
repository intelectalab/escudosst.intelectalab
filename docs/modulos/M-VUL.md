# M-VUL · 5.7 Grupos vulnerables

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `VUL` |
| Número | 5.7 |
| Nombre | Grupos vulnerables |
| Grupo | 5. Personas y salud ocupacional |
| Plan | C · Corporativo |
| Fase | 4 · Plan Corporativo |
| Origen | Ley |
| Resuelve | Gestantes, menores y personas con discapacidad |
| Tablas del catálogo | `vulnerability_records` (base médica), `work_adjustments` |
| Dependencias | [TRB](M-TRB.md) (Trabajadores y legajo), [EMO](M-EMO.md) (EMOs y aptitud), [IPC](M-IPC.md) (Matriz IPERC) |
| Prefijo de permisos | `vul.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Identificar y proteger a trabajadores de grupos vulnerables (gestantes y lactantes, menores de edad, personas con discapacidad y otros) con registro confidencial en la base médica y adecuaciones de trabajo en la base principal.

**Problema que resuelve.** Las gestantes siguen expuestas a agentes de riesgo porque nadie vincula su condición con el IPERC del puesto.

## 3. Base normativa

- Ley 29783, art. 64–67: protección de trabajadoras gestantes y lactantes, menores, personas con discapacidad.
- Ley 28048 (protección de la mujer gestante que realiza labores que pongan en riesgo su salud) y su reglamento (a confirmar vigencia).
- Ley 29973 (personas con discapacidad): ajustes razonables.
- Código de los Niños y Adolescentes: trabajos prohibidos para menores.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Médico ocupacional (`occupational_doctor`) | Registra la condición | `vul.vulnerability_records.create`, `vul.vulnerability_records.view` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Define adecuaciones | `vul.work_adjustments.create`, `vul.work_adjustments.update` |
| Jefe de área / línea (`area_lead`) | Ve y aplica adecuaciones de su personal (sin condición) | `vul.work_adjustments.view` |
| Recursos Humanos (`hr`) | Ve adecuaciones | `vul.work_adjustments.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `vulnerability_records` | médica | Condición (base médica) | company_id, worker_id, vulnerability_type, details (cifrado), start_date, end_date, consent_id |
| `work_adjustments` | principal | Adecuación (base principal) | company_id, worker_id, description (sin condición), restricted_hazard_families, start_date, end_date, approved_by, status |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-VUL-01 | Adecuaciones | Lista | Trabajadores con adecuación vigente (sin condición) |
| SCR-VUL-02 | Registro de condición (médico) | Confidencial | Tipo, fechas, consentimiento |
| SCR-VUL-03 | Compatibilidad con el puesto | Alertas | Peligros del IPERC del puesto que la adecuación restringe |

## 7. Flujos principales

1. El médico registra la condición; se propone una adecuación.
2. SST define la adecuación; el sistema cruza con el IPERC del puesto.
3. El jefe aplica la reubicación o restricción.

## 8. Reglas de negocio

- **RN-VUL-01.** La condición solo está en la base médica; la adecuación se describe sin revelarla.
- **RN-VUL-02.** Si el puesto tiene peligros de familias restringidas, se alerta y se exige reubicación o justificación.
- **RN-VUL-03.** Los menores de edad no pueden asignarse a puestos con tareas marcadas «prohibidas para menores» (validación con la fecha de nacimiento de [TRB](M-TRB.md)).
- **RN-VUL-04.** Las adecuaciones tienen vigencia y se revisan al vencer.
- **RN-VUL-05.** El acceso a la condición exige re-autenticación y registro.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| work_adjustments | proposed → active → ended | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Adecuación incompatible con el puesto | Jefe SST | App | Inmediato |
| Adecuación por vencer | Jefe SST | App | `adjustment_notice_days` antes |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Adecuaciones vigentes (sin condición) para SST; aporte al expediente [SUN](M-SUN.md) como evidencia de protección.

## 12. Integración con otros módulos

**Consume:**

- [IPC](M-IPC.md), [TRB](M-TRB.md).

**Produce:**

- Restricciones para asignación de tareas y [PTR](M-PTR.md).

## 13. Criterios de aceptación clave

1. **Dado** un trabajador de 17 años, **cuando** se asigna a un puesto con tarea prohibida, **entonces** se bloquea.
2. **Dado** una adecuación que restringe químicos, **cuando** el puesto tiene peligro químico, **entonces** se alerta.
3. **Dado** un jefe de área, **cuando** ve la adecuación, **entonces** no ve la condición.
4. **Dado** una condición, **cuando** se consulta, **entonces** se exige re-autenticación.
5. **Dado** adecuación vencida, **cuando** llega la fecha, **entonces** se alerta.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Gestión de licencias por maternidad.

**Preguntas abiertas:**

- ¿Lista oficial de trabajos prohibidos para menores a precargar?
