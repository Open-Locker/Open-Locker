<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LockerBank;
use App\StorableEvents\LockerConnectionLost;
use Illuminate\Console\Command;

class DetectOfflineLockers extends Command
{
    /** @var string */
    protected $signature = 'locker:detect-offline {--dry-run : Do not write changes or emit events}';

    /** @var string */
    protected $description = 'Detect locker banks that missed heartbeats and mark them offline.';

    public function handle(): int
    {
        $now = now();
        $dryRun = (bool) $this->option('dry-run');

        $candidates = LockerBank::query()
            ->whereNotNull('last_heartbeat_at')
            ->where('connection_status', '!=', 'offline')
            ->get(['id', 'last_heartbeat_at', 'heartbeat_timeout_seconds', 'connection_status']);

        $lost = 0;

        foreach ($candidates as $lockerBank) {
            $timeoutSeconds = max(1, (int) $lockerBank->heartbeat_timeout_seconds);
            $offlineAfter = $now->copy()->subSeconds($timeoutSeconds);

            if ($lockerBank->last_heartbeat_at && $lockerBank->last_heartbeat_at->greaterThanOrEqualTo($offlineAfter)) {
                continue;
            }

            if ($dryRun) {
                $lost++;

                continue;
            }

            $affected = LockerBank::query()
                ->whereKey($lockerBank->id)
                ->where('connection_status', '!=', 'offline')
                ->update([
                    'connection_status' => 'offline',
                    'connection_status_changed_at' => $now,
                ]);

            if ($affected === 1) {
                $lost++;

                event(new LockerConnectionLost(
                    lockerBankUuid: (string) $lockerBank->id,
                    detectedAtIso8601: $now->toIso8601String(),
                    lastHeartbeatAtIso8601: $lockerBank->last_heartbeat_at?->toIso8601String(),
                    reason: 'timeout',
                ));
            }
        }

        $this->info("Detected {$lost} offline locker(s).".($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
