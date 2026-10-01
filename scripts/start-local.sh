#!/usr/bin/env bash
#
# Starts the local dev stack. nginx, PHP-FPM, MySQL and Redis are system
# services (started if stopped); the queue worker and Reverb run in this
# terminal. Ctrl+C stops them. Logs: storage/logs/{queue,reverb}.log
#
#   bash scripts/start-local.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

env_value() { grep -E "^$1=" .env 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '"' || true; }

APP_URL=$(env_value APP_URL)
REVERB_PORT=$(env_value REVERB_SERVER_PORT)
REVERB_PORT=${REVERB_PORT:-8080}
# Every queue the app dispatches to (mirrors config/horizon.php).
QUEUES=default,notifications,whatsapp,emails,mail,printing,provisioning,assets,delivery,monitoring,analytics,reports,backup
LOG_DIR=storage/logs
mkdir -p "$LOG_DIR"

if [ "$(id -u)" -eq 0 ]; then SUDO=''; else SUDO='sudo'; fi
for svc in mysql mariadb redis-server php8.4-fpm nginx; do
  if systemctl cat "$svc.service" >/dev/null 2>&1 && ! systemctl is-active --quiet "$svc"; then
    echo "Starting $svc..."
    $SUDO systemctl start "$svc" || true
  fi
done

pids=()
cleanup() {
  echo; echo "Stopping queue worker and Reverb..."
  kill "${pids[@]}" 2>/dev/null || true
  wait 2>/dev/null || true
}
trap cleanup EXIT INT TERM

php artisan queue:listen --queue="$QUEUES" --tries=1 > "$LOG_DIR/queue.log" 2>&1 &
pids+=($!)
php artisan reverb:start --host=0.0.0.0 --port="$REVERB_PORT" > "$LOG_DIR/reverb.log" 2>&1 &
pids+=($!)

sleep 2
for pid in "${pids[@]}"; do
  kill -0 "$pid" 2>/dev/null || { echo "A process failed to start. Check $LOG_DIR/{queue,reverb}.log" >&2; exit 1; }
done

cat <<EOF

  Running:
    API      ${APP_URL}   (nginx + PHP-FPM)
    Reverb   ws://127.0.0.1:${REVERB_PORT}
    Queue    ${QUEUES}
  Logs: ${LOG_DIR}/queue.log, reverb.log, laravel.log, /var/log/nginx/error.log

  Press Ctrl+C to stop the queue worker and Reverb (nginx keeps running).
EOF

wait -n "${pids[@]}"
echo "A process exited. Check ${LOG_DIR}/ for details." >&2
