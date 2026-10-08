#!/usr/bin/env bash
# Production deploy of the Laravel backend on the VPS (nginx + php-fpm + supervisor).
# Run from the repository root after the code has been updated, e.g. by
# .github/workflows/ci-cd.yml:  git reset --hard origin/master && bash scripts/deploy.sh
#
# Settings (environment variables, all optional):
#   PHP_BIN              PHP binary                      (default: php)
#   COMPOSER_BIN         Composer binary                 (default: composer)
#   PHP_FPM_SERVICE      systemd unit reloaded after deploy, e.g. php8.3-fpm; empty = skip
#   SUPERVISOR_PROGRAMS  supervisor programs restarted after deploy
#                        (default: "remote-recorder-reverb remote-recorder-queue:*")
#   WEB_USER             Owner of storage and bootstrap/cache when deploying as root
#                        (default: www-data, the php-fpm user)
#
# Do NOT run `php artisan test` here: ApkDownloadTest deletes
# public/downloads/app-latest.apk, i.e. the published production APK.
set -euo pipefail

PHP_BIN=${PHP_BIN:-php}
COMPOSER_BIN=${COMPOSER_BIN:-composer}
PHP_FPM_SERVICE=${PHP_FPM_SERVICE:-}
SUPERVISOR_PROGRAMS=${SUPERVISOR_PROGRAMS:-"remote-recorder-reverb remote-recorder-queue:*"}
WEB_USER=${WEB_USER:-www-data}

# Deploying as root: allow Composer plugins (Laravel's package discovery needs them).
[ "$(id -u)" -eq 0 ] && export COMPOSER_ALLOW_SUPERUSER=1

ROOT=$(cd "$(dirname "$0")/.." && pwd)
log() { printf '\n==> %s\n' "$*"; }
as_root() { if [ "$(id -u)" -eq 0 ]; then "$@"; else sudo -n "$@"; fi; }

cd "$ROOT/backend/laravel"
[ -f .env ] || { echo "backend/laravel/.env is missing on the server" >&2; exit 1; }

# ffmpeg is required to merge the uploaded video parts into the final MP4
# (RecordingFinalizationService); without it every video recording fails at
# finalization. Install it once if missing so a fresh VPS works out of the box.
if ! command -v ffmpeg >/dev/null 2>&1; then
    log "install ffmpeg (required for video merge)"
    as_root apt-get update -qq
    as_root apt-get install -y --no-install-recommends ffmpeg
fi

log "composer install"
"$COMPOSER_BIN" install --no-dev --no-interaction --prefer-dist --optimize-autoloader

log "migrate"
"$PHP_BIN" artisan migrate --force

log "cache config/routes/views"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan optimize

if [ "$(id -u)" -eq 0 ]; then
    # Files artisan just created as root (logs, caches) must stay writable for php-fpm.
    chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache
fi

if [ -n "$PHP_FPM_SERVICE" ]; then
    log "reload $PHP_FPM_SERVICE (clears OPcache)"
    as_root systemctl reload "$PHP_FPM_SERVICE"
fi

if [ -n "$SUPERVISOR_PROGRAMS" ]; then
    # Reverb is a long-running process that keeps the old code in memory; the
    # queue worker too. Restart both so they pick up the new release.
    log "restart supervisor programs: $SUPERVISOR_PROGRAMS"
    # shellcheck disable=SC2086
    as_root supervisorctl restart $SUPERVISOR_PROGRAMS
fi

log "Deploy finished: $(git -C "$ROOT" rev-parse --short HEAD)"
