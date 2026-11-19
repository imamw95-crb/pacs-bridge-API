# Bridge SIMRS <-> Orthanc PACS — image produksi (Apache + PHP 8.3)
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libonig-dev libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" mbstring gd \
 && apt-get install -y --no-install-recommends fonts-dejavu-core \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers

# DocumentRoot diarahkan ke public/ (src, data, tools tetap di luar web root)
RUN printf '%s\n' \
    '<VirtualHost *:80>' \
    '    DocumentRoot /var/www/html/public' \
    '    <Directory /var/www/html/public>' \
    '        AllowOverride All' \
    '        Require all granted' \
    '        DirectoryIndex index.php index.html' \
    '    </Directory>' \
    '    ErrorLog ${APACHE_LOG_DIR}/error.log' \
    '    CustomLog ${APACHE_LOG_DIR}/access.log combined' \
    '</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html
RUN mkdir -p /var/www/html/data /var/lib/php/sessions \
 && chown -R www-data:www-data /var/www/html/data /var/lib/php/sessions \
 && echo 'session.save_path = "/var/lib/php/sessions"' > /usr/local/etc/php/conf.d/zz-session.ini

EXPOSE 80
