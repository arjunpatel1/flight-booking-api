#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root: sudo $0"
  exit 1
fi

APP_DIR="${APP_DIR:-/var/www/restaurant-pos-api}"
APP_USER="${APP_USER:-www-data}"
AGENT_ID="${AGENT_ID:-}"
BRANCH_ID="${BRANCH_ID:-}"
SERVER_URL="${SERVER_URL:-}"
AGENT_SECRET="${AGENT_SECRET:-}"
PRINTER_QUEUE="${PRINTER_QUEUE:-POS-80}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
SLEEP_SECONDS="${SLEEP_SECONDS:-1}"

if [[ -z "${AGENT_ID}" || -z "${BRANCH_ID}" || -z "${SERVER_URL}" || -z "${AGENT_SECRET}" ]]; then
  echo "AGENT_ID, BRANCH_ID, SERVER_URL and AGENT_SECRET are required."
  echo "Example:"
  echo "  sudo AGENT_ID=AGENT-xxxx BRANCH_ID=1 SERVER_URL=https://api.example.com/api/v1 AGENT_SECRET=secret APP_DIR=/var/www/restaurant-pos-api PRINTER_QUEUE=POS-80 $0"
  exit 1
fi

if [[ ! -f "${APP_DIR}/artisan" ]]; then
  echo "Laravel artisan file not found at ${APP_DIR}/artisan"
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y \
  cups \
  cups-client \
  printer-driver-escpr \
  supervisor \
  chromium \
  fonts-dejavu-core \
  fonts-noto-core \
  fontconfig \
  php-cli \
  php-curl \
  php-gd \
  php-mbstring \
  php-mysql \
  php-redis \
  php-xml \
  php-zip

systemctl enable --now cups
systemctl enable --now supervisor

usermod -aG lp,lpadmin "${APP_USER}" || true

if ! lpstat -p "${PRINTER_QUEUE}" >/dev/null 2>&1; then
  echo "Printer queue '${PRINTER_QUEUE}' was not found."
  echo "Create/check it first, for example:"
  echo "  sudo lpadmin -p ${PRINTER_QUEUE} -E -v usb://... -m raw"
  echo "Detected devices:"
  lpinfo -v || true
  exit 1
fi

install -d -m 0755 /var/log/nexdine
chown "${APP_USER}:${APP_USER}" /var/log/nexdine || true

cat >/etc/supervisor/conf.d/nexdine-print-agent.conf <<CONF
[program:nexdine-print-agent]
process_name=%(program_name)s
directory=${APP_DIR}
command=${PHP_BIN} artisan printer:agent-work --agent_id=${AGENT_ID} --branch_id=${BRANCH_ID} --server_url=${SERVER_URL} --agent_secret=${AGENT_SECRET} --printer_queue=${PRINTER_QUEUE} --sleep=${SLEEP_SECONDS}
autostart=true
autorestart=true
startsecs=3
stopwaitsecs=10
user=${APP_USER}
redirect_stderr=true
stdout_logfile=/var/log/nexdine/print-agent.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
environment=HOME="${APP_DIR}",PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
CONF

supervisorctl reread
supervisorctl update
supervisorctl restart nexdine-print-agent

echo
echo "Silent print agent installed."
echo "Status:"
supervisorctl status nexdine-print-agent || true
echo
echo "Printer queue:"
lpstat -p "${PRINTER_QUEUE}" -l || true
