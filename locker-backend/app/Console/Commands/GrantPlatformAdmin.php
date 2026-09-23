<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The escape hatch for the one role nobody can grant from inside the panel.
 *
 * Granting platform administration requires already holding it, which is right
 * — but it leaves no way back in if the last one is removed, and no way to
 * create the first on an installation that never had an admin. This command is
 * that way, and it is deliberately console-only: reaching the server is the
 * authorization.
 */
class GrantPlatformAdmin extends Command
{
    /** @var string */
    protected $signature = 'platform-admin:grant {email : The account to grant platform administration to}';

    /** @var string */
    protected $description = 'Grant platform administration to an existing account.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error(sprintf('No account found for %s.', $email));

            return self::FAILURE;
        }

        if ($user->isPlatformAdmin()) {
            $this->info(sprintf('%s already administers the installation.', $email));

            return self::SUCCESS;
        }

        // Recorded with no organization, the way the role is defined: it
        // administers the installation rather than any one operator.
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($user->id))
            ->grantRole($user->id, Role::PlatformAdmin->value, null, now(), null)
            ->persist();

        $this->info(sprintf('%s now administers the installation.', $email));

        return self::SUCCESS;
    }
}
