# M-AUD · 1.8 Bitácora de auditoría

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `AUD` |
| Número | 1.8 |
| Nombre | Bitácora de auditoría |
| Grupo | 1. Núcleo de la plataforma |
| Plan | E · Esencial |
| Fase | 0 · Cimientos |
| Origen | Plataforma |
| Resuelve | Quién hizo qué y cuándo |
| Tablas del catálogo | `audit_logs`, `sensitive_access_logs` |
| Dependencias | [USR](M-USR.md) (Usuarios, roles y permisos) |
| Prefijo de permisos | `aud.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Registrar de forma inalterable quién hizo qué, cuándo y desde dónde en todo el sistema, y de forma reforzada cada acceso a datos sensibles. Permite responder ante SUNAFIL, la ANPD o una disputa interna.

**Problema que resuelve.** Ante un accidente o inspección, las empresas no pueden demostrar cuándo se registró o modificó un dato; la sospecha de registros «arreglados» resta valor a la evidencia. La Ley de datos personales exige trazabilidad del acceso a datos de salud.

## 3. Base normativa

- Ley 29733 y D.S. 016-2024-JUS: registro de accesos a bancos de datos con datos sensibles y medidas de seguridad.
- D.S. 005-2012-TR, art. 35: conservación de registros (el log respalda la integridad).
- Ley 29783, art. 103: confidencialidad de la información médica.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Administrador de cuenta (`account_admin`) | Consulta la bitácora de su cuenta y exporta | `aud.audit_logs.view`, `aud.audit_logs.export` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Consulta la bitácora de su empresa (sin accesos sensibles) | `aud.audit_logs.view` |
| Médico ocupacional (`occupational_doctor`) | Consulta accesos a datos médicos de su empresa | `aud.sensitive_access_logs.view` |
| Dueño de la plataforma (`platform_owner`) | Consulta bitácora técnica y de soporte | `aud.audit_logs.view` |
| Auditor externo (`external_auditor`) | Consulta bitácora del periodo autorizado | `aud.audit_logs.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `audit_logs` | principal | Bitácora de cambios (inalterable, particionada por mes) | company_id, occurred_at, actor_user_id, actor_role, is_support_session, action (create/update/delete/approve/sign/login/export), entity_type, entity_id, changes (jsonb antes/después, con campos sensibles enmascarados), ip, user_agent, request_id |
| `sensitive_access_logs` | principal | Accesos a datos sensibles (inalterable) | company_id, occurred_at, user_id, role, worker_id, resource (exam, file, clinical_note, leave_detail, vulnerability), resource_id, purpose, reauth_session_id, result (granted/denied), ip |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-AUD-01 | Bitácora | Consultar cambios | Filtros por usuario, entidad, acción y fecha; línea de tiempo; diff antes/después |
| SCR-AUD-02 | Historial de un registro | Desde cualquier ficha | Panel lateral con todos los eventos del registro |
| SCR-AUD-03 | Accesos sensibles | Consulta del médico/oficial de datos | Tabla por trabajador/usuario con motivo y resultado; exportación |
| SCR-AUD-04 | Mis accesos (app del trabajador) | Transparencia | Lista de quién accedió a mis datos médicos y cuándo (sin detalle clínico) |

## 7. Flujos principales

1. Un middleware y observadores de modelo emiten un evento de auditoría por cada operación de escritura y por cada login/exportación.
2. El evento se escribe de forma síncrona en la misma transacción para cambios críticos (aprobaciones, firmas) y por cola para el resto, con `request_id` para correlación.
3. Los accesos a datos médicos se registran en `sensitive_access_logs` antes de devolver el dato; si el registro falla, la lectura se niega.

## 8. Reglas de negocio

- **RN-AUD-01.** `audit_logs` y `sensitive_access_logs` son solo INSERT (trigger que rechaza UPDATE/DELETE, rol de aplicación sin privilegios de modificación).
- **RN-AUD-02.** Los campos cifrados o médicos nunca se escriben en `changes`; se registra solo el nombre del campo modificado.
- **RN-AUD-03.** Toda acción realizada en una sesión de soporte lleva `is_support_session = true` y el identificador de la autorización.
- **RN-AUD-04.** Si no se puede escribir el registro de acceso sensible, la lectura del dato médico se deniega (fallo cerrado).
- **RN-AUD-05.** Las exportaciones (Excel, PDF, expediente) se auditan con filtros y número de registros.
- **RN-AUD-06.** La retención es el parámetro `audit_retention_years` (a confirmar); tras ese periodo las particiones se archivan cifradas en B2, no se borran sin aprobación.
- **RN-AUD-07.** El trabajador puede ver quién accedió a sus datos médicos (usuario, rol, fecha, motivo), nunca el contenido de otras personas.
- **RN-AUD-08.** La hora se guarda en UTC y se muestra en America/Lima.

## 9. Estados y transiciones

No aplica (registros inalterables).

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Acceso médico denegado repetido | Médico ocupacional, administrador | App, correo | Tras `denied_access_threshold` intentos |
| Exportación masiva de datos personales | Administrador de cuenta | Correo | Inmediato |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Exportación de la bitácora filtrada (CSV/Excel) con hash del archivo.
- Reporte de accesos a datos sensibles por periodo (evidencia para la ANPD y auditorías).

## 12. Integración con otros módulos

**Consume:**

- Eventos de todos los módulos y de [USR](M-USR.md) (sesiones, re-autenticación).

**Produce:**

- Historial por registro para todas las fichas; evidencia de integridad para [SUN](M-SUN.md) y [AUI](M-AUI.md).

## 13. Criterios de aceptación clave

1. **Dado** un usuario de aplicación, **cuando** ejecuta UPDATE sobre `audit_logs`, **entonces** la base lo rechaza.
2. **Dado** un médico abre un EMO, **cuando** se muestra el dato, **entonces** existe una fila en `sensitive_access_logs` con motivo y `granted`.
3. **Dado** la escritura en `sensitive_access_logs` falla, **cuando** el médico pide el dato, **entonces** se deniega la lectura.
4. **Dado** un cambio de teléfono cifrado de un trabajador, **cuando** se audita, **entonces** `changes` contiene el nombre del campo sin valores.
5. **Dado** un trabajador, **cuando** abre «Mis accesos», **entonces** ve los accesos a sus datos médicos sin detalle clínico.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- SIEM o correlación de eventos de seguridad de infraestructura.

**Preguntas abiertas:**

- ¿Cuál es el periodo de retención acordado con el oficial de datos personales?
