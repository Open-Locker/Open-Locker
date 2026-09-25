<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRole;
use App\Services\UserAdministrationService;
use App\Support\Organizations\OrganizationContext;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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
            // The attach and detach guards were right, but the listing was not:
            // a person who belongs to two operators showed both, so an admin of
            // one could read the other's name off a shared employee's record.
            // Nobody sees a combined view of several operators anywhere.
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->visibleOrganizations($query))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->sortable(),
                TextColumn::make('slug')->label(__('Slug'))->toggleable(),
                TextColumn::make('role')
                    ->label(__('Role'))
                    ->badge()
                    // Roles live in user_roles, not on the pivot, because they
                    // are event-sourced: the membership says where someone
                    // belongs, the role says what they may do there.
                    ->state(fn (Organization $record): string => $this->roleWithin($record)->label())
                    ->color(fn (Organization $record): string => $this->roleWithin($record) === Role::User ? 'gray' : 'primary'),
                TextColumn::make('pivot.joined_at')->label(__('Member since'))->dateTime(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label(__('Add to organization'))
                    ->visible(fn (): bool => $this->currentUserCanManageMemberships())
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $this->attachableOrganizations($query))
                    // The role is chosen here rather than copied from whatever
                    // the person holds elsewhere: carrying a role sideways would
                    // let adding someone to an organization quietly make them
                    // its administrator. Defaults to an ordinary user, which is
                    // a real state — an end user belongs to an operator and has
                    // no business in the panel at all.
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('role')
                            ->label(__('Role in this organization'))
                            ->options($this->grantableRoleOptions())
                            ->default(Role::User->value)
                            ->required(),
                    ])
                    ->mutateDataUsing(function (array $data): array {
                        $data['joined_at'] = now();

                        return $data;
                    })
                    ->after(function (array $data, Organization $record): void {
                        $this->grantRoleWithin($record, $data['role'] ?? Role::User->value);

                        $actor = $this->currentUser();
                        $owner = $this->getOwnerRecord();

                        if ($actor instanceof User && $owner instanceof User) {
                            $administration = app(UserAdministrationService::class);
                            $administration->recordJoin($actor, $owner, $record, existingAccount: true);
                            $administration->notifyAddedToOrganization($actor, $owner, $record);
                        }
                    }),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label(__('Remove'))
                    ->visible(fn (Organization $record): bool => $this->currentUserCanManageMembership($record))
                    // Not Filament's detach: that drops only the membership and
                    // leaves the roles held there.
                    ->action(function (Organization $record, DetachAction $action): void {
                        $actor = $this->currentUser();
                        $owner = $this->getOwnerRecord();

                        if (! $actor instanceof User || ! $owner instanceof User) {
                            return;
                        }

                        if (! app(UserAdministrationService::class)->removeFromOrganization($actor, $owner, $record)) {
                            Notification::make()
                                ->title(__('Cannot remove'))
                                ->body(__('The last admin cannot be removed from the organization.'))
                                ->danger()
                                ->send();
                            $action->cancel();

                            return;
                        }

                        $action->success();
                    }),
            ]);
    }

    /**
     * What the owner of this record may do inside one organization.
     *
     * No row means an ordinary user: a membership without a role is a real
     * state, not a missing one.
     */
    private function roleWithin(Organization $organization): Role
    {
        $role = UserRole::query()
            ->where('user_id', $this->getOwnerRecord()->getKey())
            ->where('organization_id', $organization->getKey())
            ->orderByRaw("CASE role WHEN 'admin' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END")
            ->value('role');

        return is_string($role) ? (Role::tryFrom($role) ?? Role::User) : Role::User;
    }

    /**
     * Roles the current user may hand out. platform_admin is deliberately
     * absent for anyone but a platform admin, matching what the service would
     * enforce anyway.
     *
     * @return array<string, string>
     */
    private function grantableRoleOptions(): array
    {
        $options = [];

        foreach (Role::cases() as $role) {
            if ($role === Role::PlatformAdmin) {
                continue;
            }

            $options[$role->value] = $role->label();
        }

        return $options;
    }

    /**
     * Role::User is the absence of a role rather than a row of its own, so
     * there is nothing to record for it.
     */
    private function grantRoleWithin(Organization $organization, string $role): void
    {
        if ($role === Role::User->value) {
            return;
        }

        $owner = $this->getOwnerRecord();

        app(OrganizationContext::class)->runWithin($organization, function () use ($owner, $organization, $role): void {
            UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($owner->getKey()))
                ->grantRole($owner->getKey(), $role, $this->currentUser()?->getKey(), now(), $organization->getKey())
                ->persist();
        });
    }

    /**
     * A platform admin administers every operator and so sees every membership.
     * Everyone else sees only the organization they are acting in, even on a
     * record that belongs to more than one.
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    private function visibleOrganizations(Builder $query): Builder
    {
        if ($this->currentUser()?->isPlatformAdmin() === true) {
            return $query;
        }

        return $query->whereKey(app(OrganizationContext::class)->currentId());
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
