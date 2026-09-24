#!/usr/bin/env bash
# Every git push / GitHub Deploy. Does not start queue or Reverb.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

if [[ ! -f "$BACKEND/.env" ]]; then
  echo "Missing $BACKEND/.env — create it once before deploying."
  exit 1
fi

if grep -q '^APP_ENV=local' "$BACKEND/.env"; then
  echo "Error: backend/.env still has APP_ENV=local. Aborting release for safety."
  exit 1
fi

git config --global --add safe.directory "$ROOT"
if [[ ! -w "$ROOT/.git" ]]; then
  echo "Git metadata is not writable by $(whoami). Run: sudo chown -R $(whoami):$(whoami) $ROOT"
  exit 1
fi
git fetch origin main
git reset --hard origin/main

cd "$BACKEND"
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
php artisan migrate --force
php artisan cache:clear
php artisan route:clear
php artisan config:clear
php artisan view:clear
php artisan route:cache
php artisan config:cache
php artisan view:cache
php artisan queue:restart
cd "$ROOT"

cd "$FRONTEND"
npm ci
npm run build
cd "$ROOT"

if command -v supervisorctl >/dev/null 2>&1; then
  sudo supervisorctl restart aswad-reverb aswad-queue
elif command -v sudo >/dev/null 2>&1 && sudo -n supervisorctl status >/dev/null 2>&1; then
  sudo supervisorctl restart aswad-reverb aswad-queue
else
  echo "Supervisor is not available. Start queue and Reverb once with deploy/setup-once.sh"
  exit 1
fi

echo "Deployment finished successfully!"
