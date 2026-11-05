#!/bin/bash
# Se ejecuta solo la primera vez que se crea el volumen de la base principal.
# Carga el esquema, las semillas y crea los usuarios LOGIN de desarrollo
# (database/README.md, sección «Cómo cargar»).
set -euo pipefail

echo ">> Cargando schema_main.sql en escudo_main"
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d escudo_main -f /database/schema_main.sql

for f in /database/seeds/*.sql; do
  echo ">> Semilla $f"
  psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d escudo_main -f "$f"
done

echo ">> Creando usuarios LOGIN de desarrollo"
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d escudo_main <<SQL
CREATE ROLE escudo_api LOGIN PASSWORD '${ESCUDO_API_PASSWORD}' IN ROLE escudo_app;
CREATE ROLE escudo_owner_login LOGIN PASSWORD '${ESCUDO_OWNER_PASSWORD}' IN ROLE escudo_owner;
SQL

echo ">> escudo_main listo"
