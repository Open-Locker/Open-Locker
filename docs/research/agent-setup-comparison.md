# Agent setup comparison

## Question

Is the `mod/agents` setup better than the previous Claude/Cursor rule setup?

## Sources

- The repository baseline on `main`, inspected with `git ls-tree` and `git show`.
- The current repository configuration in `agents.toml`, `.agents/skills/`, and
  `.agents/agents/`.
- [dotagents README](https://github.com/getsentry/dotagents/blob/main/README.md),
  the upstream source of the project-local agent-management model.

## Findings

### What improved

1. **One canonical copy.** The old setup duplicated the GitHub issue skill in
   `.claude/skills/` and `.cursor/skills/`, and kept large guidance bodies in
   multiple component rule trees. The new setup keeps project skills in
   `.agents/skills/` and declares them through `agents.toml`. dotagents defines
   `.agents/skills/` as the canonical location and generates runtime-specific
   projections from it.

2. **Less unconditional context.** The old root `CLAUDE.md` contained detailed
   architecture, commands, backend rules, API-generation rules, and ADR policy.
   The old Cursor rules added more always-loaded material. The new root
   `AGENTS.md` is a short routing document; detailed guidance is loaded only for
   backend, mobile, API-contract, IoT, or domain work. This reduces irrelevant
   context on unrelated tasks and gives each guidance block a clearer trigger.

3. **Better separation of concerns.** The new skills separate domain facts
   (`open-locker-domain`), backend workflow (`backend-laravel`), mobile workflow
   (`mobile-app`), contract synchronization (`api-contract-sync`), and hardware
   (`iot-hardware`). The three portable reviewers provide bounded review roles
   instead of making every agent carry every review checklist.

4. **Multi-agent portability.** `agents.toml` targets Claude, Cursor, and Codex
   from one declaration and declares portable subagents. This matches dotagents'
   documented model for configuring multiple agent runtimes and generating
   runtime-specific subagent files.

5. **Reproducible external dependency.** The upstream `dotagents` skill is
   declared by source and resolved in `agents.lock`, rather than copied by hand.
   A fresh checkout can run `npx @sentry/dotagents --project install` to restore
   managed dependencies.

## What did not improve, or remains risky

1. **Windows projection support is incomplete in this environment.** The
   project health check reported one missing/broken symlink because dotagents
   could not create the `.claude`/`.cursor` skill projection on this machine.
   The canonical `.agents/` files are present, but a runtime that only discovers
   skills through a generated projection may not see them until symlink support
   is enabled or a supported projection strategy is used.

2. **Deleting all Cursor rules may be too aggressive.** The old `.cursor/rules`
   files were verbose and partly stale, but they were a known native Cursor
   integration. The new setup assumes dotagents projections work. Until that is
   verified on the team's Windows environments, keeping a tiny native Cursor
   bridge or documenting the required setup would reduce compatibility risk.

3. **Some project facts were compressed too far.** The new skills are concise,
   but they omit some useful operational details from the old guidance, such as
   exact component commands and a few API-generation paths. Those details should
   be added as on-demand references or kept discoverable in package manifests,
   rather than restored to always-loaded rules.

4. **Local skill declarations are somewhat noisy.** The six project-local skills
   are explicitly listed in `agents.toml`, while they also live directly under
   `.agents/skills/`. This is workable and makes the dependency set visible, but
   the team should keep the declaration convention consistent so `sync` does not
   unexpectedly adopt or prune local skills.

## Conclusion

The current setup is conceptually better: it has a single source of truth,
progressive disclosure, portable subagents, and reproducible agent-runtime
configuration. It is not operationally better yet for every developer because
the Windows symlink projection is unresolved and Cursor discovery has not been
verified after removing native rules.

The recommended next step is to validate a fresh clone on Windows with:

```text
npx @sentry/dotagents --project install
npx @sentry/dotagents --project doctor --fix
npx @sentry/dotagents --project list
```

If Cursor and Claude can discover the generated skills after that, the new setup
is a net improvement. If not, retain minimal runtime-specific bridge files while
keeping `.agents/` as the source of truth.
