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

# 2. Solo la primera vez: dependencias y clave de Laravel
docker compose -f compose.dev.yml exec api composer install
docker compose -f compose.dev.yml exec api php artisan key:generate

# 3. Frontend
cd frontend
npm install
npm run dev
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

## Parar

```bash
docker compose -f compose.dev.yml down
```

> ⚠️ No uses `down -v`, `docker volume prune` ni `docker system prune`: borran las bases. Para recargar el esquema desde cero a propósito: `docker compose -f compose.dev.yml down -v` y luego `up -d`.

## Pendiente de definir con el equipo

- Faltan en el repositorio: `spec/` (`contexto.md`, `catalogo.json`), `CLAUDE.md`, `tools/` (`make verify`, generadores) y `api/openapi.yaml`, que la documentación cita.
- Almacenamiento S3 local: la imagen `minio/minio` ya no se publica en Docker Hub; en desarrollo se usa el disco local.
- Cómo conviven las migraciones de Laravel con `database/schema_main.sql` (hoy el esquema se carga por SQL al crear el contenedor).
