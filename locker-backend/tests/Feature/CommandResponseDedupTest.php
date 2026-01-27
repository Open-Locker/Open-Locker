<?php

namespace Tests\Feature;

use App\Mqtt\Handlers\CommandResponseHandler;
use App\StorableEvents\CommandResponseReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CommandResponseDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_command_response_is_deduplicated(): void
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

        $handler->handle($topic, $payload);
        $handler->handle($topic, $payload); // duplicate delivery

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
}
