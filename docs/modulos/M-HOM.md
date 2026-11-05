# M-HOM · 7.4 Homologación de proveedores

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `HOM` |
| Número | 7.4 |
| Nombre | Homologación de proveedores |
| Grupo | 7. Contratistas y terceros |
| Plan | + · Complemento |
| Fase | 5 · Complementos restantes |
| Origen | Mercado |
| Resuelve | Evaluación y calificación de proveedores |
| Tablas del catálogo | `vendor_evaluation_templates`, `vendor_evaluations` |
| Dependencias | [CTR](M-CTR.md) (Acreditación de contratistas) |
| Prefijo de permisos | `hom.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Evaluar y calificar proveedores y contratistas en criterios SST (y otros configurables) con plantillas ponderadas, resultando en una calificación y una vigencia de homologación.

**Problema que resuelve.** La selección de proveedores no considera su desempeño SST.

## 3. Base normativa

- Ley 29783, art. 68 (diligencia en la selección de contratistas); ISO 45001, cláusula 8.1.4 (compras).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Crea plantillas y evalúa | `hom.vendor_evaluation_templates.create`, `hom.vendor_evaluations.create` |
| Administrador de contratista (`contractor_admin`) | Responde el cuestionario de autoevaluación | `hom.vendor_evaluations.update` |
| Gerencia (`management`) | Aprueba la homologación | `hom.vendor_evaluations.approve` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `vendor_evaluation_templates` | principal | Plantilla | company_id, name, criteria (jsonb con pesos), passing_score, validity_months |
| `vendor_evaluations` | principal | Evaluación | company_id, contractor_id, template_id, evaluated_on, scores (jsonb), total_score, rating (A/B/C), valid_until, status |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-HOM-01 | Plantillas | Configuración | Criterios con peso |
| SCR-HOM-02 | Evaluar proveedor | Formulario | Puntajes por criterio; evidencias |
| SCR-HOM-03 | Proveedores homologados | Lista | Calificación y vigencia |

## 7. Flujos principales

1. Se envía la autoevaluación al proveedor; SST la valida y puntúa.
2. Gerencia aprueba; la homologación vence según la plantilla.

## 8. Reglas de negocio

- **RN-HOM-01.** El puntaje total es la suma ponderada; los pesos suman 100.
- **RN-HOM-02.** Aprueba si `total_score` ≥ `passing_score`.
- **RN-HOM-03.** `valid_until` = fecha + `validity_months`.
- **RN-HOM-04.** Una evaluación aprobada no se edita.
- **RN-HOM-05.** La calificación vencida se alerta.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| vendor_evaluations | draft → submitted → approved / rejected → expired | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Homologación por vencer | Jefe SST | App | `vendor_notice_days` antes |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Proveedores por calificación y vigencia.

## 12. Integración con otros módulos

**Consume:**

- [CTR](M-CTR.md).

**Produce:**

- Calificación visible en [CTR](M-CTR.md).

## 13. Criterios de aceptación clave

1. **Dado** pesos que suman 90, **cuando** se guarda la plantilla, **entonces** se rechaza.
2. **Dado** puntaje bajo el mínimo, **cuando** se evalúa, **entonces** rechazado.
3. **Dado** evaluación aprobada, **cuando** se edita, **entonces** se rechaza.
4. **Dado** vigencia cumplida, **cuando** corre el job, **entonces** queda vencida.
5. **Dado** autoevaluación del proveedor, **cuando** SST la valida, **entonces** se calcula el puntaje.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Evaluación financiera.

**Preguntas abiertas:**

- ¿Criterios por defecto?
