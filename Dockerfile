# syntax=docker/dockerfile:1.7

# =============================================================================
# Stage 1: dependencies (composer + vendor patches)
# =============================================================================
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
# Patch scripts copy files from libs/patches into vendor, and the optimised classmap
# must include the application classes, so libs/ is needed here.
COPY libs ./libs

RUN composer install \
        --no-dev --no-interaction --no-progress --prefer-dist \
        --optimize-autoloader --classmap-authoritative \
        --ignore-platform-reqs

# =============================================================================
# Stage 2: runtime
# =============================================================================
FROM php:8.3-cli-alpine AS runtime

ARG APP_UID=10001
ARG APP_GID=10001

# Persistent runtime dependencies + build-time deps for PDO drivers and pcntl.
RUN set -eux; \
    apk add --no-cache \
        ca-certificates tzdata icu-libs libpq sqlite-libs libzip curl; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev postgresql-dev sqlite-dev libzip-dev linux-headers; \
    docker-php-ext-configure pcntl --enable-pcntl; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql pdo_pgsql pdo_sqlite pcntl intl opcache bcmath; \
    apk del .build-deps; \
    addgroup -g "${APP_GID}" -S app; \
    adduser -u "${APP_UID}" -S -G app -h /app -s /sbin/nologin app

# Production php.ini tuned for a long-running CLI worker.
RUN set -eux; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    { \
        echo 'memory_limit = 512M'; \
        echo 'max_execution_time = 0'; \
        echo 'display_errors = stderr'; \
        echo 'log_errors = On'; \
        echo 'error_log = /proc/self/fd/2'; \
        echo 'opcache.enable_cli = 1'; \
        echo 'opcache.validate_timestamps = 0'; \
        echo 'variables_order = "EGPCS"'; \
        echo 'date.timezone = UTC'; \
    } > "$PHP_INI_DIR/conf.d/zz-app.ini"

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY --chown=app:app bin ./bin
COPY --chown=app:app config ./config
COPY --chown=app:app database ./database
COPY --chown=app:app libs ./libs
COPY --chown=app:app src ./src
COPY --chown=app:app composer.json ./
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/healthcheck.sh /usr/local/bin/healthcheck

# Runtime state lives outside the image: mount a volume at /var/lib/zabbix-event-screening
ENV APP_ENV=production \
    APP_STORAGE_PATH=/var/lib/zabbix-event-screening \
    DB_SQLITE_PATH=/var/lib/zabbix-event-screening/database.sqlite \
    DAEMON_HEARTBEAT_FILE=/var/lib/zabbix-event-screening/daemon.heartbeat \
    DAEMON_OUTPUT_DIR=/var/lib/zabbix-event-screening/screenings \
    LOG_FORMAT=json

# Normalise line endings (Windows checkouts) and make scripts executable.
RUN set -eux; \
    sed -i 's/\r$//' /usr/local/bin/entrypoint /usr/local/bin/healthcheck /app/bin/console; \
    chmod 0755 /usr/local/bin/entrypoint /usr/local/bin/healthcheck /app/bin/console; \
    mkdir -p "$APP_STORAGE_PATH"; \
    chown -R app:app "$APP_STORAGE_PATH" /app

VOLUME ["/var/lib/zabbix-event-screening"]

USER app

# Container health is derived from the daemon heartbeat (updated every poll cycle).
HEALTHCHECK --interval=60s --timeout=5s --start-period=30s --retries=3 CMD ["healthcheck"]

STOPSIGNAL SIGTERM

ENTRYPOINT ["entrypoint"]
CMD ["daemon"]

LABEL org.opencontainers.image.title="zabbix-event-screening" \
      org.opencontainers.image.description="AI-driven triage daemon for ServiceNOW incidents using Zabbix" \
      org.opencontainers.image.source="https://github.com/bneeer/zabbix-event-screening"
