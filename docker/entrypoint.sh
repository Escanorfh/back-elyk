#!/bin/bash
set -e

# Render injects env vars at container start, so migrate + cache here (not at build time).
php artisan migrate --force
php artisan config:cache

exec apache2-foreground
