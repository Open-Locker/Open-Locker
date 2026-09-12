# Backend verification

## Runtime

Use the configured container runtime for local backend commands. From
`locker-backend/`, Sail uses `APP_SERVICE=app` from the local environment;
`docker compose exec -T app ...` is the direct alternative, including on Windows.
Inspect the compose configuration before starting services. CI provisions its
own PHP runtime and executes Composer scripts directly.

Treat `locker-backend/composer.json`, `phpunit.xml`, `phpstan.neon.dist`, and
`.github/workflows/mqtt-contract-ci.yml` as the command and configuration sources.

Examples from `locker-backend/` with the app container running:

```sh
docker compose exec -T app php artisan test --filter=RelevantTest
docker compose exec -T app composer format -- --dirty
docker compose exec -T app composer analyse
```

The same commands can use `vendor/bin/sail artisan`, `vendor/bin/sail composer`,
or `vendor/bin/sail php` where Sail is available.

## Choose checks for the change

Use PHPUnit classes and existing factories/fakes. For changed behavior, add or
update focused coverage for success, validation, authorization, and failure
paths that matter. Mock hardware and external services. Keep tests isolated
using the project's test configuration rather than a development database.

Run the affected test file or filter after changes; broaden when shared behavior
or failures justify it. Format changed PHP with Pint and run the relevant static
analysis. Use the Composer quality scripts for broader verification when needed.
Documentation-only edits need link, structure, and diff checks rather than new
application tests. Report checks that could not run and their concrete blocker.

For missing or stale frontend assets, inspect `locker-backend/package.json` and
the Vite setup, then use the configured package manager/build script inside the
chosen runtime.
