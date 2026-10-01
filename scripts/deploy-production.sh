#!/usr/bin/env bash
#
# Production deploy for the NexDine API.
# Run from anywhere: bash restaurant-pos-api/scripts/deploy-production.sh
#
# Bakes in the launch-blocker requirements:
#   - refuses to deploy with APP_DEBUG=true            (P1-6)
#   - applies pending migrations                       (P1-7)
#   - clears the cache so new translation keys resolve (P1-8)
#   - restarts queue workers so new code is picked up  (P1-9)
#   - reminds the operator to keep queue:work + reverb supervised
#
set -euo pipefail
cd "$(dirname "$0")/.."

APP_RUNTIME_USER="${APP_RUNTIME_USER:-www-data}"

run_artisan() {
  if [ "$(id -u)" -eq 0 ]; then
    runuser --user "$APP_RUNTIME_USER" -- php artisan "$@"
    return
  fi

  php artisan "$@"
}

echo "==> NexDine API production deploy"

if [ "$(id -u)" -eq 0 ] && ! id "$APP_RUNTIME_USER" >/dev/null 2>&1; then
  echo "ABORT: application runtime user '$APP_RUNTIME_USER' does not exist." >&2
  exit 1
fi

# --- Safety gate: never serve production with debug on -----------------------
if [ -f .env ] && grep -qE '^APP_DEBUG=true' .env; then
  echo "ABORT: APP_DEBUG=true in .env. Set APP_DEBUG=false before deploying." >&2
  exit 1
fi
if [ -f .env ] && grep -qE '^MAIL_MAILER=log' .env; then
  echo "WARNING: MAIL_MAILER=log — notification email will be logged, not sent." >&2
fi

run_artisan down --retry=15 || true
trap 'run_artisan up || true' EXIT

# --- Schema + caches ---------------------------------------------------------
run_artisan migrate --force                 # P1-7: apply pending migrations
# Built-in role definitions evolve with the API. Existing installations must
# receive new permissions (for example waiter print access) during upgrades.
run_artisan permission:sync-permissions
run_artisan permission:sync-default-roles --force
run_artisan cache:clear                     # P1-8: refresh cached translations (new lang keys)
run_artisan config:cache
run_artisan route:cache
run_artisan event:cache || true
run_artisan view:cache || true

# The compiled configuration can contain credentials. Keep it private to the
# PHP-FPM runtime account while still allowing that account to serve requests.
if [ -f bootstrap/cache/config.php ]; then
  chmod 0640 bootstrap/cache/config.php
fi

# Laravel cache files must remain readable by PHP-FPM. Running Artisan as root
# can otherwise create bootstrap/cache/config.php as root:root with mode 0600,
# taking every HTTP endpoint offline before middleware or error logging starts.
if [ "$(id -u)" -eq 0 ]; then
  runuser --user "$APP_RUNTIME_USER" -- test -r bootstrap/cache/config.php || {
    echo "ABORT: generated config cache is not readable by '$APP_RUNTIME_USER'." >&2
    exit 1
  }
fi

# --- Workers -----------------------------------------------------------------
# Restart conventional queue workers and ask the long-running Horizon master to
# terminate gracefully. Supervisor then starts a fresh Horizon process with the
# newly deployed code.
run_artisan queue:restart                    # P1-9: reload queue workers with new code
run_artisan horizon:terminate || true

run_artisan up
trap - EXIT

echo "==> Deploy complete."
echo "    Verify these processes are supervised (systemd/supervisor):"
echo "      - php artisan queue:work        (print jobs, provisioning, email)"
echo "      - php artisan reverb:start      (realtime order/KOT broadcasts)"
