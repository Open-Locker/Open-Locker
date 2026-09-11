# PHP and Laravel

Use strict types in new PHP files, typed parameters and return values, and
braces for control flow. Prefer constructor property promotion for injected
dependencies. Add PHPDoc for useful array shapes and generics; reserve explanatory
comments for non-obvious behavior. Follow existing enum and model cast conventions.

Keep changes within the existing feature structure. Use the installed Artisan
generators when creating Laravel classes; inspect command help and pass
`--no-interaction`. Use the runtime described in [verification](verification.md).

## Persistence and application behavior

- Prefer typed Eloquent relationships and model queries. Eager-load relations
  used in loops; use the query builder when the operation warrants it.
- Preserve all existing column attributes when altering a column in a migration.
  Check the actual schema and previous migrations before changing it.
- Use factories and existing factory states for test data; add factories or
  seeders where the new model's workflow needs them.
- Use Form Requests for validation and follow sibling rule/message conventions.
  Enforce authorization through the existing gates, policies, and Sanctum setup.
- Follow existing API routes and JsonResources rather than introducing a new
  versioning or response convention within an unrelated change.
- Use named routes for application links, queued jobs for time-consuming work,
  and `config()` for application settings. Read `env()` only in config files.

## Framework structure

Inspect `locker-backend/bootstrap/app.php` for middleware, routing, and exception
registration, `locker-backend/bootstrap/providers.php` for providers, and
`locker-backend/routes/console.php`
for console setup. Match the installed framework and repository structure rather
than introducing legacy HTTP or console kernels.

Verify version-sensitive behavior with the installed source or the documentation
workflow in [Boost tooling](boost.md).
