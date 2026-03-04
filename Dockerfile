FROM php:8.2-apache-bookworm

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libzip-dev libxml2-dev libxslt1-dev libsodium-dev \
    libcurl4-openssl-dev libbz2-dev libonig-dev \
    unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd mbstring curl zip xml xsl soap \
        pdo pdo_mysql mysqli \
        exif bz2 sockets pcntl \
        opcache sodium intl gettext \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers expires deflate

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN echo '\
upload_max_filesize = 64M\n\
post_max_size = 64M\n\
memory_limit = 256M\n\
max_execution_time = 300\n\
display_errors = On\n\
error_reporting = E_ALL\n\
' > /usr/local/etc/php/conf.d/custom.ini

ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

EXPOSE 80
