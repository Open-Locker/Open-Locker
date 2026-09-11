# Agent setup

The layout follows [Sentry's JavaScript repository](https://github.com/getsentry/sentry-javascript):

```text
AGENTS.md                     # canonical repository instructions
CLAUDE.md -> AGENTS.md
.agents/skills/               # canonical skill folders
.claude/skills -> ../.agents/skills
.cursor/skills -> ../.agents/skills
```

Edit the canonical files. The links are relative Git symlinks, so they work
across checkout locations. Add new workflows under `.agents/skills/<name>/SKILL.md`;
the two skill links expose them without copying files. The root `AGENTS.md`
is the shared instruction map; component directories have no separate loaders.

## Backend guidance

`.agents/skills/backend-laravel/SKILL.md` routes backend work to focused references
for PHP/Laravel conventions, Filament, verification, and Boost tooling. Load only
the references needed for the current task. Read package versions from Composer
rather than maintaining a duplicate inventory in instructions.

`locker-backend/boost.json` disables generated guidelines while retaining MCP.
Ordinary `boost:update` runs will not recreate component instruction files with
the current configuration. Review explicit `boost:install --guidelines` runs,
which can re-enable generation. See the [research and rationale](research/agent-instruction-layout.md)
for the source-backed choice of skills with references instead of specs or
multiple overlapping framework skills.

## Windows checkouts

Enable Windows Developer Mode (or use an account with symlink privileges),
then clone with symlinks enabled:

```sh
git clone -c core.symlinks=true https://github.com/Open-Locker/Open-Locker.git
```

For an existing checkout, set `git config core.symlinks true` before checking
out this layout. Git with `core.symlinks=false` checks links out as plain text
containing the target path; those files do not provide working skill mirrors.
Changing the setting alone does not convert already checked-out placeholders;
use a fresh clone with the setting above if they are already present.

## PR writer

`.agents/skills/pr-writer/SKILL.md` is original project guidance informed by
[PR templates and contributor guidance from prominent projects](research/pull-request-writing.md).
It uses consistent Summary and Testing sections, adds context or compatibility
details when relevant, and follows an existing repository template when present.
The skill includes concise examples and distinguishes drafting text from
publishing a PR.
