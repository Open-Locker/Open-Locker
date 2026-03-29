<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CompartmentOpenRequestResource;
use App\Filament\Resources\ItemResource;
use App\Filament\Resources\LockerBankResource;
use App\Filament\Resources\TermsDocumentVersionResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

class FilamentAdminSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_resources_can_build_table_configuration(): void
    {
        $livewire = $this->createMock(HasTable::class);

        $this->assertInstanceOf(Table::class, ItemResource::table(Table::make($livewire)));
        $this->assertInstanceOf(Table::class, UserResource::table(Table::make($livewire)));
        $this->assertInstanceOf(Table::class, LockerBankResource::table(Table::make($livewire)));
        $this->assertInstanceOf(Table::class, TermsDocumentVersionResource::table(Table::make($livewire)));
        $this->assertInstanceOf(Table::class, CompartmentOpenRequestResource::table(Table::make($livewire)));
    }

    public function test_filament_resources_do_not_use_deprecated_table_actions_namespace(): void
    {
        $resourceFiles = File::allFiles(app_path('Filament/Resources'));

        foreach ($resourceFiles as $file) {
            $contents = File::get($file->getPathname());

            $this->assertStringNotContainsString('Tables\\Actions\\', $contents, $file->getPathname());
        }
    }

    public function test_edit_user_page_exposes_expected_header_actions(): void
    {
        $page = app(EditUser::class);
        $method = (new ReflectionClass($page))->getMethod('getHeaderActions');
        $method->setAccessible(true);

        $actions = $method->invoke($page);
        $group = collect($actions)->first(fn (mixed $action): bool => $action instanceof ActionGroup);

        $this->assertInstanceOf(ActionGroup::class, $group);

        $headerActionNames = collect($actions)
            ->map(fn (Action|DeleteAction|ActionGroup $action): ?string => $action instanceof ActionGroup ? null : $action->getName())
            ->filter()
            ->values()
            ->all();
        $groupActionNames = collect($group->getActions())
            ->map(fn (Action $action): ?string => $action->getName())
            ->filter()
            ->values()
            ->all();

        $this->assertContains('delete', $headerActionNames);
        $this->assertContains('sendPasswordResetLink', $groupActionNames);
        $this->assertContains('sendVerificationEmail', $groupActionNames);
        $this->assertContains('setAsAdmin', $groupActionNames);
        $this->assertContains('removeAdmin', $groupActionNames);
    }

    public function test_unverified_admin_can_access_filament_panel_for_verification_flow(): void
    {
        $user = User::factory()->unverified()->create();
        $user->makeAdmin();

        $response = $this->actingAs($user)->get(route('filament.admin.pages.dashboard'));

        $response->assertRedirect(route('filament.admin.auth.email-verification.prompt'));
    }

    public function test_authenticated_unverified_admin_can_load_filament_login_route_without_server_error(): void
    {
        $user = User::factory()->unverified()->create();
        $user->makeAdmin();

        $response = $this->actingAs($user)->get(route('filament.admin.auth.login'));

        $response->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    public function test_non_admin_cannot_access_filament_panel(): void
    {
        User::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('filament.admin.pages.dashboard'));

        $response->assertForbidden();
    }
}
