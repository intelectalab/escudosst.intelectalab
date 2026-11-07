#!/bin/bash
# Se ejecuta solo la primera vez que se crea el volumen de la base principal.
# Carga el esquema y las semillas en escudo_main y crea los usuarios LOGIN (database/README.md, «Cómo cargar»).
# En desarrollo (por defecto) también carga los datos ficticios y crea escudo_main_test para las pruebas.
# En producción: ESCUDO_LOAD_DEV_DATA=false → sin datos ficticios ni base de pruebas.
set -euo pipefail
DEV_DATA="${ESCUDO_LOAD_DEV_DATA:-true}"

load_db() {
  local db="$1"
  echo ">> Cargando schema_main.sql en $db"
  psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$db" -f /database/schema_main.sql

  for f in /database/seeds/*.sql; do
    echo ">> Semilla $f en $db"
    psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$db" -f "$f"
  done

  if [ "$DEV_DATA" = "true" ]; then
    echo ">> Datos ficticios de desarrollo en $db"
    psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$db" -f /docker-entrypoint-initdb.d/dev-seed.sql.inc
  fi
}

load_db escudo_main

echo ">> Creando usuarios LOGIN"
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d escudo_main <<SQL
CREATE ROLE escudo_api LOGIN PASSWORD '${ESCUDO_API_PASSWORD}' IN ROLE escudo_app;
CREATE ROLE escudo_owner_login LOGIN PASSWORD '${ESCUDO_OWNER_PASSWORD}' IN ROLE escudo_owner;
CREATE ROLE escudo_platform_login LOGIN PASSWORD '${ESCUDO_PLATFORM_PASSWORD}' IN ROLE escudo_platform;
SQL

if [ "$DEV_DATA" = "true" ]; then
  psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -c "CREATE DATABASE escudo_main_test"
  load_db escudo_main_test
  echo ">> escudo_main y escudo_main_test listos"
else
  echo ">> escudo_main listo (producción: sin datos ficticios)"
fi

create_backup_role() {
  # Usuario de solo lectura para las copias de seguridad (docs/15 §9): lee todas las filas (BYPASSRLS) y nada más.
  # Si no se pasa ESCUDO_BACKUP_PASSWORD queda sin poder ingresar; se activa luego con
  #   ALTER ROLE escudo_backup LOGIN PASSWORD '...';   (la misma contraseña que DB_BACKUP_PASSWORD de backend/.env)
  if [ -n "${ESCUDO_BACKUP_PASSWORD:-}" ]; then
    psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$1" -c "CREATE ROLE escudo_backup LOGIN BYPASSRLS PASSWORD '${ESCUDO_BACKUP_PASSWORD}'"
  else
    psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$1" -c "CREATE ROLE escudo_backup NOLOGIN BYPASSRLS"
  fi
  psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$1" -c "GRANT pg_read_all_data TO escudo_backup"
}
create_backup_role escudo_main
