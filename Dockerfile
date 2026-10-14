# Production image: nginx + PHP-FPM under supervisord (works behind platform entrypoint wrappers). Listens on 8080.
# dev packages stay installed on purpose: the demo seeders use Faker.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-scripts

FROM php:8.3-fpm-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor curl \
    && rm -rf /var/lib/apt/lists/*
ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/2.7.10/install-php-extensions /usr/local/bin/install-php-extensions
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_mysql gd exif bcmath intl zip opcache

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/app.conf

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=755 docker/start.sh /usr/local/bin/start.sh
EXPOSE 8080
CMD ["/usr/local/bin/start.sh"]