FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        mysqli \
        pdo_mysql \
        intl \
        mbstring \
        xml \
        zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY src/web/ /var/www/html/

RUN a2enmod rewrite \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
