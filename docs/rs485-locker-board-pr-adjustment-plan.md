# RS485 Locker Board PR Adjustment Plan

## Goal

Reduce PR 237 to the smallest reliable implementation of the proprietary RS485
locker-board protocol while preserving the established Open-Locker boundaries:

- the backend owns adapter selection, feedback polarity, and compartment mapping
- the locker client owns serial communication and hardware recovery
- command acknowledgement reports actuation, not physical door state
- door state remains `open | closed | unknown`
- serial hardware may be configured before it is connected

The implementation must work with the verified 8-channel board at address 1,
whose query-all response is seven bytes despite exposing only eight physical
channels:

```text
TX 80 01 00 33 B2
RX 80 01 00 00 00 33 B2
```

## Decisions

### Remove `channel_count`

Remove `channel_count` from:

- the locker-bank database schema and model
- Filament forms
- `apply_config`
- AsyncAPI and examples
- config hashing
- stored events and MQTT publishers
- client domain types, Zod schemas, runtime overlay, and adapter construction
- tests and documentation

Compartment mappings continue to use:

```json
{
  "compartment_number": 1,
  "slaveId": 1,
  "address": 0
}
```

The configured addresses describe the channels Open-Locker uses. They do not
claim to describe the physical size of the board.

Do not impose a product-level maximum such as 8, 12, 18, 24, 36, or 50. Enforce
only wire-format constraints:

- board address must fit the supported DIP/protocol range
- the zero-based channel address must be encodable as a non-zero one-byte wire
  channel

Multiple boards remain represented by different `slaveId` values. No board
table is introduced.

### Keep the external YAML key for this PR

Do not rename the external `modbus:` YAML key in this PR. Renaming it would add a
second migration and compatibility surface unrelated to proving the RS485
adapter.

Internally, extract a protocol-neutral serial configuration from the existing
values and use it for both adapters:

- `port`
- `baudRate`
- `dataBits`
- `stopBits`
- `parity`
- `timeout`
- `reconnectCooldownSeconds`

The RS485 adapter must no longer hardcode 9600/8N1 in `createApp.ts`; it uses the
validated configured values. `flashDurationMs` remains Waveshare-specific.

A later, independent change may rename the external key to `serial` with a
backward-compatible `modbus` alias.

### Separate logical addresses from wire-frame length

Do not calculate query-all response length from configured compartments or a
declared board size.

The transaction transport reads until a calculated inter-byte quiet period
indicates frame completion. The codec then validates:

1. structural fields: minimum length, header, board address, and command marker
2. the complete trailing XOR checksum
3. sufficient status bits for the requested configured address

The codec decodes every status byte received. Callers select only configured
addresses. A configured address not represented by the response becomes
`unknown`; it does not crash polling or infer a different board size.

### Share serial timing

Extract the existing baud/framing calculation into a protocol-neutral serial
timing helper.

For both Waveshare and the proprietary RS485 adapter:

- calculate character time from baud rate, data bits, parity, and stop bits
- enforce an inter-transaction quiet period
- include a 1 ms scheduling safety margin
- record transaction completion in `finally`, so the delay also follows errors
- use a monotonic clock

Keep two distinct timeout concepts:

- **response timeout**: existing configured `timeout`, covering the complete
  request/response transaction
- **frame quiet timeout**: derived from baud/framing and reset whenever another
  response chunk arrives

The frame is complete only after response bytes were received and the quiet
period elapsed. Header/command and XOR validation happen afterwards in the
codec.

### Recover transport synchronization after failures

After timeout, malformed frame, or unexpected excess data:

1. remove transaction listeners and timers
2. discard buffered receive data
3. close and reopen the serial port before retrying
4. enforce the normal quiet period before the next write

Late bytes from a failed transaction must never prefix the next response.
Protocol decode failures are hardware transport failures, not generic software
errors.

### Share serial-bus reconnect behavior

Create a protocol-neutral serial connection component used by both Waveshare and
the proprietary RS485 actor. It owns:

- `disconnected | connecting | connected | unreachable`
- bounded reconnect attempts
- cooldown-bounded recovery cycles
- error-code classification
- startup behavior
- reconnect-after-transaction-failure
- once-per-cycle unreachable logging

It must preserve the ADR-0051 behavior:

- missing hardware does not stop process startup
- a spent cycle enters `unreachable`
- later polling starts another cycle after cooldown
- `ENOENT`, `ENXIO`, `EIO`, `EBADF`, and equivalent observed serial failures are
  reconnectable
- `EACCES` and unknown errors are not retried indefinitely

Keep each hardware actor's `PQueue({ concurrency: 1 })`. This is the physical
hardware queue and must not be removed.

### Simplify the two queue layers without removing hardware serialization

Retain both synchronization scopes, but give them one responsibility each:

- `RuntimeConfiguredLockerBus`: FIFO lifecycle mutex protecting the active
  adapter across complete operations and profile switches
- concrete bus actor: the only prioritized hardware queue, serializing physical
  transactions and reconnects

Remove priorities from the runtime wrapper. It must not independently schedule
hardware priorities already owned by the active actor.

The required order remains:

```text
open operation starts
→ active adapter is pinned
→ its hardware transactions run serially
→ open operation ends
→ pending apply_config may switch the adapter
```

### Keep valid configuration while hardware is unavailable

`apply_config` succeeds after:

- schema validation
- mapping validation
- hash validation
- atomic overlay persistence
- effective-config reload
- successful adapter construction

A recoverable connection failure does not roll back the overlay. The adapter is
kept with state `unreachable`, and later polling/reconnect cycles recover it.

Rollback remains only for failures that mean the configuration was not safely
accepted, such as invalid data, hash mismatch, persistence failure, or adapter
construction failure.

### Preserve legacy overlays safely

When an existing overlay contains compartments but no hardware profile, treat it
as the legacy Waveshare profile:

```text
adapterType = waveshare_modbus
feedbackType = door_closing
```

Do not apply this fallback to a new installation with no runtime overlay. A new
client without `apply_config` remains capability-neutral and does not open a
serial adapter.

### Separate action feedback from door state

Remove `opened | failed` from `UnlockFeedback`.

Use the same command semantics for both adapters:

- successful `flashRelay` means the actuation command was confirmed
- an action failure throws a typed hardware error
- physical door state is observed only through `readDoorSensors`

Prefer `Promise<void>` for `flashRelay`; absence of an exception is the command
acknowledgement.

The RS485 unlock response must never directly emit `opened` or `door_jammed`.
Those outcomes require a door-state observation.

### Remove relay-state monitoring

Remove `readRelayState` from `LockerBusPort`, both production actors, simulator
and test fakes unless a concrete behavior still depends on it after review.

Remove `startRelayMonitoring` and `monitoringKeys` from
`OpenCompartmentUseCase`.

Waveshare native hardware flash already controls pulse duration. The proprietary
board controls its own actuation. Polling a relay output without acting on a
stuck state adds complexity without a product outcome.

Keep `RelayFireLog`: it records that an actuation occurred and supports
correlation with later door observations.

### Remove synchronous pre-read and `already_open` production

Do not delay actuation with a synchronous door query.

The new sequence is:

```text
resolve mapping
→ ensure bus connection
→ send actuation
→ acknowledge command
→ monitor door state
→ emit opened or door_jammed
```

Do not add a new shared snapshot cache in this PR. That would increase
complexity to preserve an outcome that is not currently considered useful.

The backend may retain support for historical `already_open` events, but the
locker client stops producing new ones. Update or supersede ADR-0040 accordingly.

## Architecture documentation

The change is architecture-significant because it changes the MQTT contract,
runtime configuration, hardware communication, reconnect policy, and door-open
semantics.

Before implementation is considered complete:

- rebase the PR onto current `main`
- resolve the existing ADR-0058 number collision
- create the next available ADR for the final RS485 decision
- reference the real 8-channel hardware capture
- partially supersede ADR-0009 for the hardware profile in the runtime overlay
- update ADR-0040 for removal of the pre-read/`already_open` production path
- reference ADR-0035 and ADR-0051 rather than duplicating their policies

Documentation items marked `[x]` below reflect implementation evidence in the
branch; rebase, CI, Raspberry Pi soak, additional boards, and transport-level
recovery tests stay open until explicitly verified.

Do not merge the branch's conflicting ADR-0058 under that number.

## Implementation phases and checklist

### Phase 1: Rebase and contract simplification

- [ ] Rebase PR 237 onto current `main` and resolve conflicts.
- [x] Remove the conflicting branch ADR-0058 and allocate the next ADR number.
- [x] Remove `channel_count` from the backend migration.
- [x] Remove `channel_count` from `LockerBank`, casts, factories, and enums.
- [x] Remove the Filament channel-count field and dependent address limits.
- [x] Remove channel-count validation from `LockerService`.
- [x] Remove `channel_count` from the aggregate event and MQTT publisher.
- [x] Remove `channel_count` from AsyncAPI schema and examples.
- [x] Recalculate config hashes from exactly:
  `adapter_type`, `feedback_type`, and normalized `compartments`.
- [x] Update backend and client golden hash vectors together.
- [x] Add only protocol-encodability validation for addresses.
- [x] Verify backend contract tests and relevant feature tests.

Suggested commit:

```text
refactor(config): remove locker board channel count
```

### Phase 2: Client configuration and compatibility

- [x] Remove `ChannelCount` from client domain types.
- [x] Remove channel count from `HardwareProfile`.
- [x] Remove channel count from Zod MQTT schemas.
- [x] Remove channel count from canonical config normalization and hashing.
- [x] Remove channel count from runtime-overlay sanitization.
- [x] Add the legacy-overlay Waveshare fallback only when compartments exist.
- [x] Keep new clients capability-neutral before their first `apply_config`.
- [x] Read RS485 baud rate, data bits, stop bits, parity, and timeout from the
  existing validated serial settings.
- [x] Document that the external `modbus` key is temporarily shared by both
  serial protocols.
- [x] Add tests for legacy overlays and fresh installations.

Suggested commit:

```text
fix(config): preserve legacy waveshare overlays
```

### Phase 3: Shared serial timing and frame transport

- [x] Extract a protocol-neutral serial timing calculator.
- [x] Keep the verified 9600/8N1 delay result at 5 ms.
- [x] Apply the timing helper to the Waveshare driver without changing behavior.
- [x] Replace RS485 `expectedResponseLength` with quiet-period frame completion.
- [x] Keep the configured overall response timeout.
- [x] Support responses split across arbitrary serial data chunks.
- [x] Validate minimum shape, expected header/board/command, and XOR after frame
  completion.
- [x] Decode all returned status bytes and trim only at the configured-address
  consumer.
- [x] Represent absent response bits as `unknown`.
- [x] Add the captured seven-byte 8-channel response as a regression fixture.
- [x] Add tests for split frames, single-chunk frames, continuous noise, and
  timeout before the first byte.

Suggested commit:

```text
fix(rs485): parse complete locker board frames
```

### Phase 4: Buffer recovery and shared reconnect

- [x] Flush/discard RX after timeout and malformed responses.
- [x] Close and reopen the port after synchronization loss.
- [x] Ensure delayed bytes from a failed request cannot satisfy the next one.
- [x] Move reconnect policy and state into a shared serial connection component.
- [x] Use it from both Waveshare and RS485 actors.
- [x] Preserve the per-actor physical hardware queue.
- [x] Make startup continue with state `unreachable` when the adapter is absent.
- [x] Recover automatically after the configured cooldown.
- [x] Classify reconnectability by error code and nested causes.
- [x] Keep permission and unknown failures loud and non-looping.
- [x] Add parity tests proving both actors follow the same state transitions.
- [x] Add tests for unplug, late plug-in, timeout, overflow, and stale bytes.

Suggested commit:

```text
refactor(serial): share reconnect and recovery policy
```

### Phase 5: Runtime lifecycle and `apply_config`

- [x] Reduce `RuntimeConfiguredLockerBus` to a FIFO lifecycle mutex.
- [x] Keep transaction priority exclusively in concrete hardware actors.
- [x] Prove an in-flight open pins the old adapter until completion.
- [x] Prove a pending profile switch runs immediately afterwards.
- [x] Make recoverable connection failure leave the new overlay applied.
- [x] Report the active adapter as `unreachable` instead of rolling back.
- [x] Retain rollback for validation, persistence, hash, and construction errors.
- [x] Ensure rollback failure cannot hide the original failure.
- [x] Add tests for configuring while disconnected and reconnecting later.

Suggested commit:

```text
fix(config): retain profiles while hardware is offline
```

### Phase 6: Opening semantics and relay-state removal

- [x] Change `flashRelay` to command acknowledgement only.
- [x] Map RS485 action failure to a typed command/hardware error.
- [x] Remove immediate `opened` and `door_jammed` outcomes from unlock feedback.
- [x] Remove the synchronous pre-actuation door query.
- [x] Stop producing new `already_open` outcomes.
- [x] Continue post-actuation door monitoring for `opened` and `door_jammed`.
- [x] Remove `readRelayState` from the bus port and all implementations.
- [x] Remove relay-state monitoring from `OpenCompartmentUseCase`.
- [x] Keep `RelayFireLog` and later door correlation.
- [x] Update simulator, fakes, backend expectations, and contract tests.
- [x] Update or supersede ADR-0040.

Suggested commit:

```text
refactor(locker): separate actuation from door state
```

### Phase 7: Documentation and final verification

- [x] Add the final ADR under the next available number.
- [x] Update runtime-overlay ADR references.
- [x] Update locker-client README and serial configuration documentation.
- [x] Update AsyncAPI examples and MQTT integration documentation.
- [x] Remove all claims that channel count determines response length.
- [x] Remove all claims that unlock feedback proves the door opened.
- [x] Run `pnpm check`.
- [x] Run `pnpm test`.
- [x] Run backend format and focused feature tests.
- [ ] Run backend static analysis (currently blocked by the pre-existing
  `AuditEventPresenter.php:154` finding on `main`).
- [x] Run the full backend test suite before merge (439 tests, 0 failures).
- [ ] Confirm all GitHub checks pass on the rebased head.

Suggested commit:

```text
docs(rs485): record locker board protocol decisions
```

## Hardware verification checklist

Use one process only on the serial port.

### Verified 8-channel board

- [x] Query board address 1 and capture:
  `80 01 00 00 00 33 B2`.
- [x] Confirm the frame is accepted without declaring a 24-channel board.
- [x] Unlock board 1, zero-based address 0, and capture:
  `8A 01 01 00 8A`.
- [x] Confirm command acknowledgement does not publish physical `opened`.
- [x] Confirm unchanged feedback without a bolt does not create a false state
  transition.
- [ ] Confirm polling publishes only configured compartments.

### Timing and recovery

- [x] Run query/unlock/query repeatedly at configured 9600/8N1.
- [x] Confirm the calculated 5 ms inter-transaction silence.
- [ ] Disconnect the USB adapter during a query.
- [ ] Confirm timeout, buffer discard, port recreation, and `unreachable`.
- [ ] Reconnect the adapter and confirm automatic recovery without process restart.
- [x] Inject or simulate a late response after timeout and prove it is not consumed
  by the next transaction.
- [ ] Run a sustained polling/actuation soak test.
- [ ] Repeat on the production Raspberry Pi before release.

### Additional boards

- [ ] Capture at least one larger board before claiming its query-all layout is
  supported.
- [ ] Test two chained board addresses on one serial bus.
- [ ] Confirm switching board addresses respects the same quiet-period timing.
- [ ] Record observed response layouts in tests and the ADR; do not infer them
  from product channel counts.

## Acceptance criteria

The adjusted PR is ready for approval when:

- no code or contract contains `channel_count`
- the verified 8-channel board query and unlock frames pass
- serial response parsing does not require a predeclared response length
- timeout or malformed input cannot poison the next transaction
- both hardware adapters use one reconnect/state policy
- the physical hardware queue remains serialized and prioritized
- valid configuration can be applied while hardware is disconnected
- legacy Waveshare overlays start successfully
- action acknowledgement cannot be mistaken for physical door state
- opening is not delayed by a synchronous pre-read
- relay-state monitoring has been removed unless a concrete product behavior
  justifies it
- architecture documentation reflects the final implementation
- local quality checks and current PR CI are green

## Explicit non-goals

- automatic physical board-size detection
- a board inventory table
- unsolicited `0x82` firmware support
- renaming the external YAML `modbus` key in this PR
- changing MQTT provisioning or authentication
- changing retained compartment snapshots as the source of current door state
