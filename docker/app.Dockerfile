FROM php:8.3-cli

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libldap-dev unzip \
    && docker-php-ext-configure ldap \
    && docker-php-ext-install ldap \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN COMPOSER_ROOT_VERSION=0.1.x-dev composer install --no-dev --no-interaction --no-progress --prefer-dist

COPY . .
RUN useradd --system --create-home --uid 10001 infraregister \
    && mkdir -p var \
    && chown -R infraregister:infraregister var

EXPOSE 8080

USER infraregister

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
