<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_bearer_token_can_authorize_private_compartment_status_channel(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-users.{$user->id}.compartment-status",
            ]);

        $response->assertOk();
    }

    public function test_web_session_can_authorize_private_compartment_status_channel(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-users.{$user->id}.compartment-status",
            ]);

        $response->assertOk();
    }
}
