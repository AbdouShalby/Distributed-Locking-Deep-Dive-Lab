FROM php:8.4-cli-alpine

# Install Redis extension and shmop
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pcntl shmop sysvshm \
    && apk del $PHPIZE_DEPS

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --prefer-dist 2>/dev/null || true

COPY . .

# Install dependencies if composer.json exists
RUN if [ -f composer.json ]; then composer install --no-interaction --prefer-dist; fi

CMD ["php", "-a"]
