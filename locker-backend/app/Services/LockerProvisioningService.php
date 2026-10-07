<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\LockerBankAggregate;
use App\Enums\Permission;
use App\Models\LockerBank;
use App\Models\User;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class LockerProvisioningService
{
    private const TOKEN_HMAC_INFO = 'open-locker/provisioning-token-hmac/v1';

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

            $this->mqttUserService->revokeForLockerBank((string) $lockedLockerBank->id);

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
            // Unscoped deliberately. The device sends only its token, as it
            // always has; registration names no bank, so nothing has
            // established an organization yet — and this lookup is the step
            // that establishes it. Scoped, it matches nothing and every device
            // is told its token is invalid.
            $lockerBank = LockerBank::withoutGlobalScope('organization')
                ->where('provisioning_token_hmac', $tokenHmac)
                ->lockForUpdate()
                ->first();

            if ($lockerBank === null || $lockerBank->provisioning_generation === null) {
                return false;
            }

            // Ownership is derived from the bank, on this side only: once it is
            // known, the provisioning is recorded inside that operator so the
            // events land in their history rather than the default
            // organization's.
            return app(OrganizationContext::class)->runWithin(
                $lockerBank->organization,
                function () use ($lockerBank, $replyToTopic): bool {
                    LockerBankAggregate::retrieve((string) $lockerBank->id)
                        ->provision(
                            $lockerBank,
                            $replyToTopic,
                            (string) $lockerBank->provisioning_generation,
                        )
                        ->persist();

                    return true;
                },
            );
        });
    }

    public function hashToken(string $token): string
    {
        $configuredKey = config('app.key');

        if (! is_string($configuredKey) || $configuredKey === '') {
            throw new RuntimeException('APP_KEY must be configured for provisioning token HMAC.');
        }

        $appKey = $configuredKey;

        if (str_starts_with($configuredKey, 'base64:')) {
            $appKey = base64_decode(substr($configuredKey, 7), true);

            if ($appKey === false || $appKey === '') {
                throw new RuntimeException('APP_KEY contains invalid Base64 data.');
            }
        }

        $validatedAppKey = (new Encrypter($appKey, (string) config('app.cipher')))->getKey();
        $tokenHmacKey = hash_hkdf('sha256', $validatedAppKey, 32, self::TOKEN_HMAC_INFO);

        return hash_hmac('sha256', $token, $tokenHmacKey);
    }
}
