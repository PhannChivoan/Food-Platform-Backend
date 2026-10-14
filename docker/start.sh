#!/bin/sh
# Container start: prepare Laravel, migrate, then hand over to supervisord (php-fpm + nginx).
cd /var/www/html || exit 1
art() { runuser -u www-data -- php artisan "$@"; }

# a mounted volume arrives root-owned
mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

art storage:link || true
art config:clear || true

# One-off database reset for hosts without a shell. Set DB_RESET=true, deploy once, then REMOVE the variable.
# DB_RESET_SEED=true also loads the demo data. WARNING: drops every table.
if [ "$DB_RESET" = "true" ]; then
  echo "db-reset: DB_RESET=true, rebuilding the schema (all existing data is dropped)"
  if [ "$DB_RESET_SEED" = "true" ]; then
    art migrate:fresh --force --seed && art db:seed --class=RealisticDataSeeder --force
  else
    art migrate:fresh --force
  fi
  echo "db-reset: finished. Remove DB_RESET from the environment variables now."
else
  art migrate --force || echo "WARNING: migrations failed, starting anyway"
fi

art optimize || true
echo "starting nginx + php-fpm on :8080"
exec /usr/bin/supervisord -c /etc/supervisor/app.conf