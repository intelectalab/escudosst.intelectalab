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
   Primero hace una **copia de seguridad cifrada** de la base principal y la médica (`escudo:backup`). Si la copia
   falla, **se detiene sin cambiar nada** y muestra el error (también queda en «Salud del sistema › Copias de seguridad»).
   Con la copia lista hace `git pull`, construye, reinicia y corre las migraciones.
   Solo en la primera instalación, cuando aún no hay contenedores levantados, se puede saltar la copia con
   `SKIP_BACKUP=1 ./deploy/escudo.sh update`.

## Prueba de restauración

`./deploy/escudo.sh restore-test` (o `./deploy/escudo.sh restore-test --target=main`) toma la última copia buena,
comprueba su huella, la descifra, la restaura en una base temporal `escudo_restore_test_…` del mismo servidor de
PostgreSQL, revisa que tenga tablas, tablas con RLS forzado, el registro de migraciones (base principal) y filas en las
tablas clave, borra la base temporal y deja el resultado y la duración en «Salud del sistema › Copias de seguridad ›
Pruebas de restauración». Sale con error si alguna prueba falla.

- Corre sola una vez por semana (domingo 04:00, hora de Lima): `BACKUP_RESTORE_TEST_DAY` (0 = domingo … 6 = sábado) y
  `BACKUP_RESTORE_TEST_AT` la cambian. Si no hay una prueba buena en `BACKUP_RESTORE_MAX_AGE_DAYS` días (8), el panel la
  marca «vencida»: sin prueba vigente no se publica una versión (docs/12 §9).
- Necesita un usuario de PostgreSQL que pueda crear bases: por defecto `postgres` con `POSTGRES_SUPERUSER_PASSWORD`
  (`BACKUP_RESTORE_DB_USERNAME` y `BACKUP_RESTORE_DB_PASSWORD` lo cambian). Para restaurar en un servidor aislado,
  `BACKUP_RESTORE_DB_HOST` y `BACKUP_RESTORE_DB_PORT` (ese servidor debe tener los mismos roles `escudo_*`).
- La restauración temporal ocupa disco y CPU unos minutos: el horario por defecto es de madrugada.

## Cuidados

- **Nunca** `docker compose ... down -v`: borra las bases de datos.
- Copia de seguridad cifrada diaria a las 02:30 en el volumen `api_storage`, y otra antes de cada `update`.
- **Límites de memoria por contenedor** en `compose.prod.yml` (`mem_limit`): pg-main 2 GB, pg-medical 1 GB, redis 512 MB,
  api 1 GB, worker 768 MB, scheduler 768 MB (la copia y la prueba de restauración corren ahí), web 768 MB, proxy 128 MB.
  Son valores de partida para un VPS de 8 GB: se ajustan con las variables `MEM_*` de `deploy/backend.env` (por ejemplo
  `MEM_PG_MAIN=3g`). Si un contenedor llega a su límite, Docker lo reinicia (`docker inspect` muestra `OOMKilled`).
- **Dominios propios de consultoras (automático):** una sola vez, agrega al cron de root del servidor (`sudo crontab -e`) la línea `*/10 * * * * cd /var/www/escudosst && ./deploy/escudo.sh domains-sync >> /var/log/escudo-dominios.log 2>&1`. Desde ahí, cuando una consultora verifica su dominio en la página (y su CNAME apunta a este servidor), se publica solo con su certificado en unos 10 minutos. Sin esa línea, se publica a mano con `./deploy/escudo.sh domain <dominio>`.
- **Vigilante:** cada hora `escudo:watch` revisa que haya copia de seguridad de las últimas 26 horas (si falta, la hace y avisa al correo de contacto del sitio) y avisa si el disco pasa el 85 % (`DISK_ALERT_PCT`).
- **Archivos médicos aparte:** los resultados de EMO, certificados de descanso médico y copias de registros médicos van cifrados con `MEDICAL_FIELD_KEY` en su propio volumen `medical_files` (no en `api_storage`). La base médica también está aparte (`pg_medical_data`). `update` deja el volumen con permisos solo para la aplicación. Para respaldarlo fuera del servidor: `docker run --rm -v escudo-prod_medical_files:/m -v "$PWD":/out alpine tar czf /out/medicos-$(date +%F).tgz -C /m .` (los archivos ya van cifrados; guarda la copia en un lugar privado).
- **Copia externa (fuera del servidor):** completa `BACKUP_S3_ENDPOINT`, `BACKUP_S3_BUCKET`, `BACKUP_S3_KEY`, `BACKUP_S3_SECRET` y `BACKUP_S3_REGION` en `deploy/backend.env` (Backblaze B2 o cualquier almacenamiento compatible con S3). Cada copia cifrada se sube también allí y se borra al pasar `BACKUP_REMOTE_RETENTION_DAYS` días (30 por defecto). En B2 crea el bucket privado `escudosst-prod-backups` con bloqueo de objetos y una llave de aplicación solo para ese bucket (leer, escribir, listar y borrar). Si se deja vacío, no se sube y el panel muestra «Copia externa: sin configurar». Comprueba con `./deploy/escudo.sh artisan escudo:backup`.
- Para ver los registros de un servicio: `./deploy/escudo.sh logs worker` (o `api`, `web`, `scheduler`, `proxy`).
