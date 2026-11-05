# M-ATS · 4.4 ATS

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `ATS` |
| Número | 4.4 |
| Nombre | ATS |
| Grupo | 4. Operación en campo |
| Plan | P · Profesional |
| Fase | 2 · Plan Profesional |
| Origen | Mercado |
| Resuelve | Análisis de trabajo seguro antes de la tarea |
| Tablas del catálogo | `ats_forms`, `ats_steps`, `ats_participants` |
| Dependencias | [IPC](M-IPC.md) (Matriz IPERC), [TRB](M-TRB.md) (Trabajadores y legajo), [FIR](M-FIR.md) (Firma electrónica de registros), [PET](M-PET.md) (PETS y procedimientos) |
| Prefijo de permisos | `ats.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Elaborar el Análisis de Trabajo Seguro antes de ejecutar una tarea: pasos, peligros, riesgos y controles por paso, con la firma de todo el equipo ejecutor y del supervisor, en el móvil y en el lugar de trabajo.

**Problema que resuelve.** El ATS se llena en papel al final del día para cumplir; no refleja la tarea real y no se puede verificar si se hizo antes de empezar.

## 3. Base normativa

- Ley 29783, art. 21: medidas de prevención; D.S. 005-2012-TR, art. 77 (identificación de peligros en las actividades).
- D.S. 024-2016-EM, Anexo 18 (ATS en minería) y G.050 en construcción vía [PSE](M-PSE.md).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe de área / línea (`area_lead`) | Elabora el ATS con su equipo y firma como supervisor | `ats.ats_forms.create`, `ats.ats_steps.create`, `ats.ats_participants.create`, `ats.ats_forms.approve` |
| Supervisor SST (`sst_supervisor`) | Elabora, revisa y valida ATS en campo | `ats.ats_forms.create`, `ats.ats_forms.approve` |
| Trabajador (`worker`) | Participa y firma el ATS | `ats.ats_participants.create` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Consulta y analiza ATS | `ats.ats_forms.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `ats_forms` | principal | ATS de una tarea | company_id, site_id, area_id, task_description, location_text, latitude, longitude, work_date, start_time, procedure_id, iperc_row_ids, ppe_required, status, supervisor_worker_id, closed_at, offline_idempotency_key |
| `ats_steps` | principal | Paso de la tarea | ats_form_id, order, step_description, hazards, risks, controls |
| `ats_participants` | principal | Ejecutor firmante | ats_form_id, worker_id, role (ejecutor, vigía, supervisor), signature_id, signed_at |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-ATS-01 | ATS del día | Lista | ATS por área con estado y hora de firma |
| SCR-ATS-02 | Nuevo ATS (móvil) | Elaborar en sitio | Tarea; copiar desde PETS o ATS anterior; pasos con peligros/controles sugeridos del IPERC; EPP requerido; foto del lugar |
| SCR-ATS-03 | Firmas del equipo | Firma asistida | Lista de participantes; firma uno por uno; firma del supervisor al final |
| SCR-ATS-04 | Cierre del ATS | Fin de tarea | Hora de término, observaciones, incidentes ocurridos |

## 7. Flujos principales

1. Antes de iniciar, el líder crea el ATS (o lo copia del PETS); el sistema sugiere peligros y controles del IPERC de la tarea.
2. El equipo revisa pasos; cada ejecutor firma.
3. El supervisor valida y firma; el ATS queda «activo».
4. Al terminar, se cierra con observaciones; si ocurrió un evento, se enlaza a [ACC](M-ACC.md).

## 8. Reglas de negocio

- **RN-ATS-01.** El ATS requiere al menos un paso con peligro y control, y al menos un ejecutor firmante además del supervisor.
- **RN-ATS-02.** La firma de los ejecutores debe registrarse antes de `start_time` o, si se firma después, queda marcada «firmado tardío» y cuenta en un indicador.
- **RN-ATS-03.** Un ejecutor sin la capacitación obligatoria del puesto vigente aparece con advertencia; si `ats_block_untrained` está activo, no puede firmar.
- **RN-ATS-04.** El ATS activo no se edita; ajustes se registran como observación.
- **RN-ATS-05.** El ATS se cierra el mismo día; si no, pasa a «no cerrado» al final de la jornada.
- **RN-ATS-06.** Los ATS offline se sincronizan por `offline_idempotency_key`.
- **RN-ATS-07.** Si la tarea corresponde a un trabajo de alto riesgo y [PTR](M-PTR.md) está habilitado, el sistema exige vincular el PETAR.
- **RN-ATS-08.** Los peligros y controles sugeridos provienen de la versión vigente del IPERC.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| ats_forms | draft → signed_by_team → active → closed; active → not_closed | `not_closed` automático al fin de jornada |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| ATS sin cierre al fin de jornada | Supervisor | App | Hora `ats_close_check_hour` |
| ATS firmado tardíamente | Jefe SST | App | Diario resumido |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- ATS en PDF con firmas; indicador de ATS firmados antes de iniciar; evidencia de planificación de tareas en el expediente [SUN](M-SUN.md).

## 12. Integración con otros módulos

**Consume:**

- [IPC](M-IPC.md), [PET](M-PET.md), [CAP](M-CAP.md), [FIR](M-FIR.md).

**Produce:**

- Evidencia de control operacional; vínculos con [ACC](M-ACC.md) y [PTR](M-PTR.md).

## 13. Criterios de aceptación clave

1. **Dado** un ATS sin ejecutores firmantes, **cuando** el supervisor intenta activarlo, **entonces** se bloquea.
2. **Dado** un ejecutor firma después de `start_time`, **cuando** se registra, **entonces** queda marcado «firmado tardío».
3. **Dado** `ats_block_untrained` activo y un ejecutor sin capacitación vigente, **cuando** intenta firmar, **entonces** se impide.
4. **Dado** un ATS activo al final de la jornada, **cuando** corre el job, **entonces** pasa a «no cerrado» y se alerta al supervisor.
5. **Dado** una tarea de trabajo en altura con PTR habilitado, **cuando** se crea el ATS, **entonces** se exige vincular el PETAR.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Planificación de mantenimiento.

**Preguntas abiertas:**

- ¿Los ATS requieren la firma del Jefe SST en alguna circunstancia?
