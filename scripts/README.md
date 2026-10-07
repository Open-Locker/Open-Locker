# Repository workflows

Just provides the repository command menu. The complex workflows live here as
TypeScript and run on Node through tsx. Execa launches Docker and Git; dotenv
parses the backend `.env` file. No separate JavaScript build is required.

From the repository root, install dependencies with Node 22+ and pnpm 11.25.0:

```sh
just script-deps
# Or, without Just:
pnpm --dir scripts install --frozen-lockfile
```

Install development dependencies too: tsx executes the workflows and TypeScript
checks them. Just integration tests require Just 1.56+.

```text
scripts/
├── package.json                # named commands and pinned dependencies
├── pnpm-lock.yaml              # reproducible dependency resolution
├── pnpm-workspace.yaml         # dependency installation settings
├── tsconfig.json               # strict type checking, no emitted files
├── lib/runtime.ts              # processes, CLI arguments, writes and HTTP probes
├── setup-mqtt.ts               # MQTT password and configuration generation
├── tracing.ts                  # backend overlay and SigNoz orchestration
└── tests/task-runner.test.ts    # workflow behavior and Just integration
```

The same workflows can be launched with either interface:

| Just | pnpm from the repository root |
| --- | --- |
| `just setup-mqtt` | `pnpm --dir scripts setup-mqtt` |
| `just trace-overlay` | `pnpm --dir scripts trace overlay` |
| `just trace-up` | `pnpm --dir scripts trace up` |
| `just trace-down` | `pnpm --dir scripts trace down` |
| `just trace-status` | `pnpm --dir scripts trace status` |

`trace` defaults to `status`. Add `--help` for usage or `--dry-run` to preview
internal actions without writing files, contacting services or running Docker
and Git. For example, `pnpm --dir scripts trace up --dry-run`. Just's
`--dry-run` prints only the pnpm command.

Each module exports its workflow independently of the CLI. `runScript` starts
the workflow only when that file is the invoked entry point, so tests can import
it without side effects. The `Runtime` interface supplies the repository root,
environment, logging and command execution; tests substitute commands to verify
orchestration without starting services.

MQTT uses a nonempty `MOSQ_HTTP_PASS` from the process environment first, then
dotenv's parsed `locker-backend/.env` value. Environment values stay literal;
file values follow dotenv quoting and comment rules, and the last duplicate key
wins. Backend variables are never loaded into the host environment. Passwords
must be a single line and are never printed. The template is replaced atomically
before MQTT restarts.

Tracing keeps SigNoz in a separate Compose project and retains its volumes when
stopping. `SIGNOZ_DIR` selects its checkout, `SIGNOZ_UI_PORT` selects its UI port,
and `FORWARD_OTLP_HTTP_PORT` / `FORWARD_OTLP_GRPC_PORT` select collector host ports.
Port overrides are passed only to the relevant subprocess. HTTP readiness uses
a bounded overall deadline. See [the observability guide](../docs/observability.md)
for setup and behavior.

Run both checks after changing workflows:

```sh
pnpm --dir scripts check
pnpm --dir scripts test
```

tsx executes TypeScript without type checking. The separate `check` command runs
`tsc --noEmit` over workflows and tests. CI runs these checks on Windows, Linux
and macOS, including the Just launch commands and paths containing spaces.
