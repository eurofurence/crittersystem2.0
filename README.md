# Critter System 2.0

**Critter 2.0** is a Volunteer & Staff Management System (VMS) for Eurofurence events; Or known
as Critter Management System (CMS). Volunteers sign up for shifts; managers plan, staff
and publish them; the organisation tracks who is on duty, what they are qualified for,
and what they are owed.

It is a from-scratch **Symfony 8 / PHP 8.4** rewrite of the original Critter (an Engelsystem fork).

> **Status: pre-production.** The application is feature-complete and in user-acceptance testing.
> Do not run it for a live event yet.

## What it does

- **Shifts** - a drag-and-paint planner, a department grid, a shift wizard, auto-assignment
  proposals, sign-up with eligibility rules, and a public schedule (with iCal export).
- **Volunteers** - volunteer types with confirmation and supporters, certifications, badges,
  worklogs, availability, and a scannable Digital ID.
- **Operations** - an info desk with messaging, news and FAQ, a bounty board for open calls,
  operational status ("on duty"), goodie distribution, and staff overview/live views.
- **Platform** - fine-grained RBAC, SSO (OIDC), two-factor auth with step-up, Telegram
  notifications, an audit log, and GDPR data export/erasure.

## Stack

|           |                                                              |
| --------- | ------------------------------------------------------------ |
| Backend   | Symfony 8.0, PHP 8.4, Doctrine ORM                           |
| Database  | PostgreSQL 16                                                |
| Templates | Twig                                                         |
| Frontend  | AssetMapper + importmap (**no Node build**), Stimulus, Turbo |
| UI        | Tabler 1.4 (a Bootstrap 5.3 theme)                           |

There is no JavaScript build step. Assets live in `assets/` and vendor packages are pulled through
the importmap.

---

## Translation

[![Translation status](https://weblate.rustybraze.net/widget/critter-system-2-0/open-graph.png)](https://weblate.rustybraze.net/engage/critter-system-2-0/)

---

## Development

Requires PHP 8.4, Composer, and Docker (for PostgreSQL).

### Install dependencies

```bash
composer install
php bin/console importmap:install                  # vendored JS/CSS
php bin/console tailwind:build                     # bundle tailwind
```

If `composer install` fails with an out-of-memory error, increase your cli memory limit to let the
`cache:clear` command run:

- `php --ini` and look for the line ending `php.ini`
- edit that file, increasing the `memory_limit` line (512M should suffice)

### Set up and start

You can either run the commands manually to set up, or use the provided docker compose file.

#### Manual

```bash
docker start critter-pg 2>/dev/null || docker run -d --name critter-pg \
  -e POSTGRES_DB=app -e POSTGRES_USER=app -e POSTGRES_PASSWORD='!ChangeMe!' \
  -p 127.0.0.1:5432:5432 postgres:16-alpine

php bin/console doctrine:migrations:migrate
php bin/console app:install                        # seed groups/privileges + first admin

symfony serve -d --no-tls --port=8000              # http://127.0.0.1:8000
```

`app:install` prints a generated admin password (or accepts your own). Root `/` redirects guests to
`/login` and signed-in users to `/dashboard`.

#### Docker compose

note: run 'install dependencies' commands first

```bash
docker compose -f compose.dev.yaml up              # app on :8000, Mailpit on :8025
```

Use the interactive wizard at http://localhost:8000/admin/install; the default password is
`devinstall` in `compose.dev.yaml`, unless you overrode it by setting `INSTALL_PASSWORD`.

### Checks

```bash
php bin/phpunit                                    # the test suite (787 tests; run it alone)
npm test                                           # Stimulus controller tests (vitest)
php bin/phpunit --testsuite Browser                # real-browser tests (Panther; see docs/testing.md)
php bin/console lint:twig templates
php bin/console lint:container
```

### UI components

Reusable UI lives in Twig macros under `templates/components/`. **Read
[`docs/ui-components.md`](docs/ui-components.md) before writing markup**, and browse the live
gallery at `/dev/ui/navigation-kit` (admin-only; the dev kits have no route at all in production).
A new component belongs in both.

---

## Production deployment

Full manual: **[`docs/deploy.md`](docs/deploy.md)**. Four supported paths:

1. **Direct web server** - `composer install --no-dev`, point the vhost at `public/`.
2. **Docker (single image)** - build from the included `Dockerfile`.
3. **Docker Compose** - `compose.prod.yaml` (app + PostgreSQL + nginx).
4. **Kubernetes / ArgoCD** - manifests included; replace the placeholder org/hosts and supply a real
   Secret.

Migrations run through `php bin/console app:migrate`, which takes an advisory lock and is
all-or-nothing, so parallel container starts cannot race each other. Containers run it on boot by
default (`RUN_MIGRATIONS_ON_START=1`); set it to `0` to manage migrations yourself. While migrations
are pending the app serves a maintenance page rather than a half-broken one.

First-run setup is either `php bin/console app:install` on the console, or the browser wizard at
`/admin/install` - the wizard is **disabled unless `INSTALL_PASSWORD` is set**.

### Environment

| Variable                  | Required | Purpose                                                                                                                                                     |
| ------------------------- | -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_ENV`                 | yes      | `prod` in production.                                                                                                                                       |
| `APP_SECRET`              | yes      | Symfony secret.                                                                                                                                             |
| `DATABASE_URL`            | yes      | `postgresql://user:pass@host:5432/db?serverVersion=16&charset=utf8`                                                                                         |
| `APP_ENCRYPTION_KEY`      | yes      | Encrypts secrets at rest (SSO secret, Telegram key, certificates). **Lose it and that data is unrecoverable.** Generate with `app:encryption:generate-key`. |
| `INSTALL_PASSWORD`        | no       | Unlocks `/admin/install`. Empty = wizard disabled.                                                                                                          |
| `RUN_MIGRATIONS_ON_START` | no       | `1` (container default) auto-migrates on start.                                                                                                             |
| `MAILER_DSN`              | no       | Defaults to discarding mail.                                                                                                                                |

Put secrets in real environment variables or `.env.local` - never in `.env`, which is committed.

---

## Permissions & roles

Authorisation has two layers. **Roles** are coarse and hierarchical; **privileges** are fine-grained
and are what the code actually checks.

### Roles

```
ROLE_ADMIN  →  ROLE_SUBADMIN  →  ROLE_STAFF  →  ROLE_USER
```

`ROLE_ADMIN` satisfies every check unconditionally. `ROLE_SUBADMIN` mirrors it but is denied the
admin-level privileges (configuration, audit, PII, RBAC management). `ROLE_STAFF` marks someone as
crew rather than a plain volunteer. A user's roles come from the groups they belong to.

### Privileges

76 named privileges (`shift:manage`, `news:manage`, `audit:view`, …), checked with
`is_granted('<privilege>')` in Twig and `#[IsGranted]` on controllers, resolved by
`App\Security\PrivilegeVoter` against `App\Security\PrivilegeCatalog`.

- **`global:admin` is the super-privilege** (`PrivilegeCatalog::SUPER`) and satisfies every check.
  A bare `admin` is _not_ a privilege - `#[IsGranted('admin')]` would deny everyone.
- **Department scoping.** Five privileges - `department:manage`, `shift:manage`, `shift:assign`,
  `assignment:manage`, `volunteertype:assign` - are scoped: held through a department-scoped
  assignment, they grant access only _within that department_. This is what makes a "delegated"
  shift manager safe to hand out.
- **Step-up 2FA.** The most sensitive privileges require a recent second factor before the action is
  allowed, not merely at login.

Privileges are never attached to users directly. They are attached to **groups**, and users are put
in groups (managed at `/manage/groups`).

### Core groups

Seeded by `app:install` and re-converged on every run, so the catalog stays the source of truth:

| Group                           | Role            | For                                                   |
| ------------------------------- | --------------- | ----------------------------------------------------- |
| Global admin                    | `ROLE_ADMIN`    | Full, unrestricted access.                            |
| Sub admin                       | `ROLE_SUBADMIN` | Everything except configuration, audit, PII and RBAC. |
| Volunteer                       | -               | The default for a newly onboarded user.               |
| Shift manager                   | `ROLE_STAFF`    | Plan, staff and publish shifts.                       |
| Shift manager (delegated)       | `ROLE_STAFF`    | The same, but scoped to their department(s).          |
| Department manager              | `ROLE_STAFF`    | Run a department and its people.                      |
| Info Desk                       | `ROLE_STAFF`    | Answer volunteer questions and messages.              |
| Communications Manager          | `ROLE_STAFF`    | News, FAQ, questions.                                 |
| Certification Manager           | `ROLE_STAFF`    | Issue and approve certifications.                     |
| Goodies Manager / Goodies Staff | `ROLE_STAFF`    | Manage / hand out goodies.                            |

Users can also sign in via **SSO (OIDC)**, with SSO groups mapped onto Critter groups - optionally
per department - at `/manage/sso-mappings`.

---

## Documentation

|                                                  |                                                                 |
| ------------------------------------------------ | --------------------------------------------------------------- |
| [`docs/deploy.md`](docs/deploy.md)               | Deployment manual: all four paths, env vars, creating an admin. |
| [`docs/ui-components.md`](docs/ui-components.md) | The Twig UI component reference.                                |
| [`docs/sso-keycloak.md`](docs/sso-keycloak.md)   | Setting up SSO against Keycloak.                                |
| [`CLAUDE.md`](CLAUDE.md)                         | Conventions and architecture notes for contributors.            |

## License

Released under the **MIT License** - see [`LICENSE`](LICENSE). Copyright (c) 2026 Eurofurence.
