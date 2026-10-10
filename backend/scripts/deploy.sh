#!/usr/bin/env bash
#
# Zusty deployment script — Hostinger business shared hosting.
#
# Hostinger gives you SSH, PHP 8.3 and MySQL, but no root, no systemd and no
# Composer. This script therefore does everything with the shell, php and mysql
# binaries that are already on the account.
#
#   ./scripts/deploy.sh                 deploy the current checkout
#   ./scripts/deploy.sh --dry-run       show what would happen, change nothing
#   ./scripts/deploy.sh --skip-backup   skip the pre-deploy backup (not advised)
#   ./scripts/deploy.sh --skip-preflight  deploy despite readiness failures
#   ./scripts/deploy.sh --rollback      restore the previous release
#
# The script is idempotent and safe to re-run. It takes a backup before touching
# the database, puts the site into maintenance mode only for the migration
# window, and rolls back automatically if a migration fails.

set -Eeuo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_PATH="$(dirname "$SCRIPT_DIR")"

PHP_BIN="${PHP_BIN:-php}"
RELEASES_KEEP="${RELEASES_KEEP:-3}"

DRY_RUN=0
SKIP_BACKUP=0
SKIP_PREFLIGHT=0
ROLLBACK=0

DEPLOY_LOG="$BASE_PATH/storage/logs/deploy.log"
STATE_DIR="$BASE_PATH/storage/deploy"
HELPER="$SCRIPT_DIR/deploy_helper.php"

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------

if [ -t 1 ]; then
    C_RESET=$'\033[0m'; C_DIM=$'\033[2m'; C_RED=$'\033[31m'
    C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'
else
    C_RESET=''; C_DIM=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''
fi

log()  { printf '%s\n' "$*" | tee -a "$DEPLOY_LOG" >/dev/null; printf '%s\n' "$*"; }
step() { log "${C_BLUE}==>${C_RESET} $*"; }
ok()   { log "${C_GREEN}  ok${C_RESET} $*"; }
warn() { log "${C_YELLOW}  ! ${C_RESET} $*"; }
die()  { log "${C_RED}  x ${C_RESET} $*"; exit 1; }
run()  {
    if [ "$DRY_RUN" -eq 1 ]; then
        log "${C_DIM}  would run: $*${C_RESET}"
    else
        "$@"
    fi
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

for argument in "$@"; do
    case "$argument" in
        --dry-run)     DRY_RUN=1 ;;
        --skip-backup) SKIP_BACKUP=1 ;;
        --skip-preflight) SKIP_PREFLIGHT=1 ;;
        --rollback)    ROLLBACK=1 ;;
        -h|--help)
            sed -n '2,17p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) die "Unknown option: $argument (try --help)" ;;
    esac
done

mkdir -p "$(dirname "$DEPLOY_LOG")" "$STATE_DIR"
log ""
log "${C_DIM}--- $(date '+%Y-%m-%d %H:%M:%S') deploy start ---${C_RESET}"

# ---------------------------------------------------------------------------
# Maintenance mode. Removed by the EXIT trap however the script ends, so a
# failure can never strand the site behind the maintenance page.
# ---------------------------------------------------------------------------

# Maintenance mode is the `general.maintenance_mode` setting in the database —
# MaintenanceMiddleware reads it on every API request. A flag file would do
# nothing, so the toggle goes through the helper that writes the setting and
# clears its cache.
MAINTENANCE_ENABLED=0

maintenance_on() {
    [ "$DRY_RUN" -eq 1 ] && { log "${C_DIM}  would enable maintenance mode${C_RESET}"; return; }
    if "$PHP_BIN" "$HELPER" maintenance:on >/dev/null 2>&1; then
        MAINTENANCE_ENABLED=1
        ok "maintenance mode on (API returns 503; health stays up)"
    else
        warn "could not enable maintenance mode; continuing with the site live"
    fi
}

maintenance_off() {
    [ "$DRY_RUN" -eq 1 ] && return
    [ "$MAINTENANCE_ENABLED" -eq 0 ] && return
    if "$PHP_BIN" "$HELPER" maintenance:off >/dev/null 2>&1; then
        MAINTENANCE_ENABLED=0
        ok "maintenance mode off"
    else
        warn "COULD NOT DISABLE MAINTENANCE MODE — run: php scripts/deploy_helper.php maintenance:off"
    fi
}

on_exit() {
    local code=$?
    maintenance_off
    if [ "$code" -ne 0 ]; then
        log "${C_RED}Deployment failed (exit $code). The site is serving again.${C_RESET}"
        log "See $DEPLOY_LOG"
    fi
    log "${C_DIM}--- $(date '+%Y-%m-%d %H:%M:%S') deploy end (exit $code) ---${C_RESET}"
}
trap on_exit EXIT

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------

preflight() {
    step "Preflight checks"

    command -v "$PHP_BIN" >/dev/null 2>&1 || die "php not found. Set PHP_BIN to the full path."

    local version
    version="$($PHP_BIN -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    ok "php $version ($($PHP_BIN -r 'echo PHP_BINARY;'))"

    # 8.1 is the floor the code targets; anything older will not parse.
    "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' \
        || die "PHP 8.1 or newer is required (found $version)."

    # zip is required: "Download my data" is a ZIP built with ZipArchive, and
    # every data export fails without it.
    for extension in pdo_mysql mbstring openssl json curl fileinfo zip; do
        "$PHP_BIN" -m | grep -qi "^${extension}$" \
            || die "Required PHP extension missing: $extension"
    done
    "$PHP_BIN" -r 'exit(class_exists("ZipArchive") ? 0 : 1);' \
        || die "Required PHP class missing: ZipArchive (enable the zip extension)"
    ok "required extensions present"

    for optional in gd apcu; do
        if "$PHP_BIN" -m | grep -qi "^${optional}$"; then
            ok "optional extension $optional present"
        else
            warn "optional extension $optional missing (image work / fast cache degrade gracefully)"
        fi
    done

    [ -f "$BASE_PATH/.env" ] || die ".env is missing. Copy .env.example and fill it in."

    # A production deploy with debug on would leak stack traces to the world.
    if grep -qE '^APP_DEBUG=(true|1)' "$BASE_PATH/.env" && grep -qE '^APP_ENV=production' "$BASE_PATH/.env"; then
        die "APP_DEBUG is true while APP_ENV is production. Fix .env before deploying."
    fi

    for key in DB_NAME DB_USER JWT_SECRET APP_ENCRYPTION_KEY; do
        local value
        value="$(grep -E "^${key}=" "$BASE_PATH/.env" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
        [ -n "$value" ] || die "$key is empty in .env"
    done
    ok ".env has the required keys"

    # Fail before touching anything if the database is unreachable.
    "$PHP_BIN" "$HELPER" check || die "Database connectivity check failed."
    ok "database reachable"

    # The deep readiness audit.
    #
    # The checks above are structural — is PHP new enough, is .env filled in,
    # does the database answer. preflight.php goes further and inspects the
    # *contents*: seeded demo accounts that still share the password published
    # in the seed file, secrets left at their example values, encryption keys
    # that match each other, scheduled jobs that have never run.
    #
    # It was added and never wired in, so none of it could stop a deploy. A
    # readiness check nothing consults is documentation, not a gate.
    #
    # Non-zero exit blocks the deploy. --skip-preflight exists for the case
    # where an operator has read the failures and accepts them; it prints what
    # it is skipping so that choice is on the record.
    if [ -f "$BASE_PATH/scripts/preflight.php" ]; then
        if [ "$SKIP_PREFLIGHT" = "1" ]; then
            warn "production readiness audit SKIPPED at your request (--skip-preflight)"
        # --pre-migrate: the migrations this deploy is about to apply are
        # expected to be pending here and must not fail the audit.
        elif "$PHP_BIN" "$BASE_PATH/scripts/preflight.php" --pre-migrate; then
            ok "production readiness audit passed"
        else
            die "Production readiness audit failed. Fix the failures above, or re-run with --skip-preflight if you accept them."
        fi
    fi
}

# ---------------------------------------------------------------------------
# Syntax gate — never deploy code that will not parse.
# ---------------------------------------------------------------------------

lint() {
    step "Linting PHP sources"

    local failed=0 count=0
    while IFS= read -r file; do
        count=$((count + 1))
        if ! "$PHP_BIN" -l "$file" >/dev/null 2>&1; then
            warn "syntax error: $file"
            "$PHP_BIN" -l "$file" || true
            failed=$((failed + 1))
        fi
    done < <(find "$BASE_PATH/app" "$BASE_PATH/config" "$BASE_PATH/routes" \
                  "$BASE_PATH/scripts" "$BASE_PATH/public" -name '*.php' -type f 2>/dev/null)

    [ "$failed" -eq 0 ] || die "$failed file(s) failed to parse. Nothing was deployed."
    ok "$count files parsed cleanly"
}

# ---------------------------------------------------------------------------
# Permissions. Hostinger runs PHP as the account user, so 755/644 is correct;
# 777 would be both unnecessary and a finding in any security review.
# ---------------------------------------------------------------------------

permissions() {
    step "Applying file permissions"

    run find "$BASE_PATH" -type d -not -path '*/storage/*' -exec chmod 755 {} \;
    run find "$BASE_PATH" -type f -name '*.php' -exec chmod 644 {} \;

    for directory in storage storage/logs storage/cache storage/tmp storage/backups \
                     storage/framework public/uploads; do
        run mkdir -p "$BASE_PATH/$directory"
        run chmod 775 "$BASE_PATH/$directory"
    done

    # .env holds database and payment credentials; keep it owner-only.
    [ -f "$BASE_PATH/.env" ] && run chmod 600 "$BASE_PATH/.env"

    run chmod +x "$SCRIPT_DIR/deploy.sh" "$SCRIPT_DIR/backup.sh" 2>/dev/null || true

    ok "permissions applied"
}

# ---------------------------------------------------------------------------
# Backup / restore
# ---------------------------------------------------------------------------

backup() {
    if [ "$SKIP_BACKUP" -eq 1 ]; then
        warn "pre-deploy backup skipped by request"
        return
    fi

    step "Pre-deploy database backup"

    if [ "$DRY_RUN" -eq 1 ]; then
        log "${C_DIM}  would run scripts/backup.sh --tag pre-deploy${C_RESET}"
        return
    fi

    if [ -x "$SCRIPT_DIR/backup.sh" ]; then
        "$SCRIPT_DIR/backup.sh" --tag pre-deploy --quiet \
            || die "Backup failed. Refusing to deploy without one."
    else
        bash "$SCRIPT_DIR/backup.sh" --tag pre-deploy --quiet \
            || die "Backup failed. Refusing to deploy without one."
    fi

    # Remember which dump this deploy started from, so --rollback can find it.
    ls -1t "$BASE_PATH/storage/backups"/*.sql.gz 2>/dev/null | head -1 > "$STATE_DIR/last-pre-deploy" || true
    ok "backup written"
}

rollback() {
    step "Rolling back the database"

    local dump
    dump="$(cat "$STATE_DIR/last-pre-deploy" 2>/dev/null || true)"

    [ -n "$dump" ] && [ -f "$dump" ] \
        || die "No pre-deploy backup recorded. Restore manually from storage/backups."

    log "  restoring $dump"
    log "${C_YELLOW}  This overwrites the current database.${C_RESET}"

    if [ "$DRY_RUN" -eq 1 ]; then
        log "${C_DIM}  would restore $dump${C_RESET}"
        return
    fi

    read -r -p "  Type the database name to confirm: " confirmation
    "$PHP_BIN" "$HELPER" restore "$dump" "$confirmation" \
        || die "Restore failed."

    ok "database restored"

    # The dump can predate accounts erased since it was taken, and the privacy
    # policy promises they are deleted again straight away.
    if "$PHP_BIN" "$SCRIPT_DIR/privacy_support_request.php" reapply-erasures; then
        ok "erasures re-applied"
    else
        warn "erasures were not re-applied; run: $PHP_BIN $SCRIPT_DIR/privacy_support_request.php reapply-erasures"
    fi
}

# ---------------------------------------------------------------------------
# Migrations
# ---------------------------------------------------------------------------

migrate() {
    step "Applying database migrations"

    if [ "$DRY_RUN" -eq 1 ]; then
        log "${C_DIM}  would run install.php --migrate-only --no-interaction${C_RESET}"
        return
    fi

    if ! "$PHP_BIN" "$SCRIPT_DIR/install.php" --migrate-only --no-interaction; then
        warn "migration failed — restoring the pre-deploy backup"
        local dump
        dump="$(cat "$STATE_DIR/last-pre-deploy" 2>/dev/null || true)"
        if [ -n "$dump" ] && [ -f "$dump" ]; then
            "$PHP_BIN" "$HELPER" restore "$dump" "--force" || true
            warn "database restored to its pre-deploy state"
        fi
        die "Migrations failed. Deployment aborted."
    fi

    ok "migrations applied"
}

# ---------------------------------------------------------------------------
# Caches
# ---------------------------------------------------------------------------

warm_caches() {
    step "Rebuilding caches"

    run rm -f "$BASE_PATH/storage/cache/classmap.php"

    if [ "$DRY_RUN" -eq 0 ]; then
        "$PHP_BIN" "$HELPER" cache:rebuild || warn "cache rebuild reported a problem"
    else
        log "${C_DIM}  would rebuild the application cache${C_RESET}"
    fi

    # OPcache lives in the web process, not this CLI one, so touching the entry
    # points is the reliable way to invalidate it on shared hosting.
    run touch "$BASE_PATH/public/index.php" "$BASE_PATH/public/admin/index.php"

    ok "caches rebuilt"
}

# ---------------------------------------------------------------------------
# Housekeeping
# ---------------------------------------------------------------------------

prune_backups() {
    step "Pruning old backups"

    # The same default as config/app.php (backup.retention_days), which is
    # the window the privacy policy states for database backups.
    local retention
    retention="$(grep -E '^BACKUP_RETENTION_DAYS=' "$BASE_PATH/.env" | head -1 | cut -d= -f2- | tr -d '"' || true)"
    retention="${retention:-14}"

    local removed=0
    if [ -d "$BASE_PATH/storage/backups" ] && [ "$DRY_RUN" -eq 0 ]; then
        while IFS= read -r old; do
            rm -f "$old"
            removed=$((removed + 1))
        done < <(find "$BASE_PATH/storage/backups" -name '*.sql.gz' -type f -mtime "+$retention" 2>/dev/null)
    fi

    ok "removed $removed backup(s) older than ${retention} days"
}

verify() {
    step "Post-deploy verification"

    if [ "$DRY_RUN" -eq 1 ]; then
        log "${C_DIM}  would verify the health endpoint${C_RESET}"
        return
    fi

    local url
    url="$(grep -E '^APP_URL=' "$BASE_PATH/.env" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"

    if [ -z "$url" ]; then
        warn "APP_URL not set; skipping the HTTP check"
        return
    fi

    local health="${url%/}/api/v1/health"
    local status
    status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$health" 2>/dev/null || echo '000')"

    if [ "$status" = "200" ]; then
        ok "health check passed ($health)"
    else
        warn "health check returned HTTP $status for $health"
        warn "the deploy completed, but verify the site manually before announcing it"
    fi
}

summary() {
    log ""
    log "${C_GREEN}Deployment complete.${C_RESET}"
    log ""
    log "  Admin panel : $(grep -E '^ADMIN_URL=' "$BASE_PATH/.env" | head -1 | cut -d= -f2- | tr -d '\"' || echo '(set ADMIN_URL)')"
    log "  API base    : $(grep -E '^API_URL=' "$BASE_PATH/.env" | head -1 | cut -d= -f2- | tr -d '\"' || echo '(set API_URL)')"
    log ""
    log "  Cron jobs — register in hPanel > Advanced > Cron Jobs. Either one entry that"
    log "  starts each job when its schedule (System > Jobs) comes round:"
    log "    * * * * *      $PHP_BIN $SCRIPT_DIR/cron.php --due"
    log "  or one entry per job:"

    # Printed from the job registry and the scheduled_jobs table, so a job
    # added by an upgrade is never missing from this list.
    local crontab_lines
    if crontab_lines="$("$PHP_BIN" "$SCRIPT_DIR/cron.php" --crontab 2>/dev/null)" && [ -n "$crontab_lines" ]; then
        printf '%s\n' "$crontab_lines" | sed 's/^/    /' | tee -a "$DEPLOY_LOG"
    else
        warn "could not print the job list; the schedules are in docs/DEPLOYMENT.md (Cron jobs)"
    fi
    log ""
    log "  Rollback if needed:  $SCRIPT_DIR/deploy.sh --rollback"
    log ""
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

if [ "$ROLLBACK" -eq 1 ]; then
    rollback
    warm_caches
    exit 0
fi

[ "$DRY_RUN" -eq 1 ] && warn "dry run — nothing will be changed"

preflight
lint
backup

maintenance_on
migrate
maintenance_off

permissions
warm_caches
prune_backups
verify
summary
