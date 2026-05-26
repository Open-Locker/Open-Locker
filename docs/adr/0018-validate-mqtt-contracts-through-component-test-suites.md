# ADR-0018: Validate MQTT contracts through component test suites

## Status

Accepted

## Date

2026-05-26

## Context

Open-Locker now defines its MQTT protocol through AsyncAPI and shared JSON
Schemas. Backend and locker-client both have schema-backed tests that validate
representative MQTT payloads against the canonical contract under
`docs/asyncapi/`.

During CI planning, we considered adding a narrow MQTT-contract-only workflow.
However, the contract tests are part of the normal backend and locker-client
test suites. Running separate contract-only jobs would add another CI workflow
to maintain and could duplicate coverage already provided by component tests.

At the same time, changes to `docs/asyncapi/**` must run both backend and
locker-client tests, because the shared contract affects both components.

## Decision

MQTT contract validation runs through the normal component test suites:

- backend test suite via Composer / PHPUnit
- locker-client test suite via pnpm / Node test runner

The CI workflow for this phase runs both suites when relevant MQTT contract or
component files change, including `docs/asyncapi/**`.

We will not maintain a long-term contract-only test workflow as the primary
validation mechanism. The current MQTT-focused workflow is an interim
component-test workflow that runs full backend and locker-client test suites for
contract-relevant paths. Broader CI workflow cleanup is tracked separately in
GitHub issue #99.

## Alternatives Considered

### Alternative A: Dedicated MQTT contract-only workflow

- Pros:
  - faster feedback for contract-only changes
  - clear GitHub check name for MQTT contract validation
- Cons:
  - duplicates parts of backend and locker-client test setup
  - risks diverging from the test suites developers run locally
  - adds more workflow surface to maintain while CI is still being cleaned up
- Why not chosen:
  - the contract tests are already integrated into component suites, so the
    extra workflow is not necessary as the primary validation path

### Alternative B: Run only backend tests for contract changes

- Pros:
  - simpler CI setup
  - validates backend publisher and inbound response behavior
- Cons:
  - misses locker-client outbound payloads and AsyncAPI example validation
  - does not protect the full cross-component MQTT boundary
- Why not chosen:
  - the MQTT contract is shared by backend and locker-client

## Consequences

### Positive

- developers and CI run the same test suites
- contract changes exercise both backend and locker-client behavior
- fewer bespoke CI commands are needed
- future CI cleanup can fold this workflow into broader component workflows

### Negative

- contract-only changes run more tests than the minimum necessary
- the current workflow name remains MQTT-focused until the broader CI cleanup is
  completed

### Risks

- if component test suites become very slow, contract changes may get slower
  feedback than a dedicated contract-only job would provide

Mitigation:

- revisit workflow structure in issue #99 once the broader CI cleanup happens
- keep contract tests focused and deterministic inside each component suite

## Rollout / Migration

1. Keep schema-backed MQTT tests inside backend and locker-client test suites.
2. Trigger the component test workflow on `docs/asyncapi/**`,
   `locker-backend/**`, and `locker-client/**` changes.
3. Defer full workflow naming and structure cleanup to issue #99.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues:
  - [#77](https://github.com/Open-Locker/Open-Locker/issues/77)
  - [#78](https://github.com/Open-Locker/Open-Locker/issues/78)
  - [#99](https://github.com/Open-Locker/Open-Locker/issues/99)
- Related docs:
  - `docs/asyncapi/mqtt.yaml`
  - `docs/adr/0015-define-mqtt-contract-via-asyncapi-and-json-schemas.md`
