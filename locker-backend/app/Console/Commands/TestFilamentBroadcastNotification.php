<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

class TestFilamentBroadcastNotification extends Command
{
    protected $signature = 'reverb:test-filament-notification
        {userId? : User ID that should receive the notification}
        {--title=Reverb test notification : Notification title}
        {--body=Realtime broadcast from local environment : Notification body}';

    protected $description = 'Send a realtime Filament broadcast notification to a user.';

    public function handle(): int
    {
        $userId = $this->argument('userId');

        $user = $userId
            ? User::query()->find((int) $userId)
            : User::query()->whereNotNull('is_admin_since')->orderBy('id')->first();

        if (! $user) {
            $this->error('No target user found. Pass a user ID explicitly.');

            return self::FAILURE;
        }

        Notification::make()
            ->title((string) $this->option('title'))
            ->body((string) $this->option('body'))
            ->success()
            ->broadcast($user);

        $this->info("Broadcast notification sent to user #{$user->id} ({$user->email}).");

        return self::SUCCESS;
    }
}
