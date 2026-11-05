# M-SUS · 1.9 Suscripciones y facturación

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `SUS` |
| Número | 1.9 |
| Nombre | Suscripciones y facturación |
| Grupo | 1. Núcleo de la plataforma |
| Plan | I · Interno |
| Fase | 0 · Cimientos |
| Origen | Plataforma |
| Resuelve | Planes, cobros y comprobantes (panel del dueño del SaaS) |
| Tablas del catálogo | `plans`, `plan_modules`, `addons`, `subscriptions`, `subscription_addons`, `invoices`, `payments`, `feature_flags` |
| Dependencias | [TEN](M-TEN.md) (Empresas, sedes y áreas) |
| Prefijo de permisos | `sus.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Panel interno del dueño del SaaS para definir planes, módulos por plan, complementos, suscripciones de cada cuenta, comprobantes y pagos, y gobernar los feature flags que habilitan los módulos por empresa.

**Problema que resuelve.** Sin una capa de suscripción, habilitar módulos por cliente sería manual y propenso a errores; bajar de plan podría borrar datos. Los precios aún no están definidos, por lo que deben ser configuración y no código.

## 3. Base normativa

- Normativa SUNAT de comprobantes electrónicos (facturación electrónica a través de un OSE/PSE; proveedor a definir).
- Código de Protección y Defensa del Consumidor (Ley 29571) en lo aplicable a contratación (a confirmar).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Dueño de la plataforma (`platform_owner`) | Define planes, módulos, complementos; gestiona suscripciones, comprobantes y pagos | `sus.plans.create`, `sus.plans.update`, `sus.plan_modules.update`, `sus.addons.update`, `sus.subscriptions.create`, `sus.subscriptions.update`, `sus.invoices.create`, `sus.payments.create`, `sus.feature_flags.update` |
| Soporte de la plataforma (`platform_support`) | Consulta el estado de suscripción para soporte | `sus.subscriptions.view` |
| Administrador de cuenta (`account_admin`) | Ve su plan, complementos, comprobantes; solicita cambios; activa o desactiva AUL y VTA por empresa | `sus.subscriptions.view`, `sus.invoices.view`, `sus.feature_flags.update` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `plans` | principal | Planes comerciales | code (E, P, C, K), name, description, max_companies, max_workers, price_reference (nulo: «Por definir»), is_active |
| `plan_modules` | principal | Módulos incluidos por plan | plan_id, module_code |
| `addons` | principal | Complementos contratables | code (MOC, OFF, IAA, PSE, ISO, AMB, HOM), name, module_code, is_active |
| `subscriptions` | principal | Suscripción de una cuenta | account_id, plan_id, status, period (mensual/anual), started_at, current_period_end, cancel_at, managed_companies (consultora) |
| `subscription_addons` | principal | Complementos de una suscripción | subscription_id, addon_id, company_id (opcional), started_at, ended_at |
| `invoices` | principal | Comprobantes emitidos | account_id, subscription_id, series, number, issue_date, currency (PEN), amounts, sunat_status, pdf_file_id, xml_file_id |
| `payments` | principal | Pagos recibidos | invoice_id, method, reference, paid_at, amount, status |
| `feature_flags` | principal | Habilitación efectiva por empresa y módulo | company_id, module_code, mode (enabled/read_only/locked/disabled_by_company), source (plan/addon/override/trial/downgrade/core/company_setting), company_enabled (interruptor de la empresa, solo AUL y VTA), toggled_by, toggled_at, reason, set_by, valid_until |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-SUS-01 | Planes y módulos | Configurar matriz plan × módulo | Tabla de 61 módulos con casillas por plan (Esencial 22, Profesional 39, Corporativo 51), 7 complementos; complementos; precios mostrados como «Por definir» |
| SCR-SUS-02 | Cuentas y suscripciones | Gestionar clientes | Lista de cuentas con plan, estado, empresas; acciones cambiar plan, suspender, reactivar |
| SCR-SUS-03 | Detalle de suscripción | Historial y complementos | Línea de tiempo de cambios; complementos por empresa; feature flags resultantes |
| SCR-SUS-04 | Comprobantes y pagos | Facturación | Emisión vía OSE, estado SUNAT, registro de pagos |
| SCR-SUS-05 | Mi suscripción (cliente) | Vista del administrador de cuenta | Plan, complementos, módulos bloqueados con «Solicitar mejora» |
| SCR-SUS-06 | Módulos opcionales por empresa | Activación por empresa | Por cada empresa de la cuenta: interruptores de Aula virtual (AUL) y Venta de cursos (VTA) cuando el plan los incluye; casos guiados «No usa aula», «Capacita solo a su personal», «Vende cursos»; advertencias de efecto |

## 7. Flujos principales

1. Al crear o cambiar una suscripción se recalculan los `feature_flags` de cada empresa: plan (acumulativo según `plan_incluye`) + complementos + overrides.
2. Al bajar de plan, los módulos que dejan de estar incluidos pasan a `read_only` (datos visibles, sin escritura); nunca se borran.
3. Al suspender por impago, todos los módulos pasan a `read_only` salvo exportaciones.
4. **Activación por empresa (AUL y VTA).** Aunque el plan los incluya, el administrador de cuenta decide por empresa: (a) empresa que no usa aula: AUL y VTA desactivados, capacita solo en [CAP](M-CAP.md); (b) empresa que capacita solo a su personal: AUL activo y VTA desactivado (caso interno, sin pasarela); (c) instituto o consultora que vende cursos: AUL y VTA activos. VTA nace desactivado.

## 8. Reglas de negocio

- **RN-SUS-01.** Ningún precio se escribe en código ni en pantallas: `price_reference` es nulo y se muestra «Por definir» hasta que el dueño lo configure.
- **RN-SUS-02.** Los feature flags efectivos se derivan de plan + complementos + overrides; cualquier cambio queda en `audit_logs`.
- **RN-SUS-03.** Bajar de plan nunca borra datos: los módulos excluidos pasan a `read_only`.
- **RN-SUS-04.** El plan Consultora (K) habilita CON y se cuenta por empresas gestionadas; cada empresa cliente conserva su propio plan.
- **RN-SUS-05.** Un complemento requiere un plan base activo (E, P o C) en la empresa donde se aplica.
- **RN-SUS-06.** Los comprobantes se emiten en soles (PEN) a través del OSE configurado detrás de una interfaz de proveedor.
- **RN-SUS-07.** AUL y VTA son activables por empresa: el flag efectivo es `enabled` solo si el plan (o un override) lo incluye y `company_enabled` es verdadero; si el plan lo incluye y la empresa lo desactivó, el modo es `disabled_by_company` (el menú no lo muestra y no se invita a mejorar el plan).
- **RN-SUS-08.** VTA se crea con `company_enabled = false` en toda empresa nueva; AUL con `company_enabled = true` cuando el plan lo incluye.
- **RN-SUS-09.** VTA solo puede activarse si AUL está activo en la misma empresa; AUL no puede desactivarse mientras VTA esté activo.
- **RN-SUS-10.** Desactivar AUL o VTA por empresa no borra datos: quedan en solo lectura para el administrador; los certificados emitidos siguen verificables y las matrículas pagadas conservan su acceso hasta su vencimiento.
- **RN-SUS-11.** La activación o desactivación por empresa la hace solo el administrador de cuenta y queda en `audit_logs` con motivo.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| feature_flags (AUL, VTA) | desactivado por la empresa ↔ enabled; cualquiera → read_only / locked según plan | `enabled` exige plan que lo incluya; VTA exige AUL `enabled` |
| subscriptions | trial → active → past_due → suspended → active; active → cancelled | `past_due` al vencer el periodo sin pago; `suspended` tras la gracia parametrizada |
| invoices | draft → issued → accepted_sunat / rejected_sunat → voided | Según respuesta del OSE |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Suscripción por renovar | Administrador de cuenta | Correo | `renewal_notice_days` antes |
| Pago vencido | Administrador de cuenta, dueño | Correo | Al pasar a `past_due` |
| Cambio de plan aplicado | Administrador de cuenta | Correo, app | Inmediato |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Cuentas por plan y complemento; altas y bajas.
- Comprobantes emitidos por periodo con estado SUNAT.

## 12. Integración con otros módulos

**Consume:**

- [TEN](M-TEN.md): cuentas y empresas.

**Produce:**

- `feature_flags` consultados por todo el sistema; límites para [TEN](M-TEN.md) y [CON](M-CON.md).
- `plans`, `plan_modules`, `addons` y `price_reference` leídos por la web pública ([WEB](M-WEB.md)) para planes, comparativa y complementos («Solicitar cotización» si no hay precio).

## 13. Criterios de aceptación clave

1. **Dado** una empresa en plan Profesional con datos de PTR creados por un override, **cuando** se quita el override, **entonces** PTR pasa a solo lectura y los datos siguen visibles.
2. **Dado** un administrador de cuenta, **cuando** abre «Mi suscripción», **entonces** los precios aparecen como «Por definir».
3. **Dado** una suscripción suspendida, **cuando** un usuario intenta crear una inspección, **entonces** la acción se rechaza y puede exportar sus registros.
4. **Dado** un complemento IAA, **cuando** se intenta asignar a una empresa sin plan base activo, **entonces** el sistema lo impide.
5. **Dado** un cambio de plan, **cuando** se aplica, **entonces** los feature flags de todas las empresas de la cuenta se recalculan.
6. **Dado** una empresa nueva en plan Profesional, **cuando** se crea, **entonces** AUL queda activo y VTA desactivado por la empresa.
7. **Dado** una empresa con AUL desactivado, **cuando** el administrador intenta activar VTA, **entonces** se rechaza indicando que primero debe activar el aula.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Pasarela de pago en línea para cobrar la suscripción de EscudoSST (a definir; distinta de la pasarela que cada empresa configura en [VTA](M-VTA.md)).
- Definición de precios.

**Preguntas abiertas:**

- ¿Qué OSE/PSE se usará para facturación electrónica?
- ¿Se ofrecerá periodo de prueba y con qué módulos?
