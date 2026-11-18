FROM composer:2 AS composer

FROM php:8.4-cli-alpine AS build
WORKDIR /app
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN apk add --no-cache unzip \
    && composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader
COPY src/ src/
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

FROM php:8.4-cli-alpine
WORKDIR /app
COPY --from=build /app /app
COPY server.php ./
USER 1000:1000
EXPOSE 8000
CMD ["php", "-d", "memory_limit=192M", "server.php"]
