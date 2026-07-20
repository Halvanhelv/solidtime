#!/usr/bin/env bash
#
# dev-php.sh — run PHP/artisan/phpunit against the running solidtime stack,
# using host source + host vendor (which has dev deps) in a throwaway container
# from the prod image. Does not disturb the running app container.
#
#   bin/dev-php.sh php artisan migrate
#   bin/dev-php.sh ./vendor/bin/phpunit --filter=feature_visibility
#   bin/dev-php.sh php artisan model:typer   # (generate-typescript)
#
# Deploy changed backend PHP into the RUNNING app container (the prod image is
# baked, so frontend HMR via bin/dev.sh does NOT cover PHP changes):
#
#   bin/dev-php.sh deploy app/Models/User.php app/Enums/HideableNavItem.php ...
#
# `deploy` copies each file into the app container, regenerates the optimized
# autoloader (REQUIRED for any NEW class — the prod image's classmap won't find
# a cp'd class otherwise, causing "Class not found" 500s), restarts the app to
# clear opcache/Octane workers, and restores the Vite hot file.
#
# Env overrides: IMAGE, NETWORK, ENV_FILE, APP_CONTAINER, HOT_URL.

set -euo pipefail

IMAGE="${IMAGE:-solidtime-selfbuild:test}"
NETWORK="${NETWORK:-solidtime_internal}"
# Path to the Kamal deploy dir's laravel.env (sibling checkout by default).
# Override with ENV_FILE=/path/to/laravel.env if your layout differs.
ENV_FILE="${ENV_FILE:-../solidtime/laravel.env}"
APP_CONTAINER="${APP_CONTAINER:-solidtime-app-1}"
HOT_URL="${HOT_URL:-http://localhost:5173}"

cd "$(dirname "$0")/.."

# --- deploy mode -------------------------------------------------------------
if [ "${1:-}" = "deploy" ]; then
  shift
  [ $# -gt 0 ] || { echo "usage: bin/dev-php.sh deploy <file> [file...]"; exit 1; }
  for f in "$@"; do
    [ -f "$f" ] || { echo "[deploy] not a file: $f"; exit 1; }
    docker cp "$f" "${APP_CONTAINER}:/var/www/html/$f" >/dev/null
    echo "[deploy] copied $f"
  done
  echo "[deploy] regenerating autoloader (picks up new classes)…"
  docker exec "$APP_CONTAINER" sh -c 'cd /var/www/html && composer dump-autoload' >/dev/null
  echo "[deploy] restarting $APP_CONTAINER …"
  docker restart "$APP_CONTAINER" >/dev/null
  for _ in $(seq 1 20); do
    [ "$(docker inspect "$APP_CONTAINER" --format '{{.State.Health.Status}}' 2>/dev/null)" = healthy ] && break
    sleep 3
  done
  # Restore the Vite hot file (a restart wipes it), so the frontend keeps
  # serving dev assets if bin/dev.sh is running.
  docker exec -u root "$APP_CONTAINER" sh -c "printf '%s' '${HOT_URL}' > /var/www/html/public/hot" 2>/dev/null || true
  echo "[deploy] done — $APP_CONTAINER healthy, hot file restored."
  exit 0
fi

# --- one-off command mode ----------------------------------------------------
exec docker run --rm \
  --network "$NETWORK" \
  -v "$PWD":/var/www/html \
  -w /var/www/html \
  --env-file "$ENV_FILE" \
  -e DB_TEST_HOST=database \
  -e DB_TEST_PORT=5432 \
  -e DB_TEST_DATABASE=solidtime_testing \
  -e DB_TEST_USERNAME=solidtime \
  -e DB_TEST_PASSWORD=solidtimesecret \
  --entrypoint "$1" \
  "$IMAGE" "${@:2}"
