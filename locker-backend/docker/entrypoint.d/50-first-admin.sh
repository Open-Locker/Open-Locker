#!/bin/bash
#
# Create the administrator named by ADMIN_EMAIL, if there is not one already.
#
# Gated on AUTORUN_ENABLED because every container in the stack runs the
# entrypoint, but only the app container has it set to true — the same flag that
# decides which container runs migrations. Without the gate, six containers would
# race to create the same account on every deploy.
#
# The command is a no-op when ADMIN_EMAIL is unset or an admin already exists, so
# this is safe on every start rather than only the first.

set -e

if [ "$AUTORUN_ENABLED" != "true" ]; then
    exit 0
fi

php /var/www/html/artisan first-admin:create
