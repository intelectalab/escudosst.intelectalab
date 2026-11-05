# M-MAP · 3.2 Mapa de riesgos

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `MAP` |
| Número | 3.2 |
| Nombre | Mapa de riesgos |
| Grupo | 3. Gestión de riesgos |
| Plan | P · Profesional |
| Fase | 2 · Plan Profesional |
| Origen | Ley |
| Resuelve | Plano con pictogramas vinculado al IPERC |
| Tablas del catálogo | `risk_maps`, `risk_map_markers` |
| Dependencias | [IPC](M-IPC.md) (Matriz IPERC), [TEN](M-TEN.md) (Empresas, sedes y áreas), [DOC](M-DOC.md) (Gestión documental), [EQE](M-EQE.md) (Equipos de emergencia y botiquines) |
| Prefijo de permisos | `map.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Elaborar el mapa de riesgos de cada sede sobre su plano, con pictogramas normalizados vinculados a las filas del IPERC y a los equipos de emergencia, versionado y aprobado, para exhibición y consulta móvil.

**Problema que resuelve.** Los mapas de riesgo se dibujan en programas de diseño, quedan desactualizados respecto al IPERC y no se pueden consultar en campo. SUNAFIL exige el mapa de riesgos exhibido.

## 3. Base normativa

- Ley 29783, art. 35 literal e: el empleador elabora un mapa de riesgos y lo exhibe en un lugar visible.
- D.S. 005-2012-TR, art. 32 literal d: el mapa de riesgos es documento obligatorio del SG-SST.
- NTP 399.010-1 (señales de seguridad: colores, símbolos, formas) para pictogramas (versión vigente a confirmar).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Carga planos, ubica marcadores, aprueba y publica | `map.risk_maps.create`, `map.risk_maps.update`, `map.risk_map_markers.create`, `map.risk_map_markers.update` |
| Miembro del Comité SST (`committee_member`) | Revisa y aprueba | `map.risk_maps.approve` |
| Jefe de área / línea (`area_lead`) | Propone marcadores de su área | `map.risk_map_markers.create` |
| Trabajador (`worker`) | Consulta el mapa de su sede | `map.risk_maps.view` |
| Auditor externo (`external_auditor`) | Consulta versiones | `map.risk_maps.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `risk_maps` | principal | Mapa por sede/nivel | company_id, site_id, floor_label, base_plan_file_id, version, status, approved_by, approved_at, document_version_id, legend (jsonb) |
| `risk_map_markers` | principal | Marcador sobre el plano | risk_map_id, marker_type (riesgo, señal obligatoria, prohibición, equipo de emergencia, ruta de evacuación, zona segura), pictogram_code, x_pct, y_pct, iperc_row_id, emergency_equipment_id, label, risk_level |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-MAP-01 | Mapas por sede | Lista | Miniaturas por sede y piso; versión vigente; semáforo de coherencia con el IPERC |
| SCR-MAP-02 | Editor de mapa | Ubicar pictogramas | Plano de fondo con zoom; paleta de pictogramas NTP; arrastrar y soltar; panel de vínculo a fila IPERC o equipo; leyenda automática |
| SCR-MAP-03 | Validación de coherencia | Diferencias con IPERC | Riesgos significativos del área sin marcador; marcadores ligados a filas retiradas |
| SCR-MAP-04 | Vista móvil | Consulta en campo | Plano con zoom táctil; tocar marcador muestra riesgo, nivel y controles |
| SCR-MAP-05 | Exportar afiche | Generar PDF para exhibir | Tamaño A3/A2, leyenda, firma, QR a la versión digital |

## 7. Flujos principales

1. El Jefe SST sube el plano (imagen o PDF) de la sede/piso.
2. Ubica marcadores; para riesgos elige la fila IPERC (el pictograma y nivel se sugieren desde el peligro del catálogo).
3. El sistema valida coherencia con el IPERC vigente de la sede.
4. Se aprueba con firma y se exporta el afiche con QR; la versión anterior queda obsoleta.

## 8. Reglas de negocio

- **RN-MAP-01.** Los marcadores de tipo riesgo deben vincularse a una fila de un IPERC vigente de la misma sede.
- **RN-MAP-02.** No se puede aprobar un mapa si existen riesgos significativos del IPERC de la sede sin marcador, salvo justificación por fila.
- **RN-MAP-03.** Cuando se aprueba una nueva versión del IPERC de la sede, el mapa vigente pasa a «requiere revisión».
- **RN-MAP-04.** Las coordenadas se guardan en porcentaje del plano para que el mapa escale en cualquier pantalla.
- **RN-MAP-05.** Una versión aprobada es inmutable; los cambios generan nueva versión.
- **RN-MAP-06.** El afiche impreso incluye QR que lleva a la versión vigente; si la versión del afiche es obsoleta, la página lo indica.
- **RN-MAP-07.** Los pictogramas provienen de una biblioteca controlada (códigos NTP); no se aceptan íconos libres en marcadores de riesgo.
- **RN-MAP-08.** Los equipos de emergencia ubicados deben existir en [EQE](M-EQE.md) y heredan su estado (vigente/vencido).

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| risk_maps | draft → in_review → approved → effective → superseded; effective ↔ review_required | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Mapa requiere revisión por cambio de IPERC | Jefe SST | App | Inmediato |
| Mapa enviado a aprobación | Comité o supervisor | App | Inmediato |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Afiche del mapa de riesgos en PDF con leyenda y QR.
- Aporte al expediente [SUN](M-SUN.md): mapa vigente de la sede.

## 12. Integración con otros módulos

**Consume:**

- [IPC](M-IPC.md): filas y niveles; [EQE](M-EQE.md): equipos; [TEN](M-TEN.md): sedes.

**Produce:**

- Mapa para consulta móvil, inducciones ([CAP](M-CAP.md)) y simulacros ([SIM](M-SIM.md)).

## 13. Criterios de aceptación clave

1. **Dado** un riesgo significativo del IPERC sin marcador, **cuando** se envía el mapa a aprobación, **entonces** se bloquea listando el riesgo.
2. **Dado** una nueva versión del IPERC aprobada, **cuando** se publica, **entonces** el mapa de la sede pasa a «requiere revisión».
3. **Dado** un afiche de una versión obsoleta, **cuando** se escanea el QR, **entonces** la página indica que existe una versión más reciente.
4. **Dado** el mapa en un teléfono, **cuando** se toca un marcador, **entonces** se muestran riesgo, nivel y controles.
5. **Dado** un extintor vencido ubicado en el mapa, **cuando** se muestra, **entonces** el marcador aparece en rojo.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Dibujo de planos arquitectónicos o CAD.

**Preguntas abiertas:**

- ¿Qué biblioteca de pictogramas se licenciará o diseñará conforme a NTP?
