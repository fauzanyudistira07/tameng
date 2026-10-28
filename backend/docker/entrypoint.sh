#!/usr/bin/env sh
set -e

mkdir -p \
  storage/app/private/repository-workspaces \
  storage/app/private/rules \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

# Sync custom engine rules from app/Services/Engines/Rules into storage/app/private/rules
if [ -d app/Services/Engines/Rules ]; then
  mkdir -p storage/app/private/rules
  cp -r app/Services/Engines/Rules/* storage/app/private/rules/ 2>/dev/null || true
fi

# Guarantee docker socket access for www-data
if [ -S /var/run/docker.sock ]; then
  DOCKER_GID=$(stat -c '%g' /var/run/docker.sock 2>/dev/null || true)
  if [ -n "$DOCKER_GID" ] && [ "$DOCKER_GID" != "0" ]; then
    addgroup -g "$DOCKER_GID" docker_host 2>/dev/null || true
    addgroup www-data docker_host 2>/dev/null || true
  fi
  chmod 666 /var/run/docker.sock 2>/dev/null || true
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rw storage bootstrap/cache 2>/dev/null || true

exec "$@"
