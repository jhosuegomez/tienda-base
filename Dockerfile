FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_mysql mbstring intl gd zip opcache \
 && docker-php-ext-enable opcache \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
 && chmod -R 755 /var/www/html \
 && mkdir -p /var/www/html/uploads/categories/retired /var/www/html/cache \
 && chown -R www-data:www-data /var/www/html/uploads /var/www/html/cache
EXPOSE 80
