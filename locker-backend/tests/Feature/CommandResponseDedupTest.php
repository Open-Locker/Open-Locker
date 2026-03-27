<?php

namespace Tests\Feature;

use App\Mqtt\Handlers\CommandResponseHandler;
use App\StorableEvents\CommandResponseReceived;
use App\StorableEvents\LockerConfigAcknowledged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CommandResponseDedupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_duplicate_command_response_is_deduplicated(): void
    {
        $handler = app(CommandResponseHandler::class);

        $lockerUuid = '11111111-1111-1111-1111-111111111111';
        $transactionId = '22222222-2222-2222-2222-222222222222';

        $topic = "locker/{$lockerUuid}/response";
        $payload = [
            'message_id' => '33333333-3333-3333-3333-333333333333',
            'type' => 'command_response',
            'action' => 'open_compartment',
            'result' => 'success',
            'transaction_id' => $transactionId,
            'timestamp' => now()->toIso8601String(),
            'message' => 'ok',
        ];

        $message = (string) json_encode($payload);

        $handler->handleMessage($topic, $message);
        $handler->handleMessage($topic, $message); // duplicate delivery

        $this->assertDatabaseCount('command_transactions', 1);
        $this->assertDatabaseHas('command_transactions', [
            'locker_uuid' => $lockerUuid,
            'transaction_id' => $transactionId,
        ]);

        $storedCount = EloquentStoredEvent::query()
            ->where('event_class', CommandResponseReceived::class)
            ->count();

        $this->assertSame(1, $storedCount);
    }

    public function test_command_response_without_message_id_is_rejected_early(): void
    {
        $handler = app(CommandResponseHandler::class);

        $lockerUuid = '11111111-1111-1111-1111-111111111111';
        $transactionId = '22222222-2222-2222-2222-222222222222';

        $topic = "locker/{$lockerUuid}/response";
        $payload = [
            'type' => 'command_response',
            'action' => 'open_compartment',
            'result' => 'success',
            'transaction_id' => $transactionId,
            'timestamp' => now()->toIso8601String(),
            'message' => 'ok',
        ];

        $handler->handleMessage($topic, (string) json_encode($payload));

        $this->assertDatabaseCount('command_transactions', 0);
        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }

    public function test_command_response_without_transaction_id_is_rejected_early(): void
    {
        $handler = app(CommandResponseHandler::class);

        $lockerUuid = '11111111-1111-1111-1111-111111111111';
        $topic = "locker/{$lockerUuid}/response";
        $payload = [
            'message_id' => '33333333-3333-3333-3333-333333333333',
            'type' => 'command_response',
            'action' => 'open_compartment',
            'result' => 'success',
            'timestamp' => now()->toIso8601String(),
            'message' => 'ok',
        ];

        $handler->handleMessage($topic, (string) json_encode($payload));

        $this->assertDatabaseCount('command_transactions', 0);
        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }

    public function test_invalid_command_response_payload_is_rejected_by_handler_validation(): void
    {
        $handler = app(CommandResponseHandler::class);

        $lockerUuid = '11111111-1111-1111-1111-111111111111';
        $topic = "locker/{$lockerUuid}/response";
        $payload = [
            'message_id' => '44444444-4444-4444-4444-444444444444',
            'type' => 'command_response',
            'result' => 'success',
            'transaction_id' => '22222222-2222-2222-2222-222222222222',
            'timestamp' => now()->toIso8601String(),
            'message' => 'ok',
        ];

        $handler->handleMessage($topic, (string) json_encode($payload));

        $this->assertDatabaseCount('command_transactions', 0);
        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }

    public function test_applied_config_hash_is_promoted_into_command_response_data(): void
    {
        $handler = app(CommandResponseHandler::class);
        $appliedConfigHash = str_repeat('a', 64);

        $topic = 'locker/11111111-1111-1111-1111-111111111111/response';
        $handler->handleMessage($topic, (string) json_encode([
            'message_id' => '55555555-5555-5555-5555-555555555555',
            'type' => 'command_response',
            'action' => 'apply_config',
            'result' => 'success',
            'transaction_id' => '66666666-6666-6666-6666-666666666666',
            'timestamp' => now()->toIso8601String(),
            'applied_config_hash' => $appliedConfigHash,
        ]));

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', CommandResponseReceived::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($storedEvent);
        $this->assertSame('apply_config', $storedEvent->event_properties['action'] ?? null);
        $this->assertSame(
            $appliedConfigHash,
            $storedEvent->event_properties['data']['applied_config_hash'] ?? null,
        );

        $derivedEvent = EloquentStoredEvent::query()
            ->where('event_class', LockerConfigAcknowledged::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($derivedEvent);
        $this->assertSame($appliedConfigHash, $derivedEvent->event_properties['appliedConfigHash'] ?? null);
    }
}
