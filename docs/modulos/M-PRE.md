# M-PRE · 2.9 Presupuesto SST

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `PRE` |
| Número | 2.9 |
| Nombre | Presupuesto SST |
| Grupo | 2. Planificación del sistema de gestión |
| Plan | C · Corporativo |
| Fase | 4 · Plan Corporativo |
| Origen | Mercado |
| Resuelve | Costo por actividad y ejecución |
| Tablas del catálogo | `budgets`, `budget_lines`, `budget_executions` |
| Dependencias | [PAS](M-PAS.md) (Programa Anual SST (PASST)), [PLA](M-PLA.md) (Plan Anual SST) |
| Prefijo de permisos | `pre.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Registrar el presupuesto SST anual por línea (vinculada a actividades del PASST o rubros) y su ejecución, mostrando lo comprometido frente a lo ejecutado. Los montos son datos que ingresa la empresa cliente.

**Problema que resuelve.** Gerencia aprueba montos globales para SST sin saber en qué se gastan; el Jefe SST no puede justificar recursos en la revisión por la dirección.

## 3. Base normativa

- Ley 29783, art. 38–39: el plan debe contar con recursos; D.S. 005-2012-TR, art. 80.
- ISO 45001, cláusula 7.1 (recursos).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Formula el presupuesto y registra ejecuciones | `pre.budgets.create`, `pre.budgets.update`, `pre.budget_lines.create`, `pre.budget_lines.update`, `pre.budget_executions.create` |
| Gerencia (`management`) | Aprueba el presupuesto | `pre.budgets.approve`, `pre.budgets.view` |
| Jefe de área / línea (`area_lead`) | Registra ejecuciones de su área | `pre.budget_executions.create` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `budgets` | principal | Presupuesto anual | company_id, year, currency (PEN), total_approved, status, approved_by, approved_at |
| `budget_lines` | principal | Línea presupuestal | budget_id, category (EPP, capacitación, EMO, monitoreo, señalización, emergencia, otros), program_activity_id, site_id, amount_planned |
| `budget_executions` | principal | Gasto ejecutado | budget_line_id, executed_on, amount, supplier_name, document_ref, file_id |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-PRE-01 | Presupuesto anual | Resumen | Total aprobado, ejecutado, % de ejecución por categoría y sede |
| SCR-PRE-02 | Líneas | Detalle | Tabla de líneas con planificado vs. ejecutado; vínculo a actividad PASST |
| SCR-PRE-03 | Registrar gasto | Ejecución | Fecha, monto, proveedor, comprobante adjunto |

## 7. Flujos principales

1. El Jefe SST crea líneas por categoría y actividad; envía a gerencia.
2. Gerencia aprueba; el presupuesto se bloquea.
3. Se registran gastos contra líneas; el sistema muestra ejecución.

## 8. Reglas de negocio

- **RN-PRE-01.** Los montos los ingresa la empresa cliente en soles; EscudoSST no propone montos.
- **RN-PRE-02.** La suma de líneas debe igualar `total_approved` para aprobar.
- **RN-PRE-03.** Un gasto no puede registrarse en una línea de un presupuesto no aprobado.
- **RN-PRE-04.** Exceder el monto de una línea requiere confirmación y queda marcado como sobreejecución.
- **RN-PRE-05.** Un presupuesto aprobado solo se modifica mediante reasignación entre líneas aprobada por gerencia (auditada).

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| budgets | draft → submitted → approved → closed | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Línea supera el umbral de ejecución | Jefe SST | App | Al pasar `budget_line_alert_pct` |
| Presupuesto enviado a aprobación | Gerencia | Correo | Inmediato |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Ejecución presupuestal por categoría, sede y actividad (Excel/PDF) para la revisión por la dirección.

## 12. Integración con otros módulos

**Consume:**

- [PAS](M-PAS.md): actividades; [PLA](M-PLA.md): plan del año.

**Produce:**

- Recursos financieros para [PLA](M-PLA.md) y [RVD](M-RVD.md).

## 13. Criterios de aceptación clave

1. **Dado** líneas que suman distinto al total, **cuando** se envía a aprobación, **entonces** se bloquea.
2. **Dado** un gasto que excede la línea, **cuando** se registra, **entonces** se pide confirmación y se marca sobreejecución.
3. **Dado** un presupuesto en borrador, **cuando** se intenta registrar un gasto, **entonces** se rechaza.
4. **Dado** un presupuesto aprobado, **cuando** gerencia reasigna entre líneas, **entonces** la reasignación queda auditada.
5. **Dado** el tablero de ejecución, **cuando** se consulta, **entonces** muestra % por categoría.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Integración contable o de compras.

**Preguntas abiertas:**

- ¿Se requiere separar gasto de capital y gasto operativo?
