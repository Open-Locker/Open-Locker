<?php

declare(strict_types=1);

namespace App\Enums;

enum LockerAdapterType: string
{
    case WaveshareModbus = 'waveshare_modbus';
    case Rs485LockBoard = 'rs485_lock_board';

    public function label(): string
    {
        return match ($this) {
            self::WaveshareModbus => __('Waveshare Modbus relay board'),
            self::Rs485LockBoard => __('RS485 locker board'),
        };
    }
}
