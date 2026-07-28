<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Span attribute keys.
 *
 * OpenTelemetry semantic conventions are used wherever one exists; domain facts
 * with no standard equivalent live under the `open_locker.` namespace.
 *
 * Nothing here may carry credentials.
 */
final class TraceAttributes
{
    /** Messaging system name; constant for this application. */
    public const MESSAGING_SYSTEM = 'messaging.system';

    /** MQTT topic the message was published to or received on. */
    public const MESSAGING_DESTINATION = 'messaging.destination.name';

    /** Transport-level message identifier (`message_id` in the MQTT envelope). */
    public const MESSAGING_MESSAGE_ID = 'messaging.message.id';

    /** Business correlation id for transaction-bound flows. */
    public const TRANSACTION_ID = 'open_locker.transaction_id';

    /** Command identifier carried by command envelopes. */
    public const COMMAND_ID = 'open_locker.command_id';

    /** Locker bank UUID, as used in MQTT topics. */
    public const LOCKER_UUID = 'open_locker.locker_uuid';

    /** Compartment number within a locker bank. */
    public const COMPARTMENT_NUMBER = 'open_locker.compartment_number';

    /** Command action name, e.g. `open_compartment`. */
    public const ACTION = 'open_locker.action';

    /** Device event name for inbound event messages. */
    public const EVENT = 'open_locker.event';

    public const MESSAGING_SYSTEM_MQTT = 'mqtt';
}
