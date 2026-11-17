#!/bin/sh
set -e

cd /var/www/html

# Esperar a que MySQL acepte conexiones
until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' > /dev/null 2>&1; do
    echo ">> Esperando a MySQL ($DB_HOST:$DB_PORT)..."
    sleep 3
done

# ¿Es la primera vez? (la tabla de migraciones todavía no existe)
if php artisan migrate:status > /dev/null 2>&1; then
    PRIMERA_VEZ=no
else
    PRIMERA_VEZ=si
fi

echo ">> Ejecutando migraciones..."
php artisan migrate --force

if [ "$PRIMERA_VEZ" = "si" ]; then
    echo ">> Base de datos nueva: cargando datos iniciales (seeders)..."
    php artisan db:seed --force
fi

# Cachear configuración para que la app sea más rápida
php artisan config:cache
php artisan view:cache

# Apache corre como www-data: debe poder escribir en storage y en las imágenes
chown -R www-data:www-data storage bootstrap/cache

echo ">> Listo. Iniciando Apache..."
exec "$@"