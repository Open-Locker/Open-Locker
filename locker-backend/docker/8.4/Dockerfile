FROM serversideup/php:8.4-fpm-nginx

LABEL maintainer="Open Locker"

USER root

# Install additional PHP extensions
RUN install-php-extensions \
    bcmath \
    gd \
    intl \
    xdebug

# Install system dependencies
RUN apt-get update && apt-get install -y \
    nano \
    unzip \
    git \
    gnupg \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Node.js
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && corepack enable \
    && corepack prepare pnpm@10.17.1 --activate

# Fix permissions for www-data
RUN chown -R www-data:www-data /var/www/html

USER www-data

# Copy dependency files first to leverage cache
COPY --chown=www-data:www-data composer.json composer.lock package.json pnpm-lock.yaml* bun.lockb* ./

# Install Composer dependencies (no autoloader yet)
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Install Node dependencies
RUN pnpm install --frozen-lockfile

# Copy the rest of the application
COPY --chown=www-data:www-data . .

# Build frontend assets
RUN pnpm run build

# Generate optimized autoloader.
#
# This runs package:discover, which boots every service provider — including
# OpenTelemetry's, which builds its exporters eagerly. There is nothing to
# export while building an image, and an exporter that cannot be constructed
# (an OTLP protocol whose transport package is not installed, say) fails the
# build rather than degrading. Disabling the SDK for this one command keeps
# image builds independent of telemetry configuration entirely.
#
# Scoped to the RUN, so it does not leak into the runtime image.
RUN OTEL_SDK_DISABLED=true composer dump-autoload --optimize

# Clean up build dependencies
RUN rm -rf node_modules

# Bootstrap the ADMIN_EMAIL administrator on start. Lives in the base image's
# entrypoint.d so it runs after migrations, on the one container that runs them.
USER root
COPY --chmod=755 docker/entrypoint.d/50-first-admin.sh /etc/entrypoint.d/50-first-admin.sh
USER www-data
