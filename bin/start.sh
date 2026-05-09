#!/bin/bash

# Ensure storage structure exists
mkdir -p /var/www/html/storage/backups
mkdir -p /var/www/html/storage/cache/twig
mkdir -p /var/www/html/public/css

# Build Tailwind CSS (Production)
echo "Building production CSS..."
cd /var/www/html && npx tailwindcss -i ./public/css/input.css -o ./public/css/output.css --minify
chown -R www-data:www-data /var/www/html/public/css
chmod -R 755 /var/www/html/public/css

# SSL Setup
mkdir -p /etc/nginx/ssl
if [ -f /certs/certificate.crt ] && [ -f /certs/private.key ]; then
    echo "Using provided certificates from /certs..."
    if [ -f /certs/ca_bundle.crt ]; then
        cat /certs/certificate.crt /certs/ca_bundle.crt > /etc/nginx/ssl/nginx.crt
    else
        cp /certs/certificate.crt /etc/nginx/ssl/nginx.crt
    fi
    cp /certs/private.key /etc/nginx/ssl/nginx.key
elif [ ! -f /etc/nginx/ssl/nginx.crt ]; then
    echo "Generating self-signed certificate..."
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout /etc/nginx/ssl/nginx.key -out /etc/nginx/ssl/nginx.crt \
        -subj "/C=US/ST=State/L=City/O=Organization/CN=localhost"
fi

# Ensure .env exists and is writable
if [ ! -f /var/www/html/.env ]; then
    touch /var/www/html/.env
fi

# Set runtime permissions for volumes
chown -R www-data:www-data /var/www/html/storage
chown www-data:www-data /var/www/html/.env
chmod 664 /var/www/html/.env

# Ensure logs are writable
mkdir -p /var/log/nk-panel
touch /var/log/cron.log
touch /var/log/metrics_collector.log
chown -R www-data:www-data /var/log/nk-panel
chmod -R 777 /var/log/nk-panel
chown www-data:www-data /var/log/cron.log
chown www-data:www-data /var/log/metrics_collector.log

# Handle Worker Mode
if [ "$1" = "worker" ]; then
    echo "Starting Queue Worker..."
    # Run worker as root to maintain access to docker.sock and ssh
    exec php /var/www/html/bin/queue_worker.php
fi

# Start metrics collector in background as www-data
su -s /bin/bash -c "php /var/www/html/bin/collect_metrics.php >> /var/log/metrics_collector.log 2>&1 &" www-data

service cron start
# Start Nginx in background
nginx
# Start PHP-FPM in foreground
php-fpm
