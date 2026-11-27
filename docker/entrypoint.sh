#!/bin/sh
#
# Container start-up for the Docker Compose stack.
#
# The compose file brings up php-fpm behind nginx, but nothing in it ever runs
# the migrations or the seeders: a fresh `docker compose up -d --build` left an
# empty database with no users, so every login failed with "credentials do not
# match" and nothing said why. The README tells an operator to run migrate and
# db:seed by hand afterwards, and that step is what gets missed.
#
# This script closes that gap. It runs the same two commands the README asks
# for, in the right order, every time the container starts - so the stack is
# usable the moment it is up.
#
# It is opt-in through RUN_MIGRATIONS. The prod compose file sets it; a stack
# that migrates by hand leaves it unset and this file changes nothing. That
# default matters: the image's CMD is php-fpm, and a stack with several web
# replicas must not have every one of them racing the same migration.

set -e

cd /var/www/html

# The image already creates these, but a volume mounted over storage/ would hide
# them - the same class of failure the storage volume seeding exists to
# prevent. Idempotent and cheap, so it runs unconditionally.
mkdir -p \
    storage/logs \
    storage/app/public \
    storage/app/agent \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache

# The built agent is split into <100 MB parts because GitHub rejects files
# larger than 100 MB. Reassemble it into the persistent storage path on boot
# so /agent/download/latest works on a fresh deployment without an operator
# having to run agent:pull by hand. Idempotent: skip when already assembled.
if [ -f public/agent/TrackerAgent.exe.part00 ]; then
    TARGET="storage/app/agent/TrackerAgent.exe"
    if [ ! -f "$TARGET" ] || [ "$(stat -c %s "$TARGET" 2>/dev/null || echo 0)" -lt 100000000 ]; then
        echo "[entrypoint] assembling TrackerAgent.exe from shipped parts..." >&2
        cat public/agent/TrackerAgent.exe.part* > "$TARGET.tmp"
        mv "$TARGET.tmp" "$TARGET"
    fi
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    # Wait for the database rather than dying on the first refused connection.
    # Compose starts app and postgres together, and postgres is routinely not
    # accepting connections yet for the first few seconds.
    attempt=1
    max_attempts="${DB_WAIT_ATTEMPTS:-30}"

    until php artisan migrate --force; do
        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "[entrypoint] database did not accept migrations after ${max_attempts} attempts - giving up" >&2
            exit 1
        fi

        echo "[entrypoint] database not ready (attempt ${attempt}/${max_attempts}); retrying in ${DB_WAIT_SECONDS:-2}s" >&2
        attempt=$((attempt + 1))
        sleep "${DB_WAIT_SECONDS:-2}"
    done

    echo "[entrypoint] migrations applied"

    # Seeding is what actually makes a login possible: it is the only thing that
    # creates the admin account. Every seeder uses firstOrCreate/updateOrCreate,
    # so running it on every boot is safe and never duplicates rows.
    #
    # A failure here is reported but not fatal - a demo-data seeder that chokes
    # on a missing spreadsheet must not stop the web server from coming up.
    if [ "${RUN_SEED:-true}" = "true" ]; then
        if php artisan db:seed --force; then
            echo "[entrypoint] database seeded"
        else
            echo "[entrypoint] seeding failed; continuing - the app is up but may have no users" >&2
        fi
    fi
fi

# Hand off to the real process. `exec` so php-fpm (or `php artisan schedule:work`
# for the scheduler service) becomes PID 1 and receives signals directly.
exec "$@"