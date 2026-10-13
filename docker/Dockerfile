FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev tzdata \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

ENV TZ=Asia/Tokyo \
    COMPOSER_ALLOW_SUPERUSER=1
RUN echo "date.timezone=Asia/Tokyo" > /usr/local/etc/php/conf.d/timezone.ini

WORKDIR /app
