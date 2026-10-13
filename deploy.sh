#!/usr/bin/env bash
#
# Deploy the latest main to the production stack, correctly.
#
# WHY THIS EXISTS (and why `git pull` alone is not enough)
#
# The compose stack bind-mounts the repository over /var/www/html, so the PHP
# source you pull is live immediately - but `public/build` is gitignored. It is
# produced by the image's asset stage (node + vite), not committed. A pull
# therefore updates every Blade template while the browser keeps loading the
# previous CSS/JS bundle, and a changed Alpine entry can leave the page stuck on
# its preloader with nothing in any log. That exact failure shipped once already.
#
# So a deploy has to: pull, rebuild the image, and copy the freshly built
# assets OUT of the image onto the host (where the bind mount will serve them).
#
# It also has to run git from INSIDE the container. storage/ is chowned to
# www-data for php-fpm, so the host user cannot write it, and a pull that needs
# to touch a file under storage/ dies with "Permission denied". Root inside the
# container can write the mount; then ownership is handed back.
#
# Usage, on the server:
#
#     cd /opt/tracker-asset && ./deploy.sh
#
set -euo pipefail

cd "$(dirname "$0")"

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
COMPOSE="docker compose -f ${COMPOSE_FILE}"
APP_SERVICE="${APP_SERVICE:-app}"

echo "==> [1/7] Pull latest main (git via the container: storage/ is www-data-owned)"
${COMPOSE} exec -u root -T "${APP_SERVICE}" sh -c 'cd /var/www/html && git fetch origin && git checkout main && git pull --ff-only origin main'
echo "    now at $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

echo "==> [2/7] Rebuild image (asset stage compiles CSS/JS)"
${COMPOSE} up -d --build

echo "==> [3/7] Wait for php-fpm"
sleep 10

echo "==> [4/7] Publish built assets onto the host (bind mount shadows the image copy)"
if [ -d public/build ]; then
    rm -rf public/build
fi
CID=$(${COMPOSE} create "${APP_SERVICE}")
docker cp "$CID:/var/www/html/public/build" "$(pwd)/public/build"
docker rm "$CID" >/dev/null
echo "    serving $(grep -oE 'assets/app-[A-Za-z0-9_-]+\.js' public/build/manifest.json | head -1)"

echo "==> [5/7] Hand storage back to php-fpm (git ran as root)"
${COMPOSE} exec -u root -T "${APP_SERVICE}" chown -R www-data:www-data storage bootstrap/cache

echo "==> [6/7] Clear caches and migrate"
${COMPOSE} exec -T "${APP_SERVICE}" php artisan optimize:clear
${COMPOSE} exec -T "${APP_SERVICE}" php artisan migrate --force

echo "==> [7/7] Restart nginx (re-resolves the new app container) and verify"
${COMPOSE} restart nginx >/dev/null 2>&1
sleep 3

curl -s -o /dev/null -w "    login=%{http_code}\n" http://localhost/login || true
echo "    done. $(git rev-parse --short HEAD) is live."