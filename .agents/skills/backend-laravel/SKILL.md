---
name: backend-laravel
description: Use for work under locker-backend/ involving Laravel, Filament, PHP, API resources, event-sourced workflows, or backend tests.
---

# Backend Laravel

Resolve paths below from the repository root unless they are Markdown links.
Read `locker-backend/composer.json` and `locker-backend/composer.lock` for required and resolved
versions; inspect sibling code before choosing framework APIs or file locations.

Keep controllers thin, validation in Form Requests, authorization in Policies,
and responses in JsonResources. Preserve the application's event-sourcing
boundaries; mock hardware in feature tests.

Load the references relevant to the change:

- [PHP and Laravel](references/php-laravel.md) for PHP, models, migrations,
  controllers, jobs, or application configuration.
- [Filament](references/filament.md) for admin resources, forms, tables,
  actions, authorization, and Livewire UI tests.
- [Verification](references/verification.md) for selecting and running backend
  tests, formatting, and static analysis.
- [Boost tooling](references/boost.md) for framework documentation, runtime
  inspection, Artisan discovery, or maintaining Boost configuration.

Load `api-contract-sync` for API or generated-client changes and
`open-locker-domain` for architecture or event-sourcing changes.
