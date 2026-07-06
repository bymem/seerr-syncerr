#!/bin/sh
set -e

PUID="${PUID:-1000}"
PGID="${PGID:-1000}"
PORT="${PORT:-8089}"
TZ="${TZ:-UTC}"

# Timezone
if [ -f "/usr/share/zoneinfo/${TZ}" ]; then
    cp "/usr/share/zoneinfo/${TZ}" /etc/localtime
    echo "${TZ}" > /etc/timezone
fi

# Create/modify a system group+user matching PGID/PUID, linuxserver.io-style.
if ! getent group appgroup >/dev/null 2>&1; then
    addgroup -g "${PGID}" appgroup
fi

if ! getent passwd appuser >/dev/null 2>&1; then
    adduser -D -u "${PUID}" -G appgroup -h /app appuser
fi

mkdir -p /config
chown -R appuser:appgroup /config

echo "Starting seerr-syncerr on port ${PORT} (PUID=${PUID} PGID=${PGID} TZ=${TZ})"

exec su-exec appuser php -S "0.0.0.0:${PORT}" -t /app/public /app/public/index.php
