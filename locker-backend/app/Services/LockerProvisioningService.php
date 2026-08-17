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
use RuntimeException;

class LockerProvisioningService
{
    public function __construct(
        private readonly MqttUserService $mqttUserService,
    ) {}

    /**
     * Reset device state and issue a token that is returned exactly once.
     *
     * @throws AuthorizationException
     */
    public function restart(LockerBank $lockerBank, User $actor): string
    {
        throw_unless(
            $actor->can(Permission::LockerBankConfigure->value),
            AuthorizationException::class,
            'You are not allowed to restart locker bank provisioning.',
        );

        $issuedAt = CarbonImmutable::now();

        $token = DB::transaction(function () use ($lockerBank, $actor, $issuedAt): string {
            $lockedLockerBank = LockerBank::query()
                ->whereKey($lockerBank->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $token = Str::random(64);
            $generation = (string) Str::uuid();
            $tokenHmac = $this->hashToken($token);

            LockerBankAggregate::retrieve((string) $lockedLockerBank->id)
                ->resetProvisioning($actor->id, $issuedAt)
                ->issueProvisioningToken(
                    $tokenHmac,
                    $generation,
                    $actor->id,
                    $issuedAt,
                )
                ->persist();

            $this->mqttUserService->deleteUser((string) $lockedLockerBank->id);

            return $token;
        });

        Log::info('Locker bank provisioning restarted.', [
            'locker_bank_id' => $lockerBank->id,
            'actor_user_id' => $actor->id,
            'issued_at' => $issuedAt->toIso8601String(),
        ]);

        return $token;
    }

    public function acceptRegistration(string $token, string $replyToTopic): bool
    {
        $tokenHmac = $this->hashToken($token);

        return DB::transaction(function () use ($tokenHmac, $replyToTopic): bool {
            $lockerBank = LockerBank::query()
                ->where('provisioning_token_hmac', $tokenHmac)
                ->lockForUpdate()
                ->first();

            if ($lockerBank === null || $lockerBank->provisioning_generation === null) {
                return false;
            }

            LockerBankAggregate::retrieve((string) $lockerBank->id)
                ->provision(
                    $lockerBank,
                    $replyToTopic,
                    (string) $lockerBank->provisioning_generation,
                )
                ->persist();

            return true;
        });
    }

    public function hashToken(string $token): string
    {
        $key = config('provisioning.token_hmac_key');

        if (
            ! is_string($key)
            || strlen(trim($key)) < 32
            || $key === 'change-me-to-a-dedicated-random-secret-of-at-least-32-characters'
        ) {
            throw new RuntimeException(
                'PROVISIONING_TOKEN_HMAC_KEY must contain at least 32 characters.',
            );
        }

        return hash_hmac('sha256', $token, $key);
    }
}
