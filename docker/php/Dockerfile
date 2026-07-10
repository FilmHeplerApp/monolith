FROM php:8.4-fpm-alpine

LABEL authors="vladimirbubnov"

WORKDIR /var/www/html

RUN apk add --no-cache \
    bash \
    git \
    curl \
    unzip \
    icu-dev \
    libzip-dev \
    postgresql-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    oniguruma-dev \
    linux-headers

RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS

RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    intl \
    zip \
    bcmath \
    gd \
    exif \
    pcntl \
    opcache

RUN pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini

CMD ["php-fpm"]
