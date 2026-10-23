<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\ActsWithinOrganization;
use App\Models\LockerBank;
use App\StorableEvents\LockerConnectionLost;
use App\Support\EventSourcing\StoredEventDispatcher;
use Illuminate\Console\Command;

class DetectOfflineLockers extends Command
{
    use ActsWithinOrganization;

    public function __construct(private readonly StoredEventDispatcher $storedEventDispatcher)
    {
        parent::__construct();
    }

    /** @var string */
    protected $signature = 'locker:detect-offline
        {--dry-run : Do not write changes or emit events}
        {--organization= : Limit detection to one organization (slug or id); omit to sweep them all}';

    /** @var string */
    protected $description = 'Detect locker banks that missed heartbeats and mark them offline.';

    public function handle(): int
    {
        $lost = 0;

        // Heartbeat detection is installation-wide maintenance, but locker
        // banks are organization-owned and scoped fail-closed — with no
        // organization in context this command would find nothing and report
        // success. Sweeping every organization is therefore stated explicitly,
        // and a single one can be named when only it needs checking.
        if (is_string($this->option('organization')) && $this->option('organization') !== '') {
            $this->resolveOrganizationFromOption();
            $lost = $this->detectWithinCurrentOrganization();
        } else {
            $this->forEachOrganization(function () use (&$lost): void {
                $lost += $this->detectWithinCurrentOrganization();
            });
        }

        $this->info("Detected {$lost} offline locker(s).".($this->option('dry-run') ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }

    private function detectWithinCurrentOrganization(): int
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

                $this->storedEventDispatcher->dispatch(new LockerConnectionLost(
                    lockerBankUuid: (string) $lockerBank->id,
                    detectedAtIso8601: $now->toIso8601String(),
                    lastHeartbeatAtIso8601: $lockerBank->last_heartbeat_at?->toIso8601String(),
                    reason: 'timeout',
                ));
            }
        }

        return $lost;
    }
}
