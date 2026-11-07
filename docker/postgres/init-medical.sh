#!/bin/bash
# Se ejecuta solo la primera vez que se crea el volumen de la base médica.
set -euo pipefail

echo ">> Cargando schema_medical.sql en escudo_medical"
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d escudo_medical -f /database/schema_medical.sql

echo ">> Creando usuario LOGIN de desarrollo"
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d escudo_medical <<SQL
CREATE ROLE escudo_med_api LOGIN PASSWORD '${ESCUDO_MED_API_PASSWORD}' IN ROLE escudo_med_app;
SQL

echo ">> escudo_medical listo"

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
create_backup_role escudo_medical
