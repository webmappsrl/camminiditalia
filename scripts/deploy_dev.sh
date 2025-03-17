#!/bin/bash
set -e

echo "Develop deployment started ..."

php artisan down

composer install --no-interaction --prefer-dist --optimize-autoloader

# Clear and cache config
php artisan optimize

php artisan migrate --force

# gracefully terminate laravel horizon
php artisan horizon:terminate

php artisan up

echo "Deployment finished!"