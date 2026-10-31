FROM php:8.2-apache

RUN apt-get update && apt-get install -y git unzip libzip-dev default-mysql-client libonig-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow serving from /var/www/html/uploads when accessed via /uploads
RUN echo '<Directory /var/www/html/uploads>\n    Options Indexes FollowSymLinks\n    AllowOverride None\n    Require all granted\n</Directory>' > /etc/apache2/conf-available/uploads.conf \
    && a2enconf uploads

WORKDIR /var/www/html

COPY . .

RUN chown -R www-data:www-data /var/www/html/uploads /var/www/html/logs 2>/dev/null || true \
    && chmod -R 755 /var/www/html/public /var/www/html/uploads 2>/dev/null || true

EXPOSE 80
