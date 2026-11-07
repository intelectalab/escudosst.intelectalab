# PHP 8.4 para la API Laravel en desarrollo (docs/15-infraestructura-y-despliegue.md §2.2).
FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip gd bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Cliente de PostgreSQL 17 (pg_dump) para las copias de seguridad (docs/15 §9). Debe ser de la misma versión mayor
# que el servidor; Debian 13 (trixie), la base de esta imagen, ya trae la 17.
RUN apt-get update && apt-get install -y --no-install-recommends postgresql-client-17     && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend
