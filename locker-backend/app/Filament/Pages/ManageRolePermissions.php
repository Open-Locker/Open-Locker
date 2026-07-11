<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Authorization\AuthorizationCatalog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Roles overview (ADR-0021): lists every catalog role with how many permissions
 * it currently holds, linking into {@see ManageRolePermissionsForRole} where the
 * individual permission switches live.
 */
class ManageRolePermissions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.manage-role-permissions';

    public static function getNavigationGroup(): ?string
    {
        return __('Setup');
    }

    public static function getNavigationLabel(): string
    {
        return __('Roles & Permissions');
    }

    public function getTitle(): string
    {
        return __('Roles & Permissions');
    }

    public static function canAccess(): bool
    {
        return self::currentUserCanManage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->buildRecords())
            ->columns([
                TextColumn::make('label')
                    ->label(__('Role'))
                    ->badge(),
                TextColumn::make('permission_count')
                    ->label(__('Permissions'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('note')
                    ->label('')
                    ->color('gray')
                    ->placeholder(''),
            ])
            ->recordActions([
                Action::make('manage')
                    ->label(__('Manage permissions'))
                    ->icon('heroicon-m-key')
                    ->visible(fn (): bool => self::currentUserCanManage())
                    ->url(fn (array $record): string => ManageRolePermissionsForRole::getUrl(['role' => $record['role']])),
            ])
            ->paginated(false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildRecords(): array
    {
        $catalog = app(AuthorizationCatalog::class);
        $totalPermissions = count($catalog->permissions());

        $counts = RolePermission::query()
            ->whereNull('revoked_at')
            ->selectRaw('role, count(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role');

        return collect($catalog->roles())
            ->mapWithKeys(fn (string $role): array => [
                $role => [
                    'role' => $role,
                    'label' => self::roleLabel($role),
                    'permission_count' => $role === Role::Admin->value
                        ? $totalPermissions
                        : (int) ($counts[$role] ?? 0),
                    'note' => $role === Role::Admin->value
                        ? __('Super role – always all permissions (read-only)')
                        : null,
                ],
            ])
            ->sortByDesc('permission_count')
            ->all();
    }

    private static function currentUserCanManage(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can(Permission::RolesManage->value);
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            Role::User->value => __('User role'),
            Role::Manager->value => __('Manager'),
            Role::Admin->value => __('Admin'),
            default => ucfirst($role),
        };
    }
}
