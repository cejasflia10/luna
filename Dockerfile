# Dockerfile
FROM php:8.2-apache

# Dependencias para extensiones
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev \
 && rm -rf /var/lib/apt/lists/*

# Extensiones PHP necesarias
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install gd zip mysqli pdo pdo_mysql

# Docroot a /public y rewrite
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN a2enmod rewrite \
 && sed -ri -e 's!DocumentRoot /var/www/html!DocumentRoot ${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!<Directory /var/www/>!<Directory ${APACHE_DOCUMENT_ROOT}>!g' /etc/apache2/apache2.conf || true \
 && printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n  AllowOverride All\n  Require all granted\n</Directory>\n' > /etc/apache2/conf-available/public-dir.conf \
 && a2enconf public-dir

# Copiá el código (tu repo debe tener /public, /includes, etc.)
COPY . /var/www/html/

# Permisos (apache = www-data)
RUN chown -R www-data:www-data /var/www/html
