# ADR-0057: Skip component CI for documentation-only changes

## Status

Accepted

## Date

2026-08-26

## Context

The backend, mobile app, and locker-client workflows use component-directory
path filters. Documentation and agent rules live inside those directories, so a
README or `.cursor` rule change currently starts the same test, Docker, and
native build jobs as an application change.

This was visible on the post-Beta documentation audit: documentation-only
changes started backend and locker-client image verification plus Android and
iOS builds. These jobs do not validate Markdown or agent instructions and add
substantial runner time.

Some documentation is executable or contractual and still needs CI:

- `website/**` is built and deployed as the public Astro/Starlight site.
- `docs/asyncapi/**` defines the MQTT contract and must keep triggering backend
  and locker-client contract checks.
- workflow and release-note configuration changes must keep triggering their
  owning workflows.

## Decision

For pull requests and branch pushes, component workflows exclude:

- `locker-backend/**/*.md` and `locker-backend/.cursor/**`;
- `locker-client/**/*.md` and `locker-client/.cursor/**`;
- `mobile-app/**/*.md` and `mobile-app/.cursor/**`.

The existing positive component paths remain. GitHub Actions evaluates the
negative patterns after those positive patterns, so source and configuration
changes still trigger component CI.

The exclusions do not apply to:

- `docs/asyncapi/**`;
- `website/**`;
- component workflow files;
- Git Cliff release-note configuration;
- release tag pushes or manual workflow dispatch.

The release-workflow validation script asserts that every affected component
workflow retains these exclusions.

## Alternatives Considered

### Keep broad component-directory triggers

- Pros: simplest path configuration; every nested change receives full CI.
- Cons: expensive Docker and native builds provide no signal for Markdown or
  agent-rule changes.
- Why not chosen: the cost is recurring and the checks do not validate the
  changed files.

### Start every workflow and skip expensive jobs after change detection

- Pros: stable check names for repositories that require every component check.
- Cons: adds a change-detection job and workflow complexity to every component.
- Why not chosen: the repository does not require these checks through branch
  protection, so workflow-level path filtering is sufficient.

### Move all component documentation to the root `docs/` directory

- Pros: component paths would contain only runtime code and configuration.
- Cons: separates READMEs and agent rules from the code they explain; public
  website content still needs its own build.
- Why not chosen: documentation locality is useful and path exclusions solve
  the runner-cost problem directly.

## Consequences

### Positive

- Markdown and agent-rule-only changes no longer start backend, locker-client,
  or mobile component jobs.
- Website and MQTT contract documentation retain their relevant validation.
- Workflow changes still run CI, making the filter policy self-validating.

### Negative

- A Markdown or `.cursor` change that indirectly affects a custom external
  process will not receive component CI unless that process has its own trigger.
- Contributors must keep non-documentation configuration out of excluded paths.

### Risks

- Incorrect pattern ordering could make a negative pattern ineffective.
  Structural validation and the explicit order in each workflow mitigate this.
- New documentation extensions require an explicit policy decision before they
  are excluded.

## Rollout / Migration

1. Add ordered negative path patterns to the six component workflows.
2. Add structural assertions to the release-workflow validation script.
3. Confirm a workflow-file change still starts validation.
4. Confirm a later documentation-only change does not start component builds.

## References

- ADR-0043: Monorepo release strategy
- GitHub issue #263: post-Beta documentation audit
- Pull request #264
- `.github/workflows/backend-docker.yml`
- `.github/workflows/mqtt-contract-ci.yml`
- `.github/workflows/client-docker.yml`
- `.github/workflows/locker-client-ci.yml`
- `.github/workflows/mobile-app-build.yml`
- `.github/workflows/mobile-app-ci.yml`
