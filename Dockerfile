FROM php:8.5-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    sshpass \
    openssh-client \
    cron \
    nginx \
    default-mysql-client \
    iputils-ping \
    fping \
    && docker-php-ext-install pdo_mysql \
    && docker-php-ext-install mbstring \
    && docker-php-ext-install exif \
    && docker-php-ext-install pcntl \
    && docker-php-ext-install bcmath \
    && docker-php-ext-install gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Set system timezone to Moscow (GMT+3)
ENV TZ=Europe/Moscow
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Configure PHP upload limits
RUN echo "upload_max_filesize=100M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size=100M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit=256M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "display_errors=Off" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "log_errors=On" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "date.timezone=Europe/Moscow" >> /usr/local/etc/php/conf.d/uploads.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-security-blocking

# Configure Nginx
COPY nginx.conf /etc/nginx/sites-available/default

# Configure OPcache
COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Create Twig cache directory
RUN mkdir -p /var/www/html/storage/cache/twig

# Create SSH ControlMaster socket directory
RUN mkdir -p /tmp/ssh_mux

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/public \
    && chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /tmp/ssh_mux

# Setup cron jobs
RUN echo "* * * * * www-data cd /var/www/html && /usr/local/bin/php bin/run_backup.php --cron >> /var/log/cron.log 2>&1" > /etc/cron.d/amnezia-cron \
    && chmod 0644 /etc/cron.d/amnezia-cron \
    && touch /var/log/cron.log \
    && chown www-data:www-data /var/log/cron.log

# Create startup script that starts all services
RUN echo '#!/bin/bash\n\
# Ensure storage structure exists\n\
mkdir -p /var/www/html/storage/backups\n\
mkdir -p /var/www/html/storage/cache/twig\n\
\n\
# Ensure .env exists and is writable\n\
if [ ! -f /var/www/html/.env ]; then\n\
    touch /var/www/html/.env\n\
fi\n\
\n\
# Set runtime permissions for volumes\n\
chown -R www-data:www-data /var/www/html/storage\n\
chown www-data:www-data /var/www/html/.env\n\
chmod 664 /var/www/html/.env\n\
\n\
# Ensure logs are writable\n\
mkdir -p /var/log/nk-panel\n\
touch /var/log/cron.log\n\
touch /var/log/metrics_collector.log\n\
chown -R www-data:www-data /var/log/nk-panel\n\
chmod -R 755 /var/log/nk-panel\n\
chown www-data:www-data /var/log/cron.log\n\
chown www-data:www-data /var/log/metrics_collector.log\n\
\n\
# Start metrics collector in background as www-data\n\
su -s /bin/bash -c "php /var/www/html/bin/collect_metrics.php >> /var/log/metrics_collector.log 2>&1 &" www-data\n\
\n\
service cron start\n\
# Start Nginx in background\n\
nginx\n\
# Start PHP-FPM in foreground\n\
php-fpm' > /start.sh \
    && chmod +x /start.sh

# Expose port 80
EXPOSE 80

CMD ["/start.sh"]
