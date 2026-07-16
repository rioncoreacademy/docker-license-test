FROM php:8.3-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /app
COPY . /app

EXPOSE 80
CMD ["php", "-S", "0.0.0.0:80", "-t", "public", "public/index.php"]
