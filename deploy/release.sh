#!/usr/bin/env bash
# Run on EVERY release (pipeline or git pull). Does not start queue/reverb.
# Those stay running under Supervisor from setup-once.sh.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

if [[ ! -f "$BACKEND/.env" ]]; then
  echo "Missing $BACKEND/.env — copy .env.example and set production values first."
  exit 1
fi

cd "$BACKEND"

if grep -q '^APP_ENV=local' .env 2>/dev/null; then
  echo "APP_ENV is still local. Set APP_ENV=production before a live release."
  exit 1
fi

composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
php artisan queue:restart

cd "$FRONTEND"
if [[ ! -f .env.production ]]; then
  echo "Missing $FRONTEND/.env.production — copy .env.production.example first."
  exit 1
fi
npm ci
npm run build

if command -v supervisorctl >/dev/null 2>&1; then
  sudo supervisorctl restart aswad-reverb
else
  echo "supervisorctl not found. Restart Reverb yourself: php artisan reverb:start is already handled by Supervisor."
fi

echo "Release finished. Queue workers will recycle via queue:restart; Reverb was restarted."
