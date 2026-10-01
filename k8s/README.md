# Deploying to the office Kubernetes cluster

## The short version

```bash
# once, at the top of deploy.sh:
#   REGISTRY=registry.office.local
#   APP_HOST=tracker-asset.office.local
#   DB_HOST=postgres.tracker.svc.cluster.local

./deploy.sh
```

That one command checks the prerequisites, creates the namespace, makes you fill
in the secret, creates storage, builds and pushes the image, applies every
manifest, runs the migrations, waits for the pods, copies in the agent binary,
and prints what to do next.

Run it again after any code change — it rebuilds, repushes and reapplies without
touching your data or your secret.

The first run stops once, on purpose, so you can fill in two real values:
the database password and the app key. It creates `k8s/secrets.yaml` from the
template and tells you so, then exits. Fill it in and run again.

## What you need on the machine you run it from

docker, kubectl with its context already pointed at the office cluster, and a
registry you can push to.

## Before the first run

Two facts about your cluster decide whether this works as written. Neither can
be checked from this repository.

```bash
# 1. Does your storage class support two pods writing at once?
kubectl get storageclass
```

The web Deployment runs 2 replicas sharing one volume, which needs
`ReadWriteMany`. If only `ReadWriteOnce` is available, the second pod stays in
`ContainerCreating` and a node drain takes the site down rather than failing
over. In that case set `replicas: 1` in `k8s/deployment-web.yaml` — a working
single replica beats a pair that deadlock. `deploy.sh` warns if the claim is
not Bound.

```bash
# 2. Which ingress controller is installed?
kubectl get ingressclass
```

`ingress.yaml` assumes nginx. The script only applies it if an nginx class
exists; otherwise it tells you to point your own controller at the
`tracker-asset-web` service.

## Why Redis is part of this

The web Deployment runs two replicas, so anything stored on local disk is
per-pod. Sessions on disk means a login that works on pod A is unknown to pod
B, and roughly half of all requests land on the wrong pod. The database driver
is not available either — this app has no `sessions` table, which is worth
knowing if you ever read `config/session.php` and see `database` as the default.

So sessions, cache and queue all go to Redis, matching `.env.example`. Redis
holds nothing that must survive a restart; the data is in PostgreSQL.

## Things `deploy.sh` cannot do for you

**Database migrations run as a Job, not an `exec`.** An `exec` needs a running
pod, which is circular on a first deploy — the pods crash-loop on a missing
schema. The Job runs once and Kubernetes records the result.

**The agent binary is not in the image.** It is 150 MB and gitignored. The
download route serves it from the shared volume, so the script copies it in
from `storage/app/agent/TrackerAgent.exe` if that file exists. If it does not,
the script warns and the download button 404s until you build it (see
`agent/README.md`) and re-run.

**`ASSET_QR_BASE_URL` is set to the same hostname as `APP_URL` by default.** It
must be an address a phone can reach — a phone on office wifi cannot resolve
`tracker-asset-web.default.svc.cluster.local`, so pointing QR labels at an
internal service name produces labels that are dead to the handheld scanner.

## Files here

| File | Purpose |
|---|---|
| `deployment-web.yaml` | php-fpm, 2 replicas |
| `deployment-scheduler.yaml` | `schedule:work`. Not optional — `agent:resolve-stale-commands` runs here every minute |
| `redis.yaml` | sessions, cache, queue |
| `service.yaml` / `ingress.yaml` | ClusterIP and the nginx rule |
| `pvc-storage.yaml` | uploads, PDFs, agent binary |
| `configmap.yaml` | non-secret settings; `deploy.sh` generates the live one |
| `secrets-template.yaml` | committed template, no values |
| `../deploy.sh` | the one command |