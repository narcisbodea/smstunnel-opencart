# PHP 7.4 with Apache, gd, zip, curl and mysqli, for testing OpenCart 3.0.3.x stores still on PHP 7.4.
# Built on an image that already has PHP 7.4 with gd and zip; only mysqli is added.
#   docker build -t smst-int-oc-php74:local tests/docker/php74
FROM prestashop/prestashop:8.1.7-7.4-apache
RUN docker-php-ext-install mysqli
