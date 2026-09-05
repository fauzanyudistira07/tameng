FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

COPY . .
RUN composer dump-autoload --optimize

FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache \
    bash \
    git \
    curl \
    openssh-client \
    ca-certificates \
    docker-cli \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    zip \
  && docker-php-ext-install \
    bcmath \
    intl \
    mbstring \
    pdo_mysql \
    zip \
  && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
  && pecl install redis \
  && docker-php-ext-enable redis \
  && apk del .build-deps

COPY --from=vendor /app /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/secsys-entrypoint
COPY docker/start.sh /usr/local/bin/secsys-start

RUN echo "upload_max_filesize = 200M" > /usr/local/etc/php/conf.d/uploads.ini \
  && echo "post_max_size = 200M" >> /usr/local/etc/php/conf.d/uploads.ini \
  && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini

RUN chown -R www-data:www-data storage bootstrap/cache \
  && chmod -R ug+rw storage bootstrap/cache \
  && chmod +x /usr/local/bin/secsys-entrypoint /usr/local/bin/secsys-start

EXPOSE 9000

ENTRYPOINT ["secsys-entrypoint"]
CMD ["secsys-start"]
