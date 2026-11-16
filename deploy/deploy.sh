#!/bin/bash
#
# Deploy a release beside the document root, on the host.
#
# The document root on this account is fixed at public_html, so the application lives in
# the domain directory and only public/ is published. This script swaps the application
# code in place and keeps the previous copy for a fast rollback.
#
# It runs ON THE SERVER, not on a workstation: the host has PHP CLI and Composer, and
# SSH is closed, so commands are issued through the control panel's Terminal.
#
#   deploy.sh <release-id> <artifact.zip>
#   deploy.sh --rollback <release-id>
#
# Expected layout:
#   /home/hakimevx/domains/<domain>/            APP_DIR  (src, bin, templates, routes, database, vendor, .env, var)
#   /home/hakimevx/domains/<domain>/public_html           published document root
#   /home/hakimevx/domains/<domain>/releases/<id>/        extracted artifact
#   /home/hakimevx/domains/<domain>/releases/<id>-prev/   code as it was before this release
#
# Smoke checks read STAGING_AUTH_USER / STAGING_AUTH_PASS from the environment when set,
# because staging sits behind HTTP basic auth. Production needs no credential.

set -u

APP_DIR="${APP_DIR:-/home/hakimevx/domains/staging-booking.hakimerad.com}"
PHP="${PHP:-/opt/alt/php83/usr/bin/php}"
COMPOSER="${COMPOSER:-/usr/local/bin/composer}"
URL="${URL:-https://staging-booking.hakimerad.com}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

RELEASES="$APP_DIR/releases"
PUBLIC_HTML="$APP_DIR/public_html"
LOG="$APP_DIR/var/logs/deployments.log"

CODE_DIRS="bin src templates routes database"
CODE_FILES="composer.json composer.lock"

fail() { echo "FAILED: $*" >&2; exit 1; }

stamp() { date -u +%Y-%m-%dT%H:%M:%SZ; }

smoke() {
    local path="$1" expect="$2" auth=() code
    [ -n "${STAGING_AUTH_USER:-}" ] && auth=(-u "${STAGING_AUTH_USER}:${STAGING_AUTH_PASS:-}")
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "${auth[@]}" "$URL$path")
    if [ "$code" != "$expect" ]; then
        echo "  smoke $path expected $expect, got $code"
        return 1
    fi
    echo "  smoke $path $code"
    return 0
}

rollback() {
    local id="$1"
    local prev="$RELEASES/$id-prev"
    [ -d "$prev" ] || fail "no snapshot at $prev"

    for d in $CODE_DIRS; do
        rm -rf "${APP_DIR:?}/$d"
        cp -R "$prev/$d" "$APP_DIR/$d" || fail "restore $d"
    done
    for f in $CODE_FILES; do
        [ -f "$prev/$f" ] && cp -f "$prev/$f" "$APP_DIR/$f"
    done
    [ -d "$prev/vendor" ] && { rm -rf "$APP_DIR/vendor"; cp -R "$prev/vendor" "$APP_DIR/vendor"; }

    echo "rolled back code to $id"
    "$PHP" "$APP_DIR/bin/console" migrate:status | tail -n 3
    echo "NOTE: schema changes are not reversed. If this release applied a coordinated or"
    echo "      irreversible migration, restore the pre-deployment dump as well."
    printf '%s rollback id=%s operator=%s\n' "$(stamp)" "$id" "${USER:-unknown}" >> "$LOG"
    exit 0
}

[ $# -ge 1 ] || fail "usage: deploy.sh <release-id> <artifact.zip> | deploy.sh --rollback <release-id>"
[ "$1" = "--rollback" ] && { [ $# -eq 2 ] || fail "usage: deploy.sh --rollback <release-id>"; rollback "$2"; }

[ $# -eq 2 ] || fail "usage: deploy.sh <release-id> <artifact.zip>"
ID="$1"
ZIP="$2"

[ -f "$ZIP" ] || fail "artifact not found: $ZIP"
[ -f "$APP_DIR/.env" ] || fail "no .env at $APP_DIR — refusing to deploy blind"
mkdir -p "$RELEASES" "$APP_DIR/var/logs" "$APP_DIR/var/backups" "$APP_DIR/var/cache" "$APP_DIR/var/private-uploads"

echo "== 1/7 verify artifact"
SUM=$(sha256sum "$ZIP" | cut -d' ' -f1)
echo "  $SUM  $(basename "$ZIP")"

echo "== 2/7 extract to releases/$ID"
rm -rf "${RELEASES:?}/$ID"
mkdir -p "$RELEASES/$ID"
unzip -q -o "$ZIP" -d "$RELEASES/$ID" || fail "unzip"

echo "== 3/7 compose dependencies (no dev packages)"
( cd "$RELEASES/$ID" && "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress ) | tail -n 6
[ -f "$RELEASES/$ID/vendor/autoload.php" ] || fail "vendor/autoload.php missing"
chmod +x "$RELEASES/$ID/bin/console"

echo "== 4/7 snapshot current code"
rm -rf "${RELEASES:?}/$ID-prev"
mkdir -p "$RELEASES/$ID-prev"
for d in $CODE_DIRS; do [ -d "$APP_DIR/$d" ] && cp -R "$APP_DIR/$d" "$RELEASES/$ID-prev/$d"; done
for f in $CODE_FILES; do [ -f "$APP_DIR/$f" ] && cp -f "$APP_DIR/$f" "$RELEASES/$ID-prev/$f"; done
[ -d "$APP_DIR/vendor" ] && cp -R "$APP_DIR/vendor" "$RELEASES/$ID-prev/vendor"
echo "  snapshot at releases/$ID-prev"

echo "== 5/7 publish code and assets"
for d in $CODE_DIRS; do
    rm -rf "${APP_DIR:?}/$d"
    cp -R "$RELEASES/$ID/$d" "$APP_DIR/$d" || fail "publish $d"
done
for f in $CODE_FILES; do cp -f "$RELEASES/$ID/$f" "$APP_DIR/$f"; done
rm -rf "$APP_DIR/vendor"; cp -R "$RELEASES/$ID/vendor" "$APP_DIR/vendor"

# .htaccess is owned by the environment, not by the release: staging carries basic auth
# and noindex, production does not. Preserve it across the asset copy, then publish.
mkdir -p "$PUBLIC_HTML"
HTKEEP=$(mktemp)
if [ -f "$PUBLIC_HTML/.htaccess" ]; then cp -f "$PUBLIC_HTML/.htaccess" "$HTKEEP"; fi
rm -f "$PUBLIC_HTML/index.html"
cp -R "$RELEASES/$ID/public/." "$PUBLIC_HTML/"
if [ -s "$HTKEEP" ]; then cp -f "$HTKEEP" "$PUBLIC_HTML/.htaccess"; fi
rm -f "$HTKEEP"

echo "== 6/7 migrate"
"$PHP" "$APP_DIR/bin/console" migrate 2>&1 | tail -n 12
"$PHP" "$APP_DIR/bin/console" migrate:status 2>&1 | grep -c 'yes'

echo "== 7/7 smoke"
RC=0
smoke / 200 || RC=1
smoke /healthz 200 || RC=1
smoke /login 200 || RC=1
smoke /admin 403 || RC=1

printf '%s deploy id=%s sha256=%s operator=%s result=%s\n' \
    "$(stamp)" "$ID" "$SUM" "${USER:-unknown}" "$([ $RC -eq 0 ] && echo ok || echo smoke-failed)" >> "$LOG"

# Keep the newest N snapshots; a rollback target older than that is not worth the disk.
ls -1dt "$RELEASES"/*-prev 2>/dev/null | tail -n +$((KEEP_RELEASES + 1)) | while read -r old; do rm -rf "$old"; done

if [ $RC -ne 0 ]; then
    echo "SMOKE FAILED — roll back with: deploy.sh --rollback $ID"
    exit 1
fi

echo "RELEASE $ID OK"
