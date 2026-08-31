#!/usr/bin/env sh
set -e

if [ "${SECSYS_RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
fi

if [ "${SECSYS_RUN_SEEDERS:-false}" = "true" ]; then
  php artisan db:seed --force
fi

if [ "${SECSYS_CACHE_BOOTSTRAP:-true}" = "true" ]; then
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
fi

exec php-fpm
