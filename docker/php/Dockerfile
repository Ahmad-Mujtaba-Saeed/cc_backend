FROM php:8.3-fpm

# No build-essential: the Debian php images already carry the compiler
# toolchain (PHPIZE_DEPS) that `pecl install` needs. No nodejs/npm: nothing
# in the PHP code runs node (Remotion renders in its own service). No python
# libs or OCR tools either: all of that runs in the `ai` container. ffmpeg
# stays — the processors call ffmpeg/ffprobe directly.
RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl zip unzip ffmpeg wget \
    libzip-dev libpng-dev libonig-dev libxml2-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install \
        pdo_mysql mbstring bcmath gd zip pcntl opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

# Increase PHP upload limits
RUN echo "upload_max_filesize = 500M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 500M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini

# OPcache (see the file). 1 = dev-friendly default; set 0 in production.
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

# The request pool (see the file: the stock 5 children are what makes the
# storyboard page reset connections on this machine).
COPY docker/php/www-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Dependencies before source, so editing app code doesn't re-download every
# package. --prefer-dist downloads zips; --prefer-source git-cloned each one.
COPY composer.json composer.lock /var/www/
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY . /var/www
RUN composer dump-autoload --no-dev --optimize --no-scripts

CMD ["php-fpm"]
