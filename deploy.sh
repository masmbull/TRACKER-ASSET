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
#     echo 'export TRACKER_REGISTRY=REDACTED_SERVER_IP:5000' >> ~/.bashrc
#     echo 'export TRACKER_APP_HOST=tracker-asset.office.local' >> ~/.bashrc
#     source ~/.bashrc
#
# Running once without those set prints the exact commands to add.
REGISTRY="${REGISTRY:-${TRACKER_REGISTRY:-}}"
IMAGE_NAME="${IMAGE_NAME:-tracker-asset}"
# Tagged by commit, not `latest`.
#
# `latest` plus the manifests' `imagePullPolicy: IfNotPresent` is a silent
# no-op on the second deploy: the image string is identical, so the pod
# template does not change, so no rollout happens at all and the old pods keep
# serving the old code - while the script reports success. Even when a rollout
# is forced by something else, the node still has `latest` cached under that
# name and skips the pull, so the new pods run the old image.
#
# A commit tag makes the image string change exactly when the code does, which
# is what makes the rollout happen. Set IMAGE_TAG in the environment to
# override - a release name, a date, anything - and it is used as given.
if [ -z "${IMAGE_TAG:-}" ]; then
    if git rev-parse --short HEAD >/dev/null 2>&1; then
        IMAGE_TAG="$(git rev-parse --short HEAD)"
    else
        # Not a git checkout. Nothing to tag by, and a deploy that dies here
        # would be worse than one that overwrites a tag on a directory someone
        # unpacked from a zip.
        IMAGE_TAG=latest
    fi
fi
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

    echo 'export TRACKER_REGISTRY=REDACTED_SERVER_IP:5000' >> ~/.bashrc
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

# --- can the node pull what this script is about to push? --------------------
#
# A successful push proves nothing about the pull that follows, because they are
# done by two different clients. This script pushes through the Docker daemon,
# which has been told to trust the registry over plain HTTP (insecure-registries
# in /etc/docker/daemon.json). Kubelet pulls through k3s's embedded containerd,
# which reads only /etc/rancher/k3s/registries.yaml and otherwise assumes https.
#
# So the failure looks like this: the build succeeds, the push succeeds, every
# manifest applies cleanly, and every pod sits in ImagePullBackOff - with
# `docker pull` on the same machine working fine.
#
# Checked here rather than after the build, and it warns rather than dies: this
# script may be running somewhere that is not a node, and every node needs the
# file, so a guess at the wrong answer must not be able to stop a deploy.
if [ -d /etc/rancher/k3s ]; then
    if grep -qs -- "$REGISTRY" /etc/rancher/k3s/registries.yaml; then
        ok "containerd is configured for ${REGISTRY}"
    else
        warn "/etc/rancher/k3s/registries.yaml does not mention ${REGISTRY}."
        warn "If that registry is plain HTTP, every pod will fail to pull and"
        warn "stay in ImagePullBackOff. To fix it on this node:"
        warn "  sudo cp k8s/registries.yaml /etc/rancher/k3s/registries.yaml"
        warn "  edit the registry name inside if it differs, then:"
        warn "  sudo systemctl restart k3s"
        warn "Why: see the header of k8s/registries.yaml."
    fi
fi

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
# Before the Deployment that mounts it, not after. A Pod whose ConfigMap volume
# does not exist is never scheduled - it sits in ContainerCreating - so applying
# these two the other way round turns every first deploy into a two-minute wait
# for a pod that was never going to start.
KN apply -f k8s/nginx-configmap.yaml     >/dev/null; ok "nginx config"
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
# The value is <container>=<image>, and each name on the left is a container
# name as written in the manifest: php-fpm in deployment-web.yaml, scheduler in
# deployment-scheduler.yaml.
#
# The left side is a container name, not a literal "container". Writing
# container=php-fpm made kubectl take `container` as a container that does not
# exist and read ${IMAGE} as a second resource to act on. That fails with "all
# resources must be specified before image changes" and, under `set -e`, ended
# the deploy right there - before the ingress, before the migrations, before the
# pods.
#
# seed-public is that web Deployment's init container, and it has to be set in
# the same call. It runs the application image too, and the manifests carry the
# placeholder image name `REGISTRY/tracker-asset:latest` - apply alone would
# leave the init container pointing at an image called literally "REGISTRY", and
# the pod would never get past Init:ErrImagePull. kubectl has accepted init
# container names here since 1.14.
KN set image deployment/tracker-asset-web php-fpm="${IMAGE}" seed-public="${IMAGE}" >/dev/null
KN set image deployment/tracker-asset-scheduler scheduler="${IMAGE}" >/dev/null
ok "image ${IMAGE} applied"

step "Applying ingress"

# k3s ships Traefik, and on the office cluster it is the only class installed -
# confirmed with `kubectl get ingressclass`, not assumed. k8s/ingress.yaml names
# it.
#
# The check is by name rather than by grepping the whole class list, because
# "an ingress class is installed" is not the question. The question is whether
# the one the manifest names is here. An Ingress whose ingressClassName does not
# exist is accepted by the API server and then ignored by every controller: the
# object exists, no route exists, and nothing anywhere reports it. That is the
# silent half of what went wrong on the first run of this script.
if kubectl get ingressclass traefik >/dev/null 2>&1; then
    KN apply -f k8s/ingress.yaml >/dev/null
    ok "ingress (class: traefik) for http://${APP_HOST}"
elif kubectl get ingressclass nginx >/dev/null 2>&1; then
    warn "this cluster's ingress class is nginx, but k8s/ingress.yaml names traefik."
    warn "Skipped. Set ingressClassName in that file to nginx, drop the traefik"
    warn "annotation, and re-run."
else
    warn "no traefik or nginx ingress class found - skipped ingress.yaml."
    warn "Expose it yourself pointing at service tracker-asset-web port 80."
fi
# --- migrations -------------------------------------------------------------
# A Job, not `kubectl exec`. An exec needs a running pod to exec into, which is
# circular on a first deploy: the pods crash-loop on a missing schema. A Job
# runs once, and Kubernetes records whether it succeeded.

step "Running database migrations"

KN delete job tracker-asset-migrate --ignore-not-found >/dev/null 2>&1 || true

# A real Job, not `kubectl run`.
#
# `kubectl run --restart=Never` creates a bare Pod. A Pod made that way carries
# no job-name label, so the wait loop below - which selects on job-name= - never
# matches it and reports "migrations did not finish in time" even when every
# migration applied; `kubectl logs job/...` has no object to read when one
# really does fail; and because the line above deletes a *job*, a re-run finds
# the old Pod still sitting there and dies on "pods tracker-asset-migrate
# already exists". Everything below assumes a Job exists, so the thing created
# here has to be one. Applying a manifest does that on any kubectl version,
# where the run generator flags have changed across releases.
KN apply -f - >/dev/null <<MIGRATE_JOB
apiVersion: batch/v1
kind: Job
metadata:
  name: tracker-asset-migrate
  labels:
    app: tracker-asset
    component: migrate
spec:
  # Retried twice, then left in place long enough to read. A migration that
  # fails for a real reason has to stay inspectable, and a single attempt that
  # dies on a transient connection error would be a dead end. The TTL clears it
  # well before the next deploy needs the name back.
  backoffLimit: 2
  ttlSecondsAfterFinished: 900
  template:
    metadata:
      labels:
        app: tracker-asset
        component: migrate
    spec:
      restartPolicy: Never
      securityContext:
        runAsNonRoot: true
        runAsUser: 33
        runAsGroup: 33
        fsGroup: 33
      containers:
        - name: migrate
          image: "${IMAGE}"
          command: ["php", "artisan", "migrate", "--force"]
          securityContext:
            allowPrivilegeEscalation: false
            capabilities:
              drop: ["ALL"]
          env:
            - name: APP_ENV
              value: production
            - name: APP_DEBUG
              value: "false"
            - name: DB_CONNECTION
              value: pgsql
            - name: DB_HOST
              value: "${DB_HOST}"
            - name: DB_PORT
              value: "5432"
            - name: DB_DATABASE
              value: "${DB_NAME}"
            - name: DB_USERNAME
              valueFrom:
                secretKeyRef:
                  name: tracker-asset-db-secret
                  key: db_username
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: tracker-asset-db-secret
                  key: db_password
            - name: APP_KEY
              valueFrom:
                secretKeyRef:
                  name: tracker-asset-secrets
                  key: app_key
            - name: CACHE_STORE
              value: redis
            - name: SESSION_DRIVER
              value: redis
            - name: REDIS_HOST
              value: tracker-asset-redis
MIGRATE_JOB

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
webready=no
for _ in $(seq 1 60); do
    ready=$(KN get deploy tracker-asset-web -o jsonpath='{.status.readyReplicas}' 2>/dev/null || echo 0)
    want=$(KN get deploy tracker-asset-web -o jsonpath='{.spec.replicas}' 2>/dev/null || echo 1)
    if [ "${ready:-0}" != "0" ] && [ "${ready:-0}" = "${want:-1}" ]; then
        printf '\n'; ok "web ready (${ready} replica(s))"; webready=yes; break
    fi
    printf '.'; sleep 2
done

# Say so when it is not up, rather than printing dots and then announcing "Done".
# This loop had no else branch at all, which is how a deploy that ended with two
# pods in ImagePullBackOff still finished with a green "Done", a URL, and no
# hint that the site was not being served - the one line that mattered was the
# one line that was never printed.
if [ "$webready" != yes ]; then
    printf '\n'
    warn "web pods are not ready - the URL below will not answer yet."
    KN get pods -l app=tracker-asset,component=web 2>/dev/null || true
    warn "Reason: kubectl describe pod -n ${NAMESPACE} -l app=tracker-asset,component=web"
    warn "  Events names the cause: ImagePullBackOff is the registry,"
    warn "  CrashLoopBackOff is the container itself."
fi

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
