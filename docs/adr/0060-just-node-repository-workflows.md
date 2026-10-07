# ADR-0060: Just command menu with TypeScript workflows on Node

## Status

Proposed (implemented on PR #275 for evaluation)

## Date

2026-10-07

## Context

The repository previously maintained paired PowerShell and Bash implementations
for MQTT configuration and tracing. These duplicated environment parsing, file
generation, Docker orchestration and readiness checks. Contributors need one
implementation per workflow that runs on Windows, Linux and macOS.

Node and pnpm are already used by the locker client, mobile app and website.
The repository also spans PHP, Docker and Git tooling, making a language-neutral
command menu useful. Backend-only contributors gain a host Node and pnpm
requirement when using these workflows.

The decision separates three concerns: the public task interface, the runtime
for workflow logic, and libraries for process execution and configuration parsing.
A portable task runner alone does not make arbitrary shell commands portable.
Docker and Git remain prerequisites for the tasks that invoke them.

## Decision

Retain Just as the public command menu. Keep simple Docker, Git and pnpm commands
in the Justfile and implement MQTT setup and tracing once in TypeScript under
`scripts/`. Just delegates complex workflows to named pnpm scripts, which execute
TypeScript through tsx on Node.

Require Node 22+, Just 1.56+ and pnpm 11.25.0. Isolate and pin workflow dependencies
in `scripts/package.json` and its lockfile. Install them with `just script-deps`
or `pnpm --dir scripts install --frozen-lockfile`, including development dependencies
needed to execute and check these tools.

Use Execa for external processes with argument arrays, an explicit repository
working directory and command-local environment overrides. Use Node APIs for
paths, atomic file replacement and bounded HTTP readiness checks. Use dotenv's
`parse` API for the backend environment file without loading its variables into
the host process environment.

Use strict NodeNext TypeScript checking for workflows and tests. tsx executes
without an emitted JavaScript build; `tsc --noEmit` runs separately because tsx
does not type-check. See [the script guide](../../scripts/README.md) for layout
and command usage.

Preserve public command names, the pinned SigNoz version and retained data.
Workflow `--dry-run` previews internal actions without file, network or subprocess
operations; Just's dry run prints only the recipe command. MQTT configuration is
written completely before restarting MQTT, and passwords are never logged.

A nonempty process environment password takes precedence and remains literal.
File values follow dotenv quoting and comment rules, with the last duplicate key
winning. This replaces the custom parser's first-match behavior. Reject multiline
passwords, including escaped newlines decoded by dotenv.

The selected combination keeps a discoverable command interface, reuses the
existing runtime ecosystem, adds types to orchestration code and delegates
process edge cases to a focused library. These workflows need little shell
composition, so a portable shell interpreter is unnecessary for this change.

## Alternatives Considered

### Task interface

| Interface | Benefits | Costs and disposition |
| --- | --- | --- |
| Just + shared workflows | Short commands, descriptions, listing and prerequisite recipes; suitable across repository languages | Retains a runner dependency and centralized launching-shell settings. Selected. |
| Node-only CLI | Removes Just; a dependency-free JavaScript dispatcher can run all tasks | Repository owns help, dispatch and argument validation; package-manager launchers need portable handling. Evaluated, not selected. |
| pnpm package scripts only | Reuses package tooling and can invoke the complex workflows directly without a custom dispatcher | Removing Just also requires exposing simple Docker, Git and simulator tasks through package commands. Viable, but the Just menu is retained. |
| Task / Taskfile | Declarative command discovery, dependencies, environment loading and caching | Adds another runner; external tools still need portable commands, and complex generation/retry logic is less natural in YAML. Not selected. |

Sources: [Just settings](https://just.systems/man/en/settings.html) and
[Task platforms and shells](https://taskfile.dev/docs/guide/platforms).

Just ordinary recipes invoke the configured shell. The platform distinction is
centralized once, while workflow logic is shared. Just script recipes can select
an interpreter directly, but per-recipe JavaScript launchers would add machinery
without improving the shared implementation.
[Just script recipes](https://just.systems/man/en/script-recipes.html).

### Workflow runtime and language

| Approach | Benefits | Costs and disposition |
| --- | --- | --- |
| Paired Bash and PowerShell | Uses native host shells | Duplicates substantive workflow logic and risks drift. Replaced. |
| Node JavaScript with standard APIs | Portable files, subprocesses and HTTP; `.mjs` works without a package manifest or loader | Requires maintaining subprocess and environment-file helpers. Evaluated as the minimal dependency option. |
| Node TypeScript + tsx | Reuses Node, adds types and executes without a separate build | Requires package installation and separate type checking. Selected. |
| Deno scripts / `deno task` | JS/TS execution, task runner and portable task shell in one runtime | Introduces another runtime to the existing stack. Not selected. |
| Bun / Bun Shell | JS/TS execution and portable shell expressions, built-ins, pipes and redirects | Requires a runtime migration and compatibility validation. Not selected. |
| Python standard library | Portable filesystem, HTTP and subprocess APIs | Adds a developer runtime outside the established JS tooling. Not selected. |
| PowerShell 7 | A single shared language with filesystem, HTTP and structured-data support | Requires installation, particularly on Unix hosts. Viable, not selected. |
| Yarn portable shell | Portable package-script syntax and common built-ins | Changes package tooling; its limited shell syntax does not replace the tracing program. Not selected. |
| Container or devcontainer execution | Standardizes the execution environment for shared shell scripts | Requires contributors to use that environment; host orchestration may still be necessary. Not adopted as the general workflow model. |

Sources: [Node APIs](https://nodejs.org/docs/latest-v22.x/api/),
[tsx](https://tsx.is/), [Deno task](https://docs.deno.com/runtime/reference/cli/task/),
[Bun Shell](https://bun.sh/docs/runtime/shell),
[Python subprocess](https://docs.python.org/3/library/subprocess.html),
[PowerShell](https://learn.microsoft.com/en-us/powershell/scripting/overview), and
[Yarn scripting](https://yarnpkg.com/features/scripting).

`.mjs` makes ES modules explicit without a package manifest; it has no inherent
cross-platform advantage. With an isolated package already needed for libraries,
TypeScript and `"type": "module"` provide a consistent module boundary without
changing sibling components. Native Node environment parsing was also considered;
any parser choice still needs an explicit precedence and file-format contract.
[Node module formats](https://nodejs.org/api/packages.html#determining-module-system)
and [environment parsing](https://nodejs.org/api/util.html#utilparseenvcontent).

### Process execution and configuration libraries

| Library or API | Responsibility | Assessment |
| --- | --- | --- |
| Node subprocess APIs | Start processes, buffer output and handle failures | Minimal dependencies, but leaves launcher and error-handling details in repository code. Replaced by Execa. |
| Execa | Checked subprocesses and output capture | Focused abstraction suited to existing argument-array commands. Selected. |
| Dax | Process execution plus portable shell expressions and built-ins | Attractive for shell composition; broader than required here. Not selected alongside Execa. |
| zx | Convenient process wrappers and quoted interpolation | Uses a configured shell; Unix utilities and shell-specific syntax still require the corresponding environment. Not selected. |
| dotenv | Parse environment-file quoting, comments and assignments | Replaces the custom parser with documented behavior. Selected with explicit precedence tests. |
| p-retry | Retry scheduling and backoff | Useful for more sophisticated retry policies; the bounded readiness loop is already short. Not selected. |

Sources: [Execa](https://github.com/sindresorhus/execa),
[Dax](https://github.com/dsherret/dax), [zx shell selection](https://google.github.io/zx/shell),
[dotenv](https://github.com/motdotla/dotenv), and
[p-retry](https://github.com/sindresorhus/p-retry).

Library selection reduces process edge cases maintained locally, rather than
eliminating application logic. Password validation, safe file writes, checkout
validation, startup order and readiness deadlines remain repository responsibilities.
Line-count reductions are not a decision criterion; types and explanatory comments
can increase source size while improving maintainability.

### Just with Node versus a Node CLI with Dax

The following compares the dependency-free Node alternative with the Dax variant;
the selected TypeScript/Execa implementation also requires package installation.

| Concern | Just + plain Node workflows | Node CLI + Dax |
| --- | --- | --- |
| Invocation | `just trace-up` | A repository-defined Node CLI command |
| Requirements | Node + Just; no script packages if using only Node APIs | Node + installed Dax package |
| Command discovery | Just supplies listing and descriptions | Repository dispatcher supplies help |
| Task dependencies | Just expresses prerequisite recipes | JavaScript calls workflow functions in order |
| Workflow code | Node APIs and a process helper | JavaScript/TypeScript with shell-like command expressions |
| Portability | Shared APIs with centralized Just launching-shell configuration | Dax supplies a portable shell and common built-ins |
| Dry runs | Just previews recipe commands; internal workflow previews are explicit | Internal workflow previews are explicit |
| Maintenance | Command menu plus workflow modules and a process helper | Dispatcher plus workflow modules; less process-helper code |

The task interface and process library are independent choices: Just + Dax is
also possible. Dax does not automatically supply a repository task menu. It can
express Docker/Git commands, capture output and set command-local environment
variables, but the tracing lifecycle and explicit dry-run behavior still need
workflow code. [Dax API](https://dax.land/).

### Bun migration scope

Replacing Node as runtime and replacing pnpm as package manager are separate
changes. Bun could simplify process expressions in repository scripts, but a
whole-project migration would touch additional runtime and build contracts.

| Area | Likely changes | Potential benefit |
| --- | --- | --- |
| Repository scripts | Process helper, tests, CI and docs | Portable shell authoring and direct TypeScript execution |
| Website / Astro | Installation and build tooling; validate integrations | Installation or startup improvements |
| Backend frontend / Vite | Package scripts, lockfile and build pipelines | Tooling improvements; Laravel remains PHP |
| Locker client | Runtime image, entrypoint, TypeScript launchers and tests; validate dependencies | Replace `ts-node`; possible startup/runtime gains |
| Mobile app / Expo | Package management, scripts and Jest/EAS configuration | Tooling improvements; some commands still require Node |
| Shared infrastructure | CI setup/cache keys, Dockerfiles, devcontainer, hooks and docs | Consistent tooling |

These are scope assessments, not measured performance results. The repository
scripts mostly wait for Docker, Git and service readiness; faster JS startup alone
is unlikely to materially shorten tracing startup. Installation and client
performance require repository-specific benchmarks.

The locker client uses Node images, MQTT, Modbus and OpenTelemetry. Networking,
tracing context, shutdown and recovery must be validated under Bun. Automatic
`.env` loading also needs comparison with the explicit environment contract.
[Bun compatibility](https://bun.sh/docs/runtime/nodejs-compat) and
[Bun environment handling](https://bun.sh/docs/runtime/environment-variables).

| Migration target | Assessment |
| --- | --- |
| Bun as primary runtime and package manager | Feasible to investigate while retaining Node for tooling requiring it. |
| No host Node installation for contributors | Potentially achievable with containers or remote builds; Node can remain in the pipeline. |
| No Node anywhere, including CI and mobile builds | Not established as a supported drop-in migration; requires validating and potentially replacing Node-dependent tooling. |

Expo's Bun guide requires Node LTS for project creation and prebuild paths using
`npm pack`. Changing package managers does not replace the mobile application's
JavaScript engine with Bun. `bun run` can also follow Node shebangs, so selecting
Bun as the actual runtime requires explicit configuration and testing.
[Expo's Bun guide](https://docs.expo.dev/guides/using-bun/) and
[Bun runtime](https://bun.sh/docs/runtime).

A full Bun migration is outside this decision. It would require a separate
compatibility and performance evaluation, particularly for production clients
and mobile builds.

### Patterns in other projects

| Project | Approach | Relevant pattern |
| --- | --- | --- |
| Prettier | Package commands call Node files under `scripts/` | Shared workflow code behind named commands. [Manifest](https://github.com/prettier/prettier/blob/main/package.json) |
| VS Code | npm commands and shared JS/TS tooling with platform launchers | Thin launchers can differ while substantial logic is shared. [Unix](https://github.com/microsoft/vscode/blob/main/scripts/code.sh), [Windows](https://github.com/microsoft/vscode/blob/main/scripts/code.bat) |
| Biome | Just menu with Rust helpers and pnpm commands | A language-neutral menu can delegate complex work to the repository's existing language. [Justfile](https://github.com/biomejs/biome/blob/main/justfile) |
| Task | YAML tasks orchestrating Go tooling | Declarative runners still require portable external commands. [Taskfile](https://github.com/go-task/task/blob/main/Taskfile.yml) |

These examples support separating the contributor interface from workflow logic.
They do not establish that any runner makes all commands portable. The chosen
shape is Just → pnpm → tsx → shared TypeScript → Node APIs / Execa → Docker and Git.

### Container-side MQTT configuration

Generating Mosquitto configuration inside its container could eliminate the host
setup script. Docker provides a consistent Linux environment across supported
hosts, making one container entrypoint possible.

This option is deferred until the pinned image's startup behavior, utilities,
user and writable paths are verified. Replacing the entrypoint must preserve
image initialization. The secret must be passed explicitly; Compose's interpolation
`.env` is not automatically the container environment. Changed environment values
require recreation rather than `restart`.
[Compose services](https://docs.docker.com/reference/compose-file/services/),
[interpolation](https://docs.docker.com/compose/how-tos/environment-variables/variable-interpolation/),
and [restart](https://docs.docker.com/reference/cli/docker/compose/restart/).

## Consequences

Contributors retain short, discoverable Just commands and can invoke complex
workflows directly through pnpm. The isolated scripts package adds dependency
installation, including tsx and TypeScript development dependencies. Backend-only
contributors need host Node and pnpm for MQTT setup and tracing.

Workflow logic has one implementation across supported hosts. Execa handles
process execution, dotenv handles file parsing, and Node handles files and HTTP.
The Justfile selects its launching shell once; the simulator remains a simple
pnpm recipe. Paths resolve from the module with `fileURLToPath`, avoiding Windows
drive-letter and encoded-space problems. Command-local environment overrides
never mutate the host environment.

Generated MQTT configuration still contains the secret. POSIX file modes do not
provide equivalent Windows ACL protection; existing secret URL semantics remain.
Container-side generation and backend health-check changes are deferred. A runtime
or task-runner choice alone does not sandbox external Docker/Git processes.

Strict type checking, all 11 behavioral tests, frozen installation, dry-run entry
points and Just formatting passed on Windows. CI is configured for Windows,
Linux and macOS, covering paths with spaces, failed processes, secrets and bounded
readiness. Linux/macOS results and full Docker startup were not verified locally.

## References

- [PR #275](https://github.com/Open-Locker/Open-Locker/pull/275)
- [Script structure and usage](../../scripts/README.md)
- [Just settings](https://just.systems/man/en/settings.html)
- [Node child processes](https://nodejs.org/api/child_process.html)
- [Node file URL conversion](https://nodejs.org/api/url.html#urlfileurltopathurl-options)
- [Node filesystem APIs](https://nodejs.org/docs/latest-v22.x/api/fs.html)
- [Execa](https://github.com/sindresorhus/execa)
- [dotenv parsing](https://github.com/motdotla/dotenv#parse)
- [tsx execution](https://tsx.is/)
- [tsx type checking](https://tsx.is/typescript)
