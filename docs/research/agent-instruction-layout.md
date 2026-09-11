# Agent instruction layout

Researched 2026-09-11.

## Recommendation

Keep one root `AGENTS.md` as a short repository map, with root `CLAUDE.md`
symlinked to it. Keep canonical skills in root `.agents/skills/`, exposed through
the existing `.claude/skills` and `.cursor/skills` symlinks. Remove component
instruction files once their useful guidance has moved into those shared skills.

For backend work, retain one `backend-laravel` skill with a short entry point and
focused reference files for implementation conventions, Filament, and
verification/tooling. The entry point must state when each reference is needed.
Keep API contract synchronization and domain architecture in their existing
skills. This is a repository-specific recommendation, not a requirement of the
skill format.

The backend document contains framework guidance and development procedures,
not feature acceptance criteria. Reference files inside its skill fit that
content better than a new `specs/` directory. Separate skills become useful when
there is an independently invoked workflow; creating one for every framework
heading would multiply discovery descriptions and maintenance without a clear
benefit here. No application architecture changes are involved, so this layout
change does not need an application ADR.

## Evidence

- Agent Skills supports a small `SKILL.md` plus optional `references/`. Metadata
  is discovered first, instructions load on activation, and resources load as
  needed. It recommends focused reference files and direct relative links from
  the entry point. This supports a backend router with conditional references.
  [Agent Skills specification](https://agentskills.io/specification)
- Claude recommends concise always-loaded instructions and moving component
  procedures to skills or scoped rules. Its documentation explicitly supports
  `CLAUDE.md` as a symlink to `AGENTS.md`; Markdown imports load at startup, so
  importing every backend reference would lose the conditional loading benefit.
  [Claude memory documentation](https://code.claude.com/docs/en/memory)
- Claude's skill documentation recommends supporting files for detailed
  references, linked from `SKILL.md` with explanations of when to read them.
  [Claude skills documentation](https://code.claude.com/docs/en/skills#add-supporting-files)
- Laravel Boost distinguishes upfront guidelines from task-specific skills and
  supports skill reference material. Its generated resources can be refreshed
  by `boost:update`, so generation settings must agree with a hand-maintained
  root layout.
  [Laravel Boost documentation](https://laravel.com/framework/docs/13.x/boost)
- Sentry keeps shared skills in `.agents/skills` and its root `CLAUDE.md` is a
  symbolic link targeting `AGENTS.md`.
  [Sentry shared directory](https://github.com/getsentry/sentry-javascript/tree/develop/.agents),
  [Sentry CLAUDE.md](https://github.com/getsentry/sentry-javascript/blob/develop/CLAUDE.md)

## Boost regeneration

Open-Locker locks `laravel/boost` at v2.2.0, commit
`b4c5bed7b45e9cd9f705ef3ab1157d437376323c`. Its installed `UpdateCommand` reads the
saved guideline and skill settings. When both are disabled it returns success
without installation; when skills remain enabled it passes only the enabled
features to the installer. Therefore setting `guidelines` to `false` in
`locker-backend/boost.json` prevents ordinary `boost:update` from recreating
component guidelines while leaving the configured MCP capability intact.
An explicit future `boost:install --guidelines` can re-enable generation; this
setting is not a permanent prohibition. This conclusion comes from the locked
implementation, rather than assuming the latest Boost documentation describes
the installed version.
[Boost v2.2.0 UpdateCommand](https://github.com/laravel/boost/blob/b4c5bed7b45e9cd9f705ef3ab1157d437376323c/src/Console/UpdateCommand.php),
[Boost v2.2.0 InstallCommand](https://github.com/laravel/boost/blob/b4c5bed7b45e9cd9f705ef3ab1157d437376323c/src/Console/InstallCommand.php)

## Maintenance

- Keep root routing explicit: backend changes require the backend skill;
  Filament changes require its Filament reference.
- Resolve package versions and runnable commands from manifests, lockfiles,
  CI, and the source tree. Preserve repository constraints instead of copying
  entire framework tutorials or stale environment assumptions.
- Keep canonical content in one place. Relative symlinks provide discovery
  adapters; removing component aliases must not leave broken links or stale
  references.
- Treat the references as maintained project guidance. Review relevant upstream
  guidance when upgrading frameworks instead of restoring generated nested
  instruction files.
