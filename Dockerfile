# Coolify-friendly multi-stage build:
# - vendor stage tanpa compile PHP extensions (hemat RAM BuildKit)
# - frontend stage terpisah (cache npm)
# - runtime stage: satu kali compile extensions dengan -j2

# ============================================================
# Stage 1: PHP vendor (composer)
# ============================================================
FROM php:8.4-cli-bookworm AS vendor

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_MEMORY_LIMIT=512M \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs

COPY . .
RUN composer dump-autoload \
    --no-dev \
    --optimize \
    --no-interaction

# ============================================================
# Stage 2: Node frontend (vite build)
# ============================================================
FROM node:22-bookworm-slim AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .

ENV NODE_OPTIONS=--max-old-space-size=512
RUN npm run build

# ============================================================
# Stage 3: Runtime (PHP-FPM + nginx + supervisor)
# ============================================================
FROM php:8.4-fpm-bookworm AS runtime

# Coolify passes build-time env vars; declare them so BuildKit bake stays stable.
ARG APP_KEY
ARG APP_ENV=production
ARG APP_DEBUG=false
ARG APP_URL
ARG COOLIFY_FQDN

ENV APP_DIR=/var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    iputils-ping \
    libcurl4-openssl-dev \
    nginx \
    nodejs \
    openssh-client \
    sqlite3 \
    supervisor \
    libfreetype6-dev \
    libicu-dev \
    libjpeg62-turbo-dev \
    libonig-dev \
    libpng-dev \
    libpq-dev \
    libsqlite3-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 \
        bcmath curl exif gd intl mbstring pcntl pdo_mysql pdo_pgsql pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR ${APP_DIR}

COPY --from=vendor /app ${APP_DIR}
COPY --from=frontend /app/public/build ${APP_DIR}/public/build
COPY --from=frontend /app/node_modules ${APP_DIR}/node_modules

COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/start-container.sh /usr/local/bin/start-container

RUN chmod +x /usr/local/bin/start-container \
    && rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
    && mkdir -p /run/php /run/nginx ${APP_DIR}/storage ${APP_DIR}/bootstrap/cache \
    && chown -R www-data:www-data ${APP_DIR}/storage ${APP_DIR}/bootstrap/cache

EXPOSE 8088 8078

ENTRYPOINT ["/usr/local/bin/start-container"]
