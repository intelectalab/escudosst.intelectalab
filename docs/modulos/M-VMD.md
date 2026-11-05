# M-VMD · 5.5 Vigilancia médica

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `VMD` |
| Número | 5.5 |
| Nombre | Vigilancia médica |
| Grupo | 5. Personas y salud ocupacional |
| Plan | C · Corporativo |
| Fase | 4 · Plan Corporativo |
| Origen | Ley |
| Resuelve | Historia ocupacional con acceso restringido (Ley 29733) |
| Tablas del catálogo | `surveillance_programs` (base médica), `surveillance_cases` (base médica), `clinical_notes` (base médica) |
| Dependencias | [EMO](M-EMO.md) (EMOs y aptitud), [MAG](M-MAG.md) (Monitoreo de agentes), [USR](M-USR.md) (Usuarios, roles y permisos), [AUD](M-AUD.md) (Bitácora de auditoría) |
| Prefijo de permisos | `vmd.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Gestionar la vigilancia médica ocupacional: programas por exposición, casos de seguimiento (p. ej. hipoacusia inducida por ruido, trastornos musculoesqueléticos) y notas clínicas, todo en la base médica con acceso restringido.

**Problema que resuelve.** Los resultados de EMO no se usan para vigilar tendencias; los casos no tienen seguimiento y la información clínica circula sin protección.

## 3. Base normativa

- Ley 29783, art. 49 literal d y 71; D.S. 005-2012-TR, art. 102–103: vigilancia de la salud y confidencialidad.
- R.M. 312-2011/MINSA y guías técnicas de vigilancia (a confirmar vigencia).
- Ley 29733 y D.S. 016-2024-JUS.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Médico ocupacional (`occupational_doctor`) | Crea programas, abre casos, registra notas | `vmd.surveillance_programs.create`, `vmd.surveillance_cases.create`, `vmd.surveillance_cases.update`, `vmd.clinical_notes.create`, `vmd.clinical_notes.view` |
| Personal de salud (`health_staff`) | Agenda seguimientos y registra datos permitidos | `vmd.surveillance_cases.view` |
| Trabajador (`worker`) | Ve sus casos y notas compartidas con él (re-autenticación) | `vmd.surveillance_cases.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `surveillance_programs` | médica | Programa de vigilancia (base médica) | company_id, code, name, exposure_agent, target_positions, criteria, periodicity_months, status |
| `surveillance_cases` | médica | Caso en seguimiento | company_id, program_id, worker_id, opened_on, case_type, severity, status, next_followup_on, closed_on |
| `clinical_notes` | médica | Nota clínica | case_id, worker_id, note_date, author_doctor_id, content (cifrado), shared_with_worker |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-VMD-01 | Programas de vigilancia | Configuración | Programas por agente con población objetivo |
| SCR-VMD-02 | Casos | Seguimiento | Casos con próximo control y severidad (solo médico) |
| SCR-VMD-03 | Nota clínica | Registro | Editor; re-autenticación; compartir con el trabajador |
| SCR-VMD-04 | Indicadores agregados | Para SST | Casos por programa y área agregados con `min_group_size`, sin nombres |

## 7. Flujos principales

1. El médico define programas a partir de exposiciones (MAG/IPERC).
2. Los resultados de EMO que cumplen criterios abren casos.
3. Se registran controles y notas; al cerrar, se actualiza el estado.

## 8. Reglas de negocio

- **RN-VMD-01.** Todo dato vive en la base médica; SST y gerencia solo ven indicadores agregados con `min_group_size`.
- **RN-VMD-02.** Lectura de casos y notas exige re-autenticación y registro.
- **RN-VMD-03.** Solo el médico autor o con rol médico del alcance edita notas; las notas firmadas no se modifican (se agregan adendas).
- **RN-VMD-04.** Un caso con control vencido genera alerta al médico.
- **RN-VMD-05.** Cambios de restricciones derivados de un caso se publican vía [EMO](M-EMO.md) como restricción funcional sin diagnóstico.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| surveillance_cases | open → in_followup → closed | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Control de caso vencido | Médico ocupacional | App | Al vencer `next_followup_on` |
| Cita de control | Trabajador | App, WhatsApp (sin datos clínicos) | `followup_notice_days` antes |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Informe agregado de vigilancia (sin nombres) para el comité y la revisión por la dirección.

## 12. Integración con otros módulos

**Consume:**

- [EMO](M-EMO.md), [MAG](M-MAG.md).

**Produce:**

- Restricciones funcionales vía [EMO](M-EMO.md); indicadores agregados para [DSH](M-DSH.md).

## 13. Criterios de aceptación clave

1. **Dado** un Jefe SST, **cuando** pide la lista de casos, **entonces** recibe 403 y ve solo agregados.
2. **Dado** un médico, **cuando** abre una nota, **entonces** se registra el acceso.
3. **Dado** un área con 3 casos y `min_group_size` 5, **cuando** se ve el agregado, **entonces** se muestra a nivel sede.
4. **Dado** una nota firmada, **cuando** se edita, **entonces** se exige adenda.
5. **Dado** un control vencido, **cuando** corre el job, **entonces** se alerta al médico.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Tratamiento médico.

**Preguntas abiertas:**

- ¿Qué programas de vigilancia se precargan?
