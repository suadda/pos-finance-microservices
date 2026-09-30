#!/bin/sh
# Migrate + seed (both idempotent), cache config/routes, then serve on :8000.
set -e

attempt=0
until php artisan migrate --force --no-interaction; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 30 ]; then
    echo "[entrypoint] database unreachable, giving up" >&2
    exit 1
  fi
  echo "[entrypoint] database not ready, retry ${attempt}/30"
  sleep 3
done

php artisan db:seed --force --no-interaction
php artisan config:cache
php artisan route:cache

exec frankenphp php-server --listen :8000 --root public/
