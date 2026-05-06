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
    openssl \
    nodejs \
    npm \
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

# Build Tailwind CSS (moved to start.sh to support volume mounts)
RUN npm install

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

# Copy and prepare startup script
COPY bin/start.sh /start.sh
RUN chmod +x /start.sh

# Expose port 80 and 443
EXPOSE 80 443

CMD ["/start.sh"]
