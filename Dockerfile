# --- stage 1: front-end assets ------------------------------------------------
#
# Separate stage so node and node_modules never reach the runtime image.
#
# public/build is gitignored, so the compiled CSS and JS cannot arrive through
# `COPY .` in the stage below - they have to be built here and copied across, or
# the deployed image serves Blade templates whose @vite directive points at
# files that do not exist. The page renders; the styling and every Livewire
# interaction do not. Nothing logs an error.
#
# The whole source is copied, not just vite.config.js and resources/. Tailwind 4
# derives its utility list by scanning the project for class names, so a build
# stage holding only the entry CSS would emit a stylesheet with nearly every
# class stripped - a page that loads, looks wrong, and gives no hint why.
#
# node:22 rather than Debian's nodejs: vite 8.3.0 and laravel-vite-plugin 3.2.0
# both declare `engines: ^20.19.0 || >=22.12.0`, and Debian ships 18. The
# platform builds of rolldown, oxide and lightningcss that npm will pick are all
# present in package-lock.json for linux-x64, so `npm ci` resolves here.
#
# This stage is discarded, so the size of the full node image over alpine costs
# one pull at build time and nothing in the result.
#
# Package files are installed before the source so `npm ci` stays cached -
# editing a Blade file rebuilds the assets, not the dependency tree.
FROM node:22 AS assets

WORKDIR /app

# .npmrc is deliberately not copied, though it sits next to these files on a
# developer's machine.
#
# It holds ignore-scripts=true there, but .gitignore line 20 keeps it out of the
# repository by policy - so `COPY .npmrc` fails the build on every machine that
# has never run npm locally, which is the server and every fresh clone. That is
# exactly how this stage failed the first time it was run: "file not found in
# build context: stat .npmrc".
#
# Dropping it costs nothing. fsevents is the only package in package-lock.json
# carrying an install script, and it is darwin-only, so on linux ignore-scripts
# has nothing to act on either way.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN npm run build

# --- stage 2: php runtime -----------------------------------------------------
#
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

# Silence git's "dubious ownership" refusal for the application directory.
#
# The container runs as root, and compose bind-mounts the host clone over
# /var/www/html with the developer's (or server user's) ownership. git >=
# 2.35.2 refuses to touch a repository owned by someone else, so every git
# call inside the container - including the one composer makes while resolving
# the root package, which printed the fatal before the first line of every
# `composer install` - dies with "detected dubious ownership in repository at
# '/var/www/html'" and instructions nobody should have to follow twice.
#
# Harmless where it appeared (composer installed from the lock file anyway),
# but it is exactly the warning-noise commit 2e4e750 removed from compose:
# output that trains people to ignore output. Baked into the image rather than
# configured in a running container, because a container's /etc/gitconfig is
# rebuilt away on the next recreate.
RUN git config --system --add safe.directory /var/www/html

RUN docker-php-ext-install -j"$(nproc)" exif
RUN docker-php-ext-install -j"$(nproc)" pcntl
RUN docker-php-ext-install -j"$(nproc)" bcmath
RUN docker-php-ext-install -j"$(nproc)" gd
RUN docker-php-ext-install -j"$(nproc)" pdo_pgsql
RUN docker-php-ext-install -j"$(nproc)" zip
RUN docker-php-ext-install -j"$(nproc)" intl

# phpredis, from PECL rather than the bundled set.
#
# config/database.php pins client => phpredis and composer carries no predis, so
# this extension is the only Redis client the image can ever have. The k8s
# manifests run SESSION_DRIVER, CACHE_STORE and QUEUE_CONNECTION all on Redis -
# two replicas share nothing on disk, so sessions cannot be files - meaning
# without it every request that touches a session dies with
# `Class "Redis" not found`. Nothing in this image's history caught it because
# local development runs cache=file and queue=sync and never calls Redis at all;
# it surfaced first as the health page's Queue row reporting a bare "Error" for a
# queue that was fine, and would have surfaced next as a 500 on every login the
# moment the office cluster came up.
#
# `yes ''` answers every prompt pecl asks (igbinary, lzf, zstd, msgpack, lz4)
# with the default "no". Left alone, pecl blocks on stdin waiting for an answer
# nobody is there to give, and the build hangs until it is killed - which looks
# like a network timeout and sends people looking in the wrong place.
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && yes '' | pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

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

# Copy the application source
COPY . /var/www/html

# The compiled front-end assets, from stage 1.
#
# Copied after the source. public/build is excluded in .dockerignore, so it
# cannot arrive via `COPY .` above and this line is the only thing that can
# supply it.
#
# That exclusion is also the guard. If the assets stage stopped producing
# output, this COPY fails on the missing path and the deploy stops, rather than
# shipping an image whose pages render unstyled with every Livewire interaction
# dead and nothing in any log to say why. Deleting the exclusion takes the guard
# with it: with public/build back in the build context, `COPY .` would satisfy
# the path from whatever happened to be on the developer's disk, and the build
# would pass.
COPY --from=assets /app/public/build ./public/build

# Laravel needs these directories to exist before anything of it boots. They are
# deliberately absent from the build context: .dockerignore excludes
# storage/framework/{cache,sessions,views}, and git does not track empty
# directories anyway, so the build has to create them itself.
#
# This RUN must stay ahead of `composer dump-autoload` below, not after it.
# dump-autoload fires the post-autoload-dump hook, which runs
# `artisan package:discover`, which boots the application. Blade asks for its
# compiled-view path during that boot, and with storage/framework/views missing
# the build dies with "Please provide a valid cache path" - a message that names
# neither the directory nor the step that wanted it.
#
# Adding --no-scripts to that dump-autoload is not an alternative fix: it would
# skip package discovery at build time, and bootstrap/cache/packages.php would
# then have to be written on the first request instead, which fails outright if
# bootstrap/cache is not writable at runtime.
RUN mkdir -p storage/logs storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Composer autoloader is regenerated after the real source is in place, so the
# classmap points at the copied files rather than the empty skeleton. This also
# writes bootstrap/cache/{packages,services}.php, baking package discovery into
# the image instead of repeating it on every request. The order is load-bearing.
RUN composer dump-autoload --optimize --no-dev

# Writable by the unprivileged user Kubernetes runs as. Baking the ownership in
# matters because an emptyDir/PVC mounted over storage would otherwise hide it.
RUN chown -R www-data:www-data storage bootstrap/cache

# Laravel writes compiled views and framework cache here. Without a separate
# writable path these land on a read-only root filesystem and every request 500s.
ENV COMPOSER_ALLOW_SUPERUSER=1

EXPOSE 9000

# php-fpm pool size, raised from the default of 5 workers.
#
# The default is fine for a site people visit. It is not fine for a fleet: an
# agent that holds its inbox request open while it waits for a command occupies
# a worker for as long as it waits, and 5 is the whole pool. Six laptops online
# would therefore not slow the dashboard down, they would take the site
# offline - every page would queue behind the agents and nothing would answer.
#
# 40 leaves room for the workers actually serving pages while the fleet is
# connected. Raised here rather than in an entrypoint so the value is part of the
# image: an operator tuning a www.conf by hand on the server would be lost the
# next rebuild, and nothing would say why.
RUN printf '[www]\n\
pm = dynamic\n\
pm.max_children = 40\n\
pm.start_servers = 4\n\
pm.min_spare_servers = 4\n\
pm.max_spare_servers = 8\n\
pm.max_requests = 500\n' \
    > /usr/local/etc/php-fpm.d/zz-tracker-pool.conf

# Container start-up: migrations, seeding, then the real process.
#
# Copied from the source (which the build ignores nothing of here), made
# executable, and set as ENTRYPOINT with php-fpm as the default CMD. The compose
# stack sets RUN_MIGRATIONS=true so a fresh `docker compose up` seeds itself; the
# Kubernetes manifests do not, so they keep migrating via their own Job and this
# entrypoint just execs php-fpm. sed strips a possible CRLF: the file is written
# on Windows and a shell script with \r in it dies on Linux with "not found".
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
