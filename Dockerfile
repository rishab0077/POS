FROM php:8.2-fpm-alpine AS php-base

RUN apk add --no-cache \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libwebp \
        libzip \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && apk del .build-deps

COPY docker/production.ini /usr/local/etc/php/conf.d/production.ini

WORKDIR /var/www/html

FROM php-base AS build

RUN apk add --no-cache git nodejs npm unzip zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN composer dump-autoload --optimize \
    && npm run build \
    && rm -rf public/storage \
    && ln -s ../storage/app/public public/storage

FROM build AS test

RUN cp .env.production.example .env \
    && composer install --no-interaction --no-progress \
    && composer dump-autoload

FROM nginx:alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /var/www/html/public /var/www/html/public

FROM php-base AS runtime

RUN apk add --no-cache mariadb-client mariadb-connector-c su-exec

COPY . .
COPY --from=build /var/www/html/vendor ./vendor
COPY --from=build /var/www/html/public ./public
COPY --from=build /var/www/html/bootstrap/cache ./bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/restaurant-pos-entrypoint

RUN chmod +x /usr/local/bin/restaurant-pos-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["restaurant-pos-entrypoint"]
CMD ["php-fpm"]
