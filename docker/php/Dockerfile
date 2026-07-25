FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git curl zip unzip ffmpeg nodejs npm wget \
    build-essential \
    libzip-dev libpng-dev libonig-dev libxml2-dev \
    libsndfile1 libgl1 \
    tesseract-ocr poppler-utils \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install \
        pdo_mysql mbstring bcmath gd zip pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

# Increase PHP upload limits
RUN echo "upload_max_filesize = 500M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 500M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY . /var/www

RUN composer install --no-dev --prefer-source --optimize-autoloader

CMD ["php-fpm"]