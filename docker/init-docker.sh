#!/bin/bash

echo "Do you want to copy the env-example into the .env file? y/n"
read envcopy

if [[ $envcopy = y ]]
then
    cp .env-example .env
fi

echo "Do you want to use the develop version of docker containers? y/n"
read develop

if [[ $develop = y ]]
then
    docker compose -f develop.compose.yml up -d
else
    docker compose up -d
fi

echo "Do you want to install and activate xdebug? y/n"
read xdebug

if [[ $xdebug = y ]]
then
    bash docker/configs/phpfpm/init-xdebug.sh
fi
