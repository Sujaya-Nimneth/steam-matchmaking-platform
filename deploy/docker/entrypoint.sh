#!/bin/sh
# Container entrypoint: prepare the database volume, then run Apache in the
# foreground. php:8.2-apache ships curl and pdo_sqlite, matching the app.

set -e

DB="${DB_PATH:-/var/www/data/database.sqlite}"

if [ ! -f "$DB" ]; then
    echo "Initializing database at $DB"
    php /var/www/app/db/init.php
fi

# In demo mode, seed an empty database so the container is instantly usable.
if [ "${DEMO_MODE:-0}" = "1" ]; then
    php /var/www/app/db/seed.php --if-empty
fi

exec apache2-foreground
