<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mqtt\Handlers\LockerConnectionStateHandler;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class LockerConnectionStateHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_valid_offline_payload_is_accepted_without_domain_side_effects(): void
    {
        $handler = app(LockerConnectionStateHandler::class);
        $lockerBank = LockerBankFactory::new()->create([
            'id' => '99999999-9999-9999-9999-999999999999',
            'connection_status' => 'online',
            'connection_status_changed_at' => now()->subHour(),
        ]);

        $priorChangedAt = $lockerBank->connection_status_changed_at;

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/connection",
            (string) json_encode([
                'message_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
                'timestamp' => now()->toIso8601String(),
                'status' => 'offline',
                'reason' => 'mqtt_last_will',
            ]),
        );

        $lockerBank->refresh();

        $this->assertSame('online', $lockerBank->connection_status);
        $this->assertEquals($priorChangedAt?->timestamp, $lockerBank->connection_status_changed_at?->timestamp);
        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }

    public function test_non_offline_status_is_rejected(): void
    {
        $handler = app(LockerConnectionStateHandler::class);
        $lockerBank = LockerBankFactory::new()->create([
            'id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'connection_status' => 'online',
            'connection_status_changed_at' => now()->subDay(),
        ]);

        $priorChangedAt = $lockerBank->connection_status_changed_at;

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/connection",
            (string) json_encode([
                'message_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
                'timestamp' => now()->toIso8601String(),
                'status' => 'online',
                'reason' => 'unexpected',
            ]),
        );

        $lockerBank->refresh();

        $this->assertSame('online', $lockerBank->connection_status);
        $this->assertEquals($priorChangedAt?->timestamp, $lockerBank->connection_status_changed_at?->timestamp);
        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }

    public function test_empty_reason_is_rejected(): void
    {
        $handler = app(LockerConnectionStateHandler::class);
        $lockerBank = LockerBankFactory::new()->create([
            'id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'connection_status' => 'online',
        ]);

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/connection",
            (string) json_encode([
                'message_id' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
                'timestamp' => now()->toIso8601String(),
                'status' => 'offline',
                'reason' => '',
            ]),
        );

        $lockerBank->refresh();

        $this->assertSame('online', $lockerBank->connection_status);
        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }

    public function test_duplicate_message_id_second_dispatch_is_ignored_without_domain_side_effects(): void
    {
        $handler = app(LockerConnectionStateHandler::class);
        $lockerBank = LockerBankFactory::new()->create([
            'id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'connection_status' => 'online',
            'connection_status_changed_at' => now()->subHour(),
        ]);

        $mid = '11111111-2222-3333-4444-555555555555';
        $priorChangedAt = $lockerBank->connection_status_changed_at;

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/connection",
            (string) json_encode([
                'message_id' => $mid,
                'timestamp' => now()->toIso8601String(),
                'status' => 'offline',
                'reason' => 'mqtt_last_will',
            ]),
        );

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/connection",
            (string) json_encode([
                'message_id' => $mid,
                'timestamp' => now()->addMinute()->toIso8601String(),
                'status' => 'offline',
                'reason' => 'mqtt_last_will',
            ]),
        );

        $lockerBank->refresh();

        $this->assertSame('online', $lockerBank->connection_status);
        $this->assertEquals($priorChangedAt?->timestamp, $lockerBank->connection_status_changed_at?->timestamp);
        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }
}
