# PHP 8.4, not 8.3.
#
# composer.json says ^8.3, which permits anything below 9.0 - but composer.lock
# was resolved on 8.4 and pinned symfony/console and fifteen other components at
# 8.1.x, and those require PHP >= 8.4.1. The constraint in composer.json is not
# the version the lock file can install on, and it is the lock file that
# `composer install` obeys.
#
# Built on 8.3 that arrives as composer exit code 2 - a dependency solving error
# that never mentions the image - which is how it went undiagnosed for a build.
# 8.4 is also what CI runs and what the lock was resolved against, so the image
# was the only place still claiming 8.3.
#
# To support 8.3 for real, re-resolve the lock against that platform rather than
# lowering the image again:
#
#     composer config platform.php 8.3.0 && composer update
#
# Extensions to add on top of what the base image ships.
#
# php:8.4-fpm already carries dom, simplexml, xml, xmlreader, xmlwriter and
# mbstring. Those are exactly what phpspreadsheet and dompdf need for the Excel
# import and the generated PDFs, so there is nothing to install for them - which
# is why they are absent here rather than merely optional. Confirm before
# changing anything here:
#
#     docker run --rm php:8.4-fpm php -m | grep -iE 'dom|simplexml|xml|mbstring'
#
# Installed one per RUN so a failure names the extension that caused it. A single
# combined RUN reports "non-zero code: 2" for all of them at once, which is how
# an earlier version of this file spent a whole deploy cycle being guessed at.
FROM php:8.4-fpm

WORKDIR /var/www/html

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
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j"$(nproc)" exif
RUN docker-php-ext-install -j"$(nproc)" pcntl
RUN docker-php-ext-install -j"$(nproc)" bcmath
RUN docker-php-ext-install -j"$(nproc)" gd
RUN docker-php-ext-install -j"$(nproc)" pdo_pgsql
RUN docker-php-ext-install -j"$(nproc)" zip
RUN docker-php-ext-install -j"$(nproc)" intl

# Report the extension state, but do not fail the build on it.
#
# This exists so that a missing extension is visible in the build log at the
# point it matters, rather than surfacing two hundred lines later inside
# `composer install` as "your php version does not satisfy requirement".
#
# Deliberately a warning, not an error. The contents of the base image drift
# between runs, and a hard check turns that drift into a build failure with no
# visible cause. A loud warning plus a readable composer error further down is
# the better trade.
#
# One line, no line continuations. Docker parses each line of a RUN as a
# separate instruction unless it ends in a backslash, and a multi-line string
# inside `php -r '...'` is not something that survives that - it produced
# "dockerfile parse error: unknown instruction: $need", because the second line
# of the PHP looked like a Dockerfile directive.
COPY docker/check-extensions.php /usr/local/bin/check-extensions.php
RUN php /usr/local/bin/check-extensions.php && rm /usr/local/bin/check-extensions.php

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
