# Critter 2.0 - Deployment Manual

This guide covers every supported way to deploy Critter 2.0 and explains the
**safe, self-healing migration model** that backs all of them.

- [⚠ The Messenger worker is mandatory](#-the-messenger-worker-is-mandatory)
- [How migrations stay safe](#how-migrations-stay-safe)
- [The install wizard & maintenance page](#the-install-wizard--maintenance-page)
- [Environment variables](#environment-variables)
- [1. Direct web server (no containers)](#1-direct-web-server-no-containers)
- [2. Docker (single image)](#2-docker-single-image)
- [3. Docker Compose](#3-docker-compose)
- [4. Kubernetes / ArgoCD](#4-kubernetes--argocd)
- [Development with containers](#development-with-containers)
- [Creating an admin from the console](#creating-an-admin-from-the-console)
- [Troubleshooting](#troubleshooting)

---

## ⚠ The Messenger worker is mandatory

**Every deployment must run a Messenger worker.** It is not an optimisation and not optional.

```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=192M
```

Four subsystems dispatch their work to the `async` transport and are written **only** by this worker:

| Routed to `async`             | What breaks without a worker                            |
| ----------------------------- | ------------------------------------------------------- |
| `App\Audit\AuditRecord`       | **The audit trail is never written.** Legally critical. |
| `App\Gdpr\GenerateDataExport` | GDPR data-portability exports never complete.           |
| `SendEmailMessage`            | No outbound email at all.                               |
| `ChatMessage` / `SmsMessage`  | No notifications.                                       |

**The failure is silent.** Without the worker the application serves normally, every action still
dispatches its message, users see no error - and the messages simply accumulate in the
`messenger_messages` table, unwritten. `audit_events` stays empty and nothing surfaces it.

Nothing is _lost_ while the worker is down. The transport is `doctrine://default`, a durable
PostgreSQL-backed buffer: messages persist across restarts, crashes and redeploys, and a worker that
comes back resumes exactly where it stopped. A backlog is a delay, not a data loss - provided a
worker eventually runs.

The worker is defined for you in `compose.prod.yaml`, `compose.dev.yaml` and `deploy/k8s/app.yaml`
(a separate `critter-worker` Deployment). If you deploy some other way - bare metal, systemd,
supervisor - **you must run it yourself**, with a restart-on-exit supervisor. `--time-limit` /
`--memory-limit` make the process exit periodically on purpose; the supervisor restarting it is how a
long-lived PHP worker is recycled.

### Monitoring (do not skip this)

A stalled worker is invisible from the outside: the application keeps serving normally. Monitor the
queue explicitly.

```bash
php bin/console app:audit:health     # exit 0 = healthy, 1 = the trail is not being written
```

It reports the backlog, the age of the oldest unconsumed message, anything in the failed transport,
and the number of rows actually in `audit_events`. Run it on a schedule and **alert on a non-zero
exit** - the k8s manifest ships a `critter-audit-health` CronJob that does exactly this. Thresholds
are tunable with `--max-backlog` and `--max-age`.

Recovering a backlog is just running the worker; it drains what is waiting. Messages that failed
repeatedly land in the `failed` transport:

```bash
php bin/console messenger:failed:show
php bin/console messenger:failed:retry
```

---

## ⚠ The Mercure hub, and why it must never be scaled

Live updates - notifications, chat, help calls, shift capacity - are delivered by a
[Mercure](https://mercure.rocks) hub over server-sent events. It is defined for you in
`compose.prod.yaml`, `compose.dev.yaml` and `deploy/k8s/mercure.yaml`.

**Run exactly one hub instance.** The open-source hub does not cluster. With two behind a load
balancer, a browser connected to one never receives what the application published to the other, and
nothing anywhere reports an error: it presents as "live updates work for some people and not others",
intermittently. The Kubernetes Deployment is pinned to `replicas: 1` with the `Recreate` strategy so
that a rollout never briefly runs two. Do not raise it. The application itself scales freely; only
the hub is constrained.

The hub is **not** exposed directly. The web server proxies `/.well-known/mercure` to it so that it
shares the application's origin, which is what allows the subscriber token to be a `SameSite=Strict`
cookie instead of a cross-site one. If you deploy some other way, reproduce that proxy rule -
including `proxy_buffering off` and a long `proxy_read_timeout`, since an SSE response never ends and
a buffered or timed-out one delivers nothing.

Four environment variables configure it:

| Variable                        | Purpose                                                          |
| ------------------------------- | ---------------------------------------------------------------- |
| `MERCURE_URL`                   | Where the app publishes (internal network).                      |
| `MERCURE_PUBLIC_URL`            | Where the browser subscribes; same origin as the app.            |
| `MERCURE_JWT_SECRET`            | Signs the tokens the **app** publishes with.                     |
| `MERCURE_SUBSCRIBER_JWT_SECRET` | Signs the tokens **browsers** subscribe with.                    |

The last two must differ, and both must be at least 32 characters (the minimum key length for
HMAC-SHA256). Keeping them separate means a subscriber token - which is handed to every user - can
never be used to publish, whatever else goes wrong. Set the same pair on the hub container
(`MERCURE_PUBLISHER_JWT_KEY` / `MERCURE_SUBSCRIBER_JWT_KEY`) and on the application.

**A hub outage is not an outage.** Every live region falls back to slow polling when the stream will
not stay connected, and the application never fails a request because publishing failed. The symptom
is updates arriving late rather than instantly.

---

## How migrations stay safe

Past deployments failed when a pod/container was killed mid-migration and left
the schema in a half-applied state, or when several replicas tried to migrate at
once. Critter 2.0 removes those failure modes:

| Mechanism                                                   | What it guarantees                                                                                                                                                                      |
| ----------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Doctrine-native status**                                  | "Is a migration needed?" is always recomputed from the database vs. the shipped migration classes - there is **no marker file** that can drift out of sync after a crash.               |
| **PostgreSQL advisory lock** (`app:migrate`)                | Only one process migrates at a time. The lock is held on the DB _session_, so if the migrating pod dies, PostgreSQL **releases it automatically** - no stale lock, no manual `--force`. |
| **All-or-nothing transaction** (`doctrine_migrations.yaml`) | An interrupted migration **rolls back as one unit**. PostgreSQL has transactional DDL, so the schema is never left half-migrated.                                                       |
| **Idempotent + retried**                                    | Replicas that find nothing pending exit cleanly. Startup loops/initContainers **retry until success**, which also rides out "database not ready yet" races.                             |

The single entrypoint for all of this is the console command:

```bash
php bin/console app:migrate          # waits for the lock, migrates all-or-nothing, idempotent
php bin/console app:migrate --no-wait # exit immediately if another migration is already running
```

A `/health` endpoint reports readiness for orchestrators:

```json
GET /health  → 200 {"status":"ok","database":"up","migrationsPending":0}
             → 503 {"status":"unavailable","database":"down","migrationsPending":null}
```

## The install wizard & maintenance page

- While a migration is **pending or the database is unreachable**, the whole
  site is sealed behind a **maintenance page** (HTTP 503). `/api/*` returns a
  JSON 503 instead of HTML. Only `/admin/install` and `/health` stay reachable.
- **`/admin/install`** is a guided wizard (welcome → system checks → database &
  migration → create admin → optional config → privacy notice → finish). The
  privacy step captures the essentials for the GDPR notice (event name, data
  controller, contact email, retention period); the full notice body keeps its
  shipped default and is edited later at _Manage → Privacy notice_. It is protected by the
  **`INSTALL_PASSWORD`** environment variable, _not_ by a login (it must work
  before any user exists). Leave `INSTALL_PASSWORD` empty to disable the wizard
  entirely and manage setup from the console.
- When there is **nothing to do** (schema current, an admin exists),
  `/admin/install` simply redirects back to the site - it is invisible in normal
  operation.

## Environment variables

| Variable                  | Required | Purpose                                                                                                                                                                                                                   |
| ------------------------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_ENV`                 | yes      | `prod` for production, `dev` for development.                                                                                                                                                                             |
| `APP_SECRET`              | yes      | Symfony secret. Generate: `php -r 'echo bin2hex(random_bytes(16));'`.                                                                                                                                                     |
| `DATABASE_URL`            | yes      | `postgresql://user:pass@host:5432/db?serverVersion=16&charset=utf8`.                                                                                                                                                      |
| `APP_ENCRYPTION_KEY`      | yes      | Encrypts secrets at rest. Generate: `php bin/console app:encryption:generate-key`. Losing it makes encrypted data unrecoverable.                                                                                          |
| `INSTALL_PASSWORD`        | no       | Unlocks `/admin/install`. Empty = wizard disabled.                                                                                                                                                                        |
| `BOT_API_TOKEN`           | no       | Shared service token the Telegram bot presents on `/api/bot`. Empty = the bot surface refuses every request. Generate: `php -r 'echo bin2hex(random_bytes(32));'`. Give the same value to the bot as its `VMS_API_TOKEN`. |
| `RUN_MIGRATIONS_ON_START` | no       | `1` (container default) auto-migrates on start; `0` to manage migrations yourself.                                                                                                                                        |
| `MAILER_DSN`              | no       | e.g. `smtp://…`; defaults to discarding mail.                                                                                                                                                                             |
| `TRUSTED_PROXIES`         | no       | Behind a TLS-terminating reverse proxy. Trusts the proxy's `X-Forwarded-*` so generated URLs use `https`. `private_ranges` (default) or a comma-separated IP/CIDR list. See "HTTPS behind a reverse proxy" below.         |
| `PUBLIC_SYNC_DIR`         | no       | Container-internal: where the entrypoint publishes `public/` for an nginx sidecar.                                                                                                                                        |
| `UPLOAD_STORAGE_DSN`      | no       | Where user uploads live. `local://var/uploads` (default) or `s3://bucket?region=…`.                                                                                                                                       |
| `EXPORT_STORAGE_DSN`      | no       | Where export archives live. `local://var/exports` (default) or `s3://bucket?region=…`. **Read the section below before deploying.**                                                                                       |
| `MERCURE_URL`             | yes      | Where the app publishes live updates, on the internal network. See "The Mercure hub" above.                                                                                                                               |
| `MERCURE_PUBLIC_URL`      | yes      | Where the browser subscribes. Must be on the app's own origin, normally `/.well-known/mercure`.                                                                                                                           |
| `MERCURE_JWT_SECRET`      | yes      | Signs the tokens the app publishes with. At least 32 characters.                                                                                                                                                          |
| `MERCURE_SUBSCRIBER_JWT_SECRET` | yes | Signs the tokens browsers subscribe with. At least 32 characters, and **must differ** from the publisher secret.                                                                                                     |

---

## HTTPS behind a reverse proxy (required for SSO)

When TLS is terminated at a reverse proxy (nginx, traefik, a Kubernetes ingress) the app is reached
over plain HTTP internally. Left uncorrected, every absolute URL it generates carries the `http`
scheme it sees - including the **OIDC SSO `redirect_uri`**. The identity provider then rejects the
login, because that value must match the `https://…` redirect URI registered for the client
byte-for-byte. The redirect URI shown on `/admin/sso` (which you copy into the provider) is affected
the same way.

The app already derives scheme and host from the incoming request; it only needs to trust the proxy's
`X-Forwarded-Proto` header to see the real `https`. Set **`TRUSTED_PROXIES`** (prod only):

- `private_ranges` (the shipped default) trusts any RFC1918/loopback upstream - correct when the
  container or pod is only reachable through the proxy.
- Narrow it to the proxy's actual address/CIDR if the app is otherwise directly reachable, so a
  client on the same private network cannot spoof the forwarded scheme.

After setting it, confirm `/admin/sso` shows an `https://…` redirect URI before registering it.

## ⚠ Export storage must be shared by every process

Two features write ZIP archives: the **audit legal export** (a signed package, kept 30 days) and the
**GDPR data export** (a complete copy of one user's personal data, downloadable for 24 hours).

They are not written and read by the same process. The GDPR archive is built by the **messenger
worker**; the download is served by a **web** request. If `EXPORT_STORAGE_DSN` points at a directory
that only one of them can see, the export is built successfully, the user is emailed a link, and the
download then fails - because the file is on another container's disk.

| Deployment                 | What to use                                                                                                            |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Single host, no containers | `local://var/exports` - one filesystem, nothing to do.                                                                 |
| Docker Compose             | `local://var/exports` - `compose.prod.yaml` mounts the `export_data` volume into the app, worker and purge containers. |
| **Kubernetes**             | **`s3://…` - required.** Pods share no filesystem; a `local://` DSN loses every archive at the next restart.           |
| More than one app replica  | `s3://…` - required, for the same reason.                                                                              |

Archives are private. They are only ever served through an authorization-checked controller, never
from a public bucket URL - do not make the bucket public.

## Retention: schedule the purge

Export archives must not accumulate. `app:gdpr:purge-exports` deletes GDPR archives once their
24-hour window closes (data minimisation - the record is kept, the personal data is not), and
`app:audit:purge-exports` deletes audit packages past their retention window. Both are idempotent, so
a missed run catches up on the next one.

`compose.prod.yaml` ships a `purge` container that runs both hourly; `deploy/k8s/app.yaml` ships the
equivalent `critter-purge-exports` CronJob. On a bare-metal install, add them to cron:

```cron
17 * * * *  cd /srv/critter && php bin/console app:gdpr:purge-exports && php bin/console app:audit:purge-exports
```

---

## 1. Direct web server (no containers)

For a classic PHP-FPM + nginx/Apache host. **Migrations are run by hand from the
console here** (per policy), so the deployer stays in control.

```bash
# 1. Install dependencies and build assets
composer install --no-dev --optimize-autoloader
php bin/console importmap:install
php bin/console asset-map:compile

# 2. Configure the environment (committed defaults live in .env; put secrets in
#    .env.local or real environment variables)
#    APP_ENV=prod, APP_SECRET=…, DATABASE_URL=…

# 3. Run migrations MANUALLY
php bin/console app:migrate

# 4. Create the first administrator (see "Creating an admin" below)
php bin/console app:install            # or: app:install --admin-username=… --admin-password=…
```

Point the web server document root at `public/` (front controller
`public/index.php`). Ensure `var/` is writable by the web user. Re-run
`app:migrate` after each code update that ships new migrations.

> You can still use the web wizard on a direct deployment: set `INSTALL_PASSWORD`
> and browse to `/admin/install`. The "manual console" rule above is the default
> policy, not a technical limitation.

---

## 2. Docker (single image)

Build the production image (PHP-FPM):

```bash
docker build --target prod -t critter:prod .
```

Run it against an existing PostgreSQL. The entrypoint auto-migrates on start
(`RUN_MIGRATIONS_ON_START=1` by default), retrying until the database is ready:

```bash
docker run -d --name critter \
  -e APP_ENV=prod \
  -e APP_SECRET=$(php -r 'echo bin2hex(random_bytes(16));') \
  -e DATABASE_URL="postgresql://app:secret@db-host:5432/app?serverVersion=16&charset=utf8" \
  -e INSTALL_PASSWORD="" \
  -p 9000:9000 \
  critter:prod
```

The image serves PHP-FPM on port 9000; put nginx (see `docker/nginx/default.conf`)
in front of it, or use Docker Compose (next section) which wires nginx for you.

---

## 3. Docker Compose

A complete stack (PostgreSQL + app + nginx) is provided in `compose.prod.yaml`.

```bash
cp docker/.env.prod.example docker/.env.prod
# edit docker/.env.prod: set POSTGRES_PASSWORD, APP_SECRET, DATABASE_URL,
# and (optionally) INSTALL_PASSWORD

docker compose -f compose.prod.yaml up -d --build
```

What happens on `up`:

1. PostgreSQL starts; Compose waits for its healthcheck.
2. The app container's entrypoint runs `app:migrate` (advisory-locked,
   all-or-nothing, retried) and publishes compiled assets to the nginx volume.
3. nginx serves the app on `http://localhost:${HTTP_PORT:-8080}`.

Then create the first admin - either set `INSTALL_PASSWORD` and use the wizard at
`/admin/install`, or run it from the console:

```bash
docker compose -f compose.prod.yaml exec app php bin/console app:install
```

It is safe to `restart`, scale, or redeploy: migrations re-check and self-heal on
every start.

---

## 4. Kubernetes / ArgoCD

Manifests live in `deploy/k8s/` (kustomize) and `deploy/argocd/`.

```text
deploy/k8s/
  namespace.yaml      # critter namespace
  secret.example.yaml # template - provide the real Secret out-of-band
  configmap.yaml      # nginx sidecar config (php-fpm over 127.0.0.1)
  postgres.yaml       # optional in-cluster PostgreSQL (use a managed DB in prod)
  app.yaml            # Deployment (initContainer migrate + app + nginx) + Service
  ingress.yaml        # TLS ingress
  kustomization.yaml
deploy/argocd/
  application.yaml    # ArgoCD Application (auto-sync, self-heal)
```

Migration is run by an **initContainer** (`php bin/console app:migrate`):

- It runs to completion before the app serves. With `replicas > 1`, the advisory
  lock means exactly one pod migrates and the others no-op.
- If the database is not ready, the initContainer exits non-zero and Kubernetes
  **retries it with back-off** until it succeeds - the rollout self-heals.
- The main container sets `RUN_MIGRATIONS_ON_START=0` (migration already handled).

Deploy directly:

```bash
# 1. Edit images and hosts (replace YOUR_ORG / example.org placeholders)
# 2. Create the real Secret (NOT the example file):
kubectl create namespace critter
kubectl -n critter create secret generic critter-secrets \
  --from-literal=APP_SECRET="$(php -r 'echo bin2hex(random_bytes(16));')" \
  --from-literal=DATABASE_URL="postgresql://app:STRONG@critter-db:5432/app?serverVersion=16&charset=utf8" \
  --from-literal=INSTALL_PASSWORD="" \
  --from-literal=POSTGRES_PASSWORD="STRONG"
# 3. Apply
kubectl apply -k deploy/k8s
```

Or via **ArgoCD** (GitOps): edit `deploy/argocd/application.yaml` (`repoURL`),
provide the Secret in-cluster, then `kubectl apply -f deploy/argocd/application.yaml`.
ArgoCD keeps the cluster in sync; each rollout re-runs the migration initContainer.

### Database persistence

`postgres.yaml` is a **StatefulSet** whose data lives on a **PersistentVolumeClaim**
(`volumeClaimTemplate`). The volume is bound to the database independently of the
pod, so the data survives pod restarts, crashes, app image updates and
rescheduling - an app rollout does not touch the DB pod at all.

Three settings guard against _deletion_ (a PVC alone does not):

- `persistentVolumeClaimRetentionPolicy: Retain/Retain` keeps the volume when the
  StatefulSet is deleted or scaled to zero.
- `argocd.argoproj.io/sync-options: Prune=false` on the Service and StatefulSet
  stops ArgoCD from ever pruning the database, even if the manifest leaves Git.
- **You must still verify the StorageClass.** Data survives PVC _deletion_ only if
  the class has `reclaimPolicy: Retain`; most cloud defaults are `Delete`. Pin a
  Retain class via `storageClassName` in the `volumeClaimTemplate`.

A PVC is not a backup: it protects against restarts, not against corruption, an
accidental `DROP`, or a destroyed volume.

### Off-cluster database backups

`backup.yaml` is a nightly CronJob (`app:backup:database`) that runs `pg_dump` in
the app image, uploads the dump to an S3-compatible bucket, and prunes dumps older
than the retention window. Pruning runs **only after a confirmed upload**, so a
failed dump never deletes the good backups.

The destination is its **own** parameter group, `BACKUP_S3_*`, in a **separate**
Secret (`critter-backup-secrets`) - see `backup-secret.example.yaml`. Keep it apart
from `critter-secrets` on purpose: a different bucket, a different write-scoped key,
a different policy. The backup pod mounts `critter-backup-secrets` for the
destination and pulls only `DATABASE_URL` + `APP_SECRET` from `critter-secrets`, so
it never receives the app's `AWS_*` / export-bucket credentials. Point `DATABASE_URL`
at a read-only Postgres role for tighter separation.

Self-hosted S3 (MinIO/Ceph/Garage) needs `BACKUP_S3_PATH_STYLE=1` and a full
`https://` endpoint URL; `BACKUP_S3_REGION` is required but its value is ignored.

Restore a dump with:

```bash
pg_restore --clean --if-exists -d "$DATABASE_URL" critter-YYYYMMDD-HHMMSS.dump
```

Create the first admin once the rollout is healthy:

```bash
kubectl -n critter exec deploy/critter -c app -- php bin/console app:install
# …or set INSTALL_PASSWORD in the Secret and use /admin/install.
```

---

## Development with containers

`compose.dev.yaml` runs the stack from a **dev image that bind-mounts your source**
and includes the full toolchain (composer dev deps, git, PHPUnit, linters):

```bash
docker compose -f compose.dev.yaml up -d --build
docker compose -f compose.dev.yaml exec app composer install   # first run / fresh checkout
# open http://localhost:8000   (Mailpit UI: http://localhost:8025)
```

Run the verification tools inside the container:

```bash
docker compose -f compose.dev.yaml exec app php bin/phpunit
docker compose -f compose.dev.yaml exec app php bin/console lint:twig templates
docker compose -f compose.dev.yaml exec app php bin/console lint:container
```

The dev stack auto-migrates the dev database on start (`RUN_MIGRATIONS_ON_START=1`).
Prefer host-based development? The classic flow still works:

```bash
docker start critter-pg
symfony serve -d --no-tls --port=8000
```

---

## Creating an admin from the console

Two simple, idempotent commands:

```bash
# Seed roles/privileges and, on a fresh database, create a default admin.
php bin/console app:install
#   → prints a generated password, or pass your own:
php bin/console app:install --admin-username=admin --admin-email=you@example.org --admin-password='choose-one'

# Set/reset any user's password later:
php bin/console app:user:password admin --password='new-password'
```

`app:install` never touches existing users, so it is safe to re-run after every
deploy to keep the role catalog current.

---

## Troubleshooting

| Symptom                                        | Cause / fix                                                                                                                                                             |
| ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Site shows the **maintenance page**            | A migration is pending or the DB is unreachable. Check `app:migrate` logs / `DATABASE_URL`. It clears automatically once migrations complete.                           |
| `/admin/install` says **"installer disabled"** | `INSTALL_PASSWORD` is empty. Set it, or finish setup from the console.                                                                                                  |
| `/admin/install` **redirects to the site**     | Nothing to install - schema is current and an admin exists. This is expected.                                                                                           |
| `app:migrate` keeps **retrying**               | The database is not reachable yet (still booting) or a migration is failing - inspect the logs. All-or-nothing means a failed attempt rolled back, so retries are safe. |
| nginx serves the app but **assets 404**        | The app container hasn't published `public/` to the shared volume yet (a few seconds on cold start), or `PUBLIC_SYNC_DIR` isn't set.                                    |
| Two replicas both **try to migrate**           | Expected and safe - the advisory lock serialises them; only one applies migrations.                                                                                     |
| Migration progress in the wizard               | The wizard streams `var/install/migration.log` live while `app:migrate` runs in the background.                                                                         |
