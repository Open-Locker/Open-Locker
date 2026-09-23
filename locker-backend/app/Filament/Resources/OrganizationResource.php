<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationResource\Pages;
use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\DefaultOrganization;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Managing operators, as opposed to managing one operator.
 *
 * Only a platform admin sees this, which is the whole difference between the
 * two jobs — a second panel would have duplicated every other resource to add
 * this one.
 */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    /**
     * An organization is not owned by an organization. Scoping this to the
     * current tenant would hide every operator but the one being viewed.
     */
    protected static bool $isScopedToTenant = false;

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): ?string
    {
        return __('Setup');
    }

    public static function getNavigationLabel(): string
    {
        return __('Organizations');
    }

    public static function getModelLabel(): string
    {
        return __('Organization');
    }

    /**
     * Hidden entirely on a single-organization installation, and never shown to
     * someone who administers one operator rather than the installation.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canViewAny(): bool
    {
        if (! config('organizations.multi_organization')) {
            return false;
        }

        $user = auth()->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    /**
     * An organization owns locker banks, compartments, grants, terms and an
     * immutable event history. Deleting one is an operational procedure with no
     * safe default, not a row action, so it is not offered here at all.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->live(onBlur: true)
                // Only while creating. On an edit this would regenerate the
                // slug from the new name and save it, breaking every panel URL
                // for that organization — and for the default one it would move
                // the slug off `default`, which is how unstamped historical
                // events are found.
                ->afterStateUpdated(function (?string $state, callable $set, string $operation): void {
                    if ($operation !== 'create') {
                        return;
                    }

                    $set('slug', Str::slug((string) $state));
                }),
            TextInput::make('slug')
                ->label(__('Slug'))
                ->required()
                ->unique(ignoreRecord: true)
                // The default organization's slug is looked up by name to
                // resolve history that predates organizations; renaming it
                // would orphan every unstamped event.
                ->disabled(fn (?Organization $record): bool => $record?->slug === DefaultOrganization::SLUG)
                // The slug is in every panel URL for this organization, so
                // changing it breaks links people have already saved.
                ->helperText(__('Used in panel URLs. Changing it invalidates existing links.')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('slug')->label(__('Slug'))->searchable(),
                TextColumn::make('users_count')->counts('users')->label(__('Members')),
                TextColumn::make('created_at')->label(__('Created'))->dateTime()->sortable(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
            'edit' => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }
}
