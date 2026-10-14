# Production image: nginx + PHP-FPM with OPcache (serversideup/php). Listens on 8080.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM serversideup/php:8.3-fpm-nginx
USER root
RUN install-php-extensions gd exif bcmath intl zip
USER www-data
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
COPY --chmod=755 --chown=www-data:www-data docker/45-db-reset.sh /etc/entrypoint.d/45-db-reset.sh
# runs `php artisan migrate --force` and `storage:link` on start, and caches config/routes
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION_ISOLATION=false \
    PHP_OPCACHE_ENABLE=1 \
    SSL_MODE=off