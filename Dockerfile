# Production image for the Steam Matchmaking Platform.
# The official PHP image ships curl and pdo_sqlite, so no extensions are
# needed and the application stays dependency-free.

FROM php:8.2-apache

RUN a2enmod rewrite headers deflate

COPY deploy/docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

COPY . /var/www/app

# SQLite database lives on a volume so it survives container replacement.
ENV DB_PATH=/var/www/data/database.sqlite
RUN mkdir -p /var/www/data && chown www-data:www-data /var/www/data \
    && chown -R www-data:www-data /var/www/app/public/avatars 2>/dev/null || true

VOLUME /var/www/data
EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
