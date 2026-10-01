#!/usr/bin/env bash
set -Eeuo pipefail

umask 027
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'
unset BASH_ENV ENV CDPATH GLOBIGNORE

readonly nginx_root='/etc/nginx'
readonly snippet_path="$nginx_root/snippets/nexdine-security-headers.conf"
readonly backup_root='/var/backups/nexdine'
apply=0

fail() {
  printf 'harden-nginx-headers: %s\n' "$*" >&2
  exit 1
}

if [[ ${1:-} == '--apply' ]]; then
  apply=1
  shift
fi
[[ $# -eq 0 ]] || fail 'usage: harden-nginx-headers.sh [--apply]'

mapfile -t site_files < <(
  {
    printf '%s\n' \
      "$nginx_root/sites-available/nexdine-web.conf" \
      "$nginx_root/sites-available/nexdine-api.conf"
    find "$nginx_root/conf.d" -maxdepth 1 -type f \
      \( -name '*.nexdine.myteknoland.in.conf' -o -name '*.nexdine.myteknoland.net.conf' \) \
      -print
  } | sort -u
)

[[ ${#site_files[@]} -gt 0 ]] || fail 'no NexDine Nginx sites were found'
for site_file in "${site_files[@]}"; do
  [[ -f $site_file && ! -L $site_file ]] || fail "unsafe site file: $site_file"
  [[ $(stat -c '%U:%G' "$site_file") == 'root:root' ]] || fail "site is not root-owned: $site_file"
done

if [[ $apply -eq 0 ]]; then
  printf 'mode=dry-run\nsnippet=%s\nsites=%d\n' "$snippet_path" "${#site_files[@]}"
  printf '%s\n' "${site_files[@]}"
  exit 0
fi

[[ ${EUID:-$(id -u)} -eq 0 ]] || fail 'apply mode must run as root'
for binary in /usr/sbin/nginx /usr/bin/systemctl /usr/bin/awk /usr/bin/tar /usr/bin/flock; do
  [[ -x $binary ]] || fail "required executable is missing: $binary"
done

exec 9>/run/lock/nexdine-nginx-headers.lock
/usr/bin/flock -n 9 || fail 'another Nginx hardening operation is running'

install -d -m 0700 -o root -g root "$backup_root"
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_file="$backup_root/nginx-headers-$timestamp.tar.gz"
backup_paths=()
for site_file in "${site_files[@]}"; do
  backup_paths+=("${site_file#/}")
done
snippet_was_present=0
if [[ -e $snippet_path ]]; then
  [[ -f $snippet_path && ! -L $snippet_path ]] || fail "unsafe security snippet: $snippet_path"
  [[ $(stat -c '%U:%G' "$snippet_path") == 'root:root' ]] || fail "security snippet is not root-owned"
  backup_paths+=("${snippet_path#/}")
  snippet_was_present=1
fi
/usr/bin/tar -C / -czf "$backup_file" "${backup_paths[@]}"
chmod 0600 "$backup_file"

work_dir=$(mktemp -d --tmpdir=/run nexdine-nginx-headers.XXXXXX)
trap 'rm -rf -- "$work_dir"' EXIT

snippet_tmp="$work_dir/security-headers.conf"
printf '%s\n' \
  'server_tokens off;' \
  'add_header Strict-Transport-Security "max-age=31536000" always;' \
  'add_header X-Frame-Options "SAMEORIGIN" always;' \
  'add_header X-Content-Type-Options "nosniff" always;' \
  'add_header Referrer-Policy "strict-origin-when-cross-origin" always;' \
  'add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;' \
  'add_header Content-Security-Policy "default-src '\''self'\''; base-uri '\''self'\''; object-src '\''none'\''; frame-ancestors '\''self'\''; form-action '\''self'\''; script-src '\''self'\'' '\''unsafe-inline'\'' https://checkout.razorpay.com; style-src '\''self'\'' '\''unsafe-inline'\''; img-src '\''self'\'' data: blob: https:; font-src '\''self'\'' data:; connect-src '\''self'\'' https: wss:; worker-src '\''self'\'' blob:; media-src '\''self'\'' data: blob:; frame-src '\''self'\'' https://uen.io https://*.uen.io https://maps.google.com https://www.google.com https://checkout.razorpay.com https://api.razorpay.com" always;' \
  > "$snippet_tmp"

rendered_files=()
for site_file in "${site_files[@]}"; do
  rendered="$work_dir/$(printf '%s' "$site_file" | sha256sum | cut -d' ' -f1).conf"
  /usr/bin/awk '
    function indentation(line, value) {
      match(line, /^[[:space:]]*/)
      value = substr(line, RSTART, RLENGTH)
      return value
    }
    function emit_pending() {
      if (pending) {
        print pending_indent "include snippets/nexdine-security-headers.conf;"
        pending = 0
      }
    }
    /^[[:space:]]*server_tokens[[:space:]]/ {
      next
    }
    /^[[:space:]]*add_header (Strict-Transport-Security|X-Frame-Options|X-Content-Type-Options|Referrer-Policy|Permissions-Policy|Content-Security-Policy|Content-Security-Policy-Report-Only)[[:space:]]/ {
      emit_pending()
      if (!in_security_block) {
        print indentation($0) "include snippets/nexdine-security-headers.conf;"
      }
      in_security_block = 1
      next
    }
    {
      in_security_block = 0
      if (pending) {
        if ($0 ~ /^[[:space:]]*include snippets\/nexdine-security-headers\.conf;/) {
          print
          pending = 0
          next
        }
        emit_pending()
      }
      print
      if ($0 ~ /^[[:space:]]*add_header Cache-Control[[:space:]]/) {
        pending = 1
        pending_indent = indentation($0)
      }
    }
    END { emit_pending() }
  ' "$site_file" > "$rendered"
  grep -Fq 'include snippets/nexdine-security-headers.conf;' "$rendered" \
    || fail "security include was not rendered for $site_file"
  rendered_files+=("$rendered")
done

rollback() {
  local status=$?
  trap - ERR
  set +e
  /usr/bin/tar -C / -xzf "$backup_file"
  if [[ $snippet_was_present -eq 0 ]]; then
    rm -f -- "$snippet_path"
  fi
  /usr/sbin/nginx -t
  /usr/bin/systemctl reload nginx
  printf 'harden-nginx-headers: update failed; restored %s\n' "$backup_file" >&2
  exit "$status"
}
trap rollback ERR

install -m 0644 -o root -g root "$snippet_tmp" "$snippet_path"
for index in "${!site_files[@]}"; do
  install -m 0644 -o root -g root "${rendered_files[$index]}" "${site_files[$index]}"
done

/usr/sbin/nginx -t

/usr/bin/systemctl reload nginx
trap - ERR
printf 'Nginx security headers applied to %d sites; backup=%s\n' "${#site_files[@]}" "$backup_file"
