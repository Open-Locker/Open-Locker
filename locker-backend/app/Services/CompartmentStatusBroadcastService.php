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
        return $this->recipientUserIdsForCompartmentIds([$compartment->id]);
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
        return $this->recipientUserIdsForCompartmentIds(
            array_values($lockerBank->compartments()->pluck('id')->all())
        );
    }

    /**
     * @param  list<mixed>  $compartmentIds
     * @return list<int>
     */
    private function recipientUserIdsForCompartmentIds(array $compartmentIds): array
    {
        if ($compartmentIds === []) {
            return [];
        }

        $accessUserIds = CompartmentAccess::query()
            ->whereIn('compartment_id', $compartmentIds)
            ->active()
            ->pluck('user_id')
            ->all();

        $groupAccessUserIds = UserGroupCompartmentAccess::query()
            ->whereIn('compartment_id', $compartmentIds)
            ->active()
            ->pluck('user_id')
            ->all();

        $operationalRoleIds = UserRole::query()
            ->whereIn('role', Role::valuesWithPermission(Permission::CompartmentOpen))
            ->pluck('user_id')
            ->all();

        return array_values(array_unique(array_merge($accessUserIds, $groupAccessUserIds, $operationalRoleIds)));
    }
}
