#!/usr/bin/env bash
# Atajos para producción de EscudoSST (se ejecuta desde la carpeta del proyecto en el servidor).
#   ./deploy/escudo.sh build            construir imágenes (api y web)
#   ./deploy/escudo.sh up               levantar o actualizar todo
#   ./deploy/escudo.sh migrate          migraciones de la base principal con el rol dueño
#   ./deploy/escudo.sh artisan <cmd>    cualquier comando de Laravel (como www-data)
#   ./deploy/escudo.sh logs [servicio]  registros (Ctrl+C para salir)
#   ./deploy/escudo.sh ps               estado de los contenedores
#   ./deploy/escudo.sh update           git pull + build + up + migrate (una versión nueva)
#   ./deploy/escudo.sh domain <dominio> publica el dominio propio (ya verificado) de una consultora, con su certificado
# Nunca usar «down -v»: borra las bases de datos.
set -euo pipefail
cd "$(dirname "$0")/.."

ENV_FILE=deploy/backend.env
if [ ! -f "$ENV_FILE" ]; then
  echo "Falta $ENV_FILE. Cópialo desde deploy/backend.env.example y complétalo." >&2
  exit 1
fi
DC=(docker compose -f compose.prod.yml --env-file "$ENV_FILE")

case "${1:-}" in
  build)   "${DC[@]}" build ;;
  up)      "${DC[@]}" up -d --remove-orphans ;;
  migrate) "${DC[@]}" exec -T -u www-data api php artisan migrate --database=pgsql_owner --path=database/migrations/main --force ;;
  artisan) shift; "${DC[@]}" exec -u www-data api php artisan "$@" ;;
  logs)    shift; "${DC[@]}" logs -f --tail=100 "$@" ;;
  ps)      "${DC[@]}" ps ;;
  update)
    git pull --ff-only
    "${DC[@]}" build
    "${DC[@]}" up -d --remove-orphans
    # El volumen de archivos médicos nace vacío y de root: que lo pueda escribir la aplicación.
    "${DC[@]}" exec -T -u root api sh -c 'chown www-data:www-data storage/app/private/medical && chmod 700 storage/app/private/medical'
    "${DC[@]}" exec -T -u www-data api php artisan migrate --database=pgsql_owner --path=database/migrations/main --force
    "${DC[@]}" ps
    ;;
  domain)
    # M-CON RN-CON-05: solo un dominio verificado por DNS. La consultora debe tener el CNAME apuntando a este servidor.
    D="${2:?Indica el dominio, por ejemplo: ./deploy/escudo.sh domain sst.miconsultora.pe}"
    [[ "$D" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$ ]] || { echo "Dominio no válido: $D" >&2; exit 1; }
    "${DC[@]}" exec -T -u www-data api php artisan escudo:con-domain "$D"
    CONF="/etc/nginx/sites-available/escudo-cliente-$D"
    sed -e "s/server_name escudosst.intelectalab.com;/server_name $D;/" -e '/Strict-Transport-Security/d'       deploy/nginx-escudosst.intelectalab.com.conf | sudo tee "$CONF" >/dev/null
    sudo ln -sf "$CONF" "/etc/nginx/sites-enabled/escudo-cliente-$D"
    sudo nginx -t && sudo systemctl reload nginx
    sudo certbot --nginx -d "$D" --redirect --non-interactive --agree-tos --keep-until-expiring
    echo "Publicado: https://$D"
    ;;
  *) sed -n '2,11p' "$0"; exit 1 ;;
esac
