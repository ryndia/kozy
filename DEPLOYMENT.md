# Deployment — kozy.ryndia.space

Everything lives in one file: [deploy.sh](deploy.sh). Copy the project to the
server, run it, and it generates the Docker stack, the `.env` (with fresh
secrets), and the Nginx vhost itself.

```bash
cd /var/www/kozy.ryndia.space
./deploy.sh          # first run → full setup; every run after → deploy
./deploy.sh nginx    # install the vhost + obtain the TLS certificate
```

---

## What runs where

| Piece | Where | Notes |
|---|---|---|
| Nginx + TLS | Host | Terminates HTTPS, proxies to `127.0.0.1:8081` |
| `kozy-app` | Container | Laravel via `artisan serve` under supervisord (Sail 8.4 runtime) |
| `kozy-queue` | Container | `queue:work` — receipts, notifications |
| `kozy-scheduler` | Container | `schedule:work` |
| `kozy-mysql` | Container | MySQL 8.0, volume `kozy_kozy-mysql` |
| `kozy-redis` | Container | Redis 7 — cache, sessions, queue |
| `kozy-mailserver` | Container | docker-mailserver — OTP email, DKIM-signed |

Generated files (`docker-compose.prod.yml`, `nginx-kozy.ryndia.space.conf`) are
rewritten on every run and are gitignored. **Edit `deploy.sh`, not them.**

### Port map (shared VPS)

| Service | visual.ryndia.space | kozy.ryndia.space |
|---|---|---|
| App (HTTP) | `8080` | `127.0.0.1:8081` |
| MySQL | `3307` | `127.0.0.1:3308` |
| Redis | `6380` | `127.0.0.1:6381` |
| Mail (SMTP/SMTPS/submission/IMAPS) | `25/465/587/993` | `127.0.0.1:2525/4465/5587/9993` |

Nothing but the host Nginx reaches kozy's containers. The mail server is
**send-only** by default precisely so it does not fight visual's mailserver for
ports 25/465/587/993 — see [Mail](#mail--otp-delivery).

---

## Commands

```
./deploy.sh                  first run → full setup; afterwards → deploy
./deploy.sh setup            force the full first-run setup
./deploy.sh mail             (re)create the mailbox + DKIM, print DNS records
./deploy.sh nginx            install the vhost and obtain the TLS certificate
./deploy.sh backup           dump the database now
./deploy.sh status           container status and health
./deploy.sh logs [service]   follow logs (default: app)
./deploy.sh artisan <args>   run an artisan command
./deploy.sh shell [service]  open a shell in a container
./deploy.sh help
```

Deploy flags: `--no-pull`, `--no-assets`, `--no-backup`, `--no-maintenance`,
`--branch=NAME`.

---

## 1. Server prerequisites

DNS: `kozy.ryndia.space` → the VPS IP.

```bash
curl -fsSL https://get.docker.com | sh
sudo apt install -y docker-compose-plugin nginx certbot python3-certbot-nginx
sudo usermod -aG docker "$USER"     # log out and back in
sudo ufw allow 22,80,443/tcp && sudo ufw enable
```

## 2. Get the code there

```bash
sudo mkdir -p /var/www/kozy.ryndia.space && sudo chown "$USER:$USER" /var/www/kozy.ryndia.space
git clone git@github.com:ryndia/kozy.git /var/www/kozy.ryndia.space
cd /var/www/kozy.ryndia.space
```

`rsync` works too — the script detects a missing git repo and skips the pull:

```bash
rsync -az --delete --exclude node_modules --exclude vendor --exclude .env \
  --exclude storage/logs --exclude storage/backups ./ deployer@vps:/var/www/kozy.ryndia.space/
```

## 3. First run

```bash
chmod +x deploy.sh
./deploy.sh
```

Checks prerequisites → writes `.env` with generated DB/Redis/mailbox secrets
(`chmod 600`) → generates the compose file → bootstraps Composer → builds and
starts the stack → waits for MySQL/Redis health → fixes permissions →
`key:generate` → `migrate --force` → `storage:link` → `npm ci && npm run build`
→ `filament:assets` → `optimize` → configures the mail server (mailbox, DKIM,
DNS records) → offers to create the first admin.

`.env` is never overwritten if it already exists.

## 4. Nginx + TLS

```bash
./deploy.sh nginx
```

If no certificate exists yet, it installs a temporary HTTP-only vhost, runs
`certbot certonly --webroot`, then swaps in the real TLS vhost and reloads —
so `nginx -t` never fails on a certificate that isn't there yet.

## 5. Stripe

Replace the `__SET_ME__` values in `.env`, then redeploy. `STRIPE_KEY` feeds
`VITE_STRIPE_KEY`, which is **compiled into the JS bundle** — it needs an asset
rebuild, not just a config cache:

```bash
./deploy.sh --no-pull
```

Leaving the keys empty is supported; bookings then settle manually.

---

## Mail — OTP delivery

`RenterOtpNotification` is sent **synchronously inside the request** (it does
not implement `ShouldQueue`), so the SMTP hop has to be fast and local. It is:
the app submits to the `mailserver` container over the internal Docker network
on port 25, no auth, authorised by `PERMIT_DOCKER=network`. The mail server
signs with DKIM and relays outward.

### Send-only by default

Same docker-mailserver configuration as visual.ryndia.space, with one
difference: the public ports are not published. Sending needs no inbound port,
so kozy's mail server coexists with visual's on one VPS. Consequences:

- OTP mail goes out, DKIM-signed. ✅
- Nothing can be *received* at `@kozy.ryndia.space` (no MX, no IMAP).
- DMARC starts at `p=none` — with no inbox, aggregate reports can't be read.

To run a full MTA (on a host where 25/465/587/993 are free), set in `.env`:

```env
MAIL_BIND_SMTP=0.0.0.0:25
MAIL_BIND_SMTPS=0.0.0.0:465
MAIL_BIND_SUBMISSION=0.0.0.0:587
MAIL_BIND_IMAPS=0.0.0.0:993
MAIL_SSL_TYPE=letsencrypt
MAIL_ENABLE_RSPAMD=1
MAIL_ENABLE_FAIL2BAN=1
```

then `sudo ufw allow 25,465,587,993/tcp`, get a cert for `mail.kozy.ryndia.space`,
add the MX record, and redeploy.

### DNS records

`./deploy.sh mail` prints them filled in with the live IP and the real DKIM key.
In the `ryndia.space` Cloudflare zone, **all DNS-only (grey cloud)**:

| Type | Name | Value |
|---|---|---|
| A | `mail.kozy` | *VPS IP* |
| TXT | `kozy` | `v=spf1 a mx ip4:<VPS IP> -all` |
| TXT | `mail._domainkey.kozy` | *printed by `./deploy.sh mail`* |
| TXT | `_dmarc.kozy` | `v=DMARC1; p=none; rua=mailto:noreply@kozy.ryndia.space` |
| MX | `kozy` | `mail.kozy.ryndia.space` (10) — only with inbound enabled |

Plus a **PTR** record at the VPS provider: *VPS IP* → `mail.kozy.ryndia.space`.
Without it, Gmail and Outlook will reject or spam-file the OTP.

### Verifying

```bash
./deploy.sh artisan tinker
>>> Mail::raw('OTP test', fn ($m) => $m->to('you@gmail.com')->subject('Kozy'));
./deploy.sh logs mailserver     # look for: DKIM-Signature field added
dig TXT mail._domainkey.kozy.ryndia.space +short
```

`./deploy.sh status` reports whether SMTP is listening and whether DKIM signing
is actually active.

### If OTP mail lands in spam

A fresh VPS IP has no sending reputation, and some providers block outbound
port 25 entirely. Relay through a provider instead — same container, four env
vars:

```env
MAIL_RELAY_HOST=smtp.provider.com
MAIL_RELAY_PORT=587
MAIL_RELAY_USER=...
MAIL_RELAY_PASSWORD=...
```

then `./deploy.sh`. Test deliverability at https://www.mail-tester.com.

### Managing mailboxes

```bash
./deploy.sh shell mailserver
setup email list
setup email add someone@kozy.ryndia.space
setup email update noreply@kozy.ryndia.space
setup config dkim domain kozy.ryndia.space
```

> **Never regenerate a DKIM key** that is already published in DNS without
> updating the TXT record — every message signed with the new key fails until
> DNS catches up. `./deploy.sh mail` keeps an existing key for this reason.

---

## Deploying changes

```bash
./deploy.sh
```

Pull → regenerate stack → `up -d --build` → **database backup** → maintenance
mode → permissions → `composer install --no-dev` → `migrate --force` →
`npm ci && npm run build` → `filament:assets` → `optimize` → restart workers →
maintenance off → health checks.

A failure mid-deploy lifts maintenance mode through an `EXIT` trap, so a broken
build never leaves the site dark.

---

## Backups

`./deploy.sh` dumps MySQL to `storage/backups/kozy-YYYYMMDD-HHMMSS.sql.gz`
before migrating and keeps the newest 10. **A failed dump aborts the deploy**
before any migration runs. On demand: `./deploy.sh backup`.

Nightly cron on the host:

```cron
0 3 * * * cd /var/www/kozy.ryndia.space && ./deploy.sh backup >/dev/null 2>&1
```

Restore:

```bash
gunzip < storage/backups/kozy-20260822-120000.sql.gz | \
  docker compose -f docker-compose.prod.yml --env-file .env exec -T mysql \
  mysql -uroot -p"$(grep '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)" kozy
```

Uploaded property images live in `storage/app/public` — back that up too
(`tar czf uploads.tgz storage/app/public`).

---

## Troubleshooting

**502 from Nginx** — app container down or still booting: `./deploy.sh status`,
then `./deploy.sh logs app`. Check `APP_PORT` matches the vhost's `proxy_pass`.

**Permission errors on cache/log paths** — `./deploy.sh` fixes permissions on
every run; to do it alone: `./deploy.sh shell app` then
`chown -R sail:sail storage bootstrap/cache` as root.

**Migrations hang** — MySQL isn't healthy: `./deploy.sh logs mysql`.

**Redis `NOAUTH`** — `REDIS_PASSWORD` drifted from what the container started
with. `docker compose -f docker-compose.prod.yml --env-file .env up -d redis`.

**Mail server keeps restarting: "You need at least one mail account to start
Dovecot"** — docker-mailserver refuses to start Dovecot with zero accounts and
shuts the container down after 120s. The account has to exist in the config
volume *before* the container starts, so `deploy.sh` provisions it with a
throwaway container. To do it by hand:

```bash
docker run --rm -v kozy_kozy-mail-config:/tmp/docker-mailserver \
  ghcr.io/docker-mailserver/docker-mailserver:latest \
  setup email add noreply@kozy.ryndia.space "$(grep '^MAIL_ACCOUNT_PASSWORD=' .env | cut -d= -f2-)"
docker compose -f docker-compose.prod.yml --env-file .env up -d mailserver
```

**Image build fails: build context `./docker/runtime-8.4` missing** — the Sail
runtime lives in `vendor/laravel/sail`, a *dev* dependency that
`composer install --no-dev` deletes on every deploy. The script copies it to
`docker/runtime-8.4` once and rebuilds it from Composer if it ever goes missing;
if you hit this, just re-run `./deploy.sh`. (This is the same failure that
breaks visual.ryndia.space's MySQL: its compose mounts
`vendor/laravel/sail/database/mysql/create-testing-database.sh`, and once
Composer removes that file Docker creates a *directory* in its place — hence
"not a directory". Delete the stray path and restore the vendor directory, or
drop that mount from the compose file: it only ever mattered on first DB init.)

**OTP email never arrives**
1. `./deploy.sh status` — is SMTP listening, is DKIM active?
2. `./deploy.sh logs mailserver` — look for `status=sent`, `status=bounced`, `status=deferred`.
3. `./deploy.sh shell mailserver` → `mailq` — anything stuck?
4. `deferred … Connection timed out` on port 25 means the provider blocks
   outbound SMTP → use a relay (above).
5. Delivered but in spam → check SPF/DKIM/DMARC/PTR.

**Mail goes out unsigned** — OpenDKIM loads its signing tables only when the
container is *recreated*, not merely restarted. `./deploy.sh mail` detects an
empty `/etc/opendkim/SigningTable` and recreates the container for you.

**Mixed content / wrong URLs** — `APP_URL` must be `https://…` and
`TRUSTED_PROXIES=*` set, since TLS terminates at Nginx.

**Session lost after login** — `SESSION_DOMAIN` must be exactly
`kozy.ryndia.space`, and `SESSION_SECURE_COOKIE=true` requires HTTPS.

**Stripe key change has no effect in the browser** — rebuild assets
(`./deploy.sh --no-pull`); `VITE_STRIPE_KEY` is baked into the bundle.

---

## Security notes

- `.env` is `chmod 600`; secrets are generated per install, never committed.
- MySQL, Redis, the app port and (by default) all mail ports bind to `127.0.0.1`.
- `APP_DEBUG=false`, `LOG_LEVEL=error`. Use `./deploy.sh logs app` to debug.
- Redis requires a password; MySQL has separate root and app credentials.
- The vhost sets HSTS, `nosniff`, and a CSP scoped to Stripe and Bunny Fonts.
- Rspamd/Fail2Ban stay off while the mail server is send-only (nothing inbound
  to filter, no public port to brute-force); turn both on with inbound mail.
