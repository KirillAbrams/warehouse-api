# Основная среда приложения с закреплённой версией языка.
FROM php:8.4.27-fpm-alpine@sha256:511af6fa3ef55c4ea0c68bb47096ba4b81771ab4949bde7813807bf177efda11 AS runtime

# Берёт средство установки библиотек из образа с закреплённой версией.
COPY --from=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/local/bin/composer

# Добавляет работу с базой и дочерними процессами; средства сборки после установки удаляются.
RUN apk add --no-cache libpq unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev linux-headers \
    && docker-php-ext-install pdo_pgsql pcntl \
    && apk del .build-deps

# Задаёт рабочую папку и подключает настройки выполнения.
WORKDIR /var/www/html
COPY docker/php.ini /usr/local/etc/php/conf.d/warehouse.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-warehouse.conf

# Среда проверок дополняет основную среду измерением покрытия.
FROM runtime AS testing
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && curl -fsSL https://xdebug.org/files/xdebug-3.5.0.tgz -o /tmp/xdebug.tgz \
    && echo 'f6daf55a5c7adadb07dd2af25ff78b1cc9b1c58d6cc442a463eba015b678aabf  /tmp/xdebug.tgz' | sha256sum -c - \
    && pecl install /tmp/xdebug.tgz \
    && docker-php-ext-enable xdebug \
    && rm /tmp/xdebug.tgz \
    && apk del .build-deps
# При обычной работе измерение покрытия выключено.
ENV XDEBUG_MODE=off
