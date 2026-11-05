# M-OBS · 4.6 Observación de conducta segura

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `OBS` |
| Número | 4.6 |
| Nombre | Observación de conducta segura |
| Grupo | 4. Operación en campo |
| Plan | C · Corporativo |
| Fase | 4 · Plan Corporativo |
| Origen | Mercado |
| Resuelve | Seguridad basada en el comportamiento |
| Tablas del catálogo | `behavior_observations`, `behavior_observation_items` |
| Dependencias | [TRB](M-TRB.md) (Trabajadores y legajo), [ACR](M-ACR.md) (Acciones correctivas) |
| Prefijo de permisos | `obs.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Registrar observaciones de conducta segura (seguridad basada en el comportamiento) con listas de comportamientos, retroalimentación inmediata y análisis de tendencias, sin fines disciplinarios.

**Problema que resuelve.** Las empresas maduras quieren reducir actos inseguros midiendo comportamientos; en papel no se pueden analizar tendencias.

## 3. Base normativa

- Ley 29783, art. 23 y 27: cultura de prevención y participación; no existe obligación legal específica (práctica de mercado).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe de área / línea (`area_lead`) | Realiza observaciones | `obs.behavior_observations.create`, `obs.behavior_observation_items.create` |
| Supervisor SST (`sst_supervisor`) | Realiza observaciones y analiza | `obs.behavior_observations.create`, `obs.behavior_observations.view` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Configura listas y analiza | `obs.behavior_observations.view` |
| Trabajador (`worker`) | Puede observar a pares si se habilita | `obs.behavior_observations.create` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `behavior_observations` | principal | Observación | company_id, site_id, area_id, observed_at, observer_user_id, observed_group (sin identificar persona por defecto), task, feedback_given, safe_count, at_risk_count, comments |
| `behavior_observation_items` | principal | Comportamiento observado | observation_id, category (EPP, posición, herramientas, procedimientos, orden), behavior, result (seguro/en riesgo), comment |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-OBS-01 | Observar (móvil) | Captura rápida | Categorías con botones seguro/en riesgo; retroalimentación dada sí/no |
| SCR-OBS-02 | Tendencias | Análisis | % de comportamiento seguro por categoría, área y mes |
| SCR-OBS-03 | Lista de comportamientos | Configuración | Categorías y comportamientos |

## 7. Flujos principales

1. El observador registra comportamientos, conversa con el equipo y marca la retroalimentación.
2. Los comportamientos en riesgo recurrentes generan acciones.

## 8. Reglas de negocio

- **RN-OBS-01.** Por defecto no se registra la identidad del trabajador observado (no disciplinario).
- **RN-OBS-02.** % seguro = comportamientos seguros / total observados.
- **RN-OBS-03.** Una categoría con % seguro bajo `bbs_alert_pct` en el mes genera alerta y sugerencia de acción.
- **RN-OBS-04.** La observación cerrada no se edita.
- **RN-OBS-05.** Las listas de comportamientos se versionan.

## 9. Estados y transiciones

No aplica (registro simple: draft → submitted).

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Categoría bajo umbral | Jefe SST | App | Al cierre del mes |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Tendencias de comportamiento seguro por área y categoría.

## 12. Integración con otros módulos

**Consume:**

- [TRB](M-TRB.md).

**Produce:**

- Acciones en [ACR](M-ACR.md); KPI en [DSH](M-DSH.md).

## 13. Criterios de aceptación clave

1. **Dado** 8 seguros y 2 en riesgo, **cuando** se guarda, **entonces** % seguro 80 %.
2. **Dado** una observación, **cuando** se registra, **entonces** no se pide identificar al trabajador.
3. **Dado** una categoría bajo umbral, **cuando** cierra el mes, **entonces** se alerta.
4. **Dado** una observación cerrada, **cuando** se edita, **entonces** se rechaza.
5. **Dado** una lista nueva versión, **cuando** se consulta una observación antigua, **entonces** conserva sus comportamientos.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Uso disciplinario.

**Preguntas abiertas:**

- ¿Algún cliente requiere identificar al observado?
