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
    && npm install -g npm pnpm bun yarn

# Fix permissions for www-data
RUN chown -R www-data:www-data /var/www/html

USER www-data

# Copy dependency files first to leverage cache
COPY --chown=www-data:www-data composer.json composer.lock package.json package-lock.json* pnpm-lock.yaml* bun.lockb* ./

# Install Composer dependencies (no autoloader yet)
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Install Node dependencies
RUN npm install

# Copy the rest of the application
COPY --chown=www-data:www-data . .

# Build frontend assets
RUN npm run build

# Generate optimized autoloader
RUN composer dump-autoload --optimize

# Clean up build dependencies
RUN rm -rf node_modules
