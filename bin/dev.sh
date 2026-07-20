#!/usr/bin/env bash
#
# dev.sh — fast frontend loop against the running prod container, no image rebuild.
#
# Starts a host Vite dev server (HMR) and points the running Laravel container at
# it via public/hot. Edit .vue/.ts → HMR picks it up instantly. Ctrl+C cleans up
# (removes the hot file so the container falls back to its baked assets).
#
# PHP changes are NOT covered — the container still runs its baked backend.
#
# Env overrides:
#   APP_CONTAINER   docker container name        (default: solidtime-app-1)
#   VITE_PORT       host vite port               (default: 5173)
#   HOT_URL         url written into public/hot  (default: http://localhost:$VITE_PORT)

set -euo pipefail

APP_CONTAINER="${APP_CONTAINER:-solidtime-app-1}"
VITE_PORT="${VITE_PORT:-5173}"
HOT_URL="${HOT_URL:-http://localhost:${VITE_PORT}}"
HOT_PATH="/var/www/html/public/hot"

cd "$(dirname "$0")/.."

# --- preflight ---------------------------------------------------------------
command -v docker >/dev/null || { echo "docker not found"; exit 1; }
docker info >/dev/null 2>&1 || { echo "docker daemon not running — start Docker Desktop"; exit 1; }
docker inspect "$APP_CONTAINER" >/dev/null 2>&1 \
  || { echo "container '$APP_CONTAINER' not found (set APP_CONTAINER=...)"; exit 1; }
[ -d node_modules ] || { echo "node_modules missing — run: npm install"; exit 1; }

VITE_PID=""

cleanup() {
  echo ""
  echo "[dev] cleaning up…"
  docker exec -u root "$APP_CONTAINER" rm -f "$HOT_PATH" 2>/dev/null \
    && echo "[dev] removed hot file → container back on baked assets"
  if [ -n "$VITE_PID" ] && kill -0 "$VITE_PID" 2>/dev/null; then
    kill "$VITE_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT INT TERM

# --- start vite --------------------------------------------------------------
echo "[dev] starting Vite on :${VITE_PORT} …"
VITE_LOCAL_DEV=1 npm run dev &
VITE_PID=$!

# wait until vite answers
for _ in $(seq 1 30); do
  if curl -sf -o /dev/null "http://localhost:${VITE_PORT}/@vite/client"; then
    break
  fi
  kill -0 "$VITE_PID" 2>/dev/null || { echo "[dev] vite exited early — see output above"; exit 1; }
  sleep 1
done
curl -sf -o /dev/null "http://localhost:${VITE_PORT}/@vite/client" \
  || { echo "[dev] vite did not become ready in time"; exit 1; }

# --- point container at the dev server ---------------------------------------
docker exec -u root "$APP_CONTAINER" sh -c "printf '%s' '${HOT_URL}' > '${HOT_PATH}'"
echo "[dev] hot file written → ${APP_CONTAINER}:${HOT_PATH} = ${HOT_URL}"
echo "[dev] ready. App serves dev assets with HMR. Ctrl+C to stop & restore."

# keep running until vite dies or user Ctrl+C
wait "$VITE_PID"
