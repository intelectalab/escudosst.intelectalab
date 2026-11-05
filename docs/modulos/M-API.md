# M-API · 8.8 API e integraciones

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `API` |
| Número | 8.8 |
| Nombre | API e integraciones |
| Grupo | 8. Diferenciadores e integraciones |
| Plan | C · Corporativo |
| Fase | 4 · Plan Corporativo |
| Origen | Mercado |
| Resuelve | Planillas, ERP y aulas virtuales |
| Tablas del catálogo | `api_clients`, `webhooks`, `webhook_deliveries` |
| Dependencias | [USR](M-USR.md) (Usuarios, roles y permisos), [TRB](M-TRB.md) (Trabajadores y legajo), [CAP](M-CAP.md) (Capacitaciones e inducciones), [NOT](M-NOT.md) (Alertas y notificaciones) |
| Prefijo de permisos | `api.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Exponer una API pública versionada y webhooks para integrar EscudoSST con sistemas de planillas, ERP, aulas virtuales y control de acceso, con clientes OAuth por empresa y alcances limitados.

**Problema que resuelve.** Las empresas medianas y grandes ya tienen planilla y ERP; cargar trabajadores a mano duplica trabajo.

## 3. Base normativa

- Ley 29733: transferencia de datos a terceros con finalidad y seguridad; nunca datos médicos por API.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Administrador de cuenta (`account_admin`) | Crea clientes API y webhooks | `api.api_clients.create`, `api.api_clients.update`, `api.webhooks.create`, `api.webhooks.update` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Consulta entregas de webhooks | `api.webhook_deliveries.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `api_clients` | principal | Cliente de API | company_id, name, client_id, secret_hash, scopes (array), rate_limit, ip_allowlist, status, last_used_at |
| `webhooks` | principal | Suscripción | company_id, url, events (array), secret_hash, is_active |
| `webhook_deliveries` | principal | Entrega | webhook_id, event, payload_hash, attempt, status_code, delivered_at, next_retry_at |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-API-01 | Clientes API | Gestión | Crear, alcances, rotar secreto, revocar |
| SCR-API-02 | Webhooks | Suscripciones | URL, eventos, prueba |
| SCR-API-03 | Entregas | Diagnóstico | Intentos y respuestas |

## 7. Flujos principales

1. El administrador crea un cliente con alcances (p. ej. `workers.write`, `trainings.read`).
2. El sistema externo obtiene token OAuth2 client credentials y llama a la API.
3. Los eventos suscritos se envían firmados (HMAC) con reintentos.

## 8. Reglas de negocio

- **RN-API-01.** Ningún alcance permite leer datos médicos; `fitness_status` solo con alcance explícito `fitness.read` y aprobación.
- **RN-API-02.** Cada cliente opera en una sola empresa con RLS.
- **RN-API-03.** Los secretos se muestran una sola vez y se guardan con hash.
- **RN-API-04.** Los webhooks se firman con HMAC-SHA256 y se reintentan con retroceso hasta `webhook_max_retries`.
- **RN-API-05.** Límite de uso por cliente (`rate_limit`); excederlo devuelve 429.
- **RN-API-06.** Las escrituras por API aceptan `Idempotency-Key`.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| api_clients | active → revoked | |
| webhook_deliveries | pending → delivered / failed | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Webhook con fallos persistentes | Administrador de cuenta | Correo | Tras `webhook_max_retries` |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Uso de la API por cliente; entregas fallidas.

## 12. Integración con otros módulos

**Consume:**

- Endpoints de [TRB](M-TRB.md), [CAP](M-CAP.md), [FOT](M-FOT.md), [ING](M-ING.md).

**Produce:**

- Eventos (`worker.created`, `training.certificate_issued`, `credential.status_changed`…).

## 13. Criterios de aceptación clave

1. **Dado** un cliente con alcance `workers.write`, **cuando** intenta leer EMO, **entonces** 403.
2. **Dado** una escritura repetida con la misma `Idempotency-Key`, **cuando** se procesa, **entonces** un solo registro.
3. **Dado** un webhook, **cuando** se envía, **entonces** lleva firma HMAC válida.
4. **Dado** exceso de llamadas, **cuando** se llama, **entonces** 429.
5. **Dado** secreto creado, **cuando** se consulta después, **entonces** no se muestra.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Conectores preconstruidos para cada ERP.

**Preguntas abiertas:**

- ¿Qué sistemas de planilla priorizar?
