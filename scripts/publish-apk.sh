#!/usr/bin/env bash
# Publish a freshly built APK as backend/laravel/public/downloads/app-latest.apk
# (served by /download), keeping the previous one as app-latest.apk.bak-<timestamp>.
#
# Usage: bash scripts/publish-apk.sh <path-to-new.apk>
#
# Settings (environment variables, all optional):
#   WEB_USER      Owner of the published APK when run as root (default: www-data)
#   KEEP_BACKUPS  Number of app-latest.apk.bak-* files to keep (default: 10)
set -euo pipefail

NEW_APK=${1:?usage: publish-apk.sh <path-to-new.apk>}
WEB_USER=${WEB_USER:-www-data}
KEEP_BACKUPS=${KEEP_BACKUPS:-10}

ROOT=$(cd "$(dirname "$0")/.." && pwd)
DIR="$ROOT/backend/laravel/public/downloads"
TARGET="$DIR/app-latest.apk"

[ -s "$NEW_APK" ] || { echo "APK not found or empty: $NEW_APK" >&2; exit 1; }
# An APK is a zip: refuse anything that is not, so a broken upload never replaces a good build.
[ "$(head -c 2 "$NEW_APK")" = "PK" ] || { echo "Not an APK (zip) file: $NEW_APK" >&2; exit 1; }

mkdir -p "$DIR"
if [ -f "$TARGET" ]; then
    cp -p "$TARGET" "$TARGET.bak-$(date +%Y%m%d%H%M%S)"
fi

# Copy next to the target, then rename: the download route never sees a half-written file.
cp "$NEW_APK" "$TARGET.tmp"
chmod 644 "$TARGET.tmp"
[ "$(id -u)" -eq 0 ] && chown "$WEB_USER":"$WEB_USER" "$TARGET.tmp"
mv -f "$TARGET.tmp" "$TARGET"

# Prune old backups (names sort chronologically).
ls -1 "$DIR"/app-latest.apk.bak-* 2>/dev/null | sort | head -n -"$KEEP_BACKUPS" | xargs -r rm -f

echo "Published $(basename "$NEW_APK") -> $TARGET ($(stat -c %s "$TARGET") bytes)"
