<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CompartmentResource\Pages\ViewCompartment;
use App\Filament\Resources\CompartmentResource\RelationManagers\GroupAccessesRelationManager;
use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Resources\GroupResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Filament\Support\AccessPickerOptions;
use App\Models\Compartment;
use App\Models\Group;
use App\Models\GroupCompartmentAccess;
use App\Models\User;
use App\Services\GroupAccessService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Granting a group access to a compartment that another group already has
 * shows a warning but stays possible (#379).
 */
class GroupAssignmentWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_warns_only_for_compartments_another_group_already_has(): void
    {
        [$admin, $service] = $this->setUpAdmin();
        $taken = Compartment::factory()->create();
        $free = Compartment::factory()->create();
        $service->grantCompartmentAccess($service->createGroup('Course A', actor: $admin), $taken, actor: $admin);

        $this->assertNull(AccessPickerOptions::otherGroupsWarning([(string) $free->id]));
        $this->assertStringContainsString(
            'Course A',
            (string) AccessPickerOptions::otherGroupsWarning([(string) $free->id, (string) $taken->id]),
        );
    }

    public function test_ignores_revoked_group_access(): void
    {
        [$admin, $service] = $this->setUpAdmin();
        $compartment = Compartment::factory()->create();
        $group = $service->createGroup('Old Course', actor: $admin);
        $service->grantCompartmentAccess($group, $compartment, actor: $admin);
        $service->revokeCompartmentAccess($group, $compartment, actor: $admin);

        $this->assertNull(AccessPickerOptions::otherGroupsWarning([(string) $compartment->id]));
    }

    public function test_group_page_shows_the_warning_below_the_compartment_picker(): void
    {
        [$admin, $service] = $this->setUpAdmin();
        $taken = Compartment::factory()->create();
        $service->grantCompartmentAccess(Group::factory()->create(['name' => 'Course A']), $taken, actor: $admin);

        $component = Livewire::actingAs($admin)
            ->test(CompartmentAccessesRelationManager::class, ['ownerRecord' => Group::factory()->create(), 'pageClass' => EditGroup::class])
            ->mountAction(TestAction::make('grantAccess')->table())
            ->fillForm(['compartment_ids' => [(string) $taken->id]])
            ->instance();

        /** @var Schema $form */
        $form = $component->{$component->getMountedActionSchemaName()};
        /** @var Field $picker */
        $picker = $form->getComponent(fn (mixed $field): bool => $field instanceof Field && $field->getName() === 'compartment_ids');
        $warnings = $picker->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [];

        $this->assertCount(1, $warnings);
        $this->assertInstanceOf(Text::class, $warnings[0]);
        $this->assertStringContainsString('Course A', (string) $warnings[0]->getContent());
    }

    public function test_a_second_group_can_still_be_granted_from_either_page(): void
    {
        [$admin, $service] = $this->setUpAdmin();
        $compartment = Compartment::factory()->create();
        $service->grantCompartmentAccess($service->createGroup('Course A', actor: $admin), $compartment, actor: $admin);
        $courseB = Group::factory()->create();
        $courseC = Group::factory()->create();

        Livewire::actingAs($admin)
            ->test(CompartmentAccessesRelationManager::class, ['ownerRecord' => $courseB, 'pageClass' => EditGroup::class])
            ->callAction(TestAction::make('grantAccess')->table(), data: ['compartment_ids' => [(string) $compartment->id]])
            ->assertHasNoActionErrors();

        Livewire::actingAs($admin)
            ->test(GroupAccessesRelationManager::class, ['ownerRecord' => $compartment, 'pageClass' => ViewCompartment::class])
            ->callAction(TestAction::make('grantAccess')->table(), data: ['group_ids' => [(string) $courseC->id]])
            ->assertHasNoActionErrors();

        $this->assertSame(3, GroupCompartmentAccess::query()->where('compartment_id', $compartment->id)->active()->count());
    }

    /**
     * @return array{User, GroupAccessService}
     */
    private function setUpAdmin(): array
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return [$admin, app(GroupAccessService::class)];
    }
}
