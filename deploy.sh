#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Deployment script for kozy.mu → kozy.ryndia.space
# ═══════════════════════════════════════════════════════════════════
#
# Usage:
#   ./deploy.sh                 # full deploy (pull, build, migrate, cache)
#   ./deploy.sh --no-pull       # deploy the code already on disk
#   ./deploy.sh --no-assets     # skip npm ci / npm run build (backend-only change)
#   ./deploy.sh --no-backup     # skip the pre-migration MySQL dump
#   ./deploy.sh --no-maintenance# stay online during the deploy
#   ./deploy.sh --branch=main   # branch to pull (default: current branch)
#
# First-time setup on a fresh server is setup-production.sh, not this.
# ═══════════════════════════════════════════════════════════════════

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

info() { echo -e "\n${BLUE}▶${NC} $1"; }
ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
warn() { echo -e "  ${YELLOW}⚠${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; exit 1; }

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$PROJECT_DIR"

COMPOSE_FILE="docker-compose.prod.yml"
BACKUP_DIR="$PROJECT_DIR/storage/backups"
BACKUP_KEEP=10

DO_PULL=true
DO_ASSETS=true
DO_BACKUP=true
DO_MAINTENANCE=true
BRANCH=""

for arg in "$@"; do
    case "$arg" in
        --no-pull)        DO_PULL=false ;;
        --no-assets)      DO_ASSETS=false ;;
        --no-backup)      DO_BACKUP=false ;;
        --no-maintenance) DO_MAINTENANCE=false ;;
        --branch=*)       BRANCH="${arg#*=}" ;;
        -h|--help)        sed -n '2,20p' "$0"; exit 0 ;;
        *)                fail "Unknown option: $arg (try --help)" ;;
    esac
done

compose() { docker compose -f "$COMPOSE_FILE" --env-file .env "$@"; }
run_in_container() { compose exec -T app "$@"; }
run_as_root()      { compose exec -u root -T app "$@"; }

[ -f "$COMPOSE_FILE" ] || fail "$COMPOSE_FILE not found — wrong directory?"
[ -f .env ] || fail ".env not found — run ./setup-production.sh first"
grep -qE "^APP_ENV=production" .env || warn "APP_ENV is not 'production' in .env"

echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  Deploying kozy.mu → kozy.ryndia.space${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"

# Bring the app back up if we bail out midway.
MAINTENANCE_ON=false
cleanup() {
    local code=$?
    if [ "$MAINTENANCE_ON" = true ]; then
        echo ""
        warn "Lifting maintenance mode..."
        run_in_container php artisan up >/dev/null 2>&1 || true
    fi
    if [ $code -ne 0 ]; then
        echo -e "\n${RED}Deployment failed (exit $code).${NC}"
        echo "  Logs: docker compose -f $COMPOSE_FILE logs --tail=100"
    fi
}
trap cleanup EXIT

# ── Step 1: Pull latest code ───────────────────────────────────────
if [ "$DO_PULL" = true ]; then
    info "Pulling latest changes..."
    if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        target_branch="${BRANCH:-$(git branch --show-current)}"
        git pull origin "$target_branch"
        ok "Pulled origin/$target_branch @ $(git rev-parse --short HEAD)"
    else
        warn "Not a git repository — skipping pull (deploy the files you rsynced)"
    fi
else
    info "Skipping git pull (--no-pull)"
fi

# ── Step 2: Ensure the stack is running ────────────────────────────
info "Ensuring containers are up to date..."
compose up -d --build --remove-orphans
ok "Containers running"

info "Waiting for MySQL to be healthy..."
for i in $(seq 1 40); do
    state="$(docker inspect -f '{{.State.Health.Status}}' kozy-mysql 2>/dev/null || echo starting)"
    [ "$state" = "healthy" ] && break
    sleep 3
done
[ "${state:-}" = "healthy" ] || fail "MySQL not healthy — check: docker compose -f $COMPOSE_FILE logs mysql"
ok "MySQL healthy"

# ── Step 3: Back up the database before touching it ────────────────
if [ "$DO_BACKUP" = true ]; then
    info "Backing up the database..."
    mkdir -p "$BACKUP_DIR"
    db_name="$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)"
    db_root_pw="$(grep -E '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)"
    stamp="$(date +%Y%m%d-%H%M%S)"
    backup_file="$BACKUP_DIR/kozy-$stamp.sql.gz"

    if compose exec -T mysql mysqldump \
            -uroot -p"$db_root_pw" \
            --single-transaction --quick --routines --events \
            "$db_name" 2>/dev/null | gzip > "$backup_file"; then
        ok "Saved $(basename "$backup_file") ($(du -h "$backup_file" | cut -f1))"
        # Keep only the newest $BACKUP_KEEP dumps.
        ls -1t "$BACKUP_DIR"/kozy-*.sql.gz 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)) | xargs -r rm --
        ok "Retention: newest $BACKUP_KEEP dumps kept"
    else
        rm -f "$backup_file"
        fail "Database backup failed — aborting before migrations (use --no-backup to override)"
    fi
else
    info "Skipping database backup (--no-backup)"
fi

# ── Step 4: Maintenance mode ───────────────────────────────────────
if [ "$DO_MAINTENANCE" = true ]; then
    info "Enabling maintenance mode..."
    run_in_container php artisan down --retry=15 >/dev/null 2>&1 || true
    MAINTENANCE_ON=true
    ok "Site is in maintenance mode"
fi

# ── Step 5: Permissions (before writing anything) ──────────────────
info "Fixing permissions..."
run_as_root chown -R sail:sail /var/www/html/vendor 2>/dev/null || true
run_as_root chown -R sail:sail /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
run_as_root chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
ok "Permissions set"

# ── Step 6: Composer dependencies ──────────────────────────────────
info "Installing Composer dependencies (no dev)..."
run_in_container composer install --optimize-autoloader --no-dev --no-interaction
ok "Composer dependencies up to date"

# ── Step 7: Migrations ─────────────────────────────────────────────
info "Running database migrations..."
run_in_container php artisan migrate --force
ok "Migrations applied"

# ── Step 8: Frontend assets ────────────────────────────────────────
if [ "$DO_ASSETS" = true ]; then
    info "Installing NPM dependencies..."
    run_in_container npm ci
    ok "NPM dependencies installed"

    info "Building frontend assets (Vite + Wayfinder + Inertia)..."
    run_in_container npm run build
    ok "Assets built into public/build"

    info "Publishing Filament assets..."
    run_in_container php artisan filament:assets
    ok "Filament assets published"
else
    info "Skipping frontend build (--no-assets)"
fi

# ── Step 9: Storage symlink (no-op if it already exists) ───────────
info "Verifying storage symlink..."
run_in_container php artisan storage:link >/dev/null 2>&1 || true
ok "public/storage in place"

# ── Step 10: Rebuild caches ────────────────────────────────────────
info "Clearing caches..."
run_in_container php artisan optimize:clear
ok "Caches cleared"

info "Caching config, routes, views, events..."
run_in_container php artisan optimize
ok "Laravel optimized"

# ── Step 11: Final permissions ─────────────────────────────────────
info "Final permissions pass..."
run_as_root chown -R sail:sail /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public/build 2>/dev/null || true
run_as_root chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
ok "Permissions locked down"

# ── Step 12: Restart workers so they pick up the new code ──────────
info "Restarting queue worker and scheduler..."
run_in_container php artisan queue:restart >/dev/null 2>&1 || true
compose restart queue scheduler >/dev/null
ok "Workers restarted"

# ── Step 13: Back online ───────────────────────────────────────────
if [ "$MAINTENANCE_ON" = true ]; then
    info "Lifting maintenance mode..."
    run_in_container php artisan up
    MAINTENANCE_ON=false
    ok "Site is live"
fi

# ── Step 14: Health check ──────────────────────────────────────────
info "Health check..."
app_port="$(grep -E '^APP_PORT=' .env | cut -d= -f2- || echo 8081)"
if curl -fsS --max-time 10 "http://127.0.0.1:${app_port:-8081}/up" >/dev/null; then
    ok "Local health check passed (127.0.0.1:${app_port:-8081}/up)"
else
    warn "Local health check FAILED — inspect: docker compose -f $COMPOSE_FILE logs --tail=100 app"
fi

if curl -fsS --max-time 10 -o /dev/null -w '%{http_code}' https://kozy.ryndia.space/up | grep -q 200; then
    ok "Public health check passed (https://kozy.ryndia.space/up)"
else
    warn "Public health check failed — check Nginx/TLS, not necessarily the app"
fi

compose ps --format "table {{.Name}}\t{{.Status}}"

echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  Deployment completed successfully!${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
echo ""
echo "  Site:   https://kozy.ryndia.space"
echo "  Admin:  https://kozy.ryndia.space/admin"
echo "  Logs:   docker compose -f $COMPOSE_FILE logs -f"
echo ""
