# M-SIM · 4.9 Simulacros y brigadas

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `SIM` |
| Número | 4.9 |
| Nombre | Simulacros y brigadas |
| Grupo | 4. Operación en campo |
| Plan | P · Profesional |
| Fase | 2 · Plan Profesional |
| Origen | Ley |
| Resuelve | Programación y participación |
| Tablas del catálogo | `drills`, `drill_participants`, `brigades`, `brigade_members` |
| Dependencias | [TRB](M-TRB.md) (Trabajadores y legajo), [EQE](M-EQE.md) (Equipos de emergencia y botiquines), [CAP](M-CAP.md) (Capacitaciones e inducciones), [ACR](M-ACR.md) (Acciones correctivas) |
| Prefijo de permisos | `sim.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Programar y evaluar simulacros (sismo, incendio, derrame, primeros auxilios), registrar participación y tiempos, y gestionar las brigadas de emergencia con sus integrantes y capacitación.

**Problema que resuelve.** Los simulacros se hacen sin evaluación ni registro de participación; las brigadas existen en papel con personal que ya no trabaja en la empresa.

## 3. Base normativa

- Ley 29783, art. 83 y D.S. 005-2012-TR, art. 83: preparación y respuesta ante emergencias; simulacros periódicos.
- D.S. 005-2012-TR, art. 33 literal g: registro de inducción, capacitación, entrenamiento y simulacros de emergencia.
- Normativa de INDECI y simulacros nacionales (referencia; frecuencia `drills_per_year` a confirmar).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Programa simulacros, conforma brigadas, evalúa | `sim.drills.create`, `sim.drills.update`, `sim.brigades.create`, `sim.brigade_members.create` |
| Supervisor SST (`sst_supervisor`) | Ejecuta y evalúa simulacros en su sede | `sim.drills.update`, `sim.drill_participants.create` |
| Jefe de área / línea (`area_lead`) | Registra participación de su área | `sim.drill_participants.create` |
| Trabajador (`worker`) | Confirma su participación; ve su brigada | `sim.drill_participants.view`, `sim.brigade_members.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `drills` | principal | Simulacro | company_id, site_id, scenario (sismo, incendio, derrame, evacuación, primeros auxilios), drill_date, planned_at, executed_at, alarm_time, evacuation_complete_time, total_evacuated, evaluation (jsonb), observations, status, report_file_id |
| `drill_participants` | principal | Participación | drill_id, worker_id, role (participante, brigadista, observador), present, signature_id |
| `brigades` | principal | Brigada | company_id, site_id, brigade_type (evacuación, primeros auxilios, contra incendio, comunicaciones), leader_worker_id, status |
| `brigade_members` | principal | Integrante | brigade_id, worker_id, start_date, end_date, training_status |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-SIM-01 | Simulacros | Programación | Calendario por sede; estado; tiempos de evacuación |
| SCR-SIM-02 | Ejecutar simulacro (móvil) | Registro en vivo | Cronómetro de alarma a evacuación completa; conteo por zona segura; lista de asistencia con check |
| SCR-SIM-03 | Evaluación | Informe | Criterios configurables; observaciones; acciones de mejora |
| SCR-SIM-04 | Brigadas | Conformación | Brigadas por sede con integrantes, líder y estado de capacitación |

## 7. Flujos principales

1. Se programa el simulacro (vinculado al PASST).
2. Se ejecuta registrando tiempos y asistencia; los brigadistas marcan su rol.
3. Se evalúa; las observaciones generan acciones correctivas.

## 8. Reglas de negocio

- **RN-SIM-01.** Tiempo de evacuación = `evacuation_complete_time` − `alarm_time`; se compara con la meta parametrizada por sede.
- **RN-SIM-02.** Cada sede debe tener al menos `drills_per_year` simulacros ejecutados al año (parámetro); si no, brecha.
- **RN-SIM-03.** Un brigadista debe ser trabajador activo y tener la capacitación de brigada vigente; si se cesa, sale automáticamente de la brigada.
- **RN-SIM-04.** Cada brigada obligatoria por sede requiere un líder y al menos `min_brigade_members`.
- **RN-SIM-05.** El simulacro solo se cierra con evaluación e informe; queda inmutable.
- **RN-SIM-06.** Las observaciones negativas generan acciones en [ACR](M-ACR.md).
- **RN-SIM-07.** Un simulacro planificado vinculado a una actividad del PASST marca esa actividad como ejecutada al cerrarse ([PAS](M-PAS.md)).
- **RN-SIM-08.** La asistencia se registra por trabajador presente; los trabajadores activos de la sede no marcados se reportan como «no participó» para seguimiento.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| drills | planned → executed → evaluated → closed; planned → cancelled | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Simulacro programado | Brigadistas, jefes de área | App, correo | `drill_notice_days` antes (configurable sin aviso para simulacros sorpresa) |
| Brigada incompleta | Jefe SST | App | Al quedar bajo el mínimo |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Registro de simulacros (parte del registro de inducción, capacitación, entrenamiento y simulacros en [REG](M-REG.md)); informe de simulacro; aporte al expediente [SUN](M-SUN.md).

## 12. Integración con otros módulos

**Consume:**

- [TRB](M-TRB.md), [CAP](M-CAP.md), [EQE](M-EQE.md), [MAP](M-MAP.md).

**Produce:**

- Ejecución de actividades en [PAS](M-PAS.md); acciones en [ACR](M-ACR.md).

## 13. Criterios de aceptación clave

1. **Dado** alarma 10:00:00 y evacuación completa 10:04:30, **cuando** se registra, **entonces** tiempo 4 min 30 s comparado con la meta.
2. **Dado** un brigadista cesado, **cuando** se procesa el cese, **entonces** sale de la brigada y se alerta si queda incompleta.
3. **Dado** un simulacro sin evaluación, **cuando** se intenta cerrar, **entonces** se bloquea.
4. **Dado** una sede sin simulacros en el año, **cuando** corre el autodiagnóstico, **entonces** aparece la brecha.
5. **Dado** una observación negativa, **cuando** se guarda, **entonces** se crea acción correctiva.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Plan de contingencias completo (se gestiona como documento en [DOC](M-DOC.md)).

**Preguntas abiertas:**

- ¿Se requiere integrar el simulacro nacional multipeligro como evento predefinido?
