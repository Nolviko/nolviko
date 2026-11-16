#!/bin/sh
set -eu
# Check storage writability as the Apache user, not root (runuser keeps the environment).
if [ "$(id -u)" = "0" ]; then
    runuser -u www-data -- php /opt/nolviko/check-runtime.php
else
    php /opt/nolviko/check-runtime.php
fi
exec docker-php-entrypoint "$@"
