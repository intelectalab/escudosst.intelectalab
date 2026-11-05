# M-ISO · 8.6 Sistema integrado ISO

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `ISO` |
| Número | 8.6 |
| Nombre | Sistema integrado ISO |
| Grupo | 8. Diferenciadores e integraciones |
| Plan | + · Complemento |
| Fase | 5 · Complementos restantes |
| Origen | Mercado |
| Resuelve | ISO 45001, 9001 y 14001 en una sola gestión |
| Tablas del catálogo | `iso_standards`, `iso_clauses`, `iso_evidences` |
| Dependencias | [DOC](M-DOC.md) (Gestión documental), [AUI](M-AUI.md) (Auditorías), [ACR](M-ACR.md) (Acciones correctivas), [MRL](M-MRL.md) (Matriz de requisitos legales) |
| Prefijo de permisos | `iso.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Gestionar un sistema integrado ISO 45001, 9001 y 14001 mapeando cláusulas a evidencias del sistema y documentos, con estado de cumplimiento por cláusula.

**Problema que resuelve.** Las empresas certificadas mantienen sistemas paralelos para calidad, ambiente y SST.

## 3. Base normativa

- ISO 45001:2018, ISO 9001:2015, ISO 14001:2015 (Anexo SL).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Mapea cláusulas y evidencias | `iso.iso_evidences.create`, `iso.iso_evidences.update` |
| Dueño de la plataforma (`platform_owner`) | Mantiene el catálogo de normas y cláusulas | `iso.iso_standards.update`, `iso.iso_clauses.update` |
| Auditor externo (`external_auditor`) | Consulta | `iso.iso_evidences.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `iso_standards` | principal | Norma | code, name, version |
| `iso_clauses` | principal | Cláusula | standard_id, number, title, hl_structure_ref |
| `iso_evidences` | principal | Evidencia por cláusula | company_id, clause_id, evidence_type (documento, registro, módulo), ref_type, ref_id, status, reviewed_at |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-ISO-01 | Matriz de cláusulas | Estado | Cláusulas por norma con semáforo |
| SCR-ISO-02 | Evidencias | Mapeo | Vincular documentos y registros a cláusulas; cláusulas comunes Anexo SL |

## 7. Flujos principales

1. Se eligen normas aplicables; se mapean evidencias por cláusula; las cláusulas comunes comparten evidencias.

## 8. Reglas de negocio

- **RN-ISO-01.** Una cláusula está cubierta si tiene al menos una evidencia vigente.
- **RN-ISO-02.** Las cláusulas comunes del Anexo SL comparten evidencias entre normas.
- **RN-ISO-03.** Una evidencia obsoleta deja la cláusula «por revisar».
- **RN-ISO-04.** El catálogo de cláusulas es global (sin textos protegidos por derechos de autor, solo número y título).
- **RN-ISO-05.** Las no conformidades ISO se gestionan en [AUI](M-AUI.md) y [ACR](M-ACR.md).

## 9. Estados y transiciones

No aplica (estado derivado por cláusula: cubierta / por revisar / sin evidencia).

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Evidencia obsoleta | Jefe SST | App | Al detectarlo |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Matriz de cumplimiento por norma.

## 12. Integración con otros módulos

**Consume:**

- [DOC](M-DOC.md), [MRL](M-MRL.md), [AUI](M-AUI.md).

**Produce:**

- Evidencias para auditorías de certificación.

## 13. Criterios de aceptación clave

1. **Dado** cláusula con evidencia vigente, **cuando** se consulta, **entonces** cubierta.
2. **Dado** evidencia pasa a obsoleta, **cuando** se recalcula, **entonces** por revisar.
3. **Dado** cláusula común, **cuando** se mapea en 45001, **entonces** aparece en 9001.
4. **Dado** un usuario, **cuando** ve el catálogo, **entonces** solo número y título.
5. **Dado** sin evidencias, **cuando** se consulta, **entonces** sin evidencia en rojo.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Textos completos de las normas ISO.

**Preguntas abiertas:**

- ¿Se incluirá ISO 27001 más adelante?
