<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateFirstAdmin extends Command
{
    /** @var string */
    protected $signature = 'first-admin:create {email? : Address of the administrator; defaults to ADMIN_EMAIL}';

    /** @var string */
    protected $description = 'Create the first administrator on a fresh instance, if there is not one already.';

    public function handle(): int
    {
        $email = $this->argument('email') ?? config('admin.first_admin_email');

        // Runs unattended on every deploy, so "nothing to do" is a success. Failing
        // here would take the container down over a missing optional setting.
        if (! is_string($email) || trim($email) === '') {
            $this->info('No administrator email configured (ADMIN_EMAIL); nothing to do.');

            return self::SUCCESS;
        }

        $email = trim($email);

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error(sprintf('"%s" is not a valid email address.', $email));

            return self::FAILURE;
        }

        // Bootstrap only. Granting admin to an existing user belongs in the panel,
        // where it goes through the role flow and leaves an audit trail; a console
        // command that did it at any time would be a way around that.
        if (User::adminRoleCount() > 0) {
            $this->info('An administrator already exists; grant further roles from the admin panel.');

            return self::SUCCESS;
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Admin',
                'last_name' => 'Account',
                // Deliberately unguessable and never shown: the administrator sets
                // their own password through the reset flow.
                'password' => Str::password(32),
            ],
        );

        // Set outside the create: email_verified_at is not fillable, so mass assignment
        // drops it silently. The address comes from deployment configuration, which is
        // already a trusted channel, so requiring a verification click on top of the
        // password reset would only add a second way to be locked out.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->makeAdmin();

        $this->info(sprintf('Administrator created for %s.', $email));
        $this->line('Use "forgot password" on the login page to set a password.');

        return self::SUCCESS;
    }
}
