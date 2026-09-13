#!/bin/bash
# Юнит-тесты ядра в изолированном контейнере (prod-образ + dev-зависимости, кэш vendor в /root/.dd-test-vendor).
set -e
mkdir -p /root/.dd-test-vendor
docker run --rm -u 0 -v /opt/raspredelenie/server:/app -v /root/.dd-test-vendor:/app/vendor -w /app --entrypoint sh raspredelenie-app -c '
  [ -x vendor/bin/phpunit ] || composer install --no-interaction --no-progress -q
  php vendor/bin/phpunit --no-coverage "$@"' -- "$@"
