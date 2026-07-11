<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Compartment;
use App\Models\CompartmentAccess;
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
        $accessUserIds = CompartmentAccess::query()
            ->where('compartment_id', $compartment->id)
            ->active()
            ->pluck('user_id')
            ->all();

        $groupAccessUserIds = UserGroupCompartmentAccess::query()
            ->where('compartment_id', $compartment->id)
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
