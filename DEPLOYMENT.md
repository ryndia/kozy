# Deployment — kozy.mu → kozy.ryndia.space

Production deployment of the Kozy Laravel app on a VPS, with MySQL, Redis, a
queue worker and a scheduler in containers, behind the host's Nginx + Let's Encrypt.

Modelled on the `visual.ryndia.space` deployment, with ports offset so both
stacks coexist on one server.

---

## What runs where

| Piece | Where | Notes |
|---|---|---|
| Nginx + TLS | Host | Terminates HTTPS, proxies to `127.0.0.1:8081` |
| `kozy-app` | Container | Laravel via `artisan serve` under supervisord (Sail 8.4 runtime) |
| `kozy-queue` | Container | `queue:work` — mail, notifications, PDF receipts |
| `kozy-scheduler` | Container | `schedule:work` |
| `kozy-mysql` | Container | MySQL 8.0, volume `kozy_kozy-mysql` |
| `kozy-redis` | Container | Redis 7, cache + sessions + queue, volume `kozy_kozy-redis` |
| Mail | External | SMTP relay (host mailserver or a provider) — no mail container |

Everything is defined in [docker-compose.prod.yml](docker-compose.prod.yml).
The local-dev [docker-compose.yml](docker-compose.yml) (Mailpit) is untouched.

### Port map (shared VPS)

| Service | visual.ryndia.space | kozy.ryndia.space |
|---|---|---|
| App (HTTP) | `8080` | `127.0.0.1:8081` |
| MySQL | `3307` | `127.0.0.1:3308` |
| Redis | `6380` | `127.0.0.1:6381` |

Kozy's ports bind to loopback only — nothing but the host Nginx (and you, over
SSH) can reach them.

---

## Files

| File | Purpose |
|---|---|
| `docker-compose.prod.yml` | The production stack |
| `.env.production.example` | Env template; secrets are generated at setup |
| `setup-production.sh` | First-time server setup (run once) |
| `deploy.sh` | Every deploy after that |
| `nginx-kozy.ryndia.space.conf` | Host vhost, TLS + reverse proxy + CSP |
| `.dockerignore` | Keeps secrets and build junk out of image context |

---

## 1. Server prerequisites

DNS: `kozy.ryndia.space` → the VPS IP (A/AAAA record).

```bash
# Docker (skip if visual.ryndia.space is already on this box)
curl -fsSL https://get.docker.com | sh
sudo apt install -y docker-compose-plugin nginx certbot python3-certbot-nginx

# Deployer user
sudo usermod -aG docker "$USER"   # log out and back in

# Firewall
sudo ufw allow 22/tcp && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw enable
```

## 2. Get the code onto the server

```bash
sudo mkdir -p /var/www/kozy.mu && sudo chown "$USER:$USER" /var/www/kozy.mu
git clone <your-repo> /var/www/kozy.mu
cd /var/www/kozy.mu
```

No git remote yet? `rsync` works too — `deploy.sh` detects the absence of a
repo and skips the pull instead of failing:

```bash
rsync -az --delete \
  --exclude node_modules --exclude vendor --exclude .env \
  --exclude storage/logs --exclude storage/backups \
  ./ deployer@vps:/var/www/kozy.mu/
```

## 3. First-time setup

```bash
chmod +x setup-production.sh deploy.sh
./setup-production.sh
```

It checks prerequisites, writes `.env` from `.env.production.example` with
freshly generated DB/Redis passwords (`chmod 600`), bootstraps Composer,
builds and starts the stack, waits for MySQL/Redis health, fixes permissions,
runs `key:generate` and `migrate --force`, links storage, builds the frontend,
publishes Filament assets, caches config/routes/views, and offers to create the
first admin.

**`.env` is never overwritten** if it already exists.

## 4. Nginx + TLS

```bash
sudo cp nginx-kozy.ryndia.space.conf /etc/nginx/sites-available/kozy.ryndia.space
sudo ln -s /etc/nginx/sites-available/kozy.ryndia.space /etc/nginx/sites-enabled/
sudo mkdir -p /var/www/certbot
sudo certbot --nginx -d kozy.ryndia.space
sudo nginx -t && sudo systemctl reload nginx
```

Certbot rewrites the file in place; the config already contains the `ssl_*`
lines it expects, so re-running it is safe.

## 5. Finish the configuration

Edit `.env` and replace the `__SET_ME__` values:

```env
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

`STRIPE_KEY` feeds `VITE_STRIPE_KEY`, which is **baked into the JS bundle** —
after changing it, rebuild the frontend, not just the config cache:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
./deploy.sh --no-pull        # rebuilds assets with the new VITE_STRIPE_KEY
```

Leaving the Stripe keys empty is supported — bookings then settle manually.

### Mail

The default `MAIL_HOST=host.docker.internal:25` relays through the
docker-mailserver already running on the VPS for `visual.ryndia.space`. Either
add a `kozy.mu` sender domain there, or point `MAIL_*` at an external provider
(Mailgun, Postmark, SES, Resend). Test it:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan tinker
>>> Mail::raw('test', fn ($m) => $m->to('you@example.com')->subject('Kozy'));
```

---

## Deploying changes

```bash
cd /var/www/kozy.mu
./deploy.sh
```

Backs up the database → maintenance mode → pull → build → `composer install
--no-dev` → `migrate --force` → `npm ci && npm run build` → Filament assets →
`optimize` → restart workers → maintenance off → health check.

| Flag | Effect |
|---|---|
| `--no-pull` | Deploy the code already on disk |
| `--no-assets` | Skip `npm ci` / `npm run build` (backend-only change) |
| `--no-backup` | Skip the pre-migration MySQL dump |
| `--no-maintenance` | Stay online for the duration |
| `--branch=main` | Branch to pull (default: current branch) |

A failure mid-deploy lifts maintenance mode via an `EXIT` trap, so the site
never stays dark because a build broke.

---

## Backups

`deploy.sh` dumps MySQL to `storage/backups/kozy-YYYYMMDD-HHMMSS.sql.gz`
before migrating and keeps the newest 10. **A failed dump aborts the deploy**
before any migration runs.

Nightly cron on the host:

```cron
0 3 * * * cd /var/www/kozy.mu && docker compose -f docker-compose.prod.yml exec -T mysql \
  mysqldump -uroot -p"$(grep '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)" \
  --single-transaction --quick kozy | gzip > storage/backups/nightly-$(date +\%F).sql.gz
```

Restore:

```bash
gunzip < storage/backups/kozy-20260822-120000.sql.gz | \
  docker compose -f docker-compose.prod.yml exec -T mysql \
  mysql -uroot -p"$(grep '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)" kozy
```

Uploaded property images live in `storage/app/public` on the host bind mount —
back that up too (`tar czf uploads.tgz storage/app/public`).

---

## Operations cheat sheet

```bash
alias kozy='docker compose -f /var/www/kozy.mu/docker-compose.prod.yml --env-file /var/www/kozy.mu/.env'

kozy ps                       # status
kozy logs -f app              # app logs
kozy logs -f queue            # queue worker
kozy exec app php artisan about
kozy exec app php artisan queue:failed
kozy exec app php artisan make:admin "Name" --email=you@kozy.mu
kozy exec mysql mysql -ukozy -p kozy      # SQL shell
kozy restart app queue scheduler
kozy down                     # stop (volumes survive)
```

---

## Troubleshooting

**502 from Nginx** — the app container is down or still booting.
`kozy ps` then `kozy logs --tail=100 app`. Confirm `APP_PORT` in `.env`
matches the `proxy_pass` port in the vhost.

**"Please provide a valid cache path" / permission errors** — rerun the
permission step: `kozy exec -u root app chown -R sail:sail /var/www/html/storage /var/www/html/bootstrap/cache`.

**Migrations hang or "connection refused"** — MySQL wasn't healthy yet.
`docker inspect -f '{{.State.Health.Status}}' kozy-mysql`. Both scripts wait
for `healthy`, so this usually means MySQL is genuinely failing — read
`kozy logs mysql`.

**Redis `NOAUTH`** — `REDIS_PASSWORD` in `.env` drifted from the value the
container started with. Restart Redis after changing it: `kozy up -d redis`.

**Mixed content / wrong URLs** — `APP_URL` must be the `https://` address and
`TRUSTED_PROXIES=*` must be set, since TLS terminates at Nginx.

**Session lost after login** — `SESSION_DOMAIN` must match the host exactly
(`kozy.ryndia.space`) and `SESSION_SECURE_COOKIE=true` requires HTTPS.

**Stripe key changes don't take effect in the browser** — `VITE_STRIPE_KEY` is
compiled into the bundle; you must rebuild assets, not just `config:cache`.

**Old assets served after deploy** — hard-refresh; Vite's manifest is
content-hashed, so a stale `public/build` means the build step was skipped.

---

## Security notes

- `.env` is `chmod 600` and excluded from Docker context via `.dockerignore`.
- MySQL and Redis publish on `127.0.0.1` only; the app port too.
- `APP_DEBUG=false` and `LOG_LEVEL=error` in the template — never flip these on
  a live site to debug; use `kozy logs -f app` instead.
- Redis requires a password (`--requirepass`), unlike the dev default.
- The vhost sets HSTS, `X-Content-Type-Options`, and a CSP scoped to Stripe and
  Bunny Fonts. Widen the CSP deliberately if you add a third-party script.
