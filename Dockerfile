# PHP 8.3, not 8.2. composer.json requires ^8.3 and the app uses typed
# constants and readonly classes that 8.2 will not parse, so the image built on
# 8.2 either failed to install dependencies or shipped a runtime that fataled
# on first request - both discovered at deploy time rather than build time.
FROM php:8.3-fpm

WORKDIR /var/www/html

# System dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libicu-dev \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-install \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    pdo_pgsql \
    zip \
    intl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencies are installed in their own layer, before the source is copied.
# Copying package.json/composer.json alone means editing a PHP file does not
# re-run composer install, which is the difference between a two-second
# rebuild and a four-minute one.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --optimize-autoloader --no-dev --no-scripts

# Node is needed to compile the front-end assets. Vite's output lives in
# public/build and is NOT in git, so an image built without this step boots
# with a Livewire page that renders but does nothing - no error, just dead UI.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund \
    && npm run build \
    && rm -rf node_modules

# Copy the application source
COPY . /var/www/html

# Composer autoloader is regenerated after the real source is in place, so the
# classmap points at the copied files rather than the empty skeleton.
RUN composer dump-autoload --optimize --no-dev

# Create storage and cache directories
RUN mkdir -p storage/logs storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Writable by the unprivileged user Kubernetes runs as. Baking the ownership in
# matters because an emptyDir/PVC mounted over storage would otherwise hide it.
RUN chown -R www-data:www-data storage bootstrap/cache

# Laravel writes compiled views and framework cache here. Without a separate
# writable path these land on a read-only root filesystem and every request 500s.
ENV COMPOSER_ALLOW_SUPERUSER=1

EXPOSE 9000

CMD ["php-fpm"]
