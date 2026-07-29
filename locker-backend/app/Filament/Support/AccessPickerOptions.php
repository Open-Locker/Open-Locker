<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Compartment;
use App\Models\Group;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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
     * Shared assignment picker: a required, multiple, searchable select whose
     * options come from one of the builders below. Used by every access/member
     * grant form so the pickers stay consistent (see issue #127).
     *
     * @param  callable(): array<string, string>  $options
     */
    public static function select(string $name, string $label, callable $options): Select
    {
        return Select::make($name)
            ->label($label)
            ->required()
            ->multiple()
            ->searchable()
            ->options($options);
    }

    /**
     * Complete grant/assignment form: the shared picker plus the common
     * `expires_at` (and optionally `notes`) fields, so every grant modal
     * stays field-for-field identical (see issue #127).
     *
     * @param  callable(): array<string, string>  $options
     * @return array<int, Field>
     */
    public static function grantForm(string $name, string $label, callable $options, bool $withNotes = true): array
    {
        $fields = [
            self::select($name, $label, $options),
            DateTimePicker::make('expires_at')
                ->label(__('Expires at'))
                ->seconds(false),
        ];

        if ($withNotes) {
            $fields[] = Textarea::make('notes')
                ->label(__('Notes'))
                ->rows(3)
                ->maxLength(2000);
        }

        return $fields;
    }

    /**
     * Parse the optional `expires_at` value submitted by a grant form.
     *
     * @param  array<string, mixed>  $data
     */
    public static function parseExpiresAt(array $data): ?Carbon
    {
        $value = $data['expires_at'] ?? null;

        return filled($value) ? Carbon::parse($value) : null;
    }

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
