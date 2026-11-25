<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * People who reset a password (#372) or open the start page (#373) can find
 * where to sign in.
 */
class LoginLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_page_links_to_the_admin_login(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.url('/admin/login').'"', false);
    }

    public function test_password_reset_points_admins_to_the_admin_login(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $this->resetPasswordViaWebForm($admin)
            ->assertOk()
            ->assertSee('href="'.url('/admin/login').'"', false);
    }

    public function test_password_reset_points_app_users_to_the_app_only(): void
    {
        $this->resetPasswordViaWebForm(User::factory()->create())
            ->assertOk()
            ->assertSee(__('Your password has been reset. You can now sign in in the app.'))
            ->assertDontSee('/admin/login', false);
    }

    private function resetPasswordViaWebForm(User $user): TestResponse
    {
        return $this->followingRedirects()->post(route('password.reset.web.store'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);
    }
}
