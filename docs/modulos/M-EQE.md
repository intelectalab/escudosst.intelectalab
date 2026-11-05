# M-EQE · 4.8 Equipos de emergencia y botiquines

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `EQE` |
| Número | 4.8 |
| Nombre | Equipos de emergencia y botiquines |
| Grupo | 4. Operación en campo |
| Plan | P · Profesional |
| Fase | 2 · Plan Profesional |
| Origen | Ley |
| Resuelve | Inventario y mantenimiento |
| Tablas del catálogo | `emergency_equipment`, `equipment_checks`, `first_aid_kits`, `first_aid_kit_items` |
| Dependencias | [TEN](M-TEN.md) (Empresas, sedes y áreas), [MAP](M-MAP.md) (Mapa de riesgos) |
| Prefijo de permisos | `eqe.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Mantener el inventario de equipos de emergencia (extintores, luces, alarmas, camillas, lavaojos, duchas) y botiquines con sus inspecciones periódicas, recargas, mantenimientos y contenido mínimo.

**Problema que resuelve.** Los extintores vencen sin que nadie lo note y los botiquines están vacíos; el registro de equipos de seguridad o emergencia es obligatorio y se observa en inspecciones.

## 3. Base normativa

- D.S. 005-2012-TR, art. 33 literal f: registro de equipos de seguridad o emergencia.
- NTP 350.043-1 (extintores portátiles: selección, distribución, inspección, mantenimiento, recarga) — versión a confirmar.
- Contenido mínimo de botiquín según normativa sectorial o del Ministerio de Salud (a confirmar).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Registra equipos, configura periodicidades, consulta estado | `eqe.emergency_equipment.create`, `eqe.emergency_equipment.update`, `eqe.first_aid_kits.create`, `eqe.first_aid_kit_items.update` |
| Supervisor SST (`sst_supervisor`) | Realiza inspecciones mensuales | `eqe.equipment_checks.create` |
| Jefe de área / línea (`area_lead`) | Inspecciona equipos de su área | `eqe.equipment_checks.create` |
| Trabajador (`worker`) | Reporta un equipo dañado escaneando su QR | `eqe.equipment_checks.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `emergency_equipment` | principal | Equipo | company_id, site_id, area_id, equipment_type (extintor PQS, CO2, agua, K; luz de emergencia; alarma; camilla; lavaojos), code, capacity, location_text, qr_token, last_recharge_on, next_recharge_on, last_hydrostatic_test_on, next_hydrostatic_test_on, manufacture_date, status |
| `equipment_checks` | principal | Inspección o mantenimiento | equipment_id, check_type (inspección mensual, recarga, mantenimiento, prueba hidrostática), checked_on, checklist (jsonb), result (operativo/observado/inoperativo), photo_file_id, checked_by, provider_name |
| `first_aid_kits` | principal | Botiquín | company_id, site_id, area_id, code, kit_type, location_text, qr_token, status |
| `first_aid_kit_items` | principal | Contenido del botiquín | kit_id, item_name, required_qty, current_qty, expires_on |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-EQE-01 | Inventario | Lista y mapa | Equipos por sede/tipo con semáforo de vigencia (recarga, prueba, inspección mensual) |
| SCR-EQE-02 | Inspección por QR (móvil) | Escanear y revisar | Checklist del tipo (presión, precinto, señalización, acceso libre); foto; resultado |
| SCR-EQE-03 | Botiquines | Contenido | Ítems con cantidad mínima, actual y vencimiento; reposición |
| SCR-EQE-04 | Calendario de mantenimientos | Programación | Recargas y pruebas por mes |

## 7. Flujos principales

1. Se registra el equipo con fechas; se genera e imprime su QR.
2. Mensualmente el responsable escanea el QR y completa el checklist.
3. Un resultado inoperativo crea acción correctiva y marca el equipo en rojo en el mapa.
4. Las recargas y pruebas registradas actualizan las próximas fechas.

## 8. Reglas de negocio

- **RN-EQE-01.** `next_recharge_on` = última recarga + `extinguisher_recharge_months` y `next_hydrostatic_test_on` = última prueba + `hydrostatic_test_years` (parámetros por tipo, a confirmar NTP).
- **RN-EQE-02.** Cada equipo exige inspección cada `equipment_check_days`; si no la tiene, queda «inspección vencida».
- **RN-EQE-03.** Un equipo inoperativo no cuenta como disponible y genera acción correctiva.
- **RN-EQE-04.** Un botiquín está conforme si todos los ítems tienen `current_qty` ≥ `required_qty` y no están vencidos.
- **RN-EQE-05.** La inspección por QR registra fecha, hora y usuario; el QR no expone datos fuera de la empresa sin sesión.
- **RN-EQE-06.** Los equipos dados de baja conservan historial.
- **RN-EQE-07.** Un extintor cuya `manufacture_date` supera la vida útil máxima parametrizada (`extinguisher_max_life_years`, a confirmar NTP) pasa a «fuera de servicio» aunque tenga recarga vigente.
- **RN-EQE-08.** Cada sede debe tener el número mínimo de extintores por tipo de riesgo configurado; si no, aparece como brecha en el autodiagnóstico [SUN](M-SUN.md).

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| emergency_equipment | operational ↔ observed; operational → out_of_service → operational; → retired | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Recarga o prueba por vencer | Jefe SST | App, correo | `equipment_due_notice_days` antes |
| Inspección mensual vencida | Responsable del área | App | Al vencer |
| Ítem de botiquín vencido o faltante | Responsable | App | Al detectarlo |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Registro de equipos de seguridad o emergencia (formato referencial R.M. 050-2013-TR) en [REG](M-REG.md).
- Calendario y estado de mantenimiento; aporte al expediente [SUN](M-SUN.md).

## 12. Integración con otros módulos

**Consume:**

- [TEN](M-TEN.md).

**Produce:**

- Equipos para [MAP](M-MAP.md) y [SIM](M-SIM.md); acciones en [ACR](M-ACR.md).

## 13. Criterios de aceptación clave

1. **Dado** un extintor recargado el 2026-03-01 con periodicidad 12 meses, **cuando** se registra, **entonces** próxima recarga 2027-03-01.
2. **Dado** un extintor sin inspección en `equipment_check_days`, **cuando** corre el job, **entonces** queda «inspección vencida».
3. **Dado** una inspección «inoperativo», **cuando** se guarda, **entonces** se crea acción y el equipo aparece en rojo.
4. **Dado** un botiquín con gasas vencidas, **cuando** se consulta, **entonces** figura «no conforme».
5. **Dado** un QR de extintor, **cuando** lo escanea alguien sin sesión, **entonces** se pide iniciar sesión.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Sistemas fijos contra incendio (rociadores) detallados.

**Preguntas abiertas:**

- ¿Periodicidades de NTP 350.043-1 vigentes? A confirmar.
