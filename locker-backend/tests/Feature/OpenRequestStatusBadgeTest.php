<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CompartmentOpenRequestStatus;
use App\Filament\Resources\LockerBankResource\Pages\EditLockerBank;
use App\Filament\Resources\LockerBankResource\RelationManagers\CompartmentsRelationManager;
use App\Filament\Resources\LockerBankResource\RelationManagers\OpenRequestsRelationManager;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\CompartmentOpenRequest;
use App\Models\LockerBank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The status columns used to type-hint `?string $state` while the model casts
 * `status` to an enum, so every table carrying the column threw a TypeError.
 * These render each status through all three tables to keep the badge and the
 * cast from drifting apart again.
 */
class OpenRequestStatusBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function openRequest(Compartment $compartment, CompartmentOpenRequestStatus $status): CompartmentOpenRequest
    {
        return CompartmentOpenRequest::create([
            'command_id' => (string) Str::uuid(),
            'compartment_id' => $compartment->id,
            'authorization_type' => 'admin',
            'status' => $status,
            'requested_at' => now(),
        ]);
    }

    /**
     * @return array<string, array{CompartmentOpenRequestStatus}>
     */
    public static function statusProvider(): array
    {
        $cases = [];

        foreach (CompartmentOpenRequestStatus::cases() as $status) {
            $cases[$status->value] = [$status];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusProvider')]
    public function test_locker_bank_tables_render_every_status(CompartmentOpenRequestStatus $status): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create();
        $compartment = Compartment::factory()->for($lockerBank)->create(['number' => 1]);
        $this->openRequest($compartment, $status);

        // This table shows the door badge rather than the command status, so it
        // only has to survive every status without throwing.
        Livewire::actingAs($admin)
            ->test(CompartmentsRelationManager::class, [
                'ownerRecord' => $lockerBank,
                'pageClass' => EditLockerBank::class,
            ])
            ->assertSuccessful();

        Livewire::actingAs($admin)
            ->test(OpenRequestsRelationManager::class, [
                'ownerRecord' => $lockerBank,
                'pageClass' => EditLockerBank::class,
            ])
            ->assertSuccessful()
            ->assertSee($status->label());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusProvider')]
    public function test_user_compartment_access_table_renders_every_status(CompartmentOpenRequestStatus $status): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $lockerBank = LockerBank::factory()->create();
        $compartment = Compartment::factory()->for($lockerBank)->create(['number' => 1]);
        $this->openRequest($compartment, $status);
        CompartmentAccess::factory()->create([
            'user_id' => $user->id,
            'compartment_id' => $compartment->id,
        ]);

        Livewire::actingAs($admin)
            ->test(CompartmentAccessesRelationManager::class, [
                'ownerRecord' => $user,
                'pageClass' => EditUser::class,
            ])
            ->assertSuccessful()
            ->assertSee($status->label());
    }
}
