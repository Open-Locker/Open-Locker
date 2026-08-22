/**
 * Span attribute keys, kept identical to the backend's `App\Observability\
 * TraceAttributes` so one query answers a question across both services.
 * Changing a key here without changing it there splits the data.
 *
 * Nothing here may carry credentials or a provisioning token.
 */

/** Messaging system name; constant for this application. */
export const MESSAGING_SYSTEM = 'messaging.system';

/** MQTT topic the message was published to or received on. */
export const MESSAGING_DESTINATION = 'messaging.destination.name';

/** Transport-level message identifier (`message_id` in the MQTT envelope). */
export const MESSAGING_MESSAGE_ID = 'messaging.message.id';

/** Business correlation id for transaction-bound flows. */
export const TRANSACTION_ID = 'open_locker.transaction_id';

/** Command identifier carried by command envelopes. */
export const COMMAND_ID = 'open_locker.command_id';

/** Locker bank UUID, as used in MQTT topics. */
export const LOCKER_UUID = 'open_locker.locker_uuid';

/** Compartment number within a locker bank. */
export const COMPARTMENT_NUMBER = 'open_locker.compartment_number';

/** Command action name, e.g. `open_compartment`. */
export const ACTION = 'open_locker.action';

/** Device event name for outbound event messages. */
export const EVENT = 'open_locker.event';

export const MESSAGING_SYSTEM_MQTT = 'mqtt';

/**
 * Modbus has no OpenTelemetry semantic convention, so these are project keys.
 * They are what turns "the locker did not open" into "board 3 stopped
 * answering".
 */
export const MODBUS_OPERATION = 'open_locker.modbus.operation';
export const MODBUS_SLAVE_ID = 'open_locker.modbus.slave_id';
export const MODBUS_ADDRESS = 'open_locker.modbus.address';
export const MODBUS_LENGTH = 'open_locker.modbus.length';
export const MODBUS_DURATION_MS = 'open_locker.modbus.duration_ms';
