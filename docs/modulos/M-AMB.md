# M-AMB · 8.7 Medio ambiente (SSOMA)

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `AMB` |
| Número | 8.7 |
| Nombre | Medio ambiente (SSOMA) |
| Grupo | 8. Diferenciadores e integraciones |
| Plan | + · Complemento |
| Fase | 5 · Complementos restantes |
| Origen | Mercado |
| Resuelve | Aspectos ambientales y residuos |
| Tablas del catálogo | `environmental_aspects`, `waste_records` |
| Dependencias | [TEN](M-TEN.md) (Empresas, sedes y áreas), [ACR](M-ACR.md) (Acciones correctivas) |
| Prefijo de permisos | `amb.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Gestionar aspectos e impactos ambientales y el registro de residuos (SSOMA) para empresas que integran ambiente con SST.

**Problema que resuelve.** Muchas empresas SSOMA llevan ambiente en hojas separadas.

## 3. Base normativa

- Ley 28611 (Ley General del Ambiente); D. Leg. 1278 (Ley de Gestión Integral de Residuos Sólidos) y su reglamento (a confirmar).
- ISO 14001:2015.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Mantiene aspectos y registra residuos | `amb.environmental_aspects.create`, `amb.waste_records.create` |
| Jefe de área / línea (`area_lead`) | Registra residuos de su área | `amb.waste_records.create` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `environmental_aspects` | principal | Aspecto/impacto | company_id, site_id, activity, aspect, impact, condition (normal/anormal/emergencia), significance_score, controls |
| `waste_records` | principal | Generación y disposición | company_id, site_id, waste_type (peligroso/no peligroso), category, quantity, unit, generated_on, disposal_method, eo_rs_provider, manifest_number, file_id |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-AMB-01 | Matriz de aspectos | Evaluación | Aspectos con significancia |
| SCR-AMB-02 | Residuos | Registro | Generación y disposición; manifiestos |

## 7. Flujos principales

1. Se identifican aspectos y se evalúa significancia; se registran residuos y su disposición con manifiesto.

## 8. Reglas de negocio

- **RN-AMB-01.** La significancia se calcula con criterios configurables.
- **RN-AMB-02.** Los residuos peligrosos exigen número de manifiesto y proveedor EO-RS.
- **RN-AMB-03.** Los aspectos significativos exigen control.
- **RN-AMB-04.** Las cantidades se registran con unidad del catálogo.
- **RN-AMB-05.** Los registros cerrados no se editan.

## 9. Estados y transiciones

No aplica máquina de estados: los aspectos se reevalúan creando una nueva evaluación y los registros de residuos pasan de `open` a `closed` al adjuntar el manifiesto de disposición.

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Residuo peligroso sin manifiesto | Jefe SST | App | `waste_manifest_days` tras generación |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Matriz de aspectos; residuos por tipo y mes.

## 12. Integración con otros módulos

**Consume:**

- [TEN](M-TEN.md).

**Produce:**

- Acciones en [ACR](M-ACR.md).

## 13. Criterios de aceptación clave

1. **Dado** residuo peligroso sin manifiesto, **cuando** se guarda, **entonces** se exige.
2. **Dado** aspecto significativo sin control, **cuando** se guarda, **entonces** se exige.
3. **Dado** criterios cambiados, **cuando** se recalcula, **entonces** nueva significancia.
4. **Dado** registro cerrado, **cuando** se edita, **entonces** se rechaza.
5. **Dado** residuos del mes, **cuando** se consulta, **entonces** totales por tipo.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Declaraciones ante el MINAM.

**Preguntas abiertas:**

- ¿Se requiere huella de carbono?
