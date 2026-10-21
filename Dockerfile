FROM php:8.5-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libzip-dev libicu-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring gd zip intl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && mkdir -p storage/logs storage/cache storage/private storage/backups storage/sessions \
    && chown -R www-data:www-data storage \
    && chmod -R 700 storage
COPY config/apache.conf /etc/apache2/sites-available/000-default.conf
COPY config/php-production.ini /usr/local/etc/php/conf.d/yasig.ini
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD php -r "exit(@file_get_contents('http://127.0.0.1/health') === '{\"status\":\"ok\"}' ? 0 : 1);"
