#!/usr/bin/env bash
set -euo pipefail

echo "▶ Récupération du code..."
git pull origin main

echo "▶ Mode maintenance activé..."
php artisan down --retry=15

echo "▶ Dépendances Composer..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo "▶ Migrations..."
php artisan migrate --force

echo "▶ Build des assets..."
npm ci --prefer-offline
npm run build

echo "▶ Vidage et rechargement des caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "▶ Redémarrage de la file d'attente..."
php artisan queue:restart

echo "▶ Mode maintenance désactivé..."
php artisan up

echo "✔ Mise à jour terminée."
