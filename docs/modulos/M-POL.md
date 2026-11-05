# M-POL · 2.2 Política y objetivos SST

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `POL` |
| Número | 2.2 |
| Nombre | Política y objetivos SST |
| Grupo | 2. Planificación del sistema de gestión |
| Plan | E · Esencial |
| Fase | 1 · Plan Esencial |
| Origen | Ley |
| Resuelve | Versionada, aprobada y difundida |
| Tablas del catálogo | `sst_policies`, `sst_objectives`, `objective_targets` |
| Dependencias | [DOC](M-DOC.md) (Gestión documental), [FIR](M-FIR.md) (Firma electrónica de registros), [COM](M-COM.md) (Comité o Supervisor SST) |
| Prefijo de permisos | `pol.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Gestionar la Política SST versionada (redacción, aprobación por la alta dirección, difusión con cargo) y los objetivos SST con metas medibles e indicadores vinculados a la política.

**Problema que resuelve.** Muchas empresas tienen una política genérica descargada de internet, sin firma de gerencia, sin difusión probada y sin objetivos medibles; SUNAFIL pide la política firmada, exhibida y conocida, y objetivos con seguimiento.

## 3. Base normativa

- Ley 29783, art. 22 y 23: política SST por escrito, firmada por el empleador, con principios mínimos (protección, cumplimiento legal, participación de trabajadores, mejora continua, integración con otros sistemas).
- D.S. 005-2012-TR, art. 32: la política es documento obligatorio del SG-SST; art. 25: exhibición en lugar visible.
- Ley 29783, art. 38 y D.S. 005-2012-TR, art. 78–79: objetivos SST documentados, medibles y con indicadores.
- Revisión de la política: parámetro `policy_review_months` (a confirmar).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Redacta la política, propone objetivos y metas, registra avances | `pol.sst_policies.create`, `pol.sst_policies.update`, `pol.sst_objectives.create`, `pol.sst_objectives.update`, `pol.objective_targets.create`, `pol.objective_targets.update` |
| Gerencia (`management`) | Aprueba y firma la política y los objetivos | `pol.sst_policies.approve`, `pol.sst_objectives.approve` |
| Miembro del Comité SST (`committee_member`) | Revisa y emite opinión sobre la política | `pol.sst_policies.view`, `pol.sst_policies.comment` |
| Consultor SST (`consultant`) | Redacta en nombre de la empresa cliente | `pol.sst_policies.create`, `pol.sst_objectives.create` |
| Trabajador (`worker`) | Lee la política y firma su difusión | `pol.sst_policies.view` |
| Auditor externo (`external_auditor`) | Consulta política y objetivos | `pol.sst_policies.view`, `pol.sst_objectives.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `sst_policies` | principal | Versión de la política SST | company_id, version_label, content (texto enriquecido), principles_checklist (jsonb con los principios legales marcados), document_version_id, status, approved_by, approved_at, effective_from, next_review_date |
| `sst_objectives` | principal | Objetivo SST | company_id, policy_id, code, title, description, principle_ref, indicator_kpi_id, owner_worker_id, year, status |
| `objective_targets` | principal | Meta medible del objetivo | objective_id, period (trimestre/semestre/año), target_value, unit, actual_value, measured_on, status_color |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-POL-01 | Política SST | Ver vigente y versiones | Texto, firma de gerencia, fecha; % de trabajadores que firmaron el cargo; botón «Nueva versión» |
| SCR-POL-02 | Editor de política | Redactar | Editor de texto con plantilla; lista de verificación de principios del art. 23 que debe estar completa; vista previa del PDF |
| SCR-POL-03 | Objetivos y metas | Seguimiento | Tarjetas por objetivo con indicador, meta, valor actual y semáforo; vínculo al principio de la política |
| SCR-POL-04 | Registro de avance | Medir metas | Valor medido, fecha, evidencia; cálculo automático cuando la meta está ligada a un KPI |
| SCR-POL-05 | Política (app del trabajador) | Leer y firmar | Texto en formato móvil y botón «Conozco la política» |

## 7. Flujos principales

1. El Jefe SST crea una versión desde la plantilla; marca los principios mínimos del art. 23.
2. La envía a revisión; el comité (o supervisor) puede comentar.
3. Gerencia aprueba con firma OTP ([FIR](M-FIR.md)); se genera el PDF y la versión de documento en [DOC](M-DOC.md).
4. Al entrar en vigor, se lanza la campaña de cargos a todos los trabajadores activos y se genera el afiche para exhibición.
5. Los objetivos se crean vinculados a la política vigente, con metas por periodo; su avance se mide manual o automáticamente desde [DSH](M-DSH.md).

## 8. Reglas de negocio

- **RN-POL-01.** No se puede enviar a aprobación una política sin todos los principios obligatorios del art. 23 marcados como incluidos.
- **RN-POL-02.** Solo el rol `management` aprueba la política; la aprobación exige firma con OTP.
- **RN-POL-03.** Solo una versión vigente a la vez; la anterior pasa a obsoleta y se conserva.
- **RN-POL-04.** Toda nueva versión vigente genera una campaña de cargos para todos los trabajadores activos y para los nuevos al ingresar.
- **RN-POL-05.** Cada objetivo debe estar ligado a la política vigente y tener al menos una meta con valor, unidad y periodo.
- **RN-POL-06.** Si una meta está ligada a un KPI, `actual_value` se toma del último snapshot y no se edita a mano.
- **RN-POL-07.** El color de la meta se calcula con el umbral parametrizado `objective_amber_pct` respecto a la meta.
- **RN-POL-08.** Al cumplirse `next_review_date` sin nueva versión, la política muestra «revisión pendiente» en ámbar.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| sst_policies | draft → in_review → approved → effective → obsolete | Devolución a draft desde in_review |
| sst_objectives | draft → approved → in_progress → achieved / not_achieved / cancelled | Cierre al final del año |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Política en revisión | Comité y gerencia | App, correo | Inmediato |
| Política vigente pendiente de cargo | Trabajador | App, WhatsApp | Al publicar; recordatorio `ack_reminder_days` |
| Meta en rojo | Responsable del objetivo, Jefe SST | App | Al medir |
| Revisión de política por vencer | Jefe SST | Correo | `policy_review_notice_days` antes |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Política en PDF con firma y afiche para exhibir (A3/A4) con QR de verificación.
- Matriz de objetivos y metas con avance (anexo del Plan Anual y de la revisión por la dirección).
- Aporte al expediente [SUN](M-SUN.md): política firmada, constancia de difusión y objetivos.

## 12. Integración con otros módulos

**Consume:**

- [DOC](M-DOC.md) y [FIR](M-FIR.md): versión documental y firmas.
- [DSH](M-DSH.md): valores de KPI para metas.
- [LBA](M-LBA.md): brechas para proponer objetivos.

**Produce:**

- Objetivos para [PLA](M-PLA.md) y [RVD](M-RVD.md).

## 13. Criterios de aceptación clave

1. **Dado** una política sin el principio de participación de trabajadores marcado, **cuando** se envía a aprobación, **entonces** el sistema lo bloquea indicando el principio faltante.
2. **Dado** un Jefe SST, **cuando** intenta aprobar la política, **entonces** no tiene la acción disponible.
3. **Dado** una política aprobada con vigencia hoy, **cuando** entra en vigor, **entonces** se crea la campaña de cargos para todos los trabajadores activos.
4. **Dado** una meta ligada al KPI de capacitación, **cuando** se recalcula el KPI, **entonces** `actual_value` refleja el nuevo valor y el color se actualiza.
5. **Dado** un trabajador nuevo, **cuando** se da de alta, **entonces** tiene la política vigente como pendiente de firma.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Políticas de otras disciplinas (calidad, ambiente) salvo vía [ISO](M-ISO.md).

**Preguntas abiertas:**

- ¿La política debe aprobarse también en sesión del comité (acta) antes de gerencia? A confirmar por especialista.
