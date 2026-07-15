ARG PHP_VERSION=8.3
ARG FRANKENPHP_VERSION=1.8
ARG COMPOSER_VERSION=2.8
ARG NODE_VERSION=20
ARG APP_ENV
ARG DOCKER_FILES_BASE_PATH="docker/prod/"

FROM composer:${COMPOSER_VERSION} AS vendor

# Upstream solidtime compiles a custom frankenphp binary here just to add the
# caddy-cbrotli module (brotli compression on top of the stock gzip support).
# Skipped intentionally: the stock dunglas/frankenphp image already ships a
# working frankenphp binary, and compiling Go/Caddy from source is slow and
# unnecessary on a small droplet for a compression-codec nice-to-have.

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS base

LABEL maintainer="solidtime <hello@solidtime.io>"
LABEL org.opencontainers.image.title="solidtime"
LABEL org.opencontainers.image.description="solidtime is a modern open source timetracker for freelancers and agencies"
LABEL org.opencontainers.image.source="https://github.com/solidtime-io/solidtime"
LABEL org.opencontainers.image.licenses="AGPL"

ARG WWWUSER=1000
ARG WWWGROUP=1000
ARG TZ=UTC
ARG APP_DIR=/var/www/html
ARG APP_ENV
ARG APP_HOST
ARG DOCKER_FILES_BASE_PATH

ENV DEBIAN_FRONTEND=noninteractive \
    TERM=xterm-color \
    OCTANE_SERVER=frankenphp \
    TZ=${TZ} \
    USER=octane \
    ROOT=${APP_DIR} \
    APP_ENV=${APP_ENV} \
    COMPOSER_FUND=0 \
    COMPOSER_MAX_PARALLEL_HTTP=24 \
    XDG_CONFIG_HOME=${APP_DIR}/.config \
    XDG_DATA_HOME=${APP_DIR}/.data \
    SERVER_NAME=${APP_HOST}

WORKDIR ${ROOT}

SHELL ["/bin/bash", "-eou", "pipefail", "-c"]

RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime \
    && echo ${TZ} > /etc/timezone

RUN apt-get update; \
    apt-get upgrade -yqq; \
    apt-get install -yqq --no-install-recommends --show-progress \
    apt-utils \
    curl \
    wget \
    vim \
    git \
    ncdu \
    procps \
    unzip \
    ca-certificates \
    supervisor \
    libsodium-dev \
    libbrotli-dev \
    # Install PHP extensions (included with dunglas/frankenphp)
    && install-php-extensions \
    bz2 \
    pcntl \
    mbstring \
    bcmath \
    sockets \
    pgsql \
    pdo_pgsql \
    opcache \
    exif \
    pdo_mysql \
    zip \
    uv \
    vips \
    intl \
    gd \
    redis \
    rdkafka \
    memcached \
    igbinary \
    ldap \
    && apt-get -y autoremove \
    && apt-get clean \
    && docker-php-source delete \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* \
    && rm /var/log/lastlog /var/log/faillog

RUN arch="$(uname -m)" \
    && case "$arch" in \
    armhf) _cronic_fname='supercronic-linux-arm' ;; \
    aarch64) _cronic_fname='supercronic-linux-arm64' ;; \
    x86_64) _cronic_fname='supercronic-linux-amd64' ;; \
    x86) _cronic_fname='supercronic-linux-386' ;; \
    *) echo >&2 "error: unsupported architecture: $arch"; exit 1 ;; \
    esac \
    && wget -q "https://github.com/aptible/supercronic/releases/download/v0.2.29/${_cronic_fname}" \
    -O /usr/bin/supercronic \
    && chmod +x /usr/bin/supercronic \
    && mkdir -p /etc/supercronic \
    && echo "*/1 * * * * php ${ROOT}/artisan schedule:run --no-interaction" > /etc/supercronic/laravel

RUN userdel --remove --force www-data \
    && groupadd --force -g ${WWWGROUP} ${USER} \
    && useradd -ms /bin/bash --no-log-init --no-user-group -g ${WWWGROUP} -u ${WWWUSER} ${USER} \
    && setcap -r /usr/local/bin/frankenphp

RUN chown -R ${USER}:${USER} ${ROOT} /var/{log,run} \
    && chmod -R a+rw ${ROOT} /var/{log,run}

RUN cp ${PHP_INI_DIR}/php.ini-production ${PHP_INI_DIR}/php.ini

USER ${USER}

COPY --link --chown=${WWWUSER}:${WWWUSER} --from=vendor /usr/bin/composer /usr/bin/composer

COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/supervisord.conf /etc/
COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/octane/FrankenPHP/supervisord.frankenphp.conf /etc/supervisor/conf.d/
COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/supervisord.*.conf /etc/supervisor/conf.d/
COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/start-container /usr/local/bin/start-container
COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/healthcheck /usr/local/bin/healthcheck
COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/php.ini ${PHP_INI_DIR}/conf.d/99-octane.ini

RUN chmod +x /usr/local/bin/start-container /usr/local/bin/healthcheck

###########################################

FROM base AS common

USER ${USER}

COPY --link --chown=${WWWUSER}:${WWWUSER} . .

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-autoloader \
    --no-ansi \
    --no-scripts

###########################################
# Build frontend assets with Node/npm
# (repo ships package-lock.json, not bun.lock — use npm to match what CI
# actually runs in .github/workflows/build-public.yml)
###########################################

FROM node:${NODE_VERSION} AS build

ARG APP_ENV

ENV ROOT=/var/www/html \
    APP_ENV=${APP_ENV}

WORKDIR ${ROOT}

COPY --link package.json package-lock.json ./

# NODE_ENV is intentionally left unset here — npm ci would skip devDependencies
# (which is where vite lives) if NODE_ENV=production. `vite build` already
# defaults to production mode on its own regardless.
RUN npm ci

COPY --link . .
COPY --link --from=common ${ROOT}/vendor vendor

RUN npm run build

###########################################

FROM common AS runner

USER ${USER}

ENV WITH_HORIZON=false \
    WITH_SCHEDULER=false \
    WITH_REVERB=false

COPY --link --chown=${WWWUSER}:${WWWUSER} --from=build ${ROOT}/public public

RUN mkdir -p \
    storage/framework/{sessions,views,cache,testing} \
    storage/logs \
    bootstrap/cache && chmod -R a+rw storage

RUN composer install \
    --classmap-authoritative \
    --no-interaction \
    --no-ansi \
    --no-dev \
    && composer clear-cache

EXPOSE 8000

ENTRYPOINT ["start-container"]

# Explicitly disabled, not just omitted: dropping our own HEALTHCHECK
# instruction isn't enough — the dunglas/frankenphp base image bakes in its
# own (CMD-SHELL curl http://localhost:2019/metrics, Caddy's admin API).
# That's fine for the http role, but worker/scheduler containers never run
# Caddy/FrankenPHP at all (CONTAINER_MODE=worker only runs `queue:work` via
# supervisord) — port 2019 never listens there, so the inherited healthcheck
# sits in "starting" forever and Kamal's non-proxied-role poller (which polls
# `docker inspect ... State.Health` directly, unlike the web role which goes
# through kamal-proxy's own separate HTTP check) times out and kills the
# container even though the worker process itself booted fine.
HEALTHCHECK NONE
