<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\FirmwareReleaseResource\Pages;
use App\Models\FirmwareRelease;
use App\Services\FirmwareReleaseService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Signed firmware images that locker banks can be updated to (ADR-0077).
 * Admin-only, like the locker bank settings it belongs with.
 */
class FirmwareReleaseResource extends Resource
{
    protected static ?string $model = FirmwareRelease::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 11;

    /** Where an upload waits until the server has hashed it and moved it to firmware/{version}.bin. */
    public const UPLOAD_DIRECTORY = 'firmware-uploads';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::LockerBankConfigure->value) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Setup');
    }

    public static function getNavigationLabel(): string
    {
        return __('Firmware releases');
    }

    public static function getModelLabel(): string
    {
        return __('Firmware release');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Firmware releases');
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('version')
                ->label(__('Version'))
                ->required()
                ->maxLength(31)
                ->unique(table: 'firmware_releases', column: 'version')
                ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! FirmwareRelease::isValidVersion((string) $value)) {
                        $fail(__('The version must follow semantic versioning, for example 1.2.3.'));
                    }
                })
                ->helperText(__('Semantic versioning, for example 1.2.3. It must match the version inside the image.')),
            FileUpload::make('image')
                ->label(__('Firmware image'))
                ->required()
                ->directory(self::UPLOAD_DIRECTORY)
                // In kibibytes; the slot size is a whole number of them.
                ->maxSize((int) ceil(FirmwareRelease::MAX_IMAGE_BYTES / 1024))
                ->helperText(__('The signed .bin image, at most :size KiB, the size of an OTA slot.', [
                    'size' => (int) (FirmwareRelease::MAX_IMAGE_BYTES / 1024),
                ])),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('version')
                    ->label(__('Version'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                IconColumn::make('invalid')
                    ->label(__('Invalid'))
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success'),
                TextColumn::make('size_bytes')
                    ->label(__('Size'))
                    ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 1).' KiB'),
                TextColumn::make('sha256')
                    ->label(__('SHA-256'))
                    ->limit(16)
                    ->tooltip(fn (FirmwareRelease $record): string => $record->sha256)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('Uploaded at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Action::make('markInvalid')
                    ->label(__('Mark invalid'))
                    ->icon('heroicon-m-no-symbol')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('An invalid release is never offered or downloaded again. This cannot be undone.'))
                    ->visible(fn (FirmwareRelease $record): bool => ! $record->invalid)
                    ->action(fn (FirmwareRelease $record) => app(FirmwareReleaseService::class)->markInvalid($record)),
                DeleteAction::make()
                    // A release that a bank is still fetching or installing must stay.
                    ->visible(fn (FirmwareRelease $record): bool => ! $record->isInUse())
                    ->using(function (FirmwareRelease $record): bool {
                        app(FirmwareReleaseService::class)->delete($record);

                        return true;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFirmwareReleases::route('/'),
            'create' => Pages\CreateFirmwareRelease::route('/create'),
        ];
    }
}
