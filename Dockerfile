FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    libzip-dev libxml2-dev libicu-dev \
    libldap2-dev unzip curl \
		vim \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
       gd zip xml intl soap opcache \
       mysqli pdo pdo_mysql ldap \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN { \
    echo 'max_input_vars = 5000'; \
    echo 'memory_limit = 256M'; \
    echo 'upload_max_filesize = 100M'; \
    echo 'post_max_size = 100M'; \
    echo 'max_execution_time = 300'; \
} > /usr/local/etc/php/conf.d/moodle.ini

#RUN mkdir -p /var/data/www/public /var/data/moodledata
#RUN chown -R www-data:www-data /var/data/www/  /var/data/moodledata

# point apache document root to /public
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/data/www/public|g' /etc/apache2/sites-available/000-default.conf

# allow .htaccess and rewrites in public dir
RUN sed -i 's|<Directory /var/www/html>|<Directory /var/data/www/public>|g' /etc/apache2/apache2.conf 2>/dev/null || true

# add directory config explicitly
RUN echo '<Directory /var/data/www/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/sites-available/000-default.conf

EXPOSE 80
