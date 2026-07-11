<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLog;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Audit\AuditEventPresenter;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_configuration_builds(): void
    {
        $livewire = $this->createMock(HasTable::class);

        $this->assertInstanceOf(Table::class, AuditLogResource::table(Table::make($livewire)));
    }

    public function test_whitelist_includes_admin_events_and_excludes_telemetry(): void
    {
        $classes = app(AuditEventPresenter::class)->auditableEventClasses();

        $this->assertContains('App\\StorableEvents\\CompartmentAccessGranted', $classes);
        $this->assertContains('App\\StorableEvents\\UserRoleGranted', $classes);

        // High-volume telemetry / internal events must not appear in the audit log.
        $this->assertNotContains('App\\StorableEvents\\HeartbeatReceived', $classes);
        $this->assertNotContains('App\\StorableEvents\\DeviceEventReceived', $classes);
        $this->assertNotContains('App\\StorableEvents\\CommandResponseReceived', $classes);
        $this->assertNotContains('App\\StorableEvents\\CompartmentDoorStateChanged', $classes);
    }

    public function test_presenter_renders_human_readable_description(): void
    {
        $event = new AuditEvent([
            'event_class' => 'App\\StorableEvents\\UserRoleGranted',
            'event_properties' => [
                'userId' => 42,
                'role' => 'manager',
                'actorUserId' => null,
            ],
        ]);

        $presenter = app(AuditEventPresenter::class);

        $description = $presenter->describe($event);

        $this->assertStringContainsString('manager', $description);
        // No actor id => attributed to the system.
        $this->assertNull($presenter->actorName($event));
        $this->assertSame(__('Role granted'), $presenter->label($event->event_class));
    }

    public function test_admin_can_access_audit_log(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $this->actingAs($admin);

        $this->assertTrue(AuditLogResource::canAccess());

        $this->get(route('filament.admin.resources.audit-logs.index'))
            ->assertOk();
    }

    public function test_actor_filter_limits_results_to_the_selected_user(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();

        $byAdmin = $this->recordAuditEvent($admin->id);
        $byOther = $this->recordAuditEvent($other->id);

        $this->actingAs($admin);

        Livewire::test(ListAuditLog::class)
            ->filterTable('actor', $admin->id)
            ->assertCanSeeTableRecords([$byAdmin])
            ->assertCanNotSeeTableRecords([$byOther]);
    }

    private function recordAuditEvent(int $actorUserId): AuditEvent
    {
        return AuditEvent::create([
            'aggregate_uuid' => (string) Str::uuid(),
            'aggregate_version' => 1,
            'event_version' => 1,
            'event_class' => 'App\\StorableEvents\\GroupCreated',
            'event_properties' => [
                'groupUuid' => (string) Str::uuid(),
                'name' => 'Test group',
                'description' => null,
                'actorUserId' => $actorUserId,
                'createdAt' => now()->toIso8601String(),
            ],
            'meta_data' => [],
            'created_at' => now(),
        ]);
    }

    public function test_non_admin_cannot_access_audit_log(): void
    {
        // The first user created is auto-promoted to admin; throw it away so the
        // user under test is a plain, unprivileged account.
        User::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->assertFalse(AuditLogResource::canAccess());

        $this->get(route('filament.admin.resources.audit-logs.index'))
            ->assertForbidden();
    }
}
