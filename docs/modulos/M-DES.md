# M-DES · 5.6 Descansos médicos y ausentismo

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `DES` |
| Número | 5.6 |
| Nombre | Descansos médicos y ausentismo |
| Grupo | 5. Personas y salud ocupacional |
| Plan | C · Corporativo |
| Fase | 4 · Plan Corporativo |
| Origen | Mercado |
| Resuelve | Control e indicadores |
| Tablas del catálogo | `absences`, `medical_leave_details` (base médica) |
| Dependencias | [TRB](M-TRB.md) (Trabajadores y legajo), [EMO](M-EMO.md) (EMOs y aptitud), [ACC](M-ACC.md) (Accidentes, incidentes y enfermedades) |
| Prefijo de permisos | `des.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Registrar descansos médicos y ausencias (con detalle médico en la base médica), calcular ausentismo y días perdidos por accidente o enfermedad ocupacional para estadísticas.

**Problema que resuelve.** Los descansos médicos se gestionan en RR.HH. sin vínculo con los accidentes; los días perdidos para el índice de gravedad se calculan mal.

## 3. Base normativa

- D.S. 005-2012-TR, art. 33 y R.M. 050-2013-TR: días de descanso en los registros de accidentes.
- Normativa de EsSalud sobre CITT (certificado de incapacidad temporal) como referencia (a confirmar).
- Ley 29733: diagnóstico del descanso es dato sensible.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Recursos Humanos (`hr`) | Registra ausencias y descansos (sin diagnóstico) | `des.absences.create`, `des.absences.update` |
| Personal de salud (`health_staff`) | Registra detalle médico | `des.medical_leave_details.create` |
| Médico ocupacional (`occupational_doctor`) | Consulta y valida detalle médico | `des.medical_leave_details.view` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Consulta ausentismo y días perdidos | `des.absences.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `absences` | principal | Ausencia (base principal) | company_id, worker_id, absence_type (descanso médico, accidente de trabajo, enfermedad ocupacional, otro), start_date, end_date, days, sst_event_id, citt_number, status |
| `medical_leave_details` | médica | Detalle médico (base médica) | company_id, absence_id, worker_id, diagnosis_cie10 (cifrado), issuer, document_file_key |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-DES-01 | Ausencias | Registro | Lista por trabajador con tipo, días y vínculo a evento |
| SCR-DES-02 | Registrar descanso | Formulario | Fechas; tipo; vínculo a accidente; carga del certificado en bucket médico |
| SCR-DES-03 | Ausentismo | Indicadores | Tasa por área y mes; días perdidos por accidentes |

## 7. Flujos principales

1. RR.HH. registra la ausencia; si es por accidente, la vincula al evento de [ACC](M-ACC.md).
2. Personal de salud carga el certificado y diagnóstico en la base médica.
3. Los días se suman a días perdidos del evento para [EST](M-EST.md).

## 8. Reglas de negocio

- **RN-DES-01.** `days` = end_date − start_date + 1 (días calendario, parámetro `lost_days_mode` a confirmar).
- **RN-DES-02.** Ausencias por accidente deben vincularse a un evento de ACC.
- **RN-DES-03.** El diagnóstico solo se guarda en la base médica.
- **RN-DES-04.** Las ausencias no se solapan para el mismo trabajador.
- **RN-DES-05.** Un trabajador con ausencia vigente queda «en licencia» en [TRB](M-TRB.md).
- **RN-DES-06.** Las extensiones de descanso se registran como nueva ausencia encadenada.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| absences | registered → active → finished | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Retorno de descanso por accidente | Jefe SST, médico | App | El día de `end_date` + 1 (para EMO de reintegro) |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Ausentismo y días perdidos (insumo del índice de gravedad en [EST](M-EST.md) y del registro de accidentes en [REG](M-REG.md)).

## 12. Integración con otros módulos

**Consume:**

- [ACC](M-ACC.md), [TRB](M-TRB.md).

**Produce:**

- Días perdidos para [EST](M-EST.md) y [ACC](M-ACC.md); orden de EMO de reintegro en [EMO](M-EMO.md).

## 13. Criterios de aceptación clave

1. **Dado** descanso del 1 al 10, **cuando** se guarda, **entonces** 10 días.
2. **Dado** descanso por accidente sin evento, **cuando** se guarda, **entonces** se exige vínculo.
3. **Dado** RR.HH., **cuando** consulta un descanso, **entonces** no ve diagnóstico.
4. **Dado** ausencias solapadas, **cuando** se guarda, **entonces** se rechaza.
5. **Dado** retorno de accidente, **cuando** llega la fecha, **entonces** se alerta para EMO de reintegro.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Cálculo de subsidios.

**Preguntas abiertas:**

- ¿Días perdidos calendario o hábiles? A confirmar.
