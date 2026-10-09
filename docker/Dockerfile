# syntax=docker/dockerfile:1
#####################################################################################
#  Snowprint image, built from source:
#
#     docker build -f docker/Dockerfile --build-context winter-boot=../winter-boot \
#         -t snowprint-analytics .
#
#  During development Winter Boot comes from a local winter-boot checkout (the
#  "winter-boot" build context; composer.json links ../winter-boot), not Packagist.
#
#  Stages "builder" and "runtime" are Winter Boot's base image
#  (suvera/winter-boot, build/docker/Dockerfile @ 3bdb36d), copied as-is and then
#  adjusted for Snowprint. Every adjustment is marked "Snowprint change:".
######################################################################################
# Snowprint change: Composer dependencies are installed first, so the native
#   extension below is built from the same Winter Boot source as the app.
#   Winter Boot is the local checkout passed as the "winter-boot" build context
#   (composer.json: path repository ../winter-boot), copied instead of symlinked.
FROM composer:2 AS vendor
COPY --from=winter-boot --exclude=vendor --exclude=target --exclude=.git . /src/winter-boot
WORKDIR /src/app
COPY composer.json composer.lock ./
RUN COMPOSER_MIRROR_PATH_REPOS=1 composer install --no-dev --no-scripts --no-interaction \
        --prefer-dist --ignore-platform-reqs --optimize-autoloader

FROM php:8.5-cli-alpine AS builder

# Snowprint change: Swoole is compiled with --enable-swoole-pgsql so PDO-pgsql
#   calls yield to other coroutines instead of blocking the whole worker
#   (SWOOLE_HOOK_PDO_PGSQL, part of SWOOLE_HOOK_ALL).
# Snowprint change: + apcu (non-blocking Prometheus storage shared by all workers),
#   + pdo_mysql (Matomo importer reads Matomo's MySQL database).
# Snowprint change: - rdkafka (the Kafka tier is not built yet; saves librdkafka).
RUN set -eux; \
    apk add --no-cache \
        $PHPIZE_DEPS \
        pax-utils \
        linux-headers \
        postgresql-dev libzip-dev curl-dev openssl-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql pdo_mysql pcntl curl zip; \
    pecl install redis; \
    pecl install apcu; \
    pecl install -D 'enable-swoole-pgsql="yes"' swoole-6.2.2; \
    docker-php-ext-enable redis apcu swoole; \
    EXT_DIR="$(php-config --extension-dir)"; \
    echo "extension dir: $EXT_DIR"; \
    strip --strip-unneeded "$EXT_DIR"/*.so; \
    ls "$EXT_DIR"/*.so; \
    runDeps="$( \
        scanelf --needed --nobanner --format '%n#p' --recursive /usr/local \
            | tr ',' '\n' \
            | sort -u \
            | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
    )"; \
    echo "runtime deps:"; echo "$runDeps"; \
    test -n "$runDeps"; \
    echo "$runDeps" > /tmp/runtime-pkgs.txt

# Winter Boot native extension (repo's php-ext/): native AOP interception and
# template evaluation. Pure Zend API, no extra shared-library deps.
# Enabled alongside swoole.
# config.m4 reads the extension version from ../VERSION.txt.
# Snowprint change: php-ext/ and VERSION.txt come from vendor/suvera/winter-boot (the
#   Winter Boot source the app uses) instead of COPY php-ext from the build context.
COPY --from=vendor /src/app/vendor/suvera/winter-boot/php-ext /tmp/php-ext
COPY --from=vendor /src/app/vendor/suvera/winter-boot/VERSION.txt /tmp/VERSION.txt
RUN set -eux; \
    cd /tmp/php-ext; \
    phpize; \
    ./configure --enable-winter_boot; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable winter_boot; \
    php --ri winter_boot; \
    strip --strip-unneeded "$(php-config --extension-dir)/winter_boot.so"; \
    cp /tmp/VERSION.txt /tmp/winter-boot-VERSION.txt; \
    rm -rf /tmp/php-ext

FROM php:8.5-cli-alpine AS runtime

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=builder /tmp/runtime-pkgs.txt /tmp/runtime-pkgs.txt
# Snowprint change: ini file from docker/ (copied from winter-boot's build/docker/),
#   plus apc.enable_cli so APCu works in the long-running CLI server.
COPY docker/winter-boot.ini /usr/local/etc/php/conf.d/
# Framework version manifest (read by WinterApplicationRunner::getBootVersion()
# for the startup banner). Mirrors the repo-root location into the image root.
# Snowprint change: taken from the Composer package (see builder).
COPY --from=builder /tmp/winter-boot-VERSION.txt /VERSION.txt

RUN set -eux; \
    pkgs="$(cat /tmp/runtime-pkgs.txt)"; \
    apk add --no-cache postgresql-client; \
    if [ -n "$pkgs" ]; then apk add --no-cache $pkgs; fi; \
    rm -f /tmp/runtime-pkgs.txt; \
    echo 'apc.enable_cli = 1' > "$PHP_INI_DIR/conf.d/zz-snowprint-apcu.ini"; \
    php -m | sort; \
    php --ri pdo_pgsql > /dev/null; \
    php --ri pgsql > /dev/null; \
    php --ri pdo_mysql > /dev/null; \
    php --ri pcntl > /dev/null; \
    php --ri curl > /dev/null; \
    php --ri zip > /dev/null; \
    php --ri redis > /dev/null; \
    php --ri apcu > /dev/null; \
    php --ri swoole > /dev/null; \
    php --ri winter_boot > /dev/null; \
    php -r 'if (!defined("SWOOLE_HOOK_PDO_PGSQL")) { fwrite(STDERR, "swoole built without pgsql coroutine support\n"); exit(1); }'; \
    php -r 'if (ini_get("variables_order") !== "EGPCS") { fwrite(STDERR, "variables_order wrong\n"); exit(1); } if (error_reporting() !== (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED)) { fwrite(STDERR, "error_reporting wrong\n"); exit(1); }'; \
    php -r 'if (!function_exists("winter_boot_advise")) { fwrite(STDERR, "winter_boot functions missing\n"); exit(1); } echo "winter_boot ok\n";'

#####################################################################################
#  Snowprint application
######################################################################################

# Dashboard: React + TypeScript app (ui/) built to static files (decision D10).
# Only the output reaches the final image; there is no Node at runtime.
FROM node:22-alpine AS ui
WORKDIR /src/ui
COPY ui/package.json ui/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY ui/ ./
RUN npm run build

# Winter Boot's SQL migrator, compiled from the framework's own box.json, from
# the same local checkout.
FROM runtime AS migrator
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /src/winter-boot /src/winter-boot
COPY migrations/build-phar.sh /tmp/build-phar.sh
RUN WINTER_BOOT_DIR=/src/winter-boot sh /tmp/build-phar.sh /out/winter-migrations-app.phar

FROM runtime
WORKDIR /app
COPY --from=migrator /out/winter-migrations-app.phar ./winter-migrations-app.phar
COPY --from=vendor /src/app/vendor ./vendor
COPY bin ./bin
COPY config ./config
COPY migrations ./migrations
COPY src ./src
COPY public ./public
COPY --from=ui /src/public/ui ./public/ui
COPY docker/entrypoint.sh /usr/local/bin/snowprint-entrypoint

RUN chmod +x /usr/local/bin/snowprint-entrypoint \
 && addgroup -S app && adduser -S -D -H -G app -h /app app \
 && mkdir -p var && chown -R app:app var
USER app

EXPOSE 7669
HEALTHCHECK --interval=10s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO /dev/null http://127.0.0.1:7669/api/system/health || exit 1
ENTRYPOINT ["snowprint-entrypoint"]
