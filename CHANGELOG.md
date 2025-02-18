# Changelog

## 1.0.0 (2025-02-18)


### ⚠ BREAKING CHANGES

* laravel 11 and new wm-package
* updated to laravel 11

### Features

* Add database migrations for media, permissions, roles, and UGC ([995a244](https://github.com/webmappsrl/camminiditalia/commit/995a2441ed567ed30bf9a8d71e7f70c7af24cecb))
* Add database seeders for Media, UgcPoi, and UgcTrack ([384577d](https://github.com/webmappsrl/camminiditalia/commit/384577d9fa103c66ff5dd9f3864f328c8d466e7b))
* Add foreign key constraints to media and UGC tables ([0193160](https://github.com/webmappsrl/camminiditalia/commit/0193160bc56721a5ddc835815c269d3d539198b9))
* add github actions ([bf00e58](https://github.com/webmappsrl/camminiditalia/commit/bf00e58184fba2de908a451ad9d2554edef3c594))
* add last_login_at column to users table (published from wm-package) ([3eb56fe](https://github.com/webmappsrl/camminiditalia/commit/3eb56fe7319b16da7368be5887275098e39892c6))
* Add Nova resources for Media, UgcPoi, and UgcTrack ([93821a7](https://github.com/webmappsrl/camminiditalia/commit/93821a785be651922612fbc22e36b5083b9d1ef7))
* Add UGC section to Nova sidebar menu ([689455c](https://github.com/webmappsrl/camminiditalia/commit/689455c16c6a5c3546d1fc48fd3a622fd36c5173))
* configured log viewer ([1f0c069](https://github.com/webmappsrl/camminiditalia/commit/1f0c06991956553a174bdb377e0b23bb92c7c86f))
* enable code coverage xdebug feature on xdebug.ini oc: 4354 ([1a24374](https://github.com/webmappsrl/camminiditalia/commit/1a2437416f22adab474f6e74de634ba40774bfe8))
* laravel 11 and new wm-package ([7bd7913](https://github.com/webmappsrl/camminiditalia/commit/7bd79139340c25bbbb53ddf1bf51ba1466428d8a))
* Update composer autoload configuration for WmPackage factories ([930ee0f](https://github.com/webmappsrl/camminiditalia/commit/930ee0fd3a705de26c01ce53a10016c20475ccbb))
* updated compose and readme ([acb0111](https://github.com/webmappsrl/camminiditalia/commit/acb01115edd598d8111b9cb4c54d7b46997ebe44))
* updated to laravel 11 ([87715ca](https://github.com/webmappsrl/camminiditalia/commit/87715caa106cf25f041e6c06befb10f8531ee3b1))
* updated wm-package ([e8c8da1](https://github.com/webmappsrl/camminiditalia/commit/e8c8da10fbcc867a87890b2ca043d9454dea3f9d))


### Bug Fixes

* add --build flag to docker compose commands to avoid docker pull errors and instead build php container locally from dockerfile ([3cb7ed9](https://github.com/webmappsrl/camminiditalia/commit/3cb7ed9573b0538615890292ff4a85b5a70a5d3f))
* fixed Nova footer design and configuration ([7cff16e](https://github.com/webmappsrl/camminiditalia/commit/7cff16e52c7c25639d6537859a801b6a7f8c666b))
* github environment variables ([c90fe1b](https://github.com/webmappsrl/camminiditalia/commit/c90fe1ba5dff1964a3ba5f98e85aea4e04c6f380))
* init-docker script ([6366ccc](https://github.com/webmappsrl/camminiditalia/commit/6366ccc327b37aadd839048cd92b0c1a4583a71d))
* login error on frontend oc:4975 ([5c609fd](https://github.com/webmappsrl/camminiditalia/commit/5c609fd7fa81c80794f081639f7225cee515670b))
* phpstan ([262f9c8](https://github.com/webmappsrl/camminiditalia/commit/262f9c821d6eeb8d0cd989880ccf019952b44c30))
* remove hardcoded PHP image from docker compose (was pulling the image from docker hub and generating an error) ([83b445e](https://github.com/webmappsrl/camminiditalia/commit/83b445ea0689554a93526a31b0d4f382eae9c789))
* update .env-example DB_HOST for Docker development ([4dc4ebd](https://github.com/webmappsrl/camminiditalia/commit/4dc4ebd9fccf39764b9a44b6d455fd8d1c9294fc))
* update docker compose commands to remove --build flag and specify PHP image ([864f26a](https://github.com/webmappsrl/camminiditalia/commit/864f26ab01a937623598a8479cfcbbb9a0861b4a))
* update GitHub Actions and .env-example for local development ([e094546](https://github.com/webmappsrl/camminiditalia/commit/e094546576ac8f7b8bb9b6f15d58c74ef22e68c8))
* Update migration files for media and UGC tracks ([7e01eaa](https://github.com/webmappsrl/camminiditalia/commit/7e01eaaedf845a045d1d55973d5d0ecb1f56adfa))
* update Nova menu section request type import and usage ([17a99bd](https://github.com/webmappsrl/camminiditalia/commit/17a99bd0e11048de765170e957c54f68e6a2d755))
* update vscode debug path mapping for project ([a9453e1](https://github.com/webmappsrl/camminiditalia/commit/a9453e140ddf2dbe51cf1bb96cff6ff2f2ca834a))


### Miscellaneous Chores

* add .env-deploy configuration for github workflow ([71a9fc7](https://github.com/webmappsrl/camminiditalia/commit/71a9fc70ab25ad6245955bbc6daedea95fb37228))
* add git submodule update to deployment script ([79c5b7f](https://github.com/webmappsrl/camminiditalia/commit/79c5b7f7d4af76b529106a4337d2479df1e95d02))
* installed horizon and log viewer packages ([262ea7a](https://github.com/webmappsrl/camminiditalia/commit/262ea7a8c48221b749e05fba1430a3ee46842388))
* supervisor docker configuration ([4fe19fa](https://github.com/webmappsrl/camminiditalia/commit/4fe19fa3333074e717673ce067ae7201eef7e0a1))
* update .env-example with project-specific configuration ([29da511](https://github.com/webmappsrl/camminiditalia/commit/29da511d71bef9a6c6e2242eddda11a779c99436))
* Update composer dependencies for map-multi-linestring and map-point packages ([7886816](https://github.com/webmappsrl/camminiditalia/commit/7886816d8cdcc68d08d600e4b5dbc0c199a69630))
* update git submodule initialization in workflows and deployment script ([fb60639](https://github.com/webmappsrl/camminiditalia/commit/fb606394064c19b29a3e2323b977ed07f4d89fc5))
* updated dependencies ([25587f0](https://github.com/webmappsrl/camminiditalia/commit/25587f032339379bd7e24b8c4ea38835ee54c677))
* updated docker compose and dockerFile ([d2f8ab1](https://github.com/webmappsrl/camminiditalia/commit/d2f8ab1ebfd62a920d3ae8f69efc48659429ae59))
* updated laravel horizon and supervisor docker conf ([15f87e9](https://github.com/webmappsrl/camminiditalia/commit/15f87e93374ee9c765ff849aaf91d5cb7e8491ad))
* updated to php 8.3 ([617f65b](https://github.com/webmappsrl/camminiditalia/commit/617f65b96a52207b0d38aa1157ee99be6462aad6))
