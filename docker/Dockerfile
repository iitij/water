# خادم التطوير/الاختبار (خطة 2.0 — S1، بطاقة S1-06).
# صورة واحدة تشغّل Laravel مع بيانات مزروعة، ليست صورة إنتاج نهائية.
FROM php:8.3-cli

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev libonig-dev libxml2-dev default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql zip mbstring bcmath gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist --no-progress

COPY . .

# بيئة افتراضية بلا أسرار؛ المتغيرات الفعلية تأتي من docker-compose.
RUN if [ ! -f .env ]; then cp .env.docker.example .env; fi \
    && php artisan key:generate --force

EXPOSE 8000

# الترحيل والبذر ثم التشغيل: أمر واحد يعطي بيئة قابلة للاختبار.
CMD ["sh", "-c", "php artisan migrate --force && php artisan db:seed --force && php artisan serve --host=0.0.0.0 --port=8000"]
