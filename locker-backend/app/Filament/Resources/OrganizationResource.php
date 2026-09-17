<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationResource\Pages;
use App\Models\Organization;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, callable $set): void {
                    $set('slug', Str::slug((string) $state));
                }),
            TextInput::make('slug')
                ->label(__('Slug'))
                ->required()
                ->unique(ignoreRecord: true)
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
                TextColumn::make('members_count')->counts('members')->label(__('Members')),
                TextColumn::make('created_at')->label(__('Created'))->dateTime()->sortable(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
        ];
    }
}
