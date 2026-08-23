<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Compartment;
use Illuminate\Support\HtmlString;

/**
 * Visible group heading for the compartment list: bank name, connection
 * indicator, and a hidden id so Filament/accordion titles stay unique.
 */
final class LockerBankGroupHeading
{
    public static function title(Compartment $record): HtmlString
    {
        $bank = $record->lockerBank;
        $name = $bank?->name ?: __('Locker bank');
        $status = $bank?->connection_status ?: 'unknown';
        $label = __($status);
        $color = match ($status) {
            'online' => '#16a34a',
            'offline' => '#dc2626',
            default => '#9ca3af',
        };

        // Filament puts the title into aria-label="..." without escaping
        // Htmlable values. Double quotes would close that attribute and dump
        // the collapse button markup onto the page.
        return new HtmlString(
            '<span style=\'display:inline-flex;align-items:center;column-gap:.375rem;white-space:nowrap\'>'
            .'<span data-group-name>'.e($name).'</span>'
            .'<span hidden>'.e((string) $record->locker_bank_id).'</span>'
            .'<span data-connection-status=\''.e($status).'\' title=\''.e($label).'\' aria-label=\''.e($label).'\' style=\'display:inline-block;width:.625rem;height:.625rem;border-radius:9999px;background:'.$color.';flex:0 0 auto\'></span>'
            .'</span>'
        );
    }

    public static function description(Compartment $record): ?string
    {
        $location = $record->lockerBank?->location_description;

        return filled($location) ? $location : null;
    }
}
