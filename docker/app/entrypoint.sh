#!/usr/bin/env sh
set -eu

cd /var/www/html

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ "${DB_CONNECTION:-}" = "sqlite" ] && [ -n "${DB_DATABASE:-}" ] && [ "${DB_DATABASE}" != ":memory:" ]; then
  mkdir -p "$(dirname "${DB_DATABASE}")"
  touch "${DB_DATABASE}"
fi

# Ensure the container never boots with host-generated cached config or routes.
rm -f bootstrap/cache/config.php bootstrap/cache/events.php bootstrap/cache/routes.php bootstrap/cache/routes-v7.php

exec "$@"
