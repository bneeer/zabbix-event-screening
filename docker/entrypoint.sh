#!/bin/sh
# Container entrypoint.
#
#  entrypoint daemon            -> run migrations, then start the polling daemon (default)
#  entrypoint screen INC0001    -> screen a single ticket
#  entrypoint migrate|db:check  -> any bin/console command
#  entrypoint <other binary>    -> executed as-is (e.g. `sh`, `php -v`)
set -eu

cd /app

case "${1:-daemon}" in
    daemon)
        # Fail fast on configuration errors before entering the loop.
        php bin/console config:check
        php bin/console migrate
        exec php bin/console daemon
        ;;
    screen|migrate|migrate:status|migrate:rollback|db:check|config:check|help)
        exec php bin/console "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
