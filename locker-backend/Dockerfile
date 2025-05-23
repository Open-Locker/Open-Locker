# Stage 1: Builder
# Nutzt ein CLI-Image von SSU, das Composer und Node/NPM enthält
FROM serversideup/php:8.4-cli as builder

# Temporär zu root wechseln, um Pakete, Node.js zu installieren und Berechtigungen zu setzen
USER root

# Installiere notwendige OS-Pakete, die PHP intl-Erweiterung und Node.js (LTS)
# libicu-dev wird für intl benötigt
# ca-certificates, curl, gnupg werden für NodeSource benötigt
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    gnupg \
    libicu-dev \
    libffi-dev \
    libmodbus5 \
    && apt-get update \
    && docker-php-ext-install intl exif ffi \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Erst alle notwendigen Dateien kopieren
COPY composer.json composer.lock ./
COPY . .
COPY packages/OpenLocker/php-modbus-ffi /app/packages/OpenLocker/php-modbus-ffi

# Dann als root die Berechtigungen für den gesamten App-Ordner setzen
RUN chown -R www-data:www-data /app

# Jetzt zum Standardbenutzer wechseln für die Build-Schritte
USER www-data

# Als www-data ausführen
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Stage 2: Final Production Image
# Nutzt das schlanke FPM-Image von SSU
FROM serversideup/php:8.4-fpm-nginx

# Temporär zu root wechseln, um die intl-Erweiterung zu installieren
USER root

# Installiere notwendige OS-Pakete und die PHP intl-Erweiterung für die Laufzeit
# libicu-dev wird für intl benötigt
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev \
    libffi-dev \
    libmodbus5 \
    && apt-get update \
    && docker-php-ext-install intl exif ffi \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Füge www-data zur dialout-Gruppe hinzu (für Zugriff auf /dev/ttyACM0)
RUN usermod -aG dialout www-data

# Zurück zum Standardbenutzer für den Rest
USER www-data

ENV APP_ENV=production
ENV LOG_CHANNEL=stderr
ENV SESSION_DRIVER=cookie
ENV CACHE_DRIVER=file

ENV APP_DEBUG=false
ENV XDEBUG_MODE=off

# Kopiere notwendige Artefakte aus der Builder-Stage
COPY --from=builder --chown=www-data:www-data /app/vendor ./vendor
COPY --from=builder --chown=www-data:www-data /app/public ./public
COPY --from=builder --chown=www-data:www-data /app/bootstrap ./bootstrap
COPY --from=builder --chown=www-data:www-data /app/config ./config
COPY --from=builder --chown=www-data:www-data /app/database ./database
COPY --from=builder --chown=www-data:www-data /app/resources ./resources
COPY --from=builder --chown=www-data:www-data /app/routes ./routes
COPY --from=builder --chown=www-data:www-data /app/app ./app
COPY --from=builder --chown=www-data:www-data /app/.env.example ./.env.example
COPY --from=builder --chown=www-data:www-data /app/artisan ./artisan
COPY --from=builder --chown=www-data:www-data /app/composer.json ./composer.json

# Kopiere den Storage-Ordner-Rahmen (ohne Logs, Sessions etc.)
COPY --from=builder --chown=www-data:www-data /app/storage/app ./storage/app
COPY --from=builder --chown=www-data:www-data /app/storage/framework/cache ./storage/framework/cache
COPY --from=builder --chown=www-data:www-data /app/storage/framework/testing ./storage/framework/testing
COPY --from=builder --chown=www-data:www-data /app/storage/framework/views ./storage/framework/views

# Kopiere die Konfiguration für das ffi-Modul
COPY --chown=www-data:www-data packages/OpenLocker/php-modbus-ffi ./packages/OpenLocker/php-modbus-ffi

# Kopiere die PHP Konfiguration für den FPM-Container
COPY ./99-locker.ini /usr/local/etc/php/conf.d/99-locker.ini

RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache/data storage/los \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache
