# =====================================================================
# Imagen personalizada del sistema RSU Reciclaje (Laravel 12 + PHP 8.2)
# Base: imágenes oficiales de Docker Hub (node, composer, php:apache)
# =====================================================================

# ---------- Etapa 1: compilar CSS y JS con Vite (solo se usa al construir) ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci
COPY resources ./resources
RUN npm run build


# ---------- Etapa 2: imagen final con PHP 8.2 + Apache HTTP Server ----------
FROM php:8.2-apache

# Zona horaria de Perú
ENV TZ=America/Lima

# Extensiones de PHP que necesita Laravel y el proyecto
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip \
    && docker-php-ext-install pdo_mysql intl zip bcmath exif opcache \
    && rm -rf /var/lib/apt/lists/*

    # Apache: servir la carpeta public/ de Laravel y activar URLs amigables
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
 && a2enmod rewrite

# Composer (copiado de la imagen oficial composer:2)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 1) Dependencias de PHP (se copian primero para aprovechar la caché de Docker)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# 2) Código de la aplicación y archivos compilados por Vite
COPY . .
COPY --from=assets /app/public/build ./public/build

# 3) Autoload optimizado, enlace public/storage y permisos para Apache
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
 && composer dump-autoload --optimize --no-dev \
 && php artisan storage:link \
 && chown -R www-data:www-data storage bootstrap/cache

# Script que prepara la base de datos al arrancar el contenedor
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

# Carpeta de imágenes subidas (logos, fotos, vehículos): se guarda en un volumen
VOLUME ["/var/www/html/storage/app/public"]

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
