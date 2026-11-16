FROM php:8.4-apache-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libpq-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql intl mbstring zip opcache gd \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/nolviko.ini
WORKDIR /var/www/html
RUN mkdir -p /var/www/storage && chown www-data:www-data /var/www/storage
ENV FORUM_STORAGE_DIR=/var/www/storage
COPY config/runtime-policy.php docker/check-runtime.php docker/start.sh /opt/nolviko/
USER www-data
RUN php /opt/nolviko/check-runtime.php
USER root
FROM base AS application
COPY composer.json composer.lock LICENSE NOTICE THIRD_PARTY_NOTICES.md ./
# The committed vendor/ is the production install (composer install --no-dev); refuse a development vendor.
COPY vendor/ vendor/
RUN php -r '$i = json_decode(file_get_contents("vendor/composer/installed.json"), true); exit(($i["dev"] ?? true) === false && ($i["dev-package-names"] ?? [1]) === [] ? 0 : 1);'
COPY worker.php worker.php
COPY src/ src/
COPY config/bootstrap.php config/runtime-policy.php config/
# The browser installer writes config/local.php (M01.14); the shipped files stay root-owned.
RUN chown www-data:www-data config && chmod 0750 config
COPY translations/ translations/
COPY views/ views/
COPY themes/ themes/
COPY docs/licenses/runtime/ docs/licenses/runtime/
COPY docs/sbom/runtime.spdx.json docs/sbom/runtime.spdx.json
COPY public/ public/
COPY docker/check-db.php /opt/nolviko/check-db.php
ENTRYPOINT ["/bin/sh", "/opt/nolviko/start.sh"]
CMD ["apache2-foreground"]
