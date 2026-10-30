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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
