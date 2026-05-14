#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

# Ensure the container never boots with host-generated cached config or routes.
rm -f bootstrap/cache/config.php bootstrap/cache/events.php bootstrap/cache/routes.php bootstrap/cache/routes-v7.php

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

exec "$@"