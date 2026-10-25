<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\LockerBank;
use App\Models\UserGroupCompartmentAccess;
use App\Models\UserRole;

/**
 * Every caller is a queued reactor, so there is no organization in context and
 * the organization scope would return no rows. Recipients are therefore found
 * in the compartment's own organization, read from the row itself, never from
 * ambient context.
 */
class CompartmentStatusBroadcastService
{
    /**
     * Users who should receive realtime compartment status for this compartment:
     * active access holders and operational roles allowed to open compartments.
     *
     * @return list<int>
     */
    public function recipientUserIdsForCompartment(Compartment $compartment): array
    {
        return $this->recipientUserIdsForCompartmentIds([$compartment->id], $compartment->organization_id);
    }

    /**
     * Users who should receive realtime status for any compartment in this bank.
     *
     * A bank's connection state is relevant to exactly the people who can act on
     * something inside it, so the audience is the union of the per-compartment
     * audiences rather than a separate rule.
     *
     * @return list<int>
     */
    public function recipientUserIdsForLockerBank(LockerBank $lockerBank): array
    {
        $compartmentIds = Compartment::withoutGlobalScope('organization')
            ->where('locker_bank_id', $lockerBank->id)
            ->pluck('id')
            ->all();

        return $this->recipientUserIdsForCompartmentIds(array_values($compartmentIds), $lockerBank->organization_id);
    }

    /**
     * @param  list<mixed>  $compartmentIds
     * @return list<int>
     */
    private function recipientUserIdsForCompartmentIds(array $compartmentIds, string $organizationId): array
    {
        if ($compartmentIds === []) {
            return [];
        }

        $accessUserIds = CompartmentAccess::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereIn('compartment_id', $compartmentIds)
            ->active()
            ->pluck('user_id')
            ->all();

        $groupAccessUserIds = UserGroupCompartmentAccess::query()
            ->whereIn('compartment_id', $compartmentIds)
            ->active()
            ->pluck('user_id')
            ->all();

        // Operators of this organization only. Platform admins belong to none and
        // see an organization's lockers only after switching into it.
        $operationalRoleIds = UserRole::query()
            ->where('organization_id', $organizationId)
            ->whereIn('role', Role::valuesWithPermission(Permission::CompartmentOpen))
            ->pluck('user_id')
            ->all();

        return array_values(array_unique(array_merge($accessUserIds, $groupAccessUserIds, $operationalRoleIds)));
    }
}
