ARG BUILD_FROM
FROM $BUILD_FROM

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

# Erstelle Verzeichnisse
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

# Kopiere Anwendungsdateien (inkl. php.ini-Härtung und php-fpm Pool-Konfiguration)
COPY rootfs /

# Setze Berechtigungen
# Webroot gehört root und ist für nginx/php-fpm nur lesbar (kein Schreibzugriff
# durch den Webserver-Prozess). Logs sind für den nginx-User beschreibbar.
RUN chmod +x /etc/services.d/steinapi/run \
    && chmod +x /etc/services.d/steinapi/finish \
    && chmod +x /usr/local/bin/steinapi-init.sh \
    && chmod +x /usr/local/bin/steinapi-cron.sh \
    && chmod +x /usr/local/bin/steinapi-mariadb.sh \
    && chown -R root:nginx /var/www/html \
    && find /var/www/html -type d -exec chmod 750 {} \; \
    && find /var/www/html -type f -exec chmod 640 {} \; \
    && chown nginx:nginx /var/log/php /var/log/steinapi \
    && chown -R mysql:mysql /var/lib/mysql \
    && chown -R mysql:mysql /run/mysqld \
    && php-fpm -t

# Exponiere Port
EXPOSE 8099

# Build arguments
ARG BUILD_ARCH
ARG BUILD_DATE
ARG BUILD_DESCRIPTION
ARG BUILD_NAME
ARG BUILD_REF
ARG BUILD_REPOSITORY
ARG BUILD_VERSION

RUN echo "${BUILD_VERSION:-dev}" > /etc/steinapi-version

# Labels
LABEL \
    io.hass.name="${BUILD_NAME}" \
    io.hass.description="${BUILD_DESCRIPTION}" \
    io.hass.arch="${BUILD_ARCH}" \
    io.hass.type="addon" \
    io.hass.version=${BUILD_VERSION} \
    maintainer="SteinAPI Addon" \
    org.opencontainers.image.title="${BUILD_NAME}" \
    org.opencontainers.image.description="${BUILD_DESCRIPTION}" \
    org.opencontainers.image.authors="SteinAPI Addon" \
    org.opencontainers.image.source="https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon" \
    org.opencontainers.image.created=${BUILD_DATE} \
    org.opencontainers.image.revision=${BUILD_REF} \
    org.opencontainers.image.version=${BUILD_VERSION}
