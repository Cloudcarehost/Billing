#!/usr/bin/env bash
# Run ONCE on a new production server. Starts queue + Reverb under Supervisor
# and installs the scheduler cron. Do not run this on every git push.
set -euo pipefail

ROOT="${1:-}"
if [[ -z "$ROOT" ]]; then
  ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fi
ROOT="$(cd "$ROOT" && pwd)"
BACKEND="$ROOT/backend"
CONF_SRC="$ROOT/deploy/aswad.conf"
CONF_DST="/etc/supervisor/conf.d/aswad.conf"

if [[ ! -d "$BACKEND" ]]; then
  echo "Backend not found at $BACKEND"
  exit 1
fi
if [[ ! -f "$BACKEND/.env" ]]; then
  echo "Create $BACKEND/.env before setup."
  exit 1
fi

sed "s#/path/to/backend#$BACKEND#g" "$CONF_SRC" | sudo tee "$CONF_DST" >/dev/null

cd "$BACKEND"
composer install --no-dev --optimize-autoloader --no-interaction
if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi
php artisan storage:link || true
php artisan migrate --force

CRON_LINE="* * * * * cd $BACKEND && php artisan schedule:run >> /dev/null 2>&1"
(crontab -l 2>/dev/null | grep -v 'artisan schedule:run' || true; echo "$CRON_LINE") | crontab -

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start aswad-queue aswad-reverb || sudo supervisorctl restart aswad-queue aswad-reverb

echo "One-time setup done. Queue and Reverb will stay running."
echo "On later deploys run only: bash deploy/release.sh"
