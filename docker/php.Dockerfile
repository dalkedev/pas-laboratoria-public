FROM php:8.4-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_mysql gd \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername
RUN mkdir -p /var/www/html/storage/mycloud && chown -R www-data:www-data /var/www/html/storage
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
WORKDIR /var/www/html
