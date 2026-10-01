# Deploying to the office Kubernetes cluster

The app is a standard Laravel container: PHP-FPM behind nginx, PostgreSQL, a
scheduler, and one shared volume for uploads. Seven manifests live in `k8s/`.

**Read this first.** Two things in here are filled with placeholders and will
not work until you change them: the `APP_KEY` and the database password in
`secrets.yaml`, and `db_host` in `configmap.yaml`.

## Before you start, on the cluster

Two questions decide whether the manifests apply cleanly at all:

```bash
# 1. Does your storage class support two pods writing at once?
kubectl get storageclass
```

The web Deployment runs 2 replicas sharing one PVC. That needs
`ReadWriteMany`. If the only classes available are `ReadWriteOnce`, the second
pod stays in `ContainerCreating` forever and a node drain takes the site down
instead of failing over. In that case set `replicas: 1` in
`deployment-web.yaml` — it is a real trade, but a working single replica beats
a dead pair.

```bash
# 2. Which ingress controller is installed?
kubectl get ingressclass
```

`ingress.yaml` assumes nginx. If it is Traefik or an external LB, ignore that
file and point whatever you use at the `tracker-asset-web` Service.

## 1. Build and push the image

There is no registry configured yet, so replace `REGISTRY` in
`deployment-web.yaml` and both Deployments with your own. Needs the .NET 8 SDK
only for the agent binary, not for the app.

```bash
# Build locally, tag for your registry
docker build -t registry.office.local/tracker-asset:1.0.0 .

# Push
docker push registry.office.local/tracker-asset:1.0.0

# Then set that tag in both deployment files (image: ...), not :latest,
# so a rollback means changing a tag rather than rebuilding.
```

## 2. Create the secret

```bash
# Generate a real key on any machine with the app checked out
php artisan key:generate --show
```

Copy the template, fill in the password and key, and apply **the copy** — the
template itself is named differently and the real one is ignored, so a filled-in
file can never be committed by accident:

```bash
cp k8s/secrets-template.yaml k8s/secrets.yaml
$EDITOR k8s/secrets.yaml          # replace both CHANGE_ME values
kubectl apply -f k8s/secrets.yaml
```

`k8s/secrets.yaml` is in `.gitignore`. Do not rename it to something tracked.

## 3. Apply the rest, in order

```bash
kubectl apply -f k8s/configmap.yaml
kubectl apply -f k8s/pvc-storage.yaml
kubectl apply -f k8s/service.yaml
kubectl apply -f k8s/deployment-web.yaml
kubectl apply -f k8s/deployment-scheduler.yaml
kubectl apply -f k8s/ingress.yaml
```

Watch it come up rather than assuming:

```bash
kubectl get pods -w
```

## 4. Run the migrations

Migrations do not run on their own. Without this the app boots and every page
that touches the database 500s.

```bash
kubectl exec deploy/tracker-asset-web -- php artisan migrate --force
```

## 5. Point DNS (or use a port-forward to check first)

```bash
# Quick check before touching DNS
kubectl port-forward svc/tracker-asset-web 8080:80
# open http://localhost:8080
```

Then add `tracker-asset.office.local` to whatever serves office DNS, pointing
at the ingress address:

```bash
kubectl get ingress tracker-asset -o jsonpath='{.status.loadBalancer.ingress[0].ip}'
```

## The two things most likely to bite

**`ASSET_QR_BASE_URL` must be an address the phones can reach.** A phone on
office wifi cannot resolve `tracker-asset-web.default.svc.cluster.local`, so
setting it to an internal service name produces QR labels that are dead to the
handheld scanner. Use the same hostname as `app_url`.

**The agent binary is not in the image.** It is ~150 MB and deliberately
gitignored. The download route (`/agent/download`) serves it from the shared
volume, so deploy it once into the PVC:

```bash
kubectl cp agent/TrackerAgent/../../storage/app/agent/TrackerAgent.exe \
  $(kubectl get pod -l app=tracker-asset,component=web -o jsonpath='{.items[0].metadata.name}'):\
  /var/www/html/storage/app/agent/TrackerAgent.exe
```

Until that file exists, the "Download agent" button in the UI 404s.