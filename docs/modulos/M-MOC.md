# M-MOC · 3.6 Gestión del cambio

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `MOC` |
| Número | 3.6 |
| Nombre | Gestión del cambio |
| Grupo | 3. Gestión de riesgos |
| Plan | + · Complemento |
| Fase | 5 · Complementos restantes |
| Origen | ISO 45001 |
| Resuelve | Evaluar riesgos antes de cambios |
| Tablas del catálogo | `change_requests`, `change_reviews` |
| Dependencias | [IPC](M-IPC.md) (Matriz IPERC), [ACR](M-ACR.md) (Acciones correctivas) |
| Prefijo de permisos | `moc.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Evaluar los riesgos SST antes de implementar cambios (procesos, equipos, materiales, organización) mediante una solicitud de cambio con revisión multidisciplinaria y actualización previa del IPERC.

**Problema que resuelve.** Los cambios se implementan sin evaluar riesgos y generan accidentes; ISO 45001 exige gestionar el cambio.

## 3. Base normativa

- ISO 45001:2018, cláusula 8.1.3 (gestión del cambio).
- Ley 29783, art. 57 y D.S. 005-2012-TR, art. 77: actualizar la evaluación de riesgos ante cambios.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe de área / línea (`area_lead`) | Solicita el cambio | `moc.change_requests.create` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Evalúa riesgos y aprueba | `moc.change_reviews.create`, `moc.change_requests.approve` |
| Gerencia (`management`) | Aprueba cambios mayores | `moc.change_requests.approve` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `change_requests` | principal | Solicitud de cambio | company_id, code, title, change_type (proceso, equipo, material, organización, normativo), site_id, area_id, description, is_temporary, planned_date, impact_level, status, iperc_matrix_id |
| `change_reviews` | principal | Revisión por disciplina | change_request_id, reviewer_user_id, discipline (SST, mantenimiento, operaciones, salud), opinion, conditions, decision, reviewed_at |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-MOC-01 | Solicitudes de cambio | Lista | Código, tipo, estado, nivel de impacto |
| SCR-MOC-02 | Nueva solicitud | Formulario | Descripción, tipo, temporal/permanente, área, fecha prevista |
| SCR-MOC-03 | Revisión | Evaluación | Opiniones por disciplina; condiciones; vínculo a nueva versión del IPERC |

## 7. Flujos principales

1. Se solicita el cambio; el Jefe SST define revisores.
2. Se revisa y se exige nueva versión del IPERC si hay riesgos nuevos.
3. Se aprueba; condiciones se convierten en acciones; tras implementar se verifica.

## 8. Reglas de negocio

- **RN-MOC-01.** Un cambio no puede aprobarse sin todas las revisiones requeridas.
- **RN-MOC-02.** Si el cambio introduce peligros nuevos, exige una versión del IPERC aprobada antes de su implementación.
- **RN-MOC-03.** Los cambios de impacto alto requieren aprobación de gerencia.
- **RN-MOC-04.** Los cambios temporales tienen fecha de fin y se revierten o convierten en permanentes con nueva revisión.
- **RN-MOC-05.** Las condiciones de aprobación se convierten en acciones en [ACR](M-ACR.md).

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| change_requests | draft → under_review → approved / rejected → implemented → verified → closed | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Revisión asignada | Revisor | App | Inmediato |
| Cambio temporal por vencer | Solicitante | App | `moc_temp_notice_days` antes |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Registro de cambios con evaluación y estado (evidencia para auditoría ISO).

## 12. Integración con otros módulos

**Consume:**

- [IPC](M-IPC.md).

**Produce:**

- Revisión de [IPC](M-IPC.md); acciones en [ACR](M-ACR.md).

## 13. Criterios de aceptación clave

1. **Dado** un cambio con peligros nuevos sin IPERC aprobado, **cuando** se intenta implementar, **entonces** se bloquea.
2. **Dado** una revisión pendiente, **cuando** se intenta aprobar, **entonces** se bloquea.
3. **Dado** un cambio de impacto alto, **cuando** el Jefe SST aprueba, **entonces** queda pendiente de gerencia.
4. **Dado** condiciones de aprobación, **cuando** se aprueba, **entonces** se crean acciones.
5. **Dado** un cambio temporal vencido, **cuando** corre el job, **entonces** se notifica al solicitante.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Gestión de proyectos de ingeniería.

**Preguntas abiertas:**

- ¿Qué criterios definen impacto alto?
