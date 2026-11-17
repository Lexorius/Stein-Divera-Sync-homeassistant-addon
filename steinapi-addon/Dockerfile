###############################################################################
# SteinAPI - Divera Sync: EIN Dockerfile für beide Varianten.
#
#   Home Assistant Add-on  -> letzte Stufe "addon" (Standardziel).
#                             Der Supervisor baut ohne --target und setzt
#                             BUILD_FROM aus build.yaml.
#   Standalone-Container   -> Stufe "standalone":
#                             docker build --target standalone -t steinapi steinapi-addon
#                             (oder: cd steinapi-docker && docker compose up -d --build)
#
# Beide Stufen bauen auf "base" auf: gleiche Pakete, gleiche rootfs/, gleiche
# Rechte. Bis 2.2.3 gab es dafür zwei getrennte Dockerfiles, die von Hand
# gleich gehalten werden mussten.
###############################################################################
ARG BUILD_FROM=alpine:3.24
FROM ${BUILD_FROM} AS base

# Mindestversion von PHP, die die Fixes für CVE-2026-109618 und CVE-2026-109619
# (Remote Code Execution, php.net Security Advisory vom 21.10.2026) enthält.
# Der Build bricht ab, wenn das Basis-Image eine ältere Version liefert.
ARG PHP_MIN_VERSION=8.4.27

# Installiere notwendige Pakete
# Hinweis: Alpine liefert kein php84-json mehr - JSON ist seit PHP 8 fest eingebaut.
RUN apk add --no-cache \
    nginx \
    php84 \
    php84-fpm \
    php84-pdo \
    php84-pdo_mysql \
    php84-curl \
    php84-openssl \
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

# Erstelle Verzeichnisse
RUN mkdir -p /var/www/html \
    && mkdir -p /var/log/steinapi \
    && mkdir -p /run/nginx \
    && mkdir -p /run/mysqld \
    && mkdir -p /var/lib/mysql \
    && mkdir -p /data/steinapi \
    && mkdir -p /data/mysql

# Kopiere Anwendungsdateien (PHP-App, nginx, php-fpm, supervisord, Start-Skripte)
COPY rootfs /

# Setze Berechtigungen
# Webroot gehört root und ist für nginx/php-fpm nur lesbar (kein Schreibzugriff
# durch den Webserver-Prozess).
RUN chmod +x /etc/services.d/steinapi/run \
    && chmod +x /etc/services.d/steinapi/finish \
    && chmod +x /usr/local/bin/steinapi-init.sh \
    && chmod +x /usr/local/bin/steinapi-cron.sh \
    && chmod +x /usr/local/bin/steinapi-mariadb.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R root:nginx /var/www/html \
    && find /var/www/html -type d -exec chmod 750 {} \; \
    && find /var/www/html -type f -exec chmod 640 {} \; \
    && chown nginx:nginx /var/log/steinapi \
    && chown -R mysql:mysql /var/lib/mysql \
    && chown -R mysql:mysql /run/mysqld \
    && php-fpm -t

ARG BUILD_VERSION=dev
RUN echo "${BUILD_VERSION}" > /etc/steinapi-version

EXPOSE 8099

###############################################################################
# Standalone-Container: Konfiguration über Umgebungsvariablen, eigener
# Entrypoint (erzeugt /data/options.json) und Healthcheck statt HA-Watchdog.
###############################################################################
FROM base AS standalone

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:8099/api.php?action=health" || exit 1

VOLUME ["/data"]

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

###############################################################################
# Home Assistant Add-on. Muss die LETZTE Stufe bleiben: der Supervisor baut
# das Standardziel. Start über s6 des Basis-Images (/etc/services.d/steinapi).
###############################################################################
FROM base AS addon

# BUILD_ARCH und BUILD_VERSION kommen vom Supervisor, Name und Beschreibung
# aus build.yaml.
ARG BUILD_ARCH
ARG BUILD_DESCRIPTION
ARG BUILD_NAME
ARG BUILD_VERSION=dev

LABEL \
    io.hass.name="${BUILD_NAME}" \
    io.hass.description="${BUILD_DESCRIPTION}" \
    io.hass.arch="${BUILD_ARCH}" \
    io.hass.type="addon" \
    io.hass.version="${BUILD_VERSION}" \
    maintainer="SteinAPI Addon" \
    org.opencontainers.image.title="${BUILD_NAME}" \
    org.opencontainers.image.description="${BUILD_DESCRIPTION}" \
    org.opencontainers.image.authors="SteinAPI Addon" \
    org.opencontainers.image.source="https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon" \
    org.opencontainers.image.version="${BUILD_VERSION}"
