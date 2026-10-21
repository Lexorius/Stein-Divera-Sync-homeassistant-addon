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
#  - Basis: alpine:3.24 (statt ghcr.io/home-assistant/*-base) - kein s6-overlay
#  - Konfiguration via Environment-Variablen (nicht /data/options.json von HA)
#  - Eigener Entrypoint statt HA-Supervisor-Services
###############################################################################
FROM alpine:3.24

# Mindestversion von PHP, die die Fixes für CVE-2026-109618 und CVE-2026-109619
# (Remote Code Execution, php.net Security Advisory vom 21.10.2026) enthält.
# Der Build bricht ab, wenn Alpine eine ältere Version liefert.
ARG PHP_MIN_VERSION=8.4.27
ARG BUILD_VERSION=standalone

# Gleiche Pakete wie im HA-Addon (damit PHP-App identisch läuft)
RUN apk add --no-cache \
    nginx \
    php84 \
    php84-fpm \
    php84-pdo \
    php84-pdo_mysql \
    php84-pdo_sqlite \
    php84-sqlite3 \
    php84-curl \
    php84-mbstring \
    php84-openssl \
    php84-session \
    php84-ctype \
    php84-dom \
    php84-xml \
    php84-simplexml \
    php84-tokenizer \
    mariadb \
    mariadb-client \
    mariadb-common \
    mariadb-server-utils \
    supervisor \
    curl \
    ca-certificates \
    jq \
    bash \
    tzdata \
    && ln -sf /usr/bin/php84 /usr/bin/php \
    && ln -sf /usr/sbin/php-fpm84 /usr/sbin/php-fpm \
    && php -r 'if (version_compare(PHP_VERSION, $argv[1], "<")) { fwrite(STDERR, "FEHLER: PHP " . PHP_VERSION . " ist aelter als die geforderte Mindestversion " . $argv[1] . " (CVE-2026-109618/109619).\n"); exit(1); } echo "PHP " . PHP_VERSION . " erfuellt Mindestversion " . $argv[1] . "\n";' "$PHP_MIN_VERSION"

# Verzeichnisse (identisch zum HA-Addon)
RUN mkdir -p /var/www/html \
    && mkdir -p /var/log/nginx \
    && mkdir -p /var/log/php \
    && mkdir -p /var/log/steinapi \
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

# Berechtigungen (identisch zum HA-Addon: Webroot read-only für nginx/php-fpm)
RUN chmod +x /etc/services.d/steinapi/run \
    && chmod +x /etc/services.d/steinapi/finish \
    && chmod +x /usr/local/bin/steinapi-init.sh \
    && chmod +x /usr/local/bin/steinapi-cron.sh \
    && chmod +x /usr/local/bin/steinapi-mariadb.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R root:nginx /var/www/html \
    && find /var/www/html -type d -exec chmod 750 {} \; \
    && find /var/www/html -type f -exec chmod 640 {} \; \
    && chown nginx:nginx /var/log/php /var/log/steinapi \
    && chown -R mysql:mysql /var/lib/mysql \
    && chown -R mysql:mysql /run/mysqld \
    && php-fpm -t \
    && echo "${BUILD_VERSION}" > /etc/steinapi-version

# Integrierter Healthcheck (ersetzt den HA-Watchdog)
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:8099/api.php?action=health" || exit 1

EXPOSE 8099

VOLUME ["/data"]

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
