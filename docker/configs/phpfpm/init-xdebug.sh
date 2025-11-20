#!/bin/bash

source .env
containerName="php-$APP_NAME"
docker exec -it -u 0 $containerName pecl install xdebug

docker cp ./docker/configs/phpfpm/xdebug.ini $containerName:/usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

docker restart $containerName
