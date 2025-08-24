# Imagen con Apache + PHP 8.2
FROM php:8.2-apache

# Extensiones necesarias (mysqli, curl, gd, intl opcional)
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libonig-dev libxml2-dev libicu-dev \
 && docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install gd mysqli intl \
 && a2enmod rewrite headers

# Config Apache: apuntar DocumentRoot a /var/www/html/public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# Ajustes PHP útiles para subir imágenes, etc.
COPY .docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Copia código
COPY . /var/www/html

# Permisos (por si subís archivos a /var/www/html/uploads)
RUN mkdir -p /var/www/html/tmp /var/www/html/uploads \
 && chown -R www-data:www-data /var/www/html \
 && find /var/www/html -type d -exec chmod 755 {} \; \
 && find /var/www/html -type f -exec chmod 644 {} \;

# Exponer puerto (Render usa $PORT, apache escucha en 80)
EXPOSE 80
