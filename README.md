# EscudoSST

SaaS multiempresa de Seguridad y Salud en el Trabajo (Perú). Documentación en [`docs/`](docs/), modelo de datos en [`database/`](database/) y prototipo navegable en [`prototipo/index.html`](prototipo/index.html).

## Estructura

| Carpeta | Contenido |
|---|---|
| `backend/` | API Laravel 12 (PHP 8.4) |
| `frontend/` | Web y PWA en Next.js 15 + TypeScript |
| `database/` | Esquemas SQL generados, semillas y catálogos |
| `docker/` | Imagen PHP de desarrollo y scripts de carga de PostgreSQL |
| `compose.dev.yml` | Entorno local con Docker |

## Requisitos

- Docker Desktop
- Node.js 20 o superior
- Git (GitHub Desktop sirve)

## Arrancar en local

```bash
# 1. Bases de datos, Redis, Mailpit y la API (la primera vez carga el esquema y las semillas)
docker compose -f compose.dev.yml up -d

# 2. Solo la primera vez: dependencias, clave y migraciones de Laravel
cp backend/.env.example backend/.env
docker compose -f compose.dev.yml run --rm --no-deps api composer install
docker compose -f compose.dev.yml up -d api
docker compose -f compose.dev.yml exec api php artisan key:generate
docker compose -f compose.dev.yml exec api php artisan migrate --database=pgsql_owner

# 3. Frontend
cd frontend
cp .env.local.example .env.local
npm install
npm run dev
```

> `backend/vendor/` vive en un volumen de Docker (`api_vendor`) porque en Windows leerlo desde la carpeta es muy lento. Los comandos de Composer se corren dentro del contenedor: `docker compose -f compose.dev.yml exec api composer ...`.

### Usuarios de prueba

Datos ficticios de [`docker/postgres/dev-seed.sql`](docker/postgres/dev-seed.sql), solo para desarrollo. Un Jefe SST por plan, cada uno en su empresa. Contraseña de todos: `EscudoDemo2026`.

| Correo | Empresa | Plan | Módulos |
|---|---|---|---|
| `esencial@escudo.test` | AgroExport Valle de Chincha | Esencial | 22 |
| `profesional@escudo.test` | Industrias Andinas | Profesional | 39 (38 activos; Venta de cursos viene apagado) |
| `corporativo@escudo.test` | Constructora Huaylas | Corporativo | 51 (50 activos) |

### Verificación en dos pasos (MFA)

El rol Jefe SST la exige (y también dueño, soporte, administrador de cuenta, consultor, médico y personal de salud; ver `backend/config/escudo.php`). La primera vez que entras te pide escanear un QR con una app de autenticación (Google Authenticator, Microsoft Authenticator…) y te entrega 8 códigos de recuperación.

Para quitarle el MFA a un usuario (pérdida del celular, o para volver a probar el alta en desarrollo):

```bash
docker compose -f compose.dev.yml exec api php artisan escudo:mfa-reset esencial@escudo.test
```

### Correo

En desarrollo **ningún correo sale a internet**: todos llegan a Mailpit, en http://localhost:8026, aunque el destinatario sea inventado (`@escudo.test`). Ahí se ven, por ejemplo, los códigos de verificación.

Para probar con un **Gmail real**:

1. En la cuenta de Google: activa la verificación en dos pasos y crea una **contraseña de aplicación** (Cuenta de Google → Seguridad → Contraseñas de aplicaciones). Son 16 letras.
2. En `backend/.env` comenta las líneas `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` y `MAIL_FROM_ADDRESS` de Mailpit y descomenta las de Gmail con tus datos.
3. Para que el código te llegue, cambia el correo de un usuario de prueba al tuyo:

```bash
docker compose -f compose.dev.yml exec api php artisan escudo:user-email esencial@escudo.test tu.cuenta@gmail.com
```

Gmail sirve solo para pruebas (límite de envíos diarios). En producción el proveedor definido es Elastic Email.

### Alta de clientes (panel de la plataforma)

En **Cuentas y suscripciones → Nueva cuenta** se crea la cuenta con su empresa (RUC validado con dígito verificador), la suscripción (prueba Profesional o plan contratado) y el administrador de cuenta. Al administrador le llega un correo (en Mailpit) con el enlace para crear su contraseña; el enlace vence en 72 h (`INVITATION_TTL_HOURS`) y arma con `FRONTEND_URL`. En su primer ingreso configura la verificación en dos pasos.

### Pagos y vencimientos (automático)

El contenedor `scheduler` corre todos los días a las 07:00 (hora de Lima) `php artisan escudo:subscriptions-daily`:

- **Avisos** 5 días antes, 3 días antes y el mismo día del vencimiento: por correo y en la campana del sistema (administrador de cuenta y Jefe SST). Nunca se repite un aviso.
- Al vencer sin pago: **Pago pendiente** durante los días de gracia (`SUBSCRIPTION_GRACE_DAYS`, hoy 3); la empresa sigue trabajando.
- Terminada la gracia: **solo lectura** (ve y descarga, no registra). La prueba gratuita vencida también queda en solo lectura.
- En el panel, **Registrar pago** desbloquea al momento y extiende el periodo (desde que venció; pagar antes no hace perder días).

Para probarlo sin esperar, cambia la fecha de fin del periodo en la base y córrelo a mano:

```bash
docker compose -f compose.dev.yml exec api php artisan escudo:subscriptions-daily
```

## Pruebas

```bash
docker compose -f compose.dev.yml exec api ./vendor/bin/pest       # backend (base escudo_main_test)
docker compose -f compose.dev.yml exec api ./vendor/bin/pint        # formato del código PHP
cd frontend && npx eslint . && npx tsc --noEmit                      # frontend
```

## Migraciones

El esquema base sale de `database/schema_main.sql` (se carga al crear el contenedor). Los cambios nuevos van como migraciones de Laravel en `backend/database/migrations/main` (o `medical`) y se corren con el rol dueño de las tablas:

```bash
docker compose -f compose.dev.yml exec api php artisan migrate --database=pgsql_owner
```

| Servicio | Dirección |
|---|---|
| Web (Next.js) | http://localhost:3000 |
| API (Laravel) | http://localhost:8000 · salud: http://localhost:8000/up |
| Correos de prueba (Mailpit) | http://localhost:8026 |
| PostgreSQL principal `escudo_main` | localhost:5440 |
| PostgreSQL médica `escudo_medical` | localhost:5441 |
| Redis | localhost:6380 |

Usuarios de base de datos en desarrollo (solo locales): `postgres` / `postgres_dev` (superusuario), `escudo_api` / `escudo_api_dev` (la app, con RLS), `escudo_owner_login` / `escudo_owner_dev` (migraciones), `escudo_med_api` / `escudo_med_api_dev` (base médica).

## Velocidad

En desarrollo, la API corre con 8 procesos (`PHP_CLI_SERVER_WORKERS` en `compose.dev.yml`) para que el panel pueda pedir varias cosas a la vez, y el frontend usa Turbopack (`npm run dev`). La primera vez que se abre cada página, Next la compila (medio segundo aprox.); después es inmediata.

Para producción (además de lo de `docs/15`):

- API: `php artisan optimize` (config, rutas y eventos en caché) en cada despliegue, y OPcache con `opcache.validate_timestamps=0`. No se cachea en desarrollo porque las pruebas usan otra base y la caché de configuración la ignoraría.
- Frontend: `npm run build` y `npm run start` (las páginas ya van compiladas).

## Parar

```bash
docker compose -f compose.dev.yml down
```

> ⚠️ No uses `down -v`, `docker volume prune` ni `docker system prune`: borran las bases. Para recargar el esquema desde cero a propósito: `docker compose -f compose.dev.yml down -v` y luego `up -d`.

## Pendiente de definir con el equipo

- Faltan en el repositorio: `spec/` (`contexto.md`, `catalogo.json`), `CLAUDE.md`, `tools/` (`make verify`, generadores) y `api/openapi.yaml`, que la documentación cita.
- Almacenamiento S3 local: la imagen `minio/minio` ya no se publica en Docker Hub; en desarrollo se usa el disco local.
- Cómo conviven las migraciones de Laravel con `database/schema_main.sql` (hoy el esquema se carga por SQL al crear el contenedor y `personal_access_tokens` de Sanctum va como migración).
- MFA: `docs/08-api.md` no lo exige a `sst_manager`, pero `M-USR.md` (RN-USR-05) sí. Se eligió lo más estricto (sí lo exige) en `config/escudo.php`; conviene corregir el documento y la semilla de roles. Pendiente: código por correo y guardar el secreto en el gestor de secretos.
- Dónde guarda el token la web: hoy `localStorage`; `docs/08-api.md` permite la cookie de sesión de Sanctum con CSRF, más segura para la web.
- Parámetros de seguridad «a confirmar» en `backend/config/escudo.php`: intentos antes del bloqueo (5), minutos de bloqueo (15) y vigencia del token (12 h).
- Suscripciones: la duración de la prueba gratuita es «por definir» (`SUBSCRIPTION_TRIAL_DAYS`, hoy 14 días). `docs/03` §8 dice que bajar de plan aplica al cierre del periodo; desde el panel del dueño hoy se aplica al momento (no hay dónde guardar un cambio programado). El plan Consultora (K) aún no se gestiona en «Cuentas».
