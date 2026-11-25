#!/usr/bin/env bash
# Atajos para producción de EscudoSST (se ejecuta desde la carpeta del proyecto en el servidor).
#   ./deploy/escudo.sh build            construir imágenes (api y web)
#   ./deploy/escudo.sh up               levantar o actualizar todo
#   ./deploy/escudo.sh migrate          migraciones de la base principal con el rol dueño
#   ./deploy/escudo.sh artisan <cmd>    cualquier comando de Laravel (como www-data)
#   ./deploy/escudo.sh logs [servicio]  registros (Ctrl+C para salir)
#   ./deploy/escudo.sh ps               estado de los contenedores
#   ./deploy/escudo.sh update           copia previa + git pull + build + up + migrate (una versión nueva; sin copia buena no sigue)
#   ./deploy/escudo.sh restore-test     prueba de restauración de la última copia en una base temporal (queda registrada)
#   ./deploy/escudo.sh domain <dominio> publica el dominio propio (ya verificado) de una consultora, con su certificado
#   ./deploy/escudo.sh domains-sync     publica solos los dominios verificados en la página (va en el cron, cada 10 min)
# Nunca usar «down -v»: borra las bases de datos.
set -euo pipefail
cd "$(dirname "$0")/.."

ENV_FILE=deploy/backend.env
if [ ! -f "$ENV_FILE" ]; then
  echo "Falta $ENV_FILE. Cópialo desde deploy/backend.env.example y complétalo." >&2
  exit 1
fi
DC=(docker compose -f compose.prod.yml --env-file "$ENV_FILE")

# M-CON RN-CON-05: publica el dominio propio (ya verificado por DNS) de una consultora, con su certificado.
publish_domain() {
  local D="$1"
  [[ "$D" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$ ]] || { echo "Dominio no válido: $D" >&2; return 1; }
  "${DC[@]}" exec -T -u www-data api php artisan escudo:con-domain "$D" || return 1
  local CONF="/etc/nginx/sites-available/escudo-cliente-$D"
  sed -e "s/server_name escudosst.intelectalab.com;/server_name $D;/" -e '/Strict-Transport-Security/d'     deploy/nginx-escudosst.intelectalab.com.conf | sudo tee "$CONF" >/dev/null
  sudo ln -sf "$CONF" "/etc/nginx/sites-enabled/escudo-cliente-$D"
  if ! { sudo nginx -t && sudo systemctl reload nginx && sudo certbot --nginx -d "$D" --redirect --non-interactive --agree-tos --keep-until-expiring; }; then
    # Si falla (por ejemplo el CNAME aún no apunta aquí), se retira para no dejar un sitio a medias.
    sudo rm -f "/etc/nginx/sites-enabled/escudo-cliente-$D" "$CONF"
    sudo nginx -t && sudo systemctl reload nginx
    return 1
  fi
  echo "Publicado: https://$D"
}

case "${1:-}" in
  build)   "${DC[@]}" build ;;
  up)      "${DC[@]}" up -d --remove-orphans ;;
  migrate) "${DC[@]}" exec -T -u www-data api php artisan migrate --database=pgsql_owner --path=database/migrations/main --force ;;
  artisan) shift; "${DC[@]}" exec -u www-data api php artisan "$@" ;;
  logs)    shift; "${DC[@]}" logs -f --tail=100 "$@" ;;
  ps)      "${DC[@]}" ps ;;
  update)
    # docs/15 §8 paso 2: copia de seguridad previa de las bases antes de tocar nada. Si falla, no se actualiza.
    # (Solo para la primera instalación, cuando aún no hay contenedores: SKIP_BACKUP=1 ./deploy/escudo.sh update)
    if [ "${SKIP_BACKUP:-0}" = "1" ]; then
      echo ">> Sin copia previa (SKIP_BACKUP=1)."
    else
      echo ">> Copia de seguridad previa (base principal y médica)"
      if ! "${DC[@]}" exec -T -u www-data api php artisan escudo:backup --target=all; then
        echo "La copia de seguridad previa falló: la actualización se detuvo y no se cambió nada." >&2
        echo "Revisa el error de arriba (o «Salud del sistema › Copias de seguridad») y vuelve a intentarlo." >&2
        exit 1
      fi
    fi
    git pull --ff-only
    "${DC[@]}" build
    "${DC[@]}" up -d --remove-orphans
    # El volumen de archivos médicos nace vacío y de root: que lo pueda escribir la aplicación.
    "${DC[@]}" exec -T -u root api sh -c 'chown www-data:www-data storage/app/private/medical && chmod 700 storage/app/private/medical'
    "${DC[@]}" exec -T -u www-data api php artisan migrate --database=pgsql_owner --path=database/migrations/main --force
    # Tras reiniciar los contenedores, ninguna tarea programada (p. ej. la copia diaria) queda trabada por un bloqueo viejo.
    "${DC[@]}" exec -T -u www-data api php artisan schedule:clear-cache
    "${DC[@]}" ps
    ;;
  restore-test)
    # docs/15 §9: restaura la última copia buena en una base temporal, la revisa, la borra y deja el resultado en el panel.
    "${DC[@]}" exec -T -u www-data api php artisan escudo:backup:restore-test "${@:2}"
    ;;
  domain)
    publish_domain "${2:?Indica el dominio, por ejemplo: ./deploy/escudo.sh domain sst.miconsultora.pe}"
    ;;
  domains-sync)
    # Publica solos los dominios que las consultoras verificaron en la página (se programa una vez en el cron del servidor).
    "${DC[@]}" exec -T -u www-data api php artisan escudo:con-domains | while read -r D; do
      [ -z "$D" ] && continue
      [ -e "/etc/nginx/sites-enabled/escudo-cliente-$D" ] && continue
      publish_domain "$D" || echo "Aún no se pudo publicar $D (¿el CNAME ya apunta a este servidor?). Se reintenta en la próxima vuelta."
    done
    ;;
  *) sed -n '2,13p' "$0"; exit 1 ;;
esac
