#!/bin/sh
# Arranque de la API en producción: prepara la configuración en caché con el .env montado y luego ejecuta el comando
# del servicio (php-fpm, queue:work o schedule:work). No corre migraciones: eso se hace a mano en cada versión.
set -e
cd /var/www/backend

if [ ! -f .env ]; then
  echo "Falta /var/www/backend/.env (se monta desde deploy/backend.env en el servidor)." >&2
  exit 1
fi

# Carpetas que viven en el volumen de archivos (pueden venir vacías la primera vez).
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private
chown -R www-data:www-data storage 2>/dev/null || true

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan event:cache >/dev/null
# Las vistas compiladas van al volumen compartido (storage): las compila solo la API, para que la cola y el
# programador no choquen con ella al arrancar juntos (rename: No such file or directory).
if [ "$1" = "php-fpm" ]; then
  php artisan view:cache >/dev/null
fi
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# php-fpm baja solo sus procesos a www-data; el resto (cola, programador, artisan) corre directamente como www-data
# para que los registros y archivos que crea se puedan seguir escribiendo.
if [ "$1" = "php-fpm" ]; then
  exec "$@"
fi
exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
