# 12 · Pruebas y calidad

Regla base: **Claude Code no aprueba su propio trabajo.** Todo cambio pasa pruebas automáticas, `make verify` y revisión de una persona.

## 1. Pirámide de pruebas

| Nivel | Herramienta | Qué cubre | Dónde corre |
|---|---|---|---|
| Unitarias backend | Pest | Reglas `RN-*`, máquinas de estado, cálculos (índices, vencimientos, semáforos), servicios | Cada push |
| Unitarias frontend | Vitest + Testing Library | Componentes, hooks, cola offline, formateo de fechas y dinero | Cada push |
| Integración API | Pest + PostgreSQL real (contenedor) | Endpoints, validación, permisos, feature flags, transacciones, colas en modo síncrono | Cada push |
| Aislamiento multiempresa | Pest | Ninguna lectura ni escritura cruza empresas | Cada push |
| RLS | Pruebas SQL (pgTAP o Pest con SQL directo) | Políticas por tabla, FK compuestas, inalterables | Cada push |
| Contratos de API | Validación contra `api/openapi.yaml` (Spectral + validación de respuestas en pruebas de integración) | Respuestas y peticiones cumplen el contrato | Cada push |
| E2E | Playwright | Flujos críticos de punta a punta | PR a `develop` y nocturno |
| Offline | Playwright con red cortada + pruebas de la cola | Captura sin red, sincronización, conflictos, fotos | PR que toque `lib/offline` y nocturno |
| Accesibilidad | axe (Playwright) + revisión manual por pantalla nueva | WCAG 2.2 AA | PR |
| Carga | k6 | Endpoints públicos y sincronización | Antes de cada versión a producción |
| Seguridad | `composer audit`, `npm audit`, análisis estático (Larastan nivel alto, ESLint), escaneo de imágenes Docker, secretos (gitleaks) | Dependencias y errores comunes | Cada push |

## 2. Aislamiento multiempresa (obligatorio)

Toda historia que toque datos de empresa incluye estas pruebas:

1. Crear empresas A y B con datos equivalentes.
2. Usuario de A lista el recurso → solo ve datos de A.
3. Usuario de A pide un id de B → **404**.
4. Usuario de A intenta crear/editar con referencias a filas de B → falla (FK compuesta o validación).
5. Job en cola con contexto A no toca datos de B.
6. Exportación de A no contiene datos de B.

Ayuda: `Tests\Support\TenantIsolation` con un helper `assertIsolated(Resource::class)`.

## 3. Pruebas de RLS

Contra la base, sin pasar por Laravel:

- Para cada tabla con `company_id`: `relrowsecurity` y `relforcerowsecurity` activados; existe la política `rls_<tabla>_company`.
- Con `app.company_id` = A, `SELECT` no devuelve filas de B; `INSERT` con `company_id` = B falla.
- Sin `app.company_id` fijado, `SELECT` falla o devuelve cero filas.
- El rol de la aplicación no tiene `BYPASSRLS` ni es dueño de tablas.
- Tablas inalterables: `UPDATE` y `DELETE` fallan.
- Base médica: mismas pruebas.

Un verificador de `make verify` recorre el esquema y falla si una tabla de empresa no cumple.

## 4. Contratos de API

- `api/openapi.yaml` es la fuente. Los tipos del frontend se generan desde él.
- En integración, cada respuesta se valida contra el esquema.
- Lint del contrato con Spectral (nombres `kebab-case`, errores con el formato de `02` §11, paginación estándar).
- Un cambio incompatible sin nueva versión falla en CI (comparación con la rama base).

## 5. E2E de flujos críticos

| # | Flujo |
|---|---|
| E2E-01 | Registro autoservicio con RUC → carga de trabajadores por Excel → asistente de línea base → panel con brechas |
| E2E-02 | IPERC: crear matriz → filas → enviar → aprobar (otro usuario) → vigente |
| E2E-03 | Elección del comité: convocatoria → candidatos → votación de trabajadores en PWA → resultados → miembros |
| E2E-04 | Inspección con hallazgo → acción correctiva → evidencia → verificación por otra persona |
| E2E-05 | Reporte público por QR sin sesión → bandeja → acción → cierre |
| E2E-06 | Accidente → registro → alerta de plazo → notificación registrada → investigación → cierre |
| E2E-07 | Capacitación con asistencia firmada → constancia → fotocheck pasa a habilitado → verificación pública verde |
| E2E-08 | EMO: programar → resultados (médico con re-autenticación) → aptitud visible para RR. HH. sin diagnóstico → registro en `sensitive_access_logs` |
| E2E-09 | Modo SUNAFIL: generar expediente → faltantes → corregir → regenerar → link compartido → acceso público → revocar |
| E2E-10 | Consultora: invitar cliente → aceptar → panel multi-cliente → cambiar de empresa → marca blanca en PDF |
| E2E-11 | Bajar de plan → módulo en solo lectura → datos intactos → subir de plan → editable |
| E2E-12 | Offline: inspección y entrega de EPP sin red → reconexión → sincronización → conflicto resuelto |
| E2E-13 | Sugerencia IA (proveedor falso) → revisar → aplicar parcialmente → registros marcados como asistidos |
| E2E-14 | Auditor externo temporal: accede solo a módulos y periodo → vence → pierde acceso |
| E2E-15 | Aula: crear curso → contenidos y evaluación con preguntas al azar → matricular por brecha de la matriz → cursar con permanencia real → aprobar → certificado por validar → validar → verificación pública por código «válido» → fotocheck habilitado |
| E2E-16 | Venta de cursos: catálogo público → carrito con cupón → pago en el simulador de pasarela → webhook firmado → matrícula automática → comprobante → portal del participante externo |
| E2E-17 | Cupos corporativos: compra de N cupos → representante asigna → límite de cupos → liberar cupo no iniciado → avance de asignados |
| E2E-18 | Reembolso total → nota de crédito → matrícula cancelada → certificado anulado → verificación pública «anulado» |
| E2E-19 | Recomendación: certificado por vencer → regla de recapacitación → «Recomendados para ti» → envío solo con consentimiento y dentro del límite → matrícula → conversión registrada |
| E2E-20 | Activación por empresa: empresa nueva con VTA desactivado → intento de activar VTA sin AUL rechazado → activar AUL y VTA → desactivar VTA → datos en solo lectura |

## 6. Offline

- Unitarias de la cola (orden, reintentos, idempotencia, límites de almacenamiento).
- E2E con `context.setOffline(true)`: crear registros, cerrar y abrir la app, reconectar.
- Conflictos simulados: el mismo registro editado en servidor y dispositivo.
- Fotos pendientes: tamaño grande, red intermitente, reanudación.
- Dispositivo revocado: el lote se rechaza y los datos locales se borran según `16`.
- Prueba en un celular Android de gama baja real antes de cada versión.

## 6.1 Pagos: webhooks e idempotencia (VTA)

Obligatorias en CI con el simulador de pasarela (`fake`) que firma eventos con el secreto de prueba:

| Caso | Resultado esperado |
|---|---|
| Webhook con firma válida de pago confirmado | Pedido `paid` → `fulfilled`; una matrícula; un comprobante; una transacción `succeeded` |
| Mismo evento repetido (n veces, también en paralelo) | Sin efectos adicionales: una sola matrícula, un solo comprobante, un solo uso de cupón |
| Firma inválida, ausente o con otro secreto (de otra empresa) | 401/400, registro del intento, ningún cambio de estado |
| Eventos fuera de orden (`pending` después de `succeeded`) | Se registra y se ignora; el estado no retrocede |
| Carrera webhook ↔ conciliación ↔ retorno del navegador | Un único paso a `paid`; el retorno del navegador nunca confirma el pago |
| Reintento de creación del cargo con la misma `idempotency_key` | La pasarela simulada no crea un segundo cargo |
| Pago tardío sobre pedido vencido | Se registra para revisión (reembolso o aprobación manual); no excede el aforo |
| Reembolso parcial y total | Total reembolsado ≤ cobrado; nota de crédito; matrícula cancelada y certificado anulado según política |
| Aislamiento | Un webhook de la pasarela de la empresa A nunca modifica pedidos de la empresa B |
| Datos de tarjeta | Búsqueda automática de patrones de tarjeta (PAN) en base, logs y colas tras la suite: cero coincidencias |

Además: pruebas de propiedad del cálculo de totales con cupones e impuestos, del aforo con compras concurrentes y del límite de cupos asignados.

## 6.2 Aula virtual y recomendaciones

- Permanencia: latidos sin interacción no suman; dos dispositivos no duplican tiempo; latidos manipulados (marca de tiempo futura, actividad bloqueada) se rechazan.
- Evaluaciones: el sorteo respeta el número de preguntas y los bancos; el intento guarda sus preguntas; tiempo límite envía lo guardado.
- Escalas: conversión de porcentaje a 0-20, 0-10, 0-100 y letras en los bordes de cada rango.
- Finalización idempotente: el mismo evento no crea dos asistencias ni dos certificados en CAP.
- Recomendaciones: no se recomienda lo ya aprobado, matriculado o comprado; deduplicación; límite de envíos; sin consentimiento no hay correo ni WhatsApp a externos; ninguna regla puede leer tablas de la base médica ni `fitness_status` (prueba estática de la lista blanca de campos); las sugerencias de IA no se publican sin aprobación.
- Certificados: verificación pública con respuesta idéntica para inexistente y no verificable; límite por IP; anulado deja de cumplir matriz y fotocheck.

## 7. Carga en endpoints públicos

| Endpoint | Escenario |
|---|---|
| `GET /api/v1/public/credentials/{token}` | Ráfaga de verificaciones en portería al inicio de turno |
| `POST /api/v1/public/report-points/{token}/reports` | Muchos reportes simultáneos + abuso (debe responder 429) |
| `POST /api/v1/webhooks/whatsapp` | Ráfaga de mensajes; reintentos duplicados del BSP (idempotencia) |
| `POST /api/v1/webhooks/payments/{gateway_id}` | Ráfaga de eventos y reintentos duplicados de la pasarela (idempotencia) |
| Verificación pública de certificados | Consultas masivas y enumeración de códigos (debe responder 429) |
| Catálogo público y checkout | Picos de visitas por campaña; abuso automatizado |
| `POST /api/v1/sync/batches` | Muchos dispositivos sincronizando a la vez tras recuperar señal |
| Vista pública del expediente | Descarga de ZIP grandes |

Criterio: sin errores 5xx; los límites de uso actúan; el resto del sistema sigue respondiendo. Los umbrales de latencia y volumen los fija el área de desarrollo.

## 8. Datos de prueba

- **Nunca datos reales** de clientes en desarrollo o pruebas. Tampoco copias anonimizadas de producción sin aprobación del dueño del producto.
- Fábricas (factories) por modelo con datos peruanos verosímiles: RUC con dígito verificador válido, DNI de 8 dígitos ficticios, nombres comunes, sedes en Lima y regiones.
- Seeder `DemoSeeder` con tres empresas de ejemplo:
  - Empresa Esencial (15 trabajadores, Supervisor SST, comercio).
  - Empresa Profesional (120 trabajadores, comité, manufactura, dos sedes).
  - Consultora con dos empresas cliente y marca blanca.
  - Instituto de seguridad con AUL y VTA activos, cursos a la venta, cupones, una empresa compradora con cupos y participantes externos ficticios.
- Catálogos globales reales (normas, peligros base, parámetros legales con `to_confirm`).
- Base médica de prueba con datos claramente ficticios («Paciente de prueba»).
- Proveedores en modo `fake` (B2 local con MinIO, correo a Mailpit, WhatsApp y IA simulados, simulador de pasarela de pagos, comprobantes y Vimeo simulados). Nunca tarjetas reales: solo las tarjetas de prueba documentadas por la pasarela en su entorno de pruebas.

## 9. Criterios de listo (Definition of Done)

Una historia está lista cuando:

- [ ] Cumple todos sus criterios de aceptación (`docs/modulos/M-<COD>.md` y el ticket `ESC-*`).
- [ ] Pruebas unitarias de reglas y transiciones nuevas.
- [ ] Pruebas de integración de endpoints nuevos o cambiados.
- [ ] Prueba de aislamiento multiempresa (si toca datos de empresa).
- [ ] Pruebas de permisos (permitido y denegado) y de feature flag (bloqueado y solo lectura).
- [ ] Migraciones con RLS forzado, FK compuestas y prueba en PostgreSQL 16 y 17.
- [ ] Contrato `api/openapi.yaml` actualizado y validado.
- [ ] Textos en archivos de mensajes `es-PE`.
- [ ] Accesibilidad: axe sin errores en pantallas nuevas.
- [ ] Sin datos médicos ni personales en logs, colas, correos o IA.
- [ ] Valores legales como parámetros con `to_confirm`.
- [ ] Auditoría (`audit_logs`) en escrituras relevantes.
- [ ] `make verify` en verde y CI en verde.
- [ ] Revisión aprobada por una persona.
- [ ] Documentación del módulo actualizada si cambió una regla.

Una versión está lista para producción cuando además:

- [ ] E2E críticos en verde.
- [ ] Pruebas de carga de endpoints públicos sin 5xx.
- [ ] Restauración de respaldo probada en el ciclo vigente.
- [ ] Sin vulnerabilidades altas abiertas en dependencias.
- [ ] Notas de versión escritas.
