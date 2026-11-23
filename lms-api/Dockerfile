# fixerMarker: 2026-11-22 quiz-share-export-results-bank-picker
FROM php:8.2-apache

RUN apt-get update && apt-get install -y git unzip libzip-dev default-mysql-client libonig-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Serve /uploads from /var/www/html/uploads with hardening:
# - no directory listings or CGI execution
# - script extensions are denied outright
# - nosniff stops browsers from reinterpreting uploaded bytes as HTML/JS
RUN a2enmod headers setenvif \
    && printf '%s\n' \
        'Alias /uploads /var/www/html/uploads' \
        '<Directory /var/www/html/uploads>' \
        '    Options -Indexes -ExecCGI -MultiViews' \
        '    AllowOverride None' \
        '    Require all granted' \
        '    SetEnvIfNoCase Origin "^(https?://.+)$" LMS_UPLOAD_ORIGIN=$1' \
        '    Header always set Access-Control-Allow-Origin "%{LMS_UPLOAD_ORIGIN}e" env=LMS_UPLOAD_ORIGIN' \
        '    Header always set Access-Control-Allow-Methods "GET, OPTIONS" env=LMS_UPLOAD_ORIGIN' \
        '    Header always set Access-Control-Allow-Headers "Authorization" env=LMS_UPLOAD_ORIGIN' \
        '    Header always merge Vary "Origin" env=LMS_UPLOAD_ORIGIN' \
        '    <FilesMatch "\.(php|phtml|phar|php[0-9]|cgi|pl|py|sh|shtml|htaccess)$">' \
        '        Require all denied' \
        '    </FilesMatch>' \
        '    Header always set X-Content-Type-Options "nosniff"' \
        '</Directory>' \
        > /etc/apache2/conf-available/uploads.conf \
    && a2enconf uploads

# Align PHP limits with the 100 MB ceiling enforced by FileUploadController.
# Without this the stock image caps uploads at 2 MB / 8 MB post size.
RUN printf '%s\n' \
        'upload_max_filesize = 100M' \
        'post_max_size = 105M' \
        'memory_limit = 256M' \
        'max_execution_time = 300' \
        'max_input_time = 300' \
        > /usr/local/etc/php/conf.d/lms-uploads.ini

WORKDIR /var/www/html

COPY . .

RUN mkdir -p /var/www/html/uploads /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/logs \
    && chmod -R 755 /var/www/html/public /var/www/html/uploads

EXPOSE 80
