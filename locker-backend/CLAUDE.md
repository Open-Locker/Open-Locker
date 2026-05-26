# CLAUDE.md — locker-backend

Backend-specific agent rules, **shared with Cursor**. The source of truth is the
`.cursor/rules/` `.mdc` files; this file only imports them so Claude reads the same
content. Edit the `.mdc` files, not copies. Add a new `@import` line here only when
the team adds a brand-new rule file.

@.cursor/rules/general/coding-style.mdc
@.cursor/rules/general/testing-guidelines.mdc
@.cursor/rules/general/open-locker-domain.mdc
@.cursor/rules/frameworks/laravel-guidelines.mdc
@.cursor/rules/frameworks/modbus-integration.mdc
@.cursor/rules/frameworks/scramble-openapi.mdc

Laravel/Boost framework guidelines live in `AGENTS.md` (auto-loaded alongside this
file). Repo-wide rules come from the root `CLAUDE.md`.
