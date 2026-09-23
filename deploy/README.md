# Production deploy

Queue and Reverb are **not** part of the GitHub pipeline as long-running jobs. Do not use `php artisan serve` or `composer run ops` on the server.

| Thing | When | What happens |
| --- | --- | --- |
| `.env` production values | **Once** | Set Redis, Reverb, `APP_ENV=production`, `APP_DEBUG=false` |
| Supervisor (`aswad-queue`, `aswad-reverb`) | **Once** | Starts queue + Reverb and keeps them alive forever |
| Cron `schedule:run` | **Once** | One crontab line, every minute |
| `deploy/release.sh` | **Every push / every pipeline** | `composer install`, migrate, caches, frontend build |
| After each release | **Every push** | `php artisan queue:restart` and `supervisorctl restart aswad-reverb` so workers load the new code |

The pipeline never runs `queue:work` or `reverb:start` itself. Supervisor already has those processes. A deploy only **signals a restart**.

## First time on the server

1. Clone the repo, copy `backend/.env.example` → `backend/.env`, set production values (see below).
2. Copy `frontend/.env.production.example` → `frontend/.env.production` and set the live API / Reverb host.
3. `sudo bash deploy/setup-once.sh /var/www/Billing`
4. Point Nginx at `backend/public` (API) and `frontend/dist` (SPA). Example: `deploy/nginx.example.conf`.
5. Put Redis in front of queue, cache, and sessions before go-live.

## Every release

GitHub Actions `Deploy` SSHs in and runs `deploy/release.sh`. You can also run it by hand:

```bash
cd /var/www/Billing
git pull
bash deploy/release.sh
```

## GitHub secrets (repo Settings → Secrets)

- `PRODUCTION_HOST` — server IP or hostname
- `PRODUCTION_USER` — SSH user
- `PRODUCTION_SSH_KEY` — private key (full PEM)
- `PRODUCTION_PATH` — app root on the server, e.g. `/var/www/Billing`

## Production `.env` (backend, once)

```
APP_ENV=production
APP_DEBUG=false
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_AFTER_COMMIT=true
REVERB_HOST=your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=https://your-frontend-domain.com
```
