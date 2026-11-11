# Despliegue de EscudoSST en el servidor (Contabo)

Guía paso a paso para subir y actualizar EscudoSST en `escudosst.intelectalab.com`. No contiene secretos: las contraseñas y llaves van solo en `deploy/backend.env` **dentro del servidor**.

## Cómo queda armado

```
Internet ─HTTPS→ nginx del servidor (certbot) ─→ 127.0.0.1:8096 ─→ contenedor «proxy» (nginx interno)
                                                                   ├─ /api/…  → «api» (Laravel, php-fpm)
                                                                   └─ el resto → «web» (Next.js)
Contenedores internos, sin puertos hacia afuera: pg-main, pg-medical, redis, worker (cola), scheduler (tareas programadas)
```

- Archivos: `compose.prod.yml`, `docker/php/Dockerfile.prod`, `frontend/Dockerfile`, `docker/nginx/escudo.conf`.
- Atajos: `deploy/escudo.sh`.
- Los otros sistemas del servidor (CRM, n8n, etc.) **no se tocan**. Solo se agrega un sitio nuevo en nginx.

## Primera vez

1. **Bajar el código.** En el servidor, en `/var/www`:
   ```
   git clone https://github.com/intelectalab/escudosst.intelectalab.git escudosst
   cd escudosst
   ```
   Si el repositorio es privado, usa una llave de despliegue (deploy key) de solo lectura.

2. **Secretos.**
   ```
   cp deploy/backend.env.example deploy/backend.env
   chmod 600 deploy/backend.env
   ```
   Completa cada valor vacío; el archivo explica cómo generar cada uno.
   - Contraseñas **nuevas**, nunca las de desarrollo.
   - `MAIL_PASSWORD`: la clave del correo escudosst@intelectalab.com.
   - **Guarda `BACKUP_KEY` y `BACKUP_MEDICAL_KEY` también fuera del servidor**: sin ellas no se pueden restaurar las copias.

3. **Construir y levantar.**
   ```
   ./deploy/escudo.sh build
   ./deploy/escudo.sh up
   ```
   La primera vez, PostgreSQL carga el esquema y las semillas **sin datos ficticios**. Tarda 1 o 2 minutos.

4. **Llave de Laravel.** Si `APP_KEY` quedó vacía:
   ```
   ./deploy/escudo.sh artisan key:generate --show
   ```
   Pega el resultado en `deploy/backend.env` y vuelve a ejecutar `./deploy/escudo.sh up`.

5. **Migraciones.**
   ```
   ./deploy/escudo.sh migrate
   ```

6. **Primer Dueño de la plataforma.** Llega un correo para crear la contraseña:
   ```
   ./deploy/escudo.sh artisan escudo:create-owner tu.correo@dominio.com "Tu nombre"
   ```

7. **nginx del servidor y HTTPS.**
   ```
   sudo cp deploy/nginx-escudosst.intelectalab.com.conf /etc/nginx/sites-available/escudosst.intelectalab.com
   sudo ln -s /etc/nginx/sites-available/escudosst.intelectalab.com /etc/nginx/sites-enabled/
   sudo nginx -t && sudo systemctl reload nginx
   sudo certbot --nginx -d escudosst.intelectalab.com --redirect
   ```

8. **Comprobar.**
   - https://escudosst.intelectalab.com abre el sitio.
   - `/login` permite entrar con el Dueño.
   - `./deploy/escudo.sh ps` muestra todo «running».
   - `./deploy/escudo.sh logs api` no muestra errores.

9. **En el panel** (Sitio web): revisa Contacto y WhatsApp, logo, políticas, y activa la agenda y el chatbot cuando quieras (arrancan apagados).

## Actualizar a una versión nueva

1. En tu computadora: commit y push a `main`.
2. En el servidor:
   ```
   cd /var/www/escudosst
   ./deploy/escudo.sh update
   ```
   Hace `git pull`, construye, reinicia y corre las migraciones.

## Cuidados

- **Nunca** `docker compose ... down -v`: borra las bases de datos.
- Copia de seguridad cifrada diaria a las 02:30 en el volumen `api_storage`.
- **Copia externa (fuera del servidor):** completa `BACKUP_S3_ENDPOINT`, `BACKUP_S3_BUCKET`, `BACKUP_S3_KEY`, `BACKUP_S3_SECRET` y `BACKUP_S3_REGION` en `deploy/backend.env` (Backblaze B2 o cualquier almacenamiento compatible con S3). Cada copia cifrada se sube también allí y se borra al pasar `BACKUP_REMOTE_RETENTION_DAYS` días (30 por defecto). En B2 crea el bucket privado `escudosst-prod-backups` con bloqueo de objetos y una llave de aplicación solo para ese bucket (leer, escribir, listar y borrar). Si se deja vacío, no se sube y el panel muestra «Copia externa: sin configurar». Comprueba con `./deploy/escudo.sh artisan escudo:backup`.
- Para ver los registros de un servicio: `./deploy/escudo.sh logs worker` (o `api`, `web`, `scheduler`, `proxy`).
