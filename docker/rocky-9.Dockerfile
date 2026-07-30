FROM rockylinux:9

RUN dnf -y module reset php \
    && dnf -y module enable php:8.3 \
    && dnf -y install \
        git \
        php-cli \
        php-json \
        php-mbstring \
        php-process \
        php-xml \
        php-zip \
        unzip \
    && dnf clean all

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
