<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Auth\WebResetPasswordNotification;
use App\Notifications\Auth\WebVerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public function test_user_can_login()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $loginData = [
            'email' => $user->email,
            'password' => 'password123',
        ];

        $response = $this->postJson('/api/login', $loginData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'first_name',
                'last_name',
            ]);
    }

    public function test_user_cannot_login_with_invalid_credentials()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $loginData = [
            'email' => $user->email,
            'password' => 'wrongpassword',
        ];

        $response = $this->postJson('/api/login', $loginData);

        $response->assertStatus(422);
    }

    public function test_user_can_logout()
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Logged out successfully',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_can_get_their_info()
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJson([
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,

            ]);
    }

    public function test_unauthenticated_user_cannot_access_protected_routes()
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_browser_request_to_protected_api_route_returns_unauthenticated_json(): void
    {
        $response = $this->get('/api/user');

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated',
            ]);
    }

    public function test_profile_update_requires_last_name(): void
    {
        $user = User::factory()->create([
            'last_name' => null,
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->putJson('/api/profile', [
            'first_name' => 'Updated',
            'email' => $user->email,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['last_name']);
    }

    public function test_login_validation_rules()
    {
        $response = $this->postJson('/api/login', [
            'email' => 'not-an-email',
            'password' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_user_can_verify_email()
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->getJson($verificationUrl);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Email verified',
            ]);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_user_cannot_verify_email_with_invalid_signature()
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $invalidVerificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->getJson($invalidVerificationUrl);

        $response->assertStatus(403);
    }

    public function test_user_can_verify_email_from_public_web_link(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify.web',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->get($verificationUrl);

        $response->assertOk()
            ->assertSee('E-Mail erfolgreich bestätigt')
            ->assertSee($user->email);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_send_verification_email()
    {
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($user)->postJson(Route('verification.send'));

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Email verification link sent',
            ]);

        Notification::assertSentTo(
            [$user],
            WebVerifyEmailNotification::class,
            function ($notification, $channels, $notifiable) {
                $mailMessage = $notification->toMail($notifiable);

                $this->assertStringStartsWith('http://open-locker.test/verify-email/', (string) $mailMessage->actionUrl);

                return true;
            }
        );
    }

    public function test_send_verification_email_already_verified()
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/email/verification-notification', [], [
            'Accept-Language' => 'de',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'E-Mail bereits bestätigt',
            ]);
    }

    public function test_user_can_request_password_reset_link()
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson(Route('password.email'), [
            'email' => $user->email,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password reset link sent',
            ]);

        Notification::assertSentTo($user, WebResetPasswordNotification::class, function ($notification) use ($user) {
            $mailMessage = $notification->toMail($user);

            $this->assertStringStartsWith('http://open-locker.test/reset-password?', (string) $mailMessage->actionUrl);
            $this->assertNotContains('open-locker://', [
                (string) $mailMessage->actionUrl,
                ...$mailMessage->introLines,
                ...$mailMessage->outroLines,
            ]);

            return true;
        });
    }

    public function test_user_cannot_request_password_reset_link_with_invalid_email()
    {
        $response = $this->postJson(Route('password.email'), [
            'email' => 'invalid-email@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_reset_password()
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->post(Route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, WebResetPasswordNotification::class, function ($notification) use ($user) {
            $response = $this->postJson('/api/reset-password', [
                'token' => $notification->token(),
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response->assertStatus(200);
            $this->assertTrue($user->fresh()->hasVerifiedEmail());

            return true;
        });
    }

    public function test_user_cannot_reset_password_with_invalid_token()
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_password_reset_page_is_publicly_accessible()
    {
        $response = $this->get('/reset-password?token=test-token&email=user@example.com');

        $response->assertOk()
            ->assertSee('Setze dein Passwort zurück')
            ->assertSee('user@example.com')
            ->assertSee('test-token', false);
    }

    public function test_user_can_reset_password_from_public_web_form()
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->post(route('password.email'), ['email' => $user->email], ['Accept-Language' => 'de']);

        Notification::assertSentTo($user, WebResetPasswordNotification::class, function ($notification) use ($user) {
            $response = $this->followingRedirects()->from(route('password.reset.form', [
                'token' => $notification->token(),
                'email' => $user->email,
            ]))->post(route('password.reset.web.store'), [
                'token' => $notification->token(),
                'email' => $user->email,
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ]);

            $response->assertOk()
                ->assertSee('Passwort erfolgreich zurückgesetzt')
                ->assertDontSee('name="email"', false)
                ->assertDontSee('<form', false);

            $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
            $this->assertTrue($user->fresh()->hasVerifiedEmail());

            return true;
        });
    }

    public function test_user_can_send_password_reset_link_for_filament_workflow(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $status = $user->sendAdminPasswordResetLink();

        $this->assertSame(\Illuminate\Support\Facades\Password::RESET_LINK_SENT, $status);
        Notification::assertSentTo($user, WebResetPasswordNotification::class);
    }

    public function test_user_can_send_verification_mail_for_filament_workflow(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $sent = $user->sendAdminVerificationEmail();

        $this->assertTrue($sent);
        Notification::assertSentTo($user, WebVerifyEmailNotification::class);
    }

    public function test_user_can_update_their_profile()
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->putJson('/api/profile', [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => 'updated@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'first_name' => 'Updated',
                'last_name' => 'Name',
                'email' => 'updated@example.com',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => 'updated@example.com',
        ]);
    }

    public function test_user_cannot_update_profile_with_existing_email()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->putJson('/api/profile', [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => $other->email,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_updating_email_resets_verification_and_sends_new_verification_email()
    {
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->putJson('/api/profile', [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => 'new-address@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'first_name' => 'Updated',
                'last_name' => 'Name',
                'email' => 'new-address@example.com',
            ]);

        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentTo([$user->fresh()], WebVerifyEmailNotification::class);
    }

    public function test_user_can_change_their_password()
    {
        $user = User::factory()->create([
            'password' => bcrypt('old-password'),
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->putJson('/api/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password updated successfully',
            ]);

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_user_cannot_change_password_with_wrong_current_password()
    {
        $user = User::factory()->create([
            'password' => bcrypt('old-password'),
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->putJson('/api/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }
}
