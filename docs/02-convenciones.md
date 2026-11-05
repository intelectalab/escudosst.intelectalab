# 02 · Convenciones

Aplican a todo el código del repositorio. `make verify` y la revisión de una persona las hacen cumplir.

## 1. Idioma

| Elemento | Idioma | Ejemplo |
|---|---|---|
| Interfaz, documentación, mensajes al usuario | Español (Perú) | «Guardar cambios», «Vence en 5 días» |
| Código, clases, variables | Inglés | `IpercRowService`, `$fitnessStatus` |
| Tablas y columnas | Inglés, `snake_case`, plural | `iperc_rows.residual_risk_level` |
| Rutas de API | Inglés, `kebab-case`, plural | `/api/v1/corrective-actions/{id}/close` |
| Permisos | `module.resource.action` en minúsculas | `ipc.iperc_rows.update`, `emo.medical_exams.read` |
| Comentarios de código | Español o inglés, breves, solo el «por qué» | — |
| Commits y PR | Español | — |

## 2. Nombres

### Backend (Laravel)
- Módulo: directorio con el código del catálogo en mayúsculas (`app/Modules/IPC`).
- Modelo: singular en PascalCase (`IpercRow`). Tabla: plural snake (`iperc_rows`).
- Controlador: `<Recurso>Controller`, solo orquesta.
- Request: `Store<Recurso>Request`, `Update<Recurso>Request`.
- Servicio: `<Acción><Recurso>Service` o `<Recurso>Service`.
- Job: verbo + objeto (`BuildDossierJob`, `SendExpiryAlertsJob`).
- Evento: pasado (`InspectionClosed`, `CredentialRevoked`).
- Máquina de estado: `<Entidad>StateMachine`. Estados en inglés `snake_case` en BD (`in_review`), etiqueta en español en la interfaz.
- Enums PHP respaldados por string.

### Base de datos
- Clave primaria `id` UUID v7.
- Columnas estándar: `id, company_id, created_at, updated_at, created_by, updated_by, deleted_at`. Tablas inalterables: sin `updated_*` ni `deleted_at`.
- FK: `<entidad_singular>_id` (`worker_id`). Entre tablas de empresa, FK compuesta `(company_id, worker_id)`.
- Booleanos: `is_`, `has_` (`is_anonymous`).
- Fechas: `_at` para instante (`timestamptz`), `_on` para día (`date`): `signed_at`, `expires_on`.
- Índices: `idx_<tabla>_<columnas>`. Únicos: `uq_<tabla>_<columnas>`. Políticas RLS: `rls_<tabla>_company`.
- Parámetros legales: tabla de parámetros con `key`, `value`, `valid_from`, `valid_to`, `source`, `to_confirm`.

### Frontend (Next.js)
- Componentes en PascalCase (`IpercMatrixTable.tsx`). Hooks `useX`. Archivos de utilidades en kebab-case.
- Rutas en español para el usuario solo en textos; los segmentos de URL en inglés (`/app/iperc/matrices/[id]`).
- Tipos de la API generados desde `api/openapi.yaml`. No escribir tipos de API a mano.

## 3. Ramas

| Rama | Uso |
|---|---|
| `main` | Siempre desplegable a producción. Protegida |
| `develop` | Integración; se despliega a pruebas |
| `feature/ESC-<COD>-<NN>-<slug>` | Una historia del backlog |
| `fix/ESC-<COD>-<NN>-<slug>` | Corrección |
| `hotfix/<slug>` | Urgencia en producción, desde `main` |

Reglas: una historia por rama. PR obligatorio con revisión humana. Sin push directo a `main` ni `develop`.

## 4. Commits

Formato Conventional Commits, en español, con la historia:

```
feat(IPC): agrega versionado de matriz IPERC [ESC-IPC-03]

Detalle opcional del porqué.
```

Tipos: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `security`, `db` (migraciones).
Ámbito: código del módulo o `core`, `infra`, `web`, `pwa`.

## 5. Pruebas

Detalle en `12-pruebas-y-calidad.md`. Mínimo por historia:
- Pruebas unitarias de reglas `RN-*` y transiciones de estado.
- Pruebas de integración de los endpoints tocados.
- **Prueba de aislamiento multiempresa** si toca datos de empresa.
- Prueba de permisos por rol (permitido y denegado).
- Prueba de feature flag (módulo no contratado y solo lectura).

Backend: Pest. Frontend: Vitest + Testing Library. E2E: Playwright.

## 6. Migraciones

- Dos carpetas: `database/migrations/main` y `database/migrations/medical`. Nunca mezclar.
- Una migración por cambio lógico. Nombre descriptivo en inglés.
- Toda tabla de empresa en la misma migración: `company_id NOT NULL`, `ENABLE` y `FORCE ROW LEVEL SECURITY`, política `rls_<tabla>_company`, FK compuestas.
- Tablas inalterables: `REVOKE UPDATE, DELETE` al rol de la aplicación y trigger que rechaza `UPDATE`/`DELETE`.
- Las migraciones deben correr en PostgreSQL 17 y 16.
- Cambios destructivos en dos pasos: primero agregar y migrar datos; después quitar en otra versión.
- Las migraciones no se editan después de llegar a `develop`. Se crea una nueva.
- Datos semilla de catálogos globales (peligros base, normas, paquetes sectoriales, parámetros legales) en seeders versionados.

## 7. i18n y textos

- Todo texto de interfaz en archivos de mensajes (`es-PE`). Sin textos sueltos en componentes.
- Claves por módulo: `ipc.matrix.title`.
- Tono: tú cercano y claro en la vista del trabajador; usted neutro en correos formales y documentos legales. Ver `07-pantallas-y-ux.md`.
- Mensajes de error del backend: código estable en inglés + mensaje en español.
- Aunque solo exista `es-PE`, la estructura permite agregar idiomas.

## 8. Fechas y horas

- Zona horaria de negocio: **America/Lima** (UTC−5, sin horario de verano).
- En BD: instantes como `timestamptz` (UTC). Días como `date`.
- En la API: ISO 8601 con desfase (`2026-03-15T08:30:00-05:00`). Días como `YYYY-MM-DD`.
- En pantalla: `dd/mm/aaaa` y `HH:mm` (24 h).
- Vencimientos se calculan por día calendario en America/Lima. «Vence hoy» = la fecha `_on` es hoy en Lima.
- El scheduler corre en America/Lima.
- Los plazos legales (días hábiles o calendario) son parámetros; el calendario de feriados es una tabla, no una constante.

## 9. Dinero

- Moneda: soles (PEN). Campo `currency` por si acaso, por defecto `PEN`.
- En BD: `numeric(14,2)`. Nunca `float`.
- En PHP: objetos `Money` (brick/money o equivalente). En TypeScript: string decimal, nunca `number` para cálculos.
- En pantalla, formato de ejemplo: `S/ 1,234.50`.
- Precios de planes: solo en configuración (`plans`). Mientras no se definan, mostrar «S/ —» o «Por definir».

## 10. Archivos

- Nunca binarios en la base. Metadatos en tabla (`file_key`, `bucket`, `mime`, `size`, `sha256`, `uploaded_by`) y objeto en B2.
- Claves de objeto: `<company_id>/<module>/<entity_id>/<uuid>.<ext>`. Sin nombres de personas en la clave.
- Bucket médico separado para todo archivo de tablas `db: med`.
- Subida: URL firmada de subida directa a B2; luego confirmación al API que verifica tamaño, tipo real (magic bytes) y hash.
- Descarga: URL firmada de corta duración generada por la API tras validar permiso.
- Tipos permitidos por módulo (lista blanca). Imágenes se re-codifican y se les quita EXIF salvo la ubicación cuando el módulo la requiere.
- Antivirus en la cola antes de marcar el archivo como disponible (ver decisiones abiertas).

## 11. Errores

Formato de respuesta de error (RFC 9457 simplificado):

```json
{
  "type": "module_not_in_plan",
  "title": "El módulo no está incluido en tu plan",
  "status": 403,
  "detail": "Matriz de requisitos legales requiere el plan Profesional.",
  "errors": { "campo": ["mensaje"] },
  "trace_id": "01J..."
}
```

Códigos estables mínimos: `validation_failed` (422), `unauthenticated` (401), `reauth_required` (401), `forbidden` (403), `module_not_in_plan` (403), `module_read_only` (403), `not_found` (404), `invalid_transition` (409), `conflict` (409), `idempotency_replay` (200 con resultado previo), `rate_limited` (429).

- Nunca devolver trazas, SQL ni datos de otra empresa.
- Un recurso de otra empresa responde 404, no 403.

## 12. Logs

- JSON estructurado a stdout. Campos: `ts`, `level`, `trace_id`, `company_id`, `user_id`, `route`, `job`, `duration_ms`, `message`.
- Prohibido registrar: contraseñas, tokens, datos médicos, DNI completo, contenido de archivos, cuerpo de peticiones de módulos médicos, prompts completos de IA.
- DNI y teléfono enmascarados (`4567****`).
- Logs técnicos ≠ auditoría. La auditoría de negocio va a `audit_logs` y `sensitive_access_logs`, no a archivos de log.
- Niveles: `error` requiere acción; `warning` degradación; `info` eventos de negocio relevantes; `debug` solo en desarrollo.
