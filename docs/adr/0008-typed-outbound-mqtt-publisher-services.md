# ADR-0008: Use typed outbound MQTT publisher services

## Status

Accepted

## Date

2026-04-11

## Context

Open-Locker's backend currently publishes outbound MQTT messages from a single
`MqttReactor`.

That reactor already handles multiple outbound flows:

- locker commands such as `open_compartment`
- locker commands such as `apply_config`
- provisioning success replies
- provisioning failure replies

More outbound MQTT message types are expected soon.

The existing inline approach puts multiple responsibilities into one reactor:

- selecting MQTT topics
- building message payloads
- ensuring `message_id` is present
- publishing via the MQTT facade
- logging transport-level publish actions

This increases the risk that future outbound flows drift in topic conventions,
payload shape, logging, or publish behavior.

The project already established inbound MQTT structure with a shared technical
layer and focused handler classes. Outbound publishing now needs a similarly
clear structure, but it should still follow Laravel service composition rather
than mechanically mirroring the inbound inheritance model.

## Decision

Open-Locker uses typed outbound MQTT publisher services for backend-originated
MQTT messages.

The structure is:

- `MqttReactor` remains the Spatie event integration point
- a low-level `MqttPublisher` service owns the actual publish call to the MQTT
  facade and payload encoding via `MqttPayloadFactory`
- small typed publisher services own topic and payload semantics for each
  outbound message family

Initial typed publishers cover:

- open compartment commands
- apply config commands
- provisioning replies

## Rationale

This keeps responsibilities narrow and Laravel-style:

- reactor: respond to stored events and orchestrate side effects
- typed publisher: define message-specific outbound contract
- low-level publisher: perform shared transport concerns once

This gives us a clean extension point for future outbound MQTT messages without
forcing a large god-class reactor or a complex registry framework too early.

## Alternatives Considered

### Alternative A: Keep all outbound publishing inline in `MqttReactor`

- Pros:
  - no new classes
  - minimal immediate refactor effort
- Cons:
  - `MqttReactor` grows as new outbound message types are added
  - payload and topic logic become harder to test in isolation
  - shared publish behavior can drift over time
- Why not chosen:
  - expected outbound growth makes the current structure too brittle

### Alternative B: Mirror inbound handlers with an abstract outbound handler hierarchy and registry

- Pros:
  - visual symmetry with inbound MQTT structure
  - possible future dispatch abstraction
- Cons:
  - introduces framework-like complexity before it is needed
  - outbound flows are triggered by typed domain events, not by topic matching
  - less idiomatic than small Laravel service classes
- Why not chosen:
  - outbound concerns are better modeled as injected services than as a second
    handler registry

## Consequences

### Positive

- outbound MQTT concerns become easier to test in isolation
- adding new outbound message types becomes more consistent
- `MqttReactor` stays focused on orchestration instead of transport details
- payload encoding and publish behavior stay centralized

### Negative

- more classes and constructor wiring in the short term
- some logic moves out of a previously familiar file

### Risks

- typed publishers may become too granular if every small variant gets its own
  class
- inconsistent publisher boundaries could still emerge if future additions are
  not reviewed carefully

Mitigations:

- keep one typed publisher per coherent outbound message family
- keep shared transport behavior inside `MqttPublisher`
- use focused tests for typed publisher topic and payload contracts

## Rollout / Migration

- introduce `MqttPublisher` as the shared low-level outbound transport service
- extract typed publisher services for current outbound flows
- refactor `MqttReactor` to delegate to those services
- keep existing queue retry semantics unchanged
- add focused tests for typed publishers and reactor delegation

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues:
  - `https://github.com/Open-Locker/Open-Locker/issues/41`
- Related docs:
  - `docs/adr/0002-mqtt-message-id-and-transaction-id-separation.md`
  - `docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`
