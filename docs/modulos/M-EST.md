# M-EST · 6.5 Estadísticas SST

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `EST` |
| Número | 6.5 |
| Nombre | Estadísticas SST |
| Grupo | 6. Eventos y mejora continua |
| Plan | E · Esencial |
| Fase | 1 · Plan Esencial |
| Origen | Ley |
| Resuelve | Índices de frecuencia, gravedad y accidentabilidad |
| Tablas del catálogo | `man_hours`, `monthly_statistics` |
| Dependencias | [ACC](M-ACC.md) (Accidentes, incidentes y enfermedades), [TRB](M-TRB.md) (Trabajadores y legajo), [TEN](M-TEN.md) (Empresas, sedes y áreas), [DES](M-DES.md) (Descansos médicos y ausentismo) |
| Prefijo de permisos | `est.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Calcular mensual y acumuladamente las estadísticas SST (índices de frecuencia, gravedad y accidentabilidad, y otros) por empresa y sede, a partir de horas-hombre trabajadas y eventos, con fórmulas y factores como parámetros.

**Problema que resuelve.** Los índices se calculan a mano con fórmulas inconsistentes (distintos factores, horas estimadas); el registro de estadísticas es obligatorio y debe ser coherente con el registro de accidentes.

## 3. Base normativa

- D.S. 005-2012-TR, art. 33 literal e: registro de estadísticas de seguridad y salud.
- R.M. 050-2013-TR: formato del registro de estadísticas (índices de frecuencia, gravedad y accidentabilidad).
- Factores de cálculo de índices (p. ej. 1 000 000 o 200 000 horas-hombre) como parámetros `stats_factor` con vigencia, **a confirmar por especialista SST**; D.S. 024-2016-EM define fórmulas propias en minería (vía [PSE](M-PSE.md)).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Registra/valida horas-hombre, cierra el mes estadístico | `est.man_hours.create`, `est.man_hours.update`, `est.monthly_statistics.close` |
| Recursos Humanos (`hr`) | Carga horas-hombre desde planilla | `est.man_hours.create` |
| Gerencia (`management`) | Consulta | `est.monthly_statistics.view` |
| Miembro del Comité SST (`committee_member`) | Consulta | `est.monthly_statistics.view` |
| Auditor externo (`external_auditor`) | Consulta el registro | `est.monthly_statistics.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `man_hours` | principal | Horas-hombre trabajadas | company_id, site_id, period (año-mes), headcount_avg, hours_worked, source (manual, cálculo por turnos, importación), includes_contractors, contractor_hours, validated_by |
| `monthly_statistics` | principal | Estadística mensual (snapshot) | company_id, site_id, period, accidents_lost_time, accidents_no_lost_time, fatal_accidents, dangerous_incidents, incidents, occupational_diseases, lost_days, hours_worked, frequency_index, severity_index, accident_rate, formula_version, factor_used, status (provisional/cerrado), closed_at, adjustments (jsonb) |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-EST-01 | Horas-hombre | Carga mensual | Tabla por sede y mes; cálculo sugerido = trabajadores activos × horas de turno × días; importación Excel |
| SCR-EST-02 | Estadísticas | Índices | Tabla mensual y acumulada con IF, IG, IA; gráficos de tendencia; comparativo por sede |
| SCR-EST-03 | Configuración de fórmulas | Parámetros | Fórmulas vigentes, factor, inclusión de contratistas, con vigencia y marca «a confirmar» |
| SCR-EST-04 | Cierre de mes | Validación | Conciliación con eventos del mes; cerrar |

## 7. Flujos principales

1. RR.HH. o SST carga horas-hombre del mes (o acepta el cálculo sugerido por turnos).
2. El sistema recalcula los indicadores provisionales con eventos del mes.
3. El Jefe SST concilia y cierra el mes; se congela el snapshot con la versión de fórmula y factor.
4. Ajustes posteriores (días perdidos que siguen corriendo) se registran como ajustes con fecha y se reflejan en acumulados.

## 8. Reglas de negocio

- **RN-EST-01.** IF = (n.º de accidentes con tiempo perdido × `stats_factor`) / horas-hombre trabajadas; IG = (días perdidos × `stats_factor`) / horas-hombre; IA = IF × IG / `accident_rate_divisor` (fórmulas y divisores parametrizados con vigencia, a confirmar).
- **RN-EST-02.** Las fórmulas y factores se guardan como `formula_version` en cada snapshot; cambiarlas no altera meses cerrados.
- **RN-EST-03.** Un mes no se cierra sin horas-hombre validadas para todas las sedes activas.
- **RN-EST-04.** Los eventos se cuentan por mes de ocurrencia; los días perdidos se imputan al mes del evento (o al mes en que se pierden, según `lost_days_allocation`, a confirmar).
- **RN-EST-05.** Si horas-hombre = 0 los índices son «No aplica».
- **RN-EST-06.** Los acumulados anuales se calculan sumando numeradores y horas, no promediando índices.
- **RN-EST-07.** La inclusión de contratistas se rige por `include_contractors_in_stats` y se indica en el reporte.
- **RN-EST-08.** Los meses cerrados son inmutables salvo ajustes registrados con motivo y usuario.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| monthly_statistics | provisional → closed; closed → adjusted (con ajustes registrados) | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Mes sin horas-hombre cargadas | RR.HH., Jefe SST | App, correo | Día `man_hours_due_day` del mes siguiente |
| Mes pendiente de cierre | Jefe SST | App | `stats_close_notice_days` después del fin de mes |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Registro de estadísticas de seguridad y salud (formato referencial R.M. 050-2013-TR) en [REG](M-REG.md).
- Índices mensuales y acumulados por sede; tendencia; comparativo entre sedes; aporte a [RVD](M-RVD.md) y al expediente [SUN](M-SUN.md).

## 12. Integración con otros módulos

**Consume:**

- [ACC](M-ACC.md), [DES](M-DES.md), [TRB](M-TRB.md), [TEN](M-TEN.md) (turnos).

**Produce:**

- Índices para [DSH](M-DSH.md), [RVD](M-RVD.md), [REG](M-REG.md), [SUN](M-SUN.md), [CON](M-CON.md).

## 13. Criterios de aceptación clave

1. **Dado** 2 accidentes con tiempo perdido, 30 días perdidos, 100 000 horas y `stats_factor` 1 000 000, **cuando** se calcula, **entonces** IF = 20, IG = 300 e IA según el divisor configurado.
2. **Dado** un mes cerrado, **cuando** se cambia el factor, **entonces** el mes cerrado conserva su factor y valores.
3. **Dado** una sede sin horas-hombre, **cuando** se intenta cerrar el mes, **entonces** se bloquea.
4. **Dado** horas-hombre = 0, **cuando** se calcula, **entonces** los índices muestran «No aplica».
5. **Dado** acumulado de enero y febrero, **cuando** se calcula, **entonces** usa la suma de accidentes y horas, no el promedio de índices.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Benchmark con otras empresas.

**Preguntas abiertas:**

- Factor y fórmula oficial del índice de accidentabilidad vigentes: **a confirmar por especialista SST**.
