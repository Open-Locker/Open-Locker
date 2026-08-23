<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Compartment;
use Illuminate\Support\HtmlString;

/**
 * Visible group heading for the compartment list: bank name, connection badge,
 * and a hidden id so Filament/accordion titles stay unique.
 */
final class LockerBankGroupHeading
{
    public static function title(Compartment $record): HtmlString
    {
        $bank = $record->lockerBank;
        $name = $bank?->name ?: __('Locker bank');
        $status = $bank?->connection_status ?: 'unknown';
        $color = match ($status) {
            'online' => 'success',
            'offline' => 'danger',
            default => 'gray',
        };

        return new HtmlString(
            '<span class="inline-flex items-center gap-2">'
            .'<span data-group-name>'.e($name).'</span>'
            .'<span hidden>'.e((string) $record->locker_bank_id).'</span>'
            .'<span class="fi-badge fi-size-sm fi-color fi-color-'.$color.'">'
            .'<span class="fi-badge-label-ctn"><span class="fi-badge-label">'.e(__($status)).'</span></span>'
            .'</span>'
            .'</span>'
        );
    }

    public static function description(Compartment $record): ?string
    {
        $location = $record->lockerBank?->location_description;

        return filled($location) ? $location : null;
    }
}
