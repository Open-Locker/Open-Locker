# Laravel Boost tooling

When the Boost MCP tools are connected, use `search-docs` for version-specific
Laravel ecosystem guidance before choosing unfamiliar APIs. Query the behavior
and filter by relevant packages. If unavailable, inspect installed source and
official documentation for the version in Composer; do not assume tools exist.

Use available tools by purpose:

- `list-artisan-commands` to discover commands and arguments; otherwise use
  Artisan's `list` or command `--help` in the configured runtime.
- `database-schema` for schema inspection and `database-query` for read-only
  database questions. Use Tinker only when executing application PHP is needed.
- `browser-logs` for recent frontend errors.
- `get-absolute-url` for local application links, or verify the app/port config
  when the tool is unavailable.

## Maintaining instructions

`locker-backend/boost.json` keeps MCP enabled and generated guidelines disabled.
The maintained instructions live in the root `.agents/skills/backend-laravel/`.
With no Boost-managed skills configured, the installed `boost:update` returns
without generating instruction files.

When upgrading Boost, review its configuration and generated guidance before
importing useful changes into these references. An explicit `boost:install
--guidelines` can re-enable generation; keep the root skill layout when
reconfiguring the integration. See the [layout research](../../../../docs/research/agent-instruction-layout.md).
