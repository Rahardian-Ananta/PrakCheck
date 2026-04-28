FROM php:8.3-cli

# Install system dependencies dan PHP extensions yang dibutuhkan
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        zip \
        xml \
        dom \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files dulu (untuk cache layer)
COPY composer.json ./

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy seluruh project
COPY . .

# Expose port
EXPOSE 8080

# Jalankan PHP built-in server
# Pakai shell form (bukan exec form) agar $PORT bisa di-expand
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t public/ public/index.php"]
