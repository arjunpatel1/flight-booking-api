#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" =~ ^(-h|--help)$ ]]; then
  cat <<'EOF'
Usage: ./scripts/start-realtime.sh

Starts the local Reverb socket server and Laravel Octane together.

Environment variables:
  REVERB_HOST        advertised socket host, default: 127.0.0.1
  REVERB_SERVER_HOST bind host, default: 0.0.0.0
  REVERB_PORT        default: 6001
  OCTANE_SERVER default: frankenphp
  OCTANE_HOST   default: 127.0.0.1
  OCTANE_PORT   default: 8000
  LOG_DIR       default: storage/logs
EOF
  exit 0
fi

cd "$(dirname "$0")/.."

if [[ -f .env ]]; then
  set -o allexport
  # shellcheck disable=SC1091
  source .env
  set +o allexport
fi

REVERB_HOST=${REVERB_HOST:-127.0.0.1}
REVERB_SERVER_HOST=${REVERB_SERVER_HOST:-0.0.0.0}
REVERB_PORT=${REVERB_PORT:-6001}
OCTANE_SERVER=${OCTANE_SERVER:-frankenphp}
OCTANE_HOST=${OCTANE_HOST:-127.0.0.1}
OCTANE_PORT=${OCTANE_PORT:-8000}
LOG_DIR=${LOG_DIR:-storage/logs}

mkdir -p "$LOG_DIR"

reverb_log="$LOG_DIR/reverb.log"
octane_log="$LOG_DIR/octane.log"

reverb_pid=''
trap 'if [[ -n "${reverb_pid:-}" ]]; then echo "Stopping Reverb (${reverb_pid})"; kill "${reverb_pid}" 2>/dev/null || true; fi' EXIT INT TERM

echo "Starting Reverb server on ${REVERB_SERVER_HOST}:${REVERB_PORT}, advertised as ${REVERB_HOST} (log: ${reverb_log})"
php artisan reverb:start --host="${REVERB_SERVER_HOST}" --port="${REVERB_PORT}" > "${reverb_log}" 2>&1 &
reverb_pid=$!

sleep 1

if ! kill -0 "${reverb_pid}" 2>/dev/null; then
  echo "Reverb failed to start. Check ${reverb_log}" >&2
  exit 1
fi

echo "Starting Octane server on ${OCTANE_HOST}:${OCTANE_PORT} (log: ${octane_log})"
exec php artisan octane:start --server="${OCTANE_SERVER}" --host="${OCTANE_HOST}" --port="${OCTANE_PORT}" > "${octane_log}" 2>&1
