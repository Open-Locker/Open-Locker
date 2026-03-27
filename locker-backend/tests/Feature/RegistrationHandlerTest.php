<?php

namespace Tests\Feature;

use App\Mqtt\Handlers\RegistrationHandler;
use App\StorableEvents\LockerWasProvisioned;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class RegistrationHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_valid_registration_message_records_provisioning_event(): void
    {
        $handler = app(RegistrationHandler::class);
        $lockerBank = LockerBankFactory::new()->create([
            'provisioned_at' => null,
        ]);

        $topic = sprintf('locker/register/%s', $lockerBank->provisioning_token);
        $handler->handleMessage($topic, (string) json_encode([
            'message_id' => '11111111-1111-1111-1111-111111111111',
            'client_id' => 'prov-client-1',
        ]));

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', LockerWasProvisioned::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($storedEvent);
        $this->assertSame((string) $lockerBank->id, $storedEvent->event_properties['lockerBankUuid'] ?? null);
        $this->assertSame('locker/provisioning/reply/prov-client-1', $storedEvent->event_properties['replyToTopic'] ?? null);

        $lockerBank->refresh();
        $this->assertNotNull($lockerBank->provisioned_at);
    }
}
