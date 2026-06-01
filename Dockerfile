FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Composer (pinned major version)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# --- Layer-cache optimisation ---
# Copy dependency manifests first so the composer install layer is only
# invalidated when composer.json or composer.lock actually change.
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy the rest of the application
COPY . .

# Finalise autoloader with application classes now available
RUN composer dump-autoload --no-dev --optimize

# Set correct permissions and switch to non-root user for security
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 storage bootstrap/cache \
    && find storage -type f -exec chmod 644 {} \;

USER www-data

# Expose port
EXPOSE 8000

# NOTE: `php artisan serve` is suitable for low-traffic / demo deployments.
# For high-traffic production, replace with php-fpm + Nginx as a reverse proxy.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
