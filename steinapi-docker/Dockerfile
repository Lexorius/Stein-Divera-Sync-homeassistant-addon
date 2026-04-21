###############################################################################
# SteinAPI - Divera Sync - Standalone Docker Image
#
# WICHTIG: Dieses Dockerfile wird mit Build-Context auf dem REPO-ROOT gebaut,
# damit die rootfs/ des HA-Addons geteilt werden kann (Single Source of Truth).
#
# Build-Aufruf (aus dem Ordner steinapi-docker/):
#     docker compose up -d --build
#
# Manuell (aus dem Repo-Root):
#     docker build -f steinapi-docker/Dockerfile -t steinapi-divera-sync:local .
#
# Unterschiede zum HA-Addon:
#  - Basis: alpine:3.19 (statt ghcr.io/home-assistant/*-base) - kein s6-overlay
#  - Konfiguration via Environment-Variablen (nicht /data/options.json von HA)
#  - Eigener Entrypoint statt HA-Supervisor-Services
###############################################################################
FROM alpine:3.19

# Gleiche Pakete wie im HA-Addon (damit PHP-App identisch läuft)
RUN apk add --no-cache \
    nginx \
    php83 \
    php83-fpm \
    php83-pdo \
    php83-pdo_mysql \
    php83-pdo_sqlite \
    php83-sqlite3 \
    php83-curl \
    php83-json \
    php83-mbstring \
    php83-openssl \
    php83-session \
    php83-ctype \
    php83-dom \
    php83-xml \
    php83-simplexml \
    php83-tokenizer \
    mariadb \
    mariadb-client \
    mariadb-common \
    supervisor \
    curl \
    jq \
    bash \
    tzdata \
    && ln -sf /usr/sbin/php-fpm83 /usr/sbin/php-fpm

# Verzeichnisse (identisch zum HA-Addon)
RUN mkdir -p /var/www/html \
    && mkdir -p /var/log/nginx \
    && mkdir -p /var/log/php \
    && mkdir -p /var/log/supervisord \
    && mkdir -p /run/nginx \
    && mkdir -p /run/mysqld \
    && mkdir -p /var/lib/mysql \
    && mkdir -p /data/steinapi \
    && mkdir -p /data/mysql

# Shared rootfs vom HA-Addon (nginx-Config, Supervisord, PHP-App, Init-Scripts).
# Pfad bezieht sich auf den Build-Context = Repo-Root.
COPY steinapi-addon/rootfs/ /

# Standalone-Entrypoint (erzeugt /data/options.json aus ENV)
COPY steinapi-docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# Berechtigungen
RUN chmod +x /etc/services.d/steinapi/run \
    && chmod +x /etc/services.d/steinapi/finish \
    && chmod +x /usr/local/bin/steinapi-init.sh \
    && chmod +x /usr/local/bin/steinapi-cron.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chmod 755 /var/www/html \
    && chown -R nginx:nginx /var/www/html \
    && chown -R mysql:mysql /var/lib/mysql \
    && chown -R mysql:mysql /run/mysqld

# PHP-FPM Konfiguration (identisch zum HA-Addon)
RUN sed -i 's/;clear_env = no/clear_env = no/g' /etc/php83/php-fpm.d/www.conf \
    && sed -i 's/user = nobody/user = nginx/g' /etc/php83/php-fpm.d/www.conf \
    && sed -i 's/group = nobody/group = nginx/g' /etc/php83/php-fpm.d/www.conf \
    && sed -i 's|listen = 127.0.0.1:9000|listen = /run/php-fpm.sock|g' /etc/php83/php-fpm.d/www.conf \
    && sed -i 's/;listen.owner = nobody/listen.owner = nginx/g' /etc/php83/php-fpm.d/www.conf \
    && sed -i 's/;listen.group = nobody/listen.group = nginx/g' /etc/php83/php-fpm.d/www.conf

# Integrierter Healthcheck (ersetzt den HA-Watchdog)
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:8099/api.php?action=health" || exit 1

EXPOSE 8099

VOLUME ["/data"]

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
