FROM richarvey/nginx-php-fpm:3.1.6

COPY . .

# Image config
ENV SKIP_COMPOSER 1
ENV WEBROOT /var/www/html/public
ENV PHP_ERRORS_STDERR 1

# Laravel config
ENV RUN_SCRIPTS 1
ENV APP_ENV production
ENV APP_DEBUG false

EXPOSE 80
