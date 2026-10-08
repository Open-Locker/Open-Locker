<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * The tabs of the admin audit log. Every {@see Audited} event names one.
 */
enum AuditCategory: string
{
    case Access = 'access';
    case Devices = 'devices';
    case Admin = 'admin';
    case Terms = 'terms';

    public function label(): string
    {
        return match ($this) {
            self::Access => __('Access'),
            self::Devices => __('Devices & Lockers'),
            self::Admin => __('Administration'),
            self::Terms => __('Terms & Legal'),
        };
    }
}
