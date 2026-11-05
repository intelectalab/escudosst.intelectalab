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
