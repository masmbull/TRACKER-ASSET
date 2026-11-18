#!/usr/bin/env bash
# Static guards for the production deploy path.
#
# Every check below stands for a failure that has already happened once:
#
#   - an outbound SSH hop from a GitHub-hosted runner that cannot reach the
#     server, so every deploy timed out before it copied anything;
#   - a compose file that would build on the server instead of pulling the image
#     CI published, which is how "deploy" and "what CI tested" drift apart;
#   - two deploys racing the same compose project;
#   - an nginx config that caches a dead container IP and answers 502 until
#     somebody restarts it by hand;
#   - a database that starts with an empty password because the variable was
#     optional.
#
# It is a script rather than a test case on purpose: what breaks here is the
# shape of YAML and nginx files, not application behaviour, and the same three
# files have to stay true on the runner where there is no PHP at all.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

deploy=".github/workflows/deploy.yml"
ci=".github/workflows/ci.yml"
compose="docker-compose.prod.yml"
nginx="docker/nginx.conf"
doc="docs/deployment.md"

failures=0

pass() { printf '  ok   %s\n' "$1"; }
fail() { printf '  FAIL %s\n' "$1" >&2; failures=$((failures + 1)); }

# must_contain <file> <extended regex> <description>
must_contain() {
    if grep -Eq -- "$2" "$1"; then
        pass "$3"
    else
        fail "$3 -- $1 has no match for /$2/"
    fi
}

# must_contain_fixed <file> <literal text> <description>
must_contain_fixed() {
    if grep -Fq -- "$2" "$1"; then
        pass "$3"
    else
        fail "$3 -- $1 does not contain \"$2\""
    fi
}

# must_not_contain <file> <extended regex> <description>
must_not_contain() {
    if grep -Eq -- "$2" "$1"; then
        fail "$3 -- $1 matches /$2/"
    else
        pass "$3"
    fi
}

# must_appear_times <file> <literal text> <count> <description>
#
# Fixed-string counting, because the compose image expression is full of `${...}`
# that an extended regex would either misread or need escaping to keep.
must_appear_times() {
    local found
    found="$(grep -Fc -- "$2" "$1" || true)"

    if [[ "$found" == "$3" ]]; then
        pass "$4"
    else
        fail "$4 -- $1 contains \"$2\" $found time(s), expected $3"
    fi
}

# must_precede <file> <earlier literal> <later literal> <description>
#
# Order is the whole point in the FastCGI block: fastcgi_params is included
# first so the overrides after it win, and written the other way round the port
# silently falls back to 80.
must_precede() {
    local first second
    first="$(grep -Fn -- "$2" "$1" | head -n 1 | cut -d: -f1 || true)"
    second="$(grep -Fn -- "$3" "$1" | head -n 1 | cut -d: -f1 || true)"

    if [[ -n "$first" && -n "$second" && "$first" -lt "$second" ]]; then
        pass "$4"
    else
        fail "$4 -- $1 needs \"$2\" (line ${first:-none}) before \"$3\" (line ${second:-none})"
    fi
}

printf 'Deploy configuration checks\n\n'

printf 'deploy.yml\n'

# The failure this file replaced: a GitHub-hosted runner opening a connection to
# a server that has no inbound SSH for it to reach.
must_not_contain "$deploy" '^[[:space:]]*(ssh|scp|rsync)([[:space:]]|$)' \
    'no outbound SSH/SCP hop from a hosted runner'
must_not_contain "$deploy" 'DEPLOY_(HOST|USER|PORT|SSH)' \
    'no SSH host, user, port or key secret'

must_contain "$deploy" 'runs-on:.*self-hosted' \
    'runs on the self-hosted runner'
must_contain "$deploy" 'runs-on:.*production' \
    'runs only on the runner labelled production'

must_contain "$deploy" 'workflows: \["CI"\]' \
    'fires only after CI'
must_contain "$deploy" 'branches: \[main\]' \
    'fires only for main'
must_contain "$deploy" 'workflow_dispatch' \
    'keeps a manual redeploy escape hatch'
must_contain "$deploy" 'head_branch == .main.' \
    'refuses any run whose source branch is not main'

must_contain "$deploy" 'group: deploy-production' \
    'serialises deploys through one concurrency group'
must_contain "$deploy" 'cancel-in-progress: false' \
    'never cancels a deploy mid-flight'

must_contain "$deploy" 'packages: read' \
    'pulls the image with the run token'
must_contain "$deploy" 'password-stdin' \
    'never puts the registry token in a process list'
must_contain "$deploy" 'docker-compose\.prod\.yml' \
    'deploys the production compose file'
must_contain "$deploy" 'docker/nginx\.conf' \
    'deploys the nginx config'
must_contain "$deploy" 'run --rm --no-deps assets' \
    're-copies the compiled assets on every release'
must_contain "$deploy" 'up -d --remove-orphans' \
    'recreates the stack'

must_contain "$deploy" '! -w "\$DEPLOY_PATH"' \
    'fails loudly when the deploy path is not writable'
must_contain "$deploy" '! -f "\$DEPLOY_PATH/\.env"' \
    'fails loudly when .env is missing'

printf '\nci.yml\n'

must_contain_fixed "$ci" 'scripts/check-deploy-config.sh' \
    'CI runs these checks'

printf '\ndocker-compose.prod.yml\n'

must_appear_times "$compose" 'image: ${IMAGE_REPOSITORY:-ghcr.io/masmbull/tracker-asset}:${IMAGE_TAG:-latest}' 3 \
    'app, scheduler and assets all run the image CI published'
must_not_contain "$compose" '^[[:space:]]*build:' \
    'never builds on the server'

must_appear_times "$compose" 'RUN_MIGRATIONS=true' 1 \
    'exactly one service runs migrations'
must_contain_fixed "$compose" '${DB_PASSWORD:?set DB_PASSWORD in .env}' \
    'a missing database password fails the deploy instead of starting empty'

must_appear_times "$compose" 'ports:' 1 \
    'nginx is the only service that publishes a port'
must_contain_fixed "$compose" '${HTTP_PORT:-80}:80' \
    'the published port stays configurable'

must_appear_times "$compose" 'healthcheck:' 2 \
    'postgres and redis both gate the app on readiness'
must_contain_fixed "$compose" 'command: php artisan schedule:work' \
    'the scheduler stays a separate service'
must_contain_fixed "$compose" 'restart: unless-stopped' \
    'the stack comes back after a reboot'

printf '\ndocker/nginx.conf\n'

must_contain_fixed "$nginx" 'resolver 127.0.0.11 valid=10s ipv6=off;' \
    'nginx re-resolves the app container instead of caching its IP'
must_contain_fixed "$nginx" 'set $tracker_app app:9000;' \
    'the upstream address lives in a variable'
must_contain_fixed "$nginx" 'fastcgi_pass $tracker_app;' \
    'the FastCGI upstream is that variable'
must_not_contain "$nginx" '^[[:space:]]*fastcgi_pass app:9000;' \
    'no hardcoded upstream line'

must_precede "$nginx" 'include fastcgi_params;' 'fastcgi_param HTTPS $tracker_https;' \
    'fastcgi_params is included before the scheme overrides'
must_contain_fixed "$nginx" 'map $http_x_forwarded_proto $tracker_https {' \
    'the scheme comes from X-Forwarded-Proto, not a hardcoded HTTPS'
must_contain_fixed "$nginx" 'client_max_body_size 320M;' \
    'the agent upload fits through nginx'

printf '\ndocs/deployment.md\n'

must_contain_fixed "$doc" 'self-hosted' \
    'the runbook says where the deploy runs'
must_contain_fixed "$doc" '--labels production' \
    'the runbook registers the runner with the production label'
must_contain_fixed "$doc" 'DEPLOY_PATH' \
    'the runbook documents the one optional setting'

printf '\n'

if ((failures > 0)); then
    printf '%d deploy configuration check(s) failed.\n' "$failures" >&2
    exit 1
fi

printf 'Deploy configuration checks passed.\n'