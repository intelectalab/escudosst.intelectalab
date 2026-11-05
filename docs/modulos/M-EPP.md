# M-EPP · 4.7 EPP

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `EPP` |
| Número | 4.7 |
| Nombre | EPP |
| Grupo | 4. Operación en campo |
| Plan | E · Esencial |
| Fase | 1 · Plan Esencial |
| Origen | Ley |
| Resuelve | Entrega con firma, stock y vencimientos |
| Tablas del catálogo | `ppe_items`, `ppe_position_requirements`, `ppe_stock_movements`, `ppe_deliveries` |
| Dependencias | [TEN](M-TEN.md) (Empresas, sedes y áreas), [TRB](M-TRB.md) (Trabajadores y legajo), [FIR](M-FIR.md) (Firma electrónica de registros), [IPC](M-IPC.md) (Matriz IPERC) |
| Prefijo de permisos | `epp.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Controlar el catálogo de EPP, los requerimientos por puesto (derivados del IPERC), el stock por almacén/sede y las entregas al trabajador con firma, talla y fecha de reposición.

**Problema que resuelve.** Las entregas de EPP se firman en cuadernos; no se sabe quién tiene EPP vencido ni cuánto stock queda; SUNAFIL exige probar la entrega gratuita y adecuada al riesgo.

## 3. Base normativa

- Ley 29783, art. 60–61: el empleador proporciona EPP adecuados y gratuitos según el riesgo, y verifica su uso.
- D.S. 005-2012-TR, art. 97: EPP como último nivel de la jerarquía de controles; adecuación ergonómica.
- Normas técnicas de EPP (NTP/ANSI/EN) como referencia de certificación (a confirmar por ítem).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Define catálogo y requerimientos por puesto | `epp.ppe_items.create`, `epp.ppe_items.update`, `epp.ppe_position_requirements.create`, `epp.ppe_position_requirements.update` |
| Supervisor SST (`sst_supervisor`) | Entrega EPP y registra movimientos | `epp.ppe_deliveries.create`, `epp.ppe_stock_movements.create` |
| Jefe de área / línea (`area_lead`) | Entrega EPP en su área | `epp.ppe_deliveries.create` |
| Trabajador (`worker`) | Firma la recepción; ve su EPP y solicita reposición | `epp.ppe_deliveries.view` |
| Gerencia (`management`) | Consulta consumo | `epp.ppe_stock_movements.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `ppe_items` | principal | Catálogo de EPP | company_id, code, name, category (cabeza, ojos, auditiva, respiratoria, manos, pies, caídas, cuerpo), brand, model, certification, has_sizes, sizes, useful_life_days, requires_training, stock_min, unit |
| `ppe_position_requirements` | principal | EPP exigido por puesto | company_id, position_id, ppe_item_id, quantity, iperc_control_id, is_mandatory |
| `ppe_stock_movements` | principal | Kardex por sede | company_id, site_id, ppe_item_id, size, movement_type (ingreso, entrega, devolución, baja, ajuste), quantity, balance_after, reference, moved_at |
| `ppe_deliveries` | principal | Entrega al trabajador | company_id, worker_id, ppe_item_id, size, quantity, delivered_on, replace_by, reason (dotación inicial, reposición por desgaste, pérdida, cambio de puesto), delivered_by, signature_id, returned_on, offline_idempotency_key |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-EPP-01 | Catálogo de EPP | Ítems | Lista con categoría, certificación, vida útil, stock por sede con alerta de mínimo |
| SCR-EPP-02 | Matriz puesto × EPP | Requerimientos | Cuadrícula; origen del requerimiento (control IPERC) |
| SCR-EPP-03 | Entregar EPP (móvil) | Entrega con firma | Buscar trabajador por DNI o fotocheck QR; se muestran EPP requeridos y estado (vigente, por vencer, faltante); seleccionar talla; firma del trabajador |
| SCR-EPP-04 | Kardex | Stock | Movimientos por sede e ítem; ingreso de compras; bajas |
| SCR-EPP-05 | Estado por trabajador | Cumplimiento | Trabajadores con EPP faltante o vencido |
| SCR-EPP-06 | Mi EPP (app del trabajador) | Ver y solicitar | EPP recibidos con fecha de reposición; botón «Solicitar reposición» |

## 7. Flujos principales

1. Al aprobarse un IPERC, los controles de EPP generan requerimientos por puesto.
2. Al ingresar o cambiar de puesto, el trabajador tiene EPP pendientes.
3. El supervisor entrega; el trabajador firma; se descuenta stock y se calcula `replace_by`.
4. Antes de `replace_by`, se alerta para reposición; la entrega de reposición registra la devolución del anterior.

## 8. Reglas de negocio

- **RN-EPP-01.** `replace_by` = `delivered_on` + `useful_life_days` del ítem (parámetro por ítem); los ítems sin vida útil no generan reposición automática.
- **RN-EPP-02.** Toda entrega requiere firma del trabajador ([FIR](M-FIR.md)); sin firma queda «pendiente de firma» y no cuenta como entregada.
- **RN-EPP-03.** Una entrega descuenta stock de la sede; no se permite stock negativo salvo que `allow_negative_stock` esté activo (queda alertado).
- **RN-EPP-04.** Un trabajador cumple EPP cuando tiene todos los ítems obligatorios de su puesto con entrega firmada y `replace_by` no vencido.
- **RN-EPP-05.** El EPP se entrega sin costo para el trabajador; el sistema no registra descuentos.
- **RN-EPP-06.** Un ítem con `requires_training` exige capacitación de uso vigente del trabajador o muestra advertencia.
- **RN-EPP-07.** Las entregas firmadas son inmutables; una devolución o baja se registra como movimiento nuevo.
- **RN-EPP-08.** El stock por debajo de `stock_min` genera alerta.
- **RN-EPP-09.** Las entregas offline se sincronizan por `offline_idempotency_key`.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| ppe_deliveries | pending_signature → delivered → due_for_replacement → replaced / returned | `due_for_replacement` automático según `replace_by` |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| EPP por reponer | Trabajador, supervisor | App, WhatsApp | `ppe_replace_notice_days` antes de `replace_by` |
| Stock bajo el mínimo | Jefe SST, encargado de almacén | App, correo | Al ocurrir |
| Trabajador nuevo con EPP pendiente | Supervisor del área | App | Al alta o cambio de puesto |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Registro de entrega de EPP por trabajador con firmas (constancia).
- Kardex y consumo por sede, área e ítem.
- Aporte al registro de equipos de seguridad o emergencia ([REG](M-REG.md)) y al expediente [SUN](M-SUN.md) por trabajador.

## 12. Integración con otros módulos

**Consume:**

- [IPC](M-IPC.md) (controles EPP), [TRB](M-TRB.md), [FIR](M-FIR.md), [CAP](M-CAP.md).

**Produce:**

- Estado de EPP para [FOT](M-FOT.md), [PTR](M-PTR.md) y [SUN](M-SUN.md); KPI en [DSH](M-DSH.md).

## 13. Criterios de aceptación clave

1. **Dado** un casco con vida útil 365 días entregado el 2026-01-10, **cuando** se registra, **entonces** `replace_by` = 2027-01-10.
2. **Dado** una entrega sin firma, **cuando** se consulta el cumplimiento del trabajador, **entonces** el ítem figura como faltante.
3. **Dado** stock 0 y `allow_negative_stock` desactivado, **cuando** se intenta entregar, **entonces** se bloquea.
4. **Dado** un trabajador con todos los EPP obligatorios vigentes, **cuando** se consulta, **entonces** figura «cumple».
5. **Dado** un trabajador cambia a un puesto que exige arnés, **cuando** se guarda el cambio, **entonces** aparece el arnés como pendiente.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Compras y órdenes a proveedores.

**Preguntas abiertas:**

- ¿Se requiere control por lote o número de serie (arneses)?
