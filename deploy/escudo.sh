#!/usr/bin/env bash
# Atajos para producción de EscudoSST (se ejecuta desde la carpeta del proyecto en el servidor).
#   ./deploy/escudo.sh build            construir imágenes (api y web)
#   ./deploy/escudo.sh up               levantar o actualizar todo
#   ./deploy/escudo.sh migrate          migraciones de la base principal con el rol dueño
#   ./deploy/escudo.sh artisan <cmd>    cualquier comando de Laravel (como www-data)
#   ./deploy/escudo.sh logs [servicio]  registros (Ctrl+C para salir)
#   ./deploy/escudo.sh ps               estado de los contenedores
#   ./deploy/escudo.sh update           git pull + build + up + migrate (una versión nueva)
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
    "${DC[@]}" exec -T -u www-data api php artisan migrate --database=pgsql_owner --path=database/migrations/main --force
    "${DC[@]}" ps
    ;;
  *) sed -n '2,10p' "$0"; exit 1 ;;
esac
