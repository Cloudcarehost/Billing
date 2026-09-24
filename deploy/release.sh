#!/bin/bash
set -e

APP_DIR="/home/nikhil/web/billing.nikhilbhangale.com/public_html"
cd "$APP_DIR"

echo "Starting deployment for billing application..."

if grep -q "APP_ENV=local" backend/.env; then
    echo "Error: backend/.env still has APP_ENV=local. Aborting release for safety."
    exit 1
fi

git fetch origin main
git reset --hard origin/main

cd backend
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
cd ..

cd frontend
npm ci
npm run build
cd ..

sudo supervisorctl restart billing-reverb

echo "Deployment finished successfully!"
