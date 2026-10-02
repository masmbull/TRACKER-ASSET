# Deploying to the office Kubernetes cluster

## The short version

```bash
# once, at the top of deploy.sh:
#   REGISTRY=REDACTED_SERVER_IP:5000
#   APP_HOST=tracker-asset.office.local
#   DB_HOST=tracker-asset-postgres

./deploy.sh
```

(The variable names deploy.sh reads are `TRACKER_REGISTRY` and `TRACKER_APP_HOST`,
which is what the one-time `export` lines in its header set; the names above are
the same settings without the prefix.)

That one command checks the prerequisites, creates the namespace, makes you fill
in the secret, creates storage, builds and pushes the image, applies every
manifest, runs the migrations, waits for the pods, copies in the agent binary,
and prints what to do next.

Run it again after any code change — it rebuilds, repushes and reapplies without
touching your data or your secret.

The image is tagged with the short commit hash, not `latest`. That is what makes
a redeploy actually redeploy: `latest` plus the manifests'
`imagePullPolicy: IfNotPresent` changes nothing in the pod template, so no
rollout happens and the old pods keep serving the old code while the script
reports success. Set `IMAGE_TAG` to tag a build by something else.

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
# 1. What storage class is installed, and can it provision at all?
kubectl get storageclass
```

The web Deployment runs one replica on purpose — this is a single-node cluster,
so a second pod cannot be scheduled anywhere else and, with the `ReadWriteOnce`
volume in `pvc-storage.yaml`, a second pod would never start. One pod that always
serves beats two where one is always CrashLooping. If the cluster ever grows a
second node, raise `replicas` in `k8s/deployment-web.yaml` and move the claim to
a `ReadWriteMany` class first, or the second pod hangs in `ContainerCreating`.
`deploy.sh` warns if the claim is not Bound yet; that is normal before a pod
uses it, since the local-path provisioner waits for a consumer.

```bash
# 2. Which ingress controller is installed?
kubectl get ingressclass
```

`ingress.yaml` is written for **Traefik**, which is what k3s ships and what the
office cluster runs. The script applies it only if a `traefik` class exists,
and says what to change if the class is `nginx` or missing. An Ingress naming a
class that does not exist is accepted by the API server and then ignored by
every controller — no route, no error, which is the worst way to be wrong.

`tracker-asset.office.local` must also resolve to the node's address from
wherever you browse. It is a `.local` name, so the office's DNS server has to
serve it (or each machine needs a hosts-file entry). `.local` does not resolve on
the public internet, and the router cannot create the record for you.

## When every pod says ImagePullBackOff

A successful `docker push` says nothing about whether the cluster can pull, which
is why this one wastes an afternoon. The push goes through the Docker daemon,
which was told to trust the registry over plain HTTP:
`insecure-registries` in `/etc/docker/daemon.json`. Kubelet pulls through k3s's
embedded containerd, which reads a different file — `/etc/rancher/k3s/registries.yaml`
— and otherwise assumes `https://<registry>/v2`. `docker pull` on the node
succeeds either way, so the obvious test passes and the pods still do not start.

`k8s/registries.yaml` is that file, ready to copy, with the diagnosis written out
in full. On every node that runs these pods:

```bash
sudo mkdir -p /etc/rancher/k3s
sudo cp k8s/registries.yaml /etc/rancher/k3s/registries.yaml
# edit the registry name inside if it differs from REDACTED_SERVER_IP:5000
sudo systemctl restart k3s
sudo systemctl restart k3s-agent   # workers only; not on a single-node server
```

containerd reads this file once at startup, so the restart is not optional.
`deploy.sh` warns when the file is missing or does not mention your registry.

## Why Redis is part of this

One replica does not make Redis optional, though the usual reason for it does not
apply here. The reason is that the pod is disposable: every `deploy.sh` run ships
a new image and replaces the pod, and anything on that pod's local disk goes with
it — every session logged out in the middle of a working day, on a deploy nobody
thinks of as disruptive. The two-replica argument (a login landing on the wrong
pod) is the same problem with a different cause.

The database driver is not an alternative either — this app has no `sessions`
table, which is worth knowing if you ever read `config/session.php` and see
`database` as the default there.

So sessions, cache and queue all go to Redis, matching `.env.example`. Redis
holds nothing that must survive a restart; the data is in PostgreSQL.

## Why there is an nginx container in the web pod

php-fpm does not speak HTTP. It speaks FastCGI on port 9000, and Traefik has no
FastCGI support at all, so something in the pod has to turn an HTTP request into
a FastCGI request. That is the `nginx` container, running the same server block
the compose file uses (`docker/nginx.conf`), from `nginx-configmap.yaml`.

It is not decoration, and leaving it out is not survivable:

- The Service's `targetPort` and the Ingress both point at the named port `http`.
  Before this container existed, that name belonged to php-fpm's 9000 — so the
  Service was proxying HTTP into a FastCGI socket, and an Ingress could never
  have worked.
- The readiness and liveness probes were `httpGet /up` against that same port.
  php-fpm answers an HTTP request with a protocol error, so the startup probe
  failed its thirty attempts and Kubernetes restarted the container forever. The
  pod could never become Ready, whatever else was correct. The probes now target
  nginx, which serves `/up` by proxying to php-fpm — so a passing probe still
  proves PHP and the application are working.

nginx also serves the compiled CSS and JS directly from disk, which is why
`deployment-web.yaml` has a `seed-public` init container: the nginx image has no
application source in it, and a container's filesystem is its own. The init
container copies `public/` out of the application image into an emptyDir, and
nginx mounts that read-only. Miss it and every `/build/*.css` request 404s while
every PHP page works — an unstyled site and a clean log.

A second init container, `seed-storage`, does the mirror-image job for the
storage claim. On a first deploy that claim is empty, and mounting an empty
directory over `/var/www/html/storage` hides the subdirectories the image
created there — `logs`, `framework/views`, and the rest. Laravel recreates some
of them and not others, and the ones it does not are 500s that quote a path
which exists in every image built here and in no container running it.

Upload limits live in that server block too. The Ingress used to carry them as
`nginx.ingress.kubernetes.io/proxy-body-size: 64m`, which Traefik would ignore;
it imposes no request body limit at all, so the 64 MB limit and the 300-second
FastCGI timeouts are now settings on the component that actually receives the
upload — nginx's own `client_max_body_size` and `fastcgi_read_timeout`.

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
| `deployment-web.yaml` | nginx in front of php-fpm, one replica |
| `nginx-configmap.yaml` | the nginx server block, mounted over `/etc/nginx/conf.d` in that pod |
| `deployment-scheduler.yaml` | `schedule:work`. Not optional — `agent:resolve-stale-commands` runs here every minute |
| `redis.yaml` | sessions, cache, queue |
| `service.yaml` / `ingress.yaml` | ClusterIP, and the Traefik rule for `tracker-asset.office.local` |
| `pvc-storage.yaml` | uploads, PDFs, agent binary |
| `configmap.yaml` | non-secret settings; `deploy.sh` generates the live one |
| `secrets-template.yaml` | committed template, no values |
| `registries.yaml` | **not** a manifest — the node's containerd config; see the ImagePullBackOff section |
| `../deploy.sh` | the one command |

The apply order is not alphabetical and is not arbitrary: `nginx-configmap.yaml`
has to be applied before `deployment-web.yaml`, because a pod whose ConfigMap
volume does not exist never starts at all. `deploy.sh` does this in the right
order; a hand-run `kubectl apply` has to as well.