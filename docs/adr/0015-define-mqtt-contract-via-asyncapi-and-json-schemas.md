# ADR-0015: Define MQTT contract via AsyncAPI and shared JSON Schemas

## Status

Accepted

## Date

2026-04-14

## Context

Open-Locker's MQTT behavior is currently described across
`docs/mqtt_integration_plan.md`, several MQTT-related ADRs, backend publishers
and handlers, locker-client publishers and parsers, and tests.

That distribution was useful while the protocol evolved, but it now creates a
real maintenance problem:

- the planned wire contract and the implemented wire contract can drift
- backend and locker-client changes are harder to review against one source of
truth
- legacy topics and field shapes can survive in code because no machine-readable
protocol contract rejects them
- CI cannot validate the protocol itself, only partial implementation behavior

The MQTT protocol is a cross-component integration boundary between backend,
locker-client, provisioning clients, and future device implementations. This is
an architecture-significant contract and needs one authoritative definition.

## Decision

Open-Locker defines the MQTT contract through:

1. one canonical AsyncAPI document at `docs/asyncapi/mqtt.yaml`
2. shared JSON Schemas under `docs/asyncapi/schemas/`
3. committed canonical examples under `docs/asyncapi/examples/`

The AsyncAPI document is the authoritative description of:

- canonical MQTT topics
- direction of communication per participant
- supported message categories
- message-to-topic mapping

The JSON Schemas are the authoritative description of:

- required and optional payload fields
- field types and structural rules
- concrete payload variants such as `open_compartment`, `apply_config`,
`heartbeat`, `snapshot`, `connection_lost`, and provisioning replies

`docs/mqtt_integration_plan.md` remains as background, rationale, and migration
context, but it is no longer the canonical wire contract.

All backend and locker-client MQTT changes must align to the AsyncAPI document
and shared JSON Schemas. Contract tests and CI validation should validate both
the specification and representative implementation fixtures against these
shared definitions.

## Alternatives Considered

### Alternative A: Keep Markdown docs as the only contract

- Pros:
  - fastest to edit
  - easy to read in planning discussions
- Cons:
  - ambiguous as an executable contract
  - difficult to validate in CI
  - easy for backend and client behavior to drift silently
- Why not chosen:
  - the protocol is now important enough that prose alone is too weak as the
  source of truth

### Alternative B: Use code-first contracts in each implementation

- Pros:
  - schemas can live close to implementation code
  - each codebase can validate its own messages easily
- Cons:
  - duplicates the contract across backend and client
  - increases the risk of divergent schema definitions
  - makes protocol review harder because there is no repository-level contract
- Why not chosen:
  - the integration boundary should be defined once at repository level, not
  reconstructed independently by each participant

### Alternative C: Use a binary protocol definition such as Protobuf or Avro

- Pros:
  - strong machine-readable contracts
  - good tooling for generated models
- Cons:
  - adds unnecessary migration complexity for the current JSON-over-MQTT setup
  - would require reworking existing payloads and debugging workflows
  - less pragmatic for the current alpha phase
- Why not chosen:
  - AsyncAPI plus JSON Schema fits the existing MQTT + JSON architecture with
  much lower adoption cost

## Consequences

### Positive

- MQTT topics and payloads have one authoritative contract
- protocol drift becomes visible in review, tests, and CI
- backend and locker-client can validate against the same shared schemas
- removing legacy topics and fields becomes explicit and auditable
- future clients can implement against a machine-readable protocol spec

### Negative

- documentation maintenance becomes stricter because the AsyncAPI and schema
files must be kept current
- some information will exist in both background docs and the formal contract,
with different roles
- initial setup requires additional files and validation workflow

### Risks

- partial adoption could leave backend/client code temporarily aligned to older
payload shapes
- AsyncAPI and JSON Schema can still diverge from reality if teams bypass them
in implementation work

Mitigations:

- remove alpha-era legacy topics and payloads instead of supporting both forms
- add contract tests and CI validation after the initial spec is committed
- reference this ADR and the AsyncAPI files in implementation work and reviews

## Rollout / Migration

1. Add the initial AsyncAPI document, shared schemas, and canonical examples.
2. Align backend and locker-client implementations to the canonical contract.
3. Add contract tests and CI validation.
4. Reduce `docs/mqtt_integration_plan.md` to rationale and migration context.

No long compatibility layer is planned for legacy MQTT fields or topics because
the project is still in alpha.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues:
  - [#72](https://github.com/Open-Locker/Open-Locker/issues/72)
  - [#73](https://github.com/Open-Locker/Open-Locker/issues/73)
  - [#74](https://github.com/Open-Locker/Open-Locker/issues/74)
- Related docs:
  - `docs/mqtt_integration_plan.md`
  - `docs/asyncapi/mqtt.yaml`
  - `docs/adr/0002-mqtt-message-id-and-transaction-id-separation.md`