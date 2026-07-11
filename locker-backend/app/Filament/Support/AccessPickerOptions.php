<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Compartment;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared option builders for admin-panel assignment pickers, so compartment,
 * user, and group selects stay consistently sorted and labelled across the
 * Filament relation managers (see issue #117).
 *
 * Each method takes a pre-filtered query — the caller is responsible for
 * excluding already-assigned records via `whereDoesntHave`/`whereNotIn`.
 */
final class AccessPickerOptions
{
    /**
     * Compartments sorted by locker bank name, then compartment number,
     * labelled "{locker bank name} / #{compartment number}".
     *
     * @param  Builder<Compartment>  $query
     * @return array<string, string>
     */
    public static function compartments(Builder $query): array
    {
        return $query
            ->with('lockerBank')
            ->join('locker_banks', 'locker_banks.id', '=', 'compartments.locker_bank_id')
            ->orderBy('locker_banks.name')
            ->orderBy('compartments.number')
            ->select('compartments.*')
            ->get()
            ->mapWithKeys(fn (Compartment $compartment): array => [
                (string) $compartment->id => sprintf(
                    '%s / #%d',
                    $compartment->lockerBank->name,
                    (int) $compartment->number
                ),
            ])
            ->all();
    }

    /**
     * Users sorted by name, labelled "{full name} ({email})".
     *
     * @param  Builder<User>  $query
     * @return array<string, string>
     */
    public static function users(Builder $query): array
    {
        return $query
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                (string) $user->id => sprintf('%s (%s)', $user->fullName(), $user->email),
            ])
            ->all();
    }

    /**
     * Groups sorted by name, labelled by name.
     *
     * @param  Builder<Group>  $query
     * @return array<string, string>
     */
    public static function groups(Builder $query): array
    {
        return $query
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Group $group): array => [
                (string) $group->id => (string) $group->name,
            ])
            ->all();
    }
}
