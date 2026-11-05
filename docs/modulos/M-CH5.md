# M-CH5 · 4.3 Charlas de 5 minutos

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `CH5` |
| Número | 4.3 |
| Nombre | Charlas de 5 minutos |
| Grupo | 4. Operación en campo |
| Plan | P · Profesional |
| Fase | 2 · Plan Profesional |
| Origen | Mercado |
| Resuelve | Asistencia con firma |
| Tablas del catálogo | `safety_talks`, `talk_attendances` |
| Dependencias | [TRB](M-TRB.md) (Trabajadores y legajo), [FIR](M-FIR.md) (Firma electrónica de registros), [CAP](M-CAP.md) (Capacitaciones e inducciones) |
| Prefijo de permisos | `ch5.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Registrar las charlas de 5 minutos (diarias o de inicio de turno) con tema, expositor y asistencia firmada en un dispositivo, para dejar evidencia simple de sensibilización continua.

**Problema que resuelve.** Las charlas se dan pero la asistencia se toma en hojas que se pierden; no hay evidencia de que el trabajador fue sensibilizado sobre los riesgos del día.

## 3. Base normativa

- Ley 29783, art. 35 literal b y art. 49 literal g: formación e información permanente.
- D.S. 005-2012-TR, art. 33 literal g: registro de inducción, capacitación, entrenamiento y simulacros (las charlas se reportan como sensibilización si la empresa lo configura).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe de área / línea (`area_lead`) | Programa, dicta y registra charlas con firma de asistentes | `ch5.safety_talks.create`, `ch5.talk_attendances.create` |
| Supervisor SST (`sst_supervisor`) | Registra charlas y gestiona el banco de temas | `ch5.safety_talks.create`, `ch5.talk_attendances.create` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Mantiene banco de temas y consulta cumplimiento | `ch5.safety_talks.update`, `ch5.safety_talks.view` |
| Trabajador (`worker`) | Firma su asistencia; ve historial | `ch5.talk_attendances.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `safety_talks` | principal | Charla | company_id, site_id, area_id, talk_date, shift_id, topic, topic_bank_id, content_summary, speaker_worker_id, duration_minutes, photo_file_ids, iperc_row_ids, status, offline_idempotency_key |
| `talk_attendances` | principal | Asistencia firmada | talk_id, worker_id, signature_id, attended_at, method (firma, lista escaneada) |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-CH5-01 | Charlas | Lista y calendario | Charlas por día, área y expositor; % de cobertura por área |
| SCR-CH5-02 | Registrar charla (móvil) | Captura rápida | Tema (del banco o libre), resumen, foto de grupo; lista de trabajadores del área precargada; firma asistida uno tras otro |
| SCR-CH5-03 | Banco de temas | Biblioteca | Temas con contenido sugerido, vinculados a peligros del IPERC |
| SCR-CH5-04 | Mis charlas (app del trabajador) | Historial | Charlas firmadas |

## 7. Flujos principales

1. El jefe de área abre «Registrar charla»; se precargan los trabajadores asignados a su área y turno.
2. Elige tema y registra resumen y foto.
3. Cada asistente firma en el dispositivo (trazo o PIN).
4. Se cierra la charla; queda bloqueada.

## 8. Reglas de negocio

- **RN-CH5-01.** Una charla requiere tema, expositor, fecha y al menos un asistente con firma para cerrarse.
- **RN-CH5-02.** Un trabajador no puede firmar dos veces la misma charla.
- **RN-CH5-03.** Solo se listan trabajadores activos; trabajadores de otras áreas pueden agregarse con búsqueda.
- **RN-CH5-04.** La charla cerrada es inmutable.
- **RN-CH5-05.** La cobertura diaria = trabajadores del área con asistencia firmada en el día / trabajadores activos del área en el turno.
- **RN-CH5-06.** Las charlas registradas offline se sincronizan por `offline_idempotency_key`.
- **RN-CH5-07.** Si la empresa lo configura (`talks_count_as_training`), las charlas se agregan al registro de capacitación como sensibilización con su duración.
- **RN-CH5-08.** El banco de temas sugiere temas según los peligros significativos del IPERC del área.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| safety_talks | draft → closed; draft → cancelled | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Área sin charla registrada en el día | Jefe de área | App | Hora parametrizada `talk_missing_check_hour` |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Registro de charlas con asistencia firmada (PDF/Excel) por periodo; aporte al registro de capacitación en [REG](M-REG.md) si se configura; evidencia en el expediente [SUN](M-SUN.md).

## 12. Integración con otros módulos

**Consume:**

- [TRB](M-TRB.md), [FIR](M-FIR.md), [IPC](M-IPC.md).

**Produce:**

- Evidencia de sensibilización; KPI de cobertura en [DSH](M-DSH.md).

## 13. Criterios de aceptación clave

1. **Dado** una charla sin asistentes firmados, **cuando** se intenta cerrar, **entonces** se bloquea.
2. **Dado** un trabajador que ya firmó, **cuando** intenta firmar otra vez, **entonces** se rechaza.
3. **Dado** una charla cerrada, **cuando** se intenta agregar un asistente, **entonces** se rechaza.
4. **Dado** un área con 20 trabajadores y 18 firmas, **cuando** se calcula, **entonces** cobertura 90 %.
5. **Dado** `talks_count_as_training` activo, **cuando** se cierra una charla, **entonces** aparece en el registro de capacitación.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Evaluación de conocimientos de la charla.

**Preguntas abiertas:**

- ¿Las charlas cuentan para el mínimo legal de capacitaciones? A confirmar por especialista.
