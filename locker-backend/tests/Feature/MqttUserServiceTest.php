<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LockerBank;
use App\Models\MqttUser;
use App\Services\MqttUserService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MqttUserServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_retired_username_can_never_be_issued_again(): void
    {
        // The whole reason identities are disabled rather than deleted: the unique
        // index keeps a retired username occupied, so a still-connected session
        // whose identity was revoked can never have it recreated underneath it.
        // An upsert here — which is what this code used to do — would silently
        // re-password the retired row instead of failing.
        $bank = LockerBank::factory()->create();
        $service = app(MqttUserService::class);

        $service->createUser('taken-identity', 'first-password', (string) $bank->id);
        $service->revokeForLockerBank((string) $bank->id);

        $this->expectException(QueryException::class);
        $service->createUser('taken-identity', 'second-password', (string) $bank->id);
    }

    public function test_revoking_disables_every_live_identity_of_one_bank_only(): void
    {
        $bank = LockerBank::factory()->create();
        $otherBank = LockerBank::factory()->create();

        $service = app(MqttUserService::class);
        $service->createUser('identity-a', 'password', (string) $bank->id);
        $service->createUser('identity-b', 'password', (string) $bank->id);
        $service->createUser('untouched', 'password', (string) $otherBank->id);

        $this->assertSame(2, $service->revokeForLockerBank((string) $bank->id));

        foreach (['identity-a', 'identity-b'] as $username) {
            $identity = MqttUser::query()->where('username', $username)->firstOrFail();
            $this->assertFalse($identity->enabled);
            $this->assertNotNull($identity->revoked_at, 'a retired identity is stamped, not deleted');
        }

        $this->assertTrue(MqttUser::query()->where('username', 'untouched')->firstOrFail()->enabled);

        // Nothing is removed, so the usernames stay occupied.
        $this->assertSame(3, MqttUser::query()->count());
    }

    public function test_revoking_a_bank_with_nothing_live_changes_nothing(): void
    {
        $bank = LockerBank::factory()->create();
        $service = app(MqttUserService::class);

        $this->assertSame(0, $service->revokeForLockerBank((string) $bank->id));
    }

    public function test_an_issued_identity_is_live_and_authenticatable(): void
    {
        $bank = LockerBank::factory()->create();
        app(MqttUserService::class)->createUser('fresh-identity', 'a-password', (string) $bank->id);

        $identity = MqttUser::query()->where('username', 'fresh-identity')->firstOrFail();

        $this->assertTrue($identity->enabled);
        $this->assertNull($identity->revoked_at);
        $this->assertSame((string) $bank->id, (string) $identity->locker_bank_id);
        $this->assertTrue(Hash::check('a-password', $identity->password_hash));
    }
}
