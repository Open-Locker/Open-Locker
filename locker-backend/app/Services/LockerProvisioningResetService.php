<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\LockerBankAggregate;
use App\Enums\Permission;
use App\Models\LockerBank;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Administrative recovery for a locker bank whose provisioning has to be
 * redone — a device that failed to provision, was replaced, or whose token
 * leaked.
 *
 * This is recovery, not revocation. Deleting the MQTT user denies future
 * authentication and ACL checks, but it does not promise that Mosquitto tears
 * down a TCP session that is already established, and the recreated username
 * is the same locker-bank UUID as before. Durable per-provisioning identities
 * are a separate piece of work (#161); HMAC token storage is another (#159).
 */
class LockerProvisioningResetService
{
    public function __construct(
        private readonly MqttUserService $mqttUserService,
    ) {}

    /**
     * Rotate the bank's provisioning token and drop everything the backend
     * believed about the device that was using the old one.
     *
     * Authorization lives here rather than on the Filament action: hiding a
     * button is presentation, and this is the only thing standing between a
     * request and a rotated credential.
     *
     * @return string The new provisioning token, for display to the admin.
     *
     * @throws AuthorizationException
     */
    public function reset(LockerBank $lockerBank, ?User $actor): string
    {
        throw_unless(
            $actor?->can(Permission::LockerBankConfigure->value) ?? false,
            AuthorizationException::class,
            'You are not allowed to reset locker bank provisioning.',
        );

        $resetAt = CarbonImmutable::now();
        $newToken = Str::random(64);

        // One transaction so a failure part-way cannot leave the bank with a
        // rotated token but a live MQTT user, or vice versa. The stored event
        // is written through the same connection, so it rolls back too.
        DB::transaction(function () use ($lockerBank, $actor, $resetAt, $newToken): void {
            // Two admins resetting the same bank at once would otherwise both
            // mint a token, and the operator holding the one that lost the race
            // could never provision. The row lock makes the second wait and
            // supersede rather than interleave.
            LockerBank::query()
                ->whereKey($lockerBank->id)
                ->lockForUpdate()
                ->first();

            // Not carried on the event by design; see LockerProvisioningReset.
            $lockerBank->forceFill(['provisioning_token' => $newToken])->save();

            $this->mqttUserService->deleteUser((string) $lockerBank->id);

            LockerBankAggregate::retrieve((string) $lockerBank->id)
                ->resetProvisioning($actor->id, $resetAt)
                ->persist();
        });

        Log::info('Locker bank provisioning reset.', [
            'locker_bank_id' => $lockerBank->id,
            'actor_user_id' => $actor->id,
            'reset_at' => $resetAt->toIso8601String(),
        ]);

        return $newToken;
    }
}
