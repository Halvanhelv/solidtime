# solidtime (our fork)

This is our fork of [solidtime](https://github.com/solidtime-io/solidtime), a self-hosted
open-source time tracker. We're running it as our own instance instead of the paid
Clockify/Toggl-style tools — full control over the code, no per-seat pricing, deployed on
our own DigitalOcean droplet via [Kamal](https://kamal-deploy.org/).

Upstream's own README (features list, upstream contributing/security docs) is still relevant
and linked at the bottom — this file only covers what's specific to how *we* run it.

## Live instance

- **URL:** https://138.197.0.49.sslip.io
  ([sslip.io](https://sslip.io) auto-resolves `<ip>.sslip.io` to that IP — no registrar/DNS
  setup needed. Swap for a real domain later by changing `proxy.hosts` + `APP_URL` in
  `config/deploy.production.yml`, one value each.)
- **Server:** DigitalOcean droplet `138.197.0.49` ("Time Tracker" project), 1 vCPU / ~1GB RAM.
- **Admin panel:** `/admin`, gated by the `SUPER_ADMINS` env var (comma-separated emails).

## Deploying

Deploys run through [Kamal](https://kamal-deploy.org/) (same tool as the `gnosis` repo).

```bash
bin/kamal deploy -d production
```

This also happens automatically via `.github/workflows/deploy.yml` on every push to `main` —
secrets live in this repo's GitHub Actions secrets (`APP_KEY`, `PASSPORT_PRIVATE_KEY`,
`PASSPORT_PUBLIC_KEY`, `DB_PASSWORD`, `MAIL_USERNAME`/`MAIL_PASSWORD` once real SMTP creds
exist, `DEPLOY_SSH_KEY` — a dedicated deploy key, not anyone's personal key).

### Image registry

We use Kamal's **local registry** feature (`registry.server: localhost:5555` in
`config/deploy.yml`) instead of a cloud registry. Kamal spins up a throwaway registry
container on whichever machine runs the deploy and tunnels the image to the server over SSH.
This sidesteps needing any registry account/subscription at all — no DO Container Registry,
no Docker Hub private repo.

### What's running

Three roles, all built from the same image, differentiated by `CONTAINER_MODE`:

| Role | CONTAINER_MODE | Does |
|---|---|---|
| `web` | `http` | Serves the app (FrankenPHP/Octane), behind kamal-proxy |
| `queue` | `worker` | Runs `php artisan queue:work` |
| `scheduler` | `scheduler` | Runs the Laravel scheduler (cron-equivalent) |

Plus one accessory: `database` (Postgres 15). We deliberately **don't** run the `gotenberg`
accessory (PDF export/invoicing) — it's the heaviest thing in the stack (headless Chromium)
and we don't use invoicing, so it's not worth the RAM on a small droplet. If that changes,
add it back to `config/deploy.yml`'s `accessories:` and `deploy.production.yml`'s hosts.

## Fixes we made on top of upstream

Upstream's `docker/prod/Dockerfile` only actually builds inside their own CI
(`build-public.yml`), which pre-runs `composer install` + `npm run build` on the GitHub
Actions runner *before* calling `docker build` — the final `COPY . .` just picks up an
already-built tree. A plain `docker build` from a fresh checkout never worked standalone.
Fixed so it's fully self-contained (see commit history on `docker/prod/Dockerfile`):

- Uncommented and wired up the `common`/`build`/`runner` multi-stage build that was
  present-but-disabled in the file.
- Swapped the `bun` build stage for `npm` — this repo ships `package-lock.json`, not
  `bun.lock`.
- Dropped `NODE_ENV=production` before `npm ci` — it was silently skipping
  `devDependencies` (where `vite` lives), breaking the frontend build.
- Dropped the custom `xcaddy`-compiled FrankenPHP binary (only existed to add the
  `caddy-cbrotli` compression module) — the stock `dunglas/frankenphp` image already has a
  working binary, and compiling Go/Caddy from source is slow for a compression-codec
  nice-to-have.
- Dropped `composer install --audit` (fails the build on unrelated known CVE advisories in
  dependencies — not something a build step should gate on) and a stray leftover
  `RUN cat .env` debug line.
- **`HEALTHCHECK NONE`, explicit.** Just omitting our own `HEALTHCHECK` wasn't enough — the
  `dunglas/frankenphp` base image bakes in its own (`curl http://localhost:2019/metrics`,
  Caddy's admin API). That's fine for the `web` role, but `worker`/`scheduler` containers
  never run Caddy/FrankenPHP at all — port 2019 never listens there, so the inherited
  healthcheck sits in `starting` forever and Kamal's readiness poller (which checks
  `docker inspect ... State.Health` directly for non-proxied roles) kills a container that
  actually booted fine.

Deploy config additions beyond a stock Kamal setup:

- `AUTO_DB_MIGRATE: "true"` — runs `php artisan migrate --isolated --force` on every boot
  (`--isolated` means only one container actually wins the lock; the rest skip).
- `TRUSTED_PROXIES` — needed since kamal-proxy sits in front; without it Laravel can't tell
  a request came in over HTTPS (`/health-check/debug`'s `secure`/`is_trusted_proxy` read
  `false` otherwise).
- `proxy.ssl: true` + `proxy.forward_headers: true` — `ssl` defaults to `false` in Kamal
  (fine for `gnosis`, which sits behind a DO load balancer that terminates TLS; we have no
  such load balancer here, so kamal-proxy itself has to do it via Let's Encrypt). Also,
  `forward_headers` defaults to `false` *specifically when* `ssl: true` — the opposite of
  what's needed — so it has to be set explicitly too.

## Known gaps

- **No real SMTP yet** — `MAIL_USERNAME`/`MAIL_PASSWORD` secrets are unset, so password
  resets/invitations won't actually send email until real creds are added.
- **No automated Postgres backups** — data only lives in the `solidtime_db_data` Docker
  volume on the droplet.
- **Small droplet** — 1 vCPU / ~1GB RAM running `web` + `queue` + `scheduler` + Postgres.
  Fine for personal/small-team use; watch memory if usage grows.
- **sslip.io domain** — works, but a real domain would be worth it if this becomes more than
  a personal tool.

## Upstream reference

- [Feature list, self-hosting guides, contributing, license](https://github.com/solidtime-io/solidtime) —
  the original project this is forked from.
- [solidtime self-hosting docs](https://docs.solidtime.io/self-hosting/intro)
- [solidtime CLI commands](https://docs.solidtime.io/self-hosting/cli-commands) (`admin:user:create`,
  `admin:user:verify`)

This fork is licensed the same as upstream: GNU Affero General Public License v3.0 (AGPL v3),
see [LICENSE.md](./LICENSE.md).
