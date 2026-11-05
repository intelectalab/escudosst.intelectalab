# M-ING · 7.3 Ingreso de terceros por QR

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `ING` |
| Número | 7.3 |
| Nombre | Ingreso de terceros por QR |
| Grupo | 7. Contratistas y terceros |
| Plan | C · Corporativo |
| Fase | 4 · Plan Corporativo |
| Origen | Mercado |
| Resuelve | Habilitado o no habilitado en portería |
| Tablas del catálogo | `access_points`, `access_events` |
| Dependencias | [FOT](M-FOT.md) (Fotocheck SST con QR), [CTR](M-CTR.md) (Acreditación de contratistas), [TEN](M-TEN.md) (Empresas, sedes y áreas) |
| Prefijo de permisos | `ing.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Controlar en portería el ingreso de trabajadores propios, contratistas y visitantes escaneando su QR, mostrando «habilitado / no habilitado» según el estado del fotocheck y la acreditación, y registrando cada evento.

**Problema que resuelve.** El vigilante no puede verificar requisitos SST; personal no acreditado ingresa a la planta.

## 3. Base normativa

- Ley 29783, art. 68: control de contratistas; art. 49: garantizar condiciones antes de iniciar labores.
- Ley 29733: registros de acceso son datos personales; finalidad y retención limitadas.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Configura puntos de acceso | `ing.access_points.create`, `ing.access_points.update` |
| Jefe de área / línea (`area_lead`) | Consulta eventos | `ing.access_events.view` |
| Supervisor SST (`sst_supervisor`) | Opera la verificación en portería | `ing.access_events.create` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `access_points` | principal | Punto de acceso | company_id, site_id, name, direction (entrada/salida/ambas), device_id, is_active |
| `access_events` | principal | Evento de acceso (inalterable) | company_id, access_point_id, credential_id, worker_id, contractor_worker_id, visitor_name, occurred_at, direction, result (habilitado, no habilitado, desconocido), reason_code, operator_user_id, override_reason |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-ING-01 | Portería (tablet/móvil) | Escanear | Cámara para QR; resultado a pantalla completa verde/rojo; nombre, foto, empresa; motivo no médico |
| SCR-ING-02 | Visitante | Registro manual | Nombre, DNI, a quién visita, inducción de visita aceptada |
| SCR-ING-03 | Eventos | Consulta | Ingresos/salidas por fecha, punto, resultado; personas dentro |

## 7. Flujos principales

1. El operador escanea el QR; el sistema consulta el estado del fotocheck y la acreditación para la sede.
2. Muestra el resultado y registra el evento.
3. Un ingreso «no habilitado» solo puede permitirse con override justificado y queda alertado.

## 8. Reglas de negocio

- **RN-ING-01.** El resultado se calcula en línea con [FOT](M-FOT.md) y la acreditación de [CTR](M-CTR.md) para la sede del punto.
- **RN-ING-02.** Los motivos mostrados al operador nunca incluyen datos médicos.
- **RN-ING-03.** `access_events` es inalterable.
- **RN-ING-04.** Un override exige motivo y genera alerta al Jefe SST.
- **RN-ING-05.** Retención de eventos por `access_retention_days` (parámetro).
- **RN-ING-06.** Sin conexión, el dispositivo usa la última lista de estados sincronizada con antigüedad máxima `access_offline_max_age` y marca los eventos como offline.

## 9. Estados y transiciones

No aplica (eventos inalterables).

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Override de no habilitado | Jefe SST | App | Inmediato |
| Intento de ingreso de no habilitado | Jefe del área del trabajador | App | Inmediato |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Ingresos por día, punto, empresa contratista; personas dentro de la planta (útil en emergencias y simulacros).

## 12. Integración con otros módulos

**Consume:**

- [FOT](M-FOT.md), [CTR](M-CTR.md).

**Produce:**

- Eventos para [AUD](M-AUD.md), [SIM](M-SIM.md) (conteo de personas).

## 13. Criterios de aceptación clave

1. **Dado** un contratista con acreditación vencida, **cuando** se escanea, **entonces** resultado no habilitado.
2. **Dado** un trabajador no apto, **cuando** se escanea, **entonces** el motivo dice «Requisito pendiente» sin términos médicos.
3. **Dado** un override, **cuando** se registra, **entonces** se exige motivo y se alerta.
4. **Dado** un evento, **cuando** se intenta editar, **entonces** se rechaza.
5. **Dado** modo offline con lista antigua, **cuando** se escanea, **entonces** el evento se marca offline.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Integración con torniquetes (vía [API](M-API.md)).

**Preguntas abiertas:**

- ¿Se requiere registrar salidas obligatoriamente?
