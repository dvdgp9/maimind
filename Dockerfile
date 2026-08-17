# PHP 8.3, la misma versión que produccion (8.3.30 sobre Ubuntu 22.04 + HestiaCP).
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        unzip \
        git \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        opcache \
        pdo_mysql \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8080

# Servidor embebido de PHP con index.php como router: los ficheros estáticos de
# public/ se sirven directamente y el resto va al front controller.
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
