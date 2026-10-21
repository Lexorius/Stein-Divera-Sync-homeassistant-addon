#!/usr/bin/env bash
# Startet MariaDB für supervisord.
# - Ausschließlich über Unix-Socket erreichbar (--skip-networking): Es gibt
#   keinen TCP-Port, den andere Container im Home-Assistant-Netz erreichen könnten.
#   Wird hier explizit gesetzt und hängt nicht von Distributions-Defaults ab.
# - Verwendet mariadbd-safe (MariaDB >= 10.4), Fallback auf mysqld_safe.

if command -v mariadbd-safe >/dev/null 2>&1; then
    SAFE_BIN=mariadbd-safe
else
    SAFE_BIN=mysqld_safe
fi

exec "$SAFE_BIN" \
    --user=mysql \
    --datadir=/data/mysql \
    --skip-networking \
    --skip-name-resolve \
    --skip-symbolic-links \
    --local-infile=0 \
    "$@"
