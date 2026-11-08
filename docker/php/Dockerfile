FROM php:8.5-fpm-alpine

# Runtime libraries. icu-data-full: Alpine ships English-only ICU data, the app needs DE/FR/IT.
RUN apk add --no-cache bash git unzip icu-libs icu-data-full libpq libzip libpng libjpeg-turbo libwebp libavif

# Extensions are compiled against temporary build deps, removed afterwards.
# OPcache is built into PHP 8.5, so it is not installed here. gd writes the image variants
# (AVIF/WebP/JPEG, S9); exif reads photo orientation.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev libavif-dev linux-headers \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install intl pdo_pgsql zip gd exif \
    && pecl install xdebug-3.5.3 \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

# dart-sass standalone for symfonycasts/sass-bundle (found on PATH as `sass`). Pinned: research E4, D10.
# Source: the GitHub release, retried through a short outage. If that still fails, the npm package
# sass-embedded-linux-musl-<arch> of the same version, whose dart-sass/ tree is byte-identical to the
# release tarball (checked for 1.105.2). Both downloads are verified against a pinned SHA-256, so a
# version bump updates all four sums: the release's from the GitHub API asset `digest`, npm's with
# `sha256sum` of the .tgz (its `dist.integrity` sha512 confirms the file).
ARG DART_SASS_VERSION=1.105.2
ARG DART_SASS_SHA256_X64=2626acaed1de20391fe2be921f948b32ffeb23e6f3518ce9f5820f43ec538da7
ARG DART_SASS_SHA256_ARM64=6cf1238157c83790927e485b1a91e5cfeaa83ce6952a9e1aa405400ea89d8249
ARG DART_SASS_NPM_SHA256_X64=ddca03507d0970d7fd1a96ee1b2d243bc191545679baa2abe10e998ecb80d85b
ARG DART_SASS_NPM_SHA256_ARM64=00bf0c68cdcd2bc37fb23de7f6404d05e57b740a2a7782fc5f2450521dfecf9c
RUN set -eux; \
    case "$(uname -m)" in \
        x86_64) arch=x64; sum="$DART_SASS_SHA256_X64"; npm_sum="$DART_SASS_NPM_SHA256_X64" ;; \
        aarch64) arch=arm64; sum="$DART_SASS_SHA256_ARM64"; npm_sum="$DART_SASS_NPM_SHA256_ARM64" ;; \
        *) echo "unsupported arch $(uname -m)" >&2; exit 1 ;; \
    esac; \
    # --retry-max-time caps each source near 7 min even when every attempt hangs to --max-time, so both
    # sources together stay well inside deploy.yaml's 30-minute job timeout.
    fetch() { curl -fsSL --retry 8 --retry-delay 15 --retry-all-errors --retry-max-time 300 --connect-timeout 20 --max-time 120 -o "$1" "$2"; }; \
    if fetch /tmp/dart-sass.tar.gz "https://github.com/sass/dart-sass/releases/download/${DART_SASS_VERSION}/dart-sass-${DART_SASS_VERSION}-linux-${arch}-musl.tar.gz"; then \
        echo "$sum  /tmp/dart-sass.tar.gz" | sha256sum -c -; \
        tar -xzf /tmp/dart-sass.tar.gz -C /opt; \
    else \
        echo "dart-sass: GitHub release download failed, falling back to the npm registry" >&2; \
        fetch /tmp/dart-sass.tar.gz "https://registry.npmjs.org/sass-embedded-linux-musl-${arch}/-/sass-embedded-linux-musl-${arch}-${DART_SASS_VERSION}.tgz"; \
        echo "$npm_sum  /tmp/dart-sass.tar.gz" | sha256sum -c -; \
        tar -xzf /tmp/dart-sass.tar.gz -C /opt --strip-components=1 package/dart-sass; \
    fi; \
    rm /tmp/dart-sass.tar.gz; \
    ln -s /opt/dart-sass/sass /usr/local/bin/sass; \
    sass --version

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
