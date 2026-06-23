<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Aggregates\RoleAggregate;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Authorization\AuthorizationCatalog;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Per-role detail screen (reached from {@see ManageRolePermissions}): lists every
 * catalog permission with its grant/revoke audit and a single contextual action —
 * green "Gewähren" when inactive, red "Entziehen" when active — mirroring the
 * compartment-access screens (ADR-0021, ADR-0026).
 *
 * Actions record RolePermissionGranted/Revoked via {@see RoleAggregate}; the
 * read model (role_permissions) is rebuilt by the projector and keeps revoked
 * rows for the audit trail. The `admin` super-role is short-circuited in
 * Gate::before, so it is shown all-granted and read-only.
 */
class ManageRolePermissionsForRole extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'rollen-berechtigungen/{role}';

    protected string $view = 'filament.pages.manage-role-permissions-for-role';

    public string $role = '';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return self::currentUserCanManage();
    }

    public function mount(string $role): void
    {
        abort_unless(self::currentUserCanManage(), 403);
        abort_unless(app(AuthorizationCatalog::class)->hasRole($role), 404);

        $this->role = $role;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Berechtigungen: '.self::roleLabel($this->role);
    }

    /**
     * @return array<string|int, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            ManageRolePermissions::getUrl() => 'Rollen & Berechtigungen',
            self::roleLabel($this->role),
        ];
    }

    public function table(Table $table): Table
    {
        $role = $this->role;
        $isAdmin = $role === Role::Admin->value;

        return $table
            ->records(fn (): array => $this->buildRecords($role, $isAdmin))
            ->columns([
                TextColumn::make('permission')
                    ->label('Berechtigung')
                    ->description(fn (array $record): ?string => $record['description'])
                    ->weight('medium')
                    ->badge()
                    ->color(fn (array $record): string => $record['active'] ? 'success' : 'gray'),
                TextColumn::make('granted_at')
                    ->label('Gewährt am')
                    ->placeholder('—'),
                TextColumn::make('granted_by')
                    ->label('Gewährt von')
                    ->placeholder('System'),
                TextColumn::make('revoked_at')
                    ->label('Entzogen am')
                    ->placeholder('—'),
                TextColumn::make('revoked_by')
                    ->label('Entzogen von')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('grant')
                    ->label('Gewähren')
                    ->color('success')
                    ->icon('heroicon-m-key')
                    ->visible(fn (array $record): bool => ! $isAdmin && self::currentUserCanManage() && ! $record['active'])
                    ->action(fn (array $record) => $this->applyToggle($role, (string) $record['permission'], true)),
                Action::make('revoke')
                    ->label('Entziehen')
                    ->color('danger')
                    ->icon('heroicon-m-no-symbol')
                    ->requiresConfirmation()
                    ->visible(fn (array $record): bool => ! $isAdmin && self::currentUserCanManage() && $record['active'])
                    ->action(fn (array $record) => $this->applyToggle($role, (string) $record['permission'], false)),
            ])
            ->paginated(false);
    }

    private function applyToggle(string $role, string $permission, bool $granted): void
    {
        abort_unless(self::currentUserCanManage(), 403);

        $catalog = app(AuthorizationCatalog::class);
        abort_unless($catalog->hasRole($role) && $catalog->hasPermission($permission), 422);

        // The admin super-role is read-only here; never mutate its bindings.
        if ($role === Role::Admin->value) {
            return;
        }

        $aggregate = RoleAggregate::retrieve(RoleAggregate::aggregateUuidFor($role));

        if ($granted) {
            $aggregate->grantPermission($role, $permission, Filament::auth()->id(), now());
        } else {
            $aggregate->revokePermission($role, $permission, Filament::auth()->id(), now());
        }

        $aggregate->persist();

        $this->resetTable();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildRecords(string $role, bool $isAdmin): array
    {
        /** @var \Illuminate\Support\Collection<string, RolePermission> $bindings */
        $bindings = RolePermission::query()
            ->where('role', $role)
            ->with(['grantedByUser', 'revokedByUser'])
            ->get()
            ->keyBy('permission');

        $descriptions = self::permissionDescriptions();

        $records = [];
        foreach (app(AuthorizationCatalog::class)->permissions() as $permission) {
            $binding = $bindings->get($permission);
            $active = $isAdmin || ($binding !== null && $binding->revoked_at === null);

            $records[$permission] = [
                'permission' => $permission,
                'description' => $descriptions[$permission] ?? null,
                'active' => $active,
                'granted_at' => $active ? $binding?->granted_at?->toDayDateTimeString() : null,
                'granted_by' => $active ? $binding?->grantedByUser?->fullName() : null,
                'revoked_at' => $binding?->revoked_at?->toDayDateTimeString(),
                'revoked_by' => $binding?->revokedByUser?->fullName(),
            ];
        }

        return $records;
    }

    private static function currentUserCanManage(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can(Permission::RolesManage->value);
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            Role::User->value => 'Nutzer',
            Role::Manager->value => 'Manager',
            Role::Admin->value => 'Admin',
            default => ucfirst($role),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function permissionDescriptions(): array
    {
        return [
            Permission::PanelAccess->value => 'Darf sich am Admin-Panel anmelden.',
            Permission::UsersManage->value => 'Nutzerdatensätze ansehen / verwalten.',
            Permission::GroupsManage->value => 'Gruppen, Mitgliedschaften und Gruppen-Fachzugriffe verwalten.',
            Permission::CompartmentAccessManage->value => 'Fachzugriffe für Nutzer gewähren / entziehen.',
            Permission::CompartmentOpen->value => 'Beliebige Fächer operativ öffnen.',
            Permission::RolesManage->value => 'Rollen und Rollen-Berechtigungen gewähren / entziehen.',
            Permission::LockerBankConfigure->value => 'Technische Konfiguration (Modbus slave_id/address, Provisioning, Heartbeat).',
            Permission::SystemConfigure->value => 'Rechtliche / systemweite Konfigurationsressourcen.',
        ];
    }
}
