# M-DSH · 1.6 Dashboard e indicadores

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `DSH` |
| Número | 1.6 |
| Nombre | Dashboard e indicadores |
| Grupo | 1. Núcleo de la plataforma |
| Plan | E · Esencial |
| Fase | 0 · Cimientos |
| Origen | Mercado |
| Resuelve | Cumplimiento en tiempo real por sede |
| Tablas del catálogo | `kpi_definitions`, `kpi_snapshots`, `dashboards`, `dashboard_widgets` |
| Dependencias | [TEN](M-TEN.md) (Empresas, sedes y áreas), [USR](M-USR.md) (Usuarios, roles y permisos) |
| Prefijo de permisos | `dsh.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Mostrar el cumplimiento SST en tiempo real por empresa y sede con indicadores definidos como datos (no código), tableros por rol y semáforos. Sirve a gerencia, al Jefe SST y a la consultora para decidir dónde actuar.

**Problema que resuelve.** El Jefe SST arma reportes mensuales a mano copiando de varios Excel; gerencia no ve el estado real y se entera de los incumplimientos tarde. No hay una vista única de «qué tan preparados estamos» frente a SUNAFIL.

## 3. Base normativa

- D.S. 005-2012-TR, art. 86–88: evaluación del SG-SST, indicadores y seguimiento.
- Ley 29783, art. 40: verificación del cumplimiento de objetivos y metas del sistema.
- R.M. 050-2013-TR: registro de estadísticas (los índices se calculan en [EST](M-EST.md)).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Configura tableros y widgets de la empresa; ve todos los KPI | `dsh.dashboards.create`, `dsh.dashboards.update`, `dsh.dashboard_widgets.create`, `dsh.dashboard_widgets.update`, `dsh.kpi_snapshots.view` |
| Gerencia (`management`) | Consulta el tablero gerencial | `dsh.dashboards.view`, `dsh.kpi_snapshots.view` |
| Miembro del Comité SST (`committee_member`) | Consulta el tablero del comité | `dsh.dashboards.view` |
| Jefe de área / línea (`area_lead`) | Ve el tablero filtrado por su ámbito | `dsh.dashboards.view` |
| Consultor SST (`consultant`) | Ve el tablero consolidado de sus empresas cliente | `dsh.dashboards.view`, `dsh.kpi_snapshots.view` |
| Dueño de la plataforma (`platform_owner`) | Mantiene el catálogo global de KPI | `dsh.kpi_definitions.create`, `dsh.kpi_definitions.update` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `kpi_definitions` | principal | KPI declarativo | company_id (nulo en globales), code, name, module_code, formula_key, unit, direction (higher_better/lower_better), green_threshold, amber_threshold, granularity, required_feature |
| `kpi_snapshots` | principal | Valor calculado por periodo y ámbito | company_id, kpi_definition_id, period_start, period_end, site_id, area_id, value, numerator, denominator, status_color, computed_at |
| `dashboards` | principal | Tablero por rol o personalizado | company_id, name, audience_role, is_default, layout (jsonb) |
| `dashboard_widgets` | principal | Widget del tablero | dashboard_id, kpi_definition_id, widget_type (tarjeta, línea, barra, semáforo, lista), filters (jsonb), position |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-DSH-01 | Tablero principal | Vista de cumplimiento | Tarjetas con % de cumplimiento PASST, capacitaciones, EMO vigentes, inspecciones, acciones vencidas, índices; filtro sede y periodo; semáforos |
| SCR-DSH-02 | Detalle del KPI | Explicar un indicador | Serie histórica, numerador/denominador, desglose por sede/área, enlace a la lista de registros que lo componen |
| SCR-DSH-03 | Editor de tablero | Personalizar | Arrastrar widgets, elegir KPI disponibles según plan, filtros fijos |
| SCR-DSH-04 | Tablero consolidado | Multiempresa (consultora o grupo) | Tabla de empresas × KPI con semáforos; orden por riesgo |
| SCR-DSH-05 | Resumen móvil | Vista compacta para gerencia | 4 indicadores clave en tarjetas grandes y alertas críticas |

## 7. Flujos principales

**Cálculo de indicadores**
1. Un job programado (y los eventos relevantes) recalcula los `kpi_snapshots` del periodo actual por empresa, sede y área.
2. Cada `formula_key` corresponde a un calculador registrado en código que devuelve numerador y denominador.
3. Se asigna color según umbrales y `direction`.
4. El tablero lee solo snapshots; el detalle permite «ver registros» con la misma consulta de origen.

## 8. Reglas de negocio

- **RN-DSH-01.** Los KPI se definen como datos (`kpi_definitions`); los umbrales verde/ámbar son parámetros por empresa con valor por defecto global.
- **RN-DSH-02.** Un widget solo se muestra si el módulo de su KPI está habilitado en la suscripción; si no, aparece bloqueado con invitación a mejorar el plan.
- **RN-DSH-03.** Todo valor mostrado guarda numerador y denominador y permite navegar a los registros que lo componen (trazabilidad).
- **RN-DSH-04.** Los filtros respetan `membership_scopes`: un Jefe de área solo ve los KPI de su ámbito.
- **RN-DSH-05.** Ningún KPI muestra datos médicos individuales; los de salud se agregan con un mínimo de `min_group_size` personas para evitar reidentificación.
- **RN-DSH-06.** Los snapshots de periodos cerrados no se recalculan salvo reproceso explícito auditado.
- **RN-DSH-07.** El tablero consolidado de la consultora solo incluye empresas con `consultant_assignments` vigentes.
- **RN-DSH-08.** Si un denominador es cero el KPI se muestra «No aplica» (gris), no 0 % ni 100 %.

## 9. Estados y transiciones

No aplica máquina de estados; los snapshots pasan de `provisional` (periodo abierto) a `closed` (periodo cerrado).

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| KPI pasa a rojo | Jefe SST | App, correo | Al recalcular |
| Resumen mensual de indicadores | Gerencia | Correo | Día parametrizado del mes |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Exportación PDF del tablero con fecha y filtros (anexo para reuniones del comité y revisión por la dirección).
- Exportación Excel de la serie histórica de cada KPI.

## 12. Integración con otros módulos

**Consume:**

- Datos de todos los módulos mediante calculadores registrados (PASST, CAP, EMO, INS, ACR, EST, etc.).

**Produce:**

- Indicadores para [RVD](M-RVD.md), [SUN](M-SUN.md) (autodiagnóstico) y el panel de [CON](M-CON.md).

## 13. Criterios de aceptación clave

1. **Dado** una empresa en plan Esencial, **cuando** abre el tablero, **entonces** los widgets de EMO aparecen bloqueados con invitación a mejorar.
2. **Dado** un KPI de capacitaciones con 45 de 50 trabajadores capacitados, **cuando** se pulsa «ver registros», **entonces** se listan los 50 con su estado.
3. **Dado** un Jefe de área con alcance de una sede, **cuando** abre el tablero, **entonces** los valores corresponden solo a esa sede.
4. **Dado** una sede sin trabajadores, **cuando** se calcula un KPI por trabajador, **entonces** se muestra «No aplica» en gris.
5. **Dado** un KPI de aptitud médica con 3 trabajadores en un área, **cuando** se consulta por área, **entonces** se muestra agregado a nivel superior por `min_group_size`.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Constructor libre de consultas o BI externo.
- Indicadores financieros.

**Preguntas abiertas:**

- ¿Qué conjunto inicial de KPI por plan se precarga (lista a validar con especialista SST)?
