# ローカル開発用イメージ。
# heteml本番はCGI版PHPだが、ローカルではApache+mod_php構成で差し支えない
# （基本設計書_Phase2.md §10 参照）。PHPバージョンのみ本番（8.5）に合わせる。
FROM php:8.5-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libzip-dev \
        libonig-dev \
        libcurl4-openssl-dev \
        unzip \
        git \
    && docker-php-ext-install pdo_mysql zip mbstring curl \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
