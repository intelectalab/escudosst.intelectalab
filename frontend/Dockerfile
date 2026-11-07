# Sitio y paneles (Next.js 15) para producción (docs/15 §2.2: servicio «web», output standalone).
# Se construye con:  docker compose -f compose.prod.yml build web
# NEXT_PUBLIC_API_URL queda grabada en el código del navegador al compilar: es la dirección pública del sitio.

FROM node:22-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

FROM node:22-alpine AS build
WORKDIR /app
ARG NEXT_PUBLIC_API_URL
ARG NEXT_PUBLIC_SITE_URL
# El middleware (redirecciones) puede quedar compilado con esta dirección: se fija también aquí.
ARG API_INTERNAL_URL=http://proxy
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL \
    NEXT_PUBLIC_SITE_URL=$NEXT_PUBLIC_SITE_URL \
    API_INTERNAL_URL=$API_INTERNAL_URL \
    NEXT_TELEMETRY_DISABLED=1
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npm run build

FROM node:22-alpine AS run
WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0 \
    TZ=America/Lima
# Sin root: el servidor corre con el usuario «node» de la imagen.
COPY --from=build --chown=node:node /app/.next/standalone ./
COPY --from=build --chown=node:node /app/.next/static ./.next/static
COPY --from=build --chown=node:node /app/public ./public
USER node
EXPOSE 3000
CMD ["node", "server.js"]
