#!/usr/bin/env bash
#
# One command to stand the whole thing up on a Kubernetes cluster.
#
#   ./deploy.sh
#
# Safe to run again. Everything it does is either create-if-absent or a rolling
# update, so a second run after a code change redeploys rather than duplicating.
#
# Needs, on the machine you run it from: docker, kubectl (context already
# pointed at the office cluster), and a registry you can push to.
#
# Nothing here deletes anything. Rollback and uninstall are separate on purpose.

set -euo pipefail

cd "$(dirname "$0")"

# --- settings ---------------------------------------------------------------
# Everything you might need to change is in this block.

# Settings can come from the environment, so nothing here has to be edited by
# hand - which matters because this file is tracked in git and every manual
# edit turns the next `git pull` into a conflict.
#
# One-time setup on the server:
#
#     echo 'export TRACKER_REGISTRY=192.168.1.178:5000' >> ~/.bashrc
#     echo 'export TRACKER_APP_HOST=tracker-asset.office.local' >> ~/.bashrc
#     source ~/.bashrc
#
# Running once without those set prints the exact commands to add.
REGISTRY="${REGISTRY:-${TRACKER_REGISTRY:-}}"
IMAGE_NAME="${IMAGE_NAME:-tracker-asset}"
IMAGE_TAG="${IMAGE_TAG:-latest}"
NAMESPACE="${NAMESPACE:-tracker-asset}"
APP_HOST="${APP_HOST:-${TRACKER_APP_HOST:-tracker-asset.office.local}}"
# The PostgreSQL that comes with postgres.yaml. If the office already runs a
# PostgreSQL the app can reach, point this at that instead and set
# USE_BUNDLED_POSTGRES=no below - running two databases for one app is how you
# end up writing to the wrong one.
DB_HOST="${DB_HOST:-tracker-asset-postgres}"
USE_BUNDLED_POSTGRES="${USE_BUNDLED_POSTGRES:-yes}"
DB_NAME="${DB_NAME:-tracker_asset}"
DB_USER="${DB_USER:-tracker}"
STORAGE_SIZE="${STORAGE_SIZE:-20Gi}"

if [ -t 1 ]; then RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; BOLD=$'\033[1m'; OFF=$'\033[0m'
else RED=''; GREEN=''; YELLOW=''; BOLD=''; OFF=''; fi

step() { printf '\n%s==>%s %s\n' "$BOLD" "$OFF" "$1"; }
ok()   { printf '%s  ok%s  %s\n' "$GREEN" "$OFF" "$1"; }
warn() { printf '%s  !!%s  %s\n' "$YELLOW" "$OFF" "$1"; }
die()  { printf '%s  XX%s  %s\n' "$RED" "$OFF" "$1" >&2; exit 1; }

# --- preflight --------------------------------------------------------------
# Checked before anything is touched, so a missing tool fails in two seconds
# rather than six minutes into a build.

step "Checking prerequisites"

command -v docker  >/dev/null 2>&1 || die "docker is not installed or not on PATH."
command -v kubectl >/dev/null 2>&1 || die "kubectl is not installed or not on PATH."
ok "docker and kubectl found"

kubectl cluster-info >/dev/null 2>&1 \
    || die "kubectl cannot reach a cluster. Check: kubectl config current-context"
ok "cluster reachable ($(kubectl config current-context))"

if [ -z "$REGISTRY" ]; then
    cat >&2 <<MSG
  No registry configured. Add these two lines once, then re-run:

    echo 'export TRACKER_REGISTRY=192.168.1.178:5000' >> ~/.bashrc
    echo 'export TRACKER_APP_HOST=tracker-asset.office.local' >> ~/.bashrc
    source ~/.bashrc

  Use your own address if it differs. Without a registry there is nowhere to
  push the built image, and Kubernetes on another node cannot pull it from the
  Docker daemon on this one.
MSG
    exit 1
fi

docker info >/dev/null 2>&1 || die "docker is installed but the daemon is not responding. Is Docker Desktop running?"
ok "docker daemon running"

# --- namespace --------------------------------------------------------------

step "Preparing namespace ${NAMESPACE}"

if kubectl get namespace "$NAMESPACE" >/dev/null 2>&1; then
    ok "namespace exists"
else
    kubectl create namespace "$NAMESPACE" >/dev/null
    ok "namespace created"
fi

# Context-scoped from here so no later call can reach the wrong cluster.
KN() { kubectl --namespace "$NAMESPACE" "$@"; }

# --- secrets ----------------------------------------------------------------

step "Preparing secrets"

if KN get secret tracker-asset-secrets >/dev/null 2>&1; then
    ok "secret exists (left alone - overwriting it would invalidate every session)"
else
    if [ ! -f k8s/secrets.yaml ]; then
        cp k8s/secrets-template.yaml k8s/secrets.yaml
        cat >&2 <<MSG
Created k8s/secrets.yaml from the template.

  Fill in the two CHANGE_ME values, then run this again:

    db_password: anything other than CHANGE_ME
    app_key:     the output of 'php artisan key:generate --show'

  k8s/secrets.yaml is gitignored, so it cannot be committed.
MSG
        exit 1
    fi

    grep -q CHANGE_ME k8s/secrets.yaml && die "k8s/secrets.yaml still has CHANGE_ME placeholders. Fill them in and run again."

    # No password syncing needed. The database credentials live in
    # tracker-asset-db-secret and every workload reads them from there, so the
    # app and PostgreSQL cannot disagree about the password.
    KN apply -f k8s/secrets.yaml >/dev/null
    ok "secrets created"
fi

# --- configmap --------------------------------------------------------------
step "Applying configuration"

# Generated rather than applied straight from the repo, so the hostname and
# database address live in this script instead of being hand-edited in YAML and
# then forgotten.
KN create configmap tracker-asset-config \
    --from-literal=app_url="http://${APP_HOST}" \
    --from-literal=asset_qr_base_url="http://${APP_HOST}" \
    --from-literal=db_host="${DB_HOST}" \
    --from-literal=db_port="5432" \
    --from-literal=db_database="${DB_NAME}" \
    --dry-run=client -o yaml | KN apply -f - >/dev/null
ok "configmap applied (app_url and asset_qr_base_url = http://${APP_HOST})"

# --- storage ----------------------------------------------------------------

step "Preparing storage"

if KN get pvc tracker-asset-storage >/dev/null 2>&1; then
    ok "volume claim exists"
else
    KN apply -f k8s/pvc-storage.yaml >/dev/null
    ok "volume claim created"
fi

# Pending here is normal, not a fault. The office cluster's local-path class uses
# WaitForFirstConsumer, which means the claim deliberately stays Pending until a
# pod actually needs the volume - the provisioner then creates the hostPath on
# the node that pod lands on. It binds the moment the web pod starts.
#
# What would be a fault is it staying Pending after the pods are running:
#
#     kubectl describe pvc tracker-asset-storage -n ${NAMESPACE}
phase=$(KN get pvc tracker-asset-storage -o jsonpath='{.status.phase}' 2>/dev/null || echo Pending)
if [ "$phase" != "Bound" ]; then
    ok "claim is $phase (expected before a pod uses it; local-path waits for a consumer)"
fi

# --- image ------------------------------------------------------------------

step "Building image"

IMAGE="${REGISTRY}/${IMAGE_NAME}:${IMAGE_TAG}"

# Build output is deliberately not silenced.
#
# It used to be, and that hid the one line that explained a failing build:
# composer's own message naming the package and the platform requirement it could
# not satisfy. All that survived was docker's trailing "returned a non-zero
# code: 2" on stderr, which names neither the package nor the version. A quiet
# build is not worth a build you cannot diagnose.
docker build -t "$IMAGE" -t "${REGISTRY}/${IMAGE_NAME}:latest" . \
    || die "image build failed - the reason is in the output above. Nothing was pushed or applied."

ok "built ${IMAGE}"

step "Pushing image"

# Push progress stays hidden: it is a hundred lines of layer digests that say
# nothing when it works. Failure is not hidden - docker prints that to stderr
# itself, and the message below says which registry refused and what to check.
docker push "$IMAGE" >/dev/null \
    || die "push to ${REGISTRY} failed (is the registry reachable, and are you logged in to it?)"

ok "pushed ${IMAGE}"

# --- workloads --------------------------------------------------------------

step "Applying workloads"

if [ "$USE_BUNDLED_POSTGRES" = "yes" ]; then
    KN apply -f k8s/postgres.yaml >/dev/null
    ok "postgresql"
    printf '  waiting for database'
    for _ in $(seq 1 30); do
        if KN exec statefulset/tracker-asset-postgres -- pg_isready -U "$DB_USER" -d "$DB_NAME" >/dev/null 2>&1; then
            printf '\n'; ok "database accepting connections"; break
        fi
        printf '.'; sleep 2
    done
fi

KN apply -f k8s/redis.yaml               >/dev/null; ok "redis"
KN apply -f k8s/service.yaml             >/dev/null; ok "service"
KN apply -f k8s/deployment-scheduler.yaml >/dev/null; ok "scheduler"
KN apply -f k8s/deployment-web.yaml      >/dev/null; ok "web"

# Point the running deployments at the image just pushed, with `kubectl set
# image` rather than by rewriting the manifest with sed.
#
# The manifests are tracked in git. Rewriting them made every deploy dirty the
# working tree, so the next `git pull` aborted with "local changes would be
# overwritten by merge" - which is what happened on the office server, twice,
# before this was fixed. A deploy script that fights the next git pull is worse
# than no deploy script at all.
#
# This must come after the apply above: the deployments have to exist before
# their image can be set.
KN set image deployment/tracker-asset-web container=php-fpm "${IMAGE}" >/dev/null
KN set image deployment/tracker-asset-scheduler container=scheduler "${IMAGE}" >/dev/null
ok "image ${IMAGE} applied"

step "Applying ingress"
if kubectl get ingressclass 2>/dev/null | grep -q nginx; then
    KN apply -f k8s/ingress.yaml >/dev/null
    ok "ingress (class: nginx)"
else
    warn "No nginx ingress class found - skipped ingress.yaml."
    warn "Expose it yourself pointing at service tracker-asset-web port 80."
fi
# --- migrations -------------------------------------------------------------
# A Job, not `kubectl exec`. An exec needs a running pod to exec into, which is
# circular on a first deploy: the pods crash-loop on a missing schema. A Job
# runs once, and Kubernetes records whether it succeeded.

step "Running database migrations"

KN delete job tracker-asset-migrate --ignore-not-found >/dev/null 2>&1 || true

KN run tracker-asset-migrate \
    --image="$IMAGE" \
    --restart=Never \
    --overrides='{
      "spec": {
        "securityContext": {"runAsNonRoot": true, "runAsUser": 33, "runAsGroup": 33, "fsGroup": 33},
        "containers": [{
          "name": "migrate",
          "image": "'"$IMAGE"'",
          "command": ["php", "artisan", "migrate", "--force"],
          "securityContext": {"allowPrivilegeEscalation": false, "capabilities": {"drop": ["ALL"]}},
          "env": [
            {"name": "APP_ENV", "value": "production"},
            {"name": "APP_DEBUG", "value": "false"},
            {"name": "DB_CONNECTION", "value": "pgsql"},
            {"name": "DB_HOST", "value": "'"$DB_HOST"'"},
            {"name": "DB_PORT", "value": "5432"},
            {"name": "DB_DATABASE", "value": "'"$DB_NAME"'"},
            {"name": "DB_USERNAME", "valueFrom": {"secretKeyRef": {"name": "tracker-asset-db-secret", "key": "db_username"}}},
            {"name": "DB_PASSWORD", "valueFrom": {"secretKeyRef": {"name": "tracker-asset-db-secret", "key": "db_password"}}},
            {"name": "APP_KEY", "valueFrom": {"secretKeyRef": {"name": "tracker-asset-secrets", "key": "app_key"}}},
            {"name": "CACHE_STORE", "value": "redis"},
            {"name": "SESSION_DRIVER", "value": "redis"},
            {"name": "REDIS_HOST", "value": "tracker-asset-redis"}
          ]
        }]
      }
    }' >/dev/null

# Wait, but bounded: a migration blocked on a wrong password would otherwise
# sit here silently.
printf '  waiting'
migrated=no
for _ in $(seq 1 60); do
    phase=$(KN get pod -l job-name=tracker-asset-migrate -o jsonpath='{.items[0].status.phase}' 2>/dev/null || echo Pending)
    case "$phase" in
        Succeeded) printf '\n'; ok "migrations applied"; migrated=yes; break ;;
        Failed)    printf '\n'; KN logs job/tracker-asset-migrate --tail=30; die "migrations failed - output above" ;;
    esac
    printf '.'; sleep 2
done
[ "$migrated" = yes ] || warn "migrations did not finish in time - check: kubectl logs job/tracker-asset-migrate -n ${NAMESPACE}"

# --- wait for ready ---------------------------------------------------------

step "Waiting for web pods"

printf '  waiting'
for _ in $(seq 1 60); do
    ready=$(KN get deploy tracker-asset-web -o jsonpath='{.status.readyReplicas}' 2>/dev/null || echo 0)
    want=$(KN get deploy tracker-asset-web -o jsonpath='{.spec.replicas}' 2>/dev/null || echo 1)
    if [ "${ready:-0}" != "0" ] && [ "${ready:-0}" = "${want:-1}" ]; then
        printf '\n'; ok "web ready (${ready} replica(s))"; break
    fi
    printf '.'; sleep 2
done

# --- agent binary -----------------------------------------------------------
# Not in the image: 150 MB and gitignored. The download route serves it from the
# shared volume, so it has to be placed there once.

step "Installing the agent binary"

if [ -f storage/app/agent/TrackerAgent.exe ]; then
    pod=$(KN get pod -l app=tracker-asset,component=web -o jsonpath='{.items[0].metadata.name}' 2>/dev/null || echo "")
    if [ -n "$pod" ]; then
        kubectl exec -n "$NAMESPACE" "$pod" -- mkdir -p /var/www/html/storage/app/agent >/dev/null 2>&1 || true
        if kubectl cp storage/app/agent/TrackerAgent.exe \
             "${NAMESPACE}/${pod}:/var/www/html/storage/app/agent/TrackerAgent.exe" >/dev/null 2>&1; then
            ok "agent binary copied into the volume"
        else
            warn "copy failed - the download button will 404 until it is retried"
        fi
    else
        warn "no web pod yet - re-run this script once the pods are up"
    fi
else
    warn "storage/app/agent/TrackerAgent.exe not found locally."
    warn "The download button in the UI will 404 until it is copied into the PVC."
fi

# --- summary ----------------------------------------------------------------

step "Done"
cat <<SUMMARY

  Context    : $(kubectl config current-context)
  Namespace  : ${NAMESPACE}
  Image      : ${IMAGE}
  URL        : http://${APP_HOST}

  Check it:
    kubectl get pods -n ${NAMESPACE}
    kubectl port-forward -n ${NAMESPACE} svc/tracker-asset-web 8080:80

  If port-forward works but the hostname does not, add a DNS entry for
  ${APP_HOST} pointing at the ingress address:
    kubectl get ingress tracker-asset -n ${NAMESPACE} \\
      -o jsonpath='{.status.loadBalancer.ingress[0].ip}'

  Re-run ./deploy.sh after any code change. It rebuilds, repushes and reapplies
  without touching data or the secret.

SUMMARY
