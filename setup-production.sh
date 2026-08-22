#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# First-time production setup for kozy.mu → kozy.ryndia.space
# ═══════════════════════════════════════════════════════════════════
#
# Prerequisites:
#   - Code on the server (git clone or rsync) at /var/www/kozy.mu
#   - Docker Engine + Docker Compose plugin installed
#   - DNS: kozy.ryndia.space → this VPS
#   - Running as the deployer user (in the `docker` group, not root)
#
# Usage:
#   cd /var/www/kozy.mu
#   chmod +x setup-production.sh
#   ./setup-production.sh
#
# Nginx + Certbot can be configured before or after this script; see
# DEPLOYMENT.md and nginx-kozy.ryndia.space.conf.
# ═══════════════════════════════════════════════════════════════════

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

STEP_NUM=1
TOTAL_STEPS=15

step() { echo -e "\n${BLUE}[$STEP_NUM/$TOTAL_STEPS]${NC} $1"; STEP_NUM=$((STEP_NUM + 1)); }
ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
warn() { echo -e "  ${YELLOW}⚠${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; exit 1; }

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$PROJECT_DIR"

COMPOSE_FILE="docker-compose.prod.yml"
compose() { docker compose -f "$COMPOSE_FILE" --env-file .env "$@"; }
run_in_container() { compose exec -T app "$@"; }
run_as_root()      { compose exec -u root -T app "$@"; }

echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  Production Setup — kozy.mu (kozy.ryndia.space)${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo ""
echo "  Project dir: $PROJECT_DIR"
echo ""

# ══════════════════════════════════════════════════════════════════
# Step 1: Prerequisites
# ══════════════════════════════════════════════════════════════════
step "Checking prerequisites..."

command -v docker >/dev/null 2>&1 || fail "Docker is not installed"
ok "Docker found: $(docker --version | head -1)"

docker compose version >/dev/null 2>&1 || fail "Docker Compose plugin is not installed"
ok "Docker Compose found: $(docker compose version | head -1)"

docker info >/dev/null 2>&1 || fail "Cannot talk to the Docker daemon — is your user in the 'docker' group?"
ok "Docker daemon reachable"

command -v openssl >/dev/null 2>&1 || fail "openssl is required to generate secrets"
[ -f "$COMPOSE_FILE" ] || fail "$COMPOSE_FILE not found — wrong directory?"
ok "$COMPOSE_FILE found"

# ══════════════════════════════════════════════════════════════════
# Step 2: Build .env from the production template
# ══════════════════════════════════════════════════════════════════
step "Setting up environment file..."

if [ -f .env ]; then
    warn ".env already exists — leaving it untouched"
    warn "Verify it against .env.production.example before continuing"
    grep -q "^APP_ENV=production" .env || warn "APP_ENV is not 'production' in .env!"
    grep -q "^DB_CONNECTION=mysql" .env || warn "DB_CONNECTION is not 'mysql' in .env!"
else
    [ -f .env.production.example ] || fail ".env.production.example not found"
    cp .env.production.example .env
    ok "Copied .env.production.example → .env"

    # Fill every __GENERATE__ placeholder with its own random secret.
    # Alphanumeric only: these land in .env, docker-compose interpolation
    # and MySQL/Redis CLI flags, where punctuation causes quoting grief.
    while grep -q "__GENERATE__" .env; do
        secret="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-32)"
        awk -v s="$secret" '
            BEGIN { done = 0 }
            !done && index($0, "__GENERATE__") { sub(/__GENERATE__/, s); done = 1 }
            { print }
        ' .env > .env.tmp && mv .env.tmp .env
    done
    ok "Generated DB / Redis secrets"
    chmod 600 .env
    ok "Locked down .env (chmod 600)"
    warn "Still to fill in by hand: every __SET_ME__ value (Stripe keys)"
fi

# ══════════════════════════════════════════════════════════════════
# Step 3: Bootstrap Composer on the host (Sail's Dockerfile lives in vendor/)
# ══════════════════════════════════════════════════════════════════
step "Installing Composer dependencies (host bootstrap)..."

if [ -d vendor/laravel/sail/runtimes/8.4 ]; then
    ok "vendor/laravel/sail already present — skipping bootstrap"
else
    docker run --rm \
        -u "$(id -u):$(id -g)" \
        -v "$PROJECT_DIR:/var/www/html" \
        -w /var/www/html \
        laravelsail/php84-composer:latest \
        composer install --no-interaction --ignore-platform-reqs
    ok "Composer dependencies installed"
fi

[ -d vendor/laravel/sail/runtimes/8.4 ] || fail "vendor/laravel/sail/runtimes/8.4 missing — Composer bootstrap failed"

# ══════════════════════════════════════════════════════════════════
# Step 4: Build and start the stack
# ══════════════════════════════════════════════════════════════════
step "Building images and starting containers..."

compose up -d --build
ok "Containers starting..."

# ══════════════════════════════════════════════════════════════════
# Step 5: Wait for MySQL and Redis
# ══════════════════════════════════════════════════════════════════
step "Waiting for MySQL and Redis to become healthy..."

for i in $(seq 1 60); do
    mysql_state="$(docker inspect -f '{{.State.Health.Status}}' kozy-mysql 2>/dev/null || echo starting)"
    redis_state="$(docker inspect -f '{{.State.Health.Status}}' kozy-redis 2>/dev/null || echo starting)"
    [ "$mysql_state" = "healthy" ] && [ "$redis_state" = "healthy" ] && break
    sleep 3
done
[ "${mysql_state:-}" = "healthy" ] || fail "MySQL never became healthy — check: docker compose -f $COMPOSE_FILE logs mysql"
[ "${redis_state:-}" = "healthy" ] || fail "Redis never became healthy — check: docker compose -f $COMPOSE_FILE logs redis"
ok "MySQL and Redis are healthy"

compose ps --format "table {{.Name}}\t{{.Status}}"

# ══════════════════════════════════════════════════════════════════
# Step 6: Ownership and permissions
# ══════════════════════════════════════════════════════════════════
step "Fixing file ownership and permissions..."

run_as_root chown -R sail:sail /var/www/html/vendor 2>/dev/null || true
run_as_root chown -R sail:sail /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
run_as_root chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
run_as_root mkdir -p /var/www/html/storage/logs 2>/dev/null || true
run_as_root touch /var/www/html/storage/logs/laravel.log 2>/dev/null || true
run_as_root chown sail:sail /var/www/html/storage/logs/laravel.log 2>/dev/null || true
ok "Permissions set (sail:sail, 775)"

# ══════════════════════════════════════════════════════════════════
# Step 7: Composer install inside the container (no dev deps)
# ══════════════════════════════════════════════════════════════════
step "Installing production Composer dependencies in the container..."

run_in_container composer install --optimize-autoloader --no-dev --no-interaction
ok "Composer dependencies verified"

# ══════════════════════════════════════════════════════════════════
# Step 8: Application key
# ══════════════════════════════════════════════════════════════════
step "Generating application key..."

if grep -qE "^APP_KEY=$" .env; then
    run_in_container php artisan key:generate --force
    ok "APP_KEY generated"
else
    warn "APP_KEY already set — skipping"
fi

# ══════════════════════════════════════════════════════════════════
# Step 9: Database migrations
# ══════════════════════════════════════════════════════════════════
step "Running database migrations..."

run_in_container php artisan migrate --force
ok "Migrations complete"

# ══════════════════════════════════════════════════════════════════
# Step 10: Storage symlink
# ══════════════════════════════════════════════════════════════════
step "Creating storage symlink (property images)..."

run_in_container php artisan storage:link 2>/dev/null || warn "Storage link already exists"
ok "public/storage → storage/app/public"

# ══════════════════════════════════════════════════════════════════
# Step 11: Frontend build
# ══════════════════════════════════════════════════════════════════
step "Installing NPM dependencies..."

run_in_container npm ci
ok "NPM dependencies installed"

step "Building frontend assets (Vite + Wayfinder + Inertia)..."

run_in_container npm run build
ok "Frontend assets built into public/build"

# ══════════════════════════════════════════════════════════════════
# Step 12: Filament assets
# ══════════════════════════════════════════════════════════════════
step "Publishing Filament assets..."

run_in_container php artisan filament:assets
ok "Filament assets published"

# ══════════════════════════════════════════════════════════════════
# Step 13: Optimize
# ══════════════════════════════════════════════════════════════════
step "Caching config, routes, views, events..."

run_in_container php artisan optimize:clear
run_in_container php artisan optimize
ok "Laravel optimized for production"

# ══════════════════════════════════════════════════════════════════
# Step 14: Final permissions
# ══════════════════════════════════════════════════════════════════
step "Final permissions pass..."

run_as_root chown -R sail:sail /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public/build
run_as_root chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
ok "Permissions locked down"

# ══════════════════════════════════════════════════════════════════
# Step 15: Admin account
# ══════════════════════════════════════════════════════════════════
step "Creating the back-office admin account..."

if [ -t 0 ]; then
    read -r -p "  Create an admin account now? [Y/n] " create_admin
    create_admin="${create_admin:-Y}"
    if [[ "$create_admin" =~ ^[Yy]$ ]]; then
        read -r -p "  Admin name: " admin_name
        read -r -p "  Admin email: " admin_email
        read -r -s -p "  Admin password: " admin_password; echo ""
        compose exec -T app php artisan make:admin "$admin_name" \
            --email="$admin_email" --password="$admin_password" \
            && ok "Admin created — sign in at https://kozy.ryndia.space/admin" \
            || warn "Admin creation failed — run it manually (see below)"
    else
        warn "Skipped — create one later with: php artisan make:admin"
    fi
else
    warn "Non-interactive shell — create the admin manually (see below)"
fi

# ══════════════════════════════════════════════════════════════════
# Done
# ══════════════════════════════════════════════════════════════════
echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  Setup complete!${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
echo ""
echo "  Site:   https://kozy.ryndia.space"
echo "  Admin:  https://kozy.ryndia.space/admin"
echo "  Health: https://kozy.ryndia.space/up"
echo "  App is listening locally on 127.0.0.1:$(grep -E '^APP_PORT=' .env | cut -d= -f2)"
echo ""
echo -e "${YELLOW}Remaining tasks:${NC}"
echo ""
echo "  1. Nginx + TLS (if not already done):"
echo "       sudo cp nginx-kozy.ryndia.space.conf /etc/nginx/sites-available/kozy.ryndia.space"
echo "       sudo ln -s /etc/nginx/sites-available/kozy.ryndia.space /etc/nginx/sites-enabled/"
echo "       sudo certbot --nginx -d kozy.ryndia.space"
echo "       sudo nginx -t && sudo systemctl reload nginx"
echo ""
echo "  2. Stripe (still __SET_ME__ in .env):"
echo "       STRIPE_KEY / STRIPE_SECRET / STRIPE_WEBHOOK_SECRET"
echo "       then: docker compose -f $COMPOSE_FILE exec app php artisan config:cache"
echo ""
echo "  3. Mail: confirm MAIL_HOST reaches a working SMTP relay, then"
echo "       docker compose -f $COMPOSE_FILE exec app php artisan tinker"
echo "       >>> Mail::raw('test', fn(\$m) => \$m->to('you@example.com')->subject('Kozy'));"
echo ""
echo "  4. Create an admin later if you skipped it:"
echo "       docker compose -f $COMPOSE_FILE exec app php artisan make:admin \"Name\" --email=you@kozy.mu"
echo ""
echo "  For every deploy after this one:  ./deploy.sh"
echo ""
