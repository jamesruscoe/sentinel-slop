# syntax=docker/dockerfile:1.7
#
# Sentinel Slop production image. One image, four roles, chosen by CONTAINER_ROLE
# (web | worker | scheduler | reverb) in docker/entrypoint.sh.
#
# Everything an analyser needs is pinned here and nowhere else:
#   PHP           the base image tag
#   Node          the node image tag (only the binary is copied: ESLint and jscpd run from node_modules)
#   PHPStan, Pint composer.lock
#   ESLint, jscpd package-lock.json
#   Semgrep       SEMGREP_VERSION (pip, hash-checked wheels)
#   gitleaks      GITLEAKS_VERSION (GitHub release, sha256 from docker/checksums.txt)
#   Ruff          RUFF_VERSION (GitHub release, sha256 from docker/checksums.txt)
# The last build step runs `php artisan sentinel:doctor --strict`, which fails the
# build when any tool is missing, unpinned or off its pin, when a required PHP
# extension is absent, or when memory_limit is below the worker requirement.
# The pins for the system binaries are passed into the image as SENTINEL_*_VERSION
# so config/sentinel.php checks the same numbers this file installed.

ARG PHP_IMAGE=php:8.4.25-fpm-bookworm
ARG NODE_IMAGE=node:24.21.0-bookworm-slim
ARG SEMGREP_VERSION=1.177.0
ARG GITLEAKS_VERSION=8.30.1
ARG RUFF_VERSION=0.16.8

# ---------------------------------------------------------------------------
# Stage 1: Vite assets and the runtime node_modules (ESLint, jscpd, typescript-eslint).
# ---------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS assets
WORKDIR /build
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build && npm prune --omit=dev --no-audit --no-fund

# ---------------------------------------------------------------------------
# Stage 2: Composer dependencies, without dev packages.
# ---------------------------------------------------------------------------
FROM composer:2.10.3 AS composer-deps
WORKDIR /build
COPY composer.json composer.lock ./
# Extensions are checked by the platform config below, not by this container: install without scripts and
# without the autoloader, both of which need the application code and the real PHP.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader \
    --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix --ignore-platform-req=ext-redis

# ---------------------------------------------------------------------------
# Stage 3: the runtime image.
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS runtime
ARG SEMGREP_VERSION
ARG GITLEAKS_VERSION
ARG RUFF_VERSION
ARG TARGETARCH

ENV DEBIAN_FRONTEND=noninteractive \
    SENTINEL_SEMGREP_VERSION=${SEMGREP_VERSION} \
    SENTINEL_GITLEAKS_VERSION=${GITLEAKS_VERSION} \
    SENTINEL_RUFF_VERSION=${RUFF_VERSION} \
    SENTINEL_SEMGREP_BINARY=/usr/local/bin/semgrep \
    SENTINEL_GITLEAKS_BINARY=/usr/local/bin/gitleaks \
    SENTINEL_RUFF_BINARY=/usr/local/bin/ruff \
    SENTINEL_NODE_BINARY=/usr/local/bin/node \
    SENTINEL_SCAN_STORAGE_PATH=/tmp/sentinel/scans \
    SENTINEL_TOOLS_DETACHED_DIR=/var/www/html/storage/sentinel-tools \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

# PHP extensions. posix, mbstring, zlib, pdo and tokenizer ship with the official image; these do not.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pcntl redis pdo_mysql intl zip opcache

# System packages: nginx + supervisord for the web role, gosu to drop privileges for the worker roles,
# python for Semgrep, git because git archive metadata is read by some tools, ca-certificates for GitHub.
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor gosu python3 python3-pip ca-certificates curl unzip git \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

# Semgrep from PyPI at an exact version. The wheel bundles the semgrep-core binary.
RUN pip3 install --no-cache-dir --break-system-packages "semgrep==${SEMGREP_VERSION}" \
    && semgrep --version

# gitleaks and Ruff from their GitHub releases, verified against sha256 sums committed in docker/checksums.txt
# (recorded once from the releases and reviewed with the pin bump; a replaced release would no longer match,
# which fetching the sums alongside the binary could never detect).
COPY docker/checksums.txt /tmp/checksums.txt
RUN set -eux; \
    case "${TARGETARCH}" in amd64) GL_ARCH=x64; RUFF_ARCH=x86_64 ;; arm64) GL_ARCH=arm64; RUFF_ARCH=aarch64 ;; *) echo "unsupported arch ${TARGETARCH}" >&2; exit 1 ;; esac; \
    cd /tmp; \
    curl -fsSLO "https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_linux_${GL_ARCH}.tar.gz"; \
    curl -fsSLO "https://github.com/astral-sh/ruff/releases/download/${RUFF_VERSION}/ruff-${RUFF_ARCH}-unknown-linux-gnu.tar.gz"; \
    sha256sum --ignore-missing --strict -c /tmp/checksums.txt; \
    tar -xzf "gitleaks_${GITLEAKS_VERSION}_linux_${GL_ARCH}.tar.gz" gitleaks; \
    install -m 0755 gitleaks /usr/local/bin/gitleaks; \
    tar -xzf "ruff-${RUFF_ARCH}-unknown-linux-gnu.tar.gz"; \
    install -m 0755 "ruff-${RUFF_ARCH}-unknown-linux-gnu/ruff" /usr/local/bin/ruff; \
    rm -rf /tmp/*; \
    gitleaks version; ruff --version

# Node: only the binary. ESLint and jscpd are run as `node node_modules/...`, so npm is not needed at runtime.
COPY --from=assets /usr/local/bin/node /usr/local/bin/node

# PHP configuration: production ini, memory_limit for the worker (laravel/framework peaks ~150 MB; the
# 128M default killed it), opcache without timestamp checks (the code never changes inside a container).
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-sentinel.ini"
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-sentinel.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf

WORKDIR /var/www/html

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
COPY --from=composer-deps /build/vendor ./vendor
COPY . .
COPY --from=assets /build/public/build ./public/build
COPY --from=assets /build/node_modules ./node_modules

# Autoloader and package discovery now that the code and the real PHP (with pcntl/posix/redis) are here.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && rm -f /usr/bin/composer

# PHPStan's phar, detached from vendor at build time so the runtime tree needs no write for it
# (ToolLocator refreshes the copy only when size or mtime differ; cp -p keeps both).
RUN mkdir -p storage/sentinel-tools && cp -p vendor/phpstan/phpstan/phpstan.phar storage/sentinel-tools/phpstan.phar

# Writable paths for www-data: Laravel's storage, the bootstrap cache, the scan workspace root, and its home
# (gosu sets HOME=/var/www; Semgrep refuses to start without a writable ~/.semgrep and crashed with
# "Permission denied: /root/.semgrep" in the first in-container scan).
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache /tmp/sentinel/scans /var/www/.semgrep /var/www/.cache \
    && chown -R www-data:www-data storage bootstrap/cache /tmp/sentinel /var/www/.semgrep /var/www/.cache \
    && chown www-data:www-data /var/www \
    && chmod +x docker/entrypoint.sh

# Views and routes can be cached without environment; config is cached at container start (it holds secrets).
RUN php artisan view:cache && php artisan route:cache

# The build fails here if anything above drifted from its pin or the PHP is not fit for the worker.
RUN php artisan sentinel:doctor --strict

EXPOSE 8080
ENTRYPOINT ["docker/entrypoint.sh"]
CMD []

# ---------------------------------------------------------------------------
# Stage 4 (`--target test`): the same image with dev dependencies, running the whole suite, Pint and PHPStan
# against the binaries that actually ship. The deploy pipeline builds this target first and pushes the runtime
# image only when it succeeds, so nothing reaches ECR that failed a test. `git init` exists for the test that
# asserts a workspace under storage/app/scans is git-ignored (the .git directory is not copied into the image).
# ---------------------------------------------------------------------------
FROM runtime AS test
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
RUN composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader \
    && cp .env.example .env && php artisan key:generate --force \
    && php artisan route:clear && php artisan view:clear && php artisan config:clear \
    && git init -q && git config --global --add safe.directory /var/www/html
ENV APP_ENV=testing
RUN php vendor/bin/pint --test \
    && php vendor/bin/phpstan analyse --no-progress --memory-limit=1G \
    && php vendor/bin/pest --compact
