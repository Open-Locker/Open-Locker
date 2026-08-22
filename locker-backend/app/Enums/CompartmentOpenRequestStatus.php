<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a compartment open request.
 *
 * Up to and including Sent the status is backend lifecycle, before the device is
 * involved. From Acknowledged onward it mirrors what the device reported.
 *
 * Acknowledged and Opened are deliberately distinct: acknowledgement means the
 * unlock pulse was sent, which is not the same as the door having opened.
 */
enum CompartmentOpenRequestStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Denied = 'denied';
    case Sent = 'sent';
    case Acknowledged = 'acknowledged';
    case Opened = 'opened';
    case AlreadyOpen = 'already_open';
    case DoorJammed = 'door_jammed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => __('Requested'),
            self::Accepted => __('Authorized'),
            self::Denied => __('Denied'),
            self::Sent => __('Sent to locker'),
            self::Acknowledged => __('Unlock pulse sent'),
            self::Opened => __('Door opened'),
            self::AlreadyOpen => __('Door was already open'),
            self::DoorJammed => __('Door did not open'),
            self::Failed => __('Failed'),
        };
    }

    /**
     * Filament badge colour for this status.
     *
     * Acknowledged is not a success: the unlock pulse was sent, but nothing has
     * confirmed the door moved. AlreadyOpen warns because the open was a no-op.
     */
    public function color(): string
    {
        return match ($this) {
            self::Opened => 'success',
            self::Denied, self::DoorJammed, self::Failed => 'danger',
            self::AlreadyOpen => 'warning',
            self::Requested, self::Accepted, self::Sent, self::Acknowledged => 'info',
        };
    }
}
