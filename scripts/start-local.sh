#!/usr/bin/env bash
#
# Starts the local dev stack: API server, queue worker and Reverb.
# Ctrl+C stops all of them. Logs: storage/logs/{serve,queue,reverb}.log
#
#   bash scripts/start-local.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

APP_PORT=${APP_PORT:-8000}
REVERB_PORT=$(grep -E '^REVERB_SERVER_PORT=' .env 2>/dev/null | cut -d= -f2 || true)
REVERB_PORT=${REVERB_PORT:-8080}
LOG_DIR=storage/logs
mkdir -p "$LOG_DIR"

# Make sure MySQL and Redis are up (no-op when already running).
if [ "$(id -u)" -eq 0 ]; then SUDO=''; else SUDO='sudo'; fi
for svc in mysql mariadb redis-server; do
  if systemctl list-unit-files "$svc.service" >/dev/null 2>&1 && ! systemctl is-active --quiet "$svc"; then
    $SUDO systemctl start "$svc" || true
  fi
done

pids=()
cleanup() {
  echo; echo "Stopping..."
  kill "${pids[@]}" 2>/dev/null || true
  wait 2>/dev/null || true
}
trap cleanup EXIT INT TERM

php artisan serve --host=127.0.0.1 --port="$APP_PORT" > "$LOG_DIR/serve.log" 2>&1 &
pids+=($!)
php artisan queue:listen --tries=1 > "$LOG_DIR/queue.log" 2>&1 &
pids+=($!)
php artisan reverb:start --host=0.0.0.0 --port="$REVERB_PORT" > "$LOG_DIR/reverb.log" 2>&1 &
pids+=($!)

sleep 2
for pid in "${pids[@]}"; do
  kill -0 "$pid" 2>/dev/null || { echo "A process failed to start. Check $LOG_DIR/{serve,queue,reverb}.log" >&2; exit 1; }
done

cat <<EOF

  Running:
    API      http://127.0.0.1:${APP_PORT}
    Reverb   ws://127.0.0.1:${REVERB_PORT}
    Queue    php artisan queue:listen
  Logs: ${LOG_DIR}/serve.log, queue.log, reverb.log, laravel.log

  Press Ctrl+C to stop.
EOF

wait -n "${pids[@]}"
echo "A process exited. Check ${LOG_DIR}/ for details." >&2
