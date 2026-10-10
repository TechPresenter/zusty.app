#!/usr/bin/env bash
#
# Zusty backup script — database and uploaded files.
#
#   ./scripts/backup.sh                    database dump, gzipped
#   ./scripts/backup.sh --files            also archive public/uploads
#   ./scripts/backup.sh --tag pre-deploy   label the dump
#   ./scripts/backup.sh --verify           gunzip -t and a structure check
#   ./scripts/backup.sh --list             list existing backups
#   ./scripts/backup.sh --prune            delete dumps past the retention window
#   ./scripts/backup.sh --quiet            only report failures
#
# Designed for Hostinger cron:
#   0 2 * * *  /usr/bin/bash /home/USER/domains/DOMAIN/backend/scripts/backup.sh --files --prune
#
# Credentials are read from .env and passed to mysqldump through a temporary
# defaults file, never on the command line, because arguments are visible to
# every other user on a shared host via `ps`.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_PATH="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$BASE_PATH/.env"

WITH_FILES=0
DO_VERIFY=0
DO_LIST=0
DO_PRUNE=0
QUIET=0
TAG="scheduled"

# True when --prune is the only action asked for, so the script prunes and stops
# instead of also writing a new dump.
PRUNE_ONLY=0
[ "$#" -gt 0 ] && PRUNE_ONLY=1
for argument in "$@"; do
    case "$argument" in
        --prune|--quiet) ;;
        *) PRUNE_ONLY=0 ;;
    esac
done
case " $* " in *" --prune "*) ;; *) PRUNE_ONLY=0 ;; esac

# ---------------------------------------------------------------------------

while [ $# -gt 0 ]; do
    case "$1" in
        --files)  WITH_FILES=1 ;;
        --verify) DO_VERIFY=1 ;;
        --list)   DO_LIST=1 ;;
        --prune)  DO_PRUNE=1 ;;
        --quiet)  QUIET=1 ;;
        --tag)    shift; TAG="${1:-manual}" ;;
        -h|--help) sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
    shift
done

say()  { [ "$QUIET" -eq 1 ] || printf '%s\n' "$*"; }
warn() { printf '%s\n' "$*" >&2; }
die()  { printf 'backup: %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Read a key from .env without sourcing it — sourcing would execute anything
# in there and would choke on values containing spaces or '#'.
# ---------------------------------------------------------------------------

env_get() {
    local key="$1" default="${2:-}" line value exported

    # An exported shell variable wins, so a cron entry or CI job can override a
    # single value without editing .env.
    exported="$(printf '%s' "${!key:-}")"
    [ -n "$exported" ] && { printf '%s' "$exported"; return; }

    [ -f "$ENV_FILE" ] || { printf '%s' "$default"; return; }

    line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" | tail -1 || true)"
    [ -n "$line" ] || { printf '%s' "$default"; return; }

    value="${line#*=}"
    value="${value%$'\r'}"                       # strip a Windows line ending
    value="$(printf '%s' "$value" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    value="${value%\"}"; value="${value#\"}"     # strip surrounding quotes
    value="${value%\'}"; value="${value#\'}"

    printf '%s' "${value:-$default}"
}

DB_HOST="$(env_get DB_HOST 127.0.0.1)"
DB_PORT="$(env_get DB_PORT 3306)"
DB_NAME="$(env_get DB_NAME)"
DB_USER="$(env_get DB_USER)"
DB_PASSWORD="$(env_get DB_PASSWORD)"
BACKUP_DIR="$(env_get BACKUP_PATH "$BASE_PATH/storage/backups")"
RETENTION="$(env_get BACKUP_RETENTION_DAYS 30)"
MYSQLDUMP="$(env_get MYSQLDUMP_PATH mysqldump)"

case "$BACKUP_DIR" in
    /*) ;;                                   # already absolute
    *)  BACKUP_DIR="$BASE_PATH/$BACKUP_DIR" ;;
esac

mkdir -p "$BACKUP_DIR"

# ---------------------------------------------------------------------------
# --list / --prune act alone.
# ---------------------------------------------------------------------------

if [ "$DO_LIST" -eq 1 ]; then
    printf '%-46s %10s  %s\n' 'FILE' 'SIZE' 'MODIFIED'
    found=0
    # A temp file, not `< <(find …)`: process substitution needs /dev/fd,
    # which Hostinger's shell does not have (the loop silently read nothing).
    list="$(mktemp "${TMPDIR:-/tmp}/zuste-backups.XXXXXX")"
    find "$BACKUP_DIR" -maxdepth 1 -name '*.sql.gz' -type f 2>/dev/null | sort -r > "$list" || true
    while IFS= read -r file; do
        found=1
        printf '%-46s %10s  %s\n' \
            "$(basename "$file")" \
            "$(du -h "$file" | cut -f1)" \
            "$(date -r "$file" '+%Y-%m-%d %H:%M' 2>/dev/null || echo '?')"
    done < "$list"
    rm -f "$list"
    [ "$found" -eq 1 ] || echo '(no backups yet)'
    exit 0
fi

prune() {
    local removed=0 list
    list="$(mktemp "${TMPDIR:-/tmp}/zuste-prune.XXXXXX")"
    find "$BACKUP_DIR" -maxdepth 1 \( -name '*.sql.gz' -o -name '*.tar.gz' \) \
         -type f -mtime "+$RETENTION" 2>/dev/null > "$list" || true
    while IFS= read -r old; do
        rm -f "$old"; removed=$((removed + 1))
    done < "$list"
    rm -f "$list"
    say "  pruned $removed backup(s) older than ${RETENTION} days"
}

# "--prune" on its own means housekeeping only — no new dump.
if [ "$PRUNE_ONLY" -eq 1 ]; then
    prune
    exit 0
fi

# ---------------------------------------------------------------------------
# Preconditions
# ---------------------------------------------------------------------------

[ -n "$DB_NAME" ] || die "DB_NAME is not set in .env"
[ -n "$DB_USER" ] || die "DB_USER is not set in .env"

command -v "$MYSQLDUMP" >/dev/null 2>&1 \
    || die "mysqldump not found at '$MYSQLDUMP'. Set MYSQLDUMP_PATH in .env."

command -v gzip >/dev/null 2>&1 || die "gzip not found"

# ---------------------------------------------------------------------------
# Credentials file — 600, in a private temp dir, removed on every exit path.
# ---------------------------------------------------------------------------

DEFAULTS_FILE="$(mktemp "${TMPDIR:-/tmp}/zuste-my.XXXXXXXX")"
chmod 600 "$DEFAULTS_FILE"

cleanup() {
    rm -f "$DEFAULTS_FILE"
    [ -n "${PARTIAL:-}" ] && [ -f "${PARTIAL}" ] && rm -f "$PARTIAL"
    return 0
}
trap cleanup EXIT INT TERM

{
    printf '[client]\n'
    printf 'user=%s\n' "$DB_USER"
    printf 'password="%s"\n' "$DB_PASSWORD"
    printf 'host=%s\n' "$DB_HOST"
    printf 'port=%s\n' "$DB_PORT"
} > "$DEFAULTS_FILE"

# ---------------------------------------------------------------------------
# Dump
# ---------------------------------------------------------------------------

STAMP="$(date '+%Y%m%d-%H%M%S')"
SAFE_TAG="$(printf '%s' "$TAG" | tr -cd '[:alnum:]._-')"
TARGET="$BACKUP_DIR/${DB_NAME}-${SAFE_TAG}-${STAMP}.sql.gz"
PARTIAL="${TARGET}.partial"

say "Backing up ${DB_NAME} -> $(basename "$TARGET")"

# --single-transaction gives a consistent snapshot of InnoDB tables without
# locking writers, which matters because this runs against the live site.
# --routines/--triggers/--events keep stored objects. --hex-blob keeps binary
# columns intact through the text dump.
if ! "$MYSQLDUMP" \
        --defaults-extra-file="$DEFAULTS_FILE" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        --hex-blob \
        --default-character-set=utf8mb4 \
        --add-drop-table \
        --set-gtid-purged=OFF \
        "$DB_NAME" 2>"${PARTIAL}.err" | gzip -9 > "$PARTIAL"; then

    # --set-gtid-purged is MySQL-only; MariaDB rejects it. Retry without it
    # rather than failing the nightly backup on a MariaDB host.
    if grep -qi 'set-gtid-purged\|unknown option' "${PARTIAL}.err" 2>/dev/null; then
        say "  retrying without --set-gtid-purged (MariaDB)"
        "$MYSQLDUMP" \
            --defaults-extra-file="$DEFAULTS_FILE" \
            --single-transaction --quick --routines --triggers --events \
            --hex-blob --default-character-set=utf8mb4 --add-drop-table \
            "$DB_NAME" 2>"${PARTIAL}.err" | gzip -9 > "$PARTIAL" \
            || { warn "mysqldump failed:"; cat "${PARTIAL}.err" >&2; rm -f "${PARTIAL}.err"; exit 1; }
    else
        warn "mysqldump failed:"
        cat "${PARTIAL}.err" >&2
        rm -f "${PARTIAL}.err"
        exit 1
    fi
fi

rm -f "${PARTIAL}.err"

# A dump that gunzip cannot read is worse than no dump, because it looks like
# protection. Check before publishing the file under its final name.
gunzip -t "$PARTIAL" 2>/dev/null || die "the dump failed its integrity check"

SIZE_BYTES="$(wc -c < "$PARTIAL" | tr -d ' ')"
[ "$SIZE_BYTES" -gt 1024 ] || die "the dump is suspiciously small (${SIZE_BYTES} bytes)"

# Publish atomically so a cron that runs mid-write never leaves a half file.
mv "$PARTIAL" "$TARGET"
PARTIAL=""
chmod 600 "$TARGET"

say "  database  $(du -h "$TARGET" | cut -f1)"

# ---------------------------------------------------------------------------
# Files
# ---------------------------------------------------------------------------

if [ "$WITH_FILES" -eq 1 ]; then
    UPLOADS="$BASE_PATH/public/uploads"

    if [ -d "$UPLOADS" ]; then
        FILES_TARGET="$BACKUP_DIR/uploads-${SAFE_TAG}-${STAMP}.tar.gz"

        if tar -czf "${FILES_TARGET}.partial" -C "$BASE_PATH/public" uploads 2>/dev/null; then
            mv "${FILES_TARGET}.partial" "$FILES_TARGET"
            chmod 600 "$FILES_TARGET"
            say "  uploads   $(du -h "$FILES_TARGET" | cut -f1)"
        else
            rm -f "${FILES_TARGET}.partial"
            warn "  uploads archive failed (the database dump is still good)"
        fi
    else
        say "  uploads   (nothing to archive)"
    fi
fi

# ---------------------------------------------------------------------------
# Verify
# ---------------------------------------------------------------------------

if [ "$DO_VERIFY" -eq 1 ]; then
    say "Verifying"

    gunzip -t "$TARGET" || die "gzip integrity check failed"

    TABLES="$(gunzip -c "$TARGET" | grep -c '^CREATE TABLE' || true)"
    say "  gzip ok, ${TABLES} CREATE TABLE statement(s)"

    [ "$TABLES" -gt 0 ] || die "the dump contains no tables"

    gunzip -c "$TARGET" | tail -5 | grep -qi 'Dump completed' \
        && say "  dump completed marker present" \
        || warn "  no 'Dump completed' marker — the dump may be truncated"
fi

[ "$DO_PRUNE" -eq 1 ] && prune

say "Done."
