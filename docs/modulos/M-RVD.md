# M-RVD · 6.6 Revisión por la dirección

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `RVD` |
| Número | 6.6 |
| Nombre | Revisión por la dirección |
| Grupo | 6. Eventos y mejora continua |
| Plan | C · Corporativo |
| Fase | 4 · Plan Corporativo |
| Origen | Ley / ISO |
| Resuelve | Informe anual a gerencia |
| Tablas del catálogo | `management_reviews`, `management_review_outputs` |
| Dependencias | [DSH](M-DSH.md) (Dashboard e indicadores), [EST](M-EST.md) (Estadísticas SST), [ACR](M-ACR.md) (Acciones correctivas), [POL](M-POL.md) (Política y objetivos SST), [AUI](M-AUI.md) (Auditorías) |
| Prefijo de permisos | `rvd.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Preparar la revisión por la dirección del SG-SST con un informe que consolida indicadores, cumplimiento de objetivos, auditorías, eventos y acciones, y registrar las decisiones (salidas) de la alta dirección.

**Problema que resuelve.** La revisión por la dirección se hace como reunión informal sin informe ni decisiones registradas.

## 3. Base normativa

- Ley 29783, art. 41 y D.S. 005-2012-TR, art. 88: revisión del SG-SST por el empleador con periodicidad (parámetro `management_review_months`, a confirmar).
- ISO 45001, cláusula 9.3.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Prepara el informe y la agenda | `rvd.management_reviews.create`, `rvd.management_reviews.update` |
| Gerencia (`management`) | Revisa, decide y firma | `rvd.management_reviews.approve`, `rvd.management_review_outputs.create` |
| Miembro del Comité SST (`committee_member`) | Consulta | `rvd.management_reviews.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `management_reviews` | principal | Revisión | company_id, period_start, period_end, held_on, participants, inputs_snapshot (jsonb con KPI, objetivos, auditorías, eventos, acciones), report_file_id, status |
| `management_review_outputs` | principal | Decisión | review_id, output_type (cambio de política, objetivos, recursos, acciones), description, responsible_user_id, due_date, corrective_action_id |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-RVD-01 | Revisiones | Lista | Periodo, fecha, estado |
| SCR-RVD-02 | Preparar revisión | Entradas automáticas | Snapshot de indicadores, objetivos, auditorías, eventos, acciones abiertas, cambios legales |
| SCR-RVD-03 | Decisiones | Salidas | Registrar decisiones con responsable |
| SCR-RVD-04 | Informe | PDF | Informe con firmas |

## 7. Flujos principales

1. El Jefe SST genera las entradas del periodo (snapshot congelado).
2. Gerencia revisa y registra decisiones.
3. Las decisiones generan acciones; se firma el informe.

## 8. Reglas de negocio

- **RN-RVD-01.** Las entradas se congelan como snapshot al generar el informe.
- **RN-RVD-02.** Toda decisión con responsable genera acción en [ACR](M-ACR.md).
- **RN-RVD-03.** La revisión se cierra con firma de gerencia.
- **RN-RVD-04.** Se alerta si no hay revisión en `management_review_months`.
- **RN-RVD-05.** El informe firmado es inmutable.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| management_reviews | draft → held → signed | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Revisión pendiente | Jefe SST, gerencia | Correo | `management_review_notice_days` antes |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Informe de revisión por la dirección (PDF firmado); aporte al expediente [SUN](M-SUN.md).

## 12. Integración con otros módulos

**Consume:**

- [DSH](M-DSH.md), [EST](M-EST.md), [POL](M-POL.md), [AUI](M-AUI.md), [ACR](M-ACR.md), [MRL](M-MRL.md).

**Produce:**

- Decisiones y acciones; cambios a [POL](M-POL.md) y [PLA](M-PLA.md).

## 13. Criterios de aceptación clave

1. **Dado** entradas generadas, **cuando** cambia un KPI después, **entonces** el snapshot no cambia.
2. **Dado** una decisión con responsable, **cuando** se guarda, **entonces** se crea acción.
3. **Dado** una revisión sin firma, **cuando** se intenta cerrar, **entonces** se bloquea.
4. **Dado** periodo sin revisión, **cuando** corre el job, **entonces** se alerta.
5. **Dado** informe firmado, **cuando** se edita, **entonces** se rechaza.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Reuniones de directorio.

**Preguntas abiertas:**

- ¿Periodicidad anual o semestral por defecto?
