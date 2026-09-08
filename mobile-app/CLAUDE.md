# CLAUDE.md — mobile-app

Mobile-specific agent rules, **shared with Cursor**. The source of truth is the
`.cursor/rules/` `.mdc` files; this file only imports them so Claude reads the same
content. Edit the `.mdc` files, not copies. Add a new `@import` line here only when
the team adds a brand-new rule file.

@.cursor/rules/expo-router-boundaries.mdc
@.cursor/rules/mobile-api-generation-flow.mdc
@.cursor/rules/mobile-code-quality-tools.mdc
@.cursor/rules/mobile-design-system-and-structure.mdc
@.cursor/rules/mobile-feature-architecture.mdc
@.cursor/rules/mobile-i18n-standards.mdc
@.cursor/rules/mobile-naming-imports.mdc
@.cursor/rules/mobile-testing-patterns.mdc
@.cursor/rules/redux-toolkit-patterns.mdc

Repo-wide rules come from the root `CLAUDE.md`.
