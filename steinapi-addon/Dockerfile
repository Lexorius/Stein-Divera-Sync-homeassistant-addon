ARG BUILD_FROM
FROM $BUILD_FROM

# Installiere notwendige Pakete
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
    tzdata

# Erstelle Verzeichnisse
RUN mkdir -p /var/www/html \
    && mkdir -p /var/log/nginx \
    && mkdir -p /var/log/php \
    && mkdir -p /var/log/supervisord \
    && mkdir -p /run/nginx \
    && mkdir -p /run/mysqld \
    && mkdir -p /var/lib/mysql \
    && mkdir -p /data/steinapi \
    && mkdir -p /data/mysql

# Kopiere Anwendungsdateien
COPY rootfs /

# Setze Berechtigungen
RUN chmod +x /etc/services.d/steinapi/run \
    && chmod +x /etc/services.d/steinapi/finish \
    && chmod +x /usr/local/bin/steinapi-init.sh \
    && chmod +x /usr/local/bin/steinapi-cron.sh \
    && chmod 755 /var/www/html \
    && chown -R nginx:nginx /var/www/html \
    && chown -R mysql:mysql /var/lib/mysql \
    && chown -R mysql:mysql /run/mysqld

# PHP-FPM Konfiguration anpassen
RUN sed -i 's/;clear_env = no/clear_env = no/g' /etc/php83/php-fpm.d/www.conf \
    && sed -i 's/user = nobody/user = nginx/g' /etc/php83/php-fpm.d/www.conf \
    && sed -i 's/group = nobody/group = nginx/g' /etc/php83/php-fpm.d/www.conf \
    && sed -i 's/listen = 127.0.0.1:9000/listen = \/run\/php-fpm.sock/g' /etc/php83/php-fpm.d/www.conf \
    && sed -i 's/;listen.owner = nobody/listen.owner = nginx/g' /etc/php83/php-fpm.d/www.conf \
    && sed -i 's/;listen.group = nobody/listen.group = nginx/g' /etc/php83/php-fpm.d/www.conf

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
    org.opencontainers.image.source="https://github.com/Lexorius/steinapi" \
    org.opencontainers.image.created=${BUILD_DATE} \
    org.opencontainers.image.revision=${BUILD_REF} \
    org.opencontainers.image.version=${BUILD_VERSION}
