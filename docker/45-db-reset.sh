#!/bin/sh
# One-off database reset for hosts without a shell. Set DB_RESET=true, deploy once, then REMOVE the variable.
# DB_RESET_SEED=true also loads the demo data. WARNING: drops every table.
if [ "$DB_RESET" = "true" ]; then
  echo "db-reset: DB_RESET=true, rebuilding the schema (all existing data is dropped)"
  if [ "$DB_RESET_SEED" = "true" ]; then
    # base data (roles, users, ...) first, then the realistic Phnom Penh restaurants on top of it
    php /var/www/html/artisan migrate:fresh --force --seed && \
      php /var/www/html/artisan db:seed --class=RealisticDataSeeder --force
  else
    php /var/www/html/artisan migrate:fresh --force
  fi
  echo "db-reset: finished. Remove DB_RESET from the environment variables now."
fi