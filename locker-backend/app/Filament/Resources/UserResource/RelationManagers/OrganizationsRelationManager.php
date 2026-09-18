<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\OrganizationContext;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Which operators a person belongs to.
 *
 * Only shown where the concept exists at all: a single-organization
 * installation never displays it, so nobody there learns that organizations are
 * a thing. Who may change a membership follows the same split as everything
 * else — a platform admin moves people between operators, an organization admin
 * only adds and removes within their own.
 */
class OrganizationsRelationManager extends RelationManager
{
    protected static string $relationship = 'organizations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Organizations');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) config('organizations.multi_organization');
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->sortable(),
                TextColumn::make('slug')->label(__('Slug'))->toggleable(),
                TextColumn::make('pivot.joined_at')->label(__('Member since'))->dateTime(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label(__('Add to organization'))
                    ->visible(fn (): bool => $this->currentUserCanManageMemberships())
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $this->attachableOrganizations($query))
                    ->mutateDataUsing(function (array $data): array {
                        $data['joined_at'] = now();

                        return $data;
                    }),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label(__('Remove'))
                    ->visible(fn (Organization $record): bool => $this->currentUserCanManageMembership($record)),
            ]);
    }

    /**
     * A platform admin may place someone in any operator. An organization admin
     * may only add to the organization they are administering — otherwise
     * "manage your own users" would quietly become "move users anywhere".
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    private function attachableOrganizations(Builder $query): Builder
    {
        $user = $this->currentUser();

        if ($user?->isPlatformAdmin() === true) {
            return $query;
        }

        return $query->whereKey(app(OrganizationContext::class)->currentId());
    }

    private function currentUserCanManageMemberships(): bool
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return false;
        }

        return $user->isPlatformAdmin() || $user->isAdmin();
    }

    private function currentUserCanManageMembership(Organization $organization): bool
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Removing someone from an organization you are not administering is
        // reaching across the boundary, even when you administer your own.
        return $user->isAdmin()
            && $organization->getKey() === app(OrganizationContext::class)->currentId();
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
